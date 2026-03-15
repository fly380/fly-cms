<?php
// admin/create_post.php
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
require_once __DIR__ . '/../data/publish_scheduler.php';
run_publish_scheduler($pdo);
$message = '';
$messageType = '';

// === ПЕРЕВІРКА НАЯВНОСТІ ТАБЛИЦЬ ===
$tablesExist = true;
$missingTables = [];

// Перевіряємо чи існує таблиця categories
try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='categories'");
    if (!$stmt->fetch()) {
        $tablesExist = false;
        $missingTables[] = 'categories';
    }
} catch (PDOException $e) {
    $tablesExist = false;
    $missingTables[] = 'categories (помилка перевірки)';
}

// Перевіряємо чи існує таблиця tags
try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tags'");
    if (!$stmt->fetch()) {
        $tablesExist = false;
        $missingTables[] = 'tags';
    }
} catch (PDOException $e) {
    $tablesExist = false;
    $missingTables[] = 'tags (помилка перевірки)';
}

// Перевіряємо чи існує таблиця post_categories
try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='post_categories'");
    if (!$stmt->fetch()) {
        $tablesExist = false;
        $missingTables[] = 'post_categories';
    }
} catch (PDOException $e) {
    $tablesExist = false;
    $missingTables[] = 'post_categories (помилка перевірки)';
}

// Перевіряємо чи існує таблиця post_tags
try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='post_tags'");
    if (!$stmt->fetch()) {
        $tablesExist = false;
        $missingTables[] = 'post_tags';
    }
} catch (PDOException $e) {
    $tablesExist = false;
    $missingTables[] = 'post_tags (помилка перевірки)';
}

