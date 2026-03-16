<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/functions.php';

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
	header('Location: /templates/login.php');
	exit;
}

$pdo = connectToDatabase();

try {
	$stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('cms_name', 'cms_version', 'favicon_path', 'logo_path')");
	$cmsSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
	$cmsSettings = [];
}
$cmsName     = htmlspecialchars($cmsSettings['cms_name']    ?? 'CMS');
$cmsVersion  = htmlspecialchars($cmsSettings['cms_version'] ?? '');
$favicon_path = htmlspecialchars($cmsSettings['favicon_path'] ?? '');
$logo_path    = htmlspecialchars($cmsSettings['logo_path']    ?? '');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
	<meta charset="UTF-8" />
	<title><?= htmlspecialchars($page_title ?? 'Адмінка') ?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<?php if ($favicon_path): ?>
	<link rel="icon" href="<?= $favicon_path ?>">
	<?php endif; ?>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
	<link href="/../assets/css/menu.css" rel="stylesheet" />	
<?php if (function_exists('fly_do_action')) fly_do_action('cms.admin.head'); ?>
</head>
<body class="<?= ($fullBleed??false) ? 'fullbleed-page' : '' ?>">
	<!-- Навбар для мобільних -->
	<nav class="navbar navbar-dark bg-dark fixed-top mobile-nav d-md-none">
		<div class="container-fluid">
			<button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
				<span class="navbar-toggler-icon"></span>
			</button>
			<span class="navbar-brand mb-0 h1"><?= $cmsName ?> Адмінка</span>
		</div>
	</nav>
	<!-- Offcanvas (мобільне меню) -->
	<div class="offcanvas offcanvas-start bg-light" tabindex="-1" id="mobileSidebar">
		<div class="offcanvas-header">
			<h5 class="offcanvas-title"><?= $cmsName ?> <?= $cmsVersion ?></h5>
			<button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Закрити"></button>
		</div>
		<div class="offcanvas-body">
			<?php if (in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])): ?>
				<a href="/admin/index.php">Адмінка</a>
				<a href="/" target="_blank">Переглянути сайт</a>
				<a href="create_page.php">Сторінки</a>
				<a href="create_post.php">Записи</a>
				<a href="menu_editor.php">Меню</a>	
				<a href="media.php">Медіафайли</a>
				<a href="notes.php">📝 Нотатки</a> 			
			<?php endif; ?>
			<?php if (in_array($_SESSION['role'], ['admin', 'superadmin'])): ?>
				<a href="site_settings.php">Налаштування сайту</a>
				<button class="sidebar-submenu-toggle" onclick="toggleSubmenu(this)">⚙️ Керування <span class="arrow">▸</span></button>
				<div class="sidebar-submenu">
					<a href="user_list.php">👥 Користувачі</a>
					<a href="invite.php">✉️ Запрошення</a>
					<?php if (in_array($_SESSION['role'], ['superadmin'])): ?>
					<a href="meta_settings.php?success=1">📋 Опис 👑</a>
					<?php endif; ?>
					<a href="/admin/logs.php">📄 Переглянути логі</a>
					<a href="/admin/SQLAdmin/phpadmin.php">🗄️ База даних</a>					
					<a href="/admin/file_manager.php">📁 Файли</a>
					<a href="/admin/backup.php">💾 Резервне копіювання</a>					
					<a href="/admin/support.php">🛠️ Підтримка</a>
					<a href="/admin/updater.php">🔄 Оновлення CMS</a>
					<a href="/admin/plugins.php">🧩 Плагіни</a>
				</div>
			<?php endif; ?>
			<?php if (function_exists('fly_do_action')) fly_do_action('cms.admin.menu'); ?>
			<a href="/templates/logout.php" class="text-danger">Вийти</a>
		</div>
	</div>
	<!-- Стаціонарне бокове меню для ПК -->
	<div class="sidebar d-none d-md-block">
		<?php if (in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])): ?>
				<a href="/admin/index.php">Адмінка</a>
				<a href="/" target="_blank">Переглянути сайт</a>
				<a href="create_page.php">Сторінки</a>
				<a href="create_post.php">Записи</a>
				<a href="menu_editor.php">Меню</a> 
				<a href="media.php">Медіафайли</a>
				<a href="notes.php">📝 Нотатки</a> 				
			<?php endif; ?>
			<?php if (in_array($_SESSION['role'], ['admin', 'superadmin'])): ?>
				<a href="site_settings.php">Налаштування сайту</a>
				<button class="sidebar-submenu-toggle" onclick="toggleSubmenu(this)">⚙️ Керування <span class="arrow">▸</span></button>
				<div class="sidebar-submenu">
					<a href="user_list.php">👥 Користувачі</a>
					<a href="invite.php">✉️ Запрошення</a>
					<?php if (in_array($_SESSION['role'], ['superadmin'])): ?>
					<a href="meta_settings.php?success=1">📋 Опис 👑</a>
					<?php endif; ?>
					<a href="/admin/logs.php">📄 Переглянути логі</a>
					<a href="/admin/SQLAdmin/phpadmin.php" target="_blank">🗄️ База даних</a>
					<a href="/admin/file_manager.php">📁 Файли</a>
					<a href="/admin/backup.php">💾 Резервне копіювання</a>
					<a href="/admin/support.php">🛠️ Підтримка</a>
					<a href="/admin/updater.php">🔄 Оновлення CMS</a>
					<a href="/admin/plugins.php">🧩 Плагіни</a>
				</div>
			<?php endif; ?>
			<?php if (function_exists('fly_do_action')) fly_do_action('cms.admin.menu'); ?>
			<a href="/templates/logout.php" class="text-danger">Вийти</a>
	</div>
	<!-- Основний контент -->
	<main class="content">
		<?= $content_html ?? '' ?>
	</main>
	 <!-- Подвал -->
	<footer class="text-center py-3 bg-light">
		<small>&copy; <?= date('Y') ?> <?= $cmsName ?> <?= $cmsVersion ?> — Адмінка</small>
	</footer>
	<?php if (function_exists('fly_do_action')) fly_do_action('cms.admin.footer'); ?>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	function toggleSubmenu(btn) {
		btn.classList.toggle('open');
		btn.nextElementSibling.classList.toggle('open');
	}
	// Автовідкриття підменю якщо поточна сторінка в ньому
	document.addEventListener('DOMContentLoaded', function() {
		var cur = window.location.pathname.split('/').pop();
		var submenuPages = ['user_list.php','invite.php','meta_settings.php','logs.php','phpadmin.php','totp_users.php','totp_setup.php','file_manager.php','backup.php','support.php','updater.php','plugins.php'];
		if (submenuPages.some(function(p){ return cur.indexOf(p) !== -1; })) {
			document.querySelectorAll('.sidebar-submenu-toggle').forEach(function(btn) {
				btn.classList.add('open');
				btn.nextElementSibling.classList.add('open');
			});
		}
	});
	</script>
</body>
</html>