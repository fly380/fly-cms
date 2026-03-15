<?php
/**
 * admin/plugins.php — Менеджер плагінів
 * Доступ: admin + superadmin
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/../data/plugins.php';

fly_send_security_headers();

$pdo      = connectToDatabase();
$username = $_SESSION['username'] ?? 'admin';
$role     = $_SESSION['role']     ?? 'admin';
$ROOT     = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
$pluginsDir = $ROOT . '/plugins';

// ─── CSRF ────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_plugins'])) {
    $_SESSION['csrf_plugins'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_plugins'];

// ─── POST дії ─────────────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $flash = ['type'=>'danger','msg'=>'Невірний CSRF токен'];
    } else {
        $action = $_POST['action'] ?? '';
        $slug   = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');

        // ── Увімкнути ────────────────────────────────────────────
        if ($action === 'enable' && $slug) {
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (!file_exists($pluginFile)) {
                $flash = ['type'=>'danger','msg'=>"plugin.php не знайдено у plugins/{$slug}/"];
            } else {
                fly_set_plugin_active($slug, true);
                log_action($username, "Плагін увімкнено: {$slug}");
                $flash = ['type'=>'success','msg'=>"Плагін «{$slug}» увімкнено"];
            }
        }

        // ── Вимкнути ─────────────────────────────────────────────
        if ($action === 'disable' && $slug) {
            fly_set_plugin_active($slug, false);
            log_action($username, "Плагін вимкнено: {$slug}");
            $flash = ['type'=>'secondary','msg'=>"Плагін «{$slug}» вимкнено"];
        }

        // ── Видалити (тільки superadmin) ─────────────────────────
        if ($action === 'delete' && $slug && $role === 'superadmin') {
            fly_set_plugin_active($slug, false);
            $dir = $pluginsDir . '/' . $slug;
            if (is_dir($dir)) {
                // Рекурсивне видалення
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($dir);
            }
            log_action($username, "Плагін видалено: {$slug}");
            $flash = ['type'=>'warning','msg'=>"Плагін «{$slug}» видалено"];
        }

        // ── Встановити з ZIP ─────────────────────────────────────
        if ($action === 'upload' && isset($_FILES['plugin_zip'])) {
            $file = $_FILES['plugin_zip'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $flash = ['type'=>'danger','msg'=>'Помилка завантаження файлу'];
            } elseif (!class_exists('ZipArchive')) {
                $flash = ['type'=>'danger','msg'=>'ZipArchive недоступний — неможливо розпакувати'];
            } else {
                $tmpZip = $ROOT . '/data/tmp_plugin_' . uniqid() . '.zip';
                move_uploaded_file($file['tmp_name'], $tmpZip);

                $zip = new ZipArchive();
                if ($zip->open($tmpZip) !== true) {
                    $flash = ['type'=>'danger','msg'=>'Не вдалося відкрити ZIP'];
                    @unlink($tmpZip);
                } else {
                    // Знаходимо кореневу папку всередині архіву
                    $firstEntry = $zip->getNameIndex(0);
                    $rootFolder = explode('/', $firstEntry)[0];

                    // Перевіряємо наявність plugin.json і plugin.php
                    $hasJson = $zip->locateName($rootFolder . '/plugin.json') !== false;
                    $hasPhp  = $zip->locateName($rootFolder . '/plugin.php')  !== false;

                    if (!$hasJson || !$hasPhp) {
                        $flash = ['type'=>'danger','msg'=>"Архів не містить plugin.json або plugin.php"];
                    } else {
                        // Читаємо slug з plugin.json
                        $jsonIdx  = $zip->locateName($rootFolder . '/plugin.json');
                        $jsonData = json_decode($zip->getFromIndex($jsonIdx), true);
                        $newSlug  = preg_replace('/[^a-z0-9_-]/', '', $jsonData['slug'] ?? $rootFolder);

                        $targetDir = $pluginsDir . '/' . $newSlug;
                        if (!is_dir($pluginsDir)) mkdir($pluginsDir, 0755, true);

                        // Розпакувати у тимчасову папку
                        $tmpDir = $ROOT . '/data/tmp_plugin_extract_' . uniqid();
                        mkdir($tmpDir, 0755, true);
                        $zip->extractTo($tmpDir);
                        $zip->close();
                        @unlink($tmpZip);

                        // Перемістити у plugins/slug/
                        $srcDir = $tmpDir . '/' . $rootFolder;
                        if (is_dir($targetDir)) {
                            // Оновлення існуючого
                            $it = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::CHILD_FIRST
                            );
                            foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
                            rmdir($targetDir);
                        }
                        rename($srcDir, $targetDir);
                        // Прибрати тимчасову папку
                        if (is_dir($tmpDir)) rmdir($tmpDir);

                        log_action($username, "Плагін встановлено: {$newSlug}");
                        $flash = ['type'=>'success','msg'=>"Плагін «{$newSlug}» встановлено. Увімкніть його нижче."];
                    }
                    if ($zip->numFiles ?? 0) $zip->close();
                }
            }
        }

        $_SESSION['csrf_plugins'] = bin2hex(random_bytes(32));
        $csrf = $_SESSION['csrf_plugins'];
    }
}

// ─── Сканування наявних плагінів ──────────────────────────────────
$availablePlugins = [];
if (is_dir($pluginsDir)) {
    foreach (glob($pluginsDir . '/*/plugin.json') as $jsonFile) {
        $slug = basename(dirname($jsonFile));
        $meta = json_decode(file_get_contents($jsonFile), true) ?? [];
        $meta['slug']    = $slug;
        $meta['active']  = fly_is_plugin_active($slug);
        $meta['has_php'] = file_exists(dirname($jsonFile) . '/plugin.php');
        $meta['has_settings'] = file_exists(dirname($jsonFile) . '/settings.php');
        $availablePlugins[$slug] = $meta;
    }
}

