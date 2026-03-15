<?php
/**
 * fly-CMS — Діагностика проблеми завантаження файлів
 * 
 * ВИКОРИСТАННЯ: Завантажте цей файл у корінь сайту і відкрийте в браузері.
 * УВАГА: Після діагностики — ВИДАЛІТЬ цей файл!
 */

// Тільки для адміна
session_start();
if (empty($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    die('<h3>❌ Доступ заборонено. Увійдіть як адмін.</h3>');
}

header('Content-Type: text/html; charset=utf-8');

function check(string $label, bool $ok, string $detail = ''): void {
    $icon   = $ok ? '✅' : '❌';
    $color  = $ok ? '#1a7a3a' : '#c00';
    $bg     = $ok ? '#eafbea' : '#ffeaea';
    echo "<tr style='background:{$bg}'>
        <td style='padding:8px 12px;font-weight:bold;color:{$color}'>{$icon} {$label}</td>
        <td style='padding:8px 12px;font-family:monospace;font-size:13px'>" . htmlspecialchars($detail) . "</td>
    </tr>";
}

function fix_dir(string $path): void {
    if (!is_dir($path)) {
        $made = @mkdir($path, 0755, true);
        echo "<p>📁 Директорія <code>{$path}</code>: " . ($made ? "✅ створена" : "❌ не вдалося створити (перевірте права)") . "</p>";
    } else {
        $writable = is_writable($path);
        if (!$writable) {
            @chmod($path, 0755);
            $writable = is_writable($path);
        }
        echo "<p>📁 Директорія <code>{$path}</code>: " . ($writable ? "✅ існує і доступна для запису" : "❌ існує але НЕ доступна для запису") . "</p>";
    }
}

$docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$tmpDir   = ini_get('upload_tmp_dir');
$sysTmp   = sys_get_temp_dir();
$phpTmp   = ini_get('sys_temp_dir') ?: $sysTmp;

// Визначаємо реальну тимчасову директорію
$effectiveTmp = '';
if (!empty($tmpDir) && is_dir($tmpDir) && is_writable($tmpDir)) {
    $effectiveTmp = $tmpDir;
} elseif (is_dir($sysTmp) && is_writable($sysTmp)) {
    $effectiveTmp = $sysTmp;
}

// Кандидати для upload_tmp_dir (якщо системна недоступна)
$candidates = [
    $docRoot . '/data/tmp',
    $docRoot . '/tmp',
    '/tmp',
    sys_get_temp_dir(),
];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<title>Діагностика Upload</title>
<style>
body { font-family: Arial, sans-serif; margin: 30px; background: #f5f5f5; }
h2   { color: #1a569e; }
h3   { color: #333; border-bottom: 2px solid #ddd; padding-bottom: 6px; }
table { border-collapse: collapse; width: 100%; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.1); margin-bottom: 20px; }
th   { background: #1a569e; color: #fff; padding: 10px 12px; text-align: left; }
code { background: #eee; padding: 2px 5px; border-radius: 3px; }
.box { background:#fff; border-radius:6px; padding:16px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.1); }
.warn { background: #fff8e1; border-left: 4px solid #f9a825; padding: 12px 16px; border-radius: 4px; margin: 10px 0; }
.fix  { background: #e8f5e9; border-left: 4px solid #2e7d32; padding: 12px 16px; border-radius: 4px; margin: 10px 0; }
.err  { background: #ffeaea; border-left: 4px solid #c00; padding: 12px 16px; border-radius: 4px; margin: 10px 0; }
</style>
</head>
<body>
<h2>🔍 fly-CMS — Діагностика завантаження файлів</h2>
<p style="color:#888">Запущено: <?= date('Y-m-d H:i:s') ?> | PHP <?= PHP_VERSION ?> | <?= PHP_OS ?></p>

<div class="box">
<h3>1. PHP Upload конфігурація</h3>
<table>
<tr><th>Параметр</th><th>Значення</th></tr>
<?php
check('file_uploads',       ini_get('file_uploads') == '1',       ini_get('file_uploads') ?: 'Off');
check('upload_tmp_dir',     !empty($tmpDir) && is_dir($tmpDir) && is_writable($tmpDir),
      !empty($tmpDir) ? $tmpDir . (is_dir($tmpDir) ? (is_writable($tmpDir) ? ' [OK]' : ' [НЕ ДОСТУПНА ДЛЯ ЗАПИСУ]') : ' [НЕ ІСНУЄ]') : '(не вказано — використовується sys_get_temp_dir)');
check('sys_get_temp_dir()', is_dir($sysTmp) && is_writable($sysTmp),
      $sysTmp . (is_dir($sysTmp) ? (is_writable($sysTmp) ? ' [OK]' : ' [НЕ ДОСТУПНА ДЛЯ ЗАПИСУ]') : ' [НЕ ІСНУЄ]'));
check('upload_max_filesize', true, ini_get('upload_max_filesize'));
check('post_max_size',       true, ini_get('post_max_size'));
check('max_file_uploads',    true, ini_get('max_file_uploads'));
check('memory_limit',        true, ini_get('memory_limit'));
?>
</table>
</div>

<div class="box">
<h3>2. Тимчасова директорія (ефективна)</h3>
<?php if ($effectiveTmp): ?>
    <div class="fix">✅ Ефективна tmp-директорія: <code><?= htmlspecialchars($effectiveTmp) ?></code></div>
    <p>Тест запису...</p>
    <?php
    $testFile = $effectiveTmp . '/cms_test_' . uniqid() . '.tmp';
    $wrote = @file_put_contents($testFile, 'test');
    if ($wrote !== false) {
        @unlink($testFile);
        echo '<div class="fix">✅ Запис у tmp директорію працює</div>';
    } else {
        echo '<div class="err">❌ Не вдалося записати у tmp директорію — права доступу?</div>';
    }
    ?>
<?php else: ?>
    <div class="err">
        ❌ <strong>ПРОБЛЕМА ЗНАЙДЕНА:</strong> Тимчасова директорія для upload недоступна!<br>
        upload_tmp_dir = <code><?= htmlspecialchars($tmpDir ?: '(не вказано)') ?></code><br>
        sys_get_temp_dir() = <code><?= htmlspecialchars($sysTmp) ?></code>
    </div>
<?php endif; ?>
</div>

<div class="box">
<h3>3. Директорії CMS для завантажень</h3>
<?php
$uploadDirs = [
    $docRoot . '/uploads',
    $docRoot . '/uploads/cms_img',
    $docRoot . '/uploads/' . date('Y'),
    $docRoot . '/uploads/' . date('Y') . '/' . date('m'),
    $docRoot . '/uploads/' . date('Y') . '/' . date('m') . '/thumbs',
];
foreach ($uploadDirs as $dir) {
    $rel = str_replace($docRoot, '', $dir);
    $exists   = is_dir($dir);
    $writable = $exists && is_writable($dir);
    check($rel, $writable,
        $exists
            ? ($writable ? 'Існує, доступна для запису' : 'Існує, але НЕ доступна для запису (chmod 755 потрібен)')
            : 'НЕ ІСНУЄ (буде створена автоматично при першому upload якщо права дозволяють)');
}
?>
</table>
</div>

<div class="box">
<h3>4. Автовиправлення</h3>
<p>Спробуємо створити/виправити необхідні директорії:</p>
<?php
// Спочатку — tmp для PHP якщо потрібен
if (!$effectiveTmp) {
    foreach ($candidates as $c) {
        if (!is_dir($c)) @mkdir($c, 0755, true);
        if (is_dir($c) && is_writable($c)) {
            echo "<div class='fix'>✅ Знайдено робочу tmp-директорію: <code>{$c}</code></div>";
            echo "<div class='warn'>⚠️ <strong>Додайте у php.ini або .htaccess:</strong><br>";
            echo "<code>upload_tmp_dir = \"{$c}\"</code><br>";
            echo "або у .htaccess: <code>php_value upload_tmp_dir \"{$c}\"</code></div>";
            break;
        }
    }
}
// Upload директорії
foreach ($uploadDirs as $dir) {
    fix_dir($dir);
}
?>
</div>

<div class="box">
<h3>5. Рішення залежно від хостингу</h3>

<h4>Варіант А — Shared hosting (рекомендовано)</h4>
<p>Додайте у <code>.htaccess</code> в корені сайту:</p>
<pre style="background:#272822;color:#f8f8f2;padding:12px;border-radius:4px;overflow-x:auto">php_value upload_tmp_dir "<?= htmlspecialchars($docRoot . '/data/tmp') ?>"</pre>
<p>І створіть директорію <code><?= htmlspecialchars($docRoot . '/data/tmp') ?></code> вручну (SFTP) з правами 755.</p>

<h4>Варіант Б — VPS/Dedicated (php.ini)</h4>
<pre style="background:#272822;color:#f8f8f2;padding:12px;border-radius:4px;overflow-x:auto">upload_tmp_dir = /tmp
upload_max_filesize = 10M
post_max_size = 12M
file_uploads = On</pre>
<p>Після зміни: <code>sudo systemctl reload php8.x-fpm</code> або <code>sudo service apache2 restart</code></p>

<h4>Варіант В — Через config.php CMS</h4>
<p>Додайте на початок файлів <code>edit_post.php</code>, <code>edit_page.php</code>, <code>media.php</code>:</p>
<pre style="background:#272822;color:#f8f8f2;padding:12px;border-radius:4px;overflow-x:auto">// Власна tmp-директорія для upload (shared hosting)
$_cms_tmp = __DIR__ . '/data/tmp';
if (!is_dir($_cms_tmp)) mkdir($_cms_tmp, 0755, true);
ini_set('upload_tmp_dir', $_cms_tmp);</pre>

<h4>Розшифровка кодів помилок PHP Upload</h4>
<table>
<tr><th>Код</th><th>Константа</th><th>Причина</th></tr>
<?php
$errors = [
    [0, 'UPLOAD_ERR_OK',        'Успішно'],
    [1, 'UPLOAD_ERR_INI_SIZE',  'Файл перевищує upload_max_filesize у php.ini'],
    [2, 'UPLOAD_ERR_FORM_SIZE', 'Файл перевищує MAX_FILE_SIZE у формі'],
    [3, 'UPLOAD_ERR_PARTIAL',   'Файл завантажено лише частково'],
    [4, 'UPLOAD_ERR_NO_FILE',   'Файл не було вибрано'],
    [6, 'UPLOAD_ERR_NO_TMP_DIR','❌ ВАША ПОМИЛКА: Відсутня тимчасова директорія'],
    [7, 'UPLOAD_ERR_CANT_WRITE','Не вдалося записати файл на диск'],
    [8, 'UPLOAD_ERR_EXTENSION', 'PHP-розширення зупинило завантаження'],
];
foreach ($errors as [$code, $const, $desc]) {
    $bg = ($code === 6) ? 'background:#ffeaea;font-weight:bold' : '';
    echo "<tr style='{$bg}'><td style='padding:6px 12px;text-align:center'>{$code}</td>
          <td style='padding:6px 12px;font-family:monospace;font-size:12px'>{$const}</td>
          <td style='padding:6px 12px'>" . htmlspecialchars($desc) . "</td></tr>";
}
?>
</table>
</div>

<div class="err" style="margin-top:20px">
    🗑️ <strong>Не забудьте видалити цей файл після діагностики!</strong>
</div>
</body>
</html>
