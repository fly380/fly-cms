<?php
/**
 * admin/invite.php
 * Генерація одноразових посилань-запрошень + відправка на email
 */
session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/smtp_helper.php';

$pdo      = connectToDatabase();
$username = $_SESSION['username'];
$message  = '';
$msgType  = '';

// ─── CSRF ─────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── Таблиця invitations ──────────────────────────────────────────
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='invitations'")->fetchColumn();
if (!$tables) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT UNIQUE NOT NULL,
        email TEXT,
        role TEXT DEFAULT 'user',
        created_by TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used_at DATETIME,
        used_by TEXT,
        require_2fa INTEGER DEFAULT 0,
        email_sent INTEGER DEFAULT 0
    )");
} else {
    $cols = array_column($pdo->query("PRAGMA table_info(invitations)")->fetchAll(), 'name');
    if (!in_array('email_sent', $cols)) {
        $pdo->exec("ALTER TABLE invitations ADD COLUMN email_sent INTEGER DEFAULT 0");
    }
    if (!in_array('require_2fa', $cols)) {
        $pdo->exec("ALTER TABLE invitations ADD COLUMN require_2fa INTEGER DEFAULT 0");
    }
}

// ─── SMTP стан ────────────────────────────────────────────────────
$smtpCfg  = @include __DIR__ . '/email_config.php';
$smtpArr  = is_array($smtpCfg) ? (isset($smtpCfg['smtp']) ? $smtpCfg['smtp'] : []) : [];
$smtpOn   = !empty($smtpArr['enabled']);
$smtpHost = isset($smtpArr['host'])     ? $smtpArr['host']     : '';
$smtpUser = isset($smtpArr['username']) ? $smtpArr['username'] : '';
$smtpPass = isset($smtpArr['password']) ? $smtpArr['password'] : '';
$smtpOk   = $smtpOn && $smtpHost && $smtpUser && $smtpPass;
$isGmail  = (strpos($smtpHost, 'gmail') !== false);

// ─── Відправка листа-запрошення ───────────────────────────────────
function send_invite_email(string $to, string $inviteUrl, string $role, string $expires, string $createdBy): array {
    $smtpCfg = @include __DIR__ . '/email_config.php';
    $smtpArr = is_array($smtpCfg) ? (isset($smtpCfg['smtp']) ? $smtpCfg['smtp'] : []) : [];

    if (empty($smtpArr['enabled']))  return ['sent' => false, 'error' => 'SMTP вимкнено'];
    if (empty($smtpArr['host']))     return ['sent' => false, 'error' => 'SMTP_HOST порожній'];
    if (empty($smtpArr['username'])) return ['sent' => false, 'error' => 'SMTP_USERNAME порожній'];
    if (empty($smtpArr['password'])) return ['sent' => false, 'error' => 'SMTP_PASSWORD порожній'];

    $host       = $smtpArr['host'];
    $port       = (int)(isset($smtpArr['port']) ? $smtpArr['port'] : 587);
    $smtpUser   = $smtpArr['username'];
    $smtpPass   = $smtpArr['password'];
    $encryption = isset($smtpArr['encryption']) ? $smtpArr['encryption'] : 'tls';
    $fromEmail  = isset($smtpArr['from_email']) ? $smtpArr['from_email'] : $smtpUser;
    $fromName   = isset($smtpArr['from_name'])  ? $smtpArr['from_name']  : 'fly-CMS';

    $roleLabels = ['admin' => 'Адміністратор', 'redaktor' => 'Редактор', 'user' => 'Користувач'];
    $roleLabel  = isset($roleLabels[$role]) ? $roleLabels[$role] : $role;
    $siteName   = function_exists('get_setting') ? (get_setting('site_name') ?: (get_setting('site_title') ?: 'fly-CMS')) : 'fly-CMS';

    $subject   = '[' . $siteName . '] Запрошення для реєстрації';

    $body_text = "Вас запрошено зареєструватись на сайті " . $siteName . ".\n\n"
               . "Роль: " . $roleLabel . "\n"
               . "Посилання дійсне до: " . $expires . "\n\n"
               . "Посилання для реєстрації:\n" . $inviteUrl . "\n\n"
               . "Посилання одноразове — після використання стане недійсним.\n"
               . "Запрошення надіслав: " . $createdBy;

    $body_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;background:#f0f4fb;margin:0;padding:20px">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">'
        . '<div style="background:linear-gradient(135deg,#1a3d6e,#2E5FA3);color:#fff;padding:28px 32px">'
        . '<div style="font-size:1.3rem;margin-bottom:4px">&#10008; ' . htmlspecialchars($siteName) . '</div>'
        . '<div style="font-size:.9rem;opacity:.8">Запрошення для реєстрації</div></div>'
        . '<div style="padding:28px 32px">'
        . '<p style="margin:0 0 16px;color:#374151">Вас запрошено зареєструватись на сайті <strong>' . htmlspecialchars($siteName) . '</strong>.</p>'
        . '<div style="background:#f3f4f6;border-radius:8px;padding:16px;margin-bottom:20px">'
        . '<div style="font-size:.82rem;color:#6b7280;margin-bottom:4px">Ваша роль</div>'
        . '<div style="font-weight:700;color:#1f2937">' . htmlspecialchars($roleLabel) . '</div>'
        . '<div style="font-size:.82rem;color:#6b7280;margin-top:10px;margin-bottom:4px">Посилання дійсне до</div>'
        . '<div style="font-weight:600;color:#1f2937">' . htmlspecialchars($expires) . '</div>'
        . '</div>'
        . '<a href="' . htmlspecialchars($inviteUrl) . '" style="display:block;text-align:center;background:#2E5FA3;color:#fff;text-decoration:none;padding:14px 24px;border-radius:8px;font-weight:700;font-size:1rem;margin-bottom:16px">Зареєструватись</a>'
        . '<p style="font-size:.78rem;color:#9ca3af;word-break:break-all;margin:0">Або скопіюйте: ' . htmlspecialchars($inviteUrl) . '</p>'
        . '</div>'
        . '<div style="padding:14px 32px;background:#f3f4f6;font-size:.75rem;color:#9ca3af">'
        . 'Посилання одноразове. Запрошення надіслав: ' . htmlspecialchars($createdBy) . '</div>'
        . '</div></body></html>';

    $res = fly_smtp_send($to, $subject, $body_html, $body_text);
    return ['sent' => $res['sent'], 'error' => $res['error']];
}

