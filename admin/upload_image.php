<?php
/**
 * admin/upload_image.php
 * AJAX-ендпоінт для завантаження зображень з TinyMCE (drag & drop / paste).
 * Повертає JSON { location: "/uploads/..." } — формат якого очікує TinyMCE.
 */

// Буферизуємо вивід ПЕРШИМ — щоб жодне попередження не зламало JSON
ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Перехоплюємо фатальні PHP помилки і повертаємо їх як JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'PHP Fatal: ' . $err['message'] . ' в ' . $err['file'] . ':' . $err['line']]);
    }
});

session_start();

// Очищаємо будь-який сміттєвий вивід і виставляємо заголовок
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ── Авторизація ───────────────────────────────────────────────────
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'] ?? '', ['admin', 'redaktor', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ заборонено']);
    exit;
}

// ── Підключення залежностей ───────────────────────────────────────
require_once __DIR__ . '/../data/rate_limiter.php';
rate_limit('upload_image', 60, 60);

require_once __DIR__ . '/../plugins/ukr_to_lat.php';
require_once __DIR__ . '/../data/log_action.php';

// ── Налаштування (не const — щоб уникнути "already defined" при повторному include) ──
$IMG_MAX_WIDTH  = 1920;
$IMG_MAX_HEIGHT = 1920;
$IMG_JPEG_Q     = 82;
$IMG_PNG_COMP   = 7;
$IMG_MAX_BYTES  = 20 * 1024 * 1024;

$ALLOWED_MIME = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

// ── Перевірка наявності файлу ─────────────────────────────────────
if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Файл не отримано. Переконайтесь що поле називається "file"']);
    exit;
}

$file = $_FILES['file'];

// ── Обхід UPLOAD_ERR_NO_TMP_DIR: читаємо multipart напряму ───────
// PHP на Windows/IIS може не мати доступу до системної tmp.
// Якщо tmp недоступна — парсимо тіло запиту вручну і зберігаємо
// файл одразу в uploads/, повністю минаючи тимчасову директорію.
if ($file['error'] === UPLOAD_ERR_NO_TMP_DIR) {
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        http_response_code(400);
        echo json_encode(['error' => 'Відсутня тимчасова директорія і не вдалося прочитати тіло запиту']);
        exit;
    }

    // Визначаємо boundary з Content-Type заголовка
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!preg_match('/boundary=([^\s;]+)/i', $ct, $bm)) {
        http_response_code(400);
        echo json_encode(['error' => 'Не вдалося визначити multipart boundary']);
        exit;
    }
    $boundary = $bm[1];

    // Парсимо multipart вручну
    $parts = explode('--' . $boundary, $rawInput);
    $fileData    = null;
    $origName    = 'upload.jpg';
    foreach ($parts as $part) {
        if (strpos($part, 'filename=') === false) continue;
        // Відокремлюємо заголовки від тіла
        $split = strpos($part, "\r\n\r\n");
        if ($split === false) $split = strpos($part, "\n\n");
        if ($split === false) continue;
        $headers  = substr($part, 0, $split);
        $fileData = substr($part, $split + 4); // +4 для \r\n\r\n
        // Обрізаємо \r\n в кінці
        $fileData = rtrim($fileData, "\r\n");
        // Витягуємо оригінальне ім'я файлу
        if (preg_match('/filename="([^"]+)"/i', $headers, $fm)) {
            $origName = $fm[1];
        }
        break;
    }

    if (empty($fileData)) {
        http_response_code(400);
        echo json_encode(['error' => 'Не вдалося витягти файл з тіла запиту']);
        exit;
    }

    // Визначаємо MIME з перших байтів (magic bytes)
    $magic = substr($fileData, 0, 12);
    if (substr($magic, 0, 2) === "\xFF\xD8") {
        $mimeReal = 'image/jpeg';
    } elseif (substr($magic, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $mimeReal = 'image/png';
    } elseif (substr($magic, 0, 6) === 'GIF87a' || substr($magic, 0, 6) === 'GIF89a') {
        $mimeReal = 'image/gif';
    } elseif (substr($magic, 0, 4) === 'RIFF' && substr($magic, 8, 4) === 'WEBP') {
        $mimeReal = 'image/webp';
    } else {
        http_response_code(415);
        echo json_encode(['error' => 'Дозволено тільки зображення (JPEG, PNG, GIF, WebP)']);
        exit;
    }

    if (!in_array($mimeReal, $ALLOWED_MIME, true)) {
        http_response_code(415);
        echo json_encode(['error' => 'Дозволено тільки зображення. Отримано: ' . $mimeReal]);
        exit;
    }

    if (strlen($fileData) > $IMG_MAX_BYTES) {
        http_response_code(413);
        echo json_encode(['error' => 'Файл надто великий (макс. 20 MB)']);
        exit;
    }

    // Будуємо безпечне ім'я і шлях
    $extMap   = ['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $ext      = $extMap[$mimeReal] ?? 'jpg';
    $baseName = pathinfo($origName, PATHINFO_FILENAME);
    $safeName = function_exists('ctl_transliterate') ? ctl_transliterate($baseName) : $baseName;
    $safeName = trim(preg_replace('/-+/', '-', preg_replace('/[^a-zA-Z0-9_-]/', '-', $safeName)), '-');
    $safeName = substr($safeName ?: 'image', 0, 60);
    $finalName = $safeName . '-' . substr(uniqid(), -6) . '.' . $ext;

    $uploadBase = __DIR__ . '/../uploads';
    if (!is_dir($uploadBase)) mkdir($uploadBase, 0755, true);
    $subDir    = date('Y') . '/' . date('m');
    $uploadDir = $uploadBase . '/' . $subDir;
    $thumbDir  = $uploadDir . '/thumbs';
    foreach ([$uploadDir, $thumbDir] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    $targetPath = $uploadDir . '/' . $finalName;
    $thumbPath  = $thumbDir  . '/' . $finalName;

    // Пишемо файл напряму (без tmp)
    if (file_put_contents($targetPath, $fileData, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Не вдалося зберегти файл (прямий запис)']);
        exit;
    }

    // Мініатюра і лог
    img_thumb($targetPath, $thumbPath, 400, 400);
    $sizeStr = img_format_bytes(filesize($targetPath));
    log_action("📎 Завантажено (no-tmp): {$finalName} ({$sizeStr})");

    $publicUrl = '/uploads/' . $subDir . '/' . $finalName;
    ob_clean();
    echo json_encode(['location' => $publicUrl]);
    exit;
}
// ── Кінець no-tmp fallback ────────────────────────────────────────

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'Файл перевищує upload_max_filesize у php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'Файл перевищує MAX_FILE_SIZE форми',
        UPLOAD_ERR_PARTIAL    => 'Файл завантажено частково',
        UPLOAD_ERR_NO_FILE    => 'Файл не обрано',
        UPLOAD_ERR_CANT_WRITE => 'Помилка запису на диск',
        UPLOAD_ERR_EXTENSION  => 'Завантаження зупинено PHP-розширенням',
    ];
    http_response_code(400);
    echo json_encode(['error' => $errMap[$file['error']] ?? 'Невідома помилка завантаження (код ' . $file['error'] . ')']);
    exit;
}

if ($file['size'] > $IMG_MAX_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'Файл надто великий (макс. 20 MB)']);
    exit;
}

