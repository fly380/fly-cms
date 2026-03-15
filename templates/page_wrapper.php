<?php
// templates/page_wrapper.php — читає theme_settings і post_* налаштування

// ── Спільні функції (get_setting, ts, get_display_name, fly_send_security_headers) ──
require_once __DIR__ . '/../config.php';

// ── HTTP Security Headers (централізовано) ────────────────────────
fly_send_security_headers();

// ══════════════════════════════════════════════════════════════════
// САНІТИЗАЦІЯ ВИВОДУ — захист від XSS
// ══════════════════════════════════════════════════════════════════

/**
 * Санітизація HTML-контенту з TinyMCE через DOMDocument.
 *
 * Використовує PHP DOM-парсер замість ненадійних regex:
 *  — повністю видаляє заборонені теги (script, iframe, form тощо)
 *  — прибирає on* атрибути з усіх елементів
 *  — замінює javascript:/vbscript: у href/src/action на "#"
 *  — видаляє style-атрибути з expression() (IE XSS)
 *  — зберігає весь легітимний форматований HTML без змін
 *
 * Fallback на regex-підхід якщо DOMDocument недоступний.
 */
if (!function_exists('fly_sanitize_html')) {
    function fly_sanitize_html(string $html): string {
        if (empty(trim($html))) return '';

        // ── DOMDocument-шлях (надійний) ───────────────────────────
        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            // Вимикаємо помилки парсера (TinyMCE може генерувати не-ідеальний HTML)
            libxml_use_internal_errors(true);
            // UTF-8 обгортка щоб DOMDocument правильно декодував кирилицю
            $dom->loadHTML(
                '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            libxml_clear_errors();

            // Теги, що видаляються повністю разом з вмістом
            $blockedTags = [
                'script', 'style', 'iframe', 'object', 'embed',
                'form', 'input', 'button', 'textarea', 'select',
                'base', 'meta', 'link', 'applet', 'frameset', 'frame',
            ];

            // SVG може містити JS через <animate onbegin>, onload тощо — видаляємо повністю
            $blockedTags[] = 'svg';

            $xpath = new DOMXPath($dom);

            // 1. Видаляємо заборонені теги
            foreach ($blockedTags as $tag) {
                $nodes = $xpath->query("//{$tag}");
                if ($nodes) {
                    foreach (iterator_to_array($nodes) as $node) {
                        $node->parentNode?->removeChild($node);
                    }
                }
            }

            // 2. Прибираємо небезпечні атрибути з усіх елементів
            $allElements = $xpath->query('//*');
            if ($allElements) {
                foreach ($allElements as $el) {
                    /** @var DOMElement $el */
                    $toRemove = [];
                    foreach ($el->attributes as $attr) {
                        $name  = strtolower($attr->nodeName);
                        $value = $attr->nodeValue;

                        // on* атрибути (onclick, onload, onmouseover…)
                        if (str_starts_with($name, 'on')) {
                            $toRemove[] = $attr->nodeName;
                            continue;
                        }

                        // javascript:/vbscript: у href, src, action, data
                        if (in_array($name, ['href', 'src', 'action', 'data'], true)) {
                            $stripped = strtolower(preg_replace('/\s+/', '', $value));
                            if (str_starts_with($stripped, 'javascript:')
                                || str_starts_with($stripped, 'vbscript:')) {
                                $el->setAttribute($attr->nodeName, '#');
                                continue;
                            }
                        }

                        // expression() в style (IE XSS)
                        if ($name === 'style'
                            && preg_match('/expression\s*\(/i', $value)) {
                            $toRemove[] = $attr->nodeName;
                        }
                    }
                    foreach ($toRemove as $attrName) {
                        $el->removeAttribute($attrName);
                    }
                }
            }

            // Витягуємо тільки вміст <body>, без <html>/<head>/<body> обгорток
            $body = $dom->getElementsByTagName('body')->item(0);
            if (!$body) return '';

            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }
            return $result;
        }

        // ── Fallback: regex (на випадок якщо DOMDocument вимкнений) ─
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<(iframe|object|embed|form|input|button|textarea|select|base|meta|link|applet|frameset|frame|svg)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<(iframe|object|embed|form|input|button|textarea|select|base|meta|link|applet|frameset|frame)\b[^>]*\/?>/is', '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is', '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/is', '', $html);
        $html = preg_replace('/(\b(?:href|src|action|data)\s*=\s*["\'])\s*(?:javascript|vbscript|data)\s*:/is', '$1#', $html);
        $html = preg_replace('/style\s*=\s*(["\'])[^"\']*expression\s*\([^"\']*\1/is', '', $html);
        $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is', '', $html);
        return $html;
    }
}

