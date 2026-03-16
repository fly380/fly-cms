<?php
// admin/menu_editor.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

// Перевірка прав доступу
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

$username = $_SESSION['username'] ?? 'невідомо';
$message = '';
$messageType = '';

$db = fly_db();
if (!function_exists('run_migrations')) {
    require_once __DIR__ . '/../data/migrations.php';
}
run_migrations($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['menu']) && is_array($_POST['menu'])) {
        $position = 1;
        
        // Видалення пунктів меню
        if (!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
            $placeholders = implode(',', array_fill(0, count($_POST['delete_ids']), '?'));
            $stmt = $db->prepare("DELETE FROM menu_items WHERE id IN ($placeholders)");
            $stmt->execute($_POST['delete_ids']);
            log_action("🗑️ Видалив пункти меню: " . implode(', ', $_POST['delete_ids']), $username);
        }

        foreach ($_POST['menu'] as $item) {
            if (!isset($item['type'])) {
                continue;
            }
            $title = trim($item['title'] ?? '');
            $url = $item['url'] ?? '';
            $type = $item['type'];
            $icon = $item['icon'] ?? '';
            $lang_settings = $item['lang_settings'] ?? '';
            
            if ($type === 'login_logout' && $title === '') {
                $title = 'Вхід/Вихід';
            }
            if ($type === 'language_switcher' && $title === '') {
                $title = 'Мова';
            }
            if ($title === '') {
                continue;
            }
            $visible = !empty($item['visible']) ? 1 : 0;
            $auth_only = !empty($item['auth_only']) ? 1 : 0;
            $visibility_role = $item['visibility_role'] ?? 'all';
            $parent_id = is_numeric($item['parent_id'] ?? null) ? (int)$item['parent_id'] : null;
            $target = $item['target'] ?? '_self';

            if (!empty($item['id'])) {
                // Оновлення існуючого пункту меню
                $stmt = $db->prepare("UPDATE menu_items SET title = ?, url = ?, position = ?, visible = ?, auth_only = ?, type = ?, visibility_role = ?, parent_id = ?, icon = ?, target = ?, lang_settings = ? WHERE id = ?");
                $stmt->execute([
                    $title, $url, $position,
                    $visible, $auth_only, $type, $visibility_role, $parent_id, $icon, $target, $lang_settings, $item['id']
                ]);
            } else {
                // Додавання нового пункту меню
                $stmt = $db->prepare("INSERT INTO menu_items (title, url, position, visible, auth_only, type, visibility_role, parent_id, icon, target, lang_settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $title, $url, $position,
                    $visible, $auth_only, $type, $visibility_role, $parent_id, $icon, $target, $lang_settings
                ]);
            }
            $position++;
        }
        
        log_action("🔧 Оновив меню", $username);
        $message = "✅ Меню успішно збережено!";
        $messageType = 'success';
    }
}

