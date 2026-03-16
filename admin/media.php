<?php
// admin/media.php
ob_start();

session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
    ob_end_clean();
    header('Location: /templates/login.php');
    exit;
}

// Підключення файлів
$functionsFile = __DIR__ . '/../admin/functions.php';
if (file_exists($functionsFile)) require_once $functionsFile;

$translitFile = __DIR__ . '/../plugins/ukr_to_lat.php';
if (file_exists($translitFile)) require_once $translitFile;

$logFile = __DIR__ . '/../data/log_action.php';
if (file_exists($logFile)) require_once $logFile;

// === НАЛАШТУВАННЯ ===
$uploadBaseDir = __DIR__ . '/../uploads';
$excludeDir = realpath($uploadBaseDir . '/cms_img');
$excludeQrDir = realpath($uploadBaseDir . '/qr');
$baseUrl = '/uploads';
$message = '';
$messageType = '';

$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'невідомо';
$itemsPerPage = 24;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// === ФУНКЦІЇ ===

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = array(
        'pdf' => '📄', 'doc' => '📝', 'docx' => '📝',
        'xls' => '📊', 'xlsx' => '📊', 'ppt' => '📽️', 'pptx' => '📽️',
        'zip' => '🗜️', 'rar' => '🗜️', '7z' => '🗜️',
        'mp3' => '🎵', 'wav' => '🎵', 'mp4' => '🎬', 'avi' => '🎬',
        'txt' => '📃', 'html' => '🌐', 'css' => '🎨', 'js' => '⚙️'
    );
    return isset($icons[$ext]) ? $icons[$ext] : '📄';
}

function resizeImage($sourcePath, $targetPath, $maxWidth = 1920, $quality = 85) {
    if (!extension_loaded('gd')) return copy($sourcePath, $targetPath);
    if (!file_exists($sourcePath)) return false;
    
    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false) return false;
    
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $type = $imageInfo[2];
    
    if ($width <= $maxWidth) {
        return copy($sourcePath, $targetPath);
    }
    
    $newWidth = $maxWidth;
    $newHeight = floor($height * ($maxWidth / $width));
    
    $src = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG: $src = @imagecreatefrompng($sourcePath); break;
        case IMAGETYPE_GIF: $src = @imagecreatefromgif($sourcePath); break;
        case IMAGETYPE_WEBP: 
            if (function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($sourcePath);
            }
            break;
        default: return false;
    }
    
    if (!$src) return false;
    
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }
    
    if ($type === IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        if ($transparent !== false) {
            imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
        }
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    $result = false;
    switch ($type) {
        case IMAGETYPE_JPEG: $result = imagejpeg($dst, $targetPath, $quality); break;
        case IMAGETYPE_PNG: $result = imagepng($dst, $targetPath, floor($quality/10)); break;
        case IMAGETYPE_GIF: $result = imagegif($dst, $targetPath); break;
        case IMAGETYPE_WEBP: 
            if (function_exists('imagewebp')) {
                $result = imagewebp($dst, $targetPath, $quality);
            }
            break;
    }
    
    imagedestroy($src);
    imagedestroy($dst);
    
    return $result;
}

function createThumbnail($sourcePath, $targetPath, $maxWidth = 300, $maxHeight = 300) {
    if (!extension_loaded('gd')) return false;
    if (!file_exists($sourcePath)) return false;
    
    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false) return false;
    
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $type = $imageInfo[2];
    
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = floor($width * $ratio);
    $newHeight = floor($height * $ratio);
    
    $src = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG: $src = @imagecreatefrompng($sourcePath); break;
        case IMAGETYPE_GIF: $src = @imagecreatefromgif($sourcePath); break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($sourcePath);
            }
            break;
        default: return false;
    }
    
    if (!$src) return false;
    
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }
    
    if ($type === IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    $result = false;
    switch ($type) {
        case IMAGETYPE_JPEG: $result = imagejpeg($dst, $targetPath, 80); break;
        case IMAGETYPE_PNG: $result = imagepng($dst, $targetPath, 8); break;
        case IMAGETYPE_GIF: $result = imagegif($dst, $targetPath); break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagewebp')) {
                $result = imagewebp($dst, $targetPath, 80);
            }
            break;
    }
    
    imagedestroy($src);
    imagedestroy($dst);
    
    return $result;
}

function getAllMediaFiles($dir, $excludeDir) {
    $files = array();
    
    if (!is_dir($dir)) return $files;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $realPath = $fileInfo->getRealPath();
                
                if ($excludeDir && strpos($realPath, $excludeDir) === 0) {
					continue;
				}
                
                if (strpos($realPath, '/thumbs/') !== false) {
                    continue;
                }
                
                if (strpos($fileInfo->getFilename(), 'temp_') === 0) {
                    continue;
                }

                // Пропускаємо приховані файли (.htaccess, .env, .gitignore тощо)
                if (strpos($fileInfo->getFilename(), '.') === 0) {
                    continue;
                }
                
                $relativePath = str_replace(realpath(__DIR__ . '/../'), '', $realPath);
                $relativePath = str_replace('\\', '/', $relativePath);
                $url = '/' . ltrim($relativePath, '/');
                
                $thumbPath = dirname($realPath) . '/thumbs/' . basename($realPath);
                $hasThumb = file_exists($thumbPath);
                
                $files[] = array(
                    'path' => $relativePath,
                    'full_path' => $realPath,
                    'name' => $fileInfo->getFilename(),
                    'url' => $url,
                    'size' => filesize($realPath),
                    'size_formatted' => formatFileSize(filesize($realPath)),
                    'modified' => filemtime($realPath),
                    'icon' => getFileIcon($fileInfo->getFilename()),
                    'has_thumb' => $hasThumb
                );
            }
        }
    } catch (Exception $e) {
        error_log('Помилка при отриманні файлів: ' . $e->getMessage());
    }
    
    return $files;
}