/**
 * Санітизація CSS для тегу <style>.
 * Видаляє expression(), url() з зовнішніх джерел, @import,
 * та спроби вийти за межі CSS-блоку через </style>.
 * custom_css доступний тільки адміну — це додатковий захист.
 */
if (!function_exists('fly_sanitize_css')) {
    function fly_sanitize_css(string $css): string {
        if (empty($css)) return '';

        // Закриваємо спробу вийти з тегу <style>
        $css = str_ireplace('</style', '<\\/style', $css);

        // Видаляємо expression() — IE XSS вектор
        $css = preg_replace('/expression\s*\(/i', 'expression_blocked(', $css);

        // Видаляємо @import (може підвантажити зовнішній CSS з JS)
        $css = preg_replace('/@import\b/i', '/* @import blocked */', $css);

        // Видаляємо url() з зовнішніми посиланнями (дозволяємо тільки /uploads/)
        $css = preg_replace('/url\s*\(\s*["\']?\s*(?!\/uploads\/)(?:https?:|\/\/)[^)]*\)/i', 'url()', $css);

        return $css;
    }
}

/**
 * Санітизація JS для тегу <script>.
 * custom_js доступний ТІЛЬКИ ролі admin (не redaktor).
 * Ця функція — додатковий захист від випадкових помилок.
 */
if (!function_exists('fly_sanitize_js')) {
    function fly_sanitize_js(string $js): string {
        if (empty($js)) return '';

        // Закриваємо спробу вийти з тегу <script>
        $js = str_ireplace('</script', '<\\/script', $js);

        return $js;
    }
}
// ══════════════════════════════════════════════════════════════════

// ── Базові налаштування ────────────────────────────────────────────
$site_title    = get_setting('site_title') ?: 'Сайт';
$logo_path     = get_setting('logo_path')  ?: '/assets/images/111.png';
$favicon_path  = file_exists(__DIR__ . '/../uploads/cms_img/favicon.ico')
    ? '/uploads/cms_img/favicon.ico'
    : (get_setting('favicon_path') ?: '/assets/images/111.png');
$footer_text   = get_setting('footer_text');
$phone         = get_setting('phone_number');
$address       = get_setting('address');
$email         = get_setting('admin_email');

// ── Теоретичні налаштування ────────────────────────────────────────
$navbar_sticky  = ts('navbar_sticky', '1') === '1';
$navbar_style   = ts('navbar_style', 'dark');
$navbar_height  = (int)ts('navbar_height', '60');
$container_max  = (int)ts('container_max_width', '1140');

// ── Налаштування для сторінки запису ──────────────────────────────
$isPost        = isset($pageData) && ($pageData['type'] ?? '') === 'post';
$post_show_hero      = ts('post_show_hero', '1') === '1';
$post_hero_h         = (int)ts('post_hero_height', '350');
$post_show_meta      = ts('post_show_meta', '1') === '1';
$post_meta_pos       = ts('post_meta_position', 'top');
$post_show_author_box= ts('post_show_author_box', '0') === '1';
$post_show_related   = ts('post_show_related', '1') === '1';
$post_related_count  = (int)ts('post_related_count', '3');
$post_content_width  = (int)ts('post_content_width', '800');
$post_sidebar        = ts('post_sidebar_enabled', '0') === '1';
$post_sidebar_pos    = ts('post_sidebar_position', 'right');
$post_breadcrumbs    = ts('post_breadcrumbs', '1') === '1';
$post_show_share     = ts('post_show_share', '1') === '1';
$post_toc            = ts('post_toc_enabled', '0') === '1';

function sanitize_phone_for_tel($p) { return preg_replace('/[^\d\+]/', '', $p); }

