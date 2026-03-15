<?php
/**
 * admin/smtp_settings.php — Налаштування SMTP
 * Читає і записує SMTP_* змінні у .env файл.
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
require_once __DIR__ . '/smtp_helper.php';

fly_send_security_headers();

$pdo      = connectToDatabase();
$username = $_SESSION['username'] ?? 'admin';

// ─── CSRF ────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_smtp'])) {
    $_SESSION['csrf_smtp'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_smtp'];

// ─── Знайти .env файл ────────────────────────────────────────────
function find_env_path(): string {
    $root    = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $storage = dirname($root) . '/cms_storage/.env';
    $local   = $root . '/.env';
    if (file_exists($storage)) return $storage;
    if (file_exists($local))   return $local;
    $storageDir = dirname($root) . '/cms_storage';
    if (!is_dir($storageDir)) @mkdir($storageDir, 0750, true);
    return is_writable(dirname($root)) ? $storage : $local;
}

// ─── Читати .env у масив ─────────────────────────────────────────
function read_env(string $path): array {
    $data = [];
    if (!file_exists($path)) return $data;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $data[trim($k)] = trim($v);
    }
    return $data;
}

// ─── Записати змінні у .env ──────────────────────────────────────
function write_env(string $path, array $updates): bool {
    $lines   = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $written = [];
    foreach ($lines as &$line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        list($k) = explode('=', $t, 2);
        $k = trim($k);
        if (array_key_exists($k, $updates)) {
            $line        = $k . '=' . $updates[$k];
            $written[$k] = true;
        }
    }
    unset($line);
    $newBlock = [];
    foreach ($updates as $k => $v) {
        if (!isset($written[$k])) $newBlock[] = $k . '=' . $v;
    }
    if ($newBlock) {
        $lines[] = '';
        foreach ($newBlock as $l) {
            // Додати відповідний коментар перед першою змінною групи
            if (strpos($l, 'GITHUB_') === 0 && !isset($ghSectionAdded)) {
                $lines[] = '# GitHub (оновлення CMS)';
                $ghSectionAdded = true;
            } elseif (strpos($l, 'SMTP_') === 0 && !isset($smtpSectionAdded)) {
                $lines[] = '# SMTP';
                $smtpSectionAdded = true;
            }
            $lines[] = $l;
        }
    }
    return file_put_contents($path, implode("\n", $lines) . "\n") !== false;
}

// ─── Тестова відправка ──────────────────────────────────────────
function smtp_test_send(array $cfg, string $to): array {
    // Тимчасово перевизначаємо $_ENV щоб fly_smtp_send підхопила cfg з аргументу
    $prev = [];
    $keys = ['SMTP_ENABLED','SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_FROM_NAME','SMTP_FROM_EMAIL','SMTP_ENCRYPTION'];
    $map  = ['host'=>'SMTP_HOST','port'=>'SMTP_PORT','username'=>'SMTP_USERNAME','password'=>'SMTP_PASSWORD',
             'from_name'=>'SMTP_FROM_NAME','from_email'=>'SMTP_FROM_EMAIL','encryption'=>'SMTP_ENCRYPTION'];

    foreach ($keys as $k) $prev[$k] = getenv($k);
    putenv('SMTP_ENABLED=true');
    foreach ($map as $cfgKey => $envKey) {
        if (isset($cfg[$cfgKey])) putenv($envKey . '=' . $cfg[$cfgKey]);
    }

    $subject   = '[fly-CMS] Test SMTP ' . date('d.m.Y H:i');
    $bodyText  = "fly-CMS SMTP Test

SMTP налаштовано успішно!
" . date('d.m.Y H:i');
    $bodyHtml  = '<div style="font-family:Arial;padding:24px;background:#f0f4fb">'
               . '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">'
               . '<div style="background:#1a3d6e;color:#fff;padding:20px"><b>fly-CMS</b> &mdash; Тест SMTP</div>'
               . '<div style="padding:20px"><p><b>&#x2705; SMTP налаштовано успішно!</b></p>'
               . '<p>Відправлено: ' . date('d.m.Y H:i') . '</p></div></div></div>';

    $res = fly_smtp_send($to, $subject, $bodyHtml, $bodyText);

    // Відновити попередні змінні середовища
    foreach ($prev as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }

    return ['ok' => $res['sent'], 'msg' => $res['sent'] ? 'Лист надіслано на ' . $to : $res['error']];
}

// ─── Обробка POST ─────────────────────────────────────────────────
$flash   = null;
$envPath = find_env_path();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $flash = ['type' => 'danger', 'msg' => 'Невірний CSRF токен. Оновіть сторінку.'];
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'save') {
            $enabled  = isset($_POST['smtp_enabled']) ? 'true' : 'false';
            $host     = trim(isset($_POST['host'])       ? $_POST['host']       : '');
            $port     = trim(isset($_POST['port'])       ? $_POST['port']       : '587');
            $user     = trim(isset($_POST['username'])   ? $_POST['username']   : '');
            $pass     =      isset($_POST['password'])   ? $_POST['password']   : '';
            $fromName = trim(isset($_POST['from_name'])  ? $_POST['from_name']  : 'fly-CMS');
            $fromMail = trim(isset($_POST['from_email']) ? $_POST['from_email'] : '');
            $encVal   =      isset($_POST['encryption']) ? $_POST['encryption'] : 'tls';
            $enc      = in_array($encVal, ['tls','ssl','none']) ? $encVal : 'tls';

            $env = read_env($envPath);
            if ($pass === '' || $pass === '(залишити без змін)') {
                $pass = isset($env['SMTP_PASSWORD']) ? $env['SMTP_PASSWORD'] : '';
            }

            $updates = [
                'SMTP_ENABLED'    => $enabled,
                'SMTP_HOST'       => $host,
                'SMTP_PORT'       => $port,
                'SMTP_USERNAME'   => $user,
                'SMTP_PASSWORD'   => $pass,
                'SMTP_FROM_NAME'  => $fromName,
                'SMTP_FROM_EMAIL' => $fromMail ? $fromMail : $user,
                'SMTP_ENCRYPTION' => $enc,
            ];

            if (write_env($envPath, $updates)) {
                foreach ($updates as $k => $v) {
                    $_ENV[$k] = $v;
                    putenv($k . '=' . $v);
                }
                $loc   = (strpos($envPath, 'cms_storage') !== false) ? 'поза webroot' : 'у webroot';
                $flash = ['type' => 'success', 'msg' => 'Налаштування збережено. .env: ' . $loc];
            } else {
                $flash = ['type' => 'danger', 'msg' => 'Не вдалося записати у ' . $envPath . ' — перевірте права на файл'];
            }
        }

        if ($action === 'test') {
            $testTo = trim(isset($_POST['test_email']) ? $_POST['test_email'] : '');
            if (!$testTo || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'warning', 'msg' => 'Введіть коректну email адресу для тесту'];
            } else {
                $env = read_env($envPath);
                $cfg = [
                    'host'       => isset($env['SMTP_HOST'])       ? $env['SMTP_HOST']       : '',
                    'port'       => isset($env['SMTP_PORT'])       ? $env['SMTP_PORT']       : 587,
                    'username'   => isset($env['SMTP_USERNAME'])   ? $env['SMTP_USERNAME']   : '',
                    'password'   => isset($env['SMTP_PASSWORD'])   ? $env['SMTP_PASSWORD']   : '',
                    'from_email' => isset($env['SMTP_FROM_EMAIL']) ? $env['SMTP_FROM_EMAIL'] : (isset($env['SMTP_USERNAME']) ? $env['SMTP_USERNAME'] : ''),
                    'from_name'  => isset($env['SMTP_FROM_NAME'])  ? $env['SMTP_FROM_NAME']  : 'fly-CMS',
                    'encryption' => isset($env['SMTP_ENCRYPTION']) ? $env['SMTP_ENCRYPTION'] : 'tls',
                ];
                $result = smtp_test_send($cfg, $testTo);
                $flash  = ['type' => $result['ok'] ? 'success' : 'danger', 'msg' => $result['msg']];
            }
        }

        // ── Зберегти GitHub налаштування ─────────────────────────
        if ($action === 'save_github') {
            $ghOwner = trim(isset($_POST['github_owner']) ? $_POST['github_owner'] : '');
            $ghRepo  = trim(isset($_POST['github_repo'])  ? $_POST['github_repo']  : '');
            $ghToken = trim(isset($_POST['github_token']) ? $_POST['github_token'] : '');

            $env = read_env($envPath);
            // Якщо токен не змінювали — зберігаємо старий
            if ($ghToken === '' || $ghToken === '••••••••') {
                $ghToken = isset($env['GITHUB_TOKEN']) ? $env['GITHUB_TOKEN'] : '';
            }

            $updates = [
                'GITHUB_OWNER' => $ghOwner,
                'GITHUB_REPO'  => $ghRepo,
                'GITHUB_TOKEN' => $ghToken,
            ];

            if (write_env($envPath, $updates)) {
                foreach ($updates as $k => $v) { $_ENV[$k] = $v; putenv($k . '=' . $v); }
                $flash = ['type' => 'success', 'msg' => '✅ GitHub налаштування збережено'];
            } else {
                $flash = ['type' => 'danger',  'msg' => 'Не вдалося записати у ' . $envPath];
            }
        }
    }
}

// ─── Дані для форми ───────────────────────────────────────────────
$env         = read_env($envPath);
$enabled     = isset($env['SMTP_ENABLED']) ? filter_var($env['SMTP_ENABLED'], FILTER_VALIDATE_BOOLEAN) : false;
$hasPass     = !empty($env['SMTP_PASSWORD']);
$smtpHost    = isset($env['SMTP_HOST']) ? $env['SMTP_HOST'] : '';
$envLocation = file_exists($envPath)
    ? (strpos($envPath, 'cms_storage') !== false ? 'поза webroot (безпечно)' : 'у webroot (є .htaccess захист)')
    : 'файл не існує — буде створений';

$providers = [
    ['name' => 'Gmail',           'host' => 'smtp.gmail.com',     'port' => 587, 'enc' => 'tls', 'note' => 'Потрібен App Password'],
    ['name' => 'Outlook/Hotmail', 'host' => 'smtp.office365.com', 'port' => 587, 'enc' => 'tls', 'note' => 'Логін — повний email'],
    ['name' => 'Yahoo',           'host' => 'smtp.mail.yahoo.com','port' => 587, 'enc' => 'tls', 'note' => 'Потрібен App Password'],
    ['name' => 'Meta.ua',         'host' => 'smtp.meta.ua',       'port' => 465, 'enc' => 'ssl', 'note' => ''],
    ['name' => 'UKR.net',         'host' => 'smtp.ukr.net',       'port' => 465, 'enc' => 'ssl', 'note' => ''],
    ['name' => 'SendGrid',        'host' => 'smtp.sendgrid.net',  'port' => 587, 'enc' => 'tls', 'note' => 'Ключ як пароль'],
];

$smtpEnc  = isset($env['SMTP_ENCRYPTION']) ? $env['SMTP_ENCRYPTION'] : 'tls';
$smtpPort = isset($env['SMTP_PORT'])       ? $env['SMTP_PORT']       : '587';
$smtpUser = isset($env['SMTP_USERNAME'])   ? $env['SMTP_USERNAME']   : '';
$smtpFrom = isset($env['SMTP_FROM_EMAIL']) ? $env['SMTP_FROM_EMAIL'] : '';
$smtpName = isset($env['SMTP_FROM_NAME'])  ? $env['SMTP_FROM_NAME']  : 'fly-CMS';

$ghOwner   = isset($env['GITHUB_OWNER']) ? $env['GITHUB_OWNER'] : '';
$ghRepo    = isset($env['GITHUB_REPO'])  ? $env['GITHUB_REPO']  : '';
$ghToken   = isset($env['GITHUB_TOKEN']) ? $env['GITHUB_TOKEN'] : '';
$ghHasToken = !empty($ghToken);
$ghConfigured = $ghOwner && $ghRepo;

$page_title = 'Налаштування .env';
ob_start();
?>
<style>
.smtp-card  { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:1rem; }
.smtp-head  { background:linear-gradient(135deg,#1a3d6e,#2E5FA3); color:#fff; padding:1.1rem 1.5rem; }
.smtp-body  { padding:1.5rem; }
.smtp-body + .smtp-body { border-top:1px solid #f3f4f6; }
.provider-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(155px,1fr)); gap:.5rem; }
.provider-btn {
    background:#f8faff; border:1.5px solid #e0e7f0; border-radius:7px;
    padding:.5rem .75rem; cursor:pointer; font-size:.8rem; font-weight:600;
    color:#374151; transition:all .15s; text-align:left; width:100%;
}
.provider-btn:hover { border-color:#2E5FA3; background:#eef3fa; color:#1a3d6e; }
.provider-note { font-size:.7rem; color:#9ca3af; font-weight:400; margin-top:2px; }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media(max-width:600px){ .field-row { grid-template-columns:1fr; } }
.test-row { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.test-row input { flex:1; min-width:180px; }
.tip-box { background:#fffbeb; border:1px solid #fde68a; border-radius:7px; padding:.75rem 1rem; font-size:.82rem; color:#78350f; }
.provider-tip { border-radius:7px; padding:.7rem 1rem; font-size:.82rem; margin-bottom:.75rem; }
.provider-tip-warn { background:#fff8e1; border:1px solid #f59e0b; color:#92400e; }
.provider-tip-err  { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }
.provider-tip ol   { margin:.4rem 0 0 1.1rem; padding:0; }
.provider-tip li   { margin-bottom:.2rem; }
.provider-tip code { background:rgba(0,0,0,.07); padding:1px 5px; border-radius:3px; font-size:.78rem; }
</style>

<div class="container-fluid" style="max-width:820px;padding:1.5rem">

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> mb-3">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="smtp-card">
  <div class="smtp-head">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.3rem">📧</span>
      <div>
        <div style="font-weight:700">Налаштування SMTP</div>
        <div style="font-size:.78rem;opacity:.8">
          .env: <code style="background:rgba(255,255,255,.15);color:#fff;padding:1px 6px;border-radius:3px"><?= htmlspecialchars($envPath) ?></code>
        </div>
      </div>
      <div class="ms-auto">
        <?php if ($enabled && $hasPass): ?>
          <span class="badge bg-success">Активно</span>
        <?php elseif ($enabled): ?>
          <span class="badge bg-warning text-dark">Не налаштовано</span>
        <?php else: ?>
          <span class="badge bg-secondary">Вимкнено</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="smtp-body" style="background:#f8faff">
    <div class="small fw-semibold text-muted mb-2">Швидкий вибір провайдера</div>
    <div class="provider-grid">
      <?php foreach ($providers as $p): ?>
      <button type="button" class="provider-btn"
        onclick="setProvider('<?= htmlspecialchars($p['host'],ENT_QUOTES) ?>','<?= (int)$p['port'] ?>','<?= htmlspecialchars($p['enc'],ENT_QUOTES) ?>')">
        <?= htmlspecialchars($p['name']) ?>
        <?php if ($p['note']): ?>
        <div class="provider-note"><?= htmlspecialchars($p['note']) ?></div>
        <?php endif; ?>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="smtp-body">
    <form method="post">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save">

      <div class="d-flex align-items-center gap-3 mb-4">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtpEnabled"
            <?= $enabled ? 'checked' : '' ?> onchange="toggleFields()">
          <label class="form-check-label fw-semibold" for="smtpEnabled">Увімкнути відправку email</label>
        </div>
        <span class="text-muted small" id="enabledHint">
          <?= $enabled ? 'Листи надсилаються через SMTP' : 'Листи не надсилаються' ?>
        </span>
      </div>

      <div id="smtpFields" <?= !$enabled ? 'style="opacity:.5;pointer-events:none"' : '' ?>>

        <div class="field-row mb-3">
          <div>
            <label class="form-label small fw-semibold">SMTP сервер *</label>
            <input type="text" class="form-control" name="host" id="fHost"
              value="<?= htmlspecialchars($smtpHost) ?>" placeholder="smtp.gmail.com">
          </div>
          <div>
            <label class="form-label small fw-semibold">Порт та шифрування</label>
            <div class="input-group">
              <input type="number" class="form-control" name="port" id="fPort"
                value="<?= htmlspecialchars($smtpPort) ?>">
              <select class="form-select" name="encryption" id="fEnc" style="max-width:90px">
                <option value="tls"  <?= $smtpEnc === 'tls'  ? 'selected' : '' ?>>TLS</option>
                <option value="ssl"  <?= $smtpEnc === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>None</option>
              </select>
            </div>
          </div>
        </div>

        <div class="field-row mb-3">
          <div>
            <label class="form-label small fw-semibold">Логін (email) *</label>
            <input type="email" class="form-control" name="username" id="fUser"
              value="<?= htmlspecialchars($smtpUser) ?>"
              placeholder="your@gmail.com" autocomplete="username">
          </div>
          <div>
            <label class="form-label small fw-semibold">Пароль *</label>
            <div class="input-group">
              <input type="password" class="form-control" name="password" id="fPass"
                value="" placeholder="<?= $hasPass ? '(залишити без змін)' : 'Введіть пароль' ?>"
                autocomplete="new-password">
              <button class="btn btn-outline-secondary" type="button"
                onclick="var f=document.getElementById('fPass');f.type=f.type==='password'?'text':'password'">👁</button>
            </div>
            <?php if ($hasPass): ?>
            <div class="form-text text-success small">Пароль збережено. Залиште поле порожнім щоб не змінювати.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="field-row mb-3">
          <div>
            <label class="form-label small fw-semibold">Ім'я відправника</label>
            <input type="text" class="form-control" name="from_name"
              value="<?= htmlspecialchars($smtpName) ?>" placeholder="fly-CMS">
          </div>
          <div>
            <label class="form-label small fw-semibold">Email відправника</label>
            <input type="email" class="form-control" name="from_email"
              value="<?= htmlspecialchars($smtpFrom) ?>" placeholder="Порожньо = логін">
          </div>
        </div>

        <div class="tip-box mb-2" id="gmailTip"
          style="display:<?= (strpos($smtpHost, 'gmail') !== false) ? 'block' : 'none' ?>">
          <strong>Gmail:</strong> звичайний пароль не працює. Потрібен App Password:
          <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">
            myaccount.google.com/apppasswords
          </a><br>
          Увімкніть 2FA → App passwords → Mail → Other → скопіюйте 16-символьний код.
        </div>

        <div class="provider-tip provider-tip-warn mb-2" id="ukrTip"
          style="display:<?= (strpos($smtpHost, 'ukr.net') !== false) ? 'block' : 'none' ?>">
          <strong>ukr.net:</strong> звичайний пароль не підійде — потрібен окремий пароль для зовнішніх програм.
          <ol>
            <li>Зайдіть на <a href="https://accounts.ukr.net" target="_blank" rel="noopener">accounts.ukr.net</a></li>
            <li>Розділ <strong>«Безпека»</strong> → <strong>«Паролі для зовнішніх програм»</strong></li>
            <li>Натисніть <strong>«Додати»</strong>, введіть назву (напр. <code>fly-CMS</code>), скопіюйте згенерований пароль</li>
            <li>Вставте цей пароль у поле <code>SMTP_PASSWORD</code> вище</li>
          </ol>
        </div>

        <div class="provider-tip provider-tip-err mb-2" id="metaTip"
          style="display:<?= (strpos($smtpHost, 'meta.ua') !== false) ? 'block' : 'none' ?>">
          <strong>meta.ua:</strong> SMTP відправка вимкнена за замовчуванням — треба увімкнути в налаштуваннях акаунта.
          <ol>
            <li>Зайдіть у вебпошту <a href="https://www.meta.ua/mail/" target="_blank" rel="noopener">meta.ua/mail</a></li>
            <li><strong>Налаштування</strong> → <strong>«Зовнішній доступ»</strong> або <strong>«IMAP/POP/SMTP»</strong></li>
            <li>Увімкніть перемикач <strong>«Дозволити доступ через SMTP»</strong> і збережіть</li>
            <li>Після активації спробуйте тестову відправку нижче</li>
          </ol>
        </div>

      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Зберегти</button>
        <a href="/admin/updater.php" class="btn btn-outline-secondary">🔄 Оновлення</a>
        <a href="/admin/support.php" class="btn btn-outline-secondary">← Підтримка</a>
      </div>
    </form>
  </div>

  <div class="smtp-body" style="background:#f8faff">
    <div class="fw-semibold small mb-2">Тестова відправка</div>
    <form method="post">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="test">
      <div class="test-row">
        <input type="email" class="form-control form-control-sm" name="test_email"
          value="<?= htmlspecialchars($smtpUser) ?>" placeholder="Email для тесту">
        <button class="btn btn-outline-primary btn-sm" <?= (!$enabled || !$hasPass) ? 'disabled' : '' ?>>
          Надіслати тест
        </button>
      </div>
      <div class="form-text small mt-1">Надішле тестовий лист з поточними налаштуваннями .env</div>
    </form>
  </div>
</div>

<div class="smtp-card">
  <div class="smtp-body">
    <div class="fw-semibold small mb-2 text-muted">Поточні значення .env</div>
    <div style="font-family:monospace;font-size:.78rem;background:#1a1a2a;color:#4ade80;border-radius:7px;padding:1rem;line-height:1.8">
      <?php
      foreach (['SMTP_ENABLED','SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_FROM_NAME','SMTP_FROM_EMAIL','SMTP_ENCRYPTION'] as $k):
          $v      = isset($env[$k]) ? $env[$k] : '<не задано>';
          $masked = ($k === 'SMTP_PASSWORD' && $v !== '<не задано>') ? str_repeat('*', min(strlen($v), 12)) : htmlspecialchars($v);
      ?>
      <div><span style="color:#94a3b8"><?= htmlspecialchars($k) ?></span>=<span style="color:#fbbf24"><?= $masked ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="text-muted small mt-2">
      Файл: <code><?= htmlspecialchars($envPath) ?></code> &middot; <?= htmlspecialchars($envLocation) ?>
    </div>
  </div>
</div>

</div>

<!-- ── GitHub / Оновлення ──────────────────────────────────── -->
<div class="smtp-card">
  <div class="smtp-head">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.3rem">🔄</span>
      <div>
        <div style="font-weight:700">Оновлення CMS — GitHub</div>
        <div style="font-size:.78rem;opacity:.8">Підключення репозиторію для автооновлень</div>
      </div>
      <div class="ms-auto">
        <?php if ($ghConfigured): ?>
          <span class="badge bg-success">Налаштовано</span>
        <?php else: ?>
          <span class="badge bg-secondary">Не налаштовано</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="smtp-body">
    <form method="post">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save_github">

      <div class="field-row mb-3">
        <div>
          <label class="form-label small fw-semibold">GitHub Username / Organization <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="github_owner"
            value="<?= htmlspecialchars($ghOwner) ?>" placeholder="my-username">
          <div class="form-text small">Власник репозиторію на GitHub</div>
        </div>
        <div>
          <label class="form-label small fw-semibold">Назва репозиторію <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="github_repo"
            value="<?= htmlspecialchars($ghRepo) ?>" placeholder="fly-cms">
          <div class="form-text small">Лише назва, без URL</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label small fw-semibold">Personal Access Token</label>
        <div class="input-group">
          <input type="password" class="form-control" name="github_token"
            value="" placeholder="<?= $ghHasToken ? '(збережено, залиште порожнім щоб не змінювати)' : 'ghp_xxxxxxxxxxxx' ?>"
            autocomplete="new-password">
          <button class="btn btn-outline-secondary" type="button"
            onclick="var f=this.previousElementSibling;f.type=f.type==='password'?'text':'password'">👁</button>
        </div>
        <div class="form-text small">
          Необхідний лише для <strong>приватних</strong> репозиторіїв.
          Для публічних — залиште порожнім.<br>
          Створити: <a href="https://github.com/settings/tokens/new?scopes=repo&description=fly-CMS" target="_blank" rel="noopener">github.com/settings/tokens →</a>
          (scope: <code>repo</code> або <code>contents:read</code>)
        </div>
      </div>

      <?php if ($ghConfigured): ?>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:7px;padding:.65rem 1rem;font-size:.83rem;color:#166534;margin-bottom:1rem">
        ✅ Репозиторій: <strong><?= htmlspecialchars($ghOwner) ?>/<?= htmlspecialchars($ghRepo) ?></strong>
        &nbsp;·&nbsp;
        <a href="/admin/updater.php" style="color:#166534;font-weight:600">Перейти до оновлень →</a>
      </div>
      <?php endif; ?>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">💾 Зберегти</button>
        <a href="/admin/updater.php" class="btn btn-outline-secondary">🔄 Оновлення CMS</a>
      </div>
    </form>
  </div>

  <div class="smtp-body" style="background:#f8faff">
    <div class="fw-semibold small mb-2 text-muted">Поточні значення .env (GitHub)</div>
    <div style="font-family:monospace;font-size:.78rem;background:#1a1a2a;color:#4ade80;border-radius:7px;padding:1rem;line-height:1.8">
      <?php foreach (['GITHUB_OWNER', 'GITHUB_REPO', 'GITHUB_TOKEN'] as $k):
          $v      = isset($env[$k]) ? $env[$k] : '<не задано>';
          $masked = ($k === 'GITHUB_TOKEN' && $v !== '<не задано>') ? str_repeat('*', min(strlen($v), 12)) : htmlspecialchars($v);
      ?>
      <div><span style="color:#94a3b8"><?= htmlspecialchars($k) ?></span>=<span style="color:#fbbf24"><?= $masked ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

</div>

<script>
function setProvider(host, port, enc) {
    document.getElementById('fHost').value = host;
    document.getElementById('fPort').value = port;
    document.getElementById('fEnc').value  = enc;
    updateProviderTips(host);
}
function toggleFields() {
    var on  = document.getElementById('smtpEnabled').checked;
    var fld = document.getElementById('smtpFields');
    var ht  = document.getElementById('enabledHint');
    fld.style.opacity       = on ? '1' : '.5';
    fld.style.pointerEvents = on ? '' : 'none';
    ht.textContent = on ? 'Листи надсилаються через SMTP' : 'Листи не надсилаються';
}
document.getElementById('fHost').addEventListener('input', function() {
    updateProviderTips(this.value);
});
function updateProviderTips(host) {
    document.getElementById('gmailTip').style.display  = (host.indexOf('gmail')   !== -1) ? 'block' : 'none';
    document.getElementById('ukrTip').style.display    = (host.indexOf('ukr.net') !== -1) ? 'block' : 'none';
    document.getElementById('metaTip').style.display   = (host.indexOf('meta.ua') !== -1) ? 'block' : 'none';
}
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';