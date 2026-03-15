<?php
// admin/media_picker.php
session_start();

// Перевірка авторизації
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'user'])) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Доступ заборонено';
    exit;
}

// Підключення функцій для транслітерації (якщо потрібно)
$translitFile = __DIR__ . '/../plugins/ukr_to_lat.php';
if (file_exists($translitFile)) {
    require_once $translitFile;
}

// === НАЛАШТУВАННЯ ===
$uploadsDir = realpath(__DIR__ . '/../uploads');
$excludeDir = realpath($uploadsDir . '/cms_img');
$images = [];
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$itemsPerPage = 24;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Функція форматування розміру файлу
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

// Рекурсивно шукаємо зображення з детальною інформацією
if (is_dir($uploadsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getRealPath();
        $filename = $fileInfo->getFilename();

        // Пропускаємо мініатюри
        if (strpos($path, '/thumbs/') !== false) {
            continue;
        }

        // Пропускаємо системні теки
        if ($excludeDir && strpos($path, $excludeDir) === 0) {
            continue;
        }

        if ($fileInfo->isFile() && preg_match('/\.(jpe?g|png|gif|webp|svg|bmp)$/i', $filename)) {
            
            // Отримуємо інформацію про зображення
            $imageInfo = @getimagesize($path);
            $dimensions = $imageInfo ? $imageInfo[0] . ' x ' . $imageInfo[1] : 'Невідомо';
            
            $relPath = str_replace(realpath(__DIR__ . '/../'), '', $path);
            $webPath = '/' . ltrim(str_replace('\\', '/', $relPath), '/');
            
            // Шлях до мініатюри
            $thumbPath = dirname($path) . '/thumbs/' . basename($path);
            $thumbWebPath = file_exists($thumbPath) ? 
                str_replace(realpath(__DIR__ . '/../'), '', $thumbPath) : null;
            $thumbWebPath = $thumbWebPath ? '/' . ltrim(str_replace('\\', '/', $thumbWebPath), '/') : $webPath;
            
            $images[] = [
                'url' => $webPath,
                'thumb' => $thumbWebPath,
                'name' => $filename,
                'size' => $fileInfo->getSize(),
                'size_formatted' => formatFileSize($fileInfo->getSize()),
                'modified' => $fileInfo->getMTime(),
                'dimensions' => $dimensions,
                'path' => $path,
                'ext' => strtolower(pathinfo($filename, PATHINFO_EXTENSION))
            ];
        }
    }
}

// Фільтрація
if (!empty($search)) {
    $images = array_filter($images, function($img) use ($search) {
        return stripos($img['name'], $search) !== false;
    });
}

if ($filter === 'large') {
    $images = array_filter($images, function($img) {
        return $img['size'] > 1024 * 1024; // > 1MB
    });
} elseif ($filter === 'medium') {
    $images = array_filter($images, function($img) {
        return $img['size'] > 100 * 1024 && $img['size'] <= 1024 * 1024; // 100KB - 1MB
    });
} elseif ($filter === 'small') {
    $images = array_filter($images, function($img) {
        return $img['size'] <= 100 * 1024; // <= 100KB
    });
}

// Сортування
if ($sort === 'name_asc') {
    usort($images, function($a, $b) { return strcmp($a['name'], $b['name']); });
} elseif ($sort === 'name_desc') {
    usort($images, function($a, $b) { return strcmp($b['name'], $a['name']); });
} elseif ($sort === 'size_asc') {
    usort($images, function($a, $b) { return $a['size'] - $b['size']; });
} elseif ($sort === 'size_desc') {
    usort($images, function($a, $b) { return $b['size'] - $a['size']; });
} elseif ($sort === 'date_asc') {
    usort($images, function($a, $b) { return $a['modified'] - $b['modified']; });
} else {
    usort($images, function($a, $b) { return $b['modified'] - $a['modified']; });
}

// Пагінація
$totalItems = count($images);
$totalPages = ceil($totalItems / $itemsPerPage);
$offset = ($currentPage - 1) * $itemsPerPage;
$pagedImages = array_slice($images, $offset, $itemsPerPage);