function processUploadedFile($tmpPath, $originalName, $uploadDir, $thumbDir) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);

    $safeName = function_exists('ctl_transliterate') ? ctl_transliterate($baseName) : $baseName;
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $safeName);
    $safeName = preg_replace('/-+/', '-', $safeName);
    $safeName = trim($safeName, '-');
    $safeName = substr($safeName, 0, 50);
    if (empty($safeName)) $safeName = 'file';

    $finalName = $safeName . '-' . uniqid() . '.' . $extension;
    $targetPath = $uploadDir . '/' . $finalName;
    
    if (move_uploaded_file($tmpPath, $targetPath)) {
        
        $imageExtensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        if (in_array($extension, $imageExtensions)) {
            $resizedPath = $uploadDir . '/' . pathinfo($finalName, PATHINFO_FILENAME) . '-large.' . $extension;
            if (resizeImage($targetPath, $resizedPath, 1920, 85)) {
                rename($resizedPath, $targetPath);
            }
            
            $thumbPath = $thumbDir . '/' . $finalName;
            createThumbnail($targetPath, $thumbPath, 300, 300);
        }
        
        if (function_exists('log_action')) {
            log_action("Завантажено файл: " . $originalName, $_SESSION['username']);
        }
        
        return array('success' => true, 'name' => $finalName, 'original' => $originalName);
    }
    
    return array('success' => false, 'error' => 'Помилка збереження');
}

// === ОБРОБКА AJAX ЗАПИТІВ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); // очищаємо будь-який HTML що міг потрапити в буфер
    header('Content-Type: application/json; charset=utf-8');
    
    // AJAX завантаження
    if (isset($_FILES['media_files'])) {
        $year = date('Y');
        $month = date('m');
        $uploadDir = $uploadBaseDir . '/' . $year . '/' . $month;
        $thumbDir = $uploadDir . '/thumbs';

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

        $results = array();
        $files = $_FILES['media_files'];

        // ── Обхід UPLOAD_ERR_NO_TMP_DIR для Windows/IIS ──────────────
        // Якщо PHP не може записати у системну tmp — парсимо multipart вручну
        $noTmpErrors = 0;
        if (is_array($files['error'])) {
            foreach ($files['error'] as $e) { if ($e === UPLOAD_ERR_NO_TMP_DIR) $noTmpErrors++; }
        } elseif ($files['error'] === UPLOAD_ERR_NO_TMP_DIR) {
            $noTmpErrors = 1;
        }

        if ($noTmpErrors > 0) {
            // Парсимо multipart вручну з php://input
            $rawInput = file_get_contents('php://input');
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if ($rawInput && preg_match('/boundary=([^\s;]+)/i', $ct, $bm)) {
                $boundary = $bm[1];
                $parts = explode('--' . $boundary, $rawInput);
                foreach ($parts as $part) {
                    if (strpos($part, 'filename=') === false) continue;
                    $split = strpos($part, "\r\n\r\n");
                    if ($split === false) continue;
                    $headers  = substr($part, 0, $split);
                    $fileData = rtrim(substr($part, $split + 4), "\r\n");
                    if (empty($fileData)) continue;
                    $origName = 'upload.jpg';
                    if (preg_match('/filename="([^"]+)"/i', $headers, $fm)) $origName = $fm[1];

                    // magic bytes → MIME
                    $magic = substr($fileData, 0, 12);
                    if (substr($magic,0,2)==="\xFF\xD8") $mime='image/jpeg';
                    elseif (substr($magic,0,8)==="\x89PNG\r\n\x1a\n") $mime='image/png';
                    elseif (substr($magic,0,6)==='GIF87a'||substr($magic,0,6)==='GIF89a') $mime='image/gif';
                    elseif (substr($magic,0,4)==='RIFF'&&substr($magic,8,4)==='WEBP') $mime='image/webp';
                    else $mime = 'application/octet-stream';

                    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    $ext = $extMap[$mime] ?? strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                    $baseName = pathinfo($origName, PATHINFO_FILENAME);
                    $safeName = function_exists('ctl_transliterate') ? ctl_transliterate($baseName) : $baseName;
                    $safeName = trim(preg_replace('/-+/','-',preg_replace('/[^a-zA-Z0-9_-]/.','-',$safeName)),'-');
                    $safeName = substr($safeName ?: 'file', 0, 50);
                    $finalName = $safeName . '-' . uniqid() . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $finalName;

                    if (file_put_contents($targetPath, $fileData, LOCK_EX) !== false) {
                        $imageExtensions = array('jpg','jpeg','png','gif','webp');
                        if (in_array($ext, $imageExtensions)) {
                            $thumbPath = $thumbDir . '/' . $finalName;
                            createThumbnail($targetPath, $thumbPath, 300, 300);
                        }
                        if (function_exists('log_action')) log_action("Завантажено файл: ".$origName, $_SESSION['username']);
                        $results[] = array('success'=>true,'name'=>$finalName,'original'=>$origName);
                    } else {
                        $results[] = array('success'=>false,'error'=>'Помилка прямого запису: '.$origName);
                    }
                }
            }
            if (empty($results)) {
                $results[] = array('success'=>false,'error'=>'Відсутня тимчасова директорія і не вдалося прочитати тіло запиту');
            }
            echo json_encode(array('success' => true, 'results' => $results));
            exit;
        }
        // ── Кінець no-tmp fallback ────────────────────────────────────
        
        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $result = processUploadedFile($files['tmp_name'][$i], $files['name'][$i], $uploadDir, $thumbDir);
                    $results[] = $result;
                } else {
                    $results[] = array('success' => false, 'error' => 'Помилка завантаження (код ' . $files['error'][$i] . ')');
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $result = processUploadedFile($files['tmp_name'], $files['name'], $uploadDir, $thumbDir);
                $results[] = $result;
            } else {
                $results[] = array('success' => false, 'error' => 'Помилка завантаження (код ' . $files['error'] . ')');
            }
        }
        
        echo json_encode(array('success' => true, 'results' => $results));
        exit;
    }
    
    // Отримання деталей файлу
    if (isset($_POST['action']) && $_POST['action'] === 'get_details') {
        $file = isset($_POST['file']) ? $_POST['file'] : '';
        if (empty($file)) {
            echo json_encode(array('success' => false));
            exit;
        }
        
        $fullPath = realpath(__DIR__ . '/../' . ltrim($file, '/\\'));
        if (!$fullPath || !file_exists($fullPath)) {
            echo json_encode(array('success' => false));
            exit;
        }
        
        $info = pathinfo($fullPath);
        $ext = strtolower($info['extension']);
        
        $details = array(
            'name' => $info['basename'],
            'path' => str_replace(realpath(__DIR__ . '/..'), '', $fullPath),
            'size' => formatFileSize(filesize($fullPath)),
            'modified' => date('d.m.Y H:i:s', filemtime($fullPath)),
            'created' => date('d.m.Y H:i:s', filectime($fullPath)),
            'type' => $ext,
            'mime' => function_exists('mime_content_type') ? mime_content_type($fullPath) : 'unknown'
        );
        
        $imageExtensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg');
        if (in_array($ext, $imageExtensions)) {
            $size = @getimagesize($fullPath);
            if ($size) {
                $details['dimensions'] = $size[0] . ' x ' . $size[1];
                $details['width'] = $size[0];
                $details['height'] = $size[1];
                $details['bits'] = isset($size['bits']) ? $size['bits'] : 'unknown';
                $details['channels'] = isset($size['channels']) ? $size['channels'] : 'unknown';
                
                if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($fullPath);
                    if ($exif) {
                        $details['exif'] = array(
                            'make' => isset($exif['Make']) ? $exif['Make'] : null,
                            'model' => isset($exif['Model']) ? $exif['Model'] : null,
                            'datetime' => isset($exif['DateTime']) ? $exif['DateTime'] : null,
                            'iso' => isset($exif['ISOSpeedRatings']) ? $exif['ISOSpeedRatings'] : null,
                            'focal' => isset($exif['FocalLength']) ? $exif['FocalLength'] : null,
                            'aperture' => isset($exif['COMPUTED']['ApertureFNumber']) ? $exif['COMPUTED']['ApertureFNumber'] : null
                        );
                    }
                }
            }
        }
        
        echo json_encode(array('success' => true, 'details' => $details));
        exit;
    }
    
    // Інші AJAX дії
    if (isset($_POST['action'])) {
        
        if ($_POST['action'] === 'bulk_delete' && isset($_POST['files'])) {
            $files = json_decode($_POST['files'], true);
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($files as $file) {
                $fileToDelete = realpath(__DIR__ . '/../' . ltrim($file, '/\\'));
                $uploadsBase = realpath($uploadBaseDir);
                $excludeReal = realpath($excludeDir);

                if ($fileToDelete && strpos($fileToDelete, $uploadsBase) === 0 && 
                    strpos($fileToDelete, $excludeReal) !== 0 && file_exists($fileToDelete)) {
                    
                    $thumbPath = dirname($fileToDelete) . '/thumbs/' . basename($fileToDelete);
                    if (file_exists($thumbPath)) {
                        unlink($thumbPath);
                    }
                    
                    if (unlink($fileToDelete)) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } else {
                    $errorCount++;
                }
            }
            
            echo json_encode(array(
                'success' => true,
                'deleted' => $successCount,
                'errors' => $errorCount
            ));
            exit;
        }
        
        if ($_POST['action'] === 'delete' && isset($_POST['file'])) {
            $fileToDelete = realpath(__DIR__ . '/../' . ltrim($_POST['file'], '/\\'));
            $uploadsBase = realpath($uploadBaseDir);
            $excludeReal = realpath($excludeDir);

            if ($fileToDelete && strpos($fileToDelete, $uploadsBase) === 0 && 
                strpos($fileToDelete, $excludeReal) !== 0 && file_exists($fileToDelete)) {
                
                $thumbPath = dirname($fileToDelete) . '/thumbs/' . basename($fileToDelete);
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
                
                if (unlink($fileToDelete)) {
                    echo json_encode(array('success' => true));
                    exit;
                }
            }
            echo json_encode(array('success' => false, 'error' => 'Помилка видалення'));
            exit;
        }
        
        if ($_POST['action'] === 'rename' && isset($_POST['file']) && isset($_POST['new_name'])) {
            $oldPath = realpath(__DIR__ . '/../' . ltrim($_POST['file'], '/\\'));
            $newName = basename($_POST['new_name']);
            $newPath = dirname($oldPath) . '/' . $newName;
            
            $uploadsBase = realpath($uploadBaseDir);
            $excludeReal = realpath($excludeDir);

            if ($oldPath && strpos($oldPath, $uploadsBase) === 0 && strpos($oldPath, $excludeReal) !== 0) {
                $oldThumb = dirname($oldPath) . '/thumbs/' . basename($oldPath);
                $newThumb = dirname($newPath) . '/thumbs/' . $newName;
                
                if (file_exists($oldThumb)) {
                    rename($oldThumb, $newThumb);
                }
                
                if (rename($oldPath, $newPath)) {
                    echo json_encode(array('success' => true));
                    exit;
                }
            }
            echo json_encode(array('success' => false, 'error' => 'Помилка перейменування'));
            exit;
        }
        
        exit;
    }
}

