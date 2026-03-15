<?php
/**
 * admin/updater.php — Система оновлень fly-CMS з GitHub
 *
 * Як налаштувати:
 *   GITHUB_OWNER=your-username    ← у .env
 *   GITHUB_REPO=fly-cms           ← у .env
 *   GITHUB_TOKEN=ghp_xxx          ← необов'язково, для приватних репо
 *
 * Що робить:
 *   1. Перевіряє останній реліз на GitHub API
 *   2. Порівнює з поточною версією з БД (settings.cms_version)
 *   3. Завантажує ZIP архів релізу
 *   4. Розпаковує в тимчасову папку
 *   5. Копіює файли (крім захищених: .env, data/, uploads/, config.php)
 *   6. Зберігає лог оновлення
 *
 * Доступ: тільки superadmin
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';

fly_send_security_headers();

$pdo      = connectToDatabase();
$username = $_SESSION['username'] ?? 'admin';
$ROOT     = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

// ─── CSRF ─────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_updater'])) {
    $_SESSION['csrf_updater'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_updater'];

// ─── Конфіг GitHub ────────────────────────────────────────────────
$ghOwner = env('GITHUB_OWNER', '');
$ghRepo  = env('GITHUB_REPO',  '');
$ghToken = env('GITHUB_TOKEN', '');

$configured = $ghOwner && $ghRepo;

// ─── Поточна версія ───────────────────────────────────────────────
$currentVersion = get_setting('cms_version') ?: '0.0.0';

// ─── Захищені шляхи — ніколи не перезаписуємо ────────────────────
$protectedPaths = [
    '.env',
    'data/',
    'uploads/',
    'config.php',
    'web.config',
    '.htaccess',
];

// ─── Хелпер: GitHub API запит ─────────────────────────────────────
function github_request(string $url, string $token = ''): array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 15,
        'header'  => implode("\r\n", array_filter([
            'User-Agent: fly-CMS-Updater/1.0',
            'Accept: application/vnd.github.v3+json',
            $token ? "Authorization: Bearer {$token}" : '',
        ])),
        'ignore_errors' => true,
    ]]);

    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
        $code = (int)($m[1] ?? 0);
    }

    if ($body === false || $code === 0) {
        return ['ok' => false, 'error' => "Не вдалося підключитися до GitHub API ($url)", 'data' => null, 'code' => 0];
    }
    $data = json_decode($body, true);
    if ($code >= 400) {
        $msg = $data['message'] ?? "HTTP $code";
        return ['ok' => false, 'error' => "GitHub API: $msg", 'data' => $data, 'code' => $code];
    }
    return ['ok' => true, 'error' => '', 'data' => $data, 'code' => $code];
}

// ─── Хелпер: завантажити файл ─────────────────────────────────────
function download_file(string $url, string $dest, string $token = ''): bool {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 60,
        'header'  => implode("\r\n", array_filter([
            'User-Agent: fly-CMS-Updater/1.0',
            $token ? "Authorization: Bearer {$token}" : '',
        ])),
        'follow_location' => true,
        'max_redirects'   => 5,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) return false;
    return file_put_contents($dest, $data) !== false;
}

// ─── Хелпер: рекурсивне видалення тимчасової папки ───────────────
function rm_rf(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

// ─── Хелпер: рекурсивне копіювання ───────────────────────────────
function copy_dir(string $src, string $dst, array $protected, string $root, array &$log): void {
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = ltrim(str_replace($src, '', $item->getPathname()), '/\\');
        // Перевірити захищений шлях
        $skip = false;
        foreach ($protected as $p) {
            if (str_starts_with($rel, $p) || $rel === rtrim($p, '/')) {
                $skip = true; break;
            }
        }
        if ($skip) { $log[] = ['skip', $rel]; continue; }

        $target = $dst . DIRECTORY_SEPARATOR . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            copy($item->getPathname(), $target);
            $log[] = ['copy', $rel];
        }
    }
}

// ─── Хелпер: str_starts_with для PHP < 8 ─────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
}

// ─── Перевірка вимог ──────────────────────────────────────────────
$hasZip        = class_exists('ZipArchive');
$hasFileGet    = function_exists('file_get_contents');
$hasUrlFopen   = (bool)ini_get('allow_url_fopen');
$hasWrite      = is_writable($ROOT);

$requirements = [
    'ZipArchive (php_zip)'  => $hasZip,
    'file_get_contents'     => $hasFileGet,
    'allow_url_fopen'       => $hasUrlFopen,
    'Запис у ' . $ROOT      => $hasWrite,
];
$reqOk = !in_array(false, $requirements, true);
// PharData як fallback для zip якщо немає ZipArchive
$canUpdate = $reqOk || ($hasPhar && $hasFileGet && $hasUrlFopen && $hasWrite);

// ─── Знайти php.ini ───────────────────────────────────────────────
$phpIniPath    = php_ini_loaded_file();
$phpIniWritable= $phpIniPath && is_writable($phpIniPath);
$phpExtDir     = ini_get('extension_dir');

// Знайти розташування php_zip.dll / zip.so
$extFile = '';
if ($phpExtDir) {
    foreach (['php_zip.dll', 'zip.dll', 'zip.so'] as $name) {
        if (file_exists($phpExtDir . DIRECTORY_SEPARATOR . $name)) {
            $extFile = $name; break;
        }
    }
}

// Альтернатива: PharData (вбудований, без php_zip)
$hasPhar = class_exists('PharData');

// ─── Спроба увімкнути через php.ini (POST) ───────────────────────
$iniMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'enable_zip'
    && ($_POST['csrf'] ?? '') === $csrf) {

    if ($phpIniWritable && $phpIniPath) {
        $iniContent = file_get_contents($phpIniPath);
        // Розкоментувати extension=php_zip або extension=zip
        $patterns = [
            '/^;?\s*extension\s*=\s*php_zip\.dll/mi',
            '/^;?\s*extension\s*=\s*zip\.dll/mi',
            '/^;?\s*extension\s*=\s*zip/mi',
        ];
        $replaced = false;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $iniContent)) {
                $iniContent = preg_replace($pat, 'extension=php_zip.dll', $iniContent);
                $replaced = true; break;
            }
        }
        if (!$replaced && $extFile) {
            // Додати рядок в кінець
            $iniContent .= "
extension=" . $extFile . "
";
            $replaced = true;
        }
        if ($replaced && file_put_contents($phpIniPath, $iniContent)) {
            $iniMsg = 'ok';
        } else {
            $iniMsg = 'write_fail';
        }
    } else {
        $iniMsg = 'not_writable';
    }
}

// ─── AJAX / POST дії ──────────────────────────────────────────────
$ajaxMode = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['ajax']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
    }
    $action = $_POST['action'] ?? '';

    // ── Перевірити оновлення ──────────────────────────────────────
    if ($action === 'check') {
        if (!$configured) {
            echo json_encode(['ok'=>false,'error'=>'GitHub не налаштовано. Додайте GITHUB_OWNER і GITHUB_REPO у .env']);
            exit;
        }
        $url = "https://api.github.com/repos/{$ghOwner}/{$ghRepo}/releases/latest";
        $res = github_request($url, $ghToken);
        if (!$res['ok']) {
            echo json_encode(['ok'=>false,'error'=>$res['error']]);
            exit;
        }
        $rel     = $res['data'];
        $latest  = ltrim($rel['tag_name'] ?? '0.0.0', 'v');
        $current = ltrim($currentVersion, 'v');
        $hasUpdate = version_compare($latest, $current, '>');

        echo json_encode([
            'ok'         => true,
            'latest'     => $latest,
            'current'    => $current,
            'has_update' => $hasUpdate,
            'name'       => $rel['name'] ?? $rel['tag_name'],
            'body'       => $rel['body'] ?? '',
            'published'  => isset($rel['published_at']) ? date('d.m.Y', strtotime($rel['published_at'])) : '',
            'zip_url'    => $rel['zipball_url'] ?? '',
            'assets'     => array_map(fn($a)=>['name'=>$a['name'],'url'=>$a['browser_download_url'],'size'=>$a['size']], $rel['assets'] ?? []),
        ]);
        exit;
    }

    // ── Застосувати оновлення ─────────────────────────────────────
    if ($action === 'update') {
        if (!$configured || !$canUpdate) {
            echo json_encode(['ok'=>false,'error'=>'Не виконані вимоги або GitHub не налаштовано']);
            exit;
        }

        $zipUrl  = trim($_POST['zip_url'] ?? '');
        $version = trim($_POST['version'] ?? '');
        if (!$zipUrl || !$version) {
            echo json_encode(['ok'=>false,'error'=>'Відсутній URL або версія']);
            exit;
        }

        $steps = [];
        $tmpDir = $ROOT . '/data/tmp_update_' . time();
        $zipPath = $tmpDir . '.zip';

        try {
            // 1. Бекап БД перед оновленням
            $steps[] = '📦 Створення бекапу БД...';
            $dbSrc  = $ROOT . '/data/BD/database.sqlite';
            $bakDir = $ROOT . '/data/backups/db';
            if (!is_dir($bakDir)) @mkdir($bakDir, 0755, true);
            $bakPath = $bakDir . '/pre_update_' . date('Ymd_His') . '.sqlite';
            if (file_exists($dbSrc)) {
                copy($dbSrc, $bakPath);
                $steps[] = '✅ Бекап: ' . basename($bakPath);
            }

            // 2. Завантажити ZIP
            $steps[] = '⬇ Завантаження ' . htmlspecialchars($version) . '...';
            if (!mkdir($tmpDir, 0755, true)) throw new Exception("Не вдалося створити $tmpDir");
            if (!download_file($zipUrl, $zipPath, $ghToken)) throw new Exception("Не вдалося завантажити ZIP");
            $steps[] = '✅ Завантажено: ' . round(filesize($zipPath)/1024) . ' КБ';

            // 3. Розпакувати
            $steps[] = '📂 Розпакування...';
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new Exception("Не вдалося відкрити ZIP");
            $zip->extractTo($tmpDir);
            $zip->close();
            unlink($zipPath);

            // GitHub архів містить папку виду "owner-repo-commit/"
            // Знаходимо її
            $extracted = glob($tmpDir . '/*', GLOB_ONLYDIR);
            $srcDir = !empty($extracted) ? $extracted[0] : $tmpDir;
            $steps[] = '✅ Розпаковано у: ' . basename($srcDir);

            // 4. Копіювати файли
            $steps[] = '📋 Копіювання файлів...';
            $copyLog = [];
            copy_dir($srcDir, $ROOT, $protectedPaths, $ROOT, $copyLog);
            $copied  = count(array_filter($copyLog, fn($l)=>$l[0]==='copy'));
            $skipped = count(array_filter($copyLog, fn($l)=>$l[0]==='skip'));
            $steps[] = "✅ Скопійовано: $copied файлів, пропущено: $skipped (захищені)";

            // 5. Оновити версію в БД
            $pdo->prepare("UPDATE settings SET value=? WHERE key='cms_version'")->execute([$version]);
            // якщо рядка немає — вставити
            $check = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE key='cms_version'");
            $check->execute();
            if ($check->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO settings(key,value) VALUES('cms_version',?)")->execute([$version]);
            }
            $steps[] = '✅ Версію оновлено до ' . $version;

            // 6. Очистити тимчасові файли
            rm_rf($tmpDir);
            $steps[] = '🧹 Тимчасові файли видалено';

            log_action($username, "Оновлення CMS до версії $version");

            echo json_encode(['ok'=>true,'steps'=>$steps,'version'=>$version]);

        } catch (Exception $e) {
            // Очистити при помилці
            if (file_exists($zipPath)) @unlink($zipPath);
            if (is_dir($tmpDir)) rm_rf($tmpDir);
            $steps[] = '❌ Помилка: ' . $e->getMessage();
            echo json_encode(['ok'=>false,'steps'=>$steps,'error'=>$e->getMessage()]);
        }
        exit;
    }
}

// ─── Список останніх релізів ──────────────────────────────────────
$releases = [];
if ($configured) {
    $url = "https://api.github.com/repos/{$ghOwner}/{$ghRepo}/releases?per_page=5";
    $res = github_request($url, $ghToken);
    if ($res['ok'] && is_array($res['data'])) {
        foreach ($res['data'] as $r) {
            $releases[] = [
                'tag'       => ltrim($r['tag_name'] ?? '', 'v'),
                'name'      => $r['name'] ?? $r['tag_name'],
                'date'      => isset($r['published_at']) ? date('d.m.Y', strtotime($r['published_at'])) : '',
                'body'      => $r['body'] ?? '',
                'zip_url'   => $r['zipball_url'] ?? '',
                'prerelease'=> !empty($r['prerelease']),
            ];
        }
    }
}

// ─── Рендер ───────────────────────────────────────────────────────
$page_title = '🔄 Оновлення CMS';
ob_start();
?>
<style>
.upd-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:1rem; }
.upd-head { background:linear-gradient(135deg,#1a3d6e,#2E5FA3); color:#fff; padding:1rem 1.5rem; }
.upd-body { padding:1.25rem 1.5rem; }
.upd-body + .upd-body { border-top:1px solid #f3f4f6; }

.req-row  { display:flex; align-items:center; gap:.6rem; padding:.3rem 0; font-size:.87rem; }
.req-ok   { color:#16a34a; font-weight:600; }
.req-fail { color:#dc2626; font-weight:600; }

.ver-badge { display:inline-block; font-family:monospace; font-size:.85rem; font-weight:700;
    background:#e0e7ff; color:#1e3a6e; border-radius:6px; padding:.2rem .7rem; }
.ver-badge.new { background:#dcfce7; color:#166534; }
.ver-badge.cur { background:#f3f4f6; color:#374151; }

.release-item { border:1px solid #e5e7eb; border-radius:8px; padding:1rem; margin-bottom:.75rem; }
.release-item.latest { border-color:#2E5FA3; }
.release-notes { font-size:.82rem; color:#6b7280; white-space:pre-wrap; max-height:120px; overflow-y:auto;
    background:#f8faff; border-radius:6px; padding:.6rem .8rem; margin-top:.5rem; font-family:monospace; }

.upd-log { font-family:monospace; font-size:.82rem; background:#0d1117; color:#4ade80;
    border-radius:8px; padding:1rem; line-height:1.9; max-height:280px; overflow-y:auto; }
.upd-log .err { color:#f85149; }
.upd-log .warn { color:#f59e0b; }

.config-warn { background:#fff8e1; border:1px solid #f59e0b; border-radius:8px;
    padding:.75rem 1rem; font-size:.85rem; color:#92400e; }
.config-warn code { background:rgba(0,0,0,.07); padding:1px 5px; border-radius:3px; }

.protected-list { font-size:.8rem; font-family:monospace; color:#6b7280; display:flex; flex-wrap:wrap; gap:.3rem .8rem; }
.protected-list span { background:#f3f4f6; padding:.1rem .5rem; border-radius:4px; }
</style>

<div class="container-fluid" style="max-width:860px;padding:1.5rem">



<!-- ── Статус версії ─────────────────────────────────────── -->
<div class="upd-card">
  <div class="upd-head d-flex align-items-center gap-2">
    <span style="font-size:1.3rem">🔄</span>
    <div>
      <div style="font-weight:700">Оновлення fly-CMS</div>
      <div style="font-size:.78rem;opacity:.8">
        <?= $configured ? "Репозиторій: {$ghOwner}/{$ghRepo}" : 'GitHub ' ?>
      </div>
    </div>
  </div>

  <div class="upd-body">
    <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
      <div>
        <div class="text-muted" style="font-size:.75rem;margin-bottom:3px">Поточна версія</div>
        <span class="ver-badge cur"><?= htmlspecialchars($currentVersion) ?></span>
      </div>
      <div id="latestVerWrap" style="display:none">
        <div class="text-muted" style="font-size:.75rem;margin-bottom:3px">Остання версія</div>
        <span class="ver-badge new" id="latestVer">—</span>
      </div>
      <div class="ms-auto">
        <button class="btn btn-primary" id="btnCheck" onclick="checkUpdate()"
          <?= !$configured ? 'disabled' : '' ?>>
          🔍 Перевірити оновлення
        </button>
      </div>
    </div>

    <div id="updateResult" style="display:none"></div>
  </div>
</div>

<!-- ── Вимоги ────────────────────────────────────────────── -->
<div class="upd-card">
  <div class="upd-body">
    <div class="fw-semibold small mb-2 text-muted">Системні вимоги</div>
    <?php foreach ($requirements as $name => $ok): ?>
    <div class="req-row">
      <span class="<?= $ok ? 'req-ok' : 'req-fail' ?>"><?= $ok ? '✓' : '✗' ?></span>
      <span><?= htmlspecialchars($name) ?></span>
    </div>
    <?php endforeach; ?>

    <?php if (!$hasZip): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:1rem;margin-top:.75rem">
      <div style="font-weight:600;color:#991b1b;margin-bottom:.5rem">⚠ ZipArchive не увімкнено</div>

      <?php if ($iniMsg === 'ok'): ?>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem .9rem;color:#166534;font-size:.85rem;margin-bottom:.75rem">
        ✅ php.ini оновлено. <strong>Перезапустіть PHP/IIS</strong> щоб зміни набули чинності, потім оновіть цю сторінку.
      </div>
      <?php elseif ($iniMsg === 'write_fail'): ?>
      <div style="background:#fff8e1;border:1px solid #f59e0b;border-radius:6px;padding:.65rem .9rem;color:#92400e;font-size:.85rem;margin-bottom:.75rem">
        ⚠ Не вдалося записати в php.ini — виконайте вручну.
      </div>
      <?php elseif ($iniMsg === 'not_writable'): ?>
      <div style="background:#fff8e1;border:1px solid #f59e0b;border-radius:6px;padding:.65rem .9rem;color:#92400e;font-size:.85rem;margin-bottom:.75rem">
        ⚠ php.ini недоступний для запису — виконайте вручну.
      </div>
      <?php endif; ?>

      <div style="font-size:.85rem;color:#374151;margin-bottom:.75rem">
        <?php if ($phpIniPath): ?>
        <strong>php.ini:</strong> <code style="font-size:.8rem"><?= htmlspecialchars($phpIniPath) ?></code><br>
        <?php if ($phpExtDir): ?>
        <strong>Папка розширень:</strong> <code style="font-size:.8rem"><?= htmlspecialchars($phpExtDir) ?></code><br>
        <?php endif; ?>
        <?php if ($extFile): ?>
        <strong>Файл розширення знайдено:</strong> <code style="font-size:.8rem"><?= htmlspecialchars($extFile) ?></code>
        <?php else: ?>
        <span style="color:#dc2626">Файл <code>php_zip.dll</code> не знайдено в папці розширень</span>
        <?php endif; ?>
        <?php else: ?>
        php.ini не знайдено
        <?php endif; ?>
      </div>

      <?php if ($phpIniWritable && $extFile): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="action" value="enable_zip">
        <button class="btn btn-danger btn-sm">⚡ Увімкнути автоматично (записати в php.ini)</button>
      </form>
      <div style="font-size:.75rem;color:#6b7280;margin-top:.4rem">
        Після натискання перезапустіть PHP / IIS / Apache
      </div>
      <?php else: ?>
      <div style="font-size:.83rem;color:#374151;margin-top:.5rem">
        <strong>Увімкніть вручну:</strong><br>
        1. Відкрийте <code><?= htmlspecialchars($phpIniPath ?: 'php.ini') ?></code><br>
        2. Знайдіть рядок <code>;extension=php_zip</code> або <code>;extension=zip</code><br>
        3. Видаліть крапку з комою на початку:<br>
        &nbsp;&nbsp;&nbsp;<code>extension=php_zip.dll</code><br>
        4. Збережіть і перезапустіть IIS / PHP
      </div>
      <?php endif; ?>

      <?php if ($hasPhar && !$hasZip): ?>
      <div style="margin-top:.75rem;padding:.65rem .9rem;background:#f0f9ff;border:1px solid #7dd3fc;border-radius:6px;font-size:.82rem;color:#0c4a6e">
        💡 <strong>Альтернатива:</strong> PharData доступний — можна розпаковувати .tar.gz без ZipArchive.
        Переконайтесь що GitHub реліз містить <code>.tar.gz</code> архів, або оновіть вручну через zip нижче.
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$hasUrlFopen): ?>
    <div style="background:#fff8e1;border:1px solid #f59e0b;border-radius:8px;padding:.75rem 1rem;margin-top:.75rem;font-size:.84rem;color:#92400e">
      ⚠ <strong>allow_url_fopen вимкнено</strong> — завантаження файлів з GitHub неможливе.<br>
      Додайте в php.ini: <code>allow_url_fopen = On</code>
    </div>
    <?php endif; ?>

  </div>

  <div class="upd-body" style="background:#f8faff">
    <div class="fw-semibold small mb-2 text-muted">Захищені файли (не перезаписуються)</div>
    <div class="protected-list">
      <?php foreach ($protectedPaths as $p): ?>
      <span><?= htmlspecialchars($p) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Список релізів ─────────────────────────────────────── -->
<?php if (!empty($releases)): ?>
<div class="upd-card">
  <div class="upd-body">
    <div class="fw-semibold small mb-3 text-muted">Останні релізи GitHub</div>
    <?php foreach ($releases as $i => $rel):
      $isNewer = version_compare($rel['tag'], ltrim($currentVersion,'v'), '>');
      $isCurrent = version_compare($rel['tag'], ltrim($currentVersion,'v'), '==');
    ?>
    <div class="release-item <?= $i===0?'latest':'' ?>">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="ver-badge <?= $isNewer?'new':($isCurrent?'cur':'') ?>">
          v<?= htmlspecialchars($rel['tag']) ?>
        </span>
        <span class="fw-semibold small"><?= htmlspecialchars($rel['name']) ?></span>
        <?php if ($rel['prerelease']): ?>
        <span class="badge bg-warning text-dark">pre-release</span>
        <?php endif; ?>
        <?php if ($isCurrent): ?>
        <span class="badge bg-secondary">встановлена</span>
        <?php endif; ?>
        <span class="text-muted small ms-auto"><?= htmlspecialchars($rel['date']) ?></span>
        <?php if ($isNewer && $reqOk && !$rel['prerelease']): ?>
        <button class="btn btn-success btn-sm"
          onclick="startUpdate('<?= htmlspecialchars($rel['zip_url'],ENT_QUOTES) ?>','<?= htmlspecialchars($rel['tag'],ENT_QUOTES) ?>')">
          ⬆ Встановити
        </button>
        <?php elseif ($isNewer && $rel['prerelease']): ?>
        <button class="btn btn-warning btn-sm"
          onclick="if(confirm('Це pre-release версія. Встановити?')) startUpdate('<?= htmlspecialchars($rel['zip_url'],ENT_QUOTES) ?>','<?= htmlspecialchars($rel['tag'],ENT_QUOTES) ?>')">
          ⬆ Встановити (pre)
        </button>
        <?php endif; ?>
      </div>
      <?php if ($rel['body']): ?>
      <div class="release-notes"><?= htmlspecialchars($rel['body']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Лог оновлення ────────────────────────────────────── -->
<div id="updateLogWrap" style="display:none" class="upd-card">
  <div class="upd-body">
    <div class="fw-semibold small mb-2" id="updateLogTitle">Встановлення...</div>
    <div class="upd-log" id="updateLog"></div>
    <div id="updateDone" style="display:none" class="mt-3">
      <div class="alert alert-success mb-2" id="updateDoneMsg"></div>
      <button class="btn btn-primary" onclick="location.reload()">↻ Перезавантажити сторінку</button>
    </div>
  </div>
</div>

<!-- ── Ручне оновлення ───────────────────────────────────── -->
<div class="upd-card">
  <div class="upd-body">
    <div class="fw-semibold small mb-2 text-muted">Ручне оновлення (URL архіву)</div>
    <div class="d-flex gap-2 flex-wrap">
      <input type="text" class="form-control form-control-sm" id="manualZipUrl"
        placeholder="https://github.com/.../archive/refs/tags/v1.0.0.zip" style="flex:1;min-width:200px">
      <input type="text" class="form-control form-control-sm" id="manualVersion"
        placeholder="1.0.0" style="max-width:100px">
      <button class="btn btn-outline-primary btn-sm"
        onclick="startUpdate(document.getElementById('manualZipUrl').value, document.getElementById('manualVersion').value)"
        <?= !$reqOk ? 'disabled' : '' ?>>
        ⬆ Встановити
      </button>
    </div>
    <div class="form-text small mt-1">Вставте посилання на ZIP архів релізу з GitHub</div>
  </div>
</div>

</div>

<script>
var CSRF = '<?= $csrf ?>';

function checkUpdate() {
    var btn = document.getElementById('btnCheck');
    btn.disabled = true;
    btn.textContent = '⏳ Перевірка...';
    var res = document.getElementById('updateResult');
    res.style.display = 'none';

    fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'csrf='+encodeURIComponent(CSRF)+'&action=check'
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        btn.disabled = false;
        btn.textContent = '🔍 Перевірити оновлення';

        if (!d.ok) {
            res.style.display = 'block';
            res.innerHTML = '<div class="alert alert-danger">❌ '+escHtml(d.error)+'</div>';
            return;
        }

        var wrap = document.getElementById('latestVerWrap');
        wrap.style.display = 'block';
        document.getElementById('latestVer').textContent = d.latest;

        if (d.has_update) {
            res.style.display = 'block';
            var notesHtml = d.body ? '<pre style="font-size:.8rem;background:#f8faff;padding:.6rem;border-radius:6px;max-height:150px;overflow-y:auto;white-space:pre-wrap">'+escHtml(d.body)+'</pre>' : '';
            res.innerHTML =
                '<div class="alert alert-success d-flex align-items-center justify-content-between gap-2 flex-wrap">' +
                '<div><strong>🎉 Доступне оновлення!</strong> v'+escHtml(d.current)+' → v'+escHtml(d.latest)+
                (d.published ? ' · '+escHtml(d.published) : '') + '</div>' +
                '<button class="btn btn-success btn-sm" onclick="startUpdate(\''+escHtml(d.zip_url)+'\',\''+escHtml(d.latest)+'\')">⬆ Встановити v'+escHtml(d.latest)+'</button>' +
                '</div>' + notesHtml;
        } else {
            res.style.display = 'block';
            res.innerHTML = '<div class="alert alert-secondary">✅ Встановлена остання версія (v'+escHtml(d.current)+')</div>';
        }
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.textContent = '🔍 Перевірити оновлення';
        res.style.display = 'block';
        res.innerHTML = '<div class="alert alert-danger">❌ Помилка: '+escHtml(e.message)+'</div>';
    });
}

function startUpdate(zipUrl, version) {
    if (!zipUrl || !version) { alert('Вкажіть URL і версію'); return; }
    if (!confirm('Встановити версію v'+version+'?\n\nБудо автоматично створено бекап БД.\nЗахищені файли не перезапишуться.')) return;

    var logWrap = document.getElementById('updateLogWrap');
    var logEl   = document.getElementById('updateLog');
    var doneEl  = document.getElementById('updateDone');
    var titleEl = document.getElementById('updateLogTitle');

    logWrap.style.display = 'block';
    logEl.innerHTML = '';
    doneEl.style.display = 'none';
    titleEl.textContent = 'Встановлення v'+version+'...';
    logWrap.scrollIntoView({behavior:'smooth'});

    function addLog(text) {
        var line = document.createElement('div');
        var cls = text.indexOf('❌') !== -1 ? 'err' : (text.indexOf('⚠') !== -1 ? 'warn' : '');
        if (cls) line.className = cls;
        line.textContent = text;
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }

    addLog('▶ Починаємо оновлення до v'+version+'...');

    fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'csrf='+encodeURIComponent(CSRF)+'&action=update&zip_url='+encodeURIComponent(zipUrl)+'&version='+encodeURIComponent(version)
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        (d.steps || []).forEach(addLog);
        doneEl.style.display = 'block';
        var msg = document.getElementById('updateDoneMsg');
        if (d.ok) {
            titleEl.textContent = '✅ Оновлення завершено';
            msg.className = 'alert alert-success mb-2';
            msg.textContent = '✅ fly-CMS оновлено до версії v'+(d.version||version);
        } else {
            titleEl.textContent = '❌ Помилка оновлення';
            msg.className = 'alert alert-danger mb-2';
            msg.textContent = '❌ '+(d.error||'Невідома помилка');
        }
    })
    .catch(function(e) {
        addLog('❌ Fetch помилка: '+e.message);
        doneEl.style.display = 'block';
        document.getElementById('updateDoneMsg').className = 'alert alert-danger mb-2';
        document.getElementById('updateDoneMsg').textContent = '❌ '+e.message;
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Автоперевірка при завантаженні якщо GitHub налаштовано
<?php if ($configured): ?>
window.addEventListener('DOMContentLoaded', function() {
    checkUpdate();
});
<?php endif; ?>
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';