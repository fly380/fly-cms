<?php
/**
 * templates/page.php — Контролер публічної сторінки / запису
 * HTML — у views/page.twig
 */

require_once __DIR__ . '/../config.php';
fly_send_security_headers();

session_start();
require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/../data/ViewContext.php';
require_once __DIR__ . '/../data/TwigFactory.php';

$db = fly_db();

// Фонове зображення (для сторінок помилок)
$bgStyle = '';
try {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'background_image'");
    $stmt->execute();
    $bg = $stmt->fetchColumn();
    if ($bg) $bgStyle = "background:url('{$bg}') no-repeat center center fixed;background-size:cover;";
} catch (PDOException $e) {}

$isLoggedIn = $_SESSION['loggedin'] ?? false;

$slug = $_GET['page'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    http_response_code(400);
    $twig = TwigFactory::create();
    echo $twig->render('errors/error.twig', ['code'=>400,'title'=>'Невірний запит','message'=>"400 Недопустиме ім'я сторінки.",'redirect_delay'=>2,'bg_style'=>$bgStyle]);
    exit;
}

// Пошук сторінки/запису
$stmt = $db->prepare("SELECT *, 'page' as type FROM pages WHERE slug = :slug");
$stmt->execute([':slug' => $slug]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    $stmt = $db->prepare("SELECT *, 'post' as type FROM posts WHERE slug = :slug");
    $stmt->execute([':slug' => $slug]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
}

$twig = TwigFactory::create();

if (!$page) {
    http_response_code(404);
    echo $twig->render('errors/error.twig', ['code'=>404,'title'=>'Не знайдено','message'=>'404 сторінка не знайдена.','redirect_delay'=>5,'bg_style'=>$bgStyle]);
    exit;
}

if ($page['draft'] && !$isLoggedIn) {
    http_response_code(403);
    echo $twig->render('errors/error.twig', ['code'=>403,'title'=>'Доступ заборонено','message'=>'🔒 Ця сторінка в статусі чорнетки.','redirect_delay'=>3,'bg_style'=>$bgStyle]);
    exit;
}

if (($page['visibility'] ?? 'public') === 'private' && !$isLoggedIn) {
    http_response_code(403);
    echo $twig->render('errors/error.twig', ['code'=>403,'title'=>'Доступ заборонено','message'=>'🔒 Сторінка доступна лише авторизованим користувачам.','redirect_delay'=>3,'bg_style'=>$bgStyle]);
    exit;
}

// Пов'язані записи
$relatedPosts = [];
if (($page['type'] ?? '') === 'post' && ts('post_show_related', '1') === '1' && !empty($page['id'])) {
    try {
        $count = (int)ts('post_related_count', '3');
        $rstmt = $db->prepare("SELECT id, title, slug, meta_description, thumbnail, created_at FROM posts WHERE draft=0 AND id != ? ORDER BY created_at DESC LIMIT ?");
        $rstmt->execute([$page['id'], $count]);
        $relatedPosts = $rstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$context = ViewContext::getPage($page, $page['content'] ?? '', $relatedPosts);
echo $twig->render('page.twig', $context);