// Отримуємо callback функцію з параметрів
$callback = isset($_GET['callback']) ? $_GET['callback'] : 'tinymceImageCallback';
$multiple = isset($_GET['multiple']) && $_GET['multiple'] === 'true';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вибір зображення</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #2271b1;
            --primary-hover: #135e96;
        }
        
        body {
            background: #f0f0f1;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            padding: 0;
            margin: 0;
        }
        
        .picker-header {
            background: #fff;
            border-bottom: 1px solid #c3c4c7;
            padding: 15px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .picker-header h3 {
            font-size: 20px;
            font-weight: 400;
            margin: 0;
            color: #1d2327;
        }
        
        .picker-toolbar {
            background: #fff;
            border-bottom: 1px solid #c3c4c7;
            padding: 12px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        
        .picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            padding: 20px;
        }
        
        .picker-item {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }
        
        .picker-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        
        .picker-item.selected {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(34,113,177,0.25);
        }
        
        .picker-preview {
            height: 150px;
            background: #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            border-bottom: 1px solid #f0f0f1;
        }
        
        .picker-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .picker-info {
            padding: 12px;
            background: #fff;
        }
        
        .picker-name {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #1d2327;
        }
        
        .picker-meta {
            font-size: 11px;
            color: #646970;
            display: flex;
            justify-content: space-between;
        }
        
        .picker-check {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 22px;
            height: 22px;
            background: #fff;
            border: 2px solid #c3c4c7;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 5;
            font-size: 14px;
            color: #fff;
            transition: all 0.2s;
        }
        
        .picker-item.selected .picker-check {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .picker-check::after {
            content: "✓";
            display: block;
            font-weight: bold;
        }
        
        .picker-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            display: none;
            gap: 4px;
            z-index: 5;
        }
        
        .picker-item:hover .picker-actions {
            display: flex;
        }
        
        .picker-action-btn {
            width: 30px;
            height: 30px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #1d2327;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .picker-action-btn:hover {
            background: #f0f0f1;
            border-color: #8c8f94;
            transform: scale(1.1);
        }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-bar .form-select,
        .filter-bar .form-control {
            width: auto;
            min-width: 140px;
            border-radius: 6px;
            font-size: 13px;
        }
        
        .search-box {
            display: flex;
            gap: 5px;
        }
        
        .pagination {
            margin: 20px;
            display: flex;
            justify-content: center;
            gap: 5px;
        }
        
        .page-link {
            padding: 6px 12px;
            background: #f6f7f7;
            border: 1px solid #c3c4c7;
            color: var(--primary);
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
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
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 8px;
            margin: 20px;
            color: #646970;
        }
        
        .empty-state .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .badge-dimensions {
            background: #e7f3ff;
            color: var(--primary);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
        }
        
        .image-dimensions {
            font-size: 10px;
            color: #646970;
            margin-top: 3px;
        }
        
        .stats-bar {
            background: #f8f9fa;
            border-top: 1px solid #dcdcde;
            padding: 10px 20px;
            font-size: 12px;
            color: #50575e;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            bottom: 0;
            z-index: 100;
        }
        
        .btn-insert {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-insert:hover {
            background: var(--primary-hover);
        }
        
        .btn-insert:disabled {
            background: #c3c4c7;
            cursor: not-allowed;
        }
        
        .selected-counter {
            background: var(--primary);
            color: #fff;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 250px;
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
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
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
            .picker-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 10px;
                padding: 10px;
            }
            
            .picker-preview {
                height: 120px;
            }
            
            .picker-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .filter-bar .form-select,
            .filter-bar .form-control {
                width: 100%;
            }
            
            .search-box {
                width: 100%;
            }
            
            .search-box input {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="picker-header d-flex justify-content-between align-items-center">
        <h3><i class="bi bi-images me-2 text-primary"></i>Вибір зображення</h3>
        <div>
            <span class="badge bg-light text-dark me-2">
                <i class="bi bi-files me-1"></i><?php echo $totalItems; ?>
            </span>
            <?php if ($multiple): ?>
                <span class="selected-counter" id="selectedCount">0</span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="picker-toolbar">
        <div class="filter-bar">
            <select class="form-select" id="filterSelect">
                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Усі зображення</option>
                <option value="large" <?php echo $filter === 'large' ? 'selected' : ''; ?>>Великі (>1MB)</option>
                <option value="medium" <?php echo $filter === 'medium' ? 'selected' : ''; ?>>Середні (100KB-1MB)</option>
                <option value="small" <?php echo $filter === 'small' ? 'selected' : ''; ?>>Малі (≤100KB)</option>
            </select>
            
            <select class="form-select" id="sortSelect">
                <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Найновіші</option>
                <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Найстаріші</option>
                <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>За назвою (А-Я)</option>
                <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>За назвою (Я-А)</option>
                <option value="size_asc" <?php echo $sort === 'size_asc' ? 'selected' : ''; ?>>Найменші</option>
                <option value="size_desc" <?php echo $sort === 'size_desc' ? 'selected' : ''; ?>>Найбільші</option>
            </select>
            
            <div class="search-box">
                <input type="text" class="form-control" placeholder="Пошук..." id="searchInput" value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-primary" onclick="applySearch()"><i class="bi bi-search"></i></button>
                <?php if (!empty($search)): ?>
                    <button class="btn btn-outline-secondary" onclick="clearSearch()"><i class="bi bi-x-lg"></i></button>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="ms-auto">
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleViewMode()">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </button>
        </div>
    </div>
    
    <!-- Сітка зображень -->
    <?php if (!empty($pagedImages)): ?>
        <div class="picker-grid" id="pickerGrid">
            <?php foreach ($pagedImages as $img): 
                $isSelected = false; // Це буде встановлено JavaScript
            ?>
                <div class="picker-item" 
                     data-url="<?php echo htmlspecialchars($img['url']); ?>" 
                     data-name="<?php echo htmlspecialchars($img['name']); ?>"
                     data-size="<?php echo $img['size']; ?>"
                     data-dimensions="<?php echo htmlspecialchars($img['dimensions']); ?>">
                    
                    <?php if ($multiple): ?>
                        <div class="picker-check" onclick="toggleSelect(this, event)"></div>
                    <?php endif; ?>
                    
                    <div class="picker-actions">
                        <a href="#" class="picker-action-btn" onclick="copyUrl('<?php echo htmlspecialchars($img['url']); ?>', event); return false;" title="Копіювати URL">
                            <i class="bi bi-link"></i>
                        </a>
                        <a href="#" class="picker-action-btn" onclick="previewImage('<?php echo htmlspecialchars($img['url']); ?>'); return false;" title="Попередній перегляд">
                            <i class="bi bi-eye"></i>
                        </a>
                    </div>
                    
                    <div class="picker-preview" onclick="<?php echo $multiple ? 'selectItem(this)' : 'selectImage(\'' . htmlspecialchars($img['url']) . '\', \'' . htmlspecialchars($img['name']) . '\')'; ?>">
                        <img src="<?php echo htmlspecialchars($img['thumb']); ?>" alt="<?php echo htmlspecialchars($img['name']); ?>" loading="lazy">
                    </div>
                    
                    <div class="picker-info">
                        <div class="picker-name" title="<?php echo htmlspecialchars($img['name']); ?>">
                            <?php echo htmlspecialchars($img['name']); ?>
                        </div>
                        <div class="picker-meta">
                            <span><i class="bi bi-file-earmark me-1"></i><?php echo $img['size_formatted']; ?></span>
                            <span><i class="bi bi-calendar me-1"></i><?php echo date('d.m.Y', $img['modified']); ?></span>
                        </div>
                        <div class="image-dimensions">
                            <span class="badge-dimensions"><i class="bi bi-arrows-angle-expand me-1"></i><?php echo $img['dimensions']; ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Пагінація -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?page=1&filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>&callback=<?php echo urlencode($callback); ?>&multiple=<?php echo $multiple ? 'true' : 'false'; ?>" 
                   class="page-link <?php echo $currentPage == 1 ? 'disabled' : ''; ?>">
                    <i class="bi bi-chevron-double-left"></i>
                </a>
                
                <?php 
                $start = max(1, $currentPage - 3);
                $end = min($totalPages, $currentPage + 3);
                for ($i = $start; $i <= $end; $i++): 
                ?>
                    <a href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>&callback=<?php echo urlencode($callback); ?>&multiple=<?php echo $multiple ? 'true' : 'false'; ?>" 
                       class="page-link <?php echo $i == $currentPage ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <a href="?page=<?php echo $totalPages; ?>&filter=<?php echo urlencode($filter); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>&callback=<?php echo urlencode($callback); ?>&multiple=<?php echo $multiple ? 'true' : 'false'; ?>" 
                   class="page-link <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>">
                    <i class="bi bi-chevron-double-right"></i>
                </a>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-state">
            <div class="icon"><i class="bi bi-image"></i></div>
            <h4>Зображень не знайдено</h4>
            <p class="text-muted">Спробуйте змінити параметри пошуку або завантажте нові зображення</p>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="?callback=<?php echo urlencode($callback); ?>&multiple=<?php echo $multiple ? 'true' : 'false'; ?>" class="btn btn-outline-primary mt-3">
                    <i class="bi bi-x-circle me-1"></i> Скинути фільтри
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Нижня панель -->
    <div class="stats-bar">
        <div>
            <?php if ($multiple): ?>
                <span id="selectionInfo">Вибрано <strong>0</strong> зображень</span>
            <?php else: ?>
                <span>Клікніть на зображення для вибору</span>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($multiple): ?>
                <button class="btn-insert" id="insertSelectedBtn" onclick="insertSelected()" disabled>
                    <i class="bi bi-check-lg me-1"></i> Вставити вибрані
                </button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Модальне вікно попереднього перегляду -->
    <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye me-2 text-primary"></i>
                        Попередній перегляд
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center" id="previewModalBody">
                    <img src="" id="previewImage" class="img-fluid rounded" style="max-height: 60vh;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Закрити
                    </button>
                    <button type="button" class="btn btn-primary" id="previewSelectBtn">
                        <i class="bi bi-check-lg me-1"></i> Вибрати
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Глобальні змінні
        var selectedImages = [];
        var multipleMode = <?php echo $multiple ? 'true' : 'false'; ?>;
        var callbackName = '<?php echo $callback; ?>';
        var previewModal = null;
        
        // Ініціалізація
        document.addEventListener('DOMContentLoaded', function() {
            previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
            
            // Обробка клавіш
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (previewModal) previewModal.hide();
                }
            });
        });
        
        // Вибір зображення (одиночний режим)
        function selectImage(url, name) {
            if (window.opener && window.opener[callbackName]) {
                window.opener[callbackName](url, { 
                    alt: name || '',
                    title: name || '',
                    width: '',
                    height: ''
                });
                window.close();
            } else {
                showNotification('warning', 'Функція зворотного виклику не знайдена');
            }
        }
        
        // Перемикання вибору (множинний режим)
        function toggleSelect(element, event) {
            event.stopPropagation();
            var item = element.closest('.picker-item');
            var url = item.getAttribute('data-url');
            
            if (item.classList.contains('selected')) {
                item.classList.remove('selected');
                var index = selectedImages.indexOf(url);
                if (index !== -1) selectedImages.splice(index, 1);
            } else {
                item.classList.add('selected');
                selectedImages.push(url);
            }
            
            updateSelection();
        }
        
        // Вибір елемента
        function selectItem(element) {
            var item = element.closest('.picker-item');
            var url = item.getAttribute('data-url');
            var name = item.getAttribute('data-name');
            
            if (multipleMode) {
                var check = item.querySelector('.picker-check');
                if (check) {
                    toggleSelect(check, { stopPropagation: function() {} });
                }
            } else {
                selectImage(url, name);
            }
        }
        
        // Оновлення інформації про вибір
        function updateSelection() {
            var count = selectedImages.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('selectionInfo').innerHTML = 'Вибрано <strong>' + count + '</strong> зображень';
            
            var insertBtn = document.getElementById('insertSelectedBtn');
            if (insertBtn) {
                insertBtn.disabled = count === 0;
            }
        }
        
        // Вставка вибраних зображень
        function insertSelected() {
            if (selectedImages.length === 0) return;
            
            if (window.opener && window.opener[callbackName]) {
                if (selectedImages.length === 1) {
                    // Для одного зображення викликаємо як звичайно
                    window.opener[callbackName](selectedImages[0], { alt: '' });
                } else {
                    // Для декількох передаємо масив
                    window.opener[callbackName](selectedImages, { multiple: true });
                }
                window.close();
            } else {
                showNotification('warning', 'Функція зворотного виклику не знайдена');
            }
        }
        
        // Попередній перегляд
        function previewImage(url) {
            document.getElementById('previewImage').src = url;
            document.getElementById('previewSelectBtn').onclick = function() {
                if (multipleMode) {
                    selectItem(document.querySelector('[data-url="' + url + '"]'));
                } else {
                    selectImage(url, '');
                }
                previewModal.hide();
            };
            previewModal.show();
        }
        
        // Копіювання URL
        function copyUrl(url, event) {
            event.stopPropagation();
            
            var fullUrl = window.location.origin + url;
            var temp = document.createElement('textarea');
            temp.value = fullUrl;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            
            showTooltip(event.pageX, event.pageY, 'URL скопійовано!');
        }
        
        // Показати підказку
        function showTooltip(x, y, message) {
            var tooltip = document.createElement('div');
            tooltip.className = 'copy-tooltip';
            tooltip.textContent = message;
            tooltip.style.left = (x - 50) + 'px';
            tooltip.style.top = (y - 40) + 'px';
            document.body.appendChild(tooltip);
            
            setTimeout(function() { tooltip.remove(); }, 1500);
        }
        
        // Показати сповіщення
        function showNotification(type, message) {
            var toast = document.createElement('div');
            toast.className = 'toast-notification alert alert-' + type + ' alert-dismissible fade show';
            toast.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + ' me-2"></i>' + message + '<button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>';
            document.body.appendChild(toast);
            
            setTimeout(function() {
                if (toast.parentElement) toast.remove();
            }, 3000);
        }
        
        // Фільтри та сортування
        document.getElementById('filterSelect').addEventListener('change', function() {
            applyFilters();
        });
        
        document.getElementById('sortSelect').addEventListener('change', function() {
            applyFilters();
        });
        
        function applyFilters() {
            var filter = document.getElementById('filterSelect').value;
            var sort = document.getElementById('sortSelect').value;
            var search = document.getElementById('searchInput').value;
            
            window.location.href = '?page=1&filter=' + filter + '&sort=' + sort + '&search=' + encodeURIComponent(search) + '&callback=' + callbackName + '&multiple=' + multipleMode;
        }
        
        function applySearch() {
            applyFilters();
        }
        
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            applyFilters();
        }
        
        // Перемикання режиму відображення
        function toggleViewMode() {
            var grid = document.getElementById('pickerGrid');
            if (grid.style.gridTemplateColumns === 'repeat(auto-fill, minmax(250px, 1fr))') {
                grid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(180px, 1fr))';
            } else {
                grid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(250px, 1fr))';
            }
        }
        
        // Гарячі клавіші
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'a' && multipleMode) {
                e.preventDefault();
                var items = document.querySelectorAll('.picker-item');
                items.forEach(function(item) {
                    if (!item.classList.contains('selected')) {
                        item.classList.add('selected');
                        var url = item.getAttribute('data-url');
                        selectedImages.push(url);
                    }
                });
                updateSelection();
            }
            
            if (e.key === 'Escape') {
                if (multipleMode) {
                    selectedImages = [];
                    document.querySelectorAll('.picker-item.selected').forEach(function(item) {
                        item.classList.remove('selected');
                    });
                    updateSelection();
                }
            }
            
            if (e.ctrlKey && e.key === 'Enter' && multipleMode && selectedImages.length > 0) {
                insertSelected();
            }
        });
    </script>
</body>
</html>