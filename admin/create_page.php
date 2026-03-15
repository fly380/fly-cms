<?php
// admin/create_page.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../plugins/ukr_to_lat.php';
require_once __DIR__ . '/../data/log_action.php';

$pdo = connectToDatabase();
$username = $_SESSION['username'] ?? 'невідомо';
$message = '';
$messageType = '';

// === ПЕРЕВІРКА НАЯВНОСТІ ПОЛІВ ===
// Перевіряємо, які поля є в таблиці pages
$columns = [];
try {
    $stmt = $pdo->query("PRAGMA table_info(pages)");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
} catch (Exception $e) {
    $columns = [];
}

// === ОБРОБКА СТВОРЕННЯ НОВОЇ СТОРІНКИ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Створення нової сторінки
    if ($_POST['action'] === 'create' && isset($_POST['new_page_title'])) {
        $title = trim($_POST['new_page_title']);
        $slug = function_exists('ctl_transliterate') ? ctl_transliterate($title) : $title;
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9-]+/', '-', $slug), '-'));
        if (function_exists('fly_apply_filters')) {
            $slug = fly_apply_filters('cms.page.slug', $slug, $title);
        }
        
        // Базовий вміст
        $content = $_POST['content'] ?? '<p>Новий контент...</p>';
        
        // Для сумісності з існуючою структурою
        $draft = (isset($_POST['status']) && $_POST['status'] === 'draft') ? 1 : 0;
        $visibility = $_POST['visibility'] ?? 'public';
        
        // Мета-дані (якщо поля існують)
        $meta_title = in_array('meta_title', $columns) ? trim($_POST['meta_title'] ?? '') : '';
        $meta_description = in_array('meta_description', $columns) ? trim($_POST['meta_description'] ?? '') : '';
        
        // Перевірка на унікальність slug
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() > 0) {
            $slug .= '-' . uniqid();
        }
        
        try {
            // Динамічне створення запиту в залежності від наявних полів
            $fields = ['slug', 'title', 'content', 'draft', 'visibility', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', 'datetime("now")'];
            $values = [$slug, $title, $content, $draft, $visibility];
            
            // Додаємо meta_title якщо поле існує
            if (in_array('meta_title', $columns)) {
                $fields[] = 'meta_title';
                $placeholders[] = '?';
                $values[] = $meta_title;
            }
            
            // Додаємо meta_description якщо поле існує
            if (in_array('meta_description', $columns)) {
                $fields[] = 'meta_description';
                $placeholders[] = '?';
                $values[] = $meta_description;
            }
            
            // Додаємо author якщо поле існує
            if (in_array('author', $columns)) {
                $fields[] = 'author';
                $placeholders[] = '?';
                $values[] = $username;
            }
            
            // Додаємо updated_at якщо поле існує
            if (in_array('updated_at', $columns)) {
                $fields[] = 'updated_at';
                $placeholders[] = 'datetime("now")';
            }
            
            $sql = "INSERT INTO pages (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            
            log_action("📄 Створив нову сторінку '{$title}' (slug: {$slug})", $username);
            
            header("Location: edit_page.php?page=" . urlencode($slug) . "&created=1");
            exit;
            
        } catch (Exception $e) {
            $message = "❌ Помилка створення сторінки: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
    
    // Масове видалення
    if ($_POST['action'] === 'bulk_delete' && isset($_POST['pages'])) {
        $pages = json_decode($_POST['pages'], true);
        $deleted = 0;
        $errors = 0;
        
        foreach ($pages as $slug) {
            // Захист від видалення головної сторінки
            if ($slug === 'main') {
                $errors++;
                continue;
            }
            
            $stmt = $pdo->prepare("DELETE FROM pages WHERE slug = ?");
            if ($stmt->execute([$slug])) {
                $deleted++;
                log_action("🗑️ Видалив сторінку '{$slug}'", $username);
                if (function_exists('fly_do_action')) {
                    fly_do_action('cms.page.saved', (int)$pdo->lastInsertId(), [
                        'slug' => $slug, 'title' => $title, 'action' => 'create',
                    ]);
                }
            } else {
                $errors++;
            }
        }
        
        $message = "✅ Видалено {$deleted} сторінок";
        if ($errors > 0) $message .= ", помилок: {$errors} (головну сторінку не можна видалити)";
        $messageType = $errors > 0 ? 'warning' : 'success';
    }
}

// Видалення однієї сторінки
if (isset($_GET['delete'])) {
    $deleteSlug = basename($_GET['delete']);
    
    // Захист від видалення головної сторінки
    if ($deleteSlug === 'main') {
        $message = "❌ Головну сторінку не можна видалити!";
        $messageType = 'danger';
    } else {
        $stmt = $pdo->prepare("DELETE FROM pages WHERE slug = ?");
        $stmt->execute([$deleteSlug]);
        $message = "✅ Сторінку <strong>{$deleteSlug}</strong> видалено.";
        $messageType = 'success';
        log_action("🗑️ Видалив сторінку '{$deleteSlug}'", $username);
    }
}

// === ОБРОБКА ФІЛЬТРІВ ТА ПОШУКУ ===
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'date_desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;

// Отримання списку сторінок з фільтрацією
$sql = "SELECT * FROM pages WHERE 1=1";
$params = [];

// Фільтр за статусом (використовуємо draft)
if ($filter === 'draft') {
    $sql .= " AND draft = 1";
} elseif ($filter === 'published') {
    $sql .= " AND draft = 0";
} elseif ($filter === 'private') {
    $sql .= " AND visibility = 'private'";
}

// Пошук
if (!empty($search)) {
    $sql .= " AND (title LIKE ? OR slug LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Сортування
if ($sort === 'title_asc') {
    $sql .= " ORDER BY title ASC";
} elseif ($sort === 'title_desc') {
    $sql .= " ORDER BY title DESC";
} elseif ($sort === 'date_asc') {
    $sql .= " ORDER BY created_at ASC";
} else {
    $sql .= " ORDER BY created_at DESC";
}

// Пагінація
$countSql = "SELECT COUNT(*) as total FROM pages WHERE 1=1";
if (!empty($search)) {
    $countSql .= " AND (title LIKE ? OR slug LIKE ?)";
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn() + 1; // +1 для головної сторінки
$totalPages = ceil($totalItems / $perPage);
$offset = ($page - 1) * $perPage;

$sql .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Додаємо головну сторінку вручну
$mainPage = [
    'slug' => 'main',
    'title' => 'Головна',
    'draft' => 0,
    'visibility' => 'public',
    'created_at' => date('Y-m-d H:i:s'),
    'author' => 'system'
];

// Вивід
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

/* Стилі для таблиці */
.table th {
    font-weight: 600;
    color: #1d2327;
    border-bottom-width: 2px;
}

.table td {
    vertical-align: middle;
    padding: 1rem 0.75rem;
}

/* Стилі для бейджів */
.badge {
    font-weight: 500;
    padding: 0.4em 0.8em;
    border-radius: 4px;
}

.badge.bg-success { background: #edfaef !important; color: #00450c !important; border-left: 3px solid var(--success); }
.badge.bg-warning { background: #fcf9e8 !important; color: #614d05 !important; border-left: 3px solid var(--warning); }
.badge.bg-secondary { background: #f0f0f1 !important; color: #2c3338 !important; border-left: 3px solid #787c82; }

/* Стилі для текстових кнопок */
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 6px 12px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 1px solid transparent;
    text-decoration: none;
    cursor: pointer;
}

.action-btn i {
    font-size: 14px;
}

.action-btn.edit-btn {
    background: #f0f6fc;
    color: var(--primary);
    border-color: #c5d9ed;
}

.action-btn.edit-btn:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.action-btn.view-btn {
    background: #e5f0fa;
    color: var(--info);
    border-color: #b8d6f2;
}

.action-btn.view-btn:hover {
    background: var(--info);
    color: white;
    border-color: var(--info);
}

.action-btn.delete-btn {
    background: #fcf0f1;
    color: var(--danger);
    border-color: #f2b6bb;
}

.action-btn.delete-btn:hover {
    background: var(--danger);
    color: white;
    border-color: var(--danger);
}

/* Стилі для статусів */
.page-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.status-dot.published { background: var(--success); box-shadow: 0 0 0 2px #edfaef; }
.status-dot.draft { background: var(--warning); box-shadow: 0 0 0 2px #fcf9e8; }
.status-dot.private { background: #787c82; box-shadow: 0 0 0 2px #f0f0f1; }

/* Стилі для заголовка */
.page-title {
    font-weight: 500;
    color: #1d2327;
    text-decoration: none;
}

.page-title:hover {
    color: var(--primary);
    text-decoration: underline;
}

.page-meta {
    font-size: 12px;
    color: #646970;
    margin-top: 4px;
}

.page-meta i {
    margin-right: 2px;
    font-size: 11px;
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

.tr-new {
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

/* Стилі для фільтрів */
.filter-bar {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

/* Адаптивність */
@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
    }
    
    .action-btn {
        width: 100%;
    }
    
    .table td {
        padding: 0.75rem 0.5rem;
    }
}
</style>

<div class="container-fluid px-4">
    <!-- Заголовок -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <i class="bi bi-files me-2 text-primary"></i>
                Керування сторінками
            </h1>
            <p class="text-muted small mt-1">
                <i class="bi bi-info-circle me-1"></i>
                Створення та редагування сторінок сайту
            </p>
        </div>
        <div>
            <button class="btn btn-success" onclick="showCreateModal()">
                <i class="bi bi-plus-lg me-1"></i> Додати сторінку
            </button>
        </div>
    </div>

    <!-- Повідомлення -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType ?: 'info'; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <!-- Фільтри та пошук -->
    <div class="filter-bar">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label small text-muted">Статус</label>
                <select class="form-select" name="filter">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Усі сторінки</option>
                    <option value="published" <?php echo $filter === 'published' ? 'selected' : ''; ?>>Опубліковані</option>
                    <option value="draft" <?php echo $filter === 'draft' ? 'selected' : ''; ?>>Чернетки</option>
                    <option value="private" <?php echo $filter === 'private' ? 'selected' : ''; ?>>Приватні</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Сортування</label>
                <select class="form-select" name="sort">
                    <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Найновіші</option>
                    <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Найстаріші</option>
                    <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>За назвою (А-Я)</option>
                    <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>За назвою (Я-А)</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">Пошук</label>
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="Пошук сторінок..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Таблиця сторінок -->
    <div class="card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-table me-2 text-primary"></i>
                <strong>Список сторінок</strong>
                <span class="badge bg-light text-dark ms-2"><?php echo $totalPages; ?></span>
            </div>
            <button class="btn btn-sm btn-outline-danger" id="bulkDeleteBtn" style="display: none;" onclick="bulkDelete()">
                <i class="bi bi-trash me-1"></i> Видалити вибрані (<span id="selectedCount">0</span>)
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="40">
                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                        </th>
                        <th>Назва сторінки</th>
                        <th>URL</th>
                        <th>Статус</th>
                        <th>Дата створення</th>
                        <th width="250">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Головна сторінка (завжди перша) -->
                    <tr data-slug="main" class="table-primary">
                        <td>
                            <input type="checkbox" class="page-select" value="main" disabled title="Головну сторінку не можна видалити">
                        </td>
                        <td>
                            <div class="d-flex align-items-start">
                                <div>
                                    <span class="fw-bold">
                                        <i class="bi bi-house-door-fill me-1 text-primary"></i>
                                        Головна сторінка
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <code>/main</code>
                        </td>
                        <td>
                            <div class="page-status">
                                <span class="status-dot published"></span>
                                <span class="badge bg-success">Опубліковано</span>
                            </div>
                        </td>
                        <td>
                            <div class="small text-muted">
                                <i class="bi bi-gear me-1"></i> Системна
                            </div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="edit_page.php?page=main" class="action-btn edit-btn">
                                    <i class="bi bi-pencil"></i> Редагувати
                                </a>
                                <a href="/" target="_blank" class="action-btn view-btn">
                                    <i class="bi bi-eye"></i> Переглянути
                                </a>
                                <button type="button" class="action-btn delete-btn" disabled title="Головну сторінку не можна видалити" style="opacity: 0.5;">
                                    <i class="bi bi-trash"></i> Видалити
                                </button>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Інші сторінки -->
                    <?php foreach ($pages as $index => $page): ?>
                        <?php 
                        $isDraft = ($page['draft'] ?? 0) == 1;
                        $isPrivate = ($page['visibility'] ?? 'public') === 'private';
                        ?>
                        <tr data-slug="<?php echo htmlspecialchars($page['slug']); ?>" class="<?php echo $index < 3 ? 'tr-new' : ''; ?>">
                            <td>
                                <input type="checkbox" class="page-select" value="<?php echo htmlspecialchars($page['slug']); ?>" onchange="updateBulkButton()">
                            </td>
                            <td>
                                <div class="d-flex align-items-start">
                                    <div>
                                        <a href="edit_page.php?page=<?php echo urlencode($page['slug']); ?>" class="page-title">
                                            <?php echo htmlspecialchars($page['title']); ?>
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <code>/<?php echo htmlspecialchars($page['slug']); ?></code>
                            </td>
                            <td>
                                <div class="page-status">
                                    <span class="status-dot 
                                        <?php 
                                        if ($isDraft) echo 'draft';
                                        elseif ($isPrivate) echo 'private';
                                        else echo 'published';
                                        ?>">
                                    </span>
                                    
                                    <?php if ($isDraft): ?>
                                        <span class="badge bg-warning text-dark">Чернетка</span>
                                    <?php elseif ($isPrivate): ?>
                                        <span class="badge bg-secondary">Приватна</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Опубліковано</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <i class="bi bi-calendar3 me-1 text-muted"></i>
                                    <?php echo date('d.m.Y', strtotime($page['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="edit_page.php?page=<?php echo urlencode($page['slug']); ?>" class="action-btn edit-btn">
                                        <i class="bi bi-pencil"></i> Редагувати
                                    </a>
                                    <a href="/<?php echo urlencode($page['slug']); ?>" target="_blank" class="action-btn view-btn">
                                        <i class="bi bi-eye"></i> Переглянути
                                    </a>
                                    <button type="button" class="action-btn delete-btn" onclick="deletePage('<?php echo urlencode($page['slug']); ?>')">
                                        <i class="bi bi-trash"></i> Видалити
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($pages)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="bi bi-files" style="font-size: 48px;"></i>
                                    <p class="mt-2">Немає створених сторінок</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Модальне вікно створення сторінки -->
<div class="modal fade" id="createPageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="createPageForm">
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        Створення нової сторінки
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Назва сторінки <span class="text-danger">*</span></label>
                        <input type="text" name="new_page_title" class="form-control" required>
                        <small class="text-muted">Буде автоматично створено URL (slug)</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Статус</label>
                            <select name="status" class="form-select">
                                <option value="published" selected>Опубліковано</option>
                                <option value="draft">Чернетка</option>
                            </select>
                        </div>

                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Скасувати
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Створити сторінку
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Модальне вікно підтвердження -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Підтвердження
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="confirmMessage">
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
var createPageModal = null;
var confirmModal = null;

// Ініціалізація
document.addEventListener('DOMContentLoaded', function() {
    createPageModal = new bootstrap.Modal(document.getElementById('createPageModal'));
    confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
});

// Показати модальне вікно створення
function showCreateModal() {
    createPageModal.show();
}

// Видалення однієї сторінки
function deletePage(slug) {
    if (slug === 'main') {
        alert('Головну сторінку не можна видалити!');
        return;
    }
    
    document.getElementById('confirmMessage').innerHTML = 
        '<i class="bi bi-exclamation-triangle text-warning" style="font-size: 48px;"></i>' +
        '<p class="mt-3 mb-0">Видалити сторінку?</p>' +
        '<small class="text-muted">Цю дію не можна скасувати!</small>';
    
    document.getElementById('confirmAction').onclick = function() {
        confirmModal.hide();
        window.location.href = '?delete=' + encodeURIComponent(slug);
    };
    
    confirmModal.show();
}

// Масове видалення
function toggleAll(checkbox) {
    var checkboxes = document.querySelectorAll('.page-select:not(:disabled)');
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
    updateBulkButton();
}

function updateBulkButton() {
    var checkboxes = document.querySelectorAll('.page-select:checked');
    var bulkBtn = document.getElementById('bulkDeleteBtn');
    var selectedCount = document.getElementById('selectedCount');
    
    if (checkboxes.length > 0) {
        bulkBtn.style.display = 'inline-block';
        selectedCount.textContent = checkboxes.length;
    } else {
        bulkBtn.style.display = 'none';
    }
}

function bulkDelete() {
    var checkboxes = document.querySelectorAll('.page-select:checked');
    if (checkboxes.length === 0) return;
    
    var pages = [];
    checkboxes.forEach(function(cb) {
        pages.push(cb.value);
    });
    
    document.getElementById('confirmMessage').innerHTML = 
        '<i class="bi bi-exclamation-triangle text-warning" style="font-size: 48px;"></i>' +
        '<p class="mt-3 mb-0">Видалити ' + pages.length + ' сторінок?</p>' +
        '<small class="text-muted">Цю дію не можна скасувати!</small>';
    
    document.getElementById('confirmAction').onclick = function() {
        confirmModal.hide();
        
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="bulk_delete">' +
                        '<input type="hidden" name="pages" value=\'' + JSON.stringify(pages) + '\'>';
        document.body.appendChild(form);
        form.submit();
    };
    
    confirmModal.show();
}

// Гарячі клавіші
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        showCreateModal();
    }
    
    if (e.key === 'Escape') {
        if (createPageModal) createPageModal.hide();
        if (confirmModal) confirmModal.hide();
    }
});

// Автоматичне створення назви
var titleInput = document.querySelector('input[name="new_page_title"]');
if (titleInput) {
    titleInput.addEventListener('blur', function() {
        // Нічого не робимо, просто даємо користувачу ввести назву
    });
}
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>