// Завантаження меню
$menu = $db->query("SELECT * FROM menu_items ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);

// Отримання списку сторінок та записів
$dbPages = $db->query("SELECT slug, title FROM pages WHERE draft = 0 ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
$dbPosts = $db->query("SELECT slug, COALESCE(meta_title, title) as title FROM posts WHERE draft = 0 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$filePages = [];
$directory = __DIR__ . '/../';
$files = glob($directory . '*.php');
foreach ($files as $filePath) {
    $filename = basename($filePath);
    if (strpos($filename, 'admin') === 0) continue; // пропускаємо адмінські файли
    $filePages[] = [
        'slug' => $filename,
        'title' => ucfirst(str_replace(['.php', '_'], ['', ' '], $filename)) . ' (файл)'
    ];
}

$pages = array_merge($dbPages, $dbPosts, $filePages);

// Заголовок сторінки
$page_title = 'Редактор меню';

// Буферизація контенту
ob_start();
?>
<style>
:root {
    --primary: #2271b1;
    --primary-hover: #135e96;
    --success: #00a32a;
    --warning: #dba617;
    --danger: #d63638;
    --info: #72aee6;
}

/* Основний стиль */
body {
    background-color: #f0f0f1;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

/* Стилі для редактора меню */
.menu-editor {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.menu-header {
    background: #f8f9fa;
    border-bottom: 1px solid #dcdcde;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.menu-header h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #1d2327;
    display: flex;
    align-items: center;
    gap: 8px;
}

.menu-header h2 i {
    color: var(--primary);
}

.menu-items-container {
    padding: 20px;
}

/* Стилі для пункту меню */
.menu-row {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
    cursor: move;
    position: relative;
}

.menu-row:hover {
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.menu-row.dragging {
    opacity: 0.5;
    transform: scale(0.98);
}

.menu-row.drag-over {
    border: 2px dashed var(--primary);
    background-color: #f0f6fc;
    transform: translateY(2px);
}

.menu-row .drag-handle {
    position: absolute;
    left: -6px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 30px;
    background: #f0f0f1;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #646970;
    cursor: move;
    opacity: 0;
    transition: opacity 0.2s;
}

.menu-row:hover .drag-handle {
    opacity: 1;
}

.menu-row .drag-handle i {
    font-size: 16px;
}

.menu-row .menu-item-content {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.menu-row .form-control,
.menu-row .form-select {
    border: 1px solid #dcdcde;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 13px;
    transition: all 0.2s;
    background: #fff;
}

.menu-row .form-control:focus,
.menu-row .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
    outline: none;
}

.menu-row .form-control-sm,
.menu-row .form-select-sm {
    padding: 4px 8px;
    font-size: 12px;
}

.menu-row .form-check {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 0;
    min-width: 70px;
}

.menu-row .form-check-input {
    margin: 0;
}

.menu-row .form-check-label {
    font-size: 12px;
    color: #50575e;
}

.menu-row .btn-remove {
    background: #fcf0f1;
    color: var(--danger);
    border: 1px solid #f2b6bb;
    border-radius: 4px;
    padding: 6px 12px;
    font-size: 13px;
    transition: all 0.2s;
    cursor: pointer;
}

.menu-row .btn-remove:hover {
    background: var(--danger);
    color: white;
    border-color: var(--danger);
}

/* Стилі для спеціальних полів перемикача мов */
.lang-switcher-preview {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f8f9fa;
    border: 1px solid #dcdcde;
    border-radius: 20px;
    padding: 4px 10px;
    font-size: 12px;
}

.lang-switcher-preview span {
    opacity: 0.7;
}

.lang-switcher-preview .flags {
    display: flex;
    gap: 2px;
}

.lang-flag-sample {
    font-size: 14px;
    cursor: default;
}

/* Стилі для кнопок дій */
.btn {
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 4px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid transparent;
    cursor: pointer;
}

.btn i {
    font-size: 16px;
}

.btn-primary {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-primary:hover {
    background: var(--primary-hover);
    border-color: var(--primary-hover);
}

.btn-secondary {
    background: #f0f0f1;
    color: #50575e;
    border-color: #dcdcde;
}

.btn-secondary:hover {
    background: #e5e5e5;
    border-color: #8c8f94;
}

.btn-outline-primary {
    background: transparent;
    color: var(--primary);
    border-color: var(--primary);
}

.btn-outline-primary:hover {
    background: var(--primary);
    color: white;
}

.btn-sm {
    padding: 4px 12px;
    font-size: 12px;
}

.btn-lg {
    padding: 12px 24px;
    font-size: 14px;
}

/* Стилі для datalist */
datalist {
    display: none;
}

/* Стилі для інформаційної панелі */
.info-bar {
    background: #f8f9fa;
    border-top: 1px solid #dcdcde;
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #646970;
}

/* Анімації */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.menu-row.new {
    animation: slideIn 0.3s ease;
}

/* Стилі для статистики */
.stats-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 16px;
    transition: all 0.2s ease;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-color: var(--primary);
}

.stats-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 12px;
}

.stats-number {
    font-size: 28px;
    font-weight: 600;
    color: #1d2327;
    line-height: 1.2;
}

.stats-label {
    font-size: 13px;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Адаптивність */
@media (max-width: 768px) {
    .menu-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .menu-row .menu-item-content {
        flex-direction: column;
        align-items: stretch;
    }
    
    .menu-row .form-control,
    .menu-row .form-select {
        width: 100% !important;
    }
    
    .menu-row .form-check {
        justify-content: flex-start;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<datalist id="pagesList">
    <?php foreach ($pages as $page): ?>
        <option value="/<?php echo htmlspecialchars($page['slug']); ?>">
            <?php echo htmlspecialchars($page['title']); ?>
        </option>
    <?php endforeach; ?>
</datalist>

<div class="container-fluid px-4">
    <!-- Заголовок -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <i class="bi bi-list me-2 text-primary"></i>
                Редактор меню
            </h1>
            <p class="text-muted small mt-1">
                <i class="bi bi-info-circle me-1"></i>
                Керування навігаційним меню сайту
            </p>
        </div>
        <div>
            <a href="/" target="_blank" class="btn btn-outline-primary">
                <i class="bi bi-eye me-1"></i> Переглянути сайт
            </a>
        </div>
    </div>

    <!-- Повідомлення -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>
            ✅ Меню успішно збережено!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Статистика -->
    <?php
    $visibleCount = 0;
    $authCount = 0;
    $childCount = 0;
    $langSwitcherCount = 0;
    
    foreach ($menu as $item) {
        if ($item['visible']) $visibleCount++;
        if ($item['auth_only']) $authCount++;
        if (!is_null($item['parent_id'])) $childCount++;
        if ($item['type'] === 'language_switcher') $langSwitcherCount++;
    }
    ?>

    <!-- Основний редактор -->
    <div class="menu-editor">
        <div class="menu-header">
            <h2>
                <i class="bi bi-list"></i>
                Редагування меню
            </h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="collapseAll()">
                    <i class="bi bi-arrows-collapse"></i> Згорнути всі
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="expandAll()">
                    <i class="bi bi-arrows-expand"></i> Розгорнути всі
                </button>
            </div>
        </div>

        <form method="POST" id="menuForm">
            <div class="menu-items-container" id="menuItems">
                <?php if (empty($menu)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-list" style="font-size: 48px;"></i>
                        <p class="mt-2">Меню порожнє. Додайте перший пункт меню.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($menu as $index => $item): ?>
                        <div class="menu-row" draggable="true" data-id="<?php echo $item['id']; ?>">
                            <div class="drag-handle">
                                <i class="bi bi-grip-vertical"></i>
                            </div>
                            
                            <input type="hidden" name="menu[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                            
                            <div class="menu-item-content">
                                <input type="text" 
                                       name="menu[<?php echo $index; ?>][title]" 
                                       class="form-control form-control-sm" 
                                       style="width: 150px;" 
                                       placeholder="Назва" 
                                       value="<?php echo htmlspecialchars($item['title']); ?>"
                                       required>
                                
                                <div class="d-flex gap-1" style="width: 200px;">
                                    <select name="menu[<?php echo $index; ?>][icon]" class="form-select form-select-sm" style="width: 70px;">
                                        <option value="">—</option>
                                        <option value="bi-house" <?php echo $item['icon'] === 'bi-house' ? 'selected' : ''; ?>>🏠</option>
                                        <option value="bi-info-circle" <?php echo $item['icon'] === 'bi-info-circle' ? 'selected' : ''; ?>>ℹ️</option>
                                        <option value="bi-envelope" <?php echo $item['icon'] === 'bi-envelope' ? 'selected' : ''; ?>>📧</option>
                                        <option value="bi-telephone" <?php echo $item['icon'] === 'bi-telephone' ? 'selected' : ''; ?>>📞</option>
                                        <option value="bi-newspaper" <?php echo $item['icon'] === 'bi-newspaper' ? 'selected' : ''; ?>>📰</option>
                                        <option value="bi-person" <?php echo $item['icon'] === 'bi-person' ? 'selected' : ''; ?>>👤</option>
                                        <option value="bi-translate" <?php echo $item['icon'] === 'bi-translate' ? 'selected' : ''; ?>>🌐</option>
                                    </select>
                                    
                                    <input type="text" 
                                           name="menu[<?php echo $index; ?>][url]" 
                                           class="form-control form-control-sm" 
                                           style="width: 130px;" 
                                           placeholder="URL" 
                                           list="pagesList" 
                                           value="<?php echo htmlspecialchars($item['url']); ?>"
                                           <?php echo $item['type'] === 'language_switcher' ? 'disabled' : ''; ?>>
                                </div>
                                
                                <select name="menu[<?php echo $index; ?>][type]" class="form-select form-select-sm" style="width: 130px;" onchange="toggleUrlField(this)">
                                    <option value="link" <?php echo $item['type'] === 'link' ? 'selected' : ''; ?>>🔗 Посилання</option>
                                    <option value="login_logout" <?php echo $item['type'] === 'login_logout' ? 'selected' : ''; ?>>🔑 Вхід/Вихід</option>
                                    <option value="language_switcher" <?php echo $item['type'] === 'language_switcher' ? 'selected' : ''; ?>>🌐 Перемикач мов</option>
                                </select>
                                
                                <select name="menu[<?php echo $index; ?>][parent_id]" class="form-select form-select-sm" style="width: 150px;">
                                    <option value="">— Головне меню —</option>
                                    <?php foreach ($menu as $parent): ?>
                                        <?php if ($parent['id'] != $item['id']): ?>
                                            <option value="<?php echo $parent['id']; ?>" <?php echo $item['parent_id'] == $parent['id'] ? 'selected' : ''; ?>>
                                                <?php echo str_repeat('—', $parent['parent_id'] ? 1 : 0); ?> <?php echo htmlspecialchars($parent['title']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                
                                <select name="menu[<?php echo $index; ?>][visibility_role]" class="form-select form-select-sm" style="width: 140px;">
                                    <option value="all" <?php echo $item['visibility_role'] === 'all' ? 'selected' : ''; ?>>🌍 Усі</option>
                                    <option value="editor_admin" <?php echo $item['visibility_role'] === 'editor_admin' ? 'selected' : ''; ?>>👥 Редактор+Адмін</option>
                                    <option value="admin" <?php echo $item['visibility_role'] === 'admin' ? 'selected' : ''; ?>>👑 Адмін + SuperAdmin</option>
                                </select>
                                
                                <select name="menu[<?php echo $index; ?>][target]" class="form-select form-select-sm" style="width: 90px;" <?php echo $item['type'] === 'language_switcher' ? 'disabled' : ''; ?>>
                                    <option value="_self" <?php echo ($item['target'] ?? '_self') === '_self' ? 'selected' : ''; ?>>Поточне</option>
                                    <option value="_blank" <?php echo ($item['target'] ?? '_self') === '_blank' ? 'selected' : ''; ?>>Нове вікно</option>
                                </select>
                                
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="menu[<?php echo $index; ?>][visible]" 
                                           value="1" 
                                           id="visible_<?php echo $index; ?>" 
                                           <?php echo $item['visible'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="visible_<?php echo $index; ?>">👁️</label>
                                </div>
                                
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="menu[<?php echo $index; ?>][auth_only]" 
                                           value="1" 
                                           id="auth_<?php echo $index; ?>" 
                                           <?php echo $item['auth_only'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="auth_<?php echo $index; ?>">🔒</label>
                                </div>
                                
                                <?php if ($item['type'] === 'language_switcher'): ?>
                                    <div class="lang-switcher-preview">
                                        <span class="flags">
                                            <span class="lang-flag-sample">🇺🇦</span>
                                            <span class="lang-flag-sample">🇬🇧</span>
                                            <span class="lang-flag-sample">🇵🇱</span>
                                            <span class="lang-flag-sample">🇩🇪</span>
                                        </span>
                                        <span>авто</span>
                                    </div>
                                <?php endif; ?>
                                
                                <button type="button" class="btn-remove" onclick="confirmRemove(this)" title="Видалити пункт меню">
                                    <i class="bi bi-trash me-1"></i>Видалити
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="p-3 border-top d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-secondary" onclick="addMenuItem()">
                    <i class="bi bi-plus-lg"></i> Додати пункт меню
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="addDivider()">
                    <i class="bi bi-dash-lg"></i> Додати роздільник
                </button>
                <button type="submit" class="btn btn-primary ms-auto">
                    <i class="bi bi-check-lg"></i> Зберегти меню
                </button>
            </div>
        </form>

        <div class="info-bar">
            <span>
                <i class="bi bi-info-circle me-1"></i>
                Перетягуйте пункти мишею для зміни порядку
            </span>
            <span>
                <i class="bi bi-diagram-3 me-1"></i>
                Для створення підменю виберіть батьківський пункт
            </span>
        </div>
    </div>

    <!-- Модалка підтвердження видалення -->
    <div class="modal fade" id="deleteMenuModal" tabindex="-1" aria-labelledby="deleteMenuModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="deleteMenuModalLabel">
                        <i class="bi bi-trash text-danger me-2"></i>Видалити пункт меню
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-2">
                    <p class="mb-1">Ви впевнені, що хочете видалити пункт меню
                        <strong id="deleteMenuItemName" class="text-danger">«...»</strong>?
                    </p>
                    <p class="small text-muted mb-0">Цю дію буде застосовано після натискання «Зберегти меню».</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Скасувати
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" id="confirmDeleteBtn">
                        <i class="bi bi-trash me-1"></i>Так, видалити
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Довідка -->
    <div class="card mt-4">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-question-circle me-2 text-primary"></i>
                Довідка по типах меню
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <h6><span class="badge bg-primary me-2">🔗</span> Посилання</h6>
                    <p class="small text-muted">Звичайне посилання на сторінку, файл або зовнішній ресурс.</p>
                </div>
                <div class="col-md-3">
                    <h6><span class="badge bg-success me-2">🔑</span> Вхід/Вихід</h6>
                    <p class="small text-muted">Динамічний пункт: показує "Вхід" для неавторизованих та "Вихід" для авторизованих.</p>
                </div>
                <div class="col-md-3">
                    <h6><span class="badge bg-info me-2">🌐</span> Перемикач мов</h6>
                    <p class="small text-muted">Додає перемикач мов (українська, англійська, польська, німецька).</p>
                </div>
                <div class="col-md-3">
                    <h6><span class="badge bg-secondary me-2">👁️</span> Видимість</h6>
                    <p class="small text-muted">Керування видимістю пункту для різних ролей користувачів.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Генерація унікального ключа
function generateUniqueKey() {
    return 'k' + Date.now() + Math.floor(Math.random() * 1000);
}

// Функція для ввімкнення/вимкнення поля URL
function toggleUrlField(select) {
    const row = select.closest('.menu-row');
    const urlInput = row.querySelector('input[name$="[url]"]');
    const targetSelect = row.querySelector('select[name$="[target]"]');
    
    if (select.value === 'language_switcher') {
        if (urlInput) {
            urlInput.disabled = true;
            urlInput.value = '#';
        }
        if (targetSelect) {
            targetSelect.disabled = true;
        }
    } else {
        if (urlInput) {
            urlInput.disabled = false;
            if (urlInput.value === '#') urlInput.value = '';
        }
        if (targetSelect) {
            targetSelect.disabled = false;
        }
    }
}

// Додавання нового пункту меню
function addMenuItem() {
    const key = generateUniqueKey();
    const container = document.getElementById('menuItems');
    
    // Якщо контейнер порожній і показує повідомлення
    if (container.children.length === 1 && container.querySelector('.text-center')) {
        container.innerHTML = '';
    }
    
    const div = document.createElement('div');
    div.className = 'menu-row new';
    div.setAttribute('draggable', true);
    div.innerHTML = `
        <div class="drag-handle">
            <i class="bi bi-grip-vertical"></i>
        </div>
        
        <div class="menu-item-content">
            <input type="text" name="menu[${key}][title]" class="form-control form-control-sm" style="width: 150px;" placeholder="Назва" required>
            
            <div class="d-flex gap-1" style="width: 200px;">
                <select name="menu[${key}][icon]" class="form-select form-select-sm" style="width: 70px;">
                    <option value="">—</option>
                    <option value="bi-house">🏠</option>
                    <option value="bi-info-circle">ℹ️</option>
                    <option value="bi-envelope">📧</option>
                    <option value="bi-telephone">📞</option>
                    <option value="bi-newspaper">📰</option>
                    <option value="bi-person">👤</option>
                    <option value="bi-translate">🌐</option>
                </select>
                
                <input type="text" name="menu[${key}][url]" class="form-control form-control-sm" style="width: 130px;" placeholder="URL" list="pagesList">
            </div>
            
            <select name="menu[${key}][type]" class="form-select form-select-sm" style="width: 130px;" onchange="toggleUrlField(this)">
                <option value="link">🔗 Посилання</option>
                <option value="login_logout">🔑 Вхід/Вихід</option>
                <option value="language_switcher">🌐 Перемикач мов</option>
            </select>
            
            <select name="menu[${key}][parent_id]" class="form-select form-select-sm" style="width: 150px;">
                <option value="">— Головне меню —</option>
                <?php foreach ($menu as $parent): ?>
                    <option value="<?php echo $parent['id']; ?>">
                        <?php echo htmlspecialchars($parent['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="menu[${key}][visibility_role]" class="form-select form-select-sm" style="width: 140px;">
                <option value="all">🌍 Усі</option>
                <option value="editor_admin">👥 Редактор+Адмін</option>
                <option value="admin">👑 Тільки адмін</option>
            </select>
            
            <select name="menu[${key}][target]" class="form-select form-select-sm" style="width: 90px;">
                <option value="_self">Поточне</option>
                <option value="_blank">Нове вікно</option>
            </select>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="menu[${key}][visible]" value="1" id="visible_${key}" checked>
                <label class="form-check-label" for="visible_${key}">👁️</label>
            </div>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="menu[${key}][auth_only]" value="1" id="auth_${key}">
                <label class="form-check-label" for="auth_${key}">🔒</label>
            </div>
            
            <button type="button" class="btn-remove" onclick="confirmRemove(this)" title="Видалити пункт меню">
                <i class="bi bi-trash me-1"></i>Видалити
            </button>
        </div>
    `;
    container.appendChild(div);
    makeDraggable(div);
}

// Додавання роздільника
function addDivider() {
    const key = generateUniqueKey();
    const container = document.getElementById('menuItems');
    
    const div = document.createElement('div');
    div.className = 'menu-row new';
    div.setAttribute('draggable', true);
    div.innerHTML = `
        <div class="drag-handle">
            <i class="bi bi-grip-vertical"></i>
        </div>
        
        <div class="menu-item-content">
            <input type="hidden" name="menu[${key}][title]" value="---">
            <input type="hidden" name="menu[${key}][type]" value="divider">
            
            <div class="d-flex align-items-center" style="width: 100%;">
                <span class="text-muted me-2">━━━━  Роздільник  ━━━━</span>
                <select name="menu[${key}][visibility_role]" class="form-select form-select-sm" style="width: 140px;">
                    <option value="all">🌍 Усі</option>
                    <option value="editor_admin">👥 Редактор+Адмін</option>
                    <option value="admin">👑 Тільки адмін</option>
                </select>
                <div class="form-check ms-2">
                    <input class="form-check-input" type="checkbox" name="menu[${key}][visible]" value="1" id="visible_${key}" checked>
                    <label class="form-check-label" for="visible_${key}">👁️</label>
                </div>
                <button type="button" class="btn-remove ms-2" onclick="confirmRemove(this)" title="Видалити роздільник">
                    <i class="bi bi-trash me-1"></i>Видалити
                </button>
            </div>
        </div>
    `;
    container.appendChild(div);
    makeDraggable(div);
}

// Видалення пункту меню через модалку
let _pendingRemoveBtn = null;

function confirmRemove(btn) {
    const row = btn.closest('.menu-row');
    const titleInput = row.querySelector('input[name$="[title]"]');
    const name = titleInput ? (titleInput.value.trim() || 'без назви') : 'цей пункт';
    document.getElementById('deleteMenuItemName').textContent = '«' + name + '»';
    _pendingRemoveBtn = btn;
    const modal = new bootstrap.Modal(document.getElementById('deleteMenuModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('deleteMenuModal')).hide();
        if (!_pendingRemoveBtn) return;
        removeMenuItem(_pendingRemoveBtn);
        _pendingRemoveBtn = null;
    });
});

function removeMenuItem(btn) {
    const row = btn.closest('.menu-row');
    if (!row) return;
    const hiddenId = row.querySelector('input[name$="[id]"]');
    if (hiddenId && hiddenId.value) {
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_ids[]';
        deleteInput.value = hiddenId.value;
        document.getElementById('menuForm').appendChild(deleteInput);
    }
    row.remove();
    const container = document.getElementById('menuItems');
    if (container.children.length === 0) {
        container.innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-list" style="font-size: 48px;"></i><p class="mt-2">Меню порожнє. Додайте перший пункт меню.</p></div>';
    }
}

// Drag & Drop функціонал
function makeDraggable(element) {
    element.addEventListener('dragstart', function(e) {
        element.classList.add('dragging');
        e.dataTransfer.setData('text/plain', element.dataset.id || '');
    });

    element.addEventListener('dragend', function(e) {
        element.classList.remove('dragging');
    });

    element.addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    element.addEventListener('drop', function(e) {
        e.preventDefault();
        var dragging = document.querySelector('.dragging');
        if (dragging && dragging !== element) {
            var container = document.getElementById('menuItems');
            var rect = element.getBoundingClientRect();
            var midY = rect.top + rect.height / 2;
            
            if (e.clientY < midY) {
                container.insertBefore(dragging, element);
            } else {
                container.insertBefore(dragging, element.nextSibling);
            }
        }
        element.classList.remove('drag-over');
    });

    element.addEventListener('dragenter', function(e) {
        e.preventDefault();
        element.classList.add('drag-over');
    });

    element.addEventListener('dragleave', function(e) {
        element.classList.remove('drag-over');
    });
}

// Ініціалізація
var rows = document.querySelectorAll('.menu-row');
for (var i = 0; i < rows.length; i++) {
    makeDraggable(rows[i]);
}

// Згорнути/розгорнути всі підменю
function collapseAll() {
    // Тут можна додати логіку згортання, якщо потрібно
}

function expandAll() {
    // Тут можна додати логіку розгортання, якщо потрібно
}

// Перед сабмітом оновлюємо `name` атрибути
document.getElementById('menuForm').addEventListener('submit', function(e) {
    var rows = document.querySelectorAll('#menuItems .menu-row');
    
    // Перевірка на порожні назви
    var hasError = false;
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var titleInput = row.querySelector('input[name$="[title]"]');
        if (titleInput && !titleInput.value && !row.querySelector('input[type="hidden"][name$="[title]"][value="---"]')) {
            titleInput.style.borderColor = 'var(--danger)';
            hasError = true;
        } else if (titleInput) {
            titleInput.style.borderColor = '';
        }
    }
    
    if (hasError) {
        e.preventDefault();
        alert('❌ Будь ласка, заповніть назви для всіх пунктів меню');
        return;
    }
    
    // Оновлюємо індекси
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var inputs = row.querySelectorAll('input, select');
        for (var j = 0; j < inputs.length; j++) {
            var input = inputs[j];
            var name = input.getAttribute('name');
            if (name && name.indexOf('menu[') !== -1) {
                var newName = name.replace(/menu\[[^\]]+\]/, 'menu[' + i + ']');
                input.setAttribute('name', newName);
            }
        }
    }
});

// Гарячі клавіші
document.addEventListener('keydown', function(e) {
    // Ctrl+N для нового пункту
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        addMenuItem();
    }
    
    // Ctrl+S для збереження
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        document.getElementById('menuForm').requestSubmit();
    }
});
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>