<?php
// admin/site_settings.php — Theme Builder + Site Settings (unified)
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header("Location: /templates/login.php"); exit;
}
// CSP та інші security headers — централізовано через config.php
require_once __DIR__ . '/../config.php';
fly_send_security_headers();
require_once __DIR__ . '/../data/log_action.php';
$username = $_SESSION['username'] ?? 'admin';

$db = fly_db();

// ── Init theme_settings table ───────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS theme_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$thDef = [
    'navbar_bg'=>'#3B4346','navbar_bg_image'=>'/assets/images/bg01.jpg',
    'navbar_text_color'=>'#ffffff','navbar_brand_color'=>'#ffffff',
    'navbar_height'=>'60','navbar_style'=>'dark','navbar_sticky'=>'1',
    'body_bg_color'=>'#ffffff','body_bg_image'=>'',
    'body_bg_size'=>'auto','body_bg_repeat'=>'repeat','body_bg_attachment'=>'scroll',
    'font_family'=>'system-ui,-apple-system,sans-serif','font_size_base'=>'16',
    'heading_color'=>'#1d2327','text_color'=>'#212529','link_color'=>'#0d6efd',
    'accent_color'=>'#0d6efd','accent_hover'=>'#0a58ca','btn_radius'=>'4',
    'container_max_width'=>'1140','content_padding'=>'40','content_padding_top'=>'40',
    'card_shadow_preset'=>'medium','card_shadow_color'=>'rgba(0,0,0,0.10)',
    'card_shadow_x'=>'0','card_shadow_y'=>'4','card_shadow_blur'=>'18','card_shadow_spread'=>'0',
    'card_hover_lift'=>'1',
    'divider_style'=>'wave','divider_color'=>'','divider_height'=>'50',
    'card_radius'=>'8','card_bg'=>'#ffffff',
    'footer_bg'=>'#212529','footer_text_color'=>'#ffffff','footer_padding'=>'16',
    'custom_css'=>'',
    'hero_enabled'=>'0','hero_layout'=>'centered',
    'hero_bg_color'=>'#1a1a2e','hero_bg_image'=>'','hero_bg_overlay'=>'0.5',
    'hero_title'=>'','hero_subtitle'=>'',
    'hero_btn1_text'=>'Детальніше','hero_btn1_url'=>'#news','hero_btn1_style'=>'primary',
    'hero_btn2_text'=>'','hero_btn2_url'=>'','hero_btn2_style'=>'outline-light',
    'hero_min_height'=>'400','hero_text_color'=>'#ffffff',
    'news_enabled'=>'1','news_title'=>'Новини','news_count'=>'6',
    'news_layout'=>'grid','news_cols'=>'3',
    'news_show_date'=>'1','news_show_author'=>'1','news_show_excerpt'=>'1',
    'news_excerpt_length'=>'120','news_show_thumb'=>'1','news_thumb_height'=>'200',
    'news_show_readmore'=>'1','news_readmore_text'=>'Читати більше',
    'news_category_filter'=>'','news_section_bg'=>'#f8f9fa',
    'home_extra_block'=>'','home_extra_position'=>'none',
    'posts_layout'=>'grid',
    'post_show_hero'=>'1','post_hero_height'=>'350',
    'post_show_meta'=>'1','post_meta_position'=>'top',
    'post_show_author_box'=>'0','post_show_related'=>'1','post_related_count'=>'3',
    'post_content_width'=>'800','post_sidebar_enabled'=>'0','post_sidebar_position'=>'right',
    'post_breadcrumbs'=>'1','post_show_share'=>'1','post_toc_enabled'=>'0',
];
$ins = $db->prepare("INSERT OR IGNORE INTO theme_settings (key,value) VALUES (?,?)");
foreach ($thDef as $k => $v) $ins->execute([$k,$v]);

// ── Міграція: видалити застарілий ключ card_shadow якщо є ─────────
// (замінено на card_shadow_preset)
$db->exec("DELETE FROM theme_settings WHERE key='card_shadow'");