$totalPlugins  = count($availablePlugins);
$activeCount   = count(array_filter($availablePlugins, function($p) { return $p['active']; }));

// ─── Рендер ───────────────────────────────────────────────────────
$page_title = '🧩 Плагіни';
ob_start();
?>
<style>
.plugin-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1rem; }
.plugin-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:10px;
    overflow:hidden; transition:box-shadow .15s;
}
.plugin-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.plugin-card.active { border-color:#2E5FA3; }
.plugin-head { padding:1rem 1.25rem .75rem; display:flex; gap:.75rem; align-items:flex-start; }
.plugin-icon { width:44px; height:44px; border-radius:10px; background:#e0e7ff;
    display:flex; align-items:center; justify-content:center; font-size:1.4rem; flex-shrink:0; }
.plugin-icon.active { background:#dbeafe; }
.plugin-title { font-weight:600; font-size:.95rem; color:#1f2937; margin-bottom:2px; }
.plugin-ver   { font-size:.75rem; color:#9ca3af; font-family:monospace; }
.plugin-desc  { padding:0 1.25rem .75rem; font-size:.83rem; color:#6b7280; line-height:1.5; }
.plugin-meta  { padding:0 1.25rem .5rem; display:flex; gap:.5rem; flex-wrap:wrap; }
.plugin-tag   { background:#f3f4f6; color:#6b7280; border-radius:4px; padding:.1rem .5rem; font-size:.72rem; }
.plugin-actions { padding:.75rem 1.25rem; border-top:1px solid #f3f4f6; display:flex; gap:.5rem; align-items:center; }
.plugin-author { margin-left:auto; font-size:.75rem; color:#9ca3af; }

.upload-zone { border:2px dashed #e5e7eb; border-radius:10px; padding:2rem; text-align:center;
    cursor:pointer; transition:all .15s; }
.upload-zone:hover { border-color:#2E5FA3; background:#f8faff; }

.hook-list { display:flex; flex-wrap:wrap; gap:.3rem; margin-top:.5rem; }
.hook-badge { background:#f0f9ff; color:#0369a1; border:1px solid #bae6fd;
    border-radius:4px; padding:.1rem .5rem; font-size:.72rem; font-family:monospace; }

.empty-plugins { text-align:center; padding:3rem 1rem; color:#9ca3af; }
.empty-plugins .ico { font-size:3rem; margin-bottom:.5rem; }
</style>

<div class="container-fluid" style="max-width:1100px;padding:1.5rem">

  <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <div>
      <h1 class="h4 mb-0">🧩 Плагіни</h1>
      <small class="text-muted">
        Встановлено: <?= $totalPlugins ?> · Активних: <?= $activeCount ?>
      </small>
    </div>
    <button class="btn btn-primary ms-auto btn-sm"
      onclick="document.getElementById('uploadSection').style.display='block';this.style.display='none'">
      ⬆ Встановити плагін
    </button>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> mb-3">
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- Завантаження ZIP -->
  <div id="uploadSection" style="display:none" class="mb-4">
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1.25rem">
      <div class="fw-semibold mb-3">⬆ Встановити плагін з ZIP-архіву</div>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
        <input type="hidden" name="csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="action" value="upload">
        <div style="flex:1;min-width:250px">
          <label class="form-label small fw-semibold">ZIP-файл плагіна</label>
          <input type="file" name="plugin_zip" accept=".zip" class="form-control form-control-sm" required>
          <div class="form-text small">Архів повинен містити <code>plugin.json</code> та <code>plugin.php</code></div>
        </div>
        <button class="btn btn-primary btn-sm">Встановити</button>
        <button type="button" class="btn btn-outline-secondary btn-sm"
          onclick="document.getElementById('uploadSection').style.display='none'">Скасувати</button>
      </form>
    </div>
  </div>

  <!-- Список плагінів -->
  <?php if (empty($availablePlugins)): ?>
  <div class="empty-plugins">
    <div class="ico">🧩</div>
    <div style="font-size:.95rem;font-weight:600;color:#374151;margin-bottom:.5rem">Плагінів ще немає</div>
    <div style="font-size:.85rem;max-width:380px;margin:0 auto">
      Розмістіть папку плагіна у <code>plugins/назва-плагіна/</code> з файлами
      <code>plugin.json</code> і <code>plugin.php</code>,<br>або встановіть через ZIP вище.
    </div>
    <a href="https://github.com/fly380/fly-cms" target="_blank" class="btn btn-outline-primary btn-sm mt-3">
      Знайти плагіни →
    </a>
  </div>
  <?php else: ?>
  <div class="plugin-grid">
    <?php foreach ($availablePlugins as $slug => $p):
      $icon    = $p['icon']        ?? '🧩';
      $name    = $p['name']        ?? $slug;
      $desc    = $p['description'] ?? '';
      $ver     = $p['version']     ?? '1.0.0';
      $author  = $p['author']      ?? '';
      $hooks   = $p['hooks']       ?? [];
      $active  = $p['active'];
    ?>
    <div class="plugin-card <?= $active ? 'active' : '' ?>">
      <div class="plugin-head">
        <div class="plugin-icon <?= $active ? 'active' : '' ?>"><?= htmlspecialchars($icon) ?></div>
        <div style="flex:1;min-width:0">
          <div class="plugin-title"><?= htmlspecialchars($name) ?></div>
          <div class="plugin-ver">v<?= htmlspecialchars($ver) ?> · <?= htmlspecialchars($slug) ?></div>
        </div>
        <?php if ($active): ?>
        <span class="badge bg-success" style="font-size:.7rem">Активний</span>
        <?php else: ?>
        <span class="badge bg-secondary" style="font-size:.7rem">Вимкнено</span>
        <?php endif; ?>
      </div>

      <?php if ($desc): ?>
      <div class="plugin-desc"><?= htmlspecialchars($desc) ?></div>
      <?php endif; ?>

      <?php if (!empty($hooks)): ?>
      <div class="plugin-meta">
        <div class="hook-list">
          <?php foreach ((array)$hooks as $h): ?>
          <span class="hook-badge"><?= htmlspecialchars($h) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="plugin-actions">
        <?php if (!$p['has_php']): ?>
        <span class="text-danger small">⚠ plugin.php не знайдено</span>
        <?php elseif ($active): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="disable">
          <input type="hidden" name="slug"   value="<?= htmlspecialchars($slug) ?>">
          <button class="btn btn-outline-secondary btn-sm">Вимкнути</button>
        </form>
        <?php else: ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="enable">
          <input type="hidden" name="slug"   value="<?= htmlspecialchars($slug) ?>">
          <button class="btn btn-primary btn-sm">Увімкнути</button>
        </form>
        <?php endif; ?>

        <?php if ($p['has_settings'] && $active): ?>
        <a href="/admin/plugin-settings.php?plugin=<?= urlencode($slug) ?>"
           class="btn btn-outline-secondary btn-sm">⚙ Налаштування</a>
        <?php endif; ?>

        <?php if ($role === 'superadmin'): ?>
        <form method="post" style="display:inline" class="ms-auto"
          onsubmit="return confirm('Видалити плагін «<?= htmlspecialchars($name) ?>»? Цю дію не можна скасувати.')">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="slug"   value="<?= htmlspecialchars($slug) ?>">
          <button class="btn btn-outline-danger btn-sm">🗑</button>
        </form>
        <?php endif; ?>

        <?php if ($author): ?>
        <span class="plugin-author">by <?= htmlspecialchars($author) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Довідка -->
  <div style="background:#f8faff;border:1px solid #e0e7f0;border-radius:10px;padding:1.25rem;margin-top:1.5rem">
    <div class="fw-semibold small mb-2 text-muted">📖 Розробникам — структура плагіна</div>
    <div style="font-family:monospace;font-size:.78rem;background:#1a1a2a;color:#4ade80;border-radius:7px;padding:1rem;line-height:1.8">
      <div style="color:#94a3b8">plugins/<span style="color:#fbbf24">my-plugin</span>/</div>
      <div style="color:#94a3b8">├── <span style="color:#4ade80">plugin.json</span> &nbsp;← метадані</div>
      <div style="color:#94a3b8">├── <span style="color:#4ade80">plugin.php</span> &nbsp;&nbsp;← код + реєстрація хуків</div>
      <div style="color:#94a3b8">├── settings.php &nbsp;← (опц.) сторінка налаштувань</div>
      <div style="color:#94a3b8">└── assets/ &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;← (опц.) CSS, JS, зображення</div>
    </div>
    <div style="font-size:.8rem;color:#6b7280;margin-top:.75rem">
      Доступні хуки: <code>cms.init</code>, <code>cms.post.saved</code>, <code>cms.page.saved</code>,
      <code>cms.post.content</code>, <code>cms.page.content</code>, <code>cms.admin.menu</code>,
      <code>cms.dashboard.widgets</code>, <code>cms.user.login</code>, <code>cms.media.uploaded</code>
    </div>
  </div>

</div>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
