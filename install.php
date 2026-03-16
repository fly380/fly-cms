<?php
/**
 * ╔══════════════════════════════════════════════╗
 * ║  fly-CMS  ·  Інсталятор  ·  v1.2            ║
 * ║  Помістіть у корінь сайту, відкрийте в      ║
 * ║  браузері. Після встановлення самовидаляється║
 * ╚══════════════════════════════════════════════╝
 *
 * Захист: якщо data/.installed існує → сторінка заблокована.
 */

define('FLY_INSTALLER_VER', '1.2.0');
define('FLY_CMS_VER',       '2.9.2-AI');

// ─────────────────────────────────────────────────────────────────
// Bootstrap: сесія + ROOT
// ─────────────────────────────────────────────────────────────────
session_start();
if (empty($_SESSION['fly_inst_token'])) {
    $_SESSION['fly_inst_token'] = bin2hex(random_bytes(32));
}
$ROOT = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

// ─────────────────────────────────────────────────────────────────
// Захист від повторної інсталяції
// ─────────────────────────────────────────────────────────────────
function is_installed(string $root): bool {
    return file_exists($root . '/data/.installed');
}

// ─────────────────────────────────────────────────────────────────
// Перевірка вимог
// ─────────────────────────────────────────────────────────────────
function get_requirements(string $root): array {
    $pdo = class_exists('PDO');
    $drivers = $pdo ? PDO::getAvailableDrivers() : [];
    return [
        ['name'=>'PHP ≥ 8.1',        'ok'=>version_compare(PHP_VERSION,'8.1','>='), 'val'=>PHP_VERSION,     'critical'=>true],
        ['name'=>'PDO SQLite',        'ok'=>in_array('sqlite',$drivers),             'val'=>in_array('sqlite',$drivers)?'✓ є':'✗ немає', 'critical'=>true],
        ['name'=>'JSON',              'ok'=>function_exists('json_encode'),           'val'=>'✓',             'critical'=>true],
        ['name'=>'mbstring',          'ok'=>extension_loaded('mbstring'),             'val'=>extension_loaded('mbstring')?'✓':'рекомендовано', 'critical'=>false],
        ['name'=>'ZipArchive',        'ok'=>class_exists('ZipArchive'),              'val'=>class_exists('ZipArchive')?'✓':'потрібен для бекапів', 'critical'=>false],
        ['name'=>'GD / Imagick',      'ok'=>extension_loaded('gd')||extension_loaded('imagick'), 'val'=>extension_loaded('gd')?'GD':(extension_loaded('imagick')?'Imagick':'рекомендовано'), 'critical'=>false],
        ['name'=>'Webroot writeable', 'ok'=>is_writable($root),                      'val'=>is_writable($root)?'✓':'✗ немає прав', 'critical'=>true],
        ['name'=>'data/ writeable',   'ok'=>(is_dir($root.'/data')&&is_writable($root.'/data'))||(!is_dir($root.'/data')&&is_writable($root)), 'val'=>'перевіряється', 'critical'=>true],
        ['name'=>'HTTPS',             'ok'=>!empty($_SERVER['HTTPS'])||($_SERVER['SERVER_PORT']??80)==443, 'val'=>(!empty($_SERVER['HTTPS'])||($_SERVER['SERVER_PORT']??80)==443)?'✓ увімкнено':'⚠ рекомендовано', 'critical'=>false],
    ];
}

