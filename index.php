<?php
/**
 * index.php — Контролер головної сторінки
 * Вся логіка залишається в PHP, HTML — у views/index.twig
 */

require_once __DIR__ . '/config.php';
fly_send_security_headers();

// Сесія
ini_set('session.cookie_httponly', 1);
if (!empty($_SERVER['HTTPS'])) ini_set('session.cookie_secure', 1);
session_set_cookie_params(['lifetime'=>3600,'path'=>'/','httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Автопублікація (web-fallback: TTL 60 сек, не виконується на кожен запит)
if (file_exists(__DIR__ . '/data/publish_scheduler.php')) {
    require_once __DIR__ . '/data/publish_scheduler.php';
    run_publish_scheduler(fly_db(), false);
}

// Дані та рендер
require_once __DIR__ . '/data/plugins.php';
require_once __DIR__ . '/data/HomeService.php';
require_once __DIR__ . '/data/ViewContext.php';
require_once __DIR__ . '/data/TwigFactory.php';

$news_cat   = ts('news_category_filter', '');
$news_count = (int)ts('news_count', '6');

$svc     = new HomeService();
$posts   = (ts('news_enabled', '1') === '1') ? $svc->getPosts($news_cat, $news_count) : [];
$context = ViewContext::getIndex($posts, $svc->getMainContent());
$context['meta_description'] = get_setting('meta_description') ?? '';
$context = ViewContext::applyPluginFilters($context);

// Передаємо LT_CONFIG у Twig-контекст (плагін lang-translator)
if (function_exists('fly_is_plugin_active') && fly_is_plugin_active('lang-translator') && function_exists('lt_get_config')) {
    $cfg = lt_get_config();
    $context['lt_config_json'] = '<script>window.LT_CONFIG=' . json_encode($cfg, JSON_UNESCAPED_UNICODE) . ';</script>';
}

$twig = TwigFactory::create();
echo $twig->render('index.twig', $context);