// ── Пов'язані записи ──────────────────────────────────────────────
$relatedPosts = [];
if ($isPost && $post_show_related && !empty($pageData['id'])) {
    try {
        $rdb  = fly_db();
        $rstmt = $rdb->prepare("SELECT id, title, slug, meta_description, thumbnail, created_at
            FROM posts WHERE draft=0 AND id != ? ORDER BY created_at DESC LIMIT ?");
        $rstmt->execute([$pageData['id'], $post_related_count]);
        $relatedPosts = $rstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// ── TOC генерація ─────────────────────────────────────────────────
$toc = [];
if ($isPost && $post_toc && !empty($contentHtml)) {
    preg_match_all('/<h([2-4])[^>]*>(.*?)<\/h\1>/is', $contentHtml, $hm);
    foreach ($hm[0] as $i => $tag) {
        $level  = (int)$hm[1][$i];
        $text   = strip_tags($hm[2][$i]);
        $anchor = 'toc-' . $i . '-' . preg_replace('/[^\w]/', '-', mb_strtolower($text));
        $toc[]  = ['level' => $level, 'text' => $text, 'anchor' => $anchor];
        $contentHtml = str_replace($tag, '<h' . $level . ' id="' . $anchor . '">' . $hm[2][$i] . '</h' . $level . '>', $contentHtml);
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($site_title . (!empty($title) ? ' — ' . $title : '')) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= htmlspecialchars($pageData['meta_description'] ?? '') ?>">
  <meta name="keywords"    content="<?= htmlspecialchars($pageData['meta_keywords']    ?? '') ?>">
  <link rel="icon" href="<?= htmlspecialchars($favicon_path) ?>" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <?php if (file_exists(__DIR__ . '/../assets/css/theme.css')): ?>
  <link rel="stylesheet" href="/assets/css/theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
  <?php endif; ?>
  <?php if (!empty($pageData['custom_css'])): ?>
  <style><?= fly_sanitize_css($pageData['custom_css']) ?></style>
  <?php endif; ?>
</head>
<body>

<!-- ── NAVBAR ─────────────────────────────────────────────────────── -->
<header class="navbar navbar-expand-lg navbar-<?= $navbar_style ?> <?= $navbar_sticky ? 'sticky-top' : '' ?> shadow-sm">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="/">
      <img src="<?= htmlspecialchars($logo_path) ?>" alt="Логотип" height="<?= min($navbar_height - 10, 50) ?>">
      <span><?= htmlspecialchars($site_title) ?></span>
    </a>
    <div class="ms-auto d-none d-lg-flex">
      <nav class="navbar-nav flex-row">
        <?php include __DIR__ . '/menu.php'; ?>
      </nav>
    </div>
    <div class="d-lg-none ms-auto">
      <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">☰</button>
    </div>
  </div>
</header>

<!-- ── ОСНОВНИЙ КОНТЕНТ ───────────────────────────────────────────── -->
<main>
<?php if ($isPost): ?>
  <!-- ═══ СТОРІНКА ЗАПИСУ ══════════════════════════════════════════ -->

  <div class="fly-content-wrap container" style="max-width:<?= $container_max ?>px">
    <?php if ($post_breadcrumbs): ?>
    <nav class="fly-breadcrumb mt-3" aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/">Головна</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($title) ?></li>
      </ol>
    </nav>
    <?php endif; ?>

    <div class="row">
      <?php if ($post_sidebar && $post_sidebar_pos === 'left' && !empty($toc)): ?>
      <div class="col-lg-3 order-lg-1">
        <div class="card p-3 mb-3">
          <h6 class="fw-bold">📋 Зміст</h6>
          <ul class="list-unstyled mb-0">
            <?php foreach($toc as $ti): ?>
            <li style="padding-left:<?= ($ti['level']-2)*12 ?>px;font-size:.85rem">
              <a href="#<?= $ti['anchor'] ?>"><?= htmlspecialchars($ti['text']) ?></a>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>

      <div class="<?= $post_sidebar ? 'col-lg-9' : 'col-12' ?> <?= $post_sidebar && $post_sidebar_pos === 'left' ? 'order-lg-2' : '' ?>">
        <article class="fly-post-content mx-auto" style="max-width:<?= $post_sidebar ? '100%' : $post_content_width . 'px' ?>">



          <?php if ($post_toc && !empty($toc)): ?>
          <div class="card bg-light mb-4 p-3">
            <strong class="d-block mb-2">📑 Зміст</strong>
            <ul class="list-unstyled mb-0">
              <?php foreach($toc as $ti): ?>
              <li style="padding-left:<?= ($ti['level']-2)*14 ?>px;font-size:.88rem">
                <a href="#<?= $ti['anchor'] ?>"><?= htmlspecialchars($ti['text']) ?></a>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <div class="fly-post-body">
            <?= fly_sanitize_html($contentHtml) ?>
          </div>

          <?php if ($post_show_meta && $post_meta_pos === 'bottom'): ?>
          <div class="fly-post-meta mt-4 pt-3 border-top d-flex flex-wrap gap-3">
            <?php if (!empty($pageData['author'])): ?>
            <span><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars(get_display_name($pageData['author'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($pageData['created_at'])): ?>
            <span><i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y', strtotime($pageData['created_at'])) ?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($post_show_share): ?>
          <div class="fly-share-btns mt-4">
            <span class="text-muted small me-2">Поділитись:</span>
            <?php $shareUrl = urlencode('https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/post/' . ($pageData['slug'] ?? '')); $shareTitle = urlencode($title); ?>
            <a href="https://t.me/share/url?url=<?= $shareUrl ?>&text=<?= $shareTitle ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-telegram"></i> Telegram</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-facebook"></i> Facebook</a>
            <a href="https://twitter.com/intent/tweet?url=<?= $shareUrl ?>&text=<?= $shareTitle ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-twitter-x"></i> X</a>
          </div>
          <?php endif; ?>



        </article>
      </div>

      <?php if ($post_sidebar && $post_sidebar_pos === 'right' && !empty($toc)): ?>
      <div class="col-lg-3">
        <div class="card p-3 mb-3">
          <h6 class="fw-bold">📋 Зміст</h6>
          <ul class="list-unstyled mb-0">
            <?php foreach($toc as $ti): ?>
            <li style="padding-left:<?= ($ti['level']-2)*12 ?>px;font-size:.85rem">
              <a href="#<?= $ti['anchor'] ?>"><?= htmlspecialchars($ti['text']) ?></a>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>
    </div><!-- /row -->

  </div><!-- /container -->

<?php else: ?>
  <!-- ═══ ЗВИЧАЙНА СТОРІНКА ════════════════════════════════════════ -->
  <div class="container py-4" style="max-width:<?= $container_max ?>px">
    <div class="border p-4 rounded bg-light content-wrapper">
      <h2 class="mb-4"><?= htmlspecialchars($title) ?></h2>
      <?= fly_sanitize_html($contentHtml) ?>
    </div>
  </div>
<?php endif; ?>
</main>

<!-- ── FOOTER ─────────────────────────────────────────────────────── -->
<footer class="footer mt-auto">
  <div class="container text-center">
    <small><?= $footer_text ?: ('&copy; ' . date('Y') . ' ' . htmlspecialchars($site_title)) ?></small>
    <?php if (!empty($phone) || !empty($email)): ?>
    <div class="mt-1">
      <?php if (!empty($phone)): ?><a href="tel:<?= sanitize_phone_for_tel($phone) ?>"><?= htmlspecialchars($phone) ?></a><?php endif; ?>
      <?php if (!empty($phone) && !empty($email)): ?> &nbsp;|&nbsp; <?php endif; ?>
      <?php if (!empty($email)): ?><a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($address)): ?>
    <div class="mt-1 small"><?= nl2br(htmlspecialchars($address)) ?></div>
    <?php endif; ?>
  </div>
</footer>

<!-- Offcanvas mobile menu -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="mobileMenu">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Меню</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">
    <?php $vertical = true; include __DIR__ . '/menu.php'; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/lang-widget.js"></script>
<?php if (!empty($pageData['custom_js'])): ?>
<script><?= fly_sanitize_js($pageData['custom_js']) ?></script>
<?php endif; ?>
</body>
</html>