// ─── POST дії ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Невірний токен безпеки.';
        $msgType = 'danger';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        // ── Створити ─────────────────────────────────────────────
        if ($action === 'create') {
            $role       = in_array(isset($_POST['role']) ? $_POST['role'] : '', ['admin','redaktor','user']) ? $_POST['role'] : 'user';
            $email      = trim(isset($_POST['email']) ? $_POST['email'] : '');
            $hours      = max(1, min(168, (int)(isset($_POST['expires_hours']) ? $_POST['expires_hours'] : 48)));
            $require2fa = isset($_POST['require_2fa']) ? 1 : 0;

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Вкажіть коректний email адресата запрошення.';
                $msgType = 'warning';
            } else {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO invitations (token, email, role, created_by, expires_at, require_2fa, email_sent)
                               VALUES (?, ?, ?, ?, datetime('now', '+' || ? || ' hours'), ?, 0)")
                    ->execute([$token, $email, $role, $username, $hours, $require2fa]);
                $invId = $pdo->lastInsertId();

                $proto     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $inviteUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/templates/register.php?token=' . $token;

                $emailStatus = '';
                if ($smtpOk) {
                    $expiresStr = date('d.m.Y H:i', strtotime('+' . $hours . ' hours'));
                    $mailRes = send_invite_email($email, $inviteUrl, $role, $expiresStr, $username);
                    if ($mailRes['sent']) {
                        $pdo->prepare("UPDATE invitations SET email_sent=1 WHERE id=?")->execute([$invId]);
                        $emailStatus = ' ✉ Лист надіслано на ' . $email . '.';
                        $msgType     = 'success';
                    } else {
                        $emailStatus = ' ⚠ Email не надіслано: ' . $mailRes['error'];
                        $msgType     = 'warning';
                    }
                } else {
                    $emailStatus = ' ⚠ SMTP не налаштовано — скопіюйте посилання вручну.';
                    $msgType     = 'warning';
                }

                log_action("Створено запрошення (роль: {$role}, email: {$email})", $username);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $message = 'Запрошення створено.' . $emailStatus;
            }
        }

        // ── Видалити ─────────────────────────────────────────────
        if ($action === 'delete') {
            $id = (int)(isset($_POST['invite_id']) ? $_POST['invite_id'] : 0);
            $pdo->prepare("DELETE FROM invitations WHERE id = ? AND used_at IS NULL")->execute([$id]);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $message = 'Запрошення видалено.';
            $msgType = 'secondary';
        }

        // ── Надіслати повторно ────────────────────────────────────
        if ($action === 'resend') {
            $id   = (int)(isset($_POST['invite_id']) ? $_POST['invite_id'] : 0);
            $stmt = $pdo->prepare("SELECT * FROM invitations WHERE id=? AND used_at IS NULL");
            $stmt->execute([$id]);
            $inv  = $stmt->fetch();

            if (!$inv || empty($inv['email'])) {
                $message = 'Запрошення не знайдено або вже використано.';
                $msgType = 'danger';
            } elseif (!$smtpOk) {
                $message = 'SMTP не налаштовано.';
                $msgType = 'warning';
            } else {
                $proto      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $inviteUrl  = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/templates/register.php?token=' . $inv['token'];
                $expiresStr = date('d.m.Y H:i', strtotime($inv['expires_at']));
                $mailRes    = send_invite_email($inv['email'], $inviteUrl, $inv['role'], $expiresStr, $username);
                if ($mailRes['sent']) {
                    $pdo->prepare("UPDATE invitations SET email_sent=1 WHERE id=?")->execute([$id]);
                    $message = 'Лист повторно надіслано на ' . $inv['email'] . '.';
                    $msgType = 'success';
                } else {
                    $message = 'Не вдалося надіслати: ' . $mailRes['error'];
                    $msgType = 'danger';
                }
            }
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

// ─── Список ───────────────────────────────────────────────────────
$invites = $pdo->query("SELECT * FROM invitations ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$proto   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

ob_start();
?>
<style>
.smtp-bar      { border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-bottom:1rem; }
.smtp-bar-ok   { background:#f0fdf4; border:1px solid #86efac; color:#166534; }
.smtp-bar-warn { background:#fff8e1; border:1px solid #f59e0b; color:#92400e; }
.smtp-bar-note { background:rgba(0,0,0,.06); border-radius:4px; padding:.15rem .5rem; font-size:.77rem; }
.smtp-bar-note a { color:inherit; text-decoration:underline; font-weight:700; }
.smtp-bar-link { font-size:.8rem; font-weight:600; white-space:nowrap; color:#1e3a6e;
    text-decoration:none; border:1px solid #1e3a6e; border-radius:5px; padding:.2rem .6rem; }
.smtp-bar-link:hover { background:#1e3a6e; color:#fff; }
</style>

<div class="container-fluid px-4">

  <div class="d-flex align-items-center mb-3">
    <div>
      <h1 class="h3 mb-0">✉️ Запрошення користувачів</h1>
      <p class="text-muted small mt-1">Одноразові посилання для реєстрації</p>
    </div>
  </div>

  <!-- SMTP статус -->
  <div class="smtp-bar <?= $smtpOk ? 'smtp-bar-ok' : 'smtp-bar-warn' ?>">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span><?= $smtpOk ? '✅' : '⚠' ?></span>
      <span>
        <?php if ($smtpOk): ?>
          Email активний &mdash; <strong><?= htmlspecialchars($smtpHost) ?></strong> /
          <?= htmlspecialchars($smtpUser) ?> &mdash; запрошення надсилатимуться автоматично
        <?php elseif (!$smtpOn): ?>
          <strong>SMTP вимкнено</strong> &mdash; листи не надсилаються, посилання копіюйте вручну.
        <?php elseif (!$smtpHost): ?>
          <strong>SMTP_HOST порожній</strong> &mdash; вкажіть сервер (напр. <code>smtp.gmail.com</code>).
        <?php else: ?>
          <strong>SMTP не налаштовано</strong> &mdash; відсутній логін або пароль.
        <?php endif; ?>
      </span>
      <?php if ($isGmail): ?>
      <span class="smtp-bar-note">
        📌 Gmail: потрібен
        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">пароль додатків</a>,
        не звичайний пароль
      </span>
      <?php endif; ?>
      <a href="/admin/smtp_settings.php" class="smtp-bar-link ms-auto">⚙ Налаштування SMTP</a>
    </div>
  </div>

  <?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- Форма -->
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
          <strong>➕ Нове запрошення</strong>
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="mb-3">
              <label class="form-label small fw-bold">
                Email одержувача <span class="text-danger">*</span>
              </label>
              <input type="email" name="email" class="form-control form-control-sm"
                     placeholder="user@example.com" required>
              <div class="form-text">
                <?php if ($smtpOk): ?>
                  Лист із запрошенням буде надіслано автоматично
                <?php else: ?>
                  Email збережеться, але лист не надішлеться (SMTP не налаштовано)
                <?php endif; ?>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-bold">Роль нового користувача</label>
              <select name="role" class="form-select form-select-sm">
                <option value="user">👤 Користувач</option>
                <option value="redaktor">✏️ Редактор</option>
                <option value="admin">🔴 Адмін</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-bold">Термін дії</label>
              <select name="expires_hours" class="form-select form-select-sm">
                <option value="1">1 година</option>
                <option value="24">24 години</option>
                <option value="48" selected>48 годин</option>
                <option value="72">3 дні</option>
                <option value="168">7 днів</option>
              </select>
            </div>

            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="require_2fa" id="require2fa" value="1">
                <label class="form-check-label small fw-bold" for="require2fa">
                  🔐 Налаштувати 2FA при реєстрації
                </label>
                <div class="form-text">Після введення логіна/пароля користувач отримає ключ 2FA</div>
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
              <?= $smtpOk ? '📨 Надіслати запрошення' : '🔗 Згенерувати посилання' ?>
            </button>
          </form>
        </div>
      </div>

      <div class="card mt-3 border-info">
        <div class="card-body small text-muted">
          <strong>ℹ️ Як це працює:</strong><br>
          <?php if ($smtpOk): ?>
            Ви вводите email → система надсилає лист із посиланням →
            користувач переходить → заповнює логін і пароль →
            акаунт створюється з вказаною роллю автоматично.
          <?php else: ?>
            Ви генеруєте посилання → копіюєте його вручну → надсилаєте користувачу →
            він переходить → заповнює логін і пароль →
            акаунт створюється з вказаною роллю автоматично.
          <?php endif; ?>
          <br><br>Посилання одноразове — після використання стає недійсним.
        </div>
      </div>
    </div>

    <!-- Список -->
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
          <strong>📋 Активні та використані запрошення</strong>
        </div>
        <?php if (empty($invites)): ?>
        <div class="card-body text-center text-muted py-5">
          <div style="font-size:3rem">✉️</div>
          <p class="mt-2">Запрошень ще немає</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
              <tr>
                <th>Email / Посилання</th>
                <th>Роль</th>
                <th>Статус</th>
                <th>Діє до</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($invites as $inv):
                $isUsed    = !empty($inv['used_at']);
                $isExpired = !$isUsed && strtotime($inv['expires_at']) < time();
                $invUrl    = $baseUrl . '/templates/register.php?token=' . $inv['token'];
                $roleMap   = ['admin'=>['Адмін','danger'],'redaktor'=>['Редактор','warning'],'user'=>['Користувач','secondary']];
                list($rl, $rc) = isset($roleMap[$inv['role']]) ? $roleMap[$inv['role']] : [$inv['role'],'secondary'];
                $emailSent = !empty($inv['email_sent']);
            ?>
            <tr class="<?= ($isUsed || $isExpired) ? 'opacity-50' : '' ?>">
              <td>
                <div class="fw-semibold" style="font-size:.85rem">
                  <?= htmlspecialchars($inv['email'] ?? '—') ?>
                  <?php if ($emailSent): ?>
                    <span class="badge bg-success ms-1" style="font-size:.65rem">✉ надіслано</span>
                  <?php elseif (!$isUsed && !$isExpired && !empty($inv['email'])): ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">не надіслано</span>
                  <?php endif; ?>
                </div>
                <?php if (!$isUsed && !$isExpired): ?>
                <div class="input-group input-group-sm mt-1" style="max-width:280px">
                  <input type="text" class="form-control font-monospace"
                         value="<?= htmlspecialchars($invUrl) ?>"
                         id="inv-<?= $inv['id'] ?>" readonly>
                  <button class="btn btn-outline-secondary"
                          onclick="copyInvite('inv-<?= $inv['id'] ?>')" title="Копіювати">📋</button>
                </div>
                <?php else: ?>
                <span class="text-muted font-monospace" style="font-size:.72em">
                  <?= substr($inv['token'], 0, 16) ?>…
                </span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-<?= $rc ?>"><?= $rl ?></span>
                <?php if (!empty($inv['require_2fa'])): ?>
                  <span class="badge bg-info text-dark ms-1">🔐 2FA</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isUsed): ?>
                  <span class="badge bg-success">✅ Використано</span>
                  <?php if ($inv['used_by']): ?>
                    <div class="text-muted" style="font-size:.75em"><?= htmlspecialchars($inv['used_by']) ?></div>
                  <?php endif; ?>
                <?php elseif ($isExpired): ?>
                  <span class="badge bg-secondary">⏰ Прострочено</span>
                <?php else: ?>
                  <span class="badge bg-primary">🟢 Активне</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" style="white-space:nowrap">
                <?= date('d.m H:i', strtotime($inv['expires_at'])) ?>
              </td>
              <td style="white-space:nowrap">
                <?php if (!$isUsed && !$isExpired && !empty($inv['email']) && $smtpOk): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="resend">
                  <input type="hidden" name="invite_id" value="<?= $inv['id'] ?>">
                  <button type="submit" class="btn btn-outline-primary btn-sm" title="Надіслати повторно">↩ Ще раз</button>
                </form>
                <?php endif; ?>
                <?php if (!$isUsed): ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Видалити запрошення?')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="invite_id" value="<?= $inv['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm">🗑</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script>
function copyInvite(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.select();
    document.execCommand('copy');
    var btn = el.nextElementSibling;
    var orig = btn.textContent;
    btn.textContent = '✅';
    setTimeout(function() { btn.textContent = orig; }, 1500);
}
</script>

<?php
$content_html = ob_get_clean();
$page_title   = 'Запрошення';
include __DIR__ . '/admin_template.php';