// === ОТРИМАННЯ ТА ФІЛЬТРАЦІЯ ФАЙЛІВ ===
$allMediaFiles = getAllMediaFiles($uploadBaseDir, $excludeDir);

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';
if ($sort === 'name_asc') {
    usort($allMediaFiles, function($a, $b) { return strcmp($a['name'], $b['name']); });
} elseif ($sort === 'name_desc') {
    usort($allMediaFiles, function($a, $b) { return strcmp($b['name'], $a['name']); });
} elseif ($sort === 'date_asc') {
    usort($allMediaFiles, function($a, $b) { return $a['modified'] - $b['modified']; });
} else {
    usort($allMediaFiles, function($a, $b) { return $b['modified'] - $a['modified']; });
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$filteredFiles = array();
foreach ($allMediaFiles as $file) {
    if (!empty($search) && stripos($file['name'], $search) === false) continue;
    
    if ($filter === 'images' && !preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file['name'])) continue;
    if ($filter === 'documents' && !preg_match('/\.(pdf|doc|docx|xls|xlsx|ppt|pptx|txt)$/i', $file['name'])) continue;
    if ($filter === 'video' && !preg_match('/\.(mp4|webm|ogg|avi|mov)$/i', $file['name'])) continue;
    if ($filter === 'audio' && !preg_match('/\.(mp3|wav|ogg)$/i', $file['name'])) continue;
    if ($filter === 'archives' && !preg_match('/\.(zip|rar|7z|tar|gz)$/i', $file['name'])) continue;
    
    $filteredFiles[] = $file;
}

$totalItems = count($filteredFiles);
$totalPages = $itemsPerPage > 0 ? ceil($totalItems / $itemsPerPage) : 1;
$offset = ($page - 1) * $itemsPerPage;
$mediaFiles = array_slice($filteredFiles, $offset, $itemsPerPage);

