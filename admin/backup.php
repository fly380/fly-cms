<?php
// admin/backup.php — Менеджер бекапів: БД + файли + автобекап
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
require_once __DIR__ . '/../config.php';

$username = $_SESSION['username'] ?? 'admin';
$ROOT     = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
$DB_PATH  = $ROOT . '/data/BD/database.sqlite';
$BACKUP_BASE = $ROOT . '/data/backups';
$DB_BACKUP_DIR   = $BACKUP_BASE . '/db';
$FILE_BACKUP_DIR = $BACKUP_BASE . '/files';

// Налаштування автобекапу (зберігаються в settings)
$pdo = connectToDatabase();

// ── Ініціалізація таблиці налаштувань автобекапу ──────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS backup_settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS backup_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL,
    filename   TEXT NOT NULL,
    size       INTEGER DEFAULT 0,
    created_by TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    note       TEXT DEFAULT ''
)");

// Гарантуємо директорії
foreach ([$BACKUP_BASE, $DB_BACKUP_DIR, $FILE_BACKUP_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

// ── Читаємо налаштування автобекапу ──────────────────────────────
function get_bset(PDO $pdo, string $k, string $def = ''): string {
    static $cache = [];
    if (!array_key_exists($k, $cache)) {
        $s = $pdo->prepare("SELECT value FROM backup_settings WHERE key=?");
        $s->execute([$k]);
        $r = $s->fetchColumn();
        $cache[$k] = $r !== false ? $r : $def;
    }
    return $cache[$k];
}
function set_bset(PDO $pdo, string $k, string $v): void {
    $pdo->prepare("INSERT INTO backup_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
        ->execute([$k,$v]);
}

// ── Helpers ───────────────────────────────────────────────────────
function fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' КБ';
    return round($bytes/1048576, 2) . ' МБ';
}

function zip_files(string $zipPath, string $sourceDir, array $excludeDirs = []): bool {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $sourceDir = rtrim(str_replace('\\','/',$sourceDir), '/');
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $file) {
        $filePath = str_replace('\\','/', $file->getRealPath());
        $relative = substr($filePath, strlen($sourceDir) + 1);

        // Виключаємо директорії/файли
        foreach ($excludeDirs as $ex) {
            if (strpos($relative, $ex) === 0) continue 2;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($filePath, $relative);
        }
    }
    return $zip->close();
}

// ── Створити бекап БД ─────────────────────────────────────────────
function create_db_backup(string $dbPath, string $backupDir, string $username, PDO $pdo): array {
    if (!file_exists($dbPath)) return ['ok'=>false,'error'=>'БД не знайдено'];

    $ts   = date('Ymd_His');
    $name = 'database_' . $ts . '.sqlite';
    $dest = $backupDir . '/' . $name;

    // Для SQLite найбезпечніший метод — SQLite3::backup() або просто copy()
    // якщо є відкриті транзакції — WAL checkpoint спочатку
    try {
        $src = new PDO('sqlite:' . $dbPath);
        $src->exec('PRAGMA wal_checkpoint(FULL)');
    } catch(Exception $e) {}

    if (!@copy($dbPath, $dest)) return ['ok'=>false,'error'=>'Не вдалося скопіювати БД'];

    $size = filesize($dest);
    $pdo->prepare("INSERT INTO backup_log(type,filename,size,created_by,note) VALUES('db',?,?,?,'Ручний бекап')")
        ->execute([$name, $size, $username]);
    log_action("💾 Бекап БД: $name (" . fmt_size($size) . ")", $username);

    return ['ok'=>true,'name'=>$name,'size'=>fmt_size($size),'path'=>$dest];
}

// ── Створити ZIP-бекап файлів ─────────────────────────────────────
function create_files_backup(string $root, string $backupDir, string $username, PDO $pdo, array $options = []): array {
    if (!class_exists('ZipArchive')) return ['ok'=>false,'error'=>'ZipArchive не доступний'];

    $ts      = date('Ymd_His');
    $scope   = $options['scope'] ?? 'full';
    $name    = "files_{$scope}_{$ts}.zip";
    $dest    = $backupDir . '/' . $name;

    $excludes = ['data/backups', 'data/locks', 'data/BD', '.git',
                 'node_modules', 'vendor', 'assets/tinymce'];

    $sourceDir = $root;
    if ($scope === 'uploads') {
        $sourceDir = $root . '/uploads';
        $name = "uploads_{$ts}.zip";
        $dest = $backupDir . '/' . $name;
        $excludes = [];
    } elseif ($scope === 'admin') {
        $sourceDir = $root . '/admin';
        $name = "admin_{$ts}.zip";
        $dest = $backupDir . '/' . $name;
        $excludes = ['SQLAdmin'];
    }

    if (!is_dir($sourceDir)) return ['ok'=>false,'error'=>'Директорія не знайдена'];

    if (!zip_files($dest, $sourceDir, $excludes)) return ['ok'=>false,'error'=>'Не вдалося створити ZIP'];

    $size = filesize($dest);
    $pdo->prepare("INSERT INTO backup_log(type,filename,size,created_by,note) VALUES('files',?,?,?,?)")
        ->execute([$name, $size, $username, "Бекап файлів ($scope)"]);
    log_action("📦 Бекап файлів ($scope): $name (" . fmt_size($size) . ")", $username);

    return ['ok'=>true,'name'=>$name,'size'=>fmt_size($size)];
}

// ── Читаємо список існуючих бекапів ──────────────────────────────
function get_backups(string $dir, string $type): array {
    $files = array_merge(glob($dir . '/*.sqlite') ?: [], glob($dir . '/*.zip') ?: []);
    rsort($files);
    return array_map(fn($f) => [
        'name'  => basename($f),
        'path'  => $f,
        'size'  => fmt_size(filesize($f)),
        'bytes' => filesize($f),
        'time'  => filemtime($f),
        'type'  => $type,
    ], array_slice($files, 0, 50));
}

// ── Ротація бекапів: видаляємо старіші за $keep штук ─────────────
function rotate_backups(string $dir, string $pattern, int $keep): int {
    $files = glob($dir . '/' . $pattern) ?: [];
    rsort($files); // новіші першими
    $deleted = 0;
    foreach (array_slice($files, $keep) as $f) {
        if (@unlink($f)) $deleted++;
    }
    return $deleted;
}

// ── Перевірка автобекапу (чи потрібно запустити) ─────────────────
function check_auto_backup(PDO $pdo, string $dbPath, string $dbBackupDir, string $fileBackupDir, string $root): void {
    $enabled = get_bset($pdo, 'auto_enabled', '0');
    if ($enabled !== '1') return;

    $interval = (int)get_bset($pdo, 'auto_interval_hours', '24');
    $lastRun  = (int)get_bset($pdo, 'auto_last_run', '0');
    $now      = time();

    if ($now - $lastRun < $interval * 3600) return;

    // Час настав — виконуємо автобекап
    $keep = (int)get_bset($pdo, 'auto_keep', '7');

    // БД
    $r = create_db_backup($dbPath, $dbBackupDir, 'auto', $pdo);
    if ($r['ok']) {
        rotate_backups($dbBackupDir, 'database_*.sqlite', $keep);
        $pdo->prepare("UPDATE backup_log SET note='Автобекап' WHERE filename=?")
            ->execute([$r['name']]);
    }

    // Файли якщо увімкнено
    if (get_bset($pdo, 'auto_files', '0') === '1') {
        $rf = create_files_backup($root, $fileBackupDir, 'auto', $pdo, ['scope'=>'admin']);
        if ($rf['ok']) rotate_backups($fileBackupDir, 'admin_*.zip', $keep);
    }

    set_bset($pdo, 'auto_last_run', (string)$now);
}

// Запускаємо перевірку автобекапу при кожному відкритті сторінки
check_auto_backup($pdo, $DB_PATH, $DB_BACKUP_DIR, $FILE_BACKUP_DIR, $ROOT);

// ── CSRF ──────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── JSON API ──────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // CSRF перевірка для мутуючих дій
    $mutating = ['backup_db','backup_files','delete_backup','save_settings','restore_db'];
    if (in_array($action, $mutating)) {
        $tok = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($tok !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
        }
    }

    if ($action === 'backup_db') {
        $r = create_db_backup($DB_PATH, $DB_BACKUP_DIR, $username, $pdo);
        echo json_encode($r); exit;
    }

    if ($action === 'backup_files') {
        $scope = $_POST['scope'] ?? 'admin';
        $r = create_files_backup($ROOT, $FILE_BACKUP_DIR, $username, $pdo, ['scope'=>$scope]);
        echo json_encode($r); exit;
    }

    if ($action === 'delete_backup') {
        $name = basename($_POST['name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Немає імені']); exit; }
        $f = $DB_BACKUP_DIR . '/' . $name;
        if (!file_exists($f)) $f = $FILE_BACKUP_DIR . '/' . $name;
        if (!file_exists($f)) { echo json_encode(['ok'=>false,'error'=>'Файл не знайдено']); exit; }
        @unlink($f);
        $pdo->prepare("DELETE FROM backup_log WHERE filename=?")->execute([$name]);
        log_action("🗑️ Видалено бекап: $name", $username);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'restore_db') {
        $name = basename($_POST['name'] ?? '');
        $f = $DB_BACKUP_DIR . '/' . $name;
        if (!$f || !file_exists($f)) { echo json_encode(['ok'=>false,'error'=>'Файл не знайдено']); exit; }
        // Бекап поточної БД перед відновленням
        $cur = create_db_backup($DB_PATH, $DB_BACKUP_DIR, $username, $pdo);
        // Копіюємо бекап на місце БД
        if (!@copy($f, $DB_PATH)) { echo json_encode(['ok'=>false,'error'=>'Не вдалося відновити']); exit; }
        log_action("♻️ Відновлено БД з: $name", $username);
        echo json_encode(['ok'=>true,'backup_before'=>$cur['name']??null]); exit;
    }

    if ($action === 'save_settings') {
        $fields = ['auto_enabled','auto_interval_hours','auto_keep','auto_files'];
        foreach ($fields as $f) {
            set_bset($pdo, $f, $_POST[$f] ?? '0');
        }
        log_action("⚙️ Оновлено налаштування автобекапу", $username);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'download') {
        $name = basename($_GET['name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Немає імені']); exit; }
        $f = $DB_BACKUP_DIR . '/' . $name;
        if (!file_exists($f)) $f = $FILE_BACKUP_DIR . '/' . $name;
        if (!file_exists($f)) { http_response_code(404); echo 'Not found'; exit; }
        // Скасовуємо JSON Content-Type
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Невідома дія']); exit;
}

