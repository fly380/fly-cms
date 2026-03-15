<?php
// Якщо файл або директорія існує — не перенаправляємо
if (php_sapi_name() === 'cli-server') {
	$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
	$fullPath = __DIR__ . $path;
	if (is_file($fullPath) || is_dir($fullPath)) {
		return false;
	}
}

// Отримуємо slug зі шляху
$slug = trim($_SERVER["REQUEST_URI"], "/");

// Автопублікація запланованих записів
require_once __DIR__ . '/data/publish_scheduler.php';
require_once __DIR__ . '/config.php';
try {
    $__pdo = fly_db();
    run_publish_scheduler($__pdo, false); // web-fallback: TTL 60 сек
} catch(Exception $e) { error_log('router scheduler: ' . $e->getMessage()); }

// Перенаправляємо всі інші запити у page.php
$_GET['page'] = $slug;
require __DIR__ . '/templates/page.php';