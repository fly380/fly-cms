<?php
/**
 * data/ViewContext.php — Будує спільний контекст для Twig-шаблонів
 *
 * Замінює сотні рядків $var = ts(...) розкидані по index.php і page_wrapper.php.
 * Всі шаблони отримують однаковий набір змінних через getBase() / getPage() / getIndex().
 */

require_once __DIR__ . '/../config.php';

class ViewContext
{
    /**
     * Базові змінні — потрібні у всіх шаблонах (navbar, footer, menu).
     */
    public static function getBase(array $extra = []): array
    {
        $siteFavicon = file_exists(FLY_ROOT . '/uploads/cms_img/favicon.ico')
            ? '/uploads/cms_img/favicon.ico'
            : (get_setting('favicon_path') ?: '/assets/images/111.png');

        $themeCssPath = FLY_ROOT . '/assets/css/theme.css';

        $ctx = [
            // Загальні налаштування
            'site_title'     => get_setting('site_title') ?: 'Сайт',
            'logo_path'      => get_setting('logo_path')  ?: '/assets/images/111.png',
            'favicon_path'   => $siteFavicon,
            'footer_text'    => get_setting('footer_text'),
            'phone'          => get_setting('phone_number'),
            'address'        => get_setting('address'),
            'email'          => get_setting('admin_email'),
            'current_year'   => (int)date('Y'),

            // Navbar
            'navbar_sticky'  => ts('navbar_sticky', '1') === '1',
            'navbar_style'   => ts('navbar_style', 'dark'),
            'navbar_height'  => (int)ts('navbar_height', '60'),
            'container_max'  => (int)ts('container_max_width', '1140'),

            // Theme CSS
            'theme_css_exists' => file_exists($themeCssPath),
            'theme_css_mtime'  => file_exists($themeCssPath) ? filemtime($themeCssPath) : 0,

            // Меню — передаємо дерево + стан сесії, рендер у partials/menu.twig
            'menu_tree'   => self::getMenuTree(),
            'menu_role'   => $_SESSION['role']     ?? 'guest',
            'menu_logged' => (bool)($_SESSION['loggedin'] ?? false),
        ];

        return array_merge($ctx, $extra);
    }