// ── Load current values ────────────────────────────────────────────
$th = [];
foreach ($db->query("SELECT key,value FROM theme_settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
    $th[$r['key']] = $r['value'];
$th = array_merge($thDef, $th);

// ── Авторегенерація CSS якщо theme.css старіший за БД ─────────────
$cssFile = __DIR__ . '/../assets/css/theme.css';
$dbFile  = __DIR__ . '/../data/BD/database.sqlite';
if (!file_exists($cssFile) || filemtime($cssFile) < filemtime($dbFile)) {
    file_put_contents($cssFile, buildCSS($th));
}

$st = [];
foreach ($db->query("SELECT key,value FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
    $st[$r['key']] = $r['value'];

$imgDir  = __DIR__ . '/../uploads/cms_img/';
$uploads = is_dir($imgDir) ? array_values(array_filter(scandir($imgDir),fn($f)=>!in_array($f,['.','..']))) : [];
$cats    = [];
try { $cats = $db->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

$msg = ''; $msgType = 'success'; $activeTab = $_GET['tab'] ?? 'general';

// ── DELETE file ─────────────────────────────────────────────────────
if (isset($_GET['del']) && in_array($_GET['del'],['logo_path','favicon_path','background_image'])) {
    $key = $_GET['del'];
    $old = $st[$key] ?? '';
    if ($old && file_exists(__DIR__.'/..'.$old)) { unlink(__DIR__.'/..'.$old); }
    $db->prepare("DELETE FROM settings WHERE key=?")->execute([$key]);
    unset($st[$key]);
    log_action("🗑 Видалено файл: $old", $username);
    header("Location: site_settings.php?tab=general&saved=1"); exit;
}

// ═══════════════════════════════════════════════════════════════════
//  SAVE
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['_action'] ?? '';
    $activeTab = $_POST['_tab']   ?? 'general';

    // ── Debug log POST shadow keys ─────────────────────────────────
    if ($action === 'save_theme') {
        $debugKeys = ['card_shadow_preset','card_shadow_x','card_shadow_y',
                      'card_shadow_blur','card_shadow_spread','card_hover_lift'];
        $debugLine = date('H:i:s') . " POST tab={$activeTab}";
        foreach ($debugKeys as $k) {
            $debugLine .= " {$k}=" . ($_POST[$k] ?? '<missing>');
        }
        file_put_contents(__DIR__ . '/../data/logs/shadow_debug.log',
            $debugLine . "\n", FILE_APPEND);
    }

    // ── Save general settings ──────────────────────────────────────
    if ($action === 'save_general') {
        $fields = ['site_title','meta_description','meta_keywords','footer_text',
                   'admin_email','phone_number','address'];
        $upsert = $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)");
        foreach ($fields as $f) {
            $v = trim($_POST[$f] ?? '');
            $upsert->execute([$f,$v]);
            $st[$f] = $v;
        }

        // File uploads
        foreach (['logo_path'=>'logo','favicon_path'=>'favicon','background_image'=>'bg'] as $key=>$pfx) {
            if (!empty($_FILES[$key]['tmp_name']) && is_uploaded_file($_FILES[$key]['tmp_name'])) {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','image/x-icon'];
                if (!in_array(mime_content_type($_FILES[$key]['tmp_name']),$allowed)) continue;
                $ext  = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
                $name = $pfx.'_'.time().'.'.$ext;
                if (move_uploaded_file($_FILES[$key]['tmp_name'], $imgDir.$name)) {
                    $upsert->execute([$key, '/uploads/cms_img/'.$name]);
                    $st[$key] = '/uploads/cms_img/'.$name;
                    log_action("📤 Завантажено: $name", $username);
                }
            } elseif (array_key_exists("sel_$key", $_POST)) {
                $sel = $_POST["sel_$key"];
                if ($sel === '') {
                    $db->prepare("DELETE FROM settings WHERE key=?")->execute([$key]);
                    unset($st[$key]);
                } else {
                    $path = '/uploads/cms_img/'.$sel;
                    $upsert->execute([$key,$path]);
                    $st[$key] = $path;
                }
            }
        }
        $uploads = is_dir($imgDir) ? array_values(array_filter(scandir($imgDir),fn($f)=>!in_array($f,['.','..']))) : [];
        log_action("⚙️ Оновлено загальні налаштування", $username);
        $msg = '✅ Загальні налаштування збережено!';
    }

    // ── Save theme ─────────────────────────────────────────────────
    if ($action === 'save_theme') {
        $checks = ['navbar_sticky','card_hover_lift','hero_enabled','news_enabled',
                   'news_show_date','news_show_author','news_show_excerpt','news_show_thumb',
                   'news_show_readmore','post_show_hero','post_show_meta','post_show_author_box',
                   'post_show_related','post_sidebar_enabled','post_breadcrumbs',
                   'post_show_share','post_toc_enabled'];
        // Зберігаємо ТІЛЬКИ ключі поточної вкладки — не чіпаємо решту
        $tabKeys = [
            'design'   => ['navbar_bg','navbar_bg_image','navbar_text_color','navbar_brand_color',
                           'navbar_height','navbar_style','navbar_sticky','body_bg_color','body_bg_image',
                           'body_bg_size','body_bg_repeat','body_bg_attachment','font_family','font_size_base',
                           'heading_color','text_color','link_color','accent_color','accent_hover','btn_radius',
                           'container_max_width','content_padding','content_padding_top',
                           'card_shadow_preset','card_shadow_color',
                           'card_shadow_x','card_shadow_y','card_shadow_blur','card_shadow_spread',
                           'card_hover_lift','divider_style','divider_color','divider_height',
                           'card_radius','card_bg','footer_bg','footer_text_color','footer_padding'],
            'homepage' => ['hero_enabled','hero_layout','hero_bg_color','hero_bg_image','hero_bg_overlay',
                           'hero_title','hero_subtitle','hero_btn1_text','hero_btn1_url','hero_btn1_style',
                           'hero_btn2_text','hero_btn2_url','hero_btn2_style','hero_min_height','hero_text_color',
                           'news_enabled','news_title','news_count','news_layout','news_cols','news_show_date',
                           'news_show_author','news_show_excerpt','news_excerpt_length','news_show_thumb',
                           'news_thumb_height','news_show_readmore','news_readmore_text','news_category_filter',
                           'news_section_bg','home_extra_block','home_extra_position',
                           'divider_style','divider_color','divider_height'],
            'postpage' => ['posts_layout','post_show_hero','post_hero_height','post_show_meta',
                           'post_meta_position','post_show_author_box','post_show_related','post_related_count',
                           'post_content_width','post_sidebar_enabled','post_sidebar_position',
                           'post_breadcrumbs','post_show_share','post_toc_enabled'],
            'css'      => ['custom_css'],
        ];
        $saveKeys = $tabKeys[$activeTab] ?? array_keys($thDef);
        $stmt = $db->prepare("INSERT OR REPLACE INTO theme_settings (key,value,updated_at) VALUES (?,?,datetime('now'))");
        foreach ($saveKeys as $k) {
            if (!array_key_exists($k, $thDef)) continue;
            $val = in_array($k, $checks) ? (isset($_POST[$k]) ? '1' : '0') : ($_POST[$k] ?? $th[$k] ?? $thDef[$k]);
            if ($k === 'hero_bg_overlay' && (float)$val > 1) $val = round((float)$val/100, 2);
            $stmt->execute([$k, $val]);
        }
        // Перечитуємо всі значення з БД для генерації CSS
        $th = $thDef;
        foreach ($db->query("SELECT key,value FROM theme_settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $th[$r['key']] = $r['value'];
        file_put_contents(__DIR__.'/../assets/css/theme.css', buildCSS($th));
        log_action("🎨 Збережено тему", $username);
        $msg = '✅ Тему збережено! CSS оновлено.';
    }

    // ── Reset theme ────────────────────────────────────────────────
    if ($action === 'reset_theme') {
        $stmt = $db->prepare("INSERT OR REPLACE INTO theme_settings (key,value,updated_at) VALUES (?,?,datetime('now'))");
        foreach ($thDef as $k => $v) { $stmt->execute([$k,$v]); }
        $th = $thDef;
        file_put_contents(__DIR__.'/../assets/css/theme.css', buildCSS($th));
        log_action("🎨 Тему скинуто до дефолтів", $username);
        $msg = '✅ Тему скинуто до дефолтів.'; $activeTab = 'design';
    }
}

// ═══════════════════════════════════════════════════════════════════
//  CSS GENERATOR
// ═══════════════════════════════════════════════════════════════════
function buildCSS(array $t): string {
    $cr=$t['card_radius'];
    // ── Тінь карток: пресет або власна ──────────────────────────────
    $shadowPresets = [
        'none'   => 'none',
        'subtle' => '0 1px 4px rgba(0,0,0,0.06)',
        'small'  => '0 2px 8px rgba(0,0,0,0.08)',
        'medium' => '0 4px 18px rgba(0,0,0,0.10)',
        'large'  => '0 8px 32px rgba(0,0,0,0.14)',
        'xl'     => '0 16px 48px rgba(0,0,0,0.18)',
    ];
    $preset = $t['card_shadow_preset'] ?? 'medium';
    if ($preset === 'none') {
        $cs = 'none';
    } elseif ($preset === 'custom') {
        $sx = (int)($t['card_shadow_x'] ?? 0);
        $sy = (int)($t['card_shadow_y'] ?? 4);
        $sb = (int)($t['card_shadow_blur'] ?? 18);
        $ss = (int)($t['card_shadow_spread'] ?? 0);
        $sc = $t['card_shadow_color'] ?? 'rgba(0,0,0,0.10)';
        $cs = "{$sx}px {$sy}px {$sb}px {$ss}px {$sc}";
    } else {
        $cs = $shadowPresets[$preset] ?? $shadowPresets['medium'];
    }
    // Hover-тінь: збільшуємо прозорість rgba у 2x, з fallback якщо немає rgba
    if ($cs === 'none') {
        $csHover = 'none';
    } else {
        $csHover = preg_replace_callback(
            '/rgba\(([^,]+,[^,]+,[^,]+),\s*([0-9]*\.?[0-9]+)\)/',
            function($m) {
                $newAlpha = min(1.0, round((float)$m[2] * 2.0, 2));
                return 'rgba(' . $m[1] . ',' . $newAlpha . ')';
            },
            $cs
        );
        // Якщо rgba не знайдено — просто використовуємо базову тінь
        if ($csHover === null || $csHover === $cs) {
            $csHover = $cs;
        }
    }
    $nh=(int)$t['navbar_height']; $fp=(int)$t['footer_padding'];
    $cm=(int)$t['container_max_width']; $cp=(int)$t['content_padding'];
    $cpt=(int)($t['content_padding_top']??$cp); $br=(int)$t['btn_radius'];
    $ov=(float)$t['hero_bg_overlay']; if($ov>1)$ov=round($ov/100,2);
    $heroH=(int)$t['hero_min_height']; $thumbH=(int)$t['news_thumb_height'];
    $pw=(int)$t['post_content_width']; $ph=(int)$t['post_hero_height'];
    $bodyBg=$t['body_bg_image']
        ?"background-color:{$t['body_bg_color']};background-image:url('{$t['body_bg_image']}');background-size:{$t['body_bg_size']};background-repeat:{$t['body_bg_repeat']};background-attachment:{$t['body_bg_attachment']};"
        :"background-color:{$t['body_bg_color']};";
    $navBg=$t['navbar_bg_image']?"background:{$t['navbar_bg']} url('{$t['navbar_bg_image']}') center/cover;"
        :"background:{$t['navbar_bg']};";
    $css="/* fly-CMS theme.css — ".date('Y-m-d H:i')." */\n\n";
    $css.=":root{\n  --accent:{$t['accent_color']};--accent-h:{$t['accent_hover']};\n";
    $css.="  --text:{$t['text_color']};--head:{$t['heading_color']};--link:{$t['link_color']};\n";
    $css.="  --card-bg:{$t['card_bg']};--card-r:{$cr}px;--card-sh:{$cs};\n";
    $css.="  --btn-r:{$br}px;--foot-bg:{$t['footer_bg']};--foot-c:{$t['footer_text_color']};\n}\n\n";
    $css.="body{{$bodyBg}font-family:{$t['font_family']};font-size:{$t['font_size_base']}px;color:{$t['text_color']};}\n";
    $css.="h1,h2,h3,h4,h5,h6{color:{$t['heading_color']};}\na{color:{$t['link_color']};}a:hover{color:{$t['accent_hover']};}\n\n";
    $css.=".container,.container-fluid{max-width:{$cm}px;}\nmain{padding-top:{$cpt}px;padding-bottom:{$cp}px;}\n\n";
    // Захист контенту коли є фонове зображення
    if(!empty(trim($t['body_bg_image']))){
        $cbg=$t['body_bg_color']?:('#ffffff');
        $css.="/* Content bg protection */\n";
        $css.="main > section:not(.fly-hero), main > div, main > article { background-color:{$cbg}; }\n";
        $css.=".fly-news-section, .fly-post-content, .fly-content-wrap { background-color:{$cbg} !important; }\n\n";
    }
    $css.=".navbar{{$navBg}min-height:{$nh}px;border-bottom:1px solid rgba(0,0,0,.1);}\n";
    $css.=".navbar .nav-link{color:{$t['navbar_text_color']} !important;}\n.navbar-brand{color:{$t['navbar_brand_color']} !important;}\n";
    if($t['navbar_sticky']==='1')$css.=".navbar{position:sticky;top:0;z-index:1030;}\nbody>main{margin-top:0;}\n";
    $css.="\n.card{background:{$t['card_bg']};border-radius:{$cr}px;box-shadow:{$cs};}\n";
    $css.=".btn-primary{background:{$t['accent_color']};border-color:{$t['accent_color']};border-radius:{$br}px;}\n";
    $css.=".btn-primary:hover{background:{$t['accent_hover']};border-color:{$t['accent_hover']};}\n.btn{border-radius:{$br}px;}\n\n";
    $css.=".footer,footer{background:{$t['footer_bg']} !important;color:{$t['footer_text_color']} !important;padding:{$fp}px 0;}\n";
    $css.=".footer a,footer a{color:{$t['footer_text_color']};opacity:.8;}\n\n";
    $css.="/* Hero */\n.fly-hero{position:relative;min-height:{$heroH}px;display:flex;align-items:center;overflow:hidden;}\n";
    $css.=".fly-hero-bg{position:absolute;inset:0;background:{$t['hero_bg_color']}";
    if(!empty($t['hero_bg_image']))$css.=" url('{$t['hero_bg_image']}') center/cover no-repeat";
    $css.=";}\n.fly-hero-overlay{position:absolute;inset:0;background:rgba(0,0,0,{$ov});}\n";
    $css.=".fly-hero-content{position:relative;z-index:2;color:{$t['hero_text_color']};width:100%;}\n";
    $css.=".fly-hero-content.centered{text-align:center;}\n.fly-hero-content.left{text-align:left;max-width:640px;}\n\n";
    $css.="/* News */\n.fly-news-section{background:{$t['news_section_bg']};padding:3rem 0;}\n";
    $css.=".fly-news-thumb-wrap{overflow:hidden;flex-shrink:0;}\n";
    $css.=".fly-news-thumb-wrap img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s;}\n";
    $css.=".fly-news-card{background:{$t['card_bg']};border-radius:{$cr}px;border:1px solid #e0e4ea;box-shadow:{$cs};overflow:hidden;height:100%;transition:transform .2s,box-shadow .2s;}\n";
    $liftVal = ($t['card_hover_lift'] ?? '1') === '1' ? 'translateY(-5px)' : 'none';
    $css.=".fly-news-card:hover{transform:{$liftVal};box-shadow:{$csHover};}\n";
    $css.=".fly-news-card:hover .fly-news-thumb-wrap img{transform:scale(1.04);}\n";
    $css.=".fly-news-card .card-body{border-top:2px solid #f0f2f5;}\n\n";
    $css.="/* Post */\n.fly-post-hero{height:{$ph}px;object-fit:cover;width:100%;}\n";
    $css.=".fly-post-content{max-width:{$pw}px;margin:0 auto;background:{$t['card_bg']};padding:2rem;border-radius:{$cr}px;box-shadow:0 2px 16px rgba(0,0,0,.07);}\n";
    $css.=".fly-post-meta{font-size:.875rem;color:#6c757d;}\n";
    $css.=".fly-share-btns{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:2rem;}\n";
    $css.=".fly-author-box{border:1px solid #dee2e6;border-radius:{$cr}px;padding:1.25rem;margin-top:2rem;display:flex;gap:1rem;align-items:center;}\n\n";
    if(!empty(trim($t['custom_css']??'')))$css.="/* Custom */\n{$t['custom_css']}\n";
    return $css;
}

// ── helpers ────────────────────────────────────────────────────────
function gs(string $k): string { global $st; return htmlspecialchars($st[$k]??''); }
function gr(string $k): string { global $st; return $st[$k]??''; }
function tv(string $k): string { global $th,$thDef; return htmlspecialchars($th[$k]??($thDef[$k]??'')); }
function tr(string $k): string { global $th,$thDef; return $th[$k]??($thDef[$k]??''); }
function tBool(string $k): bool { global $th; return ($th[$k]??'0')==='1'; }

ob_start();
?>
<style>
/* ═══════════════ UNIFIED THEME BUILDER ════════════════════════════ */
:root{--p:#2271b1;--ph:#135e96;}

/* ── Force full-page layout for this page ── */
body.fullbleed-page {
    padding-top: 0 !important;
    overflow: hidden !important;
    height: 100vh !important;
}
body.fullbleed-page main.content {
    position: fixed !important;
    top: 0 !important;
    left: 220px !important;
    right: 0 !important;
    bottom: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    height: 100vh !important;
}
@media (max-width: 767.98px) {
    body.fullbleed-page main.content {
        left: 0 !important;
        top: 56px !important;
        height: calc(100vh - 56px) !important;
    }
}

/* Two-column layout */
.tb-layout{display:grid;grid-template-columns:420px 1fr;height:100%;overflow:hidden;}
.tb-controls{display:flex;flex-direction:column;overflow:hidden;background:#f4f5f7;height:100%;}
.tb-preview{background:#16213e;display:flex;flex-direction:column;overflow:hidden;height:100%;}

/* ── Tab bar ── */
.tb-tabs{display:flex;background:#1d2327;overflow-x:auto;scrollbar-width:none;flex-shrink:0;}
.tb-tabs::-webkit-scrollbar{display:none;}
.tb-tab{flex:0 0 auto;padding:10px 14px;font-size:.76rem;font-weight:600;color:#8c8f94;cursor:pointer;
        border:none;background:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s;}
.tb-tab:hover{color:#dcdcde;background:rgba(255,255,255,.05);}
.tb-tab.active{color:#fff;border-bottom-color:var(--p);background:rgba(255,255,255,.07);}

/* Forms as flex column — pane scrolls, save bar sticks to bottom */
.tb-controls > form {
    display: none;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
    height: 100%;
}
.tb-controls > form.active-form {
    display: flex;
}
.tb-controls > form > .tb-pane {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    display: none;
    padding: 12px;
}
.tb-controls > form > .tb-pane.active {
    display: block;
}

/* ── Save bar — always visible at bottom of form ── */
.tb-save {
    padding: 10px 12px;
    background: #fff;
    border-top: 1px solid #e5e7eb;
    flex-shrink: 0;
    display: flex;
    gap: 7px;
    z-index: 5;
}
.tb-save .btn{flex:1;font-weight:600;font-size:.82rem;}


/* ── Accordion cards ── */
.tb-card{background:#fff;border:1px solid #e5e7eb;border-radius:9px;margin-bottom:10px;overflow:hidden;}
.tb-card-h{padding:9px 13px;font-size:.79rem;font-weight:700;color:#1d2327;background:#f9fafb;
           border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;
           cursor:pointer;user-select:none;gap:8px;}
.tb-card-h span{flex:1;}
.tb-caret{font-size:.65rem;color:#9ca3af;transition:transform .2s;flex-shrink:0;}
.tb-card-h.closed .tb-caret{transform:rotate(-90deg);}
.tb-card-b{padding:13px;}

/* ── Color row ── */
.cr{display:flex;align-items:center;gap:7px;margin-bottom:10px;}
.cr-lbl{flex:0 0 128px;font-size:.78rem;font-weight:500;color:#374151;line-height:1.3;}
.cr-ctrl{display:flex;gap:5px;align-items:center;flex:1;}
.cpick{width:32px;height:28px;border:1px solid #d1d5db;border-radius:4px;padding:2px;cursor:pointer;flex-shrink:0;}
.cr-txt{flex:1;height:28px;font-size:.78rem;font-family:monospace;}

/* ── Range row ── */
.rr{display:flex;align-items:center;gap:7px;margin-bottom:10px;}
.rr-lbl{flex:0 0 128px;font-size:.78rem;font-weight:500;color:#374151;}
.rr-val{min-width:42px;text-align:right;font-size:.75rem;color:#6c757d;font-variant-numeric:tabular-nums;}

/* ── Toggle ── */
.tg{position:relative;width:36px;height:20px;flex-shrink:0;cursor:pointer;}
.tg input{opacity:0;width:0;height:0;position:absolute;}
.tg-sl{position:absolute;inset:0;background:#d1d5db;border-radius:20px;transition:.22s;}
.tg-sl:before{content:'';position:absolute;width:14px;height:14px;left:3px;bottom:3px;
              background:#fff;border-radius:50%;transition:.22s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
.tg input:checked+.tg-sl{background:var(--p);}
.tg input:checked+.tg-sl:before{transform:translateX(16px);}
.tr-row{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.tr-lbl{flex:0 0 128px;font-size:.78rem;font-weight:500;color:#374151;}

/* ── Visual card selector ── */
.vcs{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;}
.vcs-opt{flex:1;min-width:68px;max-width:120px;border:2px solid #e5e7eb;border-radius:8px;
         padding:8px 5px;text-align:center;cursor:pointer;transition:all .15s;background:#fafafa;
         display:flex;flex-direction:column;align-items:center;gap:4px;}
.vcs-opt:hover{border-color:#93c5fd;background:#f0f9ff;}
.vcs-opt.on{border-color:var(--p);background:#eff6ff;box-shadow:0 0 0 2px rgba(34,113,177,.15);}
.vcs-ic{font-size:1.3rem;line-height:1;}
.vcs-lbl{font-size:.68rem;font-weight:600;color:#374151;}
.vcs-opt.on .vcs-lbl{color:var(--p);}

/* ── Visual cols picker ── */
.cols-pick{display:flex;gap:6px;margin-bottom:8px;}
.cols-opt{flex:1;border:2px solid #e5e7eb;border-radius:8px;padding:8px 4px;
          cursor:pointer;transition:all .15s;background:#fafafa;overflow:hidden;}
.cols-opt:hover{border-color:#93c5fd;}
.cols-opt.on{border-color:var(--p);background:#eff6ff;}
.cols-grid{display:grid;gap:3px;padding:4px;}
.cols-block{background:#c7d2fe;border-radius:3px;height:24px;}
.cols-opt.on .cols-block{background:var(--p);opacity:.7;}
.cols-num{text-align:center;font-size:.68rem;font-weight:700;color:#374151;margin-top:4px;}
.cols-opt.on .cols-num{color:var(--p);}

/* ── Layout picker (list/grid visual) ── */
.layout-pick{display:flex;gap:8px;margin-bottom:8px;}
.lp-opt{flex:1;border:2px solid #e5e7eb;border-radius:8px;padding:10px 8px;cursor:pointer;
        transition:all .15s;background:#fafafa;}
.lp-opt:hover{border-color:#93c5fd;}
.lp-opt.on{border-color:var(--p);background:#eff6ff;}
.lp-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:3px;margin-bottom:5px;}
.lp-list-items{display:flex;flex-direction:column;gap:3px;margin-bottom:5px;}
.lp-block{background:#c7d2fe;border-radius:3px;height:20px;}
.lp-list-block{background:#c7d2fe;border-radius:3px;height:10px;}
.lp-opt.on .lp-block,.lp-opt.on .lp-list-block{background:var(--p);opacity:.7;}
.lp-lbl{text-align:center;font-size:.68rem;font-weight:700;color:#374151;}
.lp-opt.on .lp-lbl{color:var(--p);}

/* ── Navbar/footer mini preview ── */
.mp-bar{border-radius:6px;overflow:hidden;margin-top:10px;box-shadow:0 2px 8px rgba(0,0,0,.12);}
.mp-nav{height:32px;display:flex;align-items:center;padding:0 12px;gap:8px;font-size:.72rem;font-weight:600;}
.mp-content{background:#f8f9fa;padding:8px 12px;}
.mp-content-block{height:8px;background:#e5e7eb;border-radius:3px;margin-bottom:4px;}
.mp-content-block:last-child{width:70%;}
.mp-footer{height:26px;display:flex;align-items:center;justify-content:center;font-size:.68rem;}

/* ── Section divider ── */
.sdiv{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
      color:#9ca3af;margin:12px 0 7px;padding-bottom:4px;border-bottom:1px dashed #e5e7eb;}

/* ── Hero live preview ── */
.hp-hero{border-radius:8px;overflow:hidden;margin:8px 0;position:relative;display:flex;
         align-items:center;justify-content:center;}
.hp-hero-bg{position:absolute;inset:0;transition:background .3s;}
.hp-hero-ov{position:absolute;inset:0;transition:background .3s;}
.hp-hero-txt{position:relative;z-index:2;padding:16px;text-align:center;}
.hp-hero-title{font-size:.9rem;font-weight:700;margin-bottom:4px;}
.hp-hero-sub{font-size:.7rem;opacity:.85;margin-bottom:8px;}
.hp-hero-btns{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;}
.hp-btn{padding:3px 10px;border-radius:4px;font-size:.68rem;font-weight:600;border:none;cursor:default;}

/* ── News preview ── */
.np-section{border-radius:8px;overflow:hidden;margin:8px 0;padding:10px;}
.np-title{font-size:.8rem;font-weight:700;margin-bottom:8px;color:#1d2327;}
.np-grid{display:grid;gap:6px;}
.np-item{background:#fff;border-radius:5px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.np-thumb{height:36px;background:linear-gradient(135deg,#c7d2fe,#a5b4fc);display:flex;align-items:center;justify-content:center;font-size:.9rem;}
.np-body{padding:5px 7px;}
.np-post-title{font-size:.68rem;font-weight:600;color:#1d2327;margin-bottom:2px;}
.np-meta{font-size:.6rem;color:#9ca3af;}
.np-excerpt{font-size:.6rem;color:#6c757d;margin-top:2px;line-height:1.3;}

/* ── Alert ── */
.tb-alert{
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(0);
    padding: 11px 20px;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 9999;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    opacity: 1;
    transition: opacity .4s ease, transform .4s ease;
    pointer-events: none;
    white-space: nowrap;
}
.tb-alert.ok{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;}
.tb-alert.err{background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;}
.tb-alert.hiding{opacity:0;transform:translateX(-50%) translateY(12px);}

/* ── File upload row ── */
.fi-lbl{font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.fi-row{margin-bottom:12px;}
.fi-thumb{height:44px;max-width:100%;object-fit:contain;border:1px solid #e5e7eb;
          border-radius:5px;padding:3px;background:#f9fafb;display:block;margin-top:5px;}
.fi-thumb.wide{height:64px;width:100%;object-fit:cover;}

/* ── Preview pane ── */
.pv-bar{padding:8px 12px;display:flex;align-items:center;gap:8px;flex-shrink:0;}
.pv-bar h6{color:#94a3b8;margin:0;font-size:.75rem;font-weight:600;}
.pv-devs{display:flex;gap:3px;margin-left:auto;}
.pv-devs button{background:rgba(255,255,255,.1);border:none;color:#94a3b8;padding:4px 9px;
                border-radius:4px;font-size:.7rem;cursor:pointer;transition:.15s;}
.pv-devs button.on,.pv-devs button:hover{background:rgba(255,255,255,.25);color:#fff;}
.pv-wrap{flex:1;display:flex;align-items:stretch;justify-content:center;overflow:hidden;padding:0 0 8px;}
#pvIframe{width:100%;border:none;background:#fff;transition:max-width .25s;}
#pvIframe.tab{max-width:768px;box-shadow:0 0 0 2px #334155;}
#pvIframe.mob{max-width:375px;box-shadow:0 0 0 2px #334155;}

@media(max-width:1100px){
  .tb-layout{grid-template-columns:1fr;}
  .tb-preview{display:none;}
}
</style>

<?php if ($msg): ?>
<div class="tb-alert ok" id="tbToast"><span>✅</span><?= $msg ?></div>
<script>
(function(){
    var t = document.getElementById('tbToast');
    if(!t) return;
    setTimeout(function(){ t.classList.add('hiding'); setTimeout(function(){ t.remove(); }, 420); }, 3000);
})();
</script>
<?php endif; ?>

<div class="tb-layout">
<!-- ═══════════════ CONTROLS ════════════════════════════════════════ -->
<div class="tb-controls">

  <!-- Tabs -->
  <div class="tb-tabs">
    <button class="tb-tab <?= $activeTab==='general'?'active':'' ?>" onclick="toTab(this,'general')">⚙️ Загальне</button>
    <button class="tb-tab <?= $activeTab==='design'?'active':'' ?>"  onclick="toTab(this,'design')">🎨 Дизайн</button>
    <button class="tb-tab <?= $activeTab==='homepage'?'active':'' ?>" onclick="toTab(this,'homepage')">🏠 Головна</button>
    <button class="tb-tab <?= $activeTab==='postpage'?'active':'' ?>" onclick="toTab(this,'postpage')">📄 Записи</button>
    <button class="tb-tab <?= $activeTab==='css'?'active':'' ?>"     onclick="toTab(this,'css')">💻 CSS</button>
  </div>

  <!-- ══ ЗАГАЛЬНЕ ══════════════════════════════════════════════════ -->
  <form method="POST" enctype="multipart/form-data" id="fGen">
  <input type="hidden" name="_action" value="save_general">
  <input type="hidden" name="_tab"   value="general">
  <div class="tb-pane <?= $activeTab==='general'?'active':'' ?>" id="pane-general">

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🌐 Назва та контакти</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="fi-row"><label class="fi-lbl">Назва сайту</label>
          <input name="site_title" class="form-control form-control-sm" value="<?= gs('site_title') ?>" placeholder="Моя CMS"></div>
        <div class="fi-row"><label class="fi-lbl">Текст підвалу</label>
          <input name="footer_text" class="form-control form-control-sm" value="<?= gs('footer_text') ?>"></div>
        <div class="fi-row"><label class="fi-lbl">Email</label>
          <input type="email" name="admin_email" class="form-control form-control-sm" value="<?= gs('admin_email') ?>"></div>
        <div class="fi-row"><label class="fi-lbl">Телефон</label>
          <input type="tel" name="phone_number" class="form-control form-control-sm" value="<?= gs('phone_number') ?>" placeholder="+380 XX XXX XX XX"></div>
        <div class="fi-row"><label class="fi-lbl">Адреса</label>
          <input name="address" class="form-control form-control-sm" value="<?= gs('address') ?>"></div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🔍 SEO</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="fi-row"><label class="fi-lbl">Meta опис <span id="mdl" class="text-muted fw-normal">(<?= strlen(gr('meta_description')) ?>/160)</span></label>
          <textarea name="meta_description" class="form-control form-control-sm" rows="3" maxlength="160"
                    oninput="document.getElementById('mdl').textContent='('+this.value.length+'/160)'"><?= gs('meta_description') ?></textarea></div>
        <div class="fi-row"><label class="fi-lbl">Meta keywords</label>
          <input name="meta_keywords" class="form-control form-control-sm" value="<?= gs('meta_keywords') ?>" placeholder="слово1, слово2"></div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🖼 Медіа-файли</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">

        <?php foreach(['logo_path'=>['Логотип','logo',false],'favicon_path'=>['Favicon','favicon',false],'background_image'=>['Фоновий малюнок','bg',true]] as $key=>[$lbl,$pfx,$wide]): ?>
        <div class="sdiv"><?= $lbl ?></div>
        <div class="fi-row">
          <label class="fi-lbl">Завантажити новий файл</label>
          <input type="file" name="<?= $key ?>" class="form-control form-control-sm" accept="image/*"
                 onchange="fiPrev(this,'prv-<?= $key ?>')">
          <?php $cur=gr($key); if($cur): ?><img src="<?= htmlspecialchars($cur) ?>?t=<?= time() ?>" id="prv-<?= $key ?>" class="fi-thumb <?= $wide?'wide':'' ?>" alt=""><?php endif; ?>
        </div>
        <div class="fi-row">
          <label class="fi-lbl">Або вибрати з медіа-бібліотеки</label>
          <select name="sel_<?= $key ?>" class="form-select form-select-sm" onchange="selPrev(this,'prv-<?= $key ?>')">
            <option value="">— Видалити / не змінювати —</option>
            <?php foreach($uploads as $f): ?>
            <option value="<?= htmlspecialchars($f) ?>" <?= $cur==='/uploads/cms_img/'.$f?'selected':'' ?>><?= htmlspecialchars($f) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if($cur): ?>
        <a href="?del=<?= $key ?>" class="btn btn-outline-danger btn-sm mb-3" onclick="return confirm('Видалити файл <?= $lbl ?>?')">
          🗑 Видалити <?= $lbl ?></a>
        <?php endif; ?>
        <?php endforeach; ?>

      </div>
    </div>

  </div>
    <div class="tb-save" id="save-general">
      <button type="submit" class="btn btn-primary">💾 Зберегти загальне</button>
      <a href="/" target="_blank" class="btn btn-outline-secondary" style="flex:0 0 auto;padding:7px 12px">↗ Сайт</a>
    </div>
  </form>

  <!-- ══ ДИЗАЙН ════════════════════════════════════════════════════ -->
  <form method="POST" id="fDesign">
  <input type="hidden" name="_action" value="save_theme">
  <input type="hidden" name="_tab"   value="design">
  <?php /* pass through all theme keys not in this tab */ ?>
  <?php $designKeys=['navbar_bg','navbar_bg_image','navbar_text_color','navbar_brand_color','navbar_height','navbar_style','navbar_sticky',
    'body_bg_color','body_bg_image','body_bg_size','body_bg_repeat','body_bg_attachment',
    'font_family','font_size_base','heading_color','text_color','link_color',
    'accent_color','accent_hover','btn_radius','container_max_width','content_padding','content_padding_top',
    'card_shadow_preset','card_shadow_color','card_shadow_x','card_shadow_y','card_shadow_blur','card_shadow_spread',
    'card_hover_lift','divider_style','divider_color','divider_height',
    'card_radius','card_bg','footer_bg','footer_text_color','footer_padding']; ?>
  <?php foreach($thDef as $k=>$d): if(!in_array($k,$designKeys)&&$k!=='custom_css'): ?>
  <input type="hidden" name="<?= $k ?>" value="<?= tv($k) ?>">
  <?php endif; endforeach; ?>
  <div class="tb-pane <?= $activeTab==='design'?'active':'' ?>" id="pane-design">

    <!-- NAVBAR -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🔝 Шапка (Navbar)</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="cr"><span class="cr-lbl">Фон</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('navbar_bg') ?>" oninput="syncC(this,'navbar_bg');mpUpd()">
          <input type="text" name="navbar_bg" id="navbar_bg" class="form-control form-control-sm cr-txt" value="<?= tv('navbar_bg') ?>" oninput="syncT(this,'navbar_bg');mpUpd()">
        </div></div>
        <div class="cr"><span class="cr-lbl">Фото фону (URL)</span><div class="cr-ctrl">
          <input type="text" name="navbar_bg_image" class="form-control form-control-sm cr-txt" value="<?= tv('navbar_bg_image') ?>" placeholder="порожньо = без фото">
        </div></div>
        <div class="cr"><span class="cr-lbl">Колір меню</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('navbar_text_color') ?>" oninput="syncC(this,'navbar_text_color');mpUpd()">
          <input type="text" name="navbar_text_color" id="navbar_text_color" class="form-control form-control-sm cr-txt" value="<?= tv('navbar_text_color') ?>" oninput="syncT(this,'navbar_text_color');mpUpd()">
        </div></div>
        <div class="cr"><span class="cr-lbl">Назва бренду</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('navbar_brand_color') ?>" oninput="syncC(this,'navbar_brand_color');mpUpd()">
          <input type="text" name="navbar_brand_color" id="navbar_brand_color" class="form-control form-control-sm cr-txt" value="<?= tv('navbar_brand_color') ?>" oninput="syncT(this,'navbar_brand_color');mpUpd()">
        </div></div>
        <div class="rr"><span class="rr-lbl">Висота</span>
          <input type="range" name="navbar_height" class="form-range flex-grow-1" min="40" max="120" step="2" value="<?= tv('navbar_height') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('navbar_height') ?>px</span>
        </div>
        <div class="cr"><span class="cr-lbl">Стиль Bootstrap</span><div class="cr-ctrl">
          <select name="navbar_style" class="form-select form-select-sm">
            <option value="dark"  <?= tr('navbar_style')==='dark'?'selected':'' ?>>dark (темна)</option>
            <option value="light" <?= tr('navbar_style')==='light'?'selected':'' ?>>light (світла)</option>
          </select>
        </div></div>
        <div class="tr-row"><span class="tr-lbl">Прилипаюча</span>
          <label class="tg"><input type="checkbox" name="navbar_sticky" <?= tBool('navbar_sticky')?'checked':'' ?>><span class="tg-sl"></span></label>
        </div>
        <!-- mini live preview navbar -->
        <div class="mp-bar" style="margin-top:8px">
          <div id="mpNavbar" class="mp-nav" style="background:<?= tv('navbar_bg') ?>;color:<?= tv('navbar_text_color') ?>;border-radius:6px 6px 0 0">
            🏠&nbsp;<strong id="mpBrand" style="color:<?= tv('navbar_brand_color') ?>"><?= gs('site_title')?:htmlspecialchars('Назва сайту') ?></strong>
            <span style="margin-left:auto;opacity:.7;font-size:.65rem">Головна · Новини · Про нас</span>
          </div>
          <div class="mp-content">
            <div class="mp-content-block"></div>
            <div class="mp-content-block"></div>
          </div>
          <div id="mpFooter" class="mp-footer" style="background:<?= tv('footer_bg') ?>;color:<?= tv('footer_text_color') ?>;border-radius:0 0 6px 6px">
            © 2026 <?= gs('site_title')?:htmlspecialchars('Назва сайту') ?>
          </div>
        </div>
      </div>
    </div>

    <!-- BODY BG -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🖼 Фон сторінки</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="cr"><span class="cr-lbl">Колір фону</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('body_bg_color') ?>" oninput="syncC(this,'body_bg_color')">
          <input type="text" name="body_bg_color" id="body_bg_color" class="form-control form-control-sm cr-txt" value="<?= tv('body_bg_color') ?>" oninput="syncT(this,'body_bg_color')">
        </div></div>
        <div class="cr"><span class="cr-lbl">З медіа-бібліотеки</span><div class="cr-ctrl">
          <select class="form-select form-select-sm" onchange="document.getElementById('bgImg').value=this.value?'/uploads/cms_img/'+this.value:''">
            <option value="">— без зображення —</option>
            <?php foreach($uploads as $f): $c=tr('body_bg_image')==='/uploads/cms_img/'.$f?'selected':''; ?>
            <option value="<?= htmlspecialchars($f) ?>" <?= $c ?>><?= htmlspecialchars($f) ?></option>
            <?php endforeach; ?>
          </select>
        </div></div>
        <input type="hidden" name="body_bg_image" id="bgImg" value="<?= tv('body_bg_image') ?>">
        <div class="cr"><span class="cr-lbl">URL вручну</span><div class="cr-ctrl">
          <input type="text" class="form-control form-control-sm cr-txt" value="<?= tv('body_bg_image') ?>" placeholder="/assets/images/..."
                 oninput="document.getElementById('bgImg').value=this.value">
        </div></div>
        <div class="cr"><span class="cr-lbl">Розмір</span><div class="cr-ctrl">
          <select name="body_bg_size" class="form-select form-select-sm">
            <?php foreach(['auto'=>'auto','cover'=>'cover (заповнити)','contain'=>'contain'] as $v=>$l): ?>
            <option value="<?=$v?>" <?=tr('body_bg_size')===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div></div>
        <div class="cr"><span class="cr-lbl">Повтор</span><div class="cr-ctrl">
          <select name="body_bg_repeat" class="form-select form-select-sm">
            <?php foreach(['repeat'=>'repeat','no-repeat'=>'no-repeat','repeat-x'=>'X','repeat-y'=>'Y'] as $v=>$l): ?>
            <option value="<?=$v?>" <?=tr('body_bg_repeat')===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div></div>
        <div class="cr"><span class="cr-lbl">Прокрутка</span><div class="cr-ctrl">
          <select name="body_bg_attachment" class="form-select form-select-sm">
            <option value="scroll" <?=(tr('body_bg_attachment')??'scroll')==='scroll'?'selected':''?>>scroll (звичайний)</option>
            <option value="fixed"  <?=(tr('body_bg_attachment')??'scroll')==='fixed'?'selected':''?>>fixed (parallax)</option>
          </select>
        </div></div>
      </div>
    </div>

    <!-- ТИПОГРАФІКА -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>✍️ Типографіка</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="fi-row"><label class="fi-lbl">Font family</label>
          <input name="font_family" id="ffInput" class="form-control form-control-sm" value="<?= tv('font_family') ?>"></div>
        <div class="d-flex flex-wrap gap-1 mb-3">
          <?php foreach([
            'system-ui,-apple-system,sans-serif'=>'System','\'Georgia\',serif'=>'Georgia',
            '\'Tahoma\',sans-serif'=>'Tahoma','\'Trebuchet MS\',sans-serif'=>'Trebuchet',
            '\'Arial\',sans-serif'=>'Arial','\'Courier New\',monospace'=>'Mono',
            '\'Times New Roman\',serif'=>'Times',
          ] as $fv=>$fl): ?>
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                  style="font-family:<?=$fv?>;font-size:.68rem"
                  onclick="document.getElementById('ffInput').value='<?=addslashes($fv)?>'">
            <?=$fl?></button>
          <?php endforeach; ?>
        </div>
        <div class="rr"><span class="rr-lbl">Розмір (base)</span>
          <input type="range" name="font_size_base" class="form-range flex-grow-1" min="12" max="22" step="1" value="<?= tv('font_size_base') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('font_size_base') ?>px</span>
        </div>
        <div class="cr"><span class="cr-lbl">Текст</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('text_color') ?>" oninput="syncC(this,'text_color')">
          <input type="text" name="text_color" id="text_color" class="form-control form-control-sm cr-txt" value="<?= tv('text_color') ?>" oninput="syncT(this,'text_color')">
        </div></div>
        <div class="cr"><span class="cr-lbl">Заголовки</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('heading_color') ?>" oninput="syncC(this,'heading_color')">
          <input type="text" name="heading_color" id="heading_color" class="form-control form-control-sm cr-txt" value="<?= tv('heading_color') ?>" oninput="syncT(this,'heading_color')">
        </div></div>
        <div class="cr"><span class="cr-lbl">Посилання</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('link_color') ?>" oninput="syncC(this,'link_color')">
          <input type="text" name="link_color" id="link_color" class="form-control form-control-sm cr-txt" value="<?= tv('link_color') ?>" oninput="syncT(this,'link_color')">
        </div></div>
      </div>
    </div>

    <!-- АКЦЕНТИ -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🎨 Акцент і кнопки</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="cr"><span class="cr-lbl">Акцент</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('accent_color') ?>" oninput="syncC(this,'accent_color')">
          <input type="text" name="accent_color" id="accent_color" class="form-control form-control-sm cr-txt" value="<?= tv('accent_color') ?>" oninput="syncT(this,'accent_color')">
        </div></div>
        <div class="cr"><span class="cr-lbl">Hover</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('accent_hover') ?>" oninput="syncC(this,'accent_hover')">
          <input type="text" name="accent_hover" id="accent_hover" class="form-control form-control-sm cr-txt" value="<?= tv('accent_hover') ?>" oninput="syncT(this,'accent_hover')">
        </div></div>
        <div class="rr"><span class="rr-lbl">Заокруглення кнопок</span>
          <input type="range" name="btn_radius" class="form-range flex-grow-1" min="0" max="30" step="1" value="<?= tv('btn_radius') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('btn_radius') ?>px</span>
        </div>
      </div>
    </div>

    <!-- МАКЕТ -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>📐 Макет і відступи</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="rr"><span class="rr-lbl">Макс. ширина</span>
          <input type="range" name="container_max_width" class="form-range flex-grow-1" min="768" max="1920" step="20" value="<?= tv('container_max_width') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('container_max_width') ?>px</span>
        </div>
        <div class="rr"><span class="rr-lbl">Відступ зверху</span>
          <input type="range" name="content_padding_top" class="form-range flex-grow-1" min="0" max="120" step="4" value="<?= tv('content_padding_top') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('content_padding_top') ?>px</span>
        </div>
        <div class="rr"><span class="rr-lbl">Відступ знизу</span>
          <input type="range" name="content_padding" class="form-range flex-grow-1" min="0" max="120" step="4" value="<?= tv('content_padding') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('content_padding') ?>px</span>
        </div>
      </div>
    </div>

    <!-- КАРТКИ -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🃏 Картки</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="cr"><span class="cr-lbl">Фон карток</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('card_bg') ?>" oninput="syncC(this,'card_bg')">
          <input type="text" name="card_bg" id="card_bg" class="form-control form-control-sm cr-txt" value="<?= tv('card_bg') ?>" oninput="syncT(this,'card_bg')">
        </div></div>
        <div class="rr"><span class="rr-lbl">Заокруглення</span>
          <input type="range" name="card_radius" class="form-range flex-grow-1" min="0" max="28" step="1" value="<?= tv('card_radius') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('card_radius') ?>px</span>
        </div>

        <!-- ── ТІНЬ ───────────────────────────────────────────────── -->
        <div style="border-top:1px solid #e8eaed;margin:10px 0 8px;padding-top:8px">
          <span style="font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#666">Тінь карток</span>
        </div>

        <!-- Пресет тіні -->
        <div class="tr-row" style="flex-wrap:wrap;gap:4px 6px;align-items:center">
          <span class="tr-lbl" style="flex:0 0 100%;margin-bottom:4px">Пресет</span>
          <?php
          $shadowOpts = [
            'none'   => ['label'=>'Немає',   'preview'=>'none'],
            'subtle' => ['label'=>'Легка',   'preview'=>'0 1px 4px rgba(0,0,0,.06)'],
            'small'  => ['label'=>'Мала',    'preview'=>'0 2px 8px rgba(0,0,0,.08)'],
            'medium' => ['label'=>'Середня', 'preview'=>'0 4px 18px rgba(0,0,0,.10)'],
            'large'  => ['label'=>'Велика',  'preview'=>'0 8px 32px rgba(0,0,0,.14)'],
            'xl'     => ['label'=>'XL',      'preview'=>'0 16px 48px rgba(0,0,0,.18)'],
            'custom' => ['label'=>'Власна',  'preview'=>''],
          ];
          $curPreset = tr('card_shadow_preset');
          foreach($shadowOpts as $k => $o):
          ?>
          <label class="shadow-preset-label <?= $curPreset===$k?'active':'' ?>" data-preset="<?= $k ?>" style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px">
            <input type="radio" name="card_shadow_preset" value="<?= $k ?>"
                   <?= $curPreset===$k?'checked':'' ?>
                   onchange="onShadowPresetChange(this.value)"
                   style="display:none">
            <span class="shadow-swatch"
                  style="width:42px;height:28px;background:#fff;border-radius:6px;display:block;border:2px solid <?= $curPreset===$k?'#0d6efd':'#dee2e6' ?>;box-shadow:<?= $o['preview'] ?: 'none' ?>">
            </span>
            <span style="font-size:.65rem;color:#555"><?= $o['label'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <!-- Власна тінь -->
        <div id="customShadowWrap" style="display:<?= $curPreset==='custom'?'block':'none' ?>;margin-top:8px;background:#f8f9fa;border-radius:6px;padding:10px">
          <div style="font-size:.72rem;font-weight:600;color:#555;margin-bottom:6px">Власні параметри тіні</div>
          <div class="rr"><span class="rr-lbl" style="min-width:80px">Зміщ. X</span>
            <input type="range" name="card_shadow_x" class="form-range flex-grow-1" min="-20" max="20" step="1"
                   value="<?= tv('card_shadow_x') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';updateCustomShadowPreview()">
            <span class="rr-val"><?= tv('card_shadow_x') ?>px</span>
          </div>
          <div class="rr"><span class="rr-lbl" style="min-width:80px">Зміщ. Y</span>
            <input type="range" name="card_shadow_y" class="form-range flex-grow-1" min="-20" max="40" step="1"
                   value="<?= tv('card_shadow_y') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';updateCustomShadowPreview()">
            <span class="rr-val"><?= tv('card_shadow_y') ?>px</span>
          </div>
          <div class="rr"><span class="rr-lbl" style="min-width:80px">Розмиття</span>
            <input type="range" name="card_shadow_blur" class="form-range flex-grow-1" min="0" max="80" step="1"
                   value="<?= tv('card_shadow_blur') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';updateCustomShadowPreview()">
            <span class="rr-val"><?= tv('card_shadow_blur') ?>px</span>
          </div>
          <div class="rr"><span class="rr-lbl" style="min-width:80px">Розповсюдж.</span>
            <input type="range" name="card_shadow_spread" class="form-range flex-grow-1" min="-10" max="20" step="1"
                   value="<?= tv('card_shadow_spread') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';updateCustomShadowPreview()">
            <span class="rr-val"><?= tv('card_shadow_spread') ?>px</span>
          </div>
          <div class="cr"><span class="cr-lbl">Колір тіні</span><div class="cr-ctrl">
            <input type="color" class="cpick" value="<?= tv('card_shadow_color') === 'rgba(0,0,0,0.10)' ? '#000000' : '#000000' ?>" oninput="syncShadowColor(this)" id="shadowColorPick">
            <input type="text" name="card_shadow_color" id="card_shadow_color"
                   class="form-control form-control-sm cr-txt"
                   value="<?= tv('card_shadow_color') ?>"
                   oninput="selectShadowPreset('custom');updateCustomShadowPreview()">
            <span id="customShadowPreviewBox" style="width:32px;height:24px;border-radius:4px;background:#fff;display:inline-block;vertical-align:middle;border:1px solid #ddd;box-shadow:<?= tv('card_shadow_x') ?>px <?= tv('card_shadow_y') ?>px <?= tv('card_shadow_blur') ?>px <?= tv('card_shadow_spread') ?>px <?= tv('card_shadow_color') ?>"></span>
          </div></div>
        </div>

        <!-- Підйом при hover -->
        <div class="tr-row" style="margin-top:8px">
          <span class="tr-lbl">Підйом при наведенні</span>
          <label class="tg"><input type="checkbox" name="card_hover_lift" <?= tBool('card_hover_lift')?'checked':'' ?>><span class="tg-sl"></span></label>
        </div>
      </div>
    </div>

    <!-- ФУТЕР -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🔻 Підвал</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="cr"><span class="cr-lbl">Фон</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('footer_bg') ?>" oninput="syncC(this,'footer_bg');mpUpd()">
          <input type="text" name="footer_bg" id="footer_bg" class="form-control form-control-sm cr-txt" value="<?= tv('footer_bg') ?>" oninput="syncT(this,'footer_bg');mpUpd()">
        </div></div>
        <div class="cr"><span class="cr-lbl">Текст</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('footer_text_color') ?>" oninput="syncC(this,'footer_text_color');mpUpd()">
          <input type="text" name="footer_text_color" id="footer_text_color" class="form-control form-control-sm cr-txt" value="<?= tv('footer_text_color') ?>" oninput="syncT(this,'footer_text_color');mpUpd()">
        </div></div>
        <div class="rr"><span class="rr-lbl">Відступи</span>
          <input type="range" name="footer_padding" class="form-range flex-grow-1" min="4" max="80" step="2" value="<?= tv('footer_padding') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('footer_padding') ?>px</span>
        </div>
      </div>
    </div>

  </div>
    <div class="tb-save" id="save-design">
      <button type="submit" class="btn btn-primary">💾 Зберегти дизайн</button>
      <button type="submit" name="_action" value="reset_theme"
              class="btn btn-outline-secondary" style="flex:0 0 auto;padding:7px 11px"
              onclick="return confirm('Скинути тему до дефолтів?')" title="Скинути">↺</button>
    </div>
  </form>

  <!-- ══ ГОЛОВНА ════════════════════════════════════════════════════ -->
  <form method="POST" id="fHome">
  <input type="hidden" name="_action" value="save_theme">
  <input type="hidden" name="_tab"   value="homepage">
  <?php $homeKeys=['hero_enabled','hero_layout','hero_bg_color','hero_bg_image','hero_bg_overlay','hero_title','hero_subtitle',
    'hero_btn1_text','hero_btn1_url','hero_btn1_style','hero_btn2_text','hero_btn2_url','hero_btn2_style','hero_min_height','hero_text_color',
    'news_enabled','news_title','news_count','news_layout','news_cols','news_show_date','news_show_author','news_show_excerpt',
    'news_excerpt_length','news_show_thumb','news_thumb_height','news_show_readmore','news_readmore_text','news_category_filter','news_section_bg',
    'home_extra_block','home_extra_position','divider_style','divider_color','divider_height','posts_layout']; ?>
  <?php foreach($thDef as $k=>$d): if(!in_array($k,$homeKeys)): ?>
  <input type="hidden" name="<?= $k ?>" value="<?= tv($k) ?>">
  <?php endif; endforeach; ?>

  <div class="tb-pane <?= $activeTab==='homepage'?'active':'' ?>" id="pane-homepage">

    <!-- HERO -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🦸 Hero-банер</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="tr-row"><span class="tr-lbl">Увімкнено</span>
          <label class="tg"><input type="checkbox" name="hero_enabled" <?= tBool('hero_enabled')?'checked':'' ?> onchange="heroLivePrev()"><span class="tg-sl"></span></label>
        </div>

        <div class="sdiv">Розташування</div>
        <div class="vcs" id="heroLayoutPick">
          <?php foreach(['centered'=>['🎯','По центру'],'left'=>['⬅️','Ліво'],'fullscreen'=>['⛶','Повний']] as $v=>[$ic,$lb]): ?>
          <div class="vcs-opt <?= tr('hero_layout')===$v?'on':'' ?>" onclick="vcsSet(this,'hero_layout','<?=$v?>');heroLivePrev()">
            <span class="vcs-ic"><?=$ic?></span><span class="vcs-lbl"><?=$lb?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="hero_layout" id="hero_layout" value="<?= tv('hero_layout') ?>">

        <div class="sdiv">Фон</div>
        <div class="cr"><span class="cr-lbl">Колір фону</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('hero_bg_color') ?>" oninput="syncC(this,'hero_bg_color');heroLivePrev()">
          <input type="text" name="hero_bg_color" id="hero_bg_color" class="form-control form-control-sm cr-txt" value="<?= tv('hero_bg_color') ?>" oninput="syncT(this,'hero_bg_color');heroLivePrev()">
        </div></div>
        <div class="cr"><span class="cr-lbl">Фото (медіа)</span><div class="cr-ctrl">
          <select class="form-select form-select-sm" onchange="document.getElementById('heroBgImg').value=this.value?'/uploads/cms_img/'+this.value:'';heroLivePrev()">
            <option value="">— без фото —</option>
            <?php foreach($uploads as $f): $c=tr('hero_bg_image')==='/uploads/cms_img/'.$f?'selected':''; ?>
            <option value="<?=htmlspecialchars($f)?>" <?=$c?>><?=htmlspecialchars($f)?></option>
            <?php endforeach; ?>
          </select>
        </div></div>
        <input type="hidden" name="hero_bg_image" id="heroBgImg" value="<?= tv('hero_bg_image') ?>">
        <div class="cr"><span class="cr-lbl">Колір тексту</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('hero_text_color') ?>" oninput="syncC(this,'hero_text_color');heroLivePrev()">
          <input type="text" name="hero_text_color" id="hero_text_color" class="form-control form-control-sm cr-txt" value="<?= tv('hero_text_color') ?>" oninput="syncT(this,'hero_text_color');heroLivePrev()">
        </div></div>
        <div class="rr"><span class="rr-lbl">Затемнення</span>
          <input type="range" name="hero_bg_overlay" id="heroOvRange" class="form-range flex-grow-1" min="0" max="90" step="5" value="<?= round((float)tr('hero_bg_overlay')*100) ?>" oninput="this.nextElementSibling.textContent=this.value+'%';heroLivePrev()">
          <span class="rr-val"><?= round((float)tr('hero_bg_overlay')*100) ?>%</span>
        </div>
        <div class="rr"><span class="rr-lbl">Мін. висота</span>
          <input type="range" name="hero_min_height" id="heroHRange" class="form-range flex-grow-1" min="150" max="700" step="10" value="<?= tv('hero_min_height') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';heroLivePrev()">
          <span class="rr-val"><?= tv('hero_min_height') ?>px</span>
        </div>

        <div class="sdiv">Текст</div>
        <div class="fi-row"><label class="fi-lbl">Заголовок (порожньо = назва сайту)</label>
          <input name="hero_title" id="heroTitleInput" class="form-control form-control-sm" value="<?= tv('hero_title') ?>" oninput="heroLivePrev()"></div>
        <div class="fi-row"><label class="fi-lbl">Підзаголовок</label>
          <textarea name="hero_subtitle" id="heroSubInput" class="form-control form-control-sm" rows="2" oninput="heroLivePrev()"><?= tv('hero_subtitle') ?></textarea></div>

        <div class="sdiv">Кнопки</div>
        <div class="row g-2 mb-2">
          <div class="col-5"><label class="fi-lbl">Кнопка 1 — текст</label>
            <input name="hero_btn1_text" id="heroBtn1Text" class="form-control form-control-sm" value="<?= tv('hero_btn1_text') ?>" oninput="heroLivePrev()"></div>
          <div class="col-4"><label class="fi-lbl">URL</label>
            <input name="hero_btn1_url" class="form-control form-control-sm" value="<?= tv('hero_btn1_url') ?>"></div>
          <div class="col-3"><label class="fi-lbl">Стиль</label>
            <select name="hero_btn1_style" id="heroBtn1Style" class="form-select form-select-sm" onchange="heroLivePrev()">
              <?php foreach(['primary','secondary','light','dark','outline-light','outline-primary'] as $bs): ?>
              <option value="<?=$bs?>" <?=tr('hero_btn1_style')===$bs?'selected':''?>><?=$bs?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="row g-2">
          <div class="col-5"><label class="fi-lbl">Кнопка 2 (порожньо = сховати)</label>
            <input name="hero_btn2_text" id="heroBtn2Text" class="form-control form-control-sm" value="<?= tv('hero_btn2_text') ?>" oninput="heroLivePrev()"></div>
          <div class="col-4"><label class="fi-lbl">URL</label>
            <input name="hero_btn2_url" class="form-control form-control-sm" value="<?= tv('hero_btn2_url') ?>"></div>
          <div class="col-3"><label class="fi-lbl">Стиль</label>
            <select name="hero_btn2_style" id="heroBtn2Style" class="form-select form-select-sm" onchange="heroLivePrev()">
              <?php foreach(['outline-light','outline-primary','secondary','light'] as $bs): ?>
              <option value="<?=$bs?>" <?=tr('hero_btn2_style')===$bs?'selected':''?>><?=$bs?></option>
              <?php endforeach; ?>
            </select></div>
        </div>

        <!-- LIVE MINI HERO PREVIEW -->
        <div class="sdiv">Попередній перегляд Hero</div>
        <div id="heroPreviewBox" class="hp-hero" style="min-height:<?= tv('hero_min_height') ?>px;max-height:160px">
          <div id="hpBg" class="fly-hero-bg hp-hero-bg" style="background:<?= tv('hero_bg_color') ?><?= tr('hero_bg_image')?' url(\''.tv('hero_bg_image').'\') center/cover':'' ?>"></div>
          <div id="hpOv" class="hp-hero-ov" style="background:rgba(0,0,0,<?= round((float)tr('hero_bg_overlay')*100)/100 ?>)"></div>
          <div id="hpTxt" class="hp-hero-txt" style="color:<?= tv('hero_text_color') ?>">
            <div id="hpTitle" class="hp-hero-title"><?= tv('hero_title')?:gs('site_title') ?></div>
            <div id="hpSub" class="hp-hero-sub"><?= tv('hero_subtitle') ?></div>
            <div class="hp-hero-btns" id="hpBtns">
              <?php if(tr('hero_btn1_text')): ?>
              <span id="hpBtn1" class="hp-btn btn-<?= tv('hero_btn1_style') ?>"><?= tv('hero_btn1_text') ?></span>
              <?php endif; ?>
              <?php if(tr('hero_btn2_text')): ?>
              <span id="hpBtn2" class="hp-btn btn-<?= tv('hero_btn2_style') ?>"><?= tv('hero_btn2_text') ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- СЕКЦІЯ НОВИН -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>📰 Секція новин</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="tr-row"><span class="tr-lbl">Увімкнено</span>
          <label class="tg"><input type="checkbox" name="news_enabled" <?= tBool('news_enabled')?'checked':'' ?> onchange="newsLivePrev()"><span class="tg-sl"></span></label>
        </div>
        <div class="fi-row"><label class="fi-lbl">Заголовок секції</label>
          <input name="news_title" id="newsTitleInp" class="form-control form-control-sm" value="<?= tv('news_title') ?>" oninput="newsLivePrev()"></div>
        <div class="cr"><span class="cr-lbl">Фон секції</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('news_section_bg') ?>" oninput="syncC(this,'news_section_bg');newsLivePrev()">
          <input type="text" name="news_section_bg" id="news_section_bg" class="form-control form-control-sm cr-txt" value="<?= tv('news_section_bg') ?>" oninput="syncT(this,'news_section_bg');newsLivePrev()">
        </div></div>

        <div class="sdiv">Тип відображення</div>
        <div class="layout-pick" id="newsLayoutPick">
          <div class="lp-opt <?= tr('news_layout')==='grid'?'on':'' ?>" onclick="lSet(this,'news_layout','grid');newsLivePrev()">
            <div class="lp-grid"><div class="lp-block"></div><div class="lp-block"></div><div class="lp-block"></div></div>
            <div class="lp-lbl">🔲 Сітка</div>
          </div>
          <div class="lp-opt <?= tr('news_layout')==='list'?'on':'' ?>" onclick="lSet(this,'news_layout','list');newsLivePrev()">
            <div class="lp-list-items"><div class="lp-list-block"></div><div class="lp-list-block"></div><div class="lp-list-block"></div></div>
            <div class="lp-lbl">📋 Список</div>
          </div>
          <div class="lp-opt <?= tr('news_layout')==='masonry'?'on':'' ?>" onclick="lSet(this,'news_layout','masonry');newsLivePrev()">
            <div class="lp-grid" style="grid-template-rows:auto auto;"><div class="lp-block" style="height:30px"></div><div class="lp-block" style="height:18px"></div><div class="lp-block" style="height:24px"></div></div>
            <div class="lp-lbl">🧱 Masonry</div>
          </div>
        </div>
        <input type="hidden" name="news_layout" id="news_layout" value="<?= tv('news_layout') ?>">

        <div class="sdiv">Кількість колонок</div>
        <div class="cols-pick" id="newsColsPick">
          <?php foreach([2,3,4] as $nc): ?>
          <div class="cols-opt <?= (int)tr('news_cols')===$nc?'on':'' ?>" onclick="colsSet(this,'news_cols',<?=$nc?>);newsLivePrev()">
            <div class="cols-grid" style="grid-template-columns:<?= implode(' ',array_fill(0,$nc,'1fr')) ?>">
              <?php for($i=0;$i<$nc;$i++) echo '<div class="cols-block"></div>'; ?>
            </div>
            <div class="cols-num"><?=$nc?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="news_cols" id="news_cols" value="<?= tv('news_cols') ?>">

        <div class="rr"><span class="rr-lbl">Кількість записів</span>
          <input type="range" name="news_count" class="form-range flex-grow-1" min="1" max="24" step="1" value="<?= tv('news_count') ?>" oninput="this.nextElementSibling.textContent=this.value;newsLivePrev()">
          <span class="rr-val"><?= tv('news_count') ?></span>
        </div>
        <div class="rr"><span class="rr-lbl">Висота мініатюри</span>
          <input type="range" name="news_thumb_height" class="form-range flex-grow-1" min="80" max="500" step="10" value="<?= tv('news_thumb_height') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';newsLivePrev()">
          <span class="rr-val"><?= tv('news_thumb_height') ?>px</span>
        </div>
        <div class="rr"><span class="rr-lbl">Довжина уривку</span>
          <input type="range" name="news_excerpt_length" class="form-range flex-grow-1" min="40" max="400" step="10" value="<?= tv('news_excerpt_length') ?>" oninput="this.nextElementSibling.textContent=this.value+' сим'">
          <span class="rr-val"><?= tv('news_excerpt_length') ?> сим</span>
        </div>

        <div class="sdiv">Елементи картки</div>
        <?php foreach(['news_show_thumb'=>'🖼 Мініатюра','news_show_date'=>'📅 Дата',
                        'news_show_author'=>'👤 Автор','news_show_excerpt'=>'📝 Уривок',
                        'news_show_readmore'=>'🔗 Кнопка "читати"'] as $k=>$l): ?>
        <div class="tr-row"><span class="tr-lbl"><?=$l?></span>
          <label class="tg"><input type="checkbox" name="<?=$k?>" <?=tBool($k)?'checked':''?> onchange="newsLivePrev()"><span class="tg-sl"></span></label>
        </div>
        <?php endforeach; ?>

        <div class="fi-row"><label class="fi-lbl">Текст кнопки</label>
          <input name="news_readmore_text" class="form-control form-control-sm" value="<?= tv('news_readmore_text') ?>"></div>

        <?php if(!empty($cats)): ?>
        <div class="fi-row"><label class="fi-lbl">Фільтр категорії</label>
          <select name="news_category_filter" class="form-select form-select-sm">
            <option value="">— Всі категорії —</option>
            <?php foreach($cats as $cat): ?>
            <option value="<?=$cat['id']?>" <?=tr('news_category_filter')==(string)$cat['id']?'selected':''?>><?=htmlspecialchars($cat['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <!-- LIVE MINI NEWS PREVIEW -->
        <div class="sdiv">Попередній перегляд</div>
        <div id="newsPreviewBox" class="np-section" style="background:<?= tv('news_section_bg') ?>">
          <div id="npTitle" class="np-title"><?= tv('news_title') ?></div>
          <div id="npGrid" class="np-grid" style="grid-template-columns:<?= implode(' ',array_fill(0,(int)tr('news_cols'),'1fr')) ?>">
            <?php for($i=0;$i<min((int)tr('news_cols'),6);$i++): ?>
            <div class="np-item">
              <div class="np-thumb" id="npThumb<?=$i?>" style="height:<?= round((int)tr('news_thumb_height')/5) ?>px">📰</div>
              <div class="np-body">
                <div class="np-post-title">Назва запису <?=$i+1?></div>
                <div class="np-meta" id="npMeta<?=$i?>">📅 01.01.2026 · 👤 Автор</div>
                <div class="np-excerpt" id="npExc<?=$i?>">Короткий опис запису...</div>
              </div>
            </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- РОЗДІЛЮВАЧ КОНТЕНТ / НОВИНИ -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>〰️ Розділювач контент → новини</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <p class="text-muted small mb-2">Показується між блоком основного контенту та секцією новин, лише коли обидва заповнені.</p>

        <!-- Вибір стилю -->
        <div class="fi-row"><label class="fi-lbl">Стиль</label>
          <select name="divider_style" id="divider_style_sel" class="form-select form-select-sm" onchange="updateDividerPreview()">
            <option value="wave"        <?=tr('divider_style')==='wave'       ?'selected':''?>>〰️ Хвиля</option>
            <option value="slope"       <?=tr('divider_style')==='slope'      ?'selected':''?>>📐 Нахил</option>
            <option value="triangle"    <?=tr('divider_style')==='triangle'   ?'selected':''?>>▲ Трикутник</option>
            <option value="arc"         <?=tr('divider_style')==='arc'        ?'selected':''?>>🌙 Дуга</option>
            <option value="zigzag"      <?=tr('divider_style')==='zigzag'     ?'selected':''?>>⚡ Зигзаг</option>
            <option value="line"        <?=tr('divider_style')==='line'       ?'selected':''?>>— Лінія</option>
            <option value="fade"        <?=tr('divider_style')==='fade'       ?'selected':''?>>🌫 Розмивання</option>
            <option value="double_line" <?=tr('divider_style')==='double_line'?'selected':''?>>═ Подвійна лінія</option>
          </select>
        </div>

        <!-- Висота -->
        <div class="rr"><span class="rr-lbl">Висота</span>
          <input type="range" name="divider_height" id="divider_height_r" class="form-range flex-grow-1" min="20" max="120" step="4"
                 value="<?= tv('divider_height') ?>" oninput="this.nextElementSibling.textContent=this.value+'px';updateDividerPreview()">
          <span class="rr-val"><?= tv('divider_height') ?>px</span>
        </div>

        <!-- Колір -->
        <div class="cr"><span class="cr-lbl">Колір</span><div class="cr-ctrl">
          <input type="color" class="cpick" value="<?= tv('divider_color') ?: '#f8f9fa' ?>" oninput="syncC(this,'divider_color');updateDividerPreview()">
          <input type="text" name="divider_color" id="divider_color" class="form-control form-control-sm cr-txt"
                 value="<?= tv('divider_color') ?>" placeholder="auto (із фону новин)"
                 oninput="syncT(this,'divider_color');updateDividerPreview()">
        </div></div>

        <!-- Превью -->
        <div style="margin-top:10px;border-radius:8px;overflow:hidden;border:1px solid #dee2e6;background:#fff">
          <div style="height:40px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;color:#aaa">Контент</div>
          <div id="dividerPreview" style="line-height:0"></div>
          <div id="dividerPreviewBg" style="height:40px;display:flex;align-items:center;justify-content:center;font-size:.75rem;color:#aaa">Новини</div>
        </div>
      </div>
    </div>

    <!-- EXTRA BLOCK -->
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>➕ Додатковий HTML-блок</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="fi-row"><label class="fi-lbl">Позиція</label>
          <select name="home_extra_position" class="form-select form-select-sm">
            <option value="none" <?=tr('home_extra_position')==='none'?'selected':''?>>Приховати</option>
            <option value="before_news" <?=tr('home_extra_position')==='before_news'?'selected':''?>>Перед новинами</option>
            <option value="after_news" <?=tr('home_extra_position')==='after_news'?'selected':''?>>Після новин</option>
          </select></div>
        <textarea name="home_extra_block" class="form-control font-monospace" rows="5" style="font-size:.75rem" placeholder="<section>...</section>"><?= tv('home_extra_block') ?></textarea>
      </div>
    </div>

  </div>

    <div class="tb-save" id="save-homepage">
      <button type="submit" class="btn btn-primary">💾 Зберегти головну</button>
    </div>
  </form>

  <!-- ══ ЗАПИСИ ════════════════════════════════════════════════════ -->
  <form method="POST" id="fPost">
  <input type="hidden" name="_action" value="save_theme">
  <input type="hidden" name="_tab"   value="postpage">
  <?php $postKeys=['posts_layout','post_show_hero','post_hero_height','post_show_meta','post_meta_position',
    'post_show_author_box','post_show_related','post_related_count','post_content_width',
    'post_sidebar_enabled','post_sidebar_position','post_breadcrumbs','post_show_share','post_toc_enabled']; ?>
  <?php foreach($thDef as $k=>$d): if(!in_array($k,$postKeys)): ?>
  <input type="hidden" name="<?= $k ?>" value="<?= tv($k) ?>">
  <?php endif; endforeach; ?>

  <div class="tb-pane <?= $activeTab==='postpage'?'active':'' ?>" id="pane-postpage">

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>📋 Лейаут записів на головній</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="layout-pick">
          <div class="lp-opt <?= tr('posts_layout')==='grid'?'on':'' ?>" onclick="lSet(this,'posts_layout','grid')">
            <div class="lp-grid"><div class="lp-block"></div><div class="lp-block"></div><div class="lp-block"></div></div>
            <div class="lp-lbl">🔲 Сітка</div>
          </div>
          <div class="lp-opt <?= tr('posts_layout')==='list'?'on':'' ?>" onclick="lSet(this,'posts_layout','list')">
            <div class="lp-list-items"><div class="lp-list-block"></div><div class="lp-list-block"></div><div class="lp-list-block"></div></div>
            <div class="lp-lbl">📋 Список</div>
          </div>
        </div>
        <input type="hidden" name="posts_layout" id="posts_layout" value="<?= tv('posts_layout') ?>">
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🖼 Hero-зображення запису</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="tr-row"><span class="tr-lbl">Показувати</span>
          <label class="tg"><input type="checkbox" name="post_show_hero" <?= tBool('post_show_hero')?'checked':'' ?>><span class="tg-sl"></span></label>
        </div>
        <div class="rr"><span class="rr-lbl">Висота</span>
          <input type="range" name="post_hero_height" class="form-range flex-grow-1" min="150" max="700" step="10" value="<?= tv('post_hero_height') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('post_hero_height') ?>px</span>
        </div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>📐 Ширина контенту запису</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="rr"><span class="rr-lbl">Макс. ширина</span>
          <input type="range" name="post_content_width" class="form-range flex-grow-1" min="480" max="1200" step="20" value="<?= tv('post_content_width') ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
          <span class="rr-val"><?= tv('post_content_width') ?>px</span>
        </div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>🏷 Мета і навігація</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <div class="tr-row"><span class="tr-lbl">Мета (дата/автор)</span>
          <label class="tg"><input type="checkbox" name="post_show_meta" <?= tBool('post_show_meta')?'checked':'' ?>><span class="tg-sl"></span></label>
        </div>
        <div class="sdiv">Позиція мети</div>
        <div class="vcs">
          <div class="vcs-opt <?= tr('post_meta_position')==='top'?'on':'' ?>" onclick="vcsSet(this,'post_meta_position','top')">
            <span class="vcs-ic">⬆️</span><span class="vcs-lbl">Зверху</span>
          </div>
          <div class="vcs-opt <?= tr('post_meta_position')==='bottom'?'on':'' ?>" onclick="vcsSet(this,'post_meta_position','bottom')">
            <span class="vcs-ic">⬇️</span><span class="vcs-lbl">Знизу</span>
          </div>
        </div>
        <input type="hidden" name="post_meta_position" id="post_meta_position" value="<?= tv('post_meta_position') ?>">
        <div class="tr-row"><span class="tr-lbl">Хлібні крихти</span>
          <label class="tg"><input type="checkbox" name="post_breadcrumbs" <?= tBool('post_breadcrumbs')?'checked':'' ?>><span class="tg-sl"></span></label>
        </div>
      </div>
    </div>

    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>⚙️ Додаткові елементи</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <?php foreach([
          'post_show_author_box'=>'👤 Блок автора',
          'post_show_related'  =>'📰 Схожі записи',
          'post_show_share'    =>'↗️ Кнопки Share',
          'post_toc_enabled'   =>'📑 Зміст (TOC)',
          'post_sidebar_enabled'=>'🗂 Бічна панель',
        ] as $pk=>$pl): ?>
        <div class="tr-row"><span class="tr-lbl"><?=$pl?></span>
          <label class="tg"><input type="checkbox" name="<?=$pk?>" <?=tBool($pk)?'checked':''?>><span class="tg-sl"></span></label>
        </div>
        <?php endforeach; ?>
        <div class="rr"><span class="rr-lbl">Кількість схожих</span>
          <input type="range" name="post_related_count" class="form-range flex-grow-1" min="1" max="6" step="1" value="<?= tv('post_related_count') ?>" oninput="this.nextElementSibling.textContent=this.value">
          <span class="rr-val"><?= tv('post_related_count') ?></span>
        </div>
        <div class="sdiv">Позиція сайдбару</div>
        <div class="vcs">
          <div class="vcs-opt <?= tr('post_sidebar_position')==='left'?'on':'' ?>" onclick="vcsSet(this,'post_sidebar_position','left')">
            <span class="vcs-ic">⬅️</span><span class="vcs-lbl">Ліво</span>
          </div>
          <div class="vcs-opt <?= tr('post_sidebar_position')==='right'?'on':'' ?>" onclick="vcsSet(this,'post_sidebar_position','right')">
            <span class="vcs-ic">➡️</span><span class="vcs-lbl">Право</span>
          </div>
        </div>
        <input type="hidden" name="post_sidebar_position" id="post_sidebar_position" value="<?= tv('post_sidebar_position') ?>">
      </div>
    </div>

  </div>

    <div class="tb-save" id="save-postpage">
      <button type="submit" class="btn btn-primary">💾 Зберегти записи</button>
    </div>
  </form>

  <!-- ══ CUSTOM CSS ════════════════════════════════════════════════ -->
  <form method="POST" id="fCss">
  <input type="hidden" name="_action" value="save_theme">
  <input type="hidden" name="_tab"   value="css">
  <?php foreach($thDef as $k=>$d): if($k!=='custom_css'): ?>
  <input type="hidden" name="<?=$k?>" value="<?= tv($k) ?>">
  <?php endif; endforeach; ?>

  <div class="tb-pane <?= $activeTab==='css'?'active':'' ?>" id="pane-css">
    <div class="tb-card">
      <div class="tb-card-h" onclick="tcToggle(this)"><span>💻 Власний CSS</span><span class="tb-caret">▾</span></div>
      <div class="tb-card-b">
        <p class="text-muted mb-2" style="font-size:.77rem">Підключається після <code>theme.css</code>. Перевизначає будь-які стилі теми.</p>
        <textarea name="custom_css" class="form-control font-monospace" rows="22" style="font-size:.76rem;resize:vertical" placeholder="/* Ваш CSS */&#10;.my-class { color: red; }"><?= tv('custom_css') ?></textarea>
        <?php $cf=__DIR__.'/../assets/css/theme.css'; if(file_exists($cf)): ?>
        <div class="text-muted mt-2" style="font-size:.7rem">📄 theme.css — <?=round(filesize($cf)/1024,1)?> KB, оновлено <?=date('d.m H:i',filemtime($cf))?></div>
        <?php else: ?>
        <div class="text-warning mt-2" style="font-size:.7rem">⚠️ theme.css не згенеровано — збережіть будь-яку вкладку</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

    <div class="tb-save" id="save-css">
      <button type="submit" class="btn btn-primary">💾 Зберегти CSS</button>
    </div>
  </form>

</div><!-- /tb-controls -->

<!-- ═══════════════ PREVIEW IFRAME ════════════════════════════════ -->
<div class="tb-preview">
  <div class="pv-bar">
    <h6>👁 Живий перегляд</h6>
    <div class="pv-devs">
      <button class="on" onclick="pvDev('',this)">🖥 Desktop</button>
      <button onclick="pvDev('tab',this)">📱 768px</button>
      <button onclick="pvDev('mob',this)">📲 375px</button>
    </div>
    <button onclick="pvRel()" style="background:rgba(255,255,255,.1);border:none;color:#94a3b8;padding:4px 9px;border-radius:4px;font-size:.7rem;margin-left:6px;cursor:pointer" title="Оновити">↺ Оновити</button>
    <a href="/" target="_blank" style="background:rgba(255,255,255,.1);color:#94a3b8;padding:4px 9px;border-radius:4px;font-size:.7rem;text-decoration:none;margin-left:4px">↗ Сайт</a>
  </div>
  <div class="pv-wrap">
    <iframe id="pvIframe" src="/"></iframe>
  </div>
</div>

</div><!-- /tb-layout -->

<script>
// ── Tabs ─────────────────────────────────────────────────────────────
function toTab(btn, id) {
    document.querySelectorAll('.tb-tab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tb-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tb-controls > form').forEach(f=>f.classList.remove('active-form'));
    btn.classList.add('active');
    var pane = document.getElementById('pane-'+id);
    if(pane) {
        pane.classList.add('active');
        var form = pane.closest('form');
        if(form) form.classList.add('active-form');
    }
}

// ── Init active form on load ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var activePane = document.querySelector('.tb-pane.active');
    if(activePane) {
        var form = activePane.closest('form');
        if(form) form.classList.add('active-form');
    }
});

// ── Accordion ────────────────────────────────────────────────────────
function tcToggle(h) {
    h.classList.toggle('closed');
    var b = h.nextElementSibling;
    b.style.display = b.style.display === 'none' ? '' : 'none';
}

// ── Color sync picker ↔ text ─────────────────────────────────────────
function syncC(ci, id) {
    var el = document.getElementById(id); if(el) el.value = ci.value; mpUpd();
}
function syncT(ti, id) {
    var row = ti.closest('.cr-ctrl'); if(!row) return;
    var ci = row.querySelector('input[type=color]');
    if(ci && /^#[0-9a-fA-F]{6}$/.test(ti.value)) ci.value = ti.value;
    mpUpd();
}

// ── Visual selector helpers ──────────────────────────────────────────
function vcsSet(el, hid, val) {
    el.closest('.vcs').querySelectorAll('.vcs-opt').forEach(o=>o.classList.remove('on'));
    el.classList.add('on');
    var h=document.getElementById(hid); if(h) h.value=val;
}
function lSet(el, hid, val) {
    el.closest('.layout-pick').querySelectorAll('.lp-opt').forEach(o=>o.classList.remove('on'));
    el.classList.add('on');
    var h=document.getElementById(hid); if(h) h.value=val;
}
function colsSet(el, hid, val) {
    el.closest('.cols-pick').querySelectorAll('.cols-opt').forEach(o=>o.classList.remove('on'));
    el.classList.add('on');
    var h=document.getElementById(hid); if(h) h.value=String(val);
}

// ── Mini navbar/footer preview ───────────────────────────────────────
function mpUpd() {
    var nb=document.getElementById('mpNavbar'), br=document.getElementById('mpBrand'),
        ft=document.getElementById('mpFooter');
    if(nb){
        var bg=document.getElementById('navbar_bg')?.value;
        var tc=document.getElementById('navbar_text_color')?.value;
        if(bg) nb.style.background=bg; if(tc) nb.style.color=tc;
    }
    if(br){ var bc=document.getElementById('navbar_brand_color')?.value; if(bc) br.style.color=bc; }
    if(ft){
        var fb=document.getElementById('footer_bg')?.value;
        var fc=document.getElementById('footer_text_color')?.value;
        if(fb) ft.style.background=fb; if(fc) ft.style.color=fc;
    }
}

// ── Hero mini preview ─────────────────────────────────────────────────
function heroLivePrev() {
    var bg   = document.getElementById('hero_bg_color')?.value || '#1a1a2e';
    var img  = document.getElementById('heroBgImg')?.value || '';
    var ov   = (document.getElementById('heroOvRange')?.value || 50) / 100;
    var tc   = document.getElementById('hero_text_color')?.value || '#fff';
    var h    = document.getElementById('heroHRange')?.value || 400;
    var title= document.getElementById('heroTitleInput')?.value || '';
    var sub  = document.getElementById('heroSubInput')?.value || '';
    var b1   = document.getElementById('heroBtn1Text')?.value || '';
    var b1s  = document.getElementById('heroBtn1Style')?.value || 'primary';
    var b2   = document.getElementById('heroBtn2Text')?.value || '';
    var b2s  = document.getElementById('heroBtn2Style')?.value || 'outline-light';

    var hpBg=document.getElementById('hpBg'), hpOv=document.getElementById('hpOv'),
        hpTxt=document.getElementById('hpTxt'), hpBox=document.getElementById('heroPreviewBox'),
        hpTitle=document.getElementById('hpTitle'), hpSub=document.getElementById('hpSub'),
        hpBtns=document.getElementById('hpBtns');
    if(hpBg) hpBg.style.background = bg + (img ? ' url(\''+img+'\') center/cover' : '');
    if(hpOv) hpOv.style.background = 'rgba(0,0,0,'+ov+')';
    if(hpTxt) hpTxt.style.color = tc;
    if(hpBox) hpBox.style.minHeight = Math.min(parseInt(h), 160) + 'px';
    if(hpTitle) hpTitle.textContent = title || '<?= addslashes(gs('site_title')?:htmlspecialchars('Назва сайту')) ?>';
    if(hpSub) hpSub.textContent = sub;
    if(hpBtns) {
        hpBtns.innerHTML = '';
        if(b1) { var s1=document.createElement('span'); s1.className='hp-btn btn-'+b1s; s1.textContent=b1; hpBtns.appendChild(s1); }
        if(b2) { var s2=document.createElement('span'); s2.className='hp-btn btn-'+b2s; s2.textContent=b2; hpBtns.appendChild(s2); }
    }
}

// ── News mini preview ─────────────────────────────────────────────────
function newsLivePrev() {
    var cols   = parseInt(document.getElementById('news_cols')?.value || 3);
    var bg     = document.getElementById('news_section_bg')?.value || '#f8f9fa';
    var title  = document.getElementById('newsTitleInp')?.value || 'Новини';
    var thH    = document.getElementById('pane-homepage')?.querySelector('[name=news_thumb_height]')?.value || 200;
    var layout = document.getElementById('news_layout')?.value || 'grid';
    var showThumb = document.querySelector('[name=news_show_thumb]')?.checked;
    var showMeta  = document.querySelector('[name=news_show_date]')?.checked || document.querySelector('[name=news_show_author]')?.checked;
    var showExc   = document.querySelector('[name=news_show_excerpt]')?.checked;

    var box = document.getElementById('newsPreviewBox');
    var grid= document.getElementById('npGrid');
    var t   = document.getElementById('npTitle');
    if(!box||!grid) return;
    box.style.background = bg;
    if(t) t.textContent = title;

    if(layout==='list') {
        grid.style.gridTemplateColumns = '1fr';
    } else {
        grid.style.gridTemplateColumns = Array(cols).fill('1fr').join(' ');
    }

    // update thumbs height
    box.querySelectorAll('.np-thumb').forEach(el => {
        el.style.display = showThumb ? '' : 'none';
        el.style.height = Math.round(thH/5)+'px';
    });
    box.querySelectorAll('[id^=npMeta]').forEach(el => { el.style.display = showMeta?'':'none'; });
    box.querySelectorAll('[id^=npExc]').forEach(el => { el.style.display = showExc?'':'none'; });
}

// ── File previews ─────────────────────────────────────────────────────
function fiPrev(inp, id) {
    var f=inp.files[0]; if(!f) return;
    var r=new FileReader(); r.onload=e=>{
        var img=document.getElementById(id);
        if(!img){ img=document.createElement('img'); img.id=id; img.className='fi-thumb'; inp.closest('.fi-row').appendChild(img); }
        img.src=e.target.result;
    }; r.readAsDataURL(f);
}
function selPrev(sel, id) {
    var img=document.getElementById(id);
    if(img) img.src=sel.value?'/uploads/cms_img/'+sel.value+'?t='+Date.now():'';
}

// ── Preview iframe ────────────────────────────────────────────────────
function pvDev(cls, btn) {
    document.querySelectorAll('.pv-devs button').forEach(b=>b.classList.remove('on'));
    btn.classList.add('on');
    var f=document.getElementById('pvIframe'); f.className=cls;
}
var pvT=null;
function pvRel() {
    clearTimeout(pvT);
    pvT=setTimeout(()=>{ var f=document.getElementById('pvIframe'); try{f.contentWindow.location.reload();}catch(e){f.src=f.src;} },300);
}
// Auto reload after save
<?php if($msg): ?>setTimeout(pvRel,800);<?php endif; ?>

// Reload on color change (debounced)
var pvColorT=null;
document.querySelectorAll('input[type=color],input[type=range]').forEach(el=>{
    el.addEventListener('input',()=>{ clearTimeout(pvColorT); pvColorT=setTimeout(pvRel,2000); });
});

// ── Превью розділювача ────────────────────────────────────────────────
function updateDividerPreview() {
    const style  = document.getElementById('divider_style_sel')?.value || 'wave';
    const height = parseInt(document.getElementById('divider_height_r')?.value || 50);
    const colorEl = document.getElementById('divider_color');
    const color  = (colorEl?.value?.trim()) || '#f8f9fa';
    const preview = document.getElementById('dividerPreview');
    const bgBox   = document.getElementById('dividerPreviewBg');
    if (!preview) return;
    if (bgBox) bgBox.style.background = color;

    const h = height, hh = Math.round(h/2);
    const svgAttr = `viewBox="0 0 400 ${h}" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" style="display:block;width:100%;height:${h}px"`;
    let html = '';
    if (style === 'wave') {
        html = `<svg ${svgAttr}><path d="M0,${hh} C67,${h} 133,0 200,${hh} C267,${h} 333,0 400,${hh} L400,${h} L0,${h} Z" fill="${color}"/></svg>`;
    } else if (style === 'slope') {
        html = `<svg ${svgAttr}><polygon points="0,${h} 400,0 400,${h}" fill="${color}"/></svg>`;
    } else if (style === 'triangle') {
        html = `<svg ${svgAttr}><polygon points="0,${h} 200,0 400,${h}" fill="${color}"/></svg>`;
    } else if (style === 'arc') {
        html = `<svg ${svgAttr}><path d="M0,${h} Q200,0 400,${h} Z" fill="${color}"/></svg>`;
    } else if (style === 'zigzag') {
        html = `<svg ${svgAttr}><polyline points="0,0 40,${h} 80,0 120,${h} 160,0 200,${h} 240,0 280,${h} 320,0 360,${h} 400,0 400,${h} 0,${h}" fill="${color}"/></svg>`;
    } else if (style === 'line') {
        html = `<div style="height:3px;background:linear-gradient(90deg,transparent,${color} 20%,${color} 80%,transparent)"></div><div style="height:${Math.max(0,h-3)}px;background:${color}"></div>`;
    } else if (style === 'fade') {
        html = `<div style="height:${h}px;background:linear-gradient(to bottom,transparent,${color})"></div>`;
    } else if (style === 'double_line') {
        html = `<div style="height:2px;background:${color};opacity:.3;margin-bottom:6px"></div><div style="height:4px;background:linear-gradient(90deg,transparent,${color} 15%,${color} 85%,transparent)"></div><div style="height:${Math.max(0,h-12)}px;background:${color}"></div>`;
    }
    preview.innerHTML = html;
}
// Ініціалізація при завантаженні
document.addEventListener('DOMContentLoaded', function() {
    updateDividerPreview();
});

// ── Тінь карток ──────────────────────────────────────────────────────
function selectShadowPreset(val) {
    // Зняти активний стан з усіх swatch
    document.querySelectorAll('.shadow-swatch').forEach(function(s) {
        s.style.border = '2px solid #dee2e6';
    });
    // Активувати вибраний radio і swatch
    document.querySelectorAll('input[name="card_shadow_preset"]').forEach(function(r) {
        if (r.value === val) {
            r.checked = true;
            var swatch = r.closest('label').querySelector('.shadow-swatch');
            if (swatch) swatch.style.border = '2px solid #0d6efd';
        }
    });
    // Показати/сховати custom блок
    document.getElementById('customShadowWrap').style.display = val === 'custom' ? 'block' : 'none';
    clearTimeout(pvColorT); pvColorT = setTimeout(pvRel, 600);
}
function onShadowPresetChange(val) { selectShadowPreset(val); }

// Ініціалізація color picker з rgba значення
(function(){
    var txt = document.getElementById('card_shadow_color');
    var pick = document.getElementById('shadowColorPick');
    if (!txt || !pick) return;
    var m = txt.value.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    if (m) {
        var toHex = function(n){ return ('0'+parseInt(n).toString(16)).slice(-2); };
        pick.value = '#' + toHex(m[1]) + toHex(m[2]) + toHex(m[3]);
    }
})();

function updateCustomShadowPreview() {
    const x  = document.querySelector('input[name="card_shadow_x"]')?.value || 0;
    const y  = document.querySelector('input[name="card_shadow_y"]')?.value || 4;
    const bl = document.querySelector('input[name="card_shadow_blur"]')?.value || 18;
    const sp = document.querySelector('input[name="card_shadow_spread"]')?.value || 0;
    const cl = document.getElementById('card_shadow_color')?.value || 'rgba(0,0,0,0.1)';
    const box = document.getElementById('customShadowPreviewBox');
    if (box) box.style.boxShadow = `${x}px ${y}px ${bl}px ${sp}px ${cl}`;
    clearTimeout(pvColorT); pvColorT = setTimeout(pvRel, 1200);
}

function syncShadowColor(input) {
    // Hex → rgba з поточною прозорістю
    var hex = input.value;
    var r = parseInt(hex.slice(1,3),16);
    var g = parseInt(hex.slice(3,5),16);
    var b = parseInt(hex.slice(5,7),16);
    // Витягнути поточну прозорість з текстового поля якщо є
    var txtField = document.getElementById('card_shadow_color');
    var alpha = 0.15;
    if (txtField) {
        var m = txtField.value.match(/rgba?\([^,]+,[^,]+,[^,]+,\s*([0-9.]+)\)/);
        if (m) alpha = parseFloat(m[1]);
    }
    var rgba = 'rgba('+r+','+g+','+b+','+alpha+')';
    if (txtField) txtField.value = rgba;
    // Автоматично переключити на custom
    selectShadowPreset('custom');
    updateCustomShadowPreview();
}
</script>
<?php
$content_html = ob_get_clean();
$title = '⚙️ Налаштування сайту';
$page_title = $title;
$fullBleed = true;
include __DIR__ . '/admin_template.php';