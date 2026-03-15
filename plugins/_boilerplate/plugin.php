<?php
/**
 * plugins/my-plugin/plugin.php
 * Шаблон плагіна для fly-CMS.
 *
 * Скопіюйте папку _boilerplate/ у plugins/my-plugin/
 * і змініть slug у plugin.json та fly_register_plugin() нижче.
 *
 * ─────────────────────────────────────────────────────────────────
 * ДОСТУПНІ ХУКИ (Actions):
 *
 *   cms.init                  — CMS ініціалізована, БД підключена
 *   cms.admin.head            — у <head> кожної сторінки адмінки
 *   cms.admin.footer          — перед </body> кожної сторінки адмінки
 *   cms.admin.menu            — додаткові пункти в sidebar
 *   cms.dashboard.widgets     — додаткові блоки на Dashboard
 *   cms.post.saved($id,$data) — після збереження посту
 *   cms.page.saved($id,$data) — після збереження сторінки
 *   cms.post.deleted($id)     — після видалення посту
 *   cms.user.login($user,$role)— після успішного входу
 *   cms.media.uploaded($path,$url) — після завантаження файлу
 *   cms.head.meta($item)      — мета-теги у <head> публічних сторінок
 *
 * ДОСТУПНІ ХУКИ (Filters):
 *
 *   cms.post.content($html)   — контент посту перед виводом
 *   cms.page.content($html)   — контент сторінки перед виводом
 *   cms.post.title($title)    — заголовок посту
 *   cms.post.slug($slug,$title)— slug перед збереженням
 *   cms.page.slug($slug,$title)— slug сторінки
 *   cms.admin.menu.items($arr) — масив пунктів sidebar
 *
 * ─────────────────────────────────────────────────────────────────
 * КОРИСНІ ФУНКЦІЇ:
 *
 *   fly_db()                  — PDO до основної БД
 *   get_setting($key)         — читати налаштування сайту
 *   fly_apply_filters($h,$v)  — запустити ланцюг фільтрів
 *   fly_do_action($hook,...)  — запустити ланцюг actions
 * ─────────────────────────────────────────────────────────────────
 */

// ── Реєстрація ────────────────────────────────────────────────────
fly_register_plugin([
    'slug'    => 'my-plugin',
    'name'    => 'Мій плагін',
    'version' => '1.0.0',
]);

// ── Приклад 1: Action — запустити код після збереження посту ──────
fly_add_action('cms.post.saved', function(int $postId, array $data): void {
    // $postId — ID нового/оновленого посту
    // $data   — ['slug'=>'...', 'title'=>'...', 'draft'=>0, 'action'=>'create']
    // Приклад: пінгувати sitemap-генератор
    // file_get_contents("https://your-site.com/sitemap.xml?ping=1");
}, 10);

// ── Приклад 2: Filter — модифікувати контент посту ────────────────
fly_add_filter('cms.post.content', function(string $content): string {
    // Приклад: замінити [youtube:ID] на embed
    $content = preg_replace(
        '/\[youtube:([a-zA-Z0-9_-]{11})\]/',
        '<iframe width="560" height="315" src="https://www.youtube.com/embed/$1" allowfullscreen></iframe>',
        $content
    );
    return $content;
}, 10);

// ── Приклад 3: Action — додати пункт у sidebar адмінки ───────────
fly_add_action('cms.admin.menu', function(): void {
    // Виводимо HTML пункту меню
    // echo '<a href="/admin/my-plugin-page.php">⚡ Мій плагін</a>';
}, 10);

// ── Приклад 4: Action — додати widget на Dashboard ────────────────
fly_add_action('cms.dashboard.widgets', function(): void {
    // echo '<div class="card mb-3"><div class="card-body"><h6>Мій widget</h6></div></div>';
}, 10);

// ── Приклад 5: Action — CSS/JS у head адмінки ─────────────────────
fly_add_action('cms.admin.head', function(): void {
    // $pluginUrl = '/plugins/my-plugin/assets/';
    // echo '<link rel="stylesheet" href="' . $pluginUrl . 'style.css">';
    // echo '<script src="' . $pluginUrl . 'script.js"></script>';
}, 10);
