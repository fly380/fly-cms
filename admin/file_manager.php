<?php
// admin/file_manager.php — Файловий менеджер з редактором та бекапом
// Доступ: тільки admin

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

$username = $_SESSION['username'] ?? 'admin';
$ROOT     = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

// ── Розширення що можна редагувати ────────────────────────────────
const EDITABLE_EXT = ['php','html','htm','css','js','json','txt','md','htaccess','env','xml','ini','svg'];
// Розширення що повністю заборонені (ніяких дій)
const BLOCKED_EXT  = ['exe','dll','bat','sh','bin','com','msi','ps1'];
// Директорії куди НЕ можна заходити
const BLOCKED_DIRS = ['data/BD'];

// ── Утиліти ───────────────────────────────────────────────────────
function safe_path(string $rel, string $root): string|false {
    // Нормалізуємо і переконуємось що шлях всередині ROOT
    $rel  = str_replace(['\\', '../', './'], ['/', '', ''], $rel);
    $rel  = ltrim($rel, '/');
    $full = realpath($root . '/' . $rel);
    if ($full === false) {
        // realpath повертає false для неіснуючих — будуємо вручну
        $full = $root . '/' . $rel;
        // Перевіряємо traversal
        $norm = str_replace('\\', '/', $full);
        $normRoot = str_replace('\\', '/', $root);
        if (strpos($norm, $normRoot) !== 0) return false;
        return $full;
    }
    $normFull = str_replace('\\', '/', $full);
    $normRoot = str_replace('\\', '/', $root);
    if (strpos($normFull, $normRoot) !== 0) return false;
    return $full;
}

function rel_path(string $full, string $root): string {
    $full = str_replace('\\', '/', $full);
    $root = str_replace('\\', '/', rtrim($root, '/'));
    return ltrim(substr($full, strlen($root)), '/');
}

function is_blocked_dir(string $rel): bool {
    foreach (BLOCKED_DIRS as $bd) {
        if (strpos(str_replace('\\','/',$rel), $bd) === 0) return true;
    }
    return false;
}

function fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' КБ';
    return round($bytes/1048576, 2) . ' МБ';
}

function get_ext(string $name): string {
    $dot = strrpos($name, '.');
    if ($dot === false) return strtolower($name); // .htaccess тощо
    return strtolower(substr($name, $dot + 1));
}

function is_editable(string $name): bool {
    $ext = get_ext($name);
    return in_array($ext, EDITABLE_EXT, true);
}

function is_blocked(string $name): bool {
    return in_array(get_ext($name), BLOCKED_EXT, true);
}

function is_image(string $name): bool {
    return in_array(get_ext($name), ['jpg','jpeg','png','gif','webp','svg','ico'], true);
}

function ext_icon(string $name): string {
    $e = get_ext($name);
    return match(true) {
        in_array($e,['php'])              => '🐘',
        in_array($e,['js'])               => '🟨',
        in_array($e,['css'])              => '🎨',
        in_array($e,['html','htm'])       => '🌐',
        in_array($e,['json'])             => '📋',
        in_array($e,['txt','md'])         => '📄',
        in_array($e,['jpg','jpeg','png','gif','webp','ico','svg']) => '🖼️',
        in_array($e,['zip','tar','gz'])   => '🗜️',
        in_array($e,['sqlite','sqlite3']) => '🗄️',
        in_array($e,['log'])              => '📜',
        default                           => '📁',
    };
}

// ── Backup helper ─────────────────────────────────────────────────
function create_backup(string $filePath, string $root): string|false {
    $rel     = rel_path($filePath, $root);
    $backDir = $root . '/data/backups/files/' . dirname($rel);
    if (!is_dir($backDir)) @mkdir($backDir, 0755, true);
    $ts   = date('Ymd_His');
    $name = basename($filePath) . '.' . $ts . '.bak';
    $dest = $backDir . '/' . $name;
    if (@copy($filePath, $dest)) return $dest;
    return false;
}

// ── Визначаємо поточну директорію ────────────────────────────────
$dirRel = trim($_GET['dir'] ?? '', '/\\');
$dirRel = str_replace(['../', './'], '', $dirRel);
$dirFull = $ROOT . ($dirRel ? '/' . $dirRel : '');

// Якщо шлях поза ROOT — скидаємо в корінь
if (strpos(str_replace('\\','/',realpath($dirFull) ?: $dirFull), str_replace('\\','/',$ROOT)) !== 0) {
    $dirRel  = '';
    $dirFull = $ROOT;
}

