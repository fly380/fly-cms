<?php
// admin/logs.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

// CSRF токен
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$username = $_SESSION['username'] ?? 'невідомо';
$logFile = $_SERVER['DOCUMENT_ROOT'] . '/data/logs/activity.log';
$message = '';
$messageType = '';

// Очистка логу, якщо була надіслана форма
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearLogs'])) {
    if ($_SESSION['role'] !== 'superadmin') {
        $message = '❌ Очищення логів доступне лише SuperAdmin.';
        $messageType = 'danger';
    } elseif (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        $message = '❌ Невірний токен безпеки.';
        $messageType = 'danger';
    } elseif (file_exists($logFile)) {
        file_put_contents($logFile, '');
        $message = '✅ Логи успішно очищено.';
        $messageType = 'success';
        log_action('🧹 Очистив журнал дій', $username);
    } else {
        $message = '❌ Файл логів не знайдено.';
        $messageType = 'danger';
    }
}

// Експорт логів
if (isset($_GET['export'])) {
    if (file_exists($logFile)) {
        $logs = file_get_contents($logFile);
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="logs_' . date('Y-m-d_H-i-s') . '.txt"');
        header('Content-Length: ' . strlen($logs));
        echo $logs;
        exit;
    }
}

// Отримання статистики
$logs = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [];
$totalLogs = count($logs);
$lastLog = $totalLogs > 0 ? $logs[0] : '';

// Підрахунок активності за типами
$actions = [
    '📝' => 0, // create
    '✏️' => 0, // edit/update
    '🗑️' => 0, // delete
    '📁' => 0, // upload
    '🔑' => 0, // login
    '⚙️' => 0, // settings
    '🧹' => 0, // clear
    '📄' => 0  // page
];

foreach ($logs as $log) {
    foreach (array_keys($actions) as $emoji) {
        if (strpos($log, $emoji) !== false) {
            $actions[$emoji]++;
            break;
        }
    }
}

$current_page = basename($_SERVER['PHP_SELF']);

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

