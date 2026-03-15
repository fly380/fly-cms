<?php
/**
 * templates/register.php
 * Реєстрація за одноразовим запрошенням (з опціональним налаштуванням 2FA)
 */
ini_set('session.cookie_httponly', 1);
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/../data/totp.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$step  = 'form'; // form | done | totp

if (empty($token)) { header('Location: /templates/login.php'); exit; }

$pdo = fly_db();

$stmt = $pdo->prepare("SELECT * FROM invitations WHERE token = ? AND used_at IS NULL AND expires_at > datetime('now')");
$stmt->execute([$token]);
$invite = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invite) { $step = 'invalid'; }

if (empty($_SESSION['reg_csrf'])) { $_SESSION['reg_csrf'] = bin2hex(random_bytes(32)); }

// ── Крок 1: Реєстрація ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'form' && isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['reg_csrf']) {
        $error = 'Невірний токен безпеки.';
    } else {
        $login = trim($_POST['login'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');
        $pw    = $_POST['password'] ?? '';
        $pw2   = $_POST['password2'] ?? '';

        if (strlen($login) < 3)                           { $error = 'Логін занадто короткий (мінімум 3 символи).'; }
        elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $login)) { $error = 'Логін: тільки латиниця, цифри, _ і -.'; }
        elseif (strlen($pw) < 6)                          { $error = 'Пароль має бути не коротший за 6 символів.'; }
        elseif ($pw !== $pw2)                             { $error = 'Паролі не збігаються.'; }
        elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Некоректний формат email.'; }
        else {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login = ?");
            $exists->execute([$login]);
            if ($exists->fetchColumn() > 0) {
                $error = 'Цей логін вже зайнятий.';
            } else {
                // Якщо display_name порожній - використовуємо login
                $finalDisplayName = !empty($display_name) ? $display_name : $login;
                
                // Перевіримо чи є колонка email в users
                try {
                    $pdo->query("SELECT email FROM users LIMIT 1");
                    $hasEmailColumn = true;
                } catch (Exception $e) {
                    $hasEmailColumn = false;
                }
                
                // Вставляємо користувача
                if ($hasEmailColumn) {
                    $pdo->prepare("INSERT INTO users (login, password, role, display_name, email) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$login, password_hash($pw, PASSWORD_BCRYPT), $invite['role'], $finalDisplayName, $email ?: null]);
                } else {
                    $pdo->prepare("INSERT INTO users (login, password, role, display_name) VALUES (?, ?, ?, ?)")
                        ->execute([$login, password_hash($pw, PASSWORD_BCRYPT), $invite['role'], $finalDisplayName]);
                }

                // Позначаємо запрошення як використане
                $pdo->prepare("UPDATE invitations SET used_at = datetime('now'), used_by = ? WHERE token = ?")
                    ->execute([$login, $token]);

                log_action("Новий користувач зареєструвався за запрошенням (роль: {$invite['role']})", $login);

                if (!empty($invite['require_2fa'])) {
                    // Генеруємо секрет і одразу вмикаємо 2FA
                    $secret = TOTP::generateSecret();
                    $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE login = ?")
                        ->execute([$secret, $login]);
                    log_action("2FA автоматично налаштовано при реєстрації", $login);

                    $_SESSION['reg_totp_login']  = $login;
                    $_SESSION['reg_totp_secret'] = $secret;
                    $step = 'totp';
                } else {
                    $step = 'done';
                }
                unset($_SESSION['reg_csrf']);
            }
        }
    }
}

// Відновлення кроку 2FA якщо сторінку перезавантажили
if ($step === 'form' && !empty($_SESSION['reg_totp_login'])) {
    $step = 'totp';
}

// Дані для кроку 2FA
$totpLogin  = $_SESSION['reg_totp_login']  ?? '';
$totpSecret = $_SESSION['reg_totp_secret'] ?? '';
$totpQrUrl  = '';
if ($step === 'totp' && $totpSecret) {
    $siteTitle = 'fly-CMS';
    try { $s = $pdo->query("SELECT value FROM settings WHERE key='site_title'")->fetchColumn(); if ($s) $siteTitle = $s; } catch(Exception $e) {}
    $totpQrUrl = TOTP::getQRCodeUrl($totpSecret, $totpLogin, $siteTitle);
}

// ── Dismiss 2FA step ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish_2fa'])) {
    unset($_SESSION['reg_totp_login'], $_SESSION['reg_totp_secret']);
    $step = 'done';
}

$siteTitle = 'fly-CMS';
try { $s = $pdo->query("SELECT value FROM settings WHERE key='site_title'")->fetchColumn(); if ($s) $siteTitle = $s; } catch(Exception $e) {}
$roleLabels = ['admin'=>'Адмін','redaktor'=>'Редактор','user'=>'Користувач'];
$roleColors = ['admin'=>'danger','redaktor'=>'warning','user'=>'secondary'];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<title>Реєстрація — <?= htmlspecialchars($siteTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f0f2f5; }
.reg-card { max-width: 480px; margin: 60px auto; }
</style>
</head>
<body>
<div class="reg-card">
<div class="card shadow-sm border-0">
<div class="card-body p-4">

