<?php
/**
 * plugins/seo-meta/plugin.php
 * Додає Open Graph і Twitter Card мета-теги до публічних сторінок.
 * Налаштування зберігаються в settings.seo_meta_* у БД.
 */

fly_register_plugin([
    'slug'    => 'seo-meta',
    'name'    => 'SEO Meta Tags',
    'version' => '1.0.0',
]);

// ── Хелпери ───────────────────────────────────────────────────────
function seo_meta_setting(string $key, string $default = ''): string {
    try {
        $v = fly_db()->prepare("SELECT value FROM settings WHERE key=? LIMIT 1");
        $v->execute(['seo_meta_' . $key]);
        $r = $v->fetchColumn();
        return $r !== false ? $r : $default;
    } catch (Exception $e) { return $default; }
}

function seo_meta_strip(string $html, int $limit = 160): string {
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', trim($text));
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
}

// ── Вивести OG теги у <head> адмінки (для перевірки) ──────────────
// Для публічної частини — підключати plugins.php у config.php або router.php

// ── Фільтр контенту посту — додати schema.org розмітку ────────────
fly_add_filter('cms.post.content', function(string $content): string {
    if (seo_meta_setting('schema_article', '1') !== '1') return $content;

    // Додати невидимий schema.org Article тільки якщо не вже є
    if (strpos($content, 'schema.org') !== false) return $content;

    $schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","mainEntityOfPage":{"@type":"WebPage","@id":"' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '"}}</script>';
    return $content . "\n" . $schema;
}, 20);

// ── Action: вивести OG мета-теги ─────────────────────────────────
// Цей хук викликається з шаблону (templates/page.php або views/layouts/base.twig)
// через: <?php if (function_exists('fly_do_action')) fly_do_action('cms.head.meta', $post ?? $page ?? []); ?>
fly_add_action('cms.head.meta', function(array $item = []): void {
    $siteName   = function_exists('get_setting') ? (get_setting('site_title') ?: 'fly-CMS') : 'fly-CMS';
    $title      = htmlspecialchars($item['title']            ?? $siteName);
    $desc       = htmlspecialchars(seo_meta_strip($item['meta_description'] ?? $item['content'] ?? ''));
    $image      = htmlspecialchars($item['thumbnail']        ?? seo_meta_setting('default_image', ''));
    $url        = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $twitterCard= seo_meta_setting('twitter_card', 'summary_large_image');
    $twitterSite= seo_meta_setting('twitter_site', '');

    echo "\n";
    echo "    <!-- SEO Meta Plugin -->\n";
    echo "    <meta property=\"og:type\"        content=\"article\">\n";
    echo "    <meta property=\"og:site_name\"   content=\"" . htmlspecialchars($siteName) . "\">\n";
    echo "    <meta property=\"og:title\"       content=\"{$title}\">\n";
    if ($desc) echo "    <meta property=\"og:description\" content=\"{$desc}\">\n";
    if ($image) echo "    <meta property=\"og:image\"       content=\"{$image}\">\n";
    echo "    <meta property=\"og:url\"         content=\"" . htmlspecialchars($url) . "\">\n";
    echo "    <meta name=\"twitter:card\"       content=\"{$twitterCard}\">\n";
    echo "    <meta name=\"twitter:title\"      content=\"{$title}\">\n";
    if ($desc)  echo "    <meta name=\"twitter:description\" content=\"{$desc}\">\n";
    if ($image) echo "    <meta name=\"twitter:image\"       content=\"{$image}\">\n";
    if ($twitterSite) echo "    <meta name=\"twitter:site\"  content=\"{$twitterSite}\">\n";
    echo "\n";
}, 10);
