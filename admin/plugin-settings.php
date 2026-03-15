<?php
/**
 * admin/plugin-settings.php — Налаштування плагіна
 * URL: /admin/plugin-settings.php?plugin=slug
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
$ROOT     = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

// ─── CSRF ────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_plugin_settings'])) {
    $_SESSION['csrf_plugin_settings'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_plugin_settings'];

// ─── Валідація slug ───────────────────────────────────────────────
$pluginSlug = preg_replace('/[^a-z0-9_-]/', '', $_GET['plugin'] ?? '');
if (!$pluginSlug) {
    header('Location: /admin/plugins.php');
    exit;
}

$settingsFile = $ROOT . '/plugins/' . $pluginSlug . '/settings.php';
$jsonFile     = $ROOT . '/plugins/' . $pluginSlug . '/plugin.json';

if (!file_exists($settingsFile)) {
    header('Location: /admin/plugins.php');
    exit;
}

// Метадані плагіна
$meta = file_exists($jsonFile)
    ? (json_decode(file_get_contents($jsonFile), true) ?? [])
    : [];
$pluginName = $meta['name'] ?? $pluginSlug;
$pluginIcon = $meta['icon'] ?? '🧩';

// ─── Flash (з settings.php плагіна) ──────────────────────────────
$settingsFlash = null;

// ─── Рендер ───────────────────────────────────────────────────────
$page_title = $pluginIcon . ' ' . $pluginName . ' — Налаштування';
ob_start();
?>
<div class="container-fluid" style="max-width:740px;padding:1.5rem">

  <div class="d-flex align-items-center gap-2 mb-3">
    <a href="/admin/plugins.php" class="btn btn-outline-secondary btn-sm">← Плагіни</a>
    <h1 class="h5 mb-0"><?= htmlspecialchars($pluginIcon . ' ' . $pluginName) ?> — Налаштування</h1>
  </div>

  <?php if ($settingsFlash): ?>
  <div class="alert alert-<?= $settingsFlash['type'] ?> mb-3">
    <?= htmlspecialchars($settingsFlash['msg']) ?>
  </div>
  <?php endif; ?>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1.5rem">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">

      <?php
      // Підключаємо settings.php плагіна — він може читати POST і зберігати налаштування
      require $settingsFile;
      // Оновити CSRF після POST
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $_SESSION['csrf_plugin_settings'] = bin2hex(random_bytes(32));
          $csrf = $_SESSION['csrf_plugin_settings'];
      }
      ?>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">💾 Зберегти</button>
        <a href="/admin/plugins.php" class="btn btn-outline-secondary">Назад</a>
      </div>
    </form>
  </div>

</div>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