/* Стилі для сторінки */
body {
    background-color: #f0f0f1;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

/* Статистика */
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

/* Основний контейнер логів */
.logs-container {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.logs-header {
    background: #f8f9fa;
    border-bottom: 1px solid #dcdcde;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.logs-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.logs-title h5 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #1d2327;
}

.logs-title .badge {
    background: var(--primary);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.logs-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.log-output {
    background: #1d2327;
    color: #e5e5e5;
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
    font-size: 13px;
    line-height: 1.6;
    padding: 20px;
    margin: 0;
    min-height: 500px;
    max-height: 600px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.log-output .log-entry {
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 4px;
    transition: background 0.2s;
}

.log-output .log-entry:hover {
    background: rgba(255,255,255,0.1);
}

.log-output .log-entry.new {
    animation: highlight 2s ease;
}

@keyframes highlight {
    0% { background: rgba(34,113,177,0.3); }
    100% { background: transparent; }
}

.log-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 20px;
    color: #8c8f94;
}

.log-empty i {
    font-size: 64px;
    margin-bottom: 20px;
    color: #c3c4c7;
}

.log-empty p {
    font-size: 16px;
    margin: 0;
}

/* Фільтри */
.filter-bar {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.filter-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 1px solid #dcdcde;
    background: #fff;
    color: #50575e;
    cursor: pointer;
}

.filter-btn:hover {
    background: #f0f0f1;
    border-color: #8c8f94;
}

.filter-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.filter-btn i {
    margin-right: 4px;
}

/* Кнопки дій */
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

.btn-danger {
    background: var(--danger);
    color: white;
    border-color: var(--danger);
}

.btn-danger:hover {
    background: #b32d2e;
    border-color: #b32d2e;
}

.btn-outline-secondary {
    background: #fff;
    color: #50575e;
    border-color: #dcdcde;
}

.btn-outline-secondary:hover {
    background: #f0f0f1;
    border-color: #8c8f94;
}

.btn-sm {
    padding: 4px 12px;
    font-size: 12px;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Пошук */
.search-box {
    display: flex;
    gap: 8px;
    align-items: center;
}

.search-box input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    font-size: 13px;
    transition: all 0.2s;
}

.search-box input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
    outline: none;
}

/* Інформація про час */
.log-time {
    color: #72aee6;
    margin-right: 8px;
    font-weight: 500;
}

.log-emoji {
    margin-right: 4px;
}

/* Панель інформації */
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

/* Адаптивність */
@media (max-width: 768px) {
    .logs-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .logs-actions {
        width: 100%;
    }
    
    .btn {
        flex: 1;
        justify-content: center;
    }
    
    .stats-card {
        margin-bottom: 10px;
    }
}
</style>

<div class="container-fluid px-4">
    <!-- Заголовок -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <i class="bi bi-journal-text me-2 text-primary"></i>
                Журнал дій
            </h1>
            <p class="text-muted small mt-1">
                <i class="bi bi-info-circle me-1"></i>
                Моніторинг активності користувачів в системі
            </p>
        </div>
        <div>
            <a href="?export=1" class="btn btn-outline-primary <?php echo $totalLogs === 0 ? 'disabled' : ''; ?>">
                <i class="bi bi-download me-1"></i> Експорт
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

   <!-- Фільтри -->
    <div class="filter-bar">
        <div class="d-flex justify-content-between align-items-center">
            <div class="filter-buttons" id="filterButtons">
                <button class="filter-btn active" onclick="filterLogs('all')">
                    <i class="bi bi-files"></i> Усі
                </button>
                <button class="filter-btn" onclick="filterLogs('create')">
                    <i class="bi bi-plus-circle"></i> Створення
                </button>
                <button class="filter-btn" onclick="filterLogs('edit')">
                    <i class="bi bi-pencil"></i> Редагування
                </button>
                <button class="filter-btn" onclick="filterLogs('delete')">
                    <i class="bi bi-trash"></i> Видалення
                </button>
                <button class="filter-btn" onclick="filterLogs('upload')">
                    <i class="bi bi-cloud-upload"></i> Завантаження
                </button>
                <button class="filter-btn" onclick="filterLogs('login')">
                    <i class="bi bi-box-arrow-in-right"></i> Входи
                </button>
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Пошук в логах..." onkeyup="searchLogs()">
                <button class="btn btn-outline-secondary btn-sm" onclick="clearSearch()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Основний контейнер логів -->
    <div class="logs-container">
        <div class="logs-header">
            <div class="logs-title">
                <h5>
                    <i class="bi bi-clock-history me-2"></i>
                    Хронологія подій
                </h5>
                <span class="badge">Оновлюється кожні 10 секунд</span>
            </div>
            <div class="logs-actions">
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshLogs()">
                    <i class="bi bi-arrow-clockwise"></i> Оновити
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="copyLogs()">
                    <i class="bi bi-clipboard"></i> Копіювати
                </button>
                <?php if (in_array($_SESSION['role'], ['superadmin'])): ?>
                <form action="logs.php" method="POST" style="display: inline;" onsubmit="return confirm('🧹 Ви впевнені, що хочете очистити всі логи?\nЦю дію не можна скасувати!')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="clearLogs" value="1">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i>Очистити логи
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div id="log-output" class="log-output">
            <div class="log-empty">
                <i class="bi bi-journal-text"></i>
                <p>Завантаження логів...</p>
            </div>
        </div>

        <div class="info-bar">
            <span>
                <i class="bi bi-calendar me-1"></i>
                Останнє оновлення: <span id="lastUpdate">щойно</span>
            </span>
            <span>
                <i class="bi bi-database me-1"></i>
                <span id="visibleCount">0</span> з <span id="totalCount"><?php echo $totalLogs; ?></span> записів
            </span>
        </div>
    </div>
</div>

<script>
// Глобальні змінні
let allLogs = [];
let currentFilter = 'all';
let currentSearch = '';

// Функція для завантаження логів
function fetchLogs() {
    fetch('logs_fetch.php')
        .then(response => response.text())
        .then(data => {
            // Розбиваємо на масив рядків
            allLogs = data.split('\n').filter(line => line.trim() !== '');
            
            // Відображаємо з урахуванням фільтрів
            displayLogs();
            
            // Оновлюємо час останнього оновлення
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('uk-UA');
            
            // Підраховуємо видимі
            updateCounts();
        })
        .catch(err => {
            document.getElementById('log-output').innerHTML = `
                <div class="log-empty">
                    <i class="bi bi-exclamation-triangle text-danger"></i>
                    <p>Помилка при завантаженні логів</p>
                    <small class="text-muted">${err.message}</small>
                </div>
            `;
            console.error(err);
        });
}

// Функція для відображення логів з фільтрами
function displayLogs() {
    const output = document.getElementById('log-output');
    
    if (allLogs.length === 0) {
        output.innerHTML = `
            <div class="log-empty">
                <i class="bi bi-journal-check"></i>
                <p>Логи порожні</p>
                <small class="text-muted">Немає записів для відображення</small>
            </div>
        `;
        document.getElementById('visibleCount').textContent = '0';
        return;
    }
    
    // Фільтруємо логи
    let filteredLogs = allLogs;
    
    // Фільтр за типом
    if (currentFilter !== 'all') {
        const emojiMap = {
            'create': ['📝', '📄'],
            'edit': ['✏️', '⚙️'],
            'delete': ['🗑️'],
            'upload': ['📁'],
            'login': ['🔑']
        };
        
        const emojis = emojiMap[currentFilter] || [];
        filteredLogs = filteredLogs.filter(log => 
            emojis.some(emoji => log.includes(emoji))
        );
    }
    
    // Пошук
    if (currentSearch) {
        const searchLower = currentSearch.toLowerCase();
        filteredLogs = filteredLogs.filter(log => 
            log.toLowerCase().includes(searchLower)
        );
    }
    
    // Відображаємо
    let html = '';
    filteredLogs.forEach((log, index) => {
        // Виділяємо час (перші 19 символів - YYYY-MM-DD HH:MM:SS)
        const timeMatch = log.match(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/);
        const time = timeMatch ? timeMatch[0] : '';
        const rest = log.replace(time, '').trim();
        
        html += `<div class="log-entry ${index < 5 ? 'new' : ''}">`;
        if (time) {
            html += `<span class="log-time">[${time}]</span>`;
        }
        html += `${rest}</div>`;
    });
    
    if (filteredLogs.length === 0) {
        html = `
            <div class="log-empty">
                <i class="bi bi-search"></i>
                <p>Нічого не знайдено</p>
                <small class="text-muted">Спробуйте змінити параметри фільтрації</small>
            </div>
        `;
    }
    
    output.innerHTML = html;
    document.getElementById('visibleCount').textContent = filteredLogs.length;
}

// Оновлення лічильників
function updateCounts() {
    document.getElementById('totalCount').textContent = allLogs.length;
}

// Фільтрація за типом
function filterLogs(type) {
    currentFilter = type;
    
    // Оновлюємо активний клас кнопок
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    displayLogs();
}

// Пошук
function searchLogs() {
    currentSearch = document.getElementById('searchInput').value;
    displayLogs();
}

// Очистити пошук
function clearSearch() {
    document.getElementById('searchInput').value = '';
    currentSearch = '';
    displayLogs();
}

// Оновити логи
function refreshLogs() {
    fetchLogs();
}

// Копіювати логи
function copyLogs() {
    const text = allLogs.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        alert('✅ Логи скопійовано в буфер обміну');
    }).catch(() => {
        alert('❌ Помилка при копіюванні');
    });
}

// Автоматичне оновлення
document.addEventListener('DOMContentLoaded', function() {
    fetchLogs();
    setInterval(fetchLogs, 10000); // Оновлюємо кожні 10 секунд
});

// Гарячі клавіші
document.addEventListener('keydown', function(e) {
    // Ctrl+F для фокусу на пошуку
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('searchInput').focus();
    }
    
    // Ctrl+R для оновлення
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        refreshLogs();
    }
});
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>