// === ВИПРАВЛЕНО: Обробка видалення одного запису ===
if (isset($_GET['delete'])) {
    $deleteSlug = $_GET['delete'];
    
    try {
        // Отримуємо ID запису для видалення зв'язків
        $postIdStmt = $pdo->prepare("SELECT id FROM posts WHERE slug = ?");
        $postIdStmt->execute([$deleteSlug]);
        $postId = $postIdStmt->fetchColumn();
        
        if ($postId) {
            // Видаляємо зв'язки з категоріями (якщо таблиці існують)
            if ($tablesExist) {
                $stmt = $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?");
                $stmt->execute([$postId]);
                
                $stmt = $pdo->prepare("DELETE FROM post_tags WHERE post_id = ?");
                $stmt->execute([$postId]);
            }
        }
        
        // Видаляємо сам запис
        $stmt = $pdo->prepare("DELETE FROM posts WHERE slug = ?");
        $stmt->execute([$deleteSlug]);
        
        log_action("🗑️ Видалив запис '{$deleteSlug}'", $username);
        
        header("Location: create_post.php?deleted=1");
        exit;
    } catch (PDOException $e) {
        $message = "❌ Помилка видалення: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Повідомлення про успішне видалення
if (isset($_GET['deleted'])) {
    $message = "✅ Запис успішно видалено!";
    $messageType = 'success';
}

// Отримання категорій (тільки якщо таблиця існує)
$categories = [];
if ($tablesExist) {
    try {
        $stmt = $pdo->query("SELECT id, name, slug FROM categories ORDER BY name");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $categories = [];
    }
}

// Отримання тегів (тільки якщо таблиця існує)
$tags = [];
if ($tablesExist) {
    try {
        $stmt = $pdo->query("SELECT id, name FROM tags ORDER BY name");
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tags = [];
    }
}

// === ОБРОБКА СТВОРЕННЯ НОВОГО ЗАПИСУ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Створення нового запису
    if ($_POST['action'] === 'create' && isset($_POST['new_post_title'])) {
        $title = trim($_POST['new_post_title']);
        $slug = function_exists('ctl_transliterate') ? ctl_transliterate($title) : $title;
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9-]+/', '-', $slug), '-'));
        if (function_exists('fly_apply_filters')) {
            $slug = fly_apply_filters('cms.post.slug', $slug, $title);
        }
        
        // Базовий вміст
        $content = $_POST['content'] ?? '<p>Новий запис...</p>';
        
        // Завжди створюємо як чернетку — публікація відбувається в редакторі
        $draft = 1;
        
        // Видимість
        $visibility = $_POST['visibility'] ?? 'public';
        
        // Пароль для захищених записів
        $password = ($visibility === 'password' && !empty($_POST['post_password'])) ? 
                    password_hash($_POST['post_password'], PASSWORD_DEFAULT) : null;
        
        // Мета-дані
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');
        $meta_keywords = trim($_POST['meta_keywords'] ?? '');
        
        // Налаштування відображення
        $show_on_main = isset($_POST['show_on_main']) ? 1 : 0;
        
        // Перевірка на унікальність slug
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() > 0) {
            $slug .= '-' . uniqid();
        }
        
        try {
            $pdo->beginTransaction();
            
            // ВИПРАВЛЕНО: Вставляємо тільки поля, які є в таблиці
            $allow_comments = 1;
            $sticky = 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO posts (
                    slug, title, content, draft, visibility, post_password, 
                    meta_title, meta_description, meta_keywords, 
                    show_on_main, allow_comments, sticky,
                    created_at, updated_at, author
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, 
                    ?, ?, ?,
                    datetime('now'), datetime('now'), ?
                )
            ");
            
            $stmt->execute([
                $slug, $title, $content, $draft, $visibility, $password,
                $meta_title, $meta_description, $meta_keywords,
                $show_on_main, $allow_comments, $sticky,
                $username
            ]);
            
            $postId = $pdo->lastInsertId();
            
            // Додавання категорій (тільки якщо таблиця існує)
            if ($tablesExist && !empty($_POST['categories'])) {
                $stmt = $pdo->prepare("INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)");
                foreach ($_POST['categories'] as $catId) {
                    $stmt->execute([$postId, $catId]);
                }
            }
            
            // Додавання тегів (тільки якщо таблиця існує)
            if ($tablesExist && !empty($_POST['tags'])) {
                foreach ($_POST['tags'] as $tagId) {
                    $stmt = $pdo->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)");
                    $stmt->execute([$postId, $tagId]);
                }
            }
            
            $pdo->commit();
            
            log_action("📝 Створив новий запис '{$title}' (slug: {$slug})", $username);
                if (function_exists('fly_do_action')) {
                    fly_do_action('cms.post.saved', (int)$pdo->lastInsertId(), [
                        'slug' => $slug, 'title' => $title, 'draft' => $draft, 'action' => 'create',
                    ]);
                }
            
            header("Location: edit_post.php?post=" . urlencode($slug) . "&created=1");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "❌ Помилка створення запису: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
    
    // Масове видалення
    if ($_POST['action'] === 'bulk_delete' && isset($_POST['posts'])) {
        $posts = json_decode($_POST['posts'], true);
        $deleted = 0;
        $errors = 0;
        
        foreach ($posts as $slug) {
            $stmt = $pdo->prepare("DELETE FROM posts WHERE slug = ?");
            if ($stmt->execute([$slug])) {
                $deleted++;
                log_action("🗑️ Видалив запис '{$slug}'", $username);
            } else {
                $errors++;
            }
        }
        
        $message = "✅ Видалено {$deleted} записів";
        if ($errors > 0) $message .= ", помилок: {$errors}";
        $messageType = $errors > 0 ? 'warning' : 'success';
    }
}