ob_start();
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Медіа бібліотека</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #2271b1;
            --primary-hover: #135e96;
            --success: #00a32a;
            --error: #d63638;
            --warning: #dba617;
        }
        
        body {
            background: #f0f0f1;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        
        .media-library {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 20px;
        }
        
        .media-header {
            background: #fff;
            border-bottom: 1px solid #c3c4c7;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .media-header h1 {
            font-size: 23px;
            font-weight: 400;
            margin: 0;
            color: #1d2327;
        }
        
        .media-toolbar {
            background: #fff;
            border-bottom: 1px solid #c3c4c7;
            padding: 15px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .media-item {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .media-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        
        .media-item.selected {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(34,113,177,0.25);
        }
        
        .media-preview {
            height: 160px;
            background: #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            border-bottom: 1px solid #f0f0f1;
        }
        
        .media-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .media-preview .file-icon {
            font-size: 48px;
            text-align: center;
        }
        
        .media-preview .file-icon span {
            display: block;
            font-size: 11px;
            color: #646970;
            margin-top: 5px;
            text-transform: uppercase;
        }
        
        .media-check {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 24px;
            height: 24px;
            background: #fff;
            border: 2px solid #c3c4c7;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
            font-size: 14px;
            color: #fff;
            transition: all 0.2s;
        }
        
        .media-item.selected .media-check {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .media-check::after {
            content: "✓";
            display: block;
            font-weight: bold;
        }
        
        .media-info {
            padding: 12px;
            background: #fff;
        }
        
        .media-name {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #1d2327;
        }
        
        .media-meta {
            font-size: 11px;
            color: #646970;
            display: flex;
            justify-content: space-between;
        }
        
        .media-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            display: none;
            gap: 4px;
            z-index: 5;
        }
        
        .media-item:hover .media-actions {
            display: flex;
        }
        
        .media-action-btn {
            width: 32px;
            height: 32px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #1d2327;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .media-action-btn:hover {
            background: #f0f0f1;
            border-color: #8c8f94;
            transform: scale(1.1);
        }
        
        .media-action-btn.delete:hover {
            background: var(--error);
            border-color: var(--error);
            color: #fff;
        }
        
        .media-bulk-actions {
            background: #f0f0f1;
            padding: 10px 20px;
            border-bottom: 1px solid #c3c4c7;
            display: none;
            align-items: center;
            gap: 15px;
        }
        
        .media-bulk-actions.active {
            display: flex;
        }
        
        .upload-area {
            border: 3px dashed #c3c4c7;
            background: #f8f9fa;
            padding: 40px;
            text-align: center;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .upload-area:hover {
            border-color: var(--primary);
            background: #f0f7ff;
        }
        
        .upload-area.dragover {
            border-color: var(--primary);
            background: #e3f0ff;
            transform: scale(1.02);
        }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-bar .form-select {
            width: auto;
            min-width: 150px;
            border-radius: 6px;
        }
        
        .search-box {
            display: flex;
            gap: 5px;
        }
        
        .search-box input {
            border-radius: 6px;
        }
        
        .search-box button {
            border-radius: 6px;
        }
        
        .pagination {
            margin: 20px;
            display: flex;
            justify-content: center;
            gap: 5px;
        }
        
        .page-link {
            padding: 8px 13px;
            background: #f6f7f7;
            border: 1px solid #c3c4c7;
            color: var(--primary);
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        
        .page-link:hover {
            background: #f0f0f1;
        }
        
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #dee2e6;
            padding: 1.2rem 1.5rem;
            border-radius: 16px 16px 0 0;
        }
        
        .modal-header h5 {
            font-weight: 600;
            color: #1d2327;
        }
        
        .modal-body {
            padding: 0;
        }
        
        .modal-footer {
            border-top: 1px solid #dee2e6;
            padding: 1.2rem 1.5rem;
            background: #f8f9fa;
            border-radius: 0 0 16px 16px;
        }
        
        .badge {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f0f0f1;
            color: #50575e;
        }
        
        .upload-progress {
            margin-top: 20px;
            display: none;
        }
        
        .upload-progress .progress {
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
            background: #f0f0f1;
        }
        
        .upload-progress .progress-bar {
            background: linear-gradient(90deg, var(--primary), #4a9bff);
            transition: width 0.3s ease;
            line-height: 30px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .upload-status {
            margin-top: 15px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .upload-status-item {
            padding: 8px 12px;
            margin-bottom: 5px;
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.3s ease;
        }
        
        .upload-status-item.success {
            background: #edfaef;
            border-left: 4px solid var(--success);
            color: #00450c;
        }
        
        .upload-status-item.error {
            background: #fcf0f1;
            border-left: 4px solid var(--error);
            color: #8a1f1f;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .file-details-compact {
            font-size: 13px;
        }
        
        .file-details-compact .detail-row {
            display: flex;
            margin-bottom: 12px;
            padding: 4px 0;
            border-bottom: 1px dashed #e9ecef;
        }
        
        .file-details-compact .detail-label {
            width: 90px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .file-details-compact .detail-value {
            flex: 1;
            color: #1d2327;
            font-weight: 400;
        }
        
        .file-details-compact .badge-dimensions {
            background: #e7f3ff;
            color: var(--primary);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }
        
        #previewImageContainer {
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            background-image: linear-gradient(45deg, #e9ecef 25%, transparent 25%),
                              linear-gradient(-45deg, #e9ecef 25%, transparent 25%),
                              linear-gradient(45deg, transparent 75%, #e9ecef 75%),
                              linear-gradient(-45deg, transparent 75%, #e9ecef 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
        }
        
        #previewImage {
            transition: transform 0.3s ease;
            cursor: zoom-in;
            max-width: 100%;
            max-height: 70vh;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }
        
        #previewImage.zoomed {
            transform: scale(1.5);
            cursor: zoom-out;
        }
        
        #previewInfoContainer {
            background: #fff;
            border-left: 1px solid #dee2e6;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideInRight 0.3s ease;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .copy-tooltip {
            position: fixed;
            background: #1d2327;
            color: #fff;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            z-index: 10000;
            pointer-events: none;
            animation: fadeInOut 1.5s ease;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(10px); }
            15% { opacity: 1; transform: translateY(0); }
            85% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-10px); }
        }
        
        @media (max-width: 768px) {
            .media-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 10px;
                padding: 10px;
            }
            
            .media-preview {
                height: 120px;
            }
            
            .media-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .filter-bar .form-select {
                width: 100%;
            }
            
            .search-box {
                width: 100%;
            }
            
            .search-box input {
                flex: 1;
            }
            
            .toast-notification {
                left: 20px;
                right: 20px;
                min-width: auto;
            }
            
            #previewImageContainer {
                min-height: 300px;
            }
            
            .row.g-0 {
                flex-direction: column;
            }
            
            .col-md-8, .col-md-4 {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="media-library">
        <!-- Заголовок -->
        <div class="media-header">
            <h1><i class="bi bi-images me-2"></i>Медіа бібліотека</h1>
            <div>
                <span class="badge me-2"><i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($username); ?></span>
                <button class="btn btn-primary btn-sm" onclick="showUploadModal()">
                    <i class="bi bi-cloud-upload me-1"></i> Додати файл
                </button>
            </div>
        </div>

        <!-- Панель інструментів -->
        <div class="media-toolbar">
            <div class="filter-bar">
                <select class="form-select" onchange="applyFilter(this.value)">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Усі файли</option>
                    <option value="images" <?php echo $filter === 'images' ? 'selected' : ''; ?>>Зображення</option>
                    <option value="documents" <?php echo $filter === 'documents' ? 'selected' : ''; ?>>Документи</option>
                    <option value="video" <?php echo $filter === 'video' ? 'selected' : ''; ?>>Відео</option>
                    <option value="audio" <?php echo $filter === 'audio' ? 'selected' : ''; ?>>Аудіо</option>
                    <option value="archives" <?php echo $filter === 'archives' ? 'selected' : ''; ?>>Архіви</option>
                </select>

                <select class="form-select" onchange="applySort(this.value)">
                    <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Найновіші</option>
                    <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Найстаріші</option>
                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>За назвою (А-Я)</option>
                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>За назвою (Я-А)</option>
                </select>

                <div class="search-box">
                    <input type="text" class="form-control" placeholder="Пошук файлів..." id="searchInput" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-primary" onclick="searchFiles()"><i class="bi bi-search"></i></button>
                </div>
            </div>
            
            <div>
                <button class="btn btn-outline-secondary btn-sm" onclick="selectAll()">
                    <i class="bi bi-check-all me-1"></i>Вибрати всі
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                    <i class="bi bi-x-circle me-1"></i>Скасувати
                </button>
            </div>
        </div>

        <!-- Масові дії -->
        <div class="media-bulk-actions" id="bulkActions">
            <i class="bi bi-check-circle-fill text-primary me-2"></i>
            <span>Вибрано <strong id="selectedCount">0</strong> файлів</span>
            <button class="btn btn-danger btn-sm ms-auto" onclick="bulkDelete()">
                <i class="bi bi-trash me-1"></i> Видалити вибрані
            </button>
        </div>

        <!-- Сітка файлів -->
        <?php if (!empty($mediaFiles)): ?>
            <div class="media-grid" id="mediaGrid">
                <?php foreach ($mediaFiles as $file): 
                    $isImage = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file['name']);
                    $thumbUrl = $file['url'];
                    
                    if ($isImage && $file['has_thumb']) {
                        $pathParts = pathinfo($file['url']);
                        $thumbUrl = $pathParts['dirname'] . '/thumbs/' . $pathParts['basename'];
                    }
                ?>
                    <div class="media-item" data-url="<?php echo htmlspecialchars($file['url']); ?>" data-name="<?php echo htmlspecialchars($file['name']); ?>">
                        <div class="media-check" onclick="toggleSelect(this, event)"></div>
                        
                        <div class="media-actions">
                            <a href="#" class="media-action-btn" onclick="previewFile('<?php echo htmlspecialchars($file['url']); ?>'); return false;" title="Переглянути">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="#" class="media-action-btn" onclick="copyUrl('<?php echo htmlspecialchars($file['url']); ?>', event); return false;" title="Копіювати URL">
                                <i class="bi bi-link"></i>
                            </a>
                            <a href="#" class="media-action-btn delete" onclick="deleteFile('<?php echo htmlspecialchars($file['url']); ?>', this); return false;" title="Видалити">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>

                        <div class="media-preview" onclick="previewFile('<?php echo htmlspecialchars($file['url']); ?>')">
                            <?php if ($isImage): ?>
                                <img src="<?php echo htmlspecialchars($thumbUrl); ?>" alt="<?php echo htmlspecialchars($file['name']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="file-icon">
                                    <?php echo $file['icon']; ?>
                                    <span><?php echo strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="media-info">
                            <div class="media-name" title="<?php echo htmlspecialchars($file['name']); ?>">
                                <?php echo htmlspecialchars($file['name']); ?>
                            </div>
                            <div class="media-meta">
                                <span><i class="bi bi-file-earmark me-1"></i><?php echo $file['size_formatted']; ?></span>
                                <span><i class="bi bi-calendar me-1"></i><?php echo date('d.m.Y', $file['modified']); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагінація -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <a href="?page=1&filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>" 
                       class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                        <i class="bi bi-chevron-double-left"></i>
                    </a>
                    
                    <?php 
                    $start = max(1, $page - 3);
                    $end = min($totalPages, $page + 3);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <a href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <a href="?page=<?php echo $totalPages; ?>&filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>" 
                       class="page-link <?php echo $page == $totalPages ? 'disabled' : ''; ?>">
                        <i class="bi bi-chevron-double-right"></i>
                    </a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-5">
                <div class="display-1 text-muted mb-3"><i class="bi bi-cloud-arrow-up"></i></div>
                <h3>Файлів не знайдено</h3>
                <p class="text-muted">Завантажте перший файл, щоб розпочати роботу</p>
                <button class="btn btn-primary" onclick="showUploadModal()">
                    <i class="bi bi-cloud-upload me-1"></i> Завантажити файл
                </button>
            </div>
        <?php endif; ?>

        <!-- Статус -->
        <div class="d-flex justify-content-between align-items-center px-4 py-2 border-top bg-light">
            <small class="text-muted">
                <i class="bi bi-files me-1"></i><?php echo $totalItems; ?> файл(ів)
            </small>
            <small class="text-muted">
                <i class="bi bi-layout-text-window me-1"></i>Сторінка <?php echo $page; ?> з <?php echo $totalPages; ?>
            </small>
        </div>
    </div>

    <!-- Модальне вікно завантаження -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cloud-upload me-2 text-primary"></i>
                        Завантаження медіафайлів
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="upload-area" id="dropZone">
                        <i class="bi bi-cloud-arrow-up" style="font-size: 48px; color: var(--primary);"></i>
                        <h4 class="mt-3">Перетягніть файли сюди</h4>
                        <p class="text-muted">або натисніть для вибору</p>
                        <input type="file" name="media_files[]" id="fileInput" style="display: none;" multiple accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar">
                        <button class="btn btn-outline-primary mt-2" onclick="document.getElementById('fileInput').click()">
                            <i class="bi bi-folder-plus me-1"></i> Вибрати файли
                        </button>
                    </div>
                    
                    <div class="upload-progress" id="uploadProgress">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <div class="upload-status" id="uploadStatus"></div>
                    </div>
                    
                    <div class="mt-3 small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Макс. розмір: <?php echo ini_get('upload_max_filesize'); ?> • 
                        Зображення автоматично оптимізуються
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Закрити
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальне вікно перегляду -->
    <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewModalTitle">
                        <i class="bi bi-eye me-2 text-primary"></i>
                        Перегляд файлу
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="row g-0">
                        <!-- Ліва колонка - зображення -->
                        <div class="col-md-8" id="previewImageContainer">
                            <div class="text-center p-4">
                                <img src="" id="previewImage" class="img-fluid rounded shadow" alt="">
                            </div>
                        </div>
                        
                        <!-- Права колонка - детальна інформація -->
                        <div class="col-md-4 border-start" id="previewInfoContainer">
                            <div class="p-4">
                                <h6 class="mb-3">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    Деталі файлу
                                </h6>
                                
                                <div class="file-details-compact" id="fileDetails">
                                    <div class="text-center py-4">
                                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                                            <span class="visually-hidden">Завантаження...</span>
                                        </div>
                                        <span class="text-muted">Завантаження інформації...</span>
                                    </div>
                                </div>
                                
                                <hr class="my-3">
                                
                                <h6 class="mb-3">
                                    <i class="bi bi-link-45deg text-primary me-2"></i>
                                    URL файлу
                                </h6>
                                
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control form-control-sm" id="fileUrl" readonly value="">
                                    <button class="btn btn-outline-primary btn-sm" type="button" onclick="copyPreviewUrl()" title="Копіювати URL">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-secondary btn-sm" onclick="downloadCurrentFile()">
                                        <i class="bi bi-download me-1"></i> Завантажити файл
                                    </button>
                                </div>
                                
                                <!-- Додаткова інформація для зображень -->
                                <div class="mt-3 small text-muted image-only-info" style="display: none;">
                                    <hr>
                                    <p class="mb-1">
                                        <i class="bi bi-lightbulb me-1"></i>
                                        <strong>Порада:</strong> Для вставки в редактор використовуйте:
                                    </p>
                                    <code class="d-block p-2 bg-light rounded" id="htmlCode"></code>
                                    <button class="btn btn-sm btn-outline-primary mt-2 w-100" onclick="copyHtmlCode()">
                                        <i class="bi bi-code me-1"></i> Копіювати HTML код
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Закрити
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальне вікно підтвердження -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Підтвердження
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="confirmMessage">
                    Ви впевнені?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Скасувати
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmAction">
                        <i class="bi bi-check-lg me-1"></i> Підтвердити
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Глобальні змінні
    var selectedFiles = [];
    var uploadModal = null;
    var previewModal = null;
    var confirmModal = null;
    var currentFileUrl = '';
    var currentFileData = null;
    
    // Ініціалізація при завантаженні
    document.addEventListener('DOMContentLoaded', function() {
        uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
        previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
        confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        
        setupFileInput();
        setupDragAndDrop();
        
        // Zoom для зображення
        document.getElementById('previewImage').addEventListener('click', function() {
            this.classList.toggle('zoomed');
        });
    });
    
    // Налаштування вибору файлів
    function setupFileInput() {
        var fileInput = document.getElementById('fileInput');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    uploadFiles(this.files);
                }
            });
        }
    }
    
    // Drag & drop
    function setupDragAndDrop() {
        var dropZone = document.getElementById('dropZone');
        if (!dropZone) return;
        
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            var files = e.dataTransfer.files;
            if (files.length > 0) {
                uploadFiles(files);
            }
        });
    }
    
    // AJAX завантаження файлів
    function uploadFiles(files) {
        var formData = new FormData();
        
        for (var i = 0; i < files.length; i++) {
            formData.append('media_files[]', files[i]);
        }
        
        var progressBar = document.querySelector('#uploadProgress .progress-bar');
        var uploadProgress = document.getElementById('uploadProgress');
        var uploadStatus = document.getElementById('uploadStatus');
        var dropZone = document.getElementById('dropZone');
        
        dropZone.style.display = 'none';
        uploadProgress.style.display = 'block';
        uploadStatus.innerHTML = '';
        
        var xhr = new XMLHttpRequest();
        
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }
        });
        
        xhr.onload = function() {
            var json = null;
            try { json = JSON.parse(xhr.responseText); } catch(e) {}

            if (xhr.status === 200 && json) {
                var results = json.results || [];
                results.forEach(function(result) {
                    if (result.success) {
                        uploadStatus.innerHTML += '<div class="upload-status-item success">' +
                            '<i class="bi bi-check-circle-fill"></i>' +
                            '<span>' + (result.original || 'Файл завантажено') + '</span>' +
                            '</div>';
                    } else {
                        uploadStatus.innerHTML += '<div class="upload-status-item error">' +
                            '<i class="bi bi-exclamation-circle-fill"></i>' +
                            '<span>' + (result.error || 'Помилка') + '</span>' +
                            '</div>';
                    }
                });
                // Перезавантажуємо завжди — файл вже збережено навіть якщо стиснення не вдалось
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                uploadStatus.innerHTML = '<div class="upload-status-item error">' +
                    '<i class="bi bi-exclamation-circle-fill"></i>' +
                    '<span>Помилка сервера (HTTP ' + xhr.status + ')</span>' +
                    '</div>';
                // Все одно перезавантажуємо — файл міг зберегтись
                setTimeout(function() { window.location.reload(); }, 2000);
            }
        };
        
        xhr.open('POST', 'media.php', true);
        xhr.send(formData);
    }
    
    // Показати модальне вікно завантаження
    function showUploadModal() {
        var dropZone = document.getElementById('dropZone');
        var uploadProgress = document.getElementById('uploadProgress');
        var uploadStatus = document.getElementById('uploadStatus');
        
        dropZone.style.display = 'block';
        uploadProgress.style.display = 'none';
        uploadStatus.innerHTML = '';
        
        uploadModal.show();
    }
    
    // Вибір файлів
    function toggleSelect(element, event) {
        event.stopPropagation();
        var item = element.closest('.media-item');
        var url = item.getAttribute('data-url');
        
        if (item.classList.contains('selected')) {
            item.classList.remove('selected');
            var index = selectedFiles.indexOf(url);
            if (index !== -1) selectedFiles.splice(index, 1);
        } else {
            item.classList.add('selected');
            selectedFiles.push(url);
        }
        
        updateBulkActions();
    }
    
    function selectAll() {
        var items = document.querySelectorAll('.media-item');
        selectedFiles = [];
        
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var url = item.getAttribute('data-url');
            item.classList.add('selected');
            selectedFiles.push(url);
        }
        
        updateBulkActions();
    }
    
    function clearSelection() {
        var items = document.querySelectorAll('.media-item');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.remove('selected');
        }
        selectedFiles = [];
        updateBulkActions();
    }
    
    function updateBulkActions() {
        var count = selectedFiles.length;
        var bulkActions = document.getElementById('bulkActions');
        
        if (count > 0) {
            bulkActions.classList.add('active');
            document.getElementById('selectedCount').textContent = count;
        } else {
            bulkActions.classList.remove('active');
        }
    }
    
    // Видалення
    function bulkDelete() {
        if (selectedFiles.length === 0) return;
        
        document.getElementById('confirmMessage').innerHTML = 
            '<i class="bi bi-exclamation-triangle text-warning me-2"></i>' +
            'Видалити ' + selectedFiles.length + ' файл(ів)?<br>' +
            '<small class="text-muted">Цю дію не можна скасувати!</small>';
        
        document.getElementById('confirmAction').onclick = function() {
            confirmModal.hide();
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'media.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            showNotification('success', 'Видалено ' + response.deleted + ' файлів');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        }
                    } catch(e) {}
                }
            };
            xhr.send('action=bulk_delete&files=' + encodeURIComponent(JSON.stringify(selectedFiles)));
        };
        
        confirmModal.show();
    }
    
    function deleteFile(url, element) {
        document.getElementById('confirmMessage').innerHTML = 
            '<i class="bi bi-exclamation-triangle text-warning me-2"></i>' +
            'Видалити цей файл?<br>' +
            '<small class="text-muted">Цю дію не можна скасувати!</small>';
        
        document.getElementById('confirmAction').onclick = function() {
            confirmModal.hide();
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'media.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            var item = element.closest('.media-item');
                            if (item) {
                                item.style.transition = 'all 0.3s';
                                item.style.opacity = '0';
                                item.style.transform = 'scale(0.8)';
                                setTimeout(function() {
                                    item.remove();
                                }, 300);
                            }
                            showNotification('success', 'Файл видалено');
                        }
                    } catch(e) {}
                }
            };
            xhr.send('action=delete&file=' + encodeURIComponent(url));
        };
        
        confirmModal.show();
    }
    
    // Попередній перегляд
    function previewFile(url) {
        currentFileUrl = url;
        var ext = url.split('.').pop().toLowerCase();
        var modalTitle = document.getElementById('previewModalTitle');
        var previewImage = document.getElementById('previewImage');
        var previewImageContainer = document.getElementById('previewImageContainer');
        var fileUrl = document.getElementById('fileUrl');
        var htmlCode = document.getElementById('htmlCode');
        var imageOnlyInfo = document.querySelector('.image-only-info');
        
        modalTitle.innerHTML = '<i class="bi bi-eye me-2 text-primary"></i>' + url.split('/').pop();
        fileUrl.value = window.location.origin + url;
        
        var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        var videoExts = ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv'];
        var audioExts = ['mp3', 'wav', 'ogg', 'm4a'];
        
        if (imageExts.indexOf(ext) !== -1) {
            previewImage.src = url;
            previewImage.style.display = 'block';
            previewImageContainer.innerHTML = '<div class="text-center p-4"><img src="' + url + '" id="previewImage" class="img-fluid rounded shadow" alt=""></div>';
            document.getElementById('previewImage').addEventListener('click', function() {
                this.classList.toggle('zoomed');
            });
            
            var fileName = url.split('/').pop();
            var fileNameWithoutExt = fileName.substring(0, fileName.lastIndexOf('.'));
            htmlCode.textContent = '<img src="' + url + '" alt="' + fileNameWithoutExt + '" class="img-fluid">';
            
            imageOnlyInfo.style.display = 'block';
            loadFileDetails(url);
            
        } else if (videoExts.indexOf(ext) !== -1) {
            previewImageContainer.innerHTML = '<div class="text-center p-4"><video src="' + url + '" controls class="img-fluid rounded shadow" style="max-height:70vh;"></video></div>';
            imageOnlyInfo.style.display = 'none';
            loadFileDetails(url);
            
        } else if (audioExts.indexOf(ext) !== -1) {
            previewImageContainer.innerHTML = '<div class="text-center p-4"><audio src="' + url + '" controls style="width:100%"></audio></div>';
            imageOnlyInfo.style.display = 'none';
            loadFileDetails(url);
            
        } else if (ext === 'pdf') {
            previewImageContainer.innerHTML = '<div class="text-center p-4"><iframe src="' + url + '" style="width:100%; height:70vh;" frameborder="0"></iframe></div>';
            imageOnlyInfo.style.display = 'none';
            loadFileDetails(url);
            
        } else {
            previewImageContainer.innerHTML = '<div class="text-center py-5"><div class="display-1 text-muted mb-3">📄</div><h5>' + url.split('/').pop() + '</h5><p class="text-muted">' + ext.toUpperCase() + ' файл</p></div>';
            imageOnlyInfo.style.display = 'none';
            loadFileDetails(url);
        }
        
        previewModal.show();
    }
    
    // Завантаження деталей файлу
    function loadFileDetails(url) {
        var detailsContainer = document.getElementById('fileDetails');
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'media.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.details) {
                        displayFileDetails(response.details);
                    } else {
                        displayFileDetails(getBasicFileDetails(url));
                    }
                } catch(e) {
                    displayFileDetails(getBasicFileDetails(url));
                }
            } else {
                displayFileDetails(getBasicFileDetails(url));
            }
        };
        xhr.onerror = function() {
            displayFileDetails(getBasicFileDetails(url));
        };
        xhr.send('action=get_details&file=' + encodeURIComponent(url));
    }
    
    // Відображення деталей файлу
    function displayFileDetails(details) {
        var detailsContainer = document.getElementById('fileDetails');
        var html = '';
        
        html += '<div class="detail-row"><span class="detail-label">Назва:</span><span class="detail-value" title="' + details.name + '">' + truncateString(details.name, 30) + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Тип:</span><span class="detail-value"><span class="badge bg-light text-dark">' + details.type.toUpperCase() + '</span></span></div>';
        html += '<div class="detail-row"><span class="detail-label">Розмір:</span><span class="detail-value"><strong>' + details.size + '</strong></span></div>';
        html += '<div class="detail-row"><span class="detail-label">Змінено:</span><span class="detail-value"><i class="bi bi-calendar me-1"></i>' + details.modified + '</span></div>';
        
        if (details.dimensions) {
            html += '<div class="detail-row"><span class="detail-label">Розміри:</span><span class="detail-value"><span class="badge-dimensions">' + details.dimensions + ' px</span></span></div>';
            
            var dims = details.dimensions.split(' x ');
            if (dims.length === 2) {
                var width = parseInt(dims[0]);
                var height = parseInt(dims[1]);
                var ratio = (width / height).toFixed(2);
                var orientation = width > height ? 'ландшафт' : (height > width ? 'портрет' : 'квадрат');
                
                html += '<div class="detail-row"><span class="detail-label">Формат:</span><span class="detail-value"><span class="badge bg-info bg-opacity-10 text-info">' + orientation + ' (' + ratio + ':1)</span></span></div>';
                
                var widthInches = (width / 300).toFixed(1);
                var heightInches = (height / 300).toFixed(1);
                html += '<div class="detail-row"><span class="detail-label">Для друку:</span><span class="detail-value"><small>' + widthInches + '″ × ' + heightInches + '″ (300 dpi)</small></span></div>';
            }
        }
        
        if (details.mime) {
            html += '<div class="detail-row"><span class="detail-label">MIME:</span><span class="detail-value"><small class="text-muted">' + details.mime + '</small></span></div>';
        }
        
        if (details.path) {
            html += '<div class="detail-row"><span class="detail-label">Шлях:</span><span class="detail-value"><small class="text-muted">' + truncateString(details.path, 25) + '</small></span></div>';
        }
        
        if (details.exif) {
            if (details.exif.make || details.exif.model) {
                html += '<div class="detail-row"><span class="detail-label">Камера:</span><span class="detail-value">' + (details.exif.make || '') + ' ' + (details.exif.model || '') + '</span></div>';
            }
            if (details.exif.iso) {
                html += '<div class="detail-row"><span class="detail-label">ISO:</span><span class="detail-value">' + details.exif.iso + '</span></div>';
            }
            if (details.exif.focal) {
                html += '<div class="detail-row"><span class="detail-label">Фокус:</span><span class="detail-value">' + details.exif.focal + '</span></div>';
            }
            if (details.exif.aperture) {
                html += '<div class="detail-row"><span class="detail-label">Діафрагма:</span><span class="detail-value">' + details.exif.aperture + '</span></div>';
            }
        }
        
        detailsContainer.innerHTML = html;
    }
    
    // Отримання базової інформації про файл
    function getBasicFileDetails(url) {
        var name = url.split('/').pop();
        var ext = name.split('.').pop().toLowerCase();
        
        return {
            'name': name,
            'type': ext,
            'size': '?',
            'modified': new Date().toLocaleDateString('uk-UA'),
            'dimensions': null,
            'mime': 'application/octet-stream',
            'path': url
        };
    }
    
    // Обрізання довгого рядка
    function truncateString(str, length) {
        if (str.length <= length) return str;
        return str.substring(0, length) + '...';
    }
    
    // Копіювання URL з модалки
    function copyPreviewUrl() {
        var url = document.getElementById('fileUrl');
        url.select();
        document.execCommand('copy');
        showNotification('success', 'URL скопійовано!');
    }
    
    // Копіювання HTML коду
    function copyHtmlCode() {
        var htmlCode = document.getElementById('htmlCode');
        var temp = document.createElement('textarea');
        temp.value = htmlCode.textContent;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        showNotification('success', 'HTML код скопійовано!');
    }
    
    // Завантаження поточного файлу
    function downloadCurrentFile() {
        if (currentFileUrl) {
            window.open(currentFileUrl, '_blank');
        }
    }
    
    // Копіювання URL
    function copyUrl(url, event) {
        if (event) event.stopPropagation();
        
        var fullUrl = window.location.origin + url;
        var temp = document.createElement('textarea');
        temp.value = fullUrl;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        
        var tooltip = document.createElement('div');
        tooltip.className = 'copy-tooltip';
        tooltip.textContent = 'URL скопійовано!';
        tooltip.style.left = (event ? event.pageX : window.innerWidth / 2) + 'px';
        tooltip.style.top = (event ? event.pageY - 40 : window.innerHeight / 2) + 'px';
        document.body.appendChild(tooltip);
        
        setTimeout(function() { tooltip.remove(); }, 1500);
    }
    
    // Сповіщення
    function showNotification(type, message) {
        var toast = document.createElement('div');
        toast.className = 'toast-notification alert alert-' + type + ' alert-dismissible fade show';
        toast.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + ' me-2"></i>' + message + '<button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>';
        document.body.appendChild(toast);
        setTimeout(function() { if (toast.parentElement) toast.remove(); }, 3000);
    }
    
    // Фільтри та пошук
    function applyFilter(filter) {
        window.location.href = '?filter=' + filter + '&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>';
    }
    
    function applySort(sort) {
        window.location.href = '?filter=<?php echo urlencode($filter); ?>&sort=' + sort + '&search=<?php echo urlencode($search); ?>';
    }
    
    function searchFiles() {
        var search = document.getElementById('searchInput').value;
        window.location.href = '?filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=' + encodeURIComponent(search);
    }
    
    // Гарячі клавіші
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'a') {
            e.preventDefault();
            selectAll();
        }
        if (e.key === 'Escape') {
            clearSelection();
            if (uploadModal) uploadModal.hide();
            if (previewModal) previewModal.hide();
            if (confirmModal) confirmModal.hide();
        }
        if (e.key === 'Delete' && selectedFiles.length > 0) {
            e.preventDefault();
            bulkDelete();
        }
    });
    </script>
</body>
</html>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
ob_end_flush();
?>