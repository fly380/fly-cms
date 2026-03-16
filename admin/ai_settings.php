<?php
/**
 * admin/ai_settings.php — Налаштування AI (GROQ)
 * Читає і записує GROQ_API_KEY у .env файл.
 * Доступ: admin + superadmin
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';

fly_send_security_headers();

$pdo      = connectToDatabase();
$username = $_SESSION['username'] ?? 'admin';

// ── CSRF ─────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_ai'])) {
    $_SESSION['csrf_ai'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_ai'];

// ── Знайти .env ──────────────────────────────────────────────────
function ai_find_env(): string {
    $root    = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $storage = dirname($root) . '/cms_storage/.env';
    $local   = $root . '/.env';
    if (file_exists($storage)) return $storage;
    if (file_exists($local))   return $local;
    $dir = dirname($root) . '/cms_storage';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return is_writable(dirname($root)) ? $storage : $local;
}

// ── Читати .env у масив ──────────────────────────────────────────
function ai_read_env(string $path): array {
    $data = [];
    if (!file_exists($path)) return $data;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $data[trim($k)] = trim($v);
    }
    return $data;
}

// ── Записати / оновити .env ──────────────────────────────────────
function ai_write_env(string $path, array $updates): bool {
    $lines   = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $written = [];
    foreach ($lines as &$line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || !str_contains($t, '=')) continue;
        [$k] = explode('=', $t, 2);
        $k = trim($k);
        if (array_key_exists($k, $updates)) {
            $line        = $k . '=' . $updates[$k];
            $written[$k] = true;
        }
    }
    unset($line);
    $new = [];
    foreach ($updates as $k => $v) {
        if (!isset($written[$k])) $new[] = $k . '=' . $v;
    }
    if ($new) {
        $lines[] = '';
        $lines[] = '# AI (GROQ)';
        foreach ($new as $l) $lines[] = $l;
    }
    return file_put_contents($path, implode("\n", $lines) . "\n") !== false;
}

// ── Обробка форми ────────────────────────────────────────────────
$flash    = null;
$envPath  = ai_find_env();
$envData  = ai_read_env($envPath);
$apiKey   = $envData['GROQ_API_KEY'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $flash = ['type' => 'danger', 'msg' => 'Невірний CSRF токен. Оновіть сторінку.'];
    } else {
        $newKey = trim($_POST['groq_api_key'] ?? '');

        if ($newKey !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $newKey)) {
            $flash = ['type' => 'danger', 'msg' => 'Ключ містить недопустимі символи.'];
        } else {
            if (ai_write_env($envPath, ['GROQ_API_KEY' => $newKey])) {
                $apiKey = $newKey;
                // Оновлюємо $_ENV щоб поточний процес теж бачив новий ключ
                $_ENV['GROQ_API_KEY'] = $newKey;
                putenv("GROQ_API_KEY={$newKey}");
                log_action($username, 'Оновлено GROQ_API_KEY у .env');
                $flash = ['type' => 'success', 'msg' => 'GROQ API ключ збережено.'];
            } else {
                $flash = ['type' => 'danger', 'msg' => "Не вдалось записати у файл: {$envPath}. Перевір права на запис."];
            }
        }
        // Оновлюємо CSRF
        $_SESSION['csrf_ai'] = bin2hex(random_bytes(32));
        $csrf = $_SESSION['csrf_ai'];
    }
}

$page_title = '🤖 AI налаштування';
ob_start();
?>
<div class="container-fluid px-4 py-2">

  <div class="d-flex align-items-center mb-4 gap-3">
    <div>
      <h1 class="h3 mb-0">🤖 AI налаштування</h1>
      <p class="text-muted small mt-1">GROQ API ключ для функцій штучного інтелекту в редакторі</p>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible mb-4">
    <?= htmlspecialchars($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- Форма ключа -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
          <strong>GROQ API Key</strong>
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

            <div class="mb-3">
              <label class="form-label fw-semibold">API ключ
                <a href="https://console.groq.com/keys" target="_blank" class="ms-2 small text-muted">
                  ↗ console.groq.com
                </a>
              </label>
              <div class="input-group">
                <input type="password"
                       id="groqKey"
                       name="groq_api_key"
                       class="form-control font-monospace"
                       value="<?= htmlspecialchars($apiKey) ?>"
                       placeholder="gsk_…"
                       autocomplete="off"
                       spellcheck="false">
                <button class="btn btn-outline-secondary" type="button" id="toggleKey" title="Показати/приховати">
                  👁
                </button>
              </div>
              <div class="form-text">
                Починається з <code>gsk_</code>. Зберігається у файлі <code>.env</code> поза веброутом.
              </div>
            </div>

            <?php if ($apiKey): ?>
            <div class="alert alert-success py-2 small mb-3">
              ✅ Ключ налаштований.
              Поточний: <code class="user-select-all"><?= htmlspecialchars(substr($apiKey, 0, 8)) ?>…<?= htmlspecialchars(substr($apiKey, -4)) ?></code>
            </div>
            <?php else: ?>
            <div class="alert alert-warning py-2 small mb-3">
              ⚠ Ключ не встановлено — AI-функції в редакторі не працюватимуть.
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">💾 Зберегти</button>
            <?php if ($apiKey): ?>
            <button type="submit" name="groq_api_key" value="" class="btn btn-outline-danger ms-2"
                    onclick="return confirm('Видалити GROQ API ключ?')">
              🗑 Очистити
            </button>
            <?php endif; ?>
          </form>
        </div>
      </div>
    </div>

    <!-- Інформація -->
    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white py-3">
          <strong>Про GROQ</strong>
        </div>
        <div class="card-body small text-muted">
          <p><strong class="text-body">GROQ</strong> — хмарний провайдер швидкого інференсу LLM-моделей.</p>
          <p>В fly-CMS використовується для:</p>
          <ul>
            <li>AI-асистент у редакторі постів та сторінок</li>
            <li>Генерація та покращення тексту</li>
            <li>Підказки meta-опису та заголовків</li>
          </ul>
          <hr>
          <p class="mb-1"><strong class="text-body">Отримати ключ:</strong></p>
          <ol class="ps-3">
            <li>Зареєструйтесь на <a href="https://console.groq.com" target="_blank">console.groq.com</a></li>
            <li>Перейдіть у <strong>API Keys → Create API key</strong></li>
            <li>Скопіюйте ключ і вставте зліва</li>
          </ol>
          <hr>
          <p class="mb-0 text-success">✅ Безкоштовний tier включає кілька мільйонів токенів на місяць.</p>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div>

<script>
document.getElementById('toggleKey').addEventListener('click', function () {
  const f = document.getElementById('groqKey');
  f.type = f.type === 'password' ? 'text' : 'password';
  this.textContent = f.type === 'password' ? '👁' : '🙈';
});
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
