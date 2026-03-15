<?php
/**
 * admin/admin_template_top.php
 * Мінімальний верхній navbar + sidebar для сторінок з власним layout
 * (file_manager.php, backup.php — які не використовують $content_html)
 *
 * Підключення: require_once __DIR__ . '/../admin/admin_template_top.php';
 * Після цього йде власний <body>-контент файлу.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';

$pdo = connectToDatabase();

try {
    $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('cms_name','cms_version','favicon_path')");
    $cmsSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $cmsSettings = [];
}

$cmsName     = htmlspecialchars($cmsSettings['cms_name']    ?? 'CMS');
$cmsVersion  = htmlspecialchars($cmsSettings['cms_version'] ?? '');
$favicon_path = htmlspecialchars($cmsSettings['favicon_path'] ?? '');
$_cur = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar navbar-dark bg-dark px-3 py-1 d-flex align-items-center justify-content-between" style="min-height:44px">
  <div class="d-flex align-items-center gap-3">
    <button class="btn btn-sm btn-outline-light d-md-none" data-bs-toggle="offcanvas" data-bs-target="#mobSidebar">☰</button>
    <a class="navbar-brand mb-0 fw-bold" href="/admin/index.php" style="font-size:.95rem"><?= $cmsName ?> Адмінка</a>
    <span class="text-secondary d-none d-md-inline" style="font-size:.8rem"><?= htmlspecialchars($page_title ?? '') ?></span>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <a href="/admin/file_manager.php" class="btn btn-sm <?= $_cur==='file_manager.php'?'btn-light':'btn-outline-light' ?>">📂</a>
    <a href="/admin/backup.php"       class="btn btn-sm <?= $_cur==='backup.php'?'btn-light':'btn-outline-light' ?>">💾</a>
    <a href="/admin/index.php" class="btn btn-sm btn-outline-light">← Адмінка</a>
  </div>
</nav>

<!-- Mobile offcanvas sidebar -->
<div class="offcanvas offcanvas-start bg-light" tabindex="-1" id="mobSidebar" style="max-width:240px">
  <div class="offcanvas-header py-2">
    <h6 class="offcanvas-title"><?= $cmsName ?></h6>
    <button class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-2">
    <a class="d-block p-2 text-decoration-none <?= $_cur==='index.php'?'fw-bold':'' ?>" href="/admin/index.php">🏠 Адмінка</a>
    <a class="d-block p-2 text-decoration-none" href="/admin/create_post.php">✏️ Записи</a>
    <a class="d-block p-2 text-decoration-none" href="/admin/create_page.php">📄 Сторінки</a>
    <a class="d-block p-2 text-decoration-none" href="/admin/media.php">🖼️ Медіа</a>
    <hr class="my-1">
    <a class="d-block p-2 text-decoration-none <?= $_cur==='file_manager.php'?'fw-bold text-primary':'' ?>" href="/admin/file_manager.php">📂 Файловий менеджер</a>
    <a class="d-block p-2 text-decoration-none <?= $_cur==='backup.php'?'fw-bold text-success':'' ?>" href="/admin/backup.php">💾 Бекапи</a>
    <a class="d-block p-2 text-decoration-none" href="/admin/logs.php">📜 Логи</a>
    <a class="d-block p-2 text-decoration-none" href="/admin/site_settings.php">⚙️ Налаштування</a>
    <hr class="my-1">
    <a class="d-block p-2 text-decoration-none text-danger" href="/templates/logout.php">🚪 Вийти</a>
  </div>
</div>