// ── Дані для відображення ─────────────────────────────────────────
$dbBackups   = get_backups($DB_BACKUP_DIR, 'db');
$fileBackups = get_backups($FILE_BACKUP_DIR, 'files');

// Дискова інформація
$dbSize      = file_exists($DB_PATH) ? fmt_size(filesize($DB_PATH)) : '—';
$backupDiskUsage = 0;
foreach (array_merge($dbBackups, $fileBackups) as $b) {
    $backupDiskUsage += $b['bytes'];
}

// Налаштування автобекапу
$autoEnabled  = get_bset($pdo, 'auto_enabled', '0');
$autoInterval = get_bset($pdo, 'auto_interval_hours', '24');
$autoKeep     = get_bset($pdo, 'auto_keep', '7');
$autoFiles    = get_bset($pdo, 'auto_files', '0');
$autoLastRun  = (int)get_bset($pdo, 'auto_last_run', '0');

// Лог останніх бекапів
$recentLog = $pdo->query("SELECT * FROM backup_log ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<title>Менеджер бекапів</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/../assets/css/menu.css" rel="stylesheet">
<style>
.backup-card  { border-left:4px solid #0d6efd; transition:.2s; }
.backup-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.backup-card.db   { border-left-color:#198754; }
.backup-card.files{ border-left-color:#fd7e14; }
.status-badge { font-size:.72rem; padding:2px 8px; border-radius:20px; }
.auto-active  { background:#d1f5e0; color:#0a5c2f; }
.auto-inactive{ background:#f8d7da; color:#58151c; }
.progress-ring { transform:rotate(-90deg); }
.toast-wrap   { position:fixed; bottom:20px; right:20px; z-index:9999; }
.next-backup  { font-size:.8rem; color:#666; }
</style>
</head>
<body>
<?php
$page_title = '💾 Менеджер бекапів';
require_once __DIR__ . '/../admin/admin_template_top.php';
?>

<div class="container-fluid py-3 px-4">

  <!-- ── Заголовок + швидкі дії ──────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-0">💾 Менеджер бекапів</h4>
      <small class="text-muted">БД: <strong><?= $dbSize ?></strong> · Бекапи на диску: <strong><?= fmt_size($backupDiskUsage) ?></strong></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-success" onclick="backupDB()" id="btn-backup-db">
        <i class="bi bi-database-add"></i> Бекап БД зараз
      </button>
      <div class="dropdown">
        <button class="btn btn-warning dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-archive"></i> Бекап файлів
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" onclick="backupFiles('admin')">📁 Тільки admin/</a></li>
          <li><a class="dropdown-item" onclick="backupFiles('uploads')">🖼️ Тільки uploads/</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" onclick="backupFiles('full')">⚠️ Повний сайт (ZIP)</a></li>
        </ul>
      </div>
    </div>
  </div>

  <!-- ── Статусні картки ─────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card backup-card db h-100">
        <div class="card-body py-3">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-database fs-2 text-success"></i>
            <div>
              <div class="fw-bold"><?= count($dbBackups) ?></div>
              <div class="text-muted" style="font-size:.82rem">Бекапи БД</div>
            </div>
          </div>
          <?php if (!empty($dbBackups)): ?>
          <div class="mt-2 text-muted" style="font-size:.75rem">
            Останній: <?= date('d.m.y H:i', $dbBackups[0]['time']) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card backup-card files h-100">
        <div class="card-body py-3">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-file-zip fs-2 text-warning"></i>
            <div>
              <div class="fw-bold"><?= count($fileBackups) ?></div>
              <div class="text-muted" style="font-size:.82rem">Бекапи файлів</div>
            </div>
          </div>
          <?php if (!empty($fileBackups)): ?>
          <div class="mt-2 text-muted" style="font-size:.75rem">
            Останній: <?= date('d.m.y H:i', $fileBackups[0]['time']) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body py-3">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-clock-history fs-2 text-info"></i>
            <div>
              <div class="fw-bold">
                <span class="status-badge <?= $autoEnabled==='1' ? 'auto-active' : 'auto-inactive' ?>">
                  <?= $autoEnabled==='1' ? '✅ Увімкнено' : '⏸ Вимкнено' ?>
                </span>
              </div>
              <div class="text-muted" style="font-size:.82rem">Автобекап</div>
            </div>
          </div>
          <?php if ($autoEnabled==='1' && $autoLastRun): ?>
          <div class="mt-2 next-backup">
            Останній запуск: <?= date('d.m.y H:i', $autoLastRun) ?><br>
            Наступний: <?= date('d.m.y H:i', $autoLastRun + $autoInterval * 3600) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body py-3">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-hdd fs-2 text-secondary"></i>
            <div>
              <div class="fw-bold"><?= fmt_size($backupDiskUsage) ?></div>
              <div class="text-muted" style="font-size:.82rem">Зайнято бекапами</div>
            </div>
          </div>
          <div class="mt-2 text-muted" style="font-size:.75rem">
            Зберігати: <strong><?= $autoKeep ?></strong> останніх бекапів
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- ── Ліва колонка: бекапи БД ──────────────────────────── -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-database text-success me-2"></i><strong>Бекапи бази даних</strong></span>
          <button class="btn btn-sm btn-success" onclick="backupDB()"><i class="bi bi-plus"></i></button>
        </div>
        <div class="card-body p-0">
          <?php if (empty($dbBackups)): ?>
          <div class="text-center text-muted py-4">Бекапів ще немає</div>
          <?php else: ?>
          <div class="list-group list-group-flush" id="db-backup-list">
            <?php foreach ($dbBackups as $b): ?>
            <div class="list-group-item d-flex align-items-center gap-3 py-2" id="dbrow-<?= htmlspecialchars($b['name']) ?>">
              <i class="bi bi-database-fill text-success"></i>
              <div class="flex-grow-1">
                <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($b['name']) ?></div>
                <div class="text-muted" style="font-size:.75rem">
                  <?= date('d.m.Y H:i:s', $b['time']) ?> · <?= $b['size'] ?>
                </div>
              </div>
              <div class="d-flex gap-1">
                <a href="?action=download&name=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline-secondary" title="Завантажити">
                  <i class="bi bi-download"></i>
                </a>
                <button class="btn btn-sm btn-outline-success" title="Відновити БД з цього бекапу"
                        onclick="confirmRestore('<?= htmlspecialchars($b['name']) ?>')">
                  <i class="bi bi-arrow-counterclockwise"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" title="Видалити"
                        onclick="deleteBackup('<?= htmlspecialchars($b['name']) ?>', 'dbrow-<?= htmlspecialchars($b['name']) ?>')">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Права колонка: бекапи файлів ─────────────────────── -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-file-zip text-warning me-2"></i><strong>Бекапи файлів</strong></span>
          <div class="dropdown">
            <button class="btn btn-sm btn-warning dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" onclick="backupFiles('admin')">admin/</a></li>
              <li><a class="dropdown-item" onclick="backupFiles('uploads')">uploads/</a></li>
              <li><a class="dropdown-item text-danger" onclick="backupFiles('full')">Повний сайт</a></li>
            </ul>
          </div>
        </div>
        <div class="card-body p-0">
          <?php if (empty($fileBackups)): ?>
          <div class="text-center text-muted py-4">Бекапів ще немає</div>
          <?php else: ?>
          <div class="list-group list-group-flush" id="files-backup-list">
            <?php foreach ($fileBackups as $b): ?>
            <div class="list-group-item d-flex align-items-center gap-3 py-2" id="frow-<?= htmlspecialchars($b['name']) ?>">
              <i class="bi bi-file-zip-fill text-warning"></i>
              <div class="flex-grow-1">
                <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($b['name']) ?></div>
                <div class="text-muted" style="font-size:.75rem">
                  <?= date('d.m.Y H:i:s', $b['time']) ?> · <?= $b['size'] ?>
                </div>
              </div>
              <div class="d-flex gap-1">
                <a href="?action=download&name=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline-secondary" title="Завантажити">
                  <i class="bi bi-download"></i>
                </a>
                <button class="btn btn-sm btn-outline-danger" title="Видалити"
                        onclick="deleteBackup('<?= htmlspecialchars($b['name']) ?>', 'frow-<?= htmlspecialchars($b['name']) ?>')">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Налаштування автобекапу ───────────────────────────── -->
    <div class="col-lg-6">
      <div class="card border-info">
        <div class="card-header bg-info bg-opacity-10">
          <i class="bi bi-gear me-2"></i><strong>Налаштування автобекапу</strong>
        </div>
        <div class="card-body">
          <div class="mb-3 d-flex align-items-center justify-content-between">
            <label class="form-label mb-0 fw-semibold">Автобекап БД</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="auto_enabled"
                     <?= $autoEnabled==='1' ? 'checked' : '' ?> onchange="saveAutoSettings()">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Інтервал (години)</label>
            <select class="form-select form-select-sm" id="auto_interval_hours" onchange="saveAutoSettings()">
              <?php foreach ([1,3,6,12,24,48,72,168] as $h): ?>
              <option value="<?= $h ?>" <?= $autoInterval == $h ? 'selected' : '' ?>>
                <?= $h < 24 ? "Кожні $h год." : "Кожні " . ($h/24) . " дн." ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Зберігати останніх</label>
            <select class="form-select form-select-sm" id="auto_keep" onchange="saveAutoSettings()">
              <?php foreach ([3,5,7,10,14,30] as $k): ?>
              <option value="<?= $k ?>" <?= $autoKeep == $k ? 'selected' : '' ?>><?= $k ?> бекапів</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="d-flex align-items-center justify-content-between">
            <label class="form-label mb-0">Також бекапити admin/ файли</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="auto_files"
                     <?= $autoFiles==='1' ? 'checked' : '' ?> onchange="saveAutoSettings()">
            </div>
          </div>
          <div class="mt-3 p-3 bg-light rounded" style="font-size:.8rem">
            <i class="bi bi-info-circle text-info me-1"></i>
            Автобекап запускається автоматично при відкритті цієї сторінки або сторінки адмінки (через <code>check_auto_backup()</code>). Для гарантованого розкладу додай Windows Task Scheduler або cron що відкриватиме <code>/admin/backup.php?auto=1</code>.
          </div>
        </div>
      </div>
    </div>

    <!-- ── Лог бекапів ───────────────────────────────────────── -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><i class="bi bi-list-ul me-2"></i><strong>Лог операцій</strong></div>
        <div class="card-body p-0">
          <div style="max-height:320px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
              <thead class="table-light sticky-top">
                <tr><th>Час</th><th>Тип</th><th>Файл</th><th>Розмір</th><th>Хто</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentLog as $l): ?>
                <tr>
                  <td class="text-muted" style="white-space:nowrap"><?= date('d.m H:i', strtotime($l['created_at'])) ?></td>
                  <td>
                    <?php if ($l['type']==='db'): ?>
                    <span class="badge bg-success">БД</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark">ZIP</span>
                    <?php endif; ?>
                  </td>
                  <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($l['filename']) ?>">
                    <?= htmlspecialchars($l['filename']) ?>
                  </td>
                  <td class="text-muted"><?= $l['size'] ? fmt_size($l['size']) : '—' ?></td>
                  <td class="text-muted"><?= htmlspecialchars($l['created_by']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentLog)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">Лог порожній</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Модалка: відновлення БД -->
<div class="modal fade" id="modal-restore" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h6 class="modal-title">⚠️ Відновити базу даних</h6>
      </div>
      <div class="modal-body">
        <p>Відновити з бекапу: <strong id="restore-name"></strong>?</p>
        <div class="alert alert-warning mb-0">
          <i class="bi bi-exclamation-triangle me-2"></i>
          Поточна БД буде автоматично збережена як бекап перед відновленням. Сайт продовжить роботу з відновленими даними.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
        <button class="btn btn-danger" id="btn-restore-confirm" onclick="doRestore()">Відновити</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-wrap" id="toast-wrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
let restoreTarget = null;

function toast(msg, type='success') {
  const w = document.getElementById('toast-wrap');
  const d = document.createElement('div');
  d.className = `toast show bg-${type==='success'?'success':type==='error'?'danger':'info'} text-white mb-2`;
  d.style.cssText = 'min-width:260px;padding:10px 16px;border-radius:8px;font-size:.85rem;box-shadow:0 4px 12px rgba(0,0,0,.2)';
  d.textContent = msg;
  w.appendChild(d);
  setTimeout(() => d.remove(), 4000);
}

async function post(action, body={}) {
  const fd = new FormData();
  fd.append('csrf', CSRF);
  for (const [k,v] of Object.entries(body)) fd.append(k,v);
  const r = await fetch(`?action=${action}`, {method:'POST', body:fd});
  return r.json();
}

async function backupDB() {
  const btn = document.getElementById('btn-backup-db');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Бекап...';
  const r = await post('backup_db');
  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-database-add"></i> Бекап БД зараз';
  if (r.ok) {
    toast(`✅ Бекап створено: ${r.name} (${r.size})`);
    setTimeout(() => location.reload(), 1200);
  } else {
    toast('Помилка: ' + r.error, 'error');
  }
}

async function backupFiles(scope) {
  toast(`⏳ Створення ZIP (${scope})...`, 'info');
  const r = await post('backup_files', {scope});
  if (r.ok) {
    toast(`✅ ${r.name} (${r.size})`);
    setTimeout(() => location.reload(), 1200);
  } else {
    toast('Помилка: ' + r.error, 'error');
  }
}

async function deleteBackup(name, rowId) {
  if (!confirm(`Видалити бекап ${name}?`)) return;
  const r = await post('delete_backup', {name});
  if (r.ok) {
    document.getElementById(rowId)?.remove();
    toast('Бекап видалено');
  } else toast('Помилка: ' + r.error, 'error');
}

function confirmRestore(name) {
  restoreTarget = name;
  document.getElementById('restore-name').textContent = name;
  new bootstrap.Modal('#modal-restore').show();
}

async function doRestore() {
  bootstrap.Modal.getInstance('#modal-restore')?.hide();
  toast('⏳ Відновлення БД...', 'info');
  const r = await post('restore_db', {name: restoreTarget});
  if (r.ok) {
    toast(`✅ БД відновлено. Бекап поточної: ${r.backup_before ?? '—'}`);
    setTimeout(() => location.reload(), 1500);
  } else toast('Помилка: ' + r.error, 'error');
}

async function saveAutoSettings() {
  const r = await post('save_settings', {
    auto_enabled:        document.getElementById('auto_enabled').checked ? '1' : '0',
    auto_interval_hours: document.getElementById('auto_interval_hours').value,
    auto_keep:           document.getElementById('auto_keep').value,
    auto_files:          document.getElementById('auto_files').checked ? '1' : '0',
  });
  if (r.ok) toast('⚙️ Налаштування збережено');
  else toast('Помилка: ' + r.error, 'error');
}
</script>
</body>
</html>