// ── JSON API (AJAX-запити) ─────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];

    // CSRF для всіх мутуючих дій
    $mutating = ['save','rename','delete','mkdir','upload'];
    if (in_array($action, $mutating)) {
        $tok = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($tok !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['ok'=>false,'error'=>'Невірний CSRF токен']); exit;
        }
    }

    // ── Читати файл ──────────────────────────────────────────────
    if ($action === 'read') {
        $f = safe_path($_GET['file'] ?? '', $ROOT);
        if (!$f || !is_file($f)) { echo json_encode(['ok'=>false,'error'=>'Файл не знайдено']); exit; }
        if (is_blocked(basename($f))) { echo json_encode(['ok'=>false,'error'=>'Тип файлу заблоковано']); exit; }
        if (!is_editable(basename($f))) { echo json_encode(['ok'=>false,'error'=>'Файл не редагується']); exit; }
        $sz = filesize($f);
        if ($sz > 512 * 1024) { echo json_encode(['ok'=>false,'error'=>'Файл завеликий для редактора (>512 КБ)']); exit; }
        echo json_encode(['ok'=>true,'content'=>file_get_contents($f),'size'=>$sz,'rel'=>rel_path($f,$ROOT)]); exit;
    }

    // ── Зберегти файл ─────────────────────────────────────────────
    if ($action === 'save') {
        $f = safe_path($_POST['file'] ?? '', $ROOT);
        if (!$f) { echo json_encode(['ok'=>false,'error'=>'Невірний шлях']); exit; }
        if (is_blocked(basename($f))) { echo json_encode(['ok'=>false,'error'=>'Тип файлу заблоковано']); exit; }
        $content = $_POST['content'] ?? '';
        $doBackup = ($_POST['backup'] ?? '1') === '1';

        $backupPath = null;
        if ($doBackup && is_file($f)) {
            $backupPath = create_backup($f, $ROOT);
        }

        if (file_put_contents($f, $content, LOCK_EX) === false) {
            echo json_encode(['ok'=>false,'error'=>'Не вдалося записати файл']); exit;
        }

        log_action("📝 Збережено файл: " . rel_path($f, $ROOT), $username);
        echo json_encode(['ok'=>true,'backup'=>$backupPath ? rel_path($backupPath,$ROOT) : null]); exit;
    }

    // ── Перейменувати ─────────────────────────────────────────────
    if ($action === 'rename') {
        $f    = safe_path($_POST['file'] ?? '', $ROOT);
        $newn = basename(trim($_POST['name'] ?? ''));
        if (!$f || !$newn || !file_exists($f)) { echo json_encode(['ok'=>false,'error'=>'Помилка']); exit; }
        if (is_blocked($newn)) { echo json_encode(['ok'=>false,'error'=>'Розширення заблоковано']); exit; }
        $dest = dirname($f) . '/' . $newn;
        if (file_exists($dest)) { echo json_encode(['ok'=>false,'error'=>'Вже існує']); exit; }
        if (!@rename($f, $dest)) { echo json_encode(['ok'=>false,'error'=>'Не вдалося перейменувати']); exit; }
        log_action("✏️ Перейменовано: " . basename($f) . " → " . $newn, $username);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Видалити файл / папку ─────────────────────────────────────
    if ($action === 'delete') {
        $f = safe_path($_POST['file'] ?? '', $ROOT);
        if (!$f || !file_exists($f)) { echo json_encode(['ok'=>false,'error'=>'Не знайдено']); exit; }
        // Не дозволяємо видаляти критичні файли
        $rel = rel_path($f, $ROOT);
        $critical = ['config.php','.env','.htaccess','data/BD/database.sqlite'];
        foreach ($critical as $c) {
            if ($rel === $c || str_ends_with(str_replace('\\','/',$rel), $c)) {
                echo json_encode(['ok'=>false,'error'=>'Цей файл захищено від видалення']); exit;
            }
        }
        if (is_dir($f)) {
            // Видаляємо тільки порожні директорії
            if (count(scandir($f)) > 2) { echo json_encode(['ok'=>false,'error'=>'Директорія не порожня']); exit; }
            if (!@rmdir($f)) { echo json_encode(['ok'=>false,'error'=>'Не вдалося видалити']); exit; }
        } else {
            // Бекап перед видаленням якщо редагований файл
            if (is_editable(basename($f))) {
                create_backup($f, $ROOT);
            }
            if (!@unlink($f)) { echo json_encode(['ok'=>false,'error'=>'Не вдалося видалити']); exit; }
        }
        log_action("🗑️ Видалено: " . $rel, $username);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Створити директорію ───────────────────────────────────────
    if ($action === 'mkdir') {
        $name    = basename(trim($_POST['name'] ?? ''));
        $baseDir = safe_path($_POST['dir'] ?? '', $ROOT);
        if (!$name || !$baseDir) { echo json_encode(['ok'=>false,'error'=>'Невірні параметри']); exit; }
        $newDir = $baseDir . '/' . $name;
        if (file_exists($newDir)) { echo json_encode(['ok'=>false,'error'=>'Вже існує']); exit; }
        if (!@mkdir($newDir, 0755, true)) { echo json_encode(['ok'=>false,'error'=>'Не вдалося створити']); exit; }
        log_action("📁 Створено директорію: " . rel_path($newDir,$ROOT), $username);
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Список бекапів ────────────────────────────────────────────
    if ($action === 'backups') {
        $f = safe_path($_GET['file'] ?? '', $ROOT);
        if (!$f) { echo json_encode(['ok'=>false,'error'=>'Файл не знайдено']); exit; }
        $rel     = rel_path($f, $ROOT);
        $backDir = $ROOT . '/data/backups/files/' . dirname($rel);
        $pattern = $backDir . '/' . basename($f) . '.*.bak';
        $files   = glob($pattern) ?: [];
        rsort($files);
        $list = array_map(fn($p) => [
            'path' => rel_path($p, $ROOT),
            'name' => basename($p),
            'size' => fmt_size(filesize($p)),
            'time' => filemtime($p),
        ], array_slice($files, 0, 20));
        echo json_encode(['ok'=>true,'backups'=>$list]); exit;
    }

    // ── Відновити бекап ───────────────────────────────────────────
    if ($action === 'restore') {
        $tok = $_POST['csrf'] ?? '';
        if ($tok !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
        }
        $bak  = safe_path($_POST['backup'] ?? '', $ROOT);
        $dest = safe_path($_POST['dest'] ?? '', $ROOT);
        if (!$bak || !$dest || !is_file($bak)) {
            echo json_encode(['ok'=>false,'error'=>'Файл не знайдено']); exit;
        }
        // Бекап поточного файлу перед відновленням
        if (is_file($dest)) create_backup($dest, $ROOT);
        if (!@copy($bak, $dest)) {
            echo json_encode(['ok'=>false,'error'=>'Не вдалося відновити']); exit;
        }
        log_action("♻️ Відновлено з бекапу: " . basename($bak) . " → " . rel_path($dest,$ROOT), $username);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Невідома дія']); exit;
}

// ── Завантаження файлу (upload) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    header('Content-Type: application/json; charset=utf-8');
    $tok = $_POST['csrf'] ?? '';
    if ($tok !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
    }
    $targetDir = safe_path($_POST['dir'] ?? $dirRel, $ROOT);
    if (!$targetDir || !is_dir($targetDir)) {
        echo json_encode(['ok'=>false,'error'=>'Директорія не знайдена']); exit;
    }
    $f    = $_FILES['upload_file'];
    $name = preg_replace('/[^\w.\-]/', '_', basename($f['name']));
    if (is_blocked($name)) {
        echo json_encode(['ok'=>false,'error'=>'Тип файлу заблоковано']); exit;
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        echo json_encode(['ok'=>false,'error'=>'Файл завеликий (максимум 10 МБ)']); exit;
    }
    $dest = $targetDir . '/' . $name;
    if (file_exists($dest)) {
        create_backup($dest, $ROOT); // бекап якщо перезаписуємо
    }
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        echo json_encode(['ok'=>false,'error'=>'Помилка завантаження']); exit;
    }
    log_action("⬆️ Завантажено файл: " . rel_path($dest,$ROOT), $username);
    echo json_encode(['ok'=>true,'name'=>$name,'size'=>fmt_size($f['size'])]); exit;
}

// ── CSRF ────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Читаємо вміст директорії для відображення ─────────────────────
$items = [];
if (is_dir($dirFull)) {
    $raw = scandir($dirFull);
    foreach ($raw as $name) {
        if ($name === '.') continue;
        $fullPath = $dirFull . '/' . $name;
        $relPath  = rel_path($fullPath, $ROOT);

        // Приховуємо захищені системні директорії
        if (is_dir($fullPath) && is_blocked_dir($relPath)) continue;

        $isDir = is_dir($fullPath);
        $items[] = [
            'name'    => $name,
            'rel'     => $relPath,
            'is_dir'  => $isDir,
            'size'    => $isDir ? '' : fmt_size(filesize($fullPath)),
            'mtime'   => filemtime($fullPath),
            'editable'=> !$isDir && is_editable($name),
            'blocked' => !$isDir && is_blocked($name),
            'image'   => !$isDir && is_image($name),
            'ext'     => $isDir ? '' : get_ext($name),
        ];
    }
    // Спочатку .. і папки, потім файли; все за алфавітом
    usort($items, function($a, $b) {
        if ($a['name'] === '..') return -1;
        if ($b['name'] === '..') return 1;
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
        return strcasecmp($a['name'], $b['name']);
    });
}

// Хлібні крихти
$breadcrumbs = [['name'=>'/', 'dir'=>'']];
if ($dirRel) {
    $parts = explode('/', $dirRel);
    $acc   = '';
    foreach ($parts as $p) {
        $acc .= ($acc ? '/' : '') . $p;
        $breadcrumbs[] = ['name'=>$p, 'dir'=>$acc];
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<title>Файловий менеджер — <?= htmlspecialchars($dirRel ?: '/') ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/../assets/css/menu.css" rel="stylesheet">
<!-- CodeMirror -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/theme/dracula.min.css">
<style>
:root {
  --fm-bg: #f4f6f9;
  --fm-border: #dee2e6;
  --fm-hover: #e9f0ff;
  --fm-selected: #cfe2ff;
}
body { background: var(--fm-bg); }

/* ── Файловий менеджер ── */
#fm-container { display:flex; gap:0; height:calc(100vh - 120px); min-height:500px; }
#fm-tree      { width:220px; flex-shrink:0; background:#fff; border-right:1px solid var(--fm-border);
                overflow-y:auto; padding:8px 0; }
#fm-tree a    { display:block; padding:4px 12px; font-size:.82rem; color:#333; text-decoration:none;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#fm-tree a:hover { background:var(--fm-hover); }
#fm-tree a.active { background:var(--fm-selected); font-weight:600; }

#fm-main      { flex:1; display:flex; flex-direction:column; overflow:hidden; }
#fm-toolbar   { background:#fff; border-bottom:1px solid var(--fm-border);
                padding:6px 12px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
#fm-list      { flex:1; overflow-y:auto; padding:0; }

#fm-table     { width:100%; font-size:.85rem; border-collapse:collapse; }
#fm-table th  { background:#fff; position:sticky; top:0; z-index:2;
                padding:8px 12px; font-weight:600; border-bottom:2px solid var(--fm-border);
                white-space:nowrap; cursor:pointer; user-select:none; }
#fm-table td  { padding:6px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
#fm-table tr:hover td { background:var(--fm-hover); }
#fm-table tr.selected td { background:var(--fm-selected); }
.fm-name      { cursor:pointer; display:flex; align-items:center; gap:6px; }
.fm-name:hover .fn-text { text-decoration:underline; }
.fm-actions   { white-space:nowrap; }
.fm-actions button { padding:2px 7px; font-size:.78rem; }

/* ── Редактор ── */
#editor-panel { display:none; flex-direction:column; height:100%; }
#editor-header{ background:#1e2024; color:#adb5bd; padding:8px 14px;
                display:flex; align-items:center; gap:10px; border-bottom:1px solid #333; }
#editor-header .path { flex:1; font-family:monospace; font-size:.85rem; color:#7ecfff; }
.CodeMirror   { height:100% !important; font-size:13.5px; font-family:'JetBrains Mono','Fira Code',monospace; }
.CodeMirror-scroll { height:100%; }
#editor-wrap  { flex:1; overflow:hidden; }

/* ── Статусбар ── */
#fm-status    { background:#fff; border-top:1px solid var(--fm-border);
                padding:4px 12px; font-size:.78rem; color:#666;
                display:flex; align-items:center; gap:16px; }
