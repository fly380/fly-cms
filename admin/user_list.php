<?php
// admin/user_list.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/../data/totp.php';

$pdo      = connectToDatabase();
$username = $_SESSION['username'] ?? 'невідомо';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── JSON API для 2FA AJAX-запитів ─────────────────────────────────────
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $body        = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $csrfInput   = $body['csrf_token']   ?? '';
    $action      = $body['action']       ?? '';
    $targetLogin = trim($body['target_login'] ?? '');

    if (empty($csrfInput) || $csrfInput !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Невірний токен безпеки']); exit;
    }
    if (empty($targetLogin)) {
        echo json_encode(['ok' => false, 'error' => 'Не вказано логін']); exit;
    }

    $cols    = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $hasTotp = in_array('totp_enabled', $cols) && in_array('totp_secret', $cols);
    if (!$hasTotp) { echo json_encode(['ok' => false, 'error' => 'Потрібна міграція БД']); exit; }

    $row = $pdo->prepare("SELECT totp_secret, totp_enabled FROM users WHERE login = ?");
    $row->execute([$targetLogin]);
    $targetUser = $row->fetch(PDO::FETCH_ASSOC);
    if (!$targetUser) { echo json_encode(['ok' => false, 'error' => 'Користувача не знайдено']); exit; }

    $siteTitle = 'fly-CMS';
    try { $s = $pdo->query("SELECT value FROM settings WHERE key='site_title'")->fetchColumn(); if ($s) $siteTitle = $s; } catch(Exception $e) {}

    if ($action === 'generate') {
        $secret = TOTP::generateSecret();
        $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE login = ?")->execute([$secret, $targetLogin]);
        log_action("🔑 Адмін увімкнув 2FA для '{$targetLogin}'", $username);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode(['ok' => true, 'action' => 'generated', 'secret' => $secret,
            'qrUrl' => TOTP::getQRCodeUrl($secret, $targetLogin, $siteTitle),
            'csrf_token' => $_SESSION['csrf_token']]); exit;
    }

    if ($action === 'enable') {
        $code   = trim($body['totp_code'] ?? '');
        $secret = $targetUser['totp_secret'] ?? '';
        if (empty($secret))                    { echo json_encode(['ok' => false, 'error' => 'Спочатку згенеруйте секрет']); exit; }
        if (!TOTP::verify($secret, $code))     { echo json_encode(['ok' => false, 'error' => 'Невірний код підтвердження']); exit; }
        $pdo->prepare("UPDATE users SET totp_enabled = 1 WHERE login = ?")->execute([$targetLogin]);
        log_action("✅ Адмін підтвердив 2FA для '{$targetLogin}'", $username);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode(['ok' => true, 'action' => 'enabled', 'csrf_token' => $_SESSION['csrf_token']]); exit;
    }

    if ($action === 'reset') {
        $pdo->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE login = ?")->execute([$targetLogin]);
        log_action("🔄 Адмін скинув 2FA для '{$targetLogin}'", $username);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode(['ok' => true, 'action' => 'reset', 'csrf_token' => $_SESSION['csrf_token']]); exit;
    }

    if ($action === 'bulk_reset') {
        $logins = $body['logins'] ?? [];
        if (!is_array($logins) || empty($logins)) { echo json_encode(['ok' => false, 'error' => 'Не вказано користувачів']); exit; }
        $count = 0;
        foreach ($logins as $l) {
            $l = trim($l); if (empty($l)) continue;
            $pdo->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE login = ?")->execute([$l]);
            log_action("🔄 Адмін (масово) скинув 2FA для '{$l}'", $username);
            $count++;
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode(['ok' => true, 'count' => $count, 'csrf_token' => $_SESSION['csrf_token']]); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Невідома дія']); exit;
}
// ─────────────────────────────────────────────────────────────────────

$message     = '';
$messageType = '';

// Перевірка TOTP полів в БД
$cols    = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
$hasTotp = in_array('totp_enabled', $cols) && in_array('totp_secret', $cols);

// Видалення одного користувача
if (isset($_GET['delete'])) {
    $del = $_GET['delete'];
    $delStmt = $pdo->prepare('SELECT role FROM users WHERE login=?');
    $delStmt->execute([$del]);
    $delTargetRole = $delStmt->fetchColumn();
    if ($del === $_SESSION['username']) {
        $message = '❌ Ви не можете видалити свій власний обліковий запис.';
        $messageType = 'danger';
    } elseif ($delTargetRole === 'superadmin' && $_SESSION['role'] !== 'superadmin') {
        $message = '❌ Видалення SuperAdmin доступне лише SuperAdmin.';
        $messageType = 'danger';
    } else {
        try {
            deleteUser($pdo, $del);
            log_action("🗑️ Видалив користувача '{$del}'", $username);
            header('Location: user_list.php?deleted=1'); exit;
        } catch (PDOException $e) {
            $message = '❌ Не вдалося видалити: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Масове видалення
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $toDelete = json_decode($_POST['users'], true);
    $deleted  = 0; $errors = 0;
    foreach ($toDelete as $u) {
        if ($u === $_SESSION['username']) { $errors++; continue; }
        $bStmt = $pdo->prepare('SELECT role FROM users WHERE login=?');
        $bStmt->execute([$u]);
        if ($bStmt->fetchColumn() === 'superadmin' && $_SESSION['role'] !== 'superadmin') { $errors++; continue; }
        try { deleteUser($pdo, $u); log_action("🗑️ Видалив '{$u}'", $username); $deleted++; }
        catch (PDOException $e) { $errors++; }
    }
    $message = "✅ Видалено {$deleted} користувачів";
    if ($errors > 0) $message .= ", пропущено {$errors}";
    $messageType = $errors > 0 ? 'warning' : 'success';
}

// Редагування користувача
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_username'])) {
    $editLogin   = $_POST['edit_username'];
    $newPassword = $_POST['edit_password']      ?? '';
    $newDispName = trim($_POST['edit_display_name'] ?? '');
    $isSelf      = ($editLogin === $_SESSION['username']);

    $tStmt = $pdo->prepare('SELECT role FROM users WHERE login=?');
    $tStmt->execute([$editLogin]);
    $targetCurrentRole = $tStmt->fetchColumn() ?: 'user';

    if ($targetCurrentRole === 'superadmin' && $_SESSION['role'] !== 'superadmin') {
        $message = '❌ Редагування SuperAdmin доступне лише SuperAdmin.';
        $messageType = 'danger';
    } else {
    if ($isSelf) {
        $newRole = $targetCurrentRole;
    } else {
        $newRole = $_POST['edit_role'] ?? 'user';
        $allowed = $_SESSION['role'] === 'superadmin'
            ? ['superadmin','admin','redaktor','user']
            : ['admin','redaktor','user'];
        if (!in_array($newRole, $allowed, true)) $newRole = 'user';
    }

    try {
        updateUser($pdo, $editLogin, $newPassword ?: null, $newRole, $newDispName !== '' ? $newDispName : null);
        $logMsg = $isSelf
            ? "✏️ Змінив власні дані"
            : "✏️ Оновив '{$editLogin}' (роль: {$newRole})";
        log_action($logMsg, $username);
        header('Location: user_list.php?updated=1'); exit;
    } catch (PDOException $e) {
        $message = '❌ Не вдалося оновити: ' . $e->getMessage();
        $messageType = 'danger';
    }
    } // end superadmin guard
}

// Отримання списку користувачів
$users = [];
try {
    $selectCols = $hasTotp
        ? "id, login, display_name, role, totp_enabled, totp_secret"
        : "id, login, display_name, role";
    $users = $pdo->query("SELECT {$selectCols} FROM users ORDER BY role, login")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '❌ Не вдалося отримати користувачів: ' . $e->getMessage();
    $messageType = 'danger';
}

// Статистика
$totalUsers = count($users);
$adminCount = $editorCount = $userCount = 0;
$totpEnabled = $totpDisabled = 0;
foreach ($users as $u) {
    match($u['role']) {
        'admin'    => $adminCount++,
        'redaktor' => $editorCount++,
        default    => $userCount++,
    };
    if ($hasTotp) {
        !empty($u['totp_enabled']) ? $totpEnabled++ : $totpDisabled++;
    }
}

// Назва сайту для QR-кодів
$siteTitle = 'fly-CMS';
try { $s = $pdo->query("SELECT value FROM settings WHERE key='site_title'")->fetchColumn(); if ($s) $siteTitle = $s; } catch(Exception $e) {}

$page_title = 'Керування користувачами';
ob_start();
?>
<style>
:root {
    --primary: #667eea;
    --primary-hover: #5568d3;
    --secondary: #e2e8f0;
    --text: #2d3748;
    --text-muted: #718096;
    --border: #cbd5e0;
}

* { box-sizing: border-box; }

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
    min-height: 100vh;
}

.container-fluid { 
    background: white; 
    border-radius: 10px; 
    box-shadow: 0 2px 12px rgba(0,0,0,0.06); 
    margin: 20px;
}

h1, h3 { 
    color: var(--text);
    font-weight: 700;
}

h1 { 
    border-bottom: 2px solid var(--primary);
    padding-bottom: 12px;
    font-size: 1.75rem;
}

/* ────────────────────────── Table ──────────────────────────── */
.table { 
    margin-bottom: 0;
    border-radius: 8px;
    overflow: hidden;
    table-layout: fixed;
    width: 100%;
}

.table thead th { 
    background: var(--primary);
    color: white;
    font-weight: 700;
    border: none;
    padding: 1rem;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Ширина колонок */
.table th:nth-child(1),
.table td:nth-child(1) { width: 50px; text-align: center; }

.table th:nth-child(2),
.table td:nth-child(2) { width: 35%; }

.table th:nth-child(3),
.table td:nth-child(3) { width: 12%; }

.table th:nth-child(4),
.table td:nth-child(4) { width: 18%; }

.table th:nth-child(5),
.table td:nth-child(5) { width: auto; min-width: 280px; }

.table tbody tr { 
    transition: all 0.2s ease; 
    border-bottom: 1px solid #e2e8f0;
}

.table tbody tr:hover { 
    background: #f7fafc;
}

.table td { 
    vertical-align: middle; 
    padding: 1rem 0.75rem;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* ────────────────────────── Badges ──────────────────────────── */
.role-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.role-badge.superadmin {
    background: linear-gradient(135deg,#fef9c3,#fde68a);
    color: #92400e;
    border: 1px solid #fbbf24;
}

.role-badge.admin {
    background: #eef2ff;
    color: var(--primary);
}

.role-badge.editor {
    background: #f0fdf4;
    color: #16a34a;
}

.role-badge.user {
    background: var(--secondary);
    color: var(--text);
}

/* ────────────────────────── TOTP Badge ──────────────────────────── */
.totp-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.totp-on {
    background: #dbeafe;
    color: #1e40af;
}

.totp-off {
    background: #f3f4f6;
    color: #4b5563;
}

.totp-wait {
    background: #fef3c7;
    color: #b45309;
}

/* ────────────────────────── Action Buttons ──────────────────────────── */
.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.action-btn {
    padding: 0.5rem 0.9rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
    background: var(--secondary);
    color: var(--text);
    white-space: nowrap;
}

.action-btn:hover {
    background: #cbd5e0;
    transform: translateY(-1px);
}

.action-btn:active {
    transform: translateY(0);
}

.action-btn.edit-btn {
    background: var(--primary);
    color: white;
}

.action-btn.edit-btn:hover {
    background: var(--primary-hover);
}

.action-btn.delete-btn {
    background: #fed7aa;
    color: #92400e;
}

.action-btn.delete-btn:hover {
    background: #fdba74;
}

.action-btn.totp-btn {
    background: var(--primary);
    color: white;
}

.action-btn.totp-btn:hover {
    background: var(--primary-hover);
}

.action-btn.totp-reset-btn {
    background: #fecaca;
    color: #991b1b;
}

.action-btn.totp-reset-btn:hover {
    background: #fca5a5;
}

.action-btn:disabled,
.action-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* ────────────────────────── Stats Card ──────────────────────────── */
.stats-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    transition: all 0.2s ease;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: var(--primary);
}

.stats-number {
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
}

.stats-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-top: 8px;
}

/* ────────────────────────── User Info ──────────────────────────── */
.username {
    font-weight: 600;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.disp-name {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* ────────────────────────── Forms ──────────────────────────── */
.form-control,
.form-select {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.65rem 0.95rem;
    font-size: 0.95rem;
    transition: all 0.2s;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    outline: none;
}

.form-check-input {
    width: 1.2rem;
    height: 1.2rem;
    border: 1px solid var(--border);
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.form-check-input:checked {
    background: var(--primary);
    border-color: var(--primary);
}

.form-check-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15);
}

/* ────────────────────────── Buttons ──────────────────────────── */
.btn-primary {
    background: var(--primary);
    border: none;
    border-radius: 6px;
    padding: 0.65rem 1.3rem;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-primary:hover {
    background: var(--primary-hover);
    transform: translateY(-1px);
}

.btn-secondary {
    background: var(--secondary);
    color: var(--text);
    border: none;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

.btn-success {
    background: #10b981;
    border: none;
    border-radius: 6px;
    padding: 0.65rem 1.3rem;
    font-weight: 600;
    color: white;
    transition: all 0.2s;
}

.btn-success:hover {
    background: #059669;
    transform: translateY(-1px);
}

.btn-outline-secondary {
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 6px;
    background: white;
    font-weight: 600;
}

.btn-outline-secondary:hover {
    background: var(--secondary);
    border-color: var(--primary);
}

/* ────────────────────────── Alerts ──────────────────────────── */
.alert {
    border-radius: 6px;
    border: none;
    padding: 0.9rem 1.2rem;
    margin-bottom: 1.5rem;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert-warning {
    background: #fef08a;
    color: #713f12;
}

/* ────────────────────────── Modal ──────────────────────────── */
.modal-header {
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 10px 10px 0 0;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-content {
    border-radius: 10px;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
}

/* ────────────────────────── Responsive ──────────────────────────── */
@media (max-width: 1200px) {
    .table { table-layout: auto; }
    
    .table th:nth-child(5),
    .table td:nth-child(5) { min-width: 250px; }
}

@media (max-width: 768px) {
    .table { 
        table-layout: auto; 
        font-size: 0.85rem; 
    }
    
    .table th:nth-child(2),
    .table td:nth-child(2) { width: 40%; }
    
    .table th:nth-child(3),
    .table td:nth-child(3) { width: 20%; }
    
    .table th:nth-child(4),
    .table td:nth-child(4) { display: none; }
    
    .table th:nth-child(5),
    .table td:nth-child(5) { min-width: 200px; }
    
    .action-btn {
        padding: 0.4rem 0.7rem;
        font-size: 11px;
    }
    
    .role-badge {
        padding: 0.4rem 0.8rem;
        font-size: 11px;
    }
}
</style>

<div class="container-fluid px-4">

    <!-- Заголовок -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-2" style="margin-top: 20px;">
                <i class="bi bi-people me-2" style="color: #667eea;"></i>Керування користувачами
            </h1>
            <p class="text-muted small">👥 Облікові записи, ролі та двофакторна автентифікація</p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="email_logs.php" class="btn btn-secondary" style="font-size: 0.9rem; padding: 0.6rem 1.2rem;">
                <i class="bi bi-file-text me-1"></i> Логи Email
            </a>
            <a href="add_user.php" class="btn btn-success" style="font-size: 1rem; padding: 0.8rem 2rem;">
                <i class="bi bi-plus-lg me-2"></i> Додати користувача
            </a>
        </div>
    </div>

    <!-- Глобальний алерт (AJAX) -->
    <div id="globalAlert" class="alert alert-dismissible fade show d-none" role="alert">
        <span id="globalAlertText"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Повідомлення (PHP) -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Користувача видалено.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Дані оновлено.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Статистика -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?= $totalUsers ?></div>
                <div class="stats-label">👥 Всього</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?= $adminCount ?></div>
                <div class="stats-label">🛡 Адміністраторів</div>
            </div>
        </div>
        <?php if ($hasTotp): ?>
        <div class="col-6 col-md-3">
            <div class="stats-card">
                <div class="stats-number text-success"><?= $totpEnabled ?></div>
                <div class="stats-label">🔐 2FA увімкнено</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stats-card">
                <div class="stats-number text-secondary"><?= $totpDisabled ?></div>
                <div class="stats-label">⭕ 2FA вимкнено</div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-6 col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?= $editorCount ?></div>
                <div class="stats-label">✏️ Редакторів</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?= $userCount ?></div>
                <div class="stats-label">👤 Користувачів</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Таблиця -->
    <?php if (empty($users)): ?>
        <div class="text-center py-5 bg-white rounded-3 border">
            <div class="display-1 text-muted mb-3"><i class="bi bi-people"></i></div>
            <h3>Користувачів не знайдено</h3>
            <a href="add_user.php" class="btn btn-primary btn-lg mt-3">
                <i class="bi bi-plus-lg me-1"></i> Додати користувача
            </a>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-table me-2 text-primary"></i>
                    <strong>Список користувачів</strong>
                    <span class="badge bg-light text-dark ms-2"><?= $totalUsers ?></span>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($hasTotp): ?>
                    <button class="btn btn-sm btn-outline-warning d-none" id="bulkResetTotpBtn" onclick="bulkResetTotp()">
                        🔄 Скинути 2FA (<span id="bulkTotpCount">0</span>)
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-danger" id="bulkDeleteBtn" style="display:none" onclick="bulkDelete()">
                        <i class="bi bi-trash me-1"></i> Видалити (<span id="selectedCount">0</span>)
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="40"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                            <th>Користувач</th>
                            <th>Роль</th>
                            <?php if ($hasTotp): ?><th width="120">2FA</th><?php endif; ?>
                            <th width="210">Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $isMe      = ($u['login'] === $_SESSION['username']);
                            $isSuperadminRow = ($u['role'] === 'superadmin');
                            $canManage = !$isSuperadminRow || $_SESSION['role'] === 'superadmin';
                            $roleMap   = [
                                'superadmin' => ['superadmin', '👑 SuperAdmin',  'bi-star-fill'],
                                'admin'      => ['admin',      'Адміністратор', 'bi-shield-lock'],
                                'redaktor'   => ['editor',     'Редактор',      'bi-pencil-square'],
                            ];
                            [$rClass, $rName, $rIcon] = $roleMap[$u['role']] ?? ['user', 'Користувач', 'bi-person'];
                            $loginEsc  = htmlspecialchars($u['login'], ENT_QUOTES);
                            $dispName  = htmlspecialchars($u['display_name'] ?? '', ENT_QUOTES);
                            $is2FA     = $hasTotp && !empty($u['totp_enabled']);
                            $hasSecret = $hasTotp && !empty($u['totp_secret']);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="user-select" value="<?= $loginEsc ?>"
                                       onchange="updateBulkButton()" <?= ($isMe || !$canManage) ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <div class="username">
                                    <i class="bi bi-person-circle text-secondary fs-5"></i>
                                    <div>
                                        <div>
                                            <?= htmlspecialchars($u['login']) ?>
                                            <?php if ($isMe): ?>
                                                <span class="badge bg-primary ms-1" style="font-size:10px">Ви</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($dispName !== ''): ?>
                                            <div class="disp-name">👤 <?= $dispName ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge <?= $rClass ?>">
                                    <i class="bi <?= $rIcon ?> me-1"></i><?= $rName ?>
                                </span>
                            </td>
                            <?php if ($hasTotp): ?>
                            <td id="status-<?= $loginEsc ?>">
                                <?php if ($is2FA): ?>
                                    <span class="totp-badge totp-on">✅ Увімкнено</span>
                                <?php elseif ($hasSecret): ?>
                                    <span class="totp-badge totp-wait">⏳ Очікує</span>
                                <?php else: ?>
                                    <span class="totp-badge totp-off">⭕ Вимкнено</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="action-buttons d-flex gap-1 flex-wrap" id="actions-<?= $loginEsc ?>">
                                    <?php if ($canManage): ?>
                                    <button type="button" class="action-btn edit-btn"
                                            data-bs-toggle="modal" data-bs-target="#editModal"
                                            data-username="<?= $loginEsc ?>"
                                            data-display-name="<?= $dispName ?>"
                                            data-role="<?= htmlspecialchars($u['role']) ?>"
                                            data-is-self="<?= $isMe ? '1' : '0' ?>">
                                        <i class="bi bi-pencil"></i> Редагувати
                                    </button>
                                    <?php else: ?>
                                    <button class="action-btn edit-btn disabled" disabled
                                            style="opacity:.45;cursor:not-allowed" title="Редагування SuperAdmin заборонено">
                                        <i class="bi bi-lock"></i> Захищено
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($hasTotp): ?>
                                        <?php if (!$is2FA): ?>
                                            <button type="button" class="action-btn totp-btn btn-setup-2fa"
                                                    data-login="<?= $loginEsc ?>"
                                                    data-has-secret="<?= $hasSecret ? '1' : '0' ?>"
                                                    data-secret="<?= $hasSecret ? htmlspecialchars($u['totp_secret'], ENT_QUOTES) : '' ?>"
                                                    title="Налаштувати 2FA">
                                                🔑 2FA
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($is2FA || $hasSecret): ?>
                                            <?php if ($canManage): ?>
                                            <button type="button" class="action-btn totp-reset-btn btn-reset-2fa"
                                                    data-login="<?= $loginEsc ?>" title="Скинути 2FA">
                                                🔄 Скинути 2FA
                                            </button>
                                            <?php else: ?>
                                            <button class="action-btn totp-reset-btn disabled" disabled
                                                    style="opacity:.45;cursor:not-allowed" title="Скидання 2FA SuperAdmin заборонено">
                                                🔒 2FA захищено
                                            </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($isMe): ?>
                                        <button class="action-btn delete-btn disabled" disabled title="Не можна видалити свій запис">
                                            <i class="bi bi-trash"></i> Видалити
                                        </button>
                                    <?php elseif (!$canManage): ?>
                                        <button class="action-btn delete-btn disabled" disabled
                                                style="opacity:.45;cursor:not-allowed" title="Видалення SuperAdmin заборонено">
                                            <i class="bi bi-lock"></i> Захищено
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="action-btn delete-btn"
                                                onclick="deleteUser('<?= $loginEsc ?>')">
                                            <i class="bi bi-trash"></i> Видалити
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ── Модаль: Редагування ──────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="user_list.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>Редагування користувача
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_username" id="edit_username">

                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="bi bi-person me-1"></i>Логін</label>
                        <input type="text" class="form-control" id="edit_username_display" disabled readonly>
                        <small class="text-muted">Логін змінити не можна</small>
                    </div>

                    <div class="mb-3">
                        <label for="edit_display_name" class="form-label fw-bold">
                            <i class="bi bi-person-badge me-1"></i>Відображуване ім'я
                        </label>
                        <input type="text" class="form-control" id="edit_display_name"
                               name="edit_display_name" placeholder="Ім'я що бачать інші">
                    </div>

                    <div id="edit_self_note" class="alert alert-info py-2 small mb-3 d-none">
                        🔒 Ви редагуєте власний акаунт — зміна ролі недоступна
                    </div>

                    <div class="mb-3">
                        <label for="edit_password" class="form-label fw-bold">
                            <i class="bi bi-key me-1"></i>Новий пароль
                        </label>
                        <input type="password" class="form-control" id="edit_password"
                               name="edit_password" placeholder="Порожньо — не змінювати">
                        <small class="text-muted">Мінімум 6 символів</small>
                    </div>

                    <div class="mb-3" id="edit_role_block">
                        <label for="edit_role" class="form-label fw-bold">
                            <i class="bi bi-shield me-1"></i>Роль
                        </label>
                        <select class="form-select" name="edit_role" id="edit_role">
                            <option value="user">Користувач</option>
                            <option value="redaktor">Редактор</option>
                            <option value="admin">Адміністратор</option>
                            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                            <option value="superadmin">👑 SuperAdmin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Зберегти
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Модаль: Налаштування 2FA ────────────────────────────────── -->
<div class="modal fade" id="setupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">🔑 Налаштування 2FA: <span id="setupModalUser"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="setupModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Завантаження…</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Модаль: Скидання 2FA ─────────────────────────────────────── -->
<div class="modal fade" id="resetTotpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">⚠️ Скидання 2FA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div style="font-size:3rem">🔄</div>
                <p class="mt-2 mb-0">Скинути 2FA для <strong id="resetTotpUser"></strong>?</p>
                <p class="text-muted small mt-2">Секрет і статус 2FA будуть видалені.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                <button type="button" class="btn btn-danger" id="confirmResetTotpBtn">🗑 Скинути</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Модаль: Підтвердження видалення ─────────────────────────── -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>Підтвердження
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-3" id="confirmMessage">Ви впевнені?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                <button type="button" class="btn btn-danger" id="confirmAction">Підтвердити</button>
            </div>
        </div>
    </div>
</div>

<script>
var csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
var siteTitle = <?= json_encode($siteTitle) ?>;
var API_URL   = '/admin/user_list.php';

// ── Редагування — заповнення модалі ──────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    window._confirmModal = confirmModal;

    var editModal = document.getElementById('editModal');
    editModal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        document.getElementById('edit_username').value         = btn.dataset.username;
        document.getElementById('edit_username_display').value = btn.dataset.username;
        document.getElementById('edit_display_name').value     = btn.dataset.displayName || '';
        document.getElementById('edit_role').value             = btn.dataset.role;
        document.getElementById('edit_password').value         = '';
        var isSelf = btn.dataset.isSelf === '1';
        document.getElementById('edit_role_block').style.display = isSelf ? 'none' : '';
        document.getElementById('edit_self_note').classList.toggle('d-none', !isSelf);
    });
});

// ── Видалення ─────────────────────────────────────────────────────
function deleteUser(username) {
    document.getElementById('confirmMessage').innerHTML =
        '<i class="bi bi-exclamation-triangle text-warning" style="font-size:48px"></i>' +
        '<p class="mt-3 mb-0">Видалити <strong>"' + esc(username) + '"</strong>?</p>' +
        '<small class="text-muted">Цю дію не можна скасувати!</small>';
    document.getElementById('confirmAction').onclick = function () {
        window._confirmModal.hide();
        window.location.href = 'user_list.php?delete=' + encodeURIComponent(username);
    };
    window._confirmModal.show();
}

function toggleAll(cb) {
    document.querySelectorAll('.user-select:not(:disabled)').forEach(function (c) { c.checked = cb.checked; });
    updateBulkButton();
}

function updateBulkButton() {
    var checked = document.querySelectorAll('.user-select:checked');
    var n = checked.length;
    var delBtn = document.getElementById('bulkDeleteBtn');
    delBtn.style.display = n > 0 ? 'inline-block' : 'none';
    document.getElementById('selectedCount').textContent = n;
    <?php if ($hasTotp): ?>
    var totpBtn = document.getElementById('bulkResetTotpBtn');
    document.getElementById('bulkTotpCount').textContent = n;
    totpBtn.classList.toggle('d-none', n === 0);
    <?php endif; ?>
}

function bulkDelete() {
    var users = Array.from(document.querySelectorAll('.user-select:checked')).map(function (c) { return c.value; });
    if (!users.length) return;
    document.getElementById('confirmMessage').innerHTML =
        '<i class="bi bi-exclamation-triangle text-warning" style="font-size:48px"></i>' +
        '<p class="mt-3 mb-0">Видалити <strong>' + users.length + '</strong> користувачів?</p>' +
        '<small class="text-muted">Ваш власний запис не буде видалено!</small>';
    document.getElementById('confirmAction').onclick = function () {
        window._confirmModal.hide();
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="bulk_delete">' +
                         '<input type="hidden" name="users" value=\'' + JSON.stringify(users) + '\'>';
        document.body.appendChild(form); form.submit();
    };
    window._confirmModal.show();
}

// ── 2FA: делегований обробник кнопок ─────────────────────────────
window.addEventListener('load', function () {
    document.addEventListener('click', function (e) {
        var setupBtn  = e.target.closest('.btn-setup-2fa');
        var resetBtn  = e.target.closest('.btn-reset-2fa');
        var genBtn    = e.target.closest('.btn-do-generate');
        var enableBtn = e.target.closest('.btn-do-enable');
        var copyBtn   = e.target.closest('.btn-copy-secret');

        if (setupBtn)  openSetupModal(setupBtn.dataset.login, setupBtn.dataset.hasSecret === '1', setupBtn.dataset.secret || null);
        if (resetBtn)  openResetTotpModal(resetBtn.dataset.login);
        if (genBtn)    generateSecret(genBtn.dataset.login);
        if (enableBtn) enableTotp(enableBtn.dataset.login);
        if (copyBtn)   { var i = document.getElementById('modalSecretKey'); if (i) { i.select(); document.execCommand('copy'); } }
    });

    // Авто-сабміт при 6 цифрах
    document.getElementById('setupModal').addEventListener('input', function (e) {
        if (e.target && e.target.id === 'totpCodeInput' && /^\d{6}$/.test(e.target.value)) {
            var btn = document.querySelector('.btn-do-enable');
            if (btn) enableTotp(btn.dataset.login);
        }
    });
});

function openSetupModal(login, hasExisting, existingSecret) {
    document.getElementById('setupModalUser').textContent = login;
    hasExisting && existingSecret
        ? renderSetupStep2(login, existingSecret, buildQrUrl(existingSecret, login, siteTitle))
        : renderSetupStep1(login);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('setupModal')).show();
}

function buildQrUrl(secret, account, issuer) {
    var otpauth = 'otpauth://totp/' + encodeURIComponent(issuer) + ':' + encodeURIComponent(account)
        + '?secret=' + encodeURIComponent(secret) + '&issuer=' + encodeURIComponent(issuer)
        + '&algorithm=SHA1&digits=6&period=30';
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(otpauth);
}

function renderSetupStep1(login) {
    document.getElementById('setupModalBody').innerHTML =
        '<div class="text-center py-5">' +
        '<div style="font-size:3rem">🔑</div>' +
        '<p class="mt-3 text-muted">Натисніть кнопку для генерації секретного ключа.<br>' +
        'QR-код буде відразу готовий для сканування у Google Authenticator.</p>' +
        '<button class="btn btn-primary mt-1 btn-do-generate" data-login="' + esc(login) + '">🔄 Згенерувати секрет</button>' +
        '</div>';
}

function renderSetupStep2(login, secret, qrUrl) {
    document.getElementById('setupModalBody').innerHTML =
        '<div class="row g-4 p-2">' +
          '<div class="col-md-5 text-center">' +
            '<p class="text-muted small mb-2">Відскануйте QR у Google Authenticator:</p>' +
            '<img src="' + qrUrl + '" alt="QR" class="border rounded p-2" style="max-width:180px;width:100%">' +
            '<p class="small text-muted mt-3 mb-1">Або ключ вручну:</p>' +
            '<div class="input-group input-group-sm">' +
              '<input type="text" class="form-control font-monospace text-center" value="' + esc(secret) + '" id="modalSecretKey" readonly>' +
              '<button class="btn btn-outline-secondary btn-copy-secret" title="Копіювати">📋</button>' +
            '</div>' +
            '<small class="text-muted">TOTP · SHA1 · 6 цифр · 30 сек</small>' +
          '</div>' +
          '<div class="col-md-7">' +
            '<h6 class="fw-bold text-primary">Підтвердьте код для активації</h6>' +
            '<p class="small text-muted">Після сканування у Google Authenticator введіть 6-значний код для <strong>' + esc(login) + '</strong>.</p>' +
            '<div id="enableError" class="alert alert-danger py-2 small d-none"></div>' +
            '<div class="d-flex gap-2 align-items-center mt-3">' +
              '<input type="text" id="totpCodeInput" class="form-control font-monospace text-center" ' +
                     'maxlength="6" inputmode="numeric" placeholder="000000" autocomplete="off" ' +
                     'style="font-size:1.4rem;letter-spacing:.4rem;max-width:170px;">' +
              '<button class="btn btn-success btn-do-enable" id="enableBtn" data-login="' + esc(login) + '">✅ Увімкнути</button>' +
            '</div>' +
            '<div class="mt-3">' +
              '<button class="btn btn-link btn-sm text-muted p-0 btn-do-generate" data-login="' + esc(login) + '">🔄 Перегенерувати</button>' +
            '</div>' +
          '</div>' +
        '</div>';
    setTimeout(function () { var i = document.getElementById('totpCodeInput'); if (i) i.focus(); }, 100);
}

function generateSecret(login) {
    document.getElementById('setupModalBody').innerHTML =
        '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div>' +
        '<p class="mt-2 text-muted">Генерація…</p></div>';
    apiCall({ action: 'generate', target_login: login }).then(function (data) {
        if (data.ok) {
            csrfToken = data.csrf_token;
            document.getElementById('setupModalBody').innerHTML =
                '<div class="text-center py-3">' +
                '<div style="font-size:3rem">✅</div>' +
                '<h5 class="mt-2 text-success fw-bold">2FA увімкнено!</h5>' +
                '<p class="text-muted small">Надайте користувачу <strong>' + esc(login) + '</strong> ключ або QR-код нижче.</p>' +
                '<img src="' + data.qrUrl + '" alt="QR" class="border rounded p-2 my-2" style="max-width:160px">' +
                '<p class="small text-muted mb-1">Секретний ключ:</p>' +
                '<div class="input-group input-group-sm justify-content-center" style="max-width:320px;margin:0 auto">' +
                  '<input type="text" class="form-control font-monospace text-center" id="modalSecretKey" value="' + esc(data.secret) + '" readonly>' +
                  '<button class="btn btn-outline-secondary btn-copy-secret">📋 Копіювати</button>' +
                '</div>' +
                '<button class="btn btn-primary mt-4" data-bs-dismiss="modal" onclick="location.reload()">Закрити</button>' +
                '</div>';
            updateRowStatus(login, 'enabled');
        } else { showModalError(data.error || 'Помилка.'); }
    }).catch(function (err) { showModalError('Помилка мережі: ' + esc(err.message)); });
}

function enableTotp(login) {
    var input = document.getElementById('totpCodeInput');
    var code  = input ? input.value.trim() : '';
    if (!/^\d{6}$/.test(code)) {
        var el = document.getElementById('enableError');
        if (el) { el.textContent = 'Введіть 6-значний числовий код.'; el.classList.remove('d-none'); }
        return;
    }
    var btn = document.getElementById('enableBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳…'; }
    apiCall({ action: 'enable', target_login: login, totp_code: code }).then(function (data) {
        if (data.ok) {
            csrfToken = data.csrf_token;
            document.getElementById('setupModalBody').innerHTML =
                '<div class="text-center py-4">' +
                '<div style="font-size:3.5rem">✅</div>' +
                '<h5 class="mt-3 text-success fw-bold">2FA увімкнено!</h5>' +
                '<p class="text-muted">2FA для <strong>' + esc(login) + '</strong> активована.</p>' +
                '<button class="btn btn-primary mt-2" data-bs-dismiss="modal" onclick="location.reload()">Закрити</button>' +
                '</div>';
            updateRowStatus(login, 'enabled');
        } else {
            var el = document.getElementById('enableError');
            if (el) { el.textContent = data.error || 'Невірний код.'; el.classList.remove('d-none'); }
            if (btn) { btn.disabled = false; btn.textContent = '✅ Увімкнути'; }
            if (input) { input.value = ''; input.focus(); }
        }
    }).catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = '✅ Увімкнути'; }
    });
}

