<?php
/**
 * admin/totp_users_api.php
 * JSON API для керування 2FA користувачів (тільки для адміна)
 * Використовується з totp_users.php через fetch()
 */
session_start();

header('Content-Type: application/json; charset=utf-8');

// Тільки POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Тільки адмін
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Доступ заборонено']);
    exit;
}

require_once __DIR__ . '/../data/totp.php';
require_once __DIR__ . '/../data/log_action.php';

$functionsFile = __DIR__ . '/functions.php';
if (file_exists($functionsFile)) {
    require_once $functionsFile;
    $pdo = connectToDatabase();
} else {
    require_once __DIR__ . '/../config.php';
    $pdo = fly_db();
}

$adminLogin = $_SESSION['username'];

// CSRF перевірка
$bodyRaw = file_get_contents('php://input');
$body    = json_decode($bodyRaw, true);

// Підтримка і JSON body, і FormData
if ($body === null) {
    $body = $_POST;
}

$csrfToken   = $body['csrf_token'] ?? '';
$action      = $body['action']      ?? '';
$targetLogin = trim($body['target_login'] ?? '');

if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Невірний токен безпеки']);
    exit;
}

if (empty($targetLogin)) {
    echo json_encode(['ok' => false, 'error' => 'Не вказано логін користувача']);
    exit;
}

// Перевірка наявності TOTP полів
$cols    = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
$hasTotp = in_array('totp_enabled', $cols) && in_array('totp_secret', $cols);

if (!$hasTotp) {
    echo json_encode(['ok' => false, 'error' => 'Потрібна міграція БД']);
    exit;
}

// Отримуємо користувача
$checkStmt = $pdo->prepare("SELECT id, login, totp_secret, totp_enabled FROM users WHERE login = ?");
$checkStmt->execute([$targetLogin]);
$targetUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    echo json_encode(['ok' => false, 'error' => 'Користувача не знайдено']);
    exit;
}

// Отримуємо назву сайту для QR
$siteTitle = 'fly-CMS';
try {
    $s = $pdo->query("SELECT value FROM settings WHERE key='site_title'")->fetchColumn();
    if ($s) $siteTitle = $s;
} catch (Exception $e) {}

// ── Дії ──────────────────────────────────────────────────────────────

// Генерація нового секрету
if ($action === 'generate') {
    $newSecret = TOTP::generateSecret();
    $stmt = $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE login = ?");
    $stmt->execute([$newSecret, $targetLogin]);
    log_action("🔑 Адмін згенерував секрет 2FA для '{$targetLogin}'", $adminLogin);

    // Оновлюємо CSRF токен
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'ok'         => true,
        'action'     => 'generated',
        'secret'     => $newSecret,
        'qrUrl'      => TOTP::getQRCodeUrl($newSecret, $targetLogin, $siteTitle),
        'csrf_token' => $_SESSION['csrf_token'],
    ]);
    exit;
}

// Підтвердження коду та увімкнення 2FA
if ($action === 'enable') {
    $code        = trim($body['totp_code'] ?? '');
    $freshSecret = $targetUser['totp_secret'] ?? '';

    if (empty($freshSecret)) {
        echo json_encode(['ok' => false, 'error' => 'Спочатку згенеруйте секрет для цього користувача']);
        exit;
    }

    if (!TOTP::verify($freshSecret, $code)) {
        echo json_encode(['ok' => false, 'error' => 'Невірний код підтвердження. Перевірте Google Authenticator та спробуйте ще раз']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE users SET totp_enabled = 1 WHERE login = ?");
    $stmt->execute([$targetLogin]);
    log_action("✅ Адмін увімкнув 2FA для '{$targetLogin}'", $adminLogin);

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'ok'         => true,
        'action'     => 'enabled',
        'csrf_token' => $_SESSION['csrf_token'],
    ]);
    exit;
}

// Скидання 2FA
if ($action === 'reset') {
    // Заборона скидання 2FA superadmin не-superadmin
    $chkR = $pdo->prepare('SELECT role FROM users WHERE login=?');
    $chkR->execute([$targetLogin]);
    if ($chkR->fetchColumn() === 'superadmin' && $adminLogin !== 'superadmin' && !in_array($_SESSION['role'] ?? '', ['superadmin'])) {
        echo json_encode(['success' => false, 'error' => 'Скидання 2FA SuperAdmin заборонено']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE login = ?");
    $stmt->execute([$targetLogin]);
    log_action("🔄 Адмін скинув 2FA для '{$targetLogin}'", $adminLogin);

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'ok'         => true,
        'action'     => 'reset',
        'csrf_token' => $_SESSION['csrf_token'],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Невідома дія: ' . htmlspecialchars($action)]);
