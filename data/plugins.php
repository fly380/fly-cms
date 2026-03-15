<?php
/**
 * data/plugins.php — Ядро системи плагінів fly-CMS
 *
 * API:
 *   fly_add_action($hook, $callback, $priority)  — підписатись на дію
 *   fly_do_action($hook, ...$args)               — запустити дію
 *   fly_add_filter($hook, $callback, $priority)  — підписатись на фільтр
 *   fly_apply_filters($hook, $value, ...$args)   — застосувати фільтр
 *   fly_register_plugin($meta)                   — зареєструвати плагін
 *
 * Хуки (actions):
 *   cms.init              — після ініціалізації CMS (config завантажено)
 *   cms.admin.head        — у <head> адмінки
 *   cms.admin.footer      — перед </body> адмінки
 *   cms.admin.menu        — додаткові пункти в sidebar
 *   cms.post.saved        — після збереження посту ($post_id, $data)
 *   cms.page.saved        — після збереження сторінки ($page_id, $data)
 *   cms.post.deleted      — після видалення посту ($post_id)
 *   cms.user.login        — після входу ($username, $role)
 *   cms.media.uploaded    — після завантаження файлу ($path, $url)
 *   cms.dashboard.widgets — виводить додаткові widgets на dashboard
 *
 * Хуки (filters):
 *   cms.post.content      — контент посту перед виводом ($content)
 *   cms.page.content      — контент сторінки перед виводом ($content)
 *   cms.post.title        — заголовок посту ($title)
 *   cms.post.slug         — slug перед збереженням ($slug, $title)
 *   cms.page.slug         — slug сторінки ($slug, $title)
 *   cms.admin.menu.items  — масив пунктів меню sidebar
 */

if (defined('FLY_PLUGINS_LOADED')) return;
define('FLY_PLUGINS_LOADED', true);

// ─── Реєстр хуків ─────────────────────────────────────────────────
$_fly_hooks    = [];   // actions
$_fly_filters  = [];   // filters
$_fly_plugins  = [];   // зареєстровані плагіни

// ─── Actions ──────────────────────────────────────────────────────

function fly_add_action(string $hook, callable $callback, int $priority = 10): void {
    global $_fly_hooks;
    $_fly_hooks[$hook][$priority][] = $callback;
}

function fly_do_action(string $hook, ...$args): void {
    global $_fly_hooks;
    if (empty($_fly_hooks[$hook])) return;
    ksort($_fly_hooks[$hook]);
    foreach ($_fly_hooks[$hook] as $callbacks) {
        foreach ($callbacks as $cb) {
            call_user_func_array($cb, $args);
        }
    }
}

// ─── Filters ──────────────────────────────────────────────────────

function fly_add_filter(string $hook, callable $callback, int $priority = 10): void {
    global $_fly_filters;
    $_fly_filters[$hook][$priority][] = $callback;
}

function fly_apply_filters(string $hook, $value, ...$args) {
    global $_fly_filters;
    if (empty($_fly_filters[$hook])) return $value;
    ksort($_fly_filters[$hook]);
    foreach ($_fly_filters[$hook] as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = call_user_func_array($cb, array_merge([$value], $args));
        }
    }
    return $value;
}

// ─── Реєстрація плагіна ───────────────────────────────────────────

function fly_register_plugin(array $meta): void {
    global $_fly_plugins;
    $slug = $meta['slug'] ?? '';
    if ($slug) $_fly_plugins[$slug] = $meta;
}

function fly_get_plugins(): array {
    global $_fly_plugins;
    return $_fly_plugins;
}

// ─── Завантажувач плагінів ────────────────────────────────────────

function fly_load_plugins(): void {
    // Використовуємо FLY_ROOT якщо визначено, інакше DOCUMENT_ROOT
    $root       = defined('FLY_ROOT') ? FLY_ROOT : rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $pluginsDir = $root . '/plugins';
    if (!is_dir($pluginsDir)) return;

    $pdo = fly_db();

    // Читаємо список увімкнених плагінів з БД
    try {
        $enabled = $pdo->query(
            "SELECT value FROM settings WHERE key='active_plugins' LIMIT 1"
        )->fetchColumn();
        $activePlugins = $enabled ? json_decode($enabled, true) : [];
        if (!is_array($activePlugins)) $activePlugins = [];
    } catch (Exception $e) {
        $activePlugins = [];
    }

    // Скануємо папку plugins/
    foreach (glob($pluginsDir . '/*/plugin.php') as $pluginFile) {
        $pluginSlug = basename(dirname($pluginFile));

        // Завантажуємо тільки увімкнені
        if (!in_array($pluginSlug, $activePlugins)) continue;

        // Підключаємо плагін (ob_ щоб вловити випадковий вивід)
        try {
            ob_start();
            require_once $pluginFile;
            ob_end_clean();
        } catch (\Throwable $e) {
            error_log('fly-CMS plugin error [' . $pluginSlug . ']: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('fly-CMS plugin error [' . $pluginSlug . ']: ' . $e->getMessage());
        }
    }
}

// ─── Допоміжні функції ────────────────────────────────────────────

/**
 * Читає metadata плагіна з plugin.json
 */
function fly_read_plugin_meta(string $pluginSlug): array {
    $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $jsonFile = $root . '/plugins/' . $pluginSlug . '/plugin.json';
    if (!file_exists($jsonFile)) return [];
    $data = json_decode(file_get_contents($jsonFile), true);
    return is_array($data) ? array_merge(['slug' => $pluginSlug], $data) : ['slug' => $pluginSlug];
}

/**
 * Перевіряє чи плагін активний
 */
function fly_is_plugin_active(string $slug): bool {
    try {
        $val = fly_db()->query(
            "SELECT value FROM settings WHERE key='active_plugins' LIMIT 1"
        )->fetchColumn();
        $active = $val ? json_decode($val, true) : [];
        return in_array($slug, (array)$active);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Увімкнути/вимкнути плагін
 */
function fly_set_plugin_active(string $slug, bool $active): void {
    $pdo = fly_db();
    try {
        $val     = $pdo->query("SELECT value FROM settings WHERE key='active_plugins' LIMIT 1")->fetchColumn();
        $plugins = $val ? json_decode($val, true) : [];
        if (!is_array($plugins)) $plugins = [];

        if ($active) {
            if (!in_array($slug, $plugins)) $plugins[] = $slug;
        } else {
            $plugins = array_values(array_filter($plugins, function($p) use ($slug) { return $p !== $slug; }));
        }

        $json = json_encode($plugins);
        $exists = $pdo->query("SELECT COUNT(*) FROM settings WHERE key='active_plugins'")->fetchColumn();
        if ($exists) {
            $pdo->prepare("UPDATE settings SET value=? WHERE key='active_plugins'")->execute([$json]);
        } else {
            $pdo->prepare("INSERT INTO settings(key,value) VALUES('active_plugins',?)")->execute([$json]);
        }
    } catch (Exception $e) {
        error_log('fly_set_plugin_active: ' . $e->getMessage());
    }
}

// ─── Автозавантаження при підключенні файлу ───────────────────────
fly_load_plugins();
fly_do_action('cms.init');