function showModalError(msg) {
    document.getElementById('setupModalBody').innerHTML =
        '<div class="alert alert-danger m-3">' + esc(msg) + '</div>' +
        '<div class="text-center pb-3"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Закрити</button></div>';
}

// ── 2FA: скидання ─────────────────────────────────────────────────
function openResetTotpModal(login) {
    document.getElementById('resetTotpUser').textContent = login;
    document.getElementById('confirmResetTotpBtn').onclick = function () { doResetTotp(login); };
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resetTotpModal')).show();
}

function doResetTotp(login) {
    var btn = document.getElementById('confirmResetTotpBtn');
    btn.disabled = true; btn.textContent = '⏳…';
    apiCall({ action: 'reset', target_login: login }).then(function (data) {
        bootstrap.Modal.getInstance(document.getElementById('resetTotpModal')).hide();
        if (data.ok) {
            csrfToken = data.csrf_token;
            showGlobalAlert('success', '🔄 2FA скинуто для <strong>' + esc(login) + '</strong>.');
            updateRowStatus(login, 'disabled');
        } else { showGlobalAlert('danger', data.error || 'Помилка.'); }
    }).catch(function () {
        bootstrap.Modal.getInstance(document.getElementById('resetTotpModal')).hide();
    }).finally(function () { btn.disabled = false; btn.textContent = '🗑 Скинути'; });
}