    /**
     * Будує і повертає дерево пунктів меню з БД.
     */
    public static function getMenuTree(): array
    {
        try {
            $db    = fly_db();
            $items = $db->query("SELECT * FROM menu_items ORDER BY position ASC")->fetchAll();
            return self::buildTree($items);
        } catch (Exception $e) {
            error_log('ViewContext::getMenuTree — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Контекст для index.twig — додає hero, news, divider змінні.
     */
    public static function getIndex(array $posts, string $mainContent): array
    {
        $newsOn  = ts('news_enabled', '1') === '1';
        $newsBg  = ts('news_section_bg', '#f8f9fa');
        $divH    = max(20, (int)ts('divider_height', '50'));

        $ctx = self::getBase([
            // Hero
            'hero_on'      => ts('hero_enabled', '1') === '1',
            'hero_layout'  => ts('hero_layout', 'centered'),
            'hero_bg_col'  => ts('hero_bg_color', '#1a1a2e'),
            'hero_bg_img'  => ts('hero_bg_image', ''),
            'hero_overlay' => (float)ts('hero_bg_overlay', '0.5'),
            'hero_height'  => (int)ts('hero_min_height', '400'),
            'hero_color'   => ts('hero_text_color', '#ffffff'),
            'hero_title'   => ts('hero_title', '') ?: (get_setting('site_title') ?: 'Сайт'),
            'hero_sub'     => ts('hero_subtitle', ''),
            'hero_btn1'    => ts('hero_btn1_text', 'Детальніше'),
            'hero_btn1url' => ts('hero_btn1_url', '#news'),
            'hero_btn1sty' => ts('hero_btn1_style', 'primary'),
            'hero_btn2'    => ts('hero_btn2_text', ''),
            'hero_btn2url' => ts('hero_btn2_url', ''),
            'hero_btn2sty' => ts('hero_btn2_style', 'outline-light'),

            // Головний контент
            'main_content' => $mainContent,

            // News
            'news_on'       => $newsOn,
            'news_title'    => ts('news_title', 'Новини'),
            'news_layout'   => ts('news_layout', 'grid'),
            'news_cols'     => (int)ts('news_cols', '3'),
            'news_date'     => ts('news_show_date', '1') === '1',
            'news_author'   => ts('news_show_author', '1') === '1',
            'news_excerpt'  => ts('news_show_excerpt', '1') === '1',
            'news_ex_len'   => (int)ts('news_excerpt_length', '120'),
            'news_thumb'    => ts('news_show_thumb', '1') === '1',
            'news_thumb_h'  => (int)ts('news_thumb_height', '200'),
            'news_readmore' => ts('news_show_readmore', '1') === '1',
            'news_rm_text'  => ts('news_readmore_text', 'Читати більше'),
            'news_bg'       => $newsBg,
            'card_radius'   => (int)ts('card_radius', '8'),
            'bs_cols'       => (int)(12 / min((int)ts('news_cols', '3'), 4)),
            'posts'         => $posts,

            // Divider
            'show_divider'  => $newsOn && count($posts) > 0 && !empty(trim(strip_tags($mainContent))),
            'divider_style' => ts('divider_style', 'wave'),
            'divider_color' => ts('divider_color', '') ?: $newsBg,
            'divider_height'=> $divH,

            // Extra block
            'extra_pos'  => ts('home_extra_position', 'none'),
            'extra_html' => ts('home_extra_block', ''),
        ]);

        return $ctx;
    }

    /**
     * Контекст для page.twig — сторінка або запис.
     */
    public static function getPage(array $pageData, string $contentHtml, array $relatedPosts = []): array
    {
        $isPost   = ($pageData['type'] ?? '') === 'post';
        $title    = $isPost ? ($pageData['meta_title'] ?? $pageData['title'] ?? '') : ($pageData['title'] ?? '');

        // TOC генерація
        $toc = [];
        if ($isPost && ts('post_toc_enabled', '0') === '1' && !empty($contentHtml)) {
            preg_match_all('/<h([2-4])[^>]*>(.*?)<\/h\1>/is', $contentHtml, $hm);
            foreach ($hm[0] as $i => $tag) {
                $level  = (int)$hm[1][$i];
                $text   = strip_tags($hm[2][$i]);
                $anchor = 'toc-' . $i . '-' . preg_replace('/[^\w]/', '-', mb_strtolower($text));
                $toc[]  = ['level' => $level, 'text' => $text, 'anchor' => $anchor];
                $contentHtml = str_replace(
                    $tag,
                    '<h' . $level . ' id="' . $anchor . '">' . $hm[2][$i] . '</h' . $level . '>',
                    $contentHtml
                );
            }
        }

        // Share URLs
        $shareUrl   = urlencode('https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/' . ($pageData['slug'] ?? ''));
        $shareTitle = urlencode($title);

        $ctx = self::getBase([
            'title'           => $title,
            'meta_description'=> $pageData['meta_description'] ?? '',
            'meta_keywords'   => $pageData['meta_keywords']    ?? '',
            'custom_css'      => $pageData['custom_css'] ?? '',
            'custom_js'       => $pageData['custom_js']  ?? '',

            'is_post'         => $isPost,
            'page_data'       => $pageData,
            'content_html'    => $contentHtml,
            'toc'             => $toc,
            'related_posts'   => $relatedPosts,

            // Налаштування відображення запису
            'post_show_meta'    => ts('post_show_meta', '1') === '1',
            'post_meta_pos'     => ts('post_meta_position', 'top'),
            'post_show_related' => ts('post_show_related', '1') === '1',
            'post_content_width'=> (int)ts('post_content_width', '800'),
            'post_sidebar'      => ts('post_sidebar_enabled', '0') === '1',
            'post_sidebar_pos'  => ts('post_sidebar_position', 'right'),
            'post_breadcrumbs'  => ts('post_breadcrumbs', '1') === '1',
            'post_show_share'   => ts('post_show_share', '1') === '1',
            'post_toc'          => ts('post_toc_enabled', '0') === '1',
            'container_max'     => (int)ts('container_max_width', '1140'),

            // Share
            'share_url'   => $shareUrl,
            'share_title' => $shareTitle,
        ]);

        return $ctx;
    }

    private static function buildTree(array $items, ?int $parentId = null): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)$item['parent_id'] === (int)$parentId) {
                $item['children'] = self::buildTree($items, (int)$item['id']);
                $tree[] = $item;
            }
        }
        return $tree;
    }
}