// ─────────────────────────────────────────────────────────────────
// SQLite schema (inline — без зовнішнього файлу)
// ─────────────────────────────────────────────────────────────────
function get_sqlite_schema(): string {
    return <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    login        TEXT NOT NULL UNIQUE,
    password     TEXT NOT NULL,
    role         TEXT NOT NULL DEFAULT 'user',
    display_name TEXT NOT NULL DEFAULT '',
    email        TEXT DEFAULT NULL,
    qr_file      TEXT DEFAULT NULL,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    totp_secret  TEXT DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS pages (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    slug             TEXT NOT NULL UNIQUE,
    title            TEXT NOT NULL DEFAULT '',
    content          TEXT,
    draft            INTEGER NOT NULL DEFAULT 0,
    visibility       TEXT NOT NULL DEFAULT 'public',
    custom_css       TEXT DEFAULT NULL,
    custom_js        TEXT DEFAULT NULL,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS posts (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    title          TEXT NOT NULL DEFAULT '',
    slug           TEXT NOT NULL UNIQUE,
    content        TEXT,
    author         TEXT DEFAULT '',
    draft          INTEGER NOT NULL DEFAULT 0,
    visibility     TEXT NOT NULL DEFAULT 'public',
    show_on_main   INTEGER NOT NULL DEFAULT 1,
    thumbnail      TEXT DEFAULT NULL,
    meta_title     TEXT DEFAULT NULL,
    meta_description TEXT DEFAULT NULL,
    meta_keywords  TEXT DEFAULT NULL,
    allow_comments INTEGER NOT NULL DEFAULT 1,
    sticky         INTEGER NOT NULL DEFAULT 0,
    post_password  TEXT DEFAULT NULL,
    publish_at     DATETIME DEFAULT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    parent_id   INTEGER DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    slug       TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS post_categories (
    post_id     INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    PRIMARY KEY (post_id, category_id),
    FOREIGN KEY (post_id)     REFERENCES posts(id)      ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS menu_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT NOT NULL,
    url             TEXT NOT NULL DEFAULT '',
    position        INTEGER NOT NULL DEFAULT 0,
    visible         INTEGER NOT NULL DEFAULT 1,
    auth_only       INTEGER NOT NULL DEFAULT 0,
    type            TEXT NOT NULL DEFAULT 'link',
    parent_id       INTEGER DEFAULT NULL,
    visibility_role TEXT NOT NULL DEFAULT 'all',
    icon            TEXT DEFAULT '',
    target          TEXT DEFAULT '_self',
    lang_settings   TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS main_page (
    id      INTEGER PRIMARY KEY CHECK (id = 1),
    title   TEXT,
    content TEXT
);
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS theme_settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS user_sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    login        TEXT NOT NULL,
    ip           TEXT NOT NULL DEFAULT '',
    logged_in_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active    INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT NOT NULL UNIQUE,
    email       TEXT DEFAULT NULL,
    role        TEXT NOT NULL DEFAULT 'user',
    created_by  TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,
    used_by     TEXT DEFAULT NULL,
    require_2fa INTEGER NOT NULL DEFAULT 0,
    email_sent  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS notes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner       TEXT NOT NULL,
    scope       TEXT NOT NULL DEFAULT 'personal',
    title       TEXT NOT NULL DEFAULT '',
    body        TEXT NOT NULL DEFAULT '',
    color       TEXT NOT NULL DEFAULT 'yellow',
    remind_at   DATETIME DEFAULT NULL,
    reminded    INTEGER NOT NULL DEFAULT 0,
    linked_type TEXT DEFAULT NULL,
    linked_id   INTEGER DEFAULT NULL,
    pinned      INTEGER NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS post_revisions (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id  INTEGER NOT NULL,
    title    TEXT DEFAULT NULL,
    content  TEXT NOT NULL DEFAULT '',
    saved_by TEXT DEFAULT NULL,
    saved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    note     TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS backup_settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS backup_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL,
    filename   TEXT NOT NULL,
    size       INTEGER DEFAULT 0,
    created_by TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    note       TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS support_tickets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uid         TEXT NOT NULL UNIQUE,
    author      TEXT NOT NULL,
    subject     TEXT NOT NULL DEFAULT '',
    category    TEXT NOT NULL DEFAULT 'general',
    priority    TEXT NOT NULL DEFAULT 'normal',
    status      TEXT NOT NULL DEFAULT 'open',
    reply_token TEXT DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at   DATETIME DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS support_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id   INTEGER NOT NULL,
    sender      TEXT NOT NULL,
    sender_type TEXT NOT NULL DEFAULT 'user',
    body        TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS support_attachments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL,
    filename   TEXT NOT NULL,
    size       INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL;
}

// ─────────────────────────────────────────────────────────────────
// JSON API  (усі POST + ?action=)
// ─────────────────────────────────────────────────────────────────
if (!empty($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $b = json_decode(file_get_contents('php://input'), true) ?? [];

    if (($b['_tok'] ?? '') !== $_SESSION['fly_inst_token']) {
        echo json_encode(['ok'=>false,'err'=>'Невірний токен. Оновіть сторінку.']); exit;
    }

    switch ($_GET['action']) {

        // ── Основна інсталяція ────────────────────────────────────
        case 'install': {
            $siteName   = trim($b['site_name']  ?? 'Мій сайт');
            $siteDesc   = trim($b['site_desc']  ?? '');
            $groqApiKey = preg_replace('/[^A-Za-z0-9_\-]/', '', trim($b['groq_api_key'] ?? ''));
            $adminLogin = trim($b['admin_login'] ?? 'admin');
            $adminPass  = $b['admin_pass']       ?? '';
            $adminDisp  = trim($b['admin_disp']  ?? $adminLogin);
            $createDemo = !empty($b['demo']);

            // Валідація
            if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $adminLogin))
                { echo json_encode(['ok'=>false,'err'=>'Логін: лише a-z 0-9 _ (3–50 символів)']); exit; }
            if (strlen($adminPass) < 8)
                { echo json_encode(['ok'=>false,'err'=>'Пароль адміна: мінімум 8 символів']); exit; }

            $log = [];

            try {
                // 1 ── Директорії ─────────────────────────────────
                foreach ([
                    "$ROOT/data", "$ROOT/data/BD", "$ROOT/data/logs",
                    "$ROOT/data/backups", "$ROOT/data/backups/db", "$ROOT/data/backups/files",
                    "$ROOT/uploads", "$ROOT/uploads/images", "$ROOT/uploads/media",
                    "$ROOT/assets", "$ROOT/assets/css", "$ROOT/assets/js", "$ROOT/assets/images",
                ] as $dir) {
                    if (!is_dir($dir) && !mkdir($dir, 0755, true))
                        throw new \RuntimeException("Не вдалося створити: $dir");
                }
                $htData = "$ROOT/data/.htaccess";
                if (!file_exists($htData)) file_put_contents($htData, "Options -Indexes\nDeny from all\n");
                $htUpl = "$ROOT/uploads/.htaccess";
                if (!file_exists($htUpl)) file_put_contents($htUpl,
                    "Options -Indexes\n<FilesMatch \"\\.php$\">\n    Deny from all\n</FilesMatch>\n");
                $log[] = ['ok'=>true,'msg'=>'Директорії та захисні .htaccess створено'];

                // 2 ── SQLite ──────────────────────────────────────
                $sqlitePath = "$ROOT/data/BD/database.sqlite";
                $pdo = new PDO("sqlite:$sqlitePath");
                $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA busy_timeout = 3000');
                foreach (array_filter(array_map('trim', explode(';', get_sqlite_schema()))) as $stmt) {
                    if ($stmt) $pdo->exec($stmt);
                }
                $log[] = ['ok'=>true,'msg'=>'SQLite створено: data/BD/database.sqlite (16 таблиць)'];

                // 3 ── Суперадмін ──────────────────────────────────
                $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost'=>12]);
                $pdo->prepare("INSERT OR REPLACE INTO users (login,password,role,display_name) VALUES (?,?,?,?)")
                    ->execute([$adminLogin, $hash, 'superadmin', $adminDisp ?: $adminLogin]);
                $log[] = ['ok'=>true,'msg'=>"Суперадміністратор «{$adminLogin}» створений"];

                // 4 ── Налаштування ────────────────────────────────
                $st = $pdo->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)");
                foreach ([
                    ['site_title',       $siteName],
                    ['meta_description', $siteDesc],
                    ['meta_keywords',    ''],
                    ['footer_text',      '© '.date('Y').' '.$siteName],
                    ['cms_name',         'fly-CMS'],
                    ['cms_version',      FLY_CMS_VER],
                    ['site_author',      $adminDisp ?: $adminLogin],
                    ['logo_path',        ''],
                    ['favicon_path',     ''],
                    ['background_image', ''],
                    ['installed_at',     date('Y-m-d H:i:s')],
                    ['cms_changelog',    FLY_CMS_VER.' — Встановлено через інсталятор '.date('Y-m-d')],
                ] as [$k, $v]) $st->execute([$k, $v]);
                $log[] = ['ok'=>true,'msg'=>'Базові налаштування сайту збережено'];

                // 5 ── Меню ────────────────────────────────────────
                $mp = $pdo->prepare("INSERT OR IGNORE INTO menu_items (title,url,position,visible,auth_only,type,visibility_role,icon,target) VALUES (?,?,?,1,?,?,?,?,?)");
                $mp->execute(['Головна',  '/',                 1, 0, 'link',        'all', 'bi-house',  '_self']);
                $mp->execute(['Адмінка',  '/admin/index.php',  2, 1, 'link',        'all', 'bi-person', '_self']);
                $mp->execute(['Вхід',     '',                  3, 0, 'login_logout', 'all', '',          '_self']);
                $log[] = ['ok'=>true,'msg'=>'Базове меню створено (Головна, Адмінка, Вхід)'];

                // 6 ── Демо-контент ────────────────────────────────
                if ($createDemo) {
                    $pdo->prepare("INSERT OR IGNORE INTO posts (title,slug,content,draft,author) VALUES (?,?,?,0,?)")
                        ->execute([
                            'Перший запис у блозі', 'pershyi-zapys',
                            '<p>Це демонстраційний запис. Ви можете редагувати або видалити його в адмінці.</p><p>fly-CMS дозволяє створювати та публікувати матеріали зручно і швидко.</p>',
                            $adminDisp ?: $adminLogin,
                        ]);
                    $log[] = ['ok'=>true,'msg'=>'Демо-запис у блозі створено'];
                }

                // 7 ── .env ────────────────────────────────────────
                $storageDir = dirname($ROOT).'/cms_storage';
                $envDir     = (is_dir($storageDir) || @mkdir($storageDir, 0750, true)) ? $storageDir : $ROOT;
                $envPath    = $envDir.'/.env';

                $env  = "# fly-CMS конфігурація\n# Згенеровано: ".date('Y-m-d H:i:s')."\n\n";
                $env .= "# AI\nGROQ_API_KEY={$groqApiKey}\n";
                $env .= "\n# SMTP\nSMTP_ENABLED=false\nSMTP_HOST=smtp.gmail.com\nSMTP_PORT=587\n";
                $env .= "SMTP_USERNAME=\nSMTP_PASSWORD=\n";
                $env .= "SMTP_FROM_NAME=".str_replace(["\n","\r"], '', $siteName)."\n";
                $env .= "SMTP_FROM_EMAIL=noreply@example.com\nSMTP_ENCRYPTION=tls\n";
                $dbAdminPass = bin2hex(random_bytes(8));
                $env .= "\n# phpLiteAdmin\nPHPLITEADMIN_PASSWORD={$dbAdminPass}\n";
                $env .= "\n# GitHub (оновлення CMS)\nGITHUB_OWNER=fly380\nGITHUB_REPO=fly-cms\n";
                file_put_contents($envPath, $env);

                if ($envDir === $ROOT) {
                    $ht  = "$ROOT/.htaccess";
                    $htc = file_exists($ht) ? file_get_contents($ht) : '';
                    if (!str_contains($htc, '.env')) {
                        file_put_contents($ht, $htc."\n<Files \".env\">\n    Deny from all\n</Files>\n");
                    }
                }
                $loc = ($envDir === $ROOT) ? 'webroot (.htaccess захищений)' : 'поза webroot ✓';
                $log[] = ['ok'=>true,'msg'=>".env збережено ({$loc})"];

                // 8 ── Lock-файл ───────────────────────────────────
                file_put_contents("$ROOT/data/.installed",
                    date('Y-m-d H:i:s')."\n".$adminLogin."\n".FLY_CMS_VER);
                $log[] = ['ok'=>true,'msg'=>'Інсталяцію завершено ✓'];

                $_SESSION['fly_installed'] = [
                    'login'        => $adminLogin,
                    'site'         => $siteName,
                    'db'           => 'SQLite · data/BD/database.sqlite',
                    'db_admin_pass'=> $dbAdminPass,
                ];

                echo json_encode(['ok'=>true,'log'=>$log,'db_admin_pass'=>$dbAdminPass]);

            } catch (\Throwable $e) {
                $log[] = ['ok'=>false,'msg'=>'ПОМИЛКА: '.$e->getMessage()];
                echo json_encode(['ok'=>false,'log'=>$log,'err'=>$e->getMessage()]);
            }
            exit;
        }

        case 'delete_self': {
            echo json_encode(['ok'=>@unlink(__FILE__)]);
            exit;
        }
    }

    echo json_encode(['ok'=>false,'err'=>'Unknown action']); exit;
}

// ─────────────────────────────────────────────────────────────────
// Підготовка даних для HTML
// ─────────────────────────────────────────────────────────────────
$blocked      = is_installed($ROOT);
$reqs         = get_requirements($ROOT);
$anyFail      = !empty(array_filter($reqs, fn($r)=>$r['critical']&&!$r['ok']));
$TOK          = $_SESSION['fly_inst_token'];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Встановлення fly-CMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── Tokens ─────────────────────────────────── */
:root {
  --bg:       #0c0e14;
  --bg2:      #131620;
  --bg3:      #1a1e2e;
  --border:   #252a3d;
  --border2:  #2e3450;
  --ink:      #e8eaf6;
  --ink2:     #8b93b8;
  --ink3:     #4a5070;
  --accent:   #5b7fff;
  --accent2:  #7c9bff;
  --ok:       #34d399;
  --warn:     #fbbf24;
  --err:      #f87171;
  --radius:   10px;
  --mono:     'JetBrains Mono', monospace;
  --sans:     'Syne', sans-serif;
}

/* ── Reset ──────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-text-size-adjust:100%}
body{
  background:var(--bg);
  color:var(--ink);
  font-family:var(--sans);
  min-height:100vh;
  display:flex;
  flex-direction:column;
  align-items:stretch;
}

/* ── Hero header ────────────────────────────── */
.hero {
  background: linear-gradient(160deg, #0d1530 0%, #111827 60%, #0a0d18 100%);
  border-bottom: 1px solid var(--border);
  padding: 3rem 1.5rem 2.5rem;
  text-align: center;
  position: relative;
  overflow: hidden;
}
.hero::before {
  content:'';
  position:absolute;inset:0;
  background:
    radial-gradient(ellipse 60% 40% at 20% 30%, rgba(91,127,255,.12) 0%, transparent 70%),
    radial-gradient(ellipse 40% 30% at 80% 70%, rgba(124,155,255,.08) 0%, transparent 70%);
  pointer-events:none;
}
.hero-plane { font-size:3rem; display:block; margin-bottom:.5rem; filter:drop-shadow(0 0 20px rgba(91,127,255,.5)); }
.hero-title {
  font-size:clamp(2rem,5vw,3.2rem);
  font-weight:800;
  letter-spacing:-.03em;
  background: linear-gradient(135deg, #fff 30%, var(--accent2));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
  margin-bottom:.4rem;
}
.hero-sub { color:var(--ink2); font-size:.9rem; font-weight:400; }
.hero-badge {
  display:inline-flex; align-items:center; gap:.4rem;
  background: rgba(91,127,255,.12); border:1px solid rgba(91,127,255,.25);
  border-radius:20px; padding:.25rem .85rem; font-size:.75rem; color:var(--accent2);
  font-family:var(--mono); margin-top:.8rem;
}

/* ── Progress steps ─────────────────────────── */
.steps-bar {
  display:flex; align-items:center;
  background:var(--bg2);
  border-bottom:1px solid var(--border);
  padding:.8rem 1.5rem;
  gap:0;
  overflow-x:auto;
  scrollbar-width:none;
}
.steps-bar::-webkit-scrollbar{display:none}

.step-item {
  display:flex; align-items:center; gap:.5rem;
  white-space:nowrap;
  flex-shrink:0;
  font-size:.8rem; font-weight:600;
  color:var(--ink3);
  padding:.2rem .6rem;
  transition:color .2s;
}
.step-item.active { color:var(--accent2); }
.step-item.done   { color:var(--ok); }
.step-num {
  width:22px;height:22px;border-radius:50%;
  background:var(--bg3); border:1px solid var(--border2);
  display:flex;align-items:center;justify-content:center;
  font-size:.7rem; font-weight:700; font-family:var(--mono);
  transition:all .25s;
  flex-shrink:0;
}
.step-item.active .step-num { background:var(--accent); border-color:var(--accent); color:#fff; box-shadow:0 0 10px rgba(91,127,255,.4); }
.step-item.done   .step-num { background:rgba(52,211,153,.15); border-color:var(--ok); color:var(--ok); }
.step-sep { width:24px; height:1px; background:var(--border2); flex-shrink:0; }

/* ── Layout ─────────────────────────────────── */
.layout {
  display:flex; flex:1;
  max-width:900px; width:100%;
  margin:0 auto; padding:2rem 1.25rem 4rem;
}
.card {
  background:var(--bg2);
  border:1px solid var(--border);
  border-radius:var(--radius);
  width:100%;
  overflow:hidden;
}
.card-head {
  padding:1.5rem 2rem 1rem;
  border-bottom:1px solid var(--border);
}
.card-head h2 { font-size:1.15rem; font-weight:700; margin-bottom:.2rem; }
.card-head p  { font-size:.82rem; color:var(--ink2); }
.card-body { padding:1.75rem 2rem; }
.card-body + .card-body { border-top:1px solid var(--border); }

/* ── Form elements ──────────────────────────── */
.field { margin-bottom:1.25rem; }
.field label {
  display:block; font-size:.78rem; font-weight:600;
  color:var(--ink2); letter-spacing:.04em; text-transform:uppercase;
  margin-bottom:.45rem;
}
.field label .req { color:var(--err); margin-left:.2rem; }
.field input, .field textarea, .field select {
  width:100%;
  background:var(--bg3); border:1px solid var(--border2);
  border-radius:7px; color:var(--ink);
  padding:.65rem .9rem; font-size:.9rem; font-family:var(--sans);
  outline:none; transition:border-color .2s, box-shadow .2s;
  -webkit-appearance:none;
}
.field input:focus, .field textarea:focus, .field select:focus {
  border-color:var(--accent); box-shadow:0 0 0 3px rgba(91,127,255,.18);
}
.field input.is-invalid { border-color:var(--err); }
.field .hint { font-size:.75rem; color:var(--ink3); margin-top:.3rem; }
.field .hint.ok   { color:var(--ok); }
.field .hint.warn { color:var(--warn); }
.field .hint.err  { color:var(--err); }

.row2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.row3 { display:grid; grid-template-columns:2fr 1fr 3fr; gap:1rem; }
@media(max-width:580px){.row2,.row3{grid-template-columns:1fr;}}

/* ── Input with button ──────────────────────── */
.input-with-btn { display:flex; gap:.5rem; }
.input-with-btn input { flex:1; }

/* ── Buttons ────────────────────────────────── */
.btn {
  display:inline-flex; align-items:center; justify-content:center; gap:.45rem;
  padding:.65rem 1.4rem; border-radius:7px; font-family:var(--sans);
  font-size:.88rem; font-weight:700; cursor:pointer; border:none;
  transition:all .18s; white-space:nowrap;
}
.btn:disabled { opacity:.45; cursor:not-allowed; }
.btn-primary  { background:var(--accent); color:#fff; box-shadow:0 2px 12px rgba(91,127,255,.35); }
.btn-primary:not(:disabled):hover  { background:var(--accent2); box-shadow:0 4px 18px rgba(91,127,255,.5); transform:translateY(-1px); }
.btn-ghost    { background:transparent; color:var(--ink2); border:1px solid var(--border2); }
.btn-ghost:not(:disabled):hover    { border-color:var(--accent); color:var(--accent2); }
.btn-ok       { background:rgba(52,211,153,.12); color:var(--ok); border:1px solid rgba(52,211,153,.3); }
.btn-ok:not(:disabled):hover       { background:rgba(52,211,153,.2); }
.btn-lg { padding:.85rem 2rem; font-size:1rem; }
.btn-sm { padding:.4rem .9rem; font-size:.8rem; }

/* ── Spinner ────────────────────────────────── */
.spin {
  width:16px;height:16px;border-radius:50%;
  border:2px solid rgba(255,255,255,.25); border-top-color:#fff;
  animation:spin .6s linear infinite; display:inline-block;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Requirements ───────────────────────────── */
.req-list { display:flex; flex-direction:column; gap:.3rem; }
.req-row {
  display:flex; align-items:center; gap:.7rem;
  padding:.55rem .8rem; border-radius:7px;
  background:var(--bg3); font-size:.85rem;
}
.req-name  { flex:1; }
.req-val   { font-family:var(--mono); font-size:.75rem; color:var(--ink3); margin-right:.4rem; }
.req-badge {
  width:20px;height:20px;border-radius:50%; flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;
}
.req-badge.ok   { background:rgba(52,211,153,.15); color:var(--ok); }
.req-badge.err  { background:rgba(248,113,113,.15); color:var(--err); }
.req-badge.warn { background:rgba(251,191,36,.12);  color:var(--warn); }

/* ── Checkbox ───────────────────────────────── */
.check-row { display:flex; align-items:center; gap:.7rem; cursor:pointer; font-size:.88rem; color:var(--ink2); }
.check-row input[type=checkbox] {
  width:17px; height:17px; accent-color:var(--accent);
  cursor:pointer; flex-shrink:0;
}

/* ── Alert / info boxes ─────────────────────── */
.info-box {
  border-radius:7px; padding:.8rem 1rem;
  font-size:.82rem; line-height:1.6;
  display:flex; gap:.7rem; align-items:flex-start;
}
.info-box.info    { background:rgba(91,127,255,.08); border:1px solid rgba(91,127,255,.2); }
.info-box.success { background:rgba(52,211,153,.08); border:1px solid rgba(52,211,153,.2); color:var(--ok); }
.info-box.error   { background:rgba(248,113,113,.08); border:1px solid rgba(248,113,113,.2); color:var(--err); }
.info-box.warn    { background:rgba(251,191,36,.08); border:1px solid rgba(251,191,36,.2); color:var(--warn); }

/* ── Password strength ──────────────────────── */
.strength-bar { display:flex; gap:3px; margin-top:.4rem; }
.strength-bar span {
  flex:1; height:3px; border-radius:2px;
  background:var(--border2); transition:background .2s;
}

/* ── Install log ────────────────────────────── */
.install-log {
  background:#080a10; border:1px solid var(--border);
  border-radius:8px; padding:1rem 1.2rem;
  font-family:var(--mono); font-size:.78rem; line-height:1.8;
  min-height:160px; max-height:260px; overflow-y:auto;
  color:#4ade80;
}
.log-fail { color:var(--err); }
.log-info { color:var(--ink3); }

/* ── Progress bar ───────────────────────────── */
.progress-wrap { background:var(--bg3); border-radius:4px; overflow:hidden; height:6px; margin-bottom:.75rem; }
.progress-fill {
  height:100%; background:linear-gradient(90deg, var(--accent), var(--ok));
  border-radius:4px; transition:width .4s ease;
  box-shadow:0 0 8px rgba(91,127,255,.4);
}

/* ── Success screen ─────────────────────────── */
.success-wrap { text-align:center; padding:2rem 1rem; }
.success-icon { font-size:4rem; display:block; margin-bottom:1rem;
  animation:pop .4s cubic-bezier(.175,.885,.32,1.275); }
@keyframes pop{ from{transform:scale(0)} to{transform:scale(1)} }
.creds-table {
  background:var(--bg3); border:1px solid var(--border2);
  border-radius:8px; overflow:hidden; text-align:left; width:100%; margin:1.5rem 0;
}
.creds-row { display:flex; padding:.6rem 1rem; font-size:.86rem; }
.creds-row:not(:last-child){ border-bottom:1px solid var(--border); }
.creds-key   { color:var(--ink3); width:160px; flex-shrink:0; }
.creds-val   { font-family:var(--mono); font-weight:600; word-break:break-all; }

/* ── Footer nav ─────────────────────────────── */
.card-footer {
  display:flex; justify-content:space-between; align-items:center;
  padding:1.25rem 2rem;
  border-top:1px solid var(--border);
}

/* ── Blocked screen ─────────────────────────── */
.blocked {
  max-width:460px; margin:5rem auto; padding:1.5rem;
  text-align:center;
}

/* ── Transitions ────────────────────────────── */
.screen { display:none; }
.screen.active { display:block; }

/* ── Scrollbar ──────────────────────────────── */
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:var(--bg2)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
</style>
</head>
<body>

<!-- ══ HEADER ══════════════════════════════════════════════════════ -->
<div class="hero">
  <span class="hero-plane">✈</span>
  <div class="hero-title">fly-CMS Installer</div>
  <div class="hero-sub">Система встановлення, без зайвих кроків</div>
  <div class="hero-badge">v<?= FLY_CMS_VER ?> · PHP <?= PHP_VERSION ?></div>
</div>

<?php if ($blocked): ?>
<!-- ══ BLOCKED ════════════════════════════════════════════════════ -->
<div class="layout">
  <div class="blocked" style="width:100%">
    <div style="font-size:3.5rem;margin-bottom:1rem">🔒</div>
    <h2 style="margin-bottom:.5rem">CMS вже встановлена</h2>
    <p style="color:var(--ink2);font-size:.9rem;margin-bottom:1.5rem">
      Інсталятор заблоковано з міркувань безпеки.<br>
      Для переінсталяції видаліть <code style="font-family:var(--mono);color:var(--accent2)">data/.installed</code> та <code style="font-family:var(--mono);color:var(--accent2)">.env</code>
    </p>
    <a href="/admin/" class="btn btn-primary">→ Перейти в адмінку</a>
  </div>
</div>

<?php else: ?>

<!-- ══ STEPS BAR ══════════════════════════════════════════════════ -->
<div class="steps-bar" id="stepsBar">
  <?php $sLabels=['Вимоги','База даних','Сайт','Адмін','Встановлення'];
  foreach($sLabels as $si=>$sl): $sn=$si+1; ?>
  <div class="step-item <?=$sn===1?'active':''?>" id="sn<?=$sn?>">
    <span class="step-num"><?=$sn?></span><?=$sl?>
  </div>
  <?php if($sn<5): ?><div class="step-sep"></div><?php endif; ?>
  <?php endforeach; ?>
</div>

<!-- ══ MAIN CARD ══════════════════════════════════════════════════ -->
<div class="layout">
<div class="card">

<!-- ─────────── SCREEN 1: REQUIREMENTS ─────────────────────────── -->
<div class="screen active" id="s1">
  <div class="card-head">
    <h2>Перевірка системних вимог</h2>
    <p>Переконайтесь, що сервер задовольняє мінімальні вимоги fly-CMS.</p>
  </div>
  <div class="card-body">
    <div class="req-list">
      <?php foreach($reqs as $r):
        $cls = $r['ok']?'ok':($r['critical']?'err':'warn');
        $ico = $r['ok']?'✓':($r['critical']?'✗':'!');
      ?>
      <div class="req-row">
        <span class="req-name"><?=htmlspecialchars($r['name'])?></span>
        <span class="req-val"><?=htmlspecialchars($r['val'])?></span>
        <span class="req-badge <?=$cls?>"><?=$ico?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if($anyFail): ?>
    <div class="info-box error" style="margin-top:1.2rem">
      <span>⛔</span>
      <div><strong>Критичні вимоги не виконані.</strong> Виправте їх і оновіть сторінку.</div>
    </div>
    <?php else: ?>
    <div class="info-box success" style="margin-top:1.2rem">
      <span>✓</span>
      <div><strong>Всі критичні вимоги виконано.</strong> Можна продовжувати.</div>
    </div>
    <?php endif; ?>
  </div>
  <?php if(!$anyFail): ?>
  <div class="card-footer" style="justify-content:flex-end">
    <button class="btn btn-primary" onclick="goTo(2)">Далі →</button>
  </div>
  <?php endif; ?>
</div>

<!-- ─────────── SCREEN 2: DATABASE ──────────────────────────────── -->
<div class="screen" id="s2">
  <div class="card-head">
    <h2>База даних</h2>
    <p>fly-CMS використовує SQLite — файлову базу даних, яка не потребує налаштування сервера.</p>
  </div>
  <div class="card-body">
    <div class="info-box info">
      <span>🗂</span>
      <div>
        База збережеться у <code style="font-family:var(--mono)">data/BD/database.sqlite</code>.<br>
        WAL-режим увімкнено автоматично для надійної паралельної роботи.
      </div>
    </div>
  </div>
  <div class="card-footer">
    <button class="btn btn-ghost" onclick="goTo(1)">← Назад</button>
    <button class="btn btn-primary" onclick="dbNext()">Далі →</button>
  </div>
</div>

<!-- ─────────── SCREEN 3: SITE ───────────────────────────────────── -->
<div class="screen" id="s3">
  <div class="card-head">
    <h2>Налаштування сайту</h2>
    <p>Базова інформація, яка відображається відвідувачам і в адмінці.</p>
  </div>
  <div class="card-body">
    <div class="field">
      <label>Назва сайту <span class="req">*</span></label>
      <input type="text" id="siteName" placeholder="Мій чудовий сайт" maxlength="100">
      <div class="hint">Відображається у заголовку, меню та footer.</div>
    </div>
    <div class="field">
      <label>Короткий опис</label>
      <textarea id="siteDesc" rows="2" placeholder="Про що ваш сайт..." maxlength="160"
        style="resize:vertical;min-height:60px"></textarea>
      <div class="hint" id="descCount">0/160 символів · використовується як meta description</div>
    </div>
    <label class="check-row" style="margin-top:.5rem">
      <input type="checkbox" id="createDemo" checked>
      Створити демо-сторінку та тестовий запис у блозі
    </label>

    <div class="field" style="margin-top:1.25rem">
      <label>GROQ API Key <span style="font-size:.7rem;color:var(--ink3);font-weight:400">(необов'язково)</span></label>
      <div style="position:relative">
        <input type="password" id="groqKey" placeholder="gsk_…" autocomplete="off" spellcheck="false"
               style="padding-right:2.5rem;font-family:var(--mono);letter-spacing:.03em">
        <button type="button" onclick="toggleVis('groqKey',this)"
                style="position:absolute;right:.5rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink3);font-size:1rem;padding:0">👁</button>
      </div>
      <div class="hint">
        AI-асистент у редакторі постів. Отримати безкоштовно на
        <a href="https://console.groq.com/keys" target="_blank" style="color:var(--accent)">console.groq.com</a>.
        Можна додати пізніше в адмінці → <em>AI налаштування</em>.
      </div>
    </div>
  </div>
  <div class="card-footer">
    <button class="btn btn-ghost" onclick="goTo(2)">← Назад</button>
    <button class="btn btn-primary" onclick="siteNext()">Далі →</button>
  </div>
</div>

<!-- ─────────── SCREEN 4: ADMIN ──────────────────────────────────── -->
<div class="screen" id="s4">
  <div class="card-head">
    <h2>Обліковий запис адміністратора</h2>
    <p>Збережіть ці дані — вони потрібні для входу в адмінку.</p>
  </div>
  <div class="card-body">
    <div class="row2">
      <div class="field">
        <label>Логін <span class="req">*</span></label>
        <input type="text" id="aLogin" value="admin" placeholder="admin" oninput="validateLogin(this)">
        <div class="hint" id="loginHint">Лише a-z, 0-9, підкреслення (3–50 символів)</div>
      </div>
      <div class="field">
        <label>Відображуване ім'я</label>
        <input type="text" id="aDisp" placeholder="Іван Адміністратор" maxlength="100">
        <div class="hint">Може містити будь-які символи</div>
      </div>
    </div>
    <div class="row2">
      <div class="field">
        <label>Пароль <span class="req">*</span></label>
        <div class="input-with-btn">
          <input type="password" id="aPass" placeholder="мін. 8 символів" oninput="checkStrength(this.value); checkMatch()">
          <button class="btn btn-ghost btn-sm" type="button" onclick="toggleVis('aPass',this)">👁</button>
        </div>
        <div class="strength-bar">
          <span id="sb1"></span><span id="sb2"></span><span id="sb3"></span><span id="sb4"></span><span id="sb5"></span>
        </div>
        <div class="hint" id="strengthLbl"></div>
      </div>
      <div class="field">
        <label>Підтвердження пароля <span class="req">*</span></label>
        <input type="password" id="aPass2" placeholder="повторіть пароль" oninput="checkMatch()">
        <div class="hint" id="matchHint"></div>
      </div>
    </div>
    <div class="field">
      <label>Email (необов'язково)</label>
      <input type="email" id="aEmail" placeholder="admin@example.com">
    </div>
  </div>
  <div class="card-footer">
    <button class="btn btn-ghost" onclick="goTo(3)">← Назад</button>
    <button class="btn btn-primary" onclick="adminNext()">Далі →</button>
  </div>
</div>

<!-- ─────────── SCREEN 5: INSTALL ────────────────────────────────── -->
<div class="screen" id="s5">
  <div class="card-head">
    <h2>Встановлення</h2>
    <p>Перевірте параметри і натисніть «Встановити».</p>
  </div>
  <div class="card-body">
    <!-- Summary -->
    <div id="summary" style="margin-bottom:1.5rem"></div>

    <!-- Progress (прихований до початку) -->
    <div id="progressWrap" style="display:none;margin-bottom:1rem">
      <div class="progress-wrap">
        <div class="progress-fill" id="progressFill" style="width:0%"></div>
      </div>
      <div class="install-log" id="installLog"></div>
    </div>
    <div id="installMsg"></div>
  </div>
  <div class="card-footer" id="s5footer">
    <button class="btn btn-ghost" onclick="goTo(4)" id="s5back">← Назад</button>
    <button class="btn btn-primary btn-lg" id="btnInstall" onclick="doInstall()">
      <span id="instSpin" style="display:none" class="spin"></span>
      🚀 Встановити fly-CMS
    </button>
  </div>
</div>

<!-- ─────────── SCREEN 6: SUCCESS ────────────────────────────────── -->
<div class="screen" id="s6">
  <div class="card-body success-wrap">
    <span class="success-icon">🎉</span>
    <h2 style="font-size:1.6rem;margin-bottom:.4rem">fly-CMS встановлено!</h2>
    <p style="color:var(--ink2);font-size:.9rem">Ваш сайт готовий. Збережіть дані нижче.</p>
    <div class="creds-table" id="finalCreds"></div>
    <div class="info-box warn" style="text-align:left;margin-bottom:1.5rem">
      <span>⚠</span>
      <div>Файл <code style="font-family:var(--mono)">install.php</code> було автоматично видалено.
      Якщо він ще присутній — видаліть вручну.</div>
    </div>
    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
      <a href="/admin/" class="btn btn-primary btn-lg">→ Адмінка</a>
      <a href="/" class="btn btn-ghost btn-lg">🏠 На сайт</a>
    </div>
  </div>
</div>

</div><!-- /card -->
</div><!-- /layout -->
<?php endif; ?>

<script>
const TOK = <?=json_encode($TOK)?>;

// ─── State ───────────────────────────────────────────────────────
const S = {};

// ─── Navigation ──────────────────────────────────────────────────
function goTo(n) {
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  const scr = document.getElementById('s'+n);
  if (scr) scr.classList.add('active');
  // Steps
  for(let i=1;i<=5;i++){
    const el=document.getElementById('sn'+i);
    if(!el) continue;
    el.classList.remove('active','done');
    if(i<n)  el.classList.add('done');
    if(i===n)el.classList.add('active');
  }
  if(n===5) buildSummary();
  window.scrollTo({top:0,behavior:'smooth'});
}

// ─── Helpers ─────────────────────────────────────────────────────
const g = id => document.getElementById(id);
const v = id => (g(id)?.value||'').trim();
function toggleVis(id,btn){
  const f=g(id); f.type=f.type==='password'?'text':'password';
  btn.textContent=f.type==='password'?'👁':'🙈';
}
function infoBox(type,msg){ return `<div class="info-box ${type}" style="margin-top:.5rem"><span>${{success:'✓',error:'⛔',warn:'⚠',info:'ℹ'}[type]||'ℹ'}</span><div>${msg}</div></div>`; }
function post(action,data){
  return fetch(`?action=${action}`,{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({...data,_tok:TOK})
  }).then(r=>r.json());
}

// ─── DB step ─────────────────────────────────────────────────────
function dbNext(){ goTo(3); }

// ─── Site step ───────────────────────────────────────────────────
g('siteDesc')?.addEventListener('input',function(){
  g('descCount').textContent=this.value.length+'/160 символів · meta description';
});
function siteNext(){
  if(!v('siteName')){ g('siteName').classList.add('is-invalid'); g('siteName').focus(); return; }
  g('siteName').classList.remove('is-invalid');
  goTo(4);
}

// ─── Password strength ────────────────────────────────────────────
function checkStrength(p){
  const bars=[g('sb1'),g('sb2'),g('sb3'),g('sb4'),g('sb5')];
  let sc=0;
  if(p.length>=8)sc++;if(p.length>=12)sc++;
  if(/[A-Z]/.test(p))sc++;if(/[0-9]/.test(p))sc++;if(/[^A-Za-z0-9]/.test(p))sc++;
  const cols=['','#f87171','#fbbf24','#fbbf24','#34d399','#34d399'];
  const labs=['','Дуже слабкий','Слабкий','Середній','Надійний','Відмінний'];
  bars.forEach((b,i)=>{ b.style.background=i<sc?(cols[sc]||'var(--accent)'):'var(--border2)'; });
  g('strengthLbl').textContent=labs[sc]||'';
  g('strengthLbl').style.color=cols[sc]||'';
}
function checkMatch(){
  const p1=g('aPass').value, p2=g('aPass2').value;
  if(!p2){ g('matchHint').textContent=''; return; }
  if(p1===p2){ g('matchHint').textContent='✓ Паролі збігаються'; g('matchHint').className='hint ok'; }
  else        { g('matchHint').textContent='✗ Паролі не збігаються'; g('matchHint').className='hint err'; }
}
function validateLogin(input){
  const ok=/^[a-zA-Z0-9_]{3,50}$/.test(input.value);
  input.classList.toggle('is-invalid',input.value.length>0&&!ok);
  g('loginHint').className='hint'+(input.value.length>0&&!ok?' err':'');
}
function adminNext(){
  const login=v('aLogin'), pass=g('aPass').value, pass2=g('aPass2').value;
  if(!/^[a-zA-Z0-9_]{3,50}$/.test(login)){ alert('Невірний логін. Лише a-z 0-9 _ (3–50 символів)'); return; }
  if(pass.length<8){ alert('Пароль мінімум 8 символів'); return; }
  if(pass!==pass2){ alert('Паролі не збігаються'); return; }
  goTo(5);
}

// ─── Summary ─────────────────────────────────────────────────────
function buildSummary(){
  const rows=[
    ['База даних', 'SQLite · data/BD/database.sqlite'],
    ['GROQ API',   g('groqKey')?.value?.trim() ? '✅ вказано' : '— (можна додати пізніше)'],
    ['Назва сайту', v('siteName')],
    ['Опис', v('siteDesc')||'—'],
    ['Логін адміна', v('aLogin')],
    ["Ім'я адміна", v('aDisp')||v('aLogin')],
    ['Демо-контент', g('createDemo').checked?'Так':'Ні'],
  ];
  g('summary').innerHTML='<div class="creds-table">'+
    rows.map(([k,val])=>`<div class="creds-row"><span class="creds-key">${k}</span><span class="creds-val">${val}</span></div>`).join('')+
  '</div>';
}

// ─── Install ──────────────────────────────────────────────────────
async function doInstall(){
  const btn=g('btnInstall'), sp=g('instSpin');
  const log=g('installLog'), prog=g('progressFill');
  const msg=g('installMsg'), pw=g('progressWrap');

  btn.disabled=true; sp.style.display='inline-block';
  pw.style.display='block'; g('s5back').style.display='none';
  log.innerHTML='<span class="log-info">▶ Починаємо встановлення…</span>\n';
  msg.innerHTML='';

  const payload={
    site_name:   v('siteName'),
    site_desc:   v('siteDesc'),
    groq_api_key: g('groqKey')?.value?.trim() || '',
    admin_login: v('aLogin'),
    admin_pass:  g('aPass').value,
    admin_disp:  v('aDisp'),
    admin_email: v('aEmail'),
    demo:        g('createDemo').checked,
  };

  try {
    const r=await post('install',payload);
    const total=(r.log||[]).length||1;
    (r.log||[]).forEach((step,i)=>{
      const cls=step.ok?'':'log-fail';
      log.innerHTML+=`<span class="${cls}">${step.ok?'✓':'✗'} ${step.msg}</span>\n`;
      prog.style.width=Math.round((i+1)/total*100)+'%';
      log.scrollTop=log.scrollHeight;
    });

    if(r.ok){
      prog.style.width='100%';
      log.innerHTML+='<span style="color:var(--warn)">★ Встановлення успішне!</span>';
      // Самовидалення
      post('delete_self',{}).catch(()=>{});
      setTimeout(()=>showSuccess(payload, r.db_admin_pass||'', payload.groq_api_key||''),900);
    } else {
      msg.innerHTML=infoBox('error',r.err||'Помилка встановлення');
      btn.disabled=false; sp.style.display='none';
      g('s5back').style.display='';
    }
  } catch(e){
    msg.innerHTML=infoBox('error',e.message);
    btn.disabled=false; sp.style.display='none';
    g('s5back').style.display='';
  }
}

function showSuccess(p, dbPass, groqKey){
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  g('stepsBar').style.display='none';
  g('s6').classList.add('active');
  const rows=[
    ['URL сайту',  `<a href="${location.origin}/" target="_blank">${location.origin}/</a>`],
    ['Адмінка',    `<a href="${location.origin}/admin/" target="_blank">${location.origin}/admin/</a>`],
    ['Логін',      p.admin_login],
    ['Пароль',     '(вказаний при встановленні)'],
    ['База даних', 'SQLite · data/BD/database.sqlite'],
    ['DB Manager', `<a href="${location.origin}/admin/SQLAdmin/phpadmin.php" target="_blank">/admin/SQLAdmin/phpadmin.php</a>`],
    ['Пароль DB',  dbPass ? `<code style="font-family:var(--mono);letter-spacing:.05em;color:var(--ok)">${dbPass}</code>` : '—'],
    ['GROQ API',   groqKey ? `✅ збережено` : `— <a href="${location.origin}/admin/ai_settings.php" target="_blank" style="font-size:.85em">додати пізніше</a>`],
    ['Версія CMS', '<?=FLY_CMS_VER?>'],
  ];
  g('finalCreds').innerHTML=rows
    .map(([k,v])=>`<div class="creds-row"><span class="creds-key">${k}</span><span class="creds-val">${v}</span></div>`)
    .join('');
  if(dbPass){
    g('finalCreds').insertAdjacentHTML('afterend',
      `<div class="info-box warn" style="margin-top:1rem"><span>⚠</span><div>
        Збережіть пароль DB Manager зараз — він більше <strong>ніде не відображається</strong>.
        Знайти його завжди можна у файлі <code style="font-family:var(--mono)">.env</code>
        (рядок <code style="font-family:var(--mono)">PHPLITEADMIN_PASSWORD=...</code>).
      </div></div>`
    );
  }
  window.scrollTo({top:0,behavior:'smooth'});
}
</script>
</body>
</html>