// ── Перевірка MIME через finfo ────────────────────────────────────
if (!class_exists('finfo')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP розширення fileinfo не встановлено']);
    exit;
}
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeReal = $finfo->file($file['tmp_name']);
if (!in_array($mimeReal, $ALLOWED_MIME, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'Дозволено тільки зображення (JPEG, PNG, GIF, WebP). Отримано: ' . $mimeReal]);
    exit;
}

// ── Безпечне ім'я файлу ───────────────────────────────────────────
$origName  = $file['name'];
$ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (empty($ext)) {
    $ext = explode('/', $mimeReal)[1] ?? 'jpg';
    $ext = str_replace('jpeg', 'jpg', $ext);
}
$baseName  = pathinfo($origName, PATHINFO_FILENAME);
$safeName  = function_exists('ctl_transliterate') ? ctl_transliterate($baseName) : $baseName;
$safeName  = preg_replace('/[^a-zA-Z0-9_-]/', '-', $safeName);
$safeName  = preg_replace('/-+/', '-', $safeName);
$safeName  = trim($safeName, '-');
$safeName  = substr($safeName ?: 'image', 0, 60);
$finalName = $safeName . '-' . substr(uniqid(), -6) . '.' . $ext;

// ── Директорія завантаження ───────────────────────────────────────
$uploadBase = realpath(__DIR__ . '/../uploads');
if (!$uploadBase) {
    $uploadBase = __DIR__ . '/../uploads';
    mkdir($uploadBase, 0755, true);
    $uploadBase = realpath($uploadBase);
}
$subDir    = date('Y') . '/' . date('m');
$uploadDir = $uploadBase . '/' . $subDir;
$thumbDir  = $uploadDir . '/thumbs';

foreach ([$uploadDir, $thumbDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Не вдалося створити директорію: ' . $dir]);
        exit;
    }
}

$targetPath = $uploadDir . '/' . $finalName;
$thumbPath  = $thumbDir  . '/' . $finalName;

// ── Стиснення та збереження ───────────────────────────────────────
$compressed = img_compress($file['tmp_name'], $targetPath, $mimeReal, $IMG_MAX_WIDTH, $IMG_MAX_HEIGHT, $IMG_JPEG_Q, $IMG_PNG_COMP);

if (!$compressed) {
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Не вдалося зберегти файл на диск']);
        exit;
    }
}

// ── Мініатюра ─────────────────────────────────────────────────────
img_thumb($targetPath, $thumbPath, 400, 400);

