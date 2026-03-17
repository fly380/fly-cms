<?php
/**
 * plugins/lang-translator/plugin.php
 */

fly_register_plugin([
    'slug'    => 'lang-translator',
    'name'    => 'Language Translator',
    'version' => '1.0.2',
]);

// ── Хелпер ────────────────────────────────────────────────────────
function lt_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $v = fly_db()->prepare("SELECT value FROM settings WHERE key=? LIMIT 1");
        $v->execute(['lt_' . $key]);
        $r = $v->fetchColumn();
        $cache[$key] = ($r !== false && $r !== null) ? $r : $default;
    } catch (Exception $e) { $cache[$key] = $default; }
    return $cache[$key];
}

function lt_get_config(): array {
    $langs_raw = lt_setting('languages', 'en,pl,de');
    $enabled   = array_values(array_filter(array_map('trim', explode(',', $langs_raw))));
    $style      = lt_setting('style',       'flag_name');
    $position   = lt_setting('position',    'navbar');
    $cache_h    = (int)lt_setting('cache_hours', '24');

    return [
        'position'    => $position,
        'style'       => $style,
        'languages'   => $enabled,
        'endpoint'    => '/templates/translate.php',
        'cache_hours' => $cache_h,
        'chunk_size'  => 15,
        'delay_ms'    => 50,
        // Хеш конфігу — якщо змінився, JS очищує localStorage-кеш перекладів
        'config_hash' => substr(md5($style . implode(',', $enabled) . $cache_h), 0, 8),
    ];
}

// ── 1. Інжект LT_CONFIG у <head> ─────────────────────────────────
fly_add_action('cms.head', function(): void {
    if (defined('FLY_ADMIN_CONTEXT')) return;
    $cfg = lt_get_config();
    echo '<script>window.LT_CONFIG=' . json_encode($cfg, JSON_UNESCAPED_UNICODE) . ';</script>';
}, 5);

// ── 2. Footer і Floating — через фільтр Twig-контексту ───────────
fly_add_filter('cms.twig.context', function(array $context): array {
    if (defined('FLY_ADMIN_CONTEXT')) return $context;

    $cfg = lt_get_config();
    $context['lt_position']      = $cfg['position'];
    $context['lt_footer_html']   = '';
    $context['lt_floating_html'] = '';

    if ($cfg['position'] === 'footer') {
        $context['lt_footer_html'] = '<div id="lang-switcher-mount" class="d-inline-block ms-3"></div>';
    } elseif ($cfg['position'] === 'floating') {
        $context['lt_floating_html'] = '<div id="lang-switcher-mount" style="position:fixed;bottom:24px;right:24px;z-index:9999"></div>';
    }

    return $context;
}, 10);