.status-indicator { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:4px; }
.status-saved   { background:#28a745; }
.status-unsaved { background:#ffc107; }
.status-error   { background:#dc3545; }

/* ── Модалки ── */
.modal-header.danger { background:#dc3545; color:#fff; }
.modal-header.info   { background:#0d6efd; color:#fff; }
.backup-item { font-size:.82rem; cursor:pointer; padding:6px 10px; border-radius:6px; }
.backup-item:hover { background:var(--fm-hover); }

/* ── Toast ── */
#toast-wrap   { position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.fm-toast     { min-width:260px; padding:10px 16px; border-radius:8px; font-size:.85rem;
                box-shadow:0 4px 12px rgba(0,0,0,.2); animation:slideIn .2s ease; }
.fm-toast.success { background:#198754; color:#fff; }
.fm-toast.error   { background:#dc3545; color:#fff; }
.fm-toast.info    { background:#0d6efd; color:#fff; }
@keyframes slideIn { from{transform:translateX(40px);opacity:0} to{transform:none;opacity:1} }

/* ── Drag'n'drop upload zone ── */
#drop-overlay { display:none; position:fixed; inset:0; background:rgba(13,110,253,.15);
                z-index:1000; pointer-events:none; border:4px dashed #0d6efd;
                align-items:center; justify-content:center; font-size:1.5rem;
                color:#0d6efd; font-weight:700; }
#drop-overlay.active { display:flex; }
</style>
</head>
<body>
<?php
$page_title = '📂 Файловий менеджер';
require_once __DIR__ . '/../admin/admin_template_top.php';
?>

<div id="drop-overlay">⬆️ Відпусти для завантаження</div>
<div id="toast-wrap"></div>

<!-- Breadcrumbs + назва -->
<div class="d-flex align-items-center justify-content-between mb-2 px-3 pt-2">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
      <?php foreach ($breadcrumbs as $i => $bc): ?>
      <li class="breadcrumb-item <?= $i === count($breadcrumbs)-1 ? 'active' : '' ?>">
        <?php if ($i < count($breadcrumbs)-1): ?>
          <a href="?dir=<?= urlencode($bc['dir']) ?>"><?= htmlspecialchars($bc['name']) ?></a>
        <?php else: ?>
          <?= htmlspecialchars($bc['name']) ?>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </nav>
  <small class="text-muted"><?= htmlspecialchars($ROOT) ?></small>
</div>

<!-- Головний контейнер -->
<div id="fm-container" class="mx-3 mb-3 border rounded overflow-hidden bg-white">

  <!-- Дерево (ліва панель) буде заповнена JS -->
  <div id="fm-tree"><div class="text-muted px-3 py-2" style="font-size:.8rem">Завантаження...</div></div>

  <!-- Права панель: список файлів АБО редактор -->
  <div id="fm-main">
    <!-- Тулбар -->
    <div id="fm-toolbar">
      <button class="btn btn-sm btn-outline-secondary" onclick="refreshDir()" title="Оновити"><i class="bi bi-arrow-clockwise"></i></button>
      <button class="btn btn-sm btn-outline-primary" onclick="showMkdirModal()" title="Нова папка"><i class="bi bi-folder-plus"></i> Папка</button>
      <label class="btn btn-sm btn-outline-success mb-0" title="Завантажити файл">
        <i class="bi bi-upload"></i> Завантажити
        <input type="file" id="upload-input" multiple style="display:none" onchange="uploadFiles(this.files)">
      </label>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span id="sel-count" class="text-muted" style="font-size:.8rem"></span>
        <button id="btn-delete-sel" class="btn btn-sm btn-outline-danger d-none" onclick="deleteSelected()">
          <i class="bi bi-trash"></i> Видалити
        </button>
      </div>
    </div>

    <!-- Список файлів -->
    <div id="fm-list">
      <table id="fm-table">
        <thead>
          <tr>
            <th style="width:32px"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
            <th onclick="sortBy('name')">Ім'я <span id="sort-name">↑</span></th>
            <th onclick="sortBy('ext')" style="width:80px">Тип</th>
            <th onclick="sortBy('size')" style="width:90px">Розмір</th>
            <th onclick="sortBy('mtime')" style="width:130px">Змінено</th>
            <th style="width:130px">Дії</th>
          </tr>
        </thead>
        <tbody id="fm-tbody">
          <?php foreach ($items as $item): ?>
          <?php
            $enc  = htmlspecialchars($item['rel']);
            $encN = htmlspecialchars($item['name']);
          ?>
          <tr data-path="<?= $enc ?>" data-name="<?= $encN ?>"
              data-is-dir="<?= $item['is_dir']?'1':'0' ?>"
              data-editable="<?= $item['editable']?'1':'0' ?>"
              data-mtime="<?= $item['mtime'] ?>"
              data-size="<?= $item['size'] ?>"
              data-ext="<?= htmlspecialchars($item['ext']) ?>">
            <td><?php if($item['name']!=='..'): ?><input type="checkbox" class="row-chk" onchange="updateSel()"></td><?php else: ?></td><?php endif; ?>
            <td>
              <span class="fm-name" onclick="<?= $item['is_dir'] ? "navDir('".addslashes($item['rel'])."')" : ($item['editable'] ? "openEditor('".addslashes($item['rel'])."')" : "") ?>">
                <?php if ($item['is_dir']): ?>
                  <i class="bi bi-folder-fill text-warning"></i>
                <?php else: ?>
                  <span style="font-size:1.1em"><?= ext_icon($item['name']) ?></span>
                <?php endif; ?>
                <span class="fn-text"><?= $encN ?></span>
              </span>
            </td>
            <td><span class="badge bg-secondary bg-opacity-25 text-secondary"><?= $item['is_dir'] ? 'dir' : htmlspecialchars($item['ext']) ?></span></td>
            <td class="text-muted"><?= $item['size'] ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= $item['mtime'] ? date('d.m.y H:i', $item['mtime']) : '' ?></td>
            <td class="fm-actions">
              <?php if ($item['name'] !== '..'): ?>
              <button class="btn btn-sm btn-outline-secondary" title="Перейменувати" onclick="showRename('<?= $enc ?>', '<?= $encN ?>')"><i class="bi bi-pencil"></i></button>
              <?php if ($item['editable']): ?>
              <button class="btn btn-sm btn-outline-primary" title="Редагувати" onclick="openEditor('<?= addslashes($item['rel']) ?>')"><i class="bi bi-code-slash"></i></button>
              <button class="btn btn-sm btn-outline-info" title="Бекапи" onclick="showBackups('<?= addslashes($item['rel']) ?>')"><i class="bi bi-clock-history"></i></button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-danger" title="Видалити" onclick="confirmDelete('<?= $enc ?>', <?= $item['is_dir']?'true':'false' ?>)"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Редактор (прихований поки не відкрито файл) -->
    <div id="editor-panel">
      <div id="editor-header">
        <button class="btn btn-sm btn-secondary" onclick="closeEditor()"><i class="bi bi-arrow-left"></i></button>
        <span class="path" id="editor-path">—</span>
        <span id="editor-status-dot" class="status-indicator status-saved"></span>
        <button class="btn btn-sm btn-success" onclick="saveFile()" id="btn-save"><i class="bi bi-floppy"></i> Зберегти <kbd>Ctrl+S</kbd></button>
        <button class="btn btn-sm btn-outline-light" onclick="showBackups(currentFile)" title="Бекапи"><i class="bi bi-clock-history"></i></button>
      </div>
      <div id="editor-wrap"></div>
    </div>

    <!-- Статусбар -->
    <div id="fm-status">
      <span id="st-path">—</span>
      <span id="st-size"></span>
      <span id="st-cursor" class="ms-auto"></span>
    </div>
  </div>
</div>

<!-- Модалка: підтвердження видалення -->
<div class="modal fade" id="modal-delete" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header danger"><h6 class="modal-title mb-0">🗑️ Видалити?</h6></div>
      <div class="modal-body"><p id="del-msg" class="mb-0"></p></div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Скасувати</button>
        <button class="btn btn-sm btn-danger" id="btn-del-confirm" onclick="doDelete()">Видалити</button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка: перейменування -->
<div class="modal fade" id="modal-rename" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header info"><h6 class="modal-title mb-0 text-white">✏️ Перейменувати</h6></div>
      <div class="modal-body">
        <input type="text" id="rename-input" class="form-control" placeholder="Нове ім'я">
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Скасувати</button>
        <button class="btn btn-sm btn-primary" onclick="doRename()">Перейменувати</button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка: нова папка -->
<div class="modal fade" id="modal-mkdir" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header info"><h6 class="modal-title mb-0 text-white">📁 Нова папка</h6></div>
      <div class="modal-body">
        <input type="text" id="mkdir-input" class="form-control" placeholder="Назва папки">
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Скасувати</button>
        <button class="btn btn-sm btn-primary" onclick="doMkdir()">Створити</button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка: бекапи -->
<div class="modal fade" id="modal-backups" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title">🕐 Бекапи файлу: <span id="backup-file-name"></span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="backup-list-body"><div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- CodeMirror core + modes -->
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/php/php.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/css/css.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/clike/clike.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/closebrackets.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/selection/active-line.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/search/searchcursor.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/foldcode.min.js"></script>

<script>
const CSRF   = <?= json_encode($_SESSION['csrf_token']) ?>;
const CUR_DIR = <?= json_encode($dirRel) ?>;

let editor       = null;
let currentFile  = null;
let isDirty      = false;
let deleteTarget = null;
let renameTarget = null;
let sortField    = 'name';
let sortAsc      = true;

// ── Toast ─────────────────────────────────────────────────────────
function toast(msg, type='success', dur=3000) {
  const w = document.getElementById('toast-wrap');
  const d = document.createElement('div');
  d.className = `fm-toast ${type}`;
  d.textContent = msg;
  w.appendChild(d);
  setTimeout(() => d.remove(), dur);
}

// ── API helper ────────────────────────────────────────────────────
async function api(url, body=null) {
  const opts = { headers:{'X-CSRF-Token': CSRF} };
  if (body) {
    opts.method = 'POST';
    if (body instanceof FormData) {
      opts.body = body;
    } else {
      const fd = new FormData();
      fd.append('csrf', CSRF);
      for (const [k,v] of Object.entries(body)) fd.append(k,v);
      opts.body = fd;
    }
  }
  const r = await fetch(url, opts);
  return r.json();
}

// ── Навігація по директоріях ──────────────────────────────────────
function navDir(rel) {
  if (isDirty && !confirm('Є незбережені зміни. Перейти?')) return;
  window.location.href = '?dir=' + encodeURIComponent(rel);
}

function refreshDir() {
  window.location.reload();
}

// ── Сортування ────────────────────────────────────────────────────
function sortBy(field) {
  if (sortField === field) sortAsc = !sortAsc;
  else { sortField = field; sortAsc = true; }
  document.querySelectorAll('[id^=sort-]').forEach(e => e.textContent = '');
  const arrow = document.getElementById('sort-' + field);
  if (arrow) arrow.textContent = sortAsc ? '↑' : '↓';

  const tbody = document.getElementById('fm-tbody');
  const rows  = [...tbody.querySelectorAll('tr')];
  rows.sort((a,b) => {
    if (a.dataset.name === '..') return -1;
    if (b.dataset.name === '..') return 1;
    // Папки завжди вгорі
    if (a.dataset.isDir !== b.dataset.isDir) return b.dataset.isDir - a.dataset.isDir;
    let va = a.dataset[field] ?? '', vb = b.dataset[field] ?? '';
    if (field === 'mtime') { va = +va; vb = +vb; }
    const cmp = typeof va === 'number' ? va - vb : va.localeCompare(vb);
    return sortAsc ? cmp : -cmp;
  });
  rows.forEach(r => tbody.appendChild(r));
}

// ── Вибір файлів ──────────────────────────────────────────────────
function toggleAll(chk) {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = chk.checked);
  updateSel();
}
function updateSel() {
  const sel = document.querySelectorAll('.row-chk:checked').length;
  document.getElementById('sel-count').textContent = sel ? `Вибрано: ${sel}` : '';
  document.getElementById('btn-delete-sel').classList.toggle('d-none', !sel);
  document.getElementById('chk-all').checked = sel > 0 && sel === document.querySelectorAll('.row-chk').length;
}

// ── Видалення ─────────────────────────────────────────────────────
function confirmDelete(path, isDir) {
  deleteTarget = {path, isDir, multi: false};
  document.getElementById('del-msg').innerHTML = `Видалити <strong>${path.split('/').pop()}</strong>?` + (isDir ? '<br><small class="text-danger">Тільки порожні папки можна видалити</small>' : '');
  new bootstrap.Modal('#modal-delete').show();
}
function deleteSelected() {
  const sel = [...document.querySelectorAll('.row-chk:checked')].map(c => c.closest('tr').dataset.path);
  if (!sel.length) return;
  deleteTarget = {paths: sel, multi: true};
  document.getElementById('del-msg').innerHTML = `Видалити <strong>${sel.length}</strong> об'єкт(ів)?`;
  new bootstrap.Modal('#modal-delete').show();
}
async function doDelete() {
  bootstrap.Modal.getInstance('#modal-delete')?.hide();
  const targets = deleteTarget.multi ? deleteTarget.paths : [deleteTarget.path];
  let ok = 0, fail = 0;
  for (const p of targets) {
    const r = await api(`?action=delete`, {file: p});
    if (r.ok) ok++; else { fail++; toast('Помилка: ' + r.error, 'error'); }
  }
  if (ok) { toast(`Видалено: ${ok}`, 'success'); setTimeout(refreshDir, 800); }
}

// ── Перейменування ────────────────────────────────────────────────
function showRename(path, name) {
  renameTarget = path;
  document.getElementById('rename-input').value = name;
  new bootstrap.Modal('#modal-rename').show();
  setTimeout(() => document.getElementById('rename-input').select(), 300);
}
async function doRename() {
  const name = document.getElementById('rename-input').value.trim();
  if (!name) return;
  bootstrap.Modal.getInstance('#modal-rename')?.hide();
  const r = await api(`?action=rename`, {file: renameTarget, name});
  if (r.ok) { toast('Перейменовано'); setTimeout(refreshDir, 600); }
  else toast('Помилка: ' + r.error, 'error');
}

// ── Нова папка ────────────────────────────────────────────────────
function showMkdirModal() {
  document.getElementById('mkdir-input').value = '';
  new bootstrap.Modal('#modal-mkdir').show();
  setTimeout(() => document.getElementById('mkdir-input').focus(), 300);
}
async function doMkdir() {
  const name = document.getElementById('mkdir-input').value.trim();
  if (!name) return;
  bootstrap.Modal.getInstance('#modal-mkdir')?.hide();
  const r = await api(`?action=mkdir`, {dir: CUR_DIR, name});
  if (r.ok) { toast('Папку створено'); setTimeout(refreshDir, 600); }
  else toast('Помилка: ' + r.error, 'error');
}

// ── Завантаження файлів ───────────────────────────────────────────
async function uploadFiles(files) {
  for (const file of files) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('dir', CUR_DIR);
    fd.append('upload_file', file);
    const r = await api('?action=upload_placeholder', fd); // буде перехоплено PHP
    // насправді POST без action param
  }
}
// Реальний upload через форму (уникаємо action param)
document.getElementById('upload-input').addEventListener('change', async function() {
  for (const file of this.files) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('dir', CUR_DIR);
    fd.append('upload_file', file);
    const r = await fetch(window.location.href, {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) toast(`✅ ${j.name} (${j.size})`);
    else toast('Помилка: ' + j.error, 'error');
  }
  setTimeout(refreshDir, 800);
  this.value = '';
});

// ── Drag & Drop Upload ────────────────────────────────────────────
const overlay = document.getElementById('drop-overlay');
let dragCount = 0;
document.addEventListener('dragenter', e => { dragCount++; overlay.classList.add('active'); });
document.addEventListener('dragleave', e => { if(--dragCount<=0){dragCount=0; overlay.classList.remove('active');} });
document.addEventListener('dragover', e => e.preventDefault());
document.addEventListener('drop', async e => {
  e.preventDefault(); dragCount=0; overlay.classList.remove('active');
  const files = e.dataTransfer.files;
  for (const file of files) {
    const fd = new FormData();
    fd.append('csrf', CSRF); fd.append('dir', CUR_DIR); fd.append('upload_file', file);
    const r = await fetch(window.location.href, {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) toast(`✅ ${j.name}`); else toast('Помилка: ' + j.error, 'error');
  }
  setTimeout(refreshDir, 800);
});

// ── Редактор ──────────────────────────────────────────────────────
function modeForFile(path) {
  const ext = path.split('.').pop().toLowerCase();
  return {php:'php', js:'javascript', css:'css', html:'htmlmixed', htm:'htmlmixed',
          json:'javascript', xml:'xml', svg:'xml'}[ext] ?? 'null';
}

async function openEditor(path) {
  const r = await api(`?action=read&file=${encodeURIComponent(path)}`);
  if (!r.ok) { toast(r.error, 'error'); return; }

  currentFile = path;
  document.getElementById('editor-path').textContent = path;
  document.getElementById('st-path').textContent = path;
  document.getElementById('st-size').textContent = (r.size/1024).toFixed(1) + ' КБ';
  setDirty(false);

  document.getElementById('fm-list').style.display = 'none';
  document.getElementById('fm-toolbar').style.display = 'none';
  const panel = document.getElementById('editor-panel');
  panel.style.display = 'flex';

  const wrap = document.getElementById('editor-wrap');
  wrap.innerHTML = '';

  if (editor) { editor.toTextArea(); editor = null; }

  const ta = document.createElement('textarea');
  ta.id = 'editor-ta';
  wrap.appendChild(ta);
  ta.value = r.content;

  editor = CodeMirror.fromTextArea(ta, {
    mode: modeForFile(path),
    theme: 'dracula',
    lineNumbers: true,
    indentUnit: 4,
    tabSize: 4,
    indentWithTabs: false,
    autoCloseBrackets: true,
    matchBrackets: true,
    styleActiveLine: true,
    lineWrapping: false,
    extraKeys: {
      'Ctrl-S': saveFile,
      'Cmd-S':  saveFile,
    }
  });

  editor.on('change', () => setDirty(true));
  editor.on('cursorActivity', () => {
    const c = editor.getCursor();
    document.getElementById('st-cursor').textContent = `Рядок ${c.line+1}, Стовпець ${c.ch+1}`;
  });

  setTimeout(() => editor.refresh(), 50);
}

function setDirty(v) {
  isDirty = v;
  const dot = document.getElementById('editor-status-dot');
  dot.className = 'status-indicator ' + (v ? 'status-unsaved' : 'status-saved');
  document.getElementById('btn-save').classList.toggle('btn-success', !v);
  document.getElementById('btn-save').classList.toggle('btn-warning', v);
}

async function saveFile() {
  if (!currentFile || !editor) return;
  const content = editor.getValue();
  const r = await api(`?action=save`, {file: currentFile, content, backup: '1'});
  if (r.ok) {
    setDirty(false);
    toast('✅ Збережено' + (r.backup ? ` (бекап: ${r.backup.split('/').pop()})` : ''));
  } else {
    toast('Помилка збереження: ' + r.error, 'error');
  }
}

function closeEditor() {
  if (isDirty && !confirm('Є незбережені зміни. Закрити?')) return;
  document.getElementById('fm-list').style.display = '';
  document.getElementById('fm-toolbar').style.display = '';
  document.getElementById('editor-panel').style.display = 'none';
  if (editor) { editor.toTextArea(); editor = null; }
  currentFile = null; isDirty = false;
}

// ── Бекапи ────────────────────────────────────────────────────────
async function showBackups(path) {
  document.getElementById('backup-file-name').textContent = path.split('/').pop();
  document.getElementById('backup-list-body').innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
  new bootstrap.Modal('#modal-backups').show();

  const r = await api(`?action=backups&file=${encodeURIComponent(path)}`);
  const body = document.getElementById('backup-list-body');
  if (!r.ok || !r.backups.length) {
    body.innerHTML = '<p class="text-muted text-center py-3">Бекапів ще немає</p>';
    return;
  }
  body.innerHTML = r.backups.map(b => `
    <div class="backup-item d-flex align-items-center gap-2 border-bottom py-2">
      <i class="bi bi-clock text-info"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold" style="font-size:.82rem">${b.name}</div>
        <div class="text-muted" style="font-size:.75rem">${new Date(b.time*1000).toLocaleString('uk-UA')} · ${b.size}</div>
      </div>
      <button class="btn btn-sm btn-outline-success" onclick="restoreBackup('${b.path}','${path}')">
        <i class="bi bi-arrow-counterclockwise"></i> Відновити
      </button>
    </div>
  `).join('');
}

async function restoreBackup(backupPath, destPath) {
  if (!confirm(`Відновити ${backupPath.split('/').pop()} → ${destPath.split('/').pop()}?\nПоточний файл буде збережено як бекап.`)) return;
  bootstrap.Modal.getInstance('#modal-backups')?.hide();
  const r = await api(`?action=restore`, {backup: backupPath, dest: destPath});
  if (r.ok) {
    toast('✅ Файл відновлено');
    if (currentFile === destPath) {
      setTimeout(() => openEditor(destPath), 500);
    }
  } else toast('Помилка: ' + r.error, 'error');
}

// ── Клавіатурні скорочення ────────────────────────────────────────
document.addEventListener('keydown', e => {
  if ((e.ctrlKey||e.metaKey) && e.key==='s' && currentFile) {
    e.preventDefault(); saveFile();
  }
  if (e.key === 'Escape' && document.getElementById('editor-panel').style.display !== 'none') {
    closeEditor();
  }
});

// ── Попередження при закритті з незбереженими змінами ─────────────
window.addEventListener('beforeunload', e => {
  if (isDirty) { e.preventDefault(); e.returnValue = ''; }
});

// ── Навігація по дереву (завантажуємо асинхронно) ─────────────────
(async function loadTree() {
  const tree = document.getElementById('fm-tree');
  // Будуємо просте дерево з поточних breadcrumbs
  const bc = <?= json_encode($breadcrumbs) ?>;
  let html = '';
  bc.forEach(b => {
    const depth = b.dir.split('/').filter(Boolean).length;
    html += `<a href="?dir=${encodeURIComponent(b.dir)}" style="padding-left:${12+depth*12}px" class="${b.dir === <?= json_encode($dirRel) ?> ? 'active' : ''}">
      ${depth===0 ? '🏠' : '📁'} ${b.name}
    </a>`;
  });
  tree.innerHTML = html || '<div class="px-3 py-2 text-muted" style="font-size:.8rem">Корінь</div>';
})();

// Авто-відкриття редактора якщо переданий file= параметр
const urlFile = new URLSearchParams(window.location.search).get('file');
if (urlFile) setTimeout(() => openEditor(urlFile), 100);
</script>
</body>
</html>