function bulkResetTotp() {
    var logins = Array.from(document.querySelectorAll('.user-select:checked')).map(function (c) { return c.value; });
    if (!logins.length) return;
    if (!confirm('Скинути 2FA для ' + logins.length + ' користувачів?')) return;
    apiCall({ action: 'bulk_reset', logins: logins }).then(function (data) {
        if (data.ok) {
            csrfToken = data.csrf_token;
            logins.forEach(function (l) { updateRowStatus(l, 'disabled'); });
            document.querySelectorAll('.user-select:checked').forEach(function (c) { c.checked = false; });
            updateBulkButton();
            showGlobalAlert('success', '🔄 2FA скинуто для <strong>' + data.count + '</strong> користувачів.');
        } else { showGlobalAlert('danger', data.error || 'Помилка.'); }
    });
}

// ── Оновлення рядка без перезавантаження ──────────────────────────
function updateRowStatus(login, newStatus) {
    var id = CSS.escape(login);
    var statusCell  = document.getElementById('status-'  + id);
    var actionsCell = document.getElementById('actions-' + id);

    if (statusCell) {
        var b = {
            enabled:  '<span class="totp-badge totp-on">✅ Увімкнено</span>',
            pending:  '<span class="totp-badge totp-wait">⏳ Очікує</span>',
            disabled: '<span class="totp-badge totp-off">⭕ Вимкнено</span>',
        };
        statusCell.innerHTML = b[newStatus] || b.disabled;
    }

    if (actionsCell) {
        var la       = login.replace(/&/g,'&amp;').replace(/"/g,'&quot;');
        var editBtn  = actionsCell.querySelector('.edit-btn')   ? actionsCell.querySelector('.edit-btn').outerHTML   : '';
        var delBtn   = actionsCell.querySelector('.delete-btn') ? actionsCell.querySelector('.delete-btn').outerHTML : '';
        var totpBtns = newStatus === 'enabled'
            ? '<button type="button" class="action-btn totp-reset-btn btn-reset-2fa" data-login="' + la + '" title="Скинути 2FA">🔄 Скинути 2FA</button>'
            : '<button type="button" class="action-btn totp-btn btn-setup-2fa" data-login="' + la + '" data-has-secret="0" data-secret="" title="Налаштувати 2FA">🔑 2FA</button>';
        actionsCell.innerHTML = editBtn + totpBtns + delBtn;
    }
}

// ── Глобальний алерт ──────────────────────────────────────────────
function showGlobalAlert(type, msg) {
    var el = document.getElementById('globalAlert');
    el.className = 'alert alert-' + type + ' alert-dismissible fade show';
    document.getElementById('globalAlertText').innerHTML = msg;
    el.classList.remove('d-none');
    clearTimeout(el._t);
    el._t = setTimeout(function () {
        try { bootstrap.Alert.getOrCreateInstance(el).close(); } catch (e) {}
    }, 6000);
}

// ── API запит ─────────────────────────────────────────────────────
function apiCall(params) {
    params.csrf_token = csrfToken;
    return fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(params),
    }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    });
}

function esc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('edit_password')?.addEventListener('input', function () {
    this.setCustomValidity(this.value.length > 0 && this.value.length < 6 ? 'Мінімум 6 символів' : '');
});
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';