// === ОБРОБКА ФІЛЬТРІВ ТА ПОШУКУ ===
$filter = $_GET['filter'] ?? 'all';
$author = $_GET['author'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'date_desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;

// ВИПРАВЛЕНО: Використовуємо draft замість status для фільтрації
$sql = "SELECT p.* FROM posts p WHERE 1=1";
$params = [];

// Фільтр за статусом (використовуємо draft)
if ($filter === 'draft') {
    $sql .= " AND p.draft = 1";
} elseif ($filter === 'published') {
    $sql .= " AND p.draft = 0";
} elseif ($filter === 'private') {
    $sql .= " AND p.visibility = 'private'";
}

// Фільтр за автором
if (!empty($author)) {
    $sql .= " AND p.author = ?";
    $params[] = $author;
}

// Пошук
if (!empty($search)) {
    $sql .= " AND (p.title LIKE ? OR p.content LIKE ? OR p.meta_title LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Сортування
if ($sort === 'title_asc') {
    $sql .= " ORDER BY p.title ASC";
} elseif ($sort === 'title_desc') {
    $sql .= " ORDER BY p.title DESC";
} elseif ($sort === 'date_asc') {
    $sql .= " ORDER BY p.created_at ASC";
} else {
    $sql .= " ORDER BY p.created_at DESC";
}

// Пагінація
$countSql = "SELECT COUNT(*) as total FROM posts p WHERE 1=1";

// Додаємо ті самі фільтри до count запиту
if ($filter !== 'all') {
    if ($filter === 'draft') $countSql .= " AND p.draft = 1";
    elseif ($filter === 'published') $countSql .= " AND p.draft = 0";
    elseif ($filter === 'private') $countSql .= " AND p.visibility = 'private'";
}
if (!empty($author)) $countSql .= " AND p.author = ?";
if (!empty($search)) $countSql .= " AND (p.title LIKE ? OR p.content LIKE ? OR p.meta_title LIKE ?)";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$totalPages = ceil($totalItems / $perPage);
$offset = ($page - 1) * $perPage;

$sql .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Отримання списку авторів для фільтра
$authors = [];
try {
    // Отримуємо список авторів з posts та їх display_name з users
    $stmt = $pdo->query("SELECT DISTINCT p.author, COALESCE(u.display_name, p.author) as author_display
                         FROM posts p 
                         LEFT JOIN users u ON LOWER(p.author) = LOWER(u.login)
                         WHERE p.author IS NOT NULL 
                         ORDER BY COALESCE(u.display_name, p.author)");
    $authorsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Формуємо масив [login => display_name]
    foreach ($authorsData as $auth) {
        $authors[$auth['author']] = $auth['author_display'];
    }
} catch (PDOException $e) {
    $authors = [];
}

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
.badge.bg-info { background: #e5f0fa !important; color: #043959 !important; border-left: 3px solid var(--info); }
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
.post-status {
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
.post-title {
    font-weight: 500;
    color: #1d2327;
    text-decoration: none;
}

.post-title:hover {
    color: var(--primary);
    text-decoration: underline;
}

.post-meta {
    font-size: 12px;
    color: #646970;
    margin-top: 4px;
}

.post-meta i {
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
                <i class="bi bi-pencil-square me-2 text-primary"></i>
                Блог — Записи
            </h1>
            <p class="text-muted small mt-1">
                <i class="bi bi-info-circle me-1"></i>
                Управління записами блогу
            </p>
        </div>
        <div>
            <button class="btn btn-success" onclick="showCreateModal()">
                <i class="bi bi-plus-lg me-1"></i> Додати запис
            </button>
        </div>
    </div>

    <!-- Повідомлення -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType ?: 'info'; ?> alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
            <i class="bi bi-info-circle me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Якщо таблиць немає, показуємо інструкцію -->
    <?php if (!$tablesExist): ?>
        <div class="card mb-4 border-warning animate__animated animate__fadeIn">
            <div class="card-header bg-warning text-white">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Потрібно створити таблиці в базі даних
            </div>
            <div class="card-body">
                <p>Виконайте цей SQL запит у вашій базі даних:</p>
                <pre class="bg-light p-3 rounded"><code><?php echo htmlspecialchars($createTablesSQL); ?></code></pre>
                <p class="text-muted small">Після створення таблиць оновіть сторінку.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Статистика -->
    <?php if ($tablesExist): ?>
    <div class="row g-3 mb-4">
        <?php
        $stats = [];
        try {
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN draft = 0 THEN 1 ELSE 0 END) as published,
                    SUM(CASE WHEN draft = 1 THEN 1 ELSE 0 END) as drafts,
                    SUM(CASE WHEN visibility = 'private' THEN 1 ELSE 0 END) as private
                FROM posts
            ");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $stats = ['total' => 0, 'published' => 0, 'drafts' => 0, 'private' => 0];
        }
        ?>
        <div class="col-sm-6 col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e5f0fa;">
                    <i class="bi bi-file-text text-primary"></i>
                </div>
                <div class="stats-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stats-label">Всього записів</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #edfaef;">
                    <i class="bi bi-check-circle text-success"></i>
                </div>
                <div class="stats-number"><?php echo $stats['published'] ?? 0; ?></div>
                <div class="stats-label">Опубліковано</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fcf9e8;">
                    <i class="bi bi-pencil text-warning"></i>
                </div>
                <div class="stats-number"><?php echo $stats['drafts'] ?? 0; ?></div>
                <div class="stats-label">Чернетки</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #f0f0f1;">
                    <i class="bi bi-lock text-secondary"></i>
                </div>
                <div class="stats-number"><?php echo $stats['private'] ?? 0; ?></div>
                <div class="stats-label">Приватні</div>
            </div>
        </div>
    </div>

    <!-- Фільтри та пошук -->
    <div class="filter-bar">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label small text-muted">Статус</label>
                <select class="form-select" name="filter">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Усі записи</option>
                    <option value="published" <?php echo $filter === 'published' ? 'selected' : ''; ?>>Опубліковані</option>
                    <option value="draft" <?php echo $filter === 'draft' ? 'selected' : ''; ?>>Чернетки</option>
                    <option value="private" <?php echo $filter === 'private' ? 'selected' : ''; ?>>Приватні</option>
                </select>
            </div>
            <?php if (!empty($authors)): ?>
            <div class="col-md-3">
                <label class="form-label small text-muted">Автор</label>
                <select class="form-select" name="author">
                    <option value="">Всі автори</option>
                    <?php foreach ($authors as $login => $display_name): ?>
                        <option value="<?php echo htmlspecialchars($login); ?>" <?php echo $author == $login ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label small text-muted">Сортування</label>
                <select class="form-select" name="sort">
                    <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Найновіші</option>
                    <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Найстаріші</option>
                    <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>За назвою (А-Я)</option>
                    <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>За назвою (Я-А)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Пошук</label>
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="Пошук записів..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Таблиця записів -->
    <?php if (!empty($posts)): ?>
        <div class="card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-table me-2 text-primary"></i>
                    <strong>Список записів</strong>
                    <span class="badge bg-light text-dark ms-2"><?php echo $totalItems; ?></span>
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
                            <th>Заголовок</th>
                            <th>Автор</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th width="250">Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $index => $post): ?>
                            <tr data-slug="<?php echo htmlspecialchars($post['slug']); ?>" class="<?php echo $index < 3 ? 'tr-new' : ''; ?>">
                                <td>
                                    <input type="checkbox" class="post-select" value="<?php echo htmlspecialchars($post['slug']); ?>" onchange="updateBulkButton()">
                                </td>
                                <td>
                                    <div class="d-flex align-items-start">
                                        <div>
                                            <a href="edit_post.php?post=<?php echo urlencode($post['slug']); ?>" class="post-title">
                                                <?php echo htmlspecialchars($post['meta_title'] ?: $post['title']); ?>
                                            </a>
                                            <?php if (!empty($post['post_password'])): ?>
                                                <span class="badge bg-secondary ms-1" title="Захищено паролем">🔒</span>
                                            <?php endif; ?>
                                            <div class="post-meta">
                                                <i class="bi bi-link"></i> <?php echo htmlspecialchars($post['slug']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-info bg-opacity-10 text-info py-2 px-3">
                                        <i class="bi bi-person-circle me-1"></i>
                                        <?php 
                                            // Отримуємо display_name автора з БД
                                            if (!empty($post['author'])) {
                                                try {
                                                    // Спробуємо знайти користувача по точному логіну
                                                    $stmt = $pdo->prepare("SELECT display_name FROM users WHERE login = ?");
                                                    $stmt->execute([$post['author']]);
                                                    $authorInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                                                    
                                                    // Якщо не знайдено - спробуємо по LIKE (може бути регістр-залежна проблема)
                                                    if (!$authorInfo) {
                                                        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE LOWER(login) = LOWER(?)");
                                                        $stmt->execute([$post['author']]);
                                                        $authorInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                                                    }
                                                    
                                                    $authorDisplay = $authorInfo && !empty($authorInfo['display_name']) 
                                                        ? $authorInfo['display_name'] 
                                                        : $post['author'];
                                                    echo htmlspecialchars($authorDisplay);
                                                } catch (Exception $e) {
                                                    echo htmlspecialchars($post['author']);
                                                }
                                            } else {
                                                echo '—';
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="post-status">
                                        <span class="status-dot 
                                            <?php 
                                            if (isset($post['draft']) && $post['draft'] == 1) {
                                                echo 'draft';
                                            } else {
                                                echo 'published';
                                            }
                                            ?>">
                                        </span>
                                        
                                        <?php if (isset($post['draft']) && $post['draft'] == 1): ?>
                                            <span class="badge bg-warning text-dark">Чернетка</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Опубліковано</span>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($post['visibility']) && $post['visibility'] === 'private'): ?>
                                            <span class="badge bg-secondary mt-1">Приватний</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="small">
                                        <i class="bi bi-calendar3 me-1 text-muted"></i>
                                        <?php echo date('d.m.Y', strtotime($post['created_at'])); ?>
                                        <br>
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo date('H:i', strtotime($post['created_at'])); ?>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit_post.php?post=<?php echo urlencode($post['slug']); ?>" class="action-btn edit-btn">
                                            <i class="bi bi-pencil"></i> Редагувати
                                        </a>
                                        <a href="<?php echo '/post/' . urlencode($post['slug']); ?>" target="_blank" class="action-btn view-btn">
                                            <i class="bi bi-eye"></i> Переглянути
                                        </a>
                                        <button type="button" class="action-btn delete-btn" onclick="deletePost('<?php echo $post['slug']; ?>')">
                                            <i class="bi bi-trash"></i> Видалити
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Пагінація -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="small text-muted">
                            Показано <?php echo $offset + 1; ?> - <?php echo min($offset + $perPage, $totalItems); ?> з <?php echo $totalItems; ?> записів
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&filter=<?php echo urlencode($filter); ?>&author=<?php echo urlencode($author); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php 
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                for ($i = $start; $i <= $end; $i++): 
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>&author=<?php echo urlencode($author); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&filter=<?php echo urlencode($filter); ?>&author=<?php echo urlencode($author); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-5 bg-white rounded-3 border">
            <div class="display-1 text-muted mb-3">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <h3>Записів не знайдено</h3>
            <p class="text-muted mb-4">Спробуйте змінити параметри фільтрації або створіть новий запис</p>
            <button class="btn btn-primary btn-lg" onclick="showCreateModal()">
                <i class="bi bi-plus-lg me-1"></i> Створити перший запис
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Модальне вікно створення запису -->
<div class="modal fade" id="createPostModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="createPostForm">
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        Створення нового запису
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Назва запису <span class="text-danger">*</span></label>
                                <input type="text" name="new_post_title" class="form-control" required>
                                <small class="text-muted">Буде автоматично створено URL (slug)</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Заголовок (meta_title)</label>
                                <input type="text" name="meta_title" class="form-control">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Опис (meta_description)</label>
                                <textarea name="meta_description" class="form-control" rows="2" maxlength="160"></textarea>
                                <small class="text-muted">Макс. 160 символів</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Ключові слова (meta_keywords)</label>
                                <input type="text" name="meta_keywords" class="form-control">
                                <small class="text-muted">Розділяйте комами</small>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Статус</h6>
                                    <div class="alert alert-warning py-2 px-3 mb-2" style="font-size:.85rem">
                                        <i class="bi bi-info-circle me-1"></i> Запис буде створено як <strong>чернетку</strong>. Опублікувати можна в редакторі.
                                    </div>
                                    
                                    <h6 class="card-title mt-3">Видимість</h6>
                                    <select name="visibility" class="form-select" id="visibilitySelect">
                                        <option value="public" selected>Публічний</option>
                                        <option value="private">Приватний</option>
                                        <option value="password">Захищений паролем</option>
                                    </select>
                                    
                                    <div id="passwordField" style="display: none;" class="mt-2">
                                        <input type="password" name="post_password" class="form-control" placeholder="Пароль">
                                    </div>
                                    
                                    <?php if (!empty($categories)): ?>
                                    <h6 class="card-title mt-3">Категорії</h6>
                                    <div class="category-list" style="max-height: 150px; overflow-y: auto;">
                                        <?php foreach ($categories as $cat): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" id="cat<?php echo $cat['id']; ?>">
                                                <label class="form-check-label" for="cat<?php echo $cat['id']; ?>">
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($tags)): ?>
                                    <h6 class="card-title mt-3">Теги</h6>
                                    <select class="form-select" name="tags[]" multiple size="3">
                                        <?php foreach ($tags as $tag): ?>
                                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Можна ввести нові теги через кому</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">Налаштування</h6>
                                    
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="show_on_main" id="show_on_main" checked>
                                        <label class="form-check-label" for="show_on_main">
                                            Показувати на головній
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Скасувати
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Створити запис
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
var createPostModal = null;
var confirmModal = null;

// Ініціалізація
document.addEventListener('DOMContentLoaded', function() {
    createPostModal = new bootstrap.Modal(document.getElementById('createPostModal'));
    confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    
    // Поле пароля
    var visibilitySelect = document.getElementById('visibilitySelect');
    if (visibilitySelect) {
        visibilitySelect.addEventListener('change', function() {
            var passwordField = document.getElementById('passwordField');
            passwordField.style.display = this.value === 'password' ? 'block' : 'none';
        });
    }
});

// Показати модальне вікно створення
function showCreateModal() {
    createPostModal.show();
}

// Видалення одного запису - ВИПРАВЛЕНО
function deletePost(slug) {
    if (!slug) return;
    
    console.log('Видалення запису:', slug); // Для перевірки
    
    document.getElementById('confirmMessage').innerHTML = 
        '<i class="bi bi-exclamation-triangle text-warning" style="font-size: 48px;"></i>' +
        '<p class="mt-3 mb-0">Видалити запис <strong>"' + slug + '"</strong>?</p>' +
        '<small class="text-muted">Цю дію не можна скасувати!</small>';
    
    document.getElementById('confirmAction').onclick = function() {
        confirmModal.hide();
        window.location.href = '?delete=' + encodeURIComponent(slug);
    };
    
    confirmModal.show();
}

// Масове видалення
function toggleAll(checkbox) {
    var checkboxes = document.querySelectorAll('.post-select');
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
    updateBulkButton();
}

function updateBulkButton() {
    var checkboxes = document.querySelectorAll('.post-select:checked');
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
    var checkboxes = document.querySelectorAll('.post-select:checked');
    if (checkboxes.length === 0) return;
    
    var posts = [];
    checkboxes.forEach(function(cb) {
        posts.push(cb.value);
    });
    
    document.getElementById('confirmMessage').innerHTML = 
        '<i class="bi bi-exclamation-triangle text-warning" style="font-size: 48px;"></i>' +
        '<p class="mt-3 mb-0">Видалити ' + posts.length + ' записів?</p>' +
        '<small class="text-muted">Цю дію не можна скасувати!</small>';
    
    document.getElementById('confirmAction').onclick = function() {
        confirmModal.hide();
        
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="bulk_delete">' +
                        '<input type="hidden" name="posts" value=\'' + JSON.stringify(posts) + '\'>';
        document.body.appendChild(form);
        form.submit();
    };
    
    confirmModal.show();
}

// Гарячі клавіші
document.addEventListener('keydown', function(e) {
    // Ctrl+N для нового запису
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        showCreateModal();
    }
    
    // Escape для закриття модальних вікон
    if (e.key === 'Escape') {
        if (createPostModal) createPostModal.hide();
        if (confirmModal) confirmModal.hide();
    }
});

// Валідація meta_description
var metaDesc = document.querySelector('textarea[name="meta_description"]');
if (metaDesc) {
    metaDesc.addEventListener('input', function() {
        if (this.value.length > 160) {
            this.value = this.value.substring(0, 160);
        }
    });
}

// Автоматичне створення meta_title
var titleInput = document.querySelector('input[name="new_post_title"]');
if (titleInput) {
    titleInput.addEventListener('blur', function() {
        if (this.value && !document.querySelector('input[name="meta_title"]').value) {
            document.querySelector('input[name="meta_title"]').value = this.value;
        }
    });
}
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>