<?php if ($step === 'invalid'): ?>
    <div class="text-center py-4">
        <div style="font-size:3rem">❌</div>
        <h5 class="mt-3 text-danger">Посилання недійсне</h5>
        <p class="text-muted small">Запрошення вже використане, прострочене або не існує.</p>
        <a href="/templates/login.php" class="btn btn-outline-secondary btn-sm mt-2">До входу</a>
    </div>

<?php elseif ($step === 'done'): ?>
    <div class="text-center py-4">
        <div style="font-size:3rem">✅</div>
        <h5 class="mt-3 text-success fw-bold">Акаунт створено!</h5>
        <p class="text-muted">Тепер можете увійти зі своїм логіном і паролем.</p>
        <a href="/templates/login.php" class="btn btn-primary mt-2">Увійти</a>
    </div>

<?php elseif ($step === 'totp'): ?>
    <div class="text-center mb-3">
        <div style="font-size:2.5rem">🔐</div>
        <h5 class="mt-2 fw-bold">Налаштуйте двофакторну автентифікацію</h5>
        <p class="text-muted small">Відскануйте QR-код у <strong>Google Authenticator</strong> або іншому TOTP-додатку</p>
    </div>

    <div class="text-center mb-3">
        <img src="<?= htmlspecialchars($totpQrUrl) ?>" alt="QR-код" class="border rounded p-2" style="max-width:180px">
    </div>

    <p class="text-center text-muted small mb-1">Або введіть ключ вручну:</p>
    <div class="input-group input-group-sm mb-1" style="max-width:320px;margin:0 auto">
        <input type="text" class="form-control font-monospace text-center"
               id="totpSecretField" value="<?= htmlspecialchars($totpSecret) ?>" readonly>
        <button class="btn btn-outline-secondary" onclick="copySecret()" title="Копіювати">📋</button>
    </div>
    <p class="text-center text-muted mb-4" style="font-size:.75rem">TOTP · SHA1 · 6 цифр · 30 сек</p>

    <div class="alert alert-info small py-2">
        <strong>Важливо!</strong> Збережіть цей ключ — він знадобиться при кожному вході.
        Після закриття сторінки ключ більше не буде показаний.
    </div>

    <form method="POST">
        <input type="hidden" name="finish_2fa" value="1">
        <button type="submit" class="btn btn-success w-100 mt-2">
            ✅ Зберіг ключ — перейти до входу
        </button>
    </form>

<?php else: // form ?>
    <div class="text-center mb-4">
        <h4 class="fw-bold">Реєстрація</h4>
        <p class="text-muted small">
            <?= htmlspecialchars($siteTitle) ?> · Роль:
            <span class="badge bg-<?= $roleColors[$invite['role']] ?? 'secondary' ?>">
                <?= $roleLabels[$invite['role']] ?? $invite['role'] ?>
            </span>
            <?php if (!empty($invite['require_2fa'])): ?>
                <span class="badge bg-info text-dark ms-1">🔐 2FA</span>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['reg_csrf']) ?>">

        <div class="mb-3">
            <label class="form-label small fw-bold">Логін</label>
            <input type="text" name="login" class="form-control"
                   value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                   minlength="3" required autofocus placeholder="my_login">
            <div class="form-text">Тільки латинські літери, цифри, _ і -</div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">Отображуване ім'я</label>
            <input type="text" name="display_name" class="form-control"
                   value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
                   placeholder="Ваше ім'я (опціонально)">
            <div class="form-text">Якщо порожньо — буде використано логін</div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">Email</label>
            <input type="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   placeholder="user@example.com (опціонально)">
            <div class="form-text">Для восстановления доступа (опціонально)</div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">Пароль</label>
            <input type="password" name="password" class="form-control" minlength="6" required placeholder="Мінімум 6 символів">
        </div>
        <div class="mb-4">
            <label class="form-label small fw-bold">Повторіть пароль</label>
            <input type="password" name="password2" class="form-control" minlength="6" required>
        </div>

        <?php if (!empty($invite['require_2fa'])): ?>
        <div class="alert alert-info small py-2 mb-3">
            🔐 Після реєстрації вам буде показано секретний ключ для налаштування двофакторної автентифікації.
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary w-100">
            <?= !empty($invite['require_2fa']) ? '➡️ Далі — налаштувати 2FA' : '✅ Створити акаунт' ?>
        </button>
    </form>
    <p class="text-center text-muted small mt-3">Вже є акаунт? <a href="/templates/login.php">Увійти</a></p>
<?php endif; ?>

</div>
</div>
</div>

<script>
function copySecret() {
    var el = document.getElementById('totpSecretField');
    if (!el) return;
    el.select();
    document.execCommand('copy');
    var btn = el.nextElementSibling;
    var orig = btn.textContent;
    btn.textContent = '✅';
    setTimeout(function(){ btn.textContent = orig; }, 1500);
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>