// ── Відповідь ─────────────────────────────────────────────────────
$publicUrl = '/uploads/' . $subDir . '/' . $finalName;
$sizeStr   = img_format_bytes(file_exists($targetPath) ? filesize($targetPath) : 0);

log_action("📎 Завантажено: {$finalName} ({$sizeStr})");

ob_clean(); // фінальне очищення буферу перед відповіддю
if (function_exists('fly_do_action')) {
    fly_do_action('cms.media.uploaded', $savedPath ?? '', $publicUrl);
}
echo json_encode(['location' => $publicUrl]);
exit;

// ═══════════════════════════════════════════════════════════════════
// ФУНКЦІЇ
// ═══════════════════════════════════════════════════════════════════

function img_compress(string $src, string $dst, string $mime, int $maxW, int $maxH, int $jpegQ, int $pngC): bool {
    if (class_exists('Imagick')) {
        return img_compress_imagick($src, $dst, $mime, $maxW, $maxH, $jpegQ, $pngC);
    }
    if (extension_loaded('gd')) {
        return img_compress_gd($src, $dst, $mime, $maxW, $maxH, $jpegQ, $pngC);
    }
    return false;
}

function img_compress_imagick(string $src, string $dst, string $mime, int $maxW, int $maxH, int $jpegQ, int $pngC): bool {
    try {
        $im = new Imagick($src);
        $im->autoOrient();
        $im->stripImage();

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w > $maxW || $h > $maxH) {
            $im->resizeImage($maxW, $maxH, Imagick::FILTER_LANCZOS, 1, true);
        }

        switch ($mime) {
            case 'image/jpeg': case 'image/jpg':
                $im->setImageFormat('jpeg');
                $im->setImageCompression(Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality($jpegQ);
                $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                break;
            case 'image/png':
                $im->setImageFormat('png');
                $im->setImageCompressionQuality($pngC * 10);
                break;
            case 'image/webp':
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality($jpegQ);
                break;
        }

        $im->writeImage($dst);
        $im->clear();
        $im->destroy();
        return true;
    } catch (Exception $e) {
        error_log('fly-CMS Imagick: ' . $e->getMessage());
        return false;
    }
}

function img_compress_gd(string $src, string $dst, string $mime, int $maxW, int $maxH, int $jpegQ, int $pngC): bool {
    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h, $type] = $info;

    $im = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_GIF  => @imagecreatefromgif($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$im) return false;

    // EXIF авто-орієнтація
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o    = $exif['Orientation'] ?? 1;
        if ($o === 3) $im = imagerotate($im, 180, 0);
        elseif ($o === 6) $im = imagerotate($im, -90, 0);
        elseif ($o === 8) $im = imagerotate($im, 90, 0);
        $w = imagesx($im);
        $h = imagesy($im);
    }

    if ($w > $maxW || $h > $maxH) {
        $ratio   = min($maxW / $w, $maxH / $h);
        $nW      = max(1, (int)round($w * $ratio));
        $nH      = max(1, (int)round($h * $ratio));
        $resized = imagecreatetruecolor($nW, $nH);
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $trans = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $nW, $nH, $trans);
        }
        imagecopyresampled($resized, $im, 0, 0, 0, 0, $nW, $nH, $w, $h);
        imagedestroy($im);
        $im = $resized;
    }

    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($im, $dst, $jpegQ),
        IMAGETYPE_PNG  => imagepng($im, $dst, $pngC),
        IMAGETYPE_GIF  => imagegif($im, $dst),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($im, $dst, $jpegQ) : false,
        default        => false,
    };
    imagedestroy($im);
    return (bool)$ok;
}

function img_thumb(string $src, string $dst, int $maxW, int $maxH): bool {
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($src);
            $im->thumbnailImage($maxW, $maxH, true);
            $im->writeImage($dst);
            $im->clear(); $im->destroy();
            return true;
        } catch (Exception $e) {}
    }
    if (!extension_loaded('gd')) return false;

    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h, $type] = $info;

    $ratio  = min($maxW / $w, $maxH / $h);
    $nW     = max(1, (int)round($w * $ratio));
    $nH     = max(1, (int)round($h * $ratio));
    $src_im = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_GIF  => @imagecreatefromgif($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$src_im) return false;

    $dst_im = imagecreatetruecolor($nW, $nH);
    if ($type === IMAGETYPE_PNG) { imagealphablending($dst_im, false); imagesavealpha($dst_im, true); }
    imagecopyresampled($dst_im, $src_im, 0, 0, 0, 0, $nW, $nH, $w, $h);

    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst_im, $dst, 80),
        IMAGETYPE_PNG  => imagepng($dst_im, $dst, 7),
        IMAGETYPE_GIF  => imagegif($dst_im, $dst),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst_im, $dst, 80) : false,
        default        => false,
    };
    imagedestroy($src_im);
    imagedestroy($dst_im);
    return (bool)$ok;
}

function img_format_bytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024, 1) . ' KB';
    return $b . ' B';
}