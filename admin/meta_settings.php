<?php
// admin/meta_settings.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

$username = $_SESSION['username'] ?? 'невідомо';
$message = '';
$messageType = '';

$db = fly_db();

// Отримуємо поточні значення
$stmt = $db->query("SELECT key, value FROM settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['site_author', 'cms_name', 'cms_version', 'cms_changelog'];
    foreach ($fields as $field) {
        $value = trim($_POST[$field] ?? '');
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$field, $value]);
    }
    
    log_action("⚙️ Оновив налаштування CMS", $username);
    header("Location: meta_settings.php?success=1");
    exit;
}

$page_title = 'Налаштування даних CMS';

ob_start();
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="/assets/tinymce/tinymce.min.js"></script>

<style>
:root {
    --primary: #2271b1;
    --primary-hover: #135e96;
    --success: #00a32a;
    --warning: #dba617;
    --danger: #d63638;
    --info: #72aee6;
}

body {
    background-color: #f0f0f1;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

/* Основний контейнер */
.settings-container {
    max-width: 900px;
    margin: 30px auto;
    padding: 0 20px;
}

/* Картка налаштувань */
.settings-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.settings-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #dcdcde;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.settings-header h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: #1d2327;
    display: flex;
    align-items: center;
    gap: 10px;
}

.settings-header h1 i {
    color: var(--primary);
    font-size: 24px;
}

.settings-header .badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    background: #e5f0fa;
    color: var(--primary);
}

.settings-body {
    padding: 24px;
}

/* Стилі для полів форми */
.form-section {
    background: #f8f9fa;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}

.form-section h3 {
    font-size: 16px;
    font-weight: 600;
    color: #1d2327;
    margin: -20px -20px 20px -20px;
    padding: 15px 20px;
    background: #fff;
    border-bottom: 1px solid #dcdcde;
    border-radius: 8px 8px 0 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-section h3 i {
    color: var(--primary);
}

.form-label {
    font-weight: 500;
    color: #1d2327;
    margin-bottom: 8px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-label i {
    color: var(--primary);
    font-size: 16px;
}

.form-control {
    border: 1px solid #dcdcde;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    transition: all 0.2s;
    background: #fff;
}

.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
    outline: none;
}

.form-control-lg {
    padding: 12px 16px;
    font-size: 16px;
}

.form-text {
    font-size: 12px;
    color: #646970;
    margin-top: 4px;
}

/* Стилі для кнопок */
.btn {
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
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

.btn-outline-secondary {
    background: transparent;
    color: #50575e;
    border-color: #dcdcde;
}

.btn-outline-secondary:hover {
    background: #f0f0f1;
}

.btn-lg {
    padding: 12px 24px;
    font-size: 16px;
}

.btn-block {
    width: 100%;
}

/* Стилі для інформаційної панелі */
.info-panel {
    background: #f8f9fa;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 16px;
    margin-top: 24px;
}

.info-panel-item {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #e9ecef;
}

.info-panel-item:last-child {
    border-bottom: none;
}

.info-panel-label {
    color: #646970;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.info-panel-value {
    font-weight: 500;
    color: #1d2327;
    font-size: 13px;
}

/* Стилі для алертів */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1px solid transparent;
}

.alert-success {
    background: #edfaef;
    color: #00450c;
    border-color: #c3e6cb;
}

.alert i {
    font-size: 20px;
}

/* Стилі для статусів */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.status-badge.info {
    background: #e5f0fa;
    color: var(--primary);
}

/* Адаптивність */
@media (max-width: 768px) {
    .settings-body {
        padding: 16px;
    }
    
    .form-section {
        padding: 16px;
    }
    
    .btn {
        width: 100%;
    }
    
    .settings-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<script>
tinymce.init({
    selector: 'textarea',
    language_url: '/assets/tinymce/langs/uk.js',
    language: 'uk',
    license_key: 'off',
    height: 400,
    toolbar_mode: 'wrap',
    plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
        'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
        'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount', 'emoticons'
    ],
    toolbar: 'aihelper | undo redo | styles | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table preview code fullscreen | help',
    
    setup: function (editor) {
        editor.ui.registry.addButton('aihelper', {
            text: '🤖 ШІ-помічник',
            onAction: function () {
                const userPrompt = prompt("Введіть запит для генерації (наприклад: згенеруй текст про нашу компанію):");
                if (userPrompt) {
                    const button = document.querySelector('.tox-tbtn[aria-label="🤖 ШІ-помічник"]');
                    const originalText = button ? button.innerHTML : '🤖 ШІ-помічник';
                    
                    if (button) {
                        button.innerHTML = '⏳ Генерація...';
                    }
                    
                    const timeoutId = setTimeout(() => {
                        alert("Запит виконується довше ніж зазвичай. Будь ласка, зачекайте...");
                    }, 10000);
                    
                    fetch('ai_helper.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ prompt: userPrompt })
                    })
                    .then(res => res.text())
                    .then(text => {
                        clearTimeout(timeoutId);
                        console.log("RAW response:", text);
                        
                        try {
                            const data = JSON.parse(text);
                            if (data.text) {
                                editor.insertContent(data.text);
                            } else {
                                alert("Помилка генерації");
                            }
                        } catch (e) {
                            console.error("JSON parse error:", e);
                            alert("Сервер повернув некоректну відповідь. Перевірте ai_helper.php.");
                        }
                    })
                    .catch(error => {
                        clearTimeout(timeoutId);
                        alert("Помилка з'єднання: " + error.message);
                    })
                    .finally(() => {
                        if (button) {
                            button.innerHTML = originalText;
                        }
                    });
                }
            }
        });
    },
    content_style: 'body { font-family:Arial,sans-serif; font-size:14px }'
});

// Попередження перед виходом з незбереженими змінами
let formChanged = false;
const settingsForm = document.getElementById('settingsForm');
if (settingsForm) {
    settingsForm.addEventListener('input', function() {
        formChanged = true;
    });
}

window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Валідація версії (семантичне версіонування)
document.addEventListener('DOMContentLoaded', function() {
    const versionInput = document.getElementById('cms_version');
    if (versionInput) {
        versionInput.addEventListener('blur', function() {
            const value = this.value;
            const versionRegex = /^\d+\.\d+\.\d+$/;
            if (value && !versionRegex.test(value)) {
                this.classList.add('is-invalid');
                const feedback = this.nextElementSibling;
                if (feedback && feedback.classList.contains('invalid-feedback')) {
                    feedback.style.display = 'block';
                }
            } else {
                this.classList.remove('is-invalid');
                const feedback = this.nextElementSibling;
                if (feedback && feedback.classList.contains('invalid-feedback')) {
                    feedback.style.display = 'none';
                }
            }
        });
    }
});
</script>

<div class="settings-container">
    <!-- Картка налаштувань -->
    <div class="settings-card">
        <div class="settings-header">
            <h1>
                <i class="bi bi-gear"></i>
                Налаштування даних CMS
            </h1>
            <span class="badge">
                <i class="bi bi-shield-lock"></i>
                Тільки для адміністратора
            </span>
        </div>

        <div class="settings-body">
            <!-- Повідомлення про успіх -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i>
                    ✅ Зміни успішно збережено!
                </div>
            <?php endif; ?>

            <!-- Форма налаштувань -->
            <form method="post" id="settingsForm">
                <!-- Основна інформація -->
                <div class="form-section">
                    <h3>
                        <i class="bi bi-info-circle"></i>
                        Основна інформація
                    </h3>
                    
                    <div class="mb-4">
                        <label for="site_author" class="form-label">
                            <i class="bi bi-person"></i>
                            Автор сайту
                        </label>
                        <input type="text" 
                               id="site_author" 
                               name="site_author" 
                               class="form-control form-control-lg" 
                               value="<?php echo htmlspecialchars($settings['site_author'] ?? ''); ?>" 
                               placeholder="Ім'я автора або назва компанії">
                        <div class="form-text">
                            Відображатиметься в footer сайту та в мета-даних
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="cms_name" class="form-label">
                            <i class="bi bi-box"></i>
                            Назва CMS
                        </label>
                        <input type="text" 
                               id="cms_name" 
                               name="cms_name" 
                               class="form-control form-control-lg" 
                               value="<?php echo htmlspecialchars($settings['cms_name'] ?? ''); ?>" 
                               placeholder="Назва вашої системи керування">
                        <div class="form-text">
                            Назва, що відображається в адмін-панелі
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="cms_version" class="form-label">
                            <i class="bi bi-tag"></i>
                            Версія CMS
                        </label>
                        <input type="text" 
                               id="cms_version" 
                               name="cms_version" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($settings['cms_version'] ?? ''); ?>" 
                               placeholder="1.0.0">
                        <div class="invalid-feedback">
                            Будь ласка, використовуйте формат семантичного версіонування (напр. 1.0.0)
                        </div>
                        <div class="form-text">
                            Рекомендований формат: MAJOR.MINOR.PATCH (напр. 1.0.0)
                        </div>
                    </div>
                </div>

                <!-- Історія змін -->
                <div class="form-section">
                    <h3>
                        <i class="bi bi-clock-history"></i>
                        Історія змін (Changelog)
                    </h3>
                    
                    <div class="mb-3">
                        <label for="cms_changelog" class="form-label">
                            <i class="bi bi-list-ul"></i>
                            Опис змін
                        </label>
                        <textarea id="cms_changelog" 
                                  name="cms_changelog" 
                                  class="form-control" 
                                  rows="6" 
                                  placeholder="## Версія 1.0.0 (2024-01-01)

- Додано новий функціонал...
- Виправлено помилки...
- Оновлено залежності..."><?php echo htmlspecialchars($settings['cms_changelog'] ?? ''); ?></textarea>
                        <div class="form-text">
                            Використовуйте Markdown для форматування
                        </div>
                    </div>
                </div>

                <!-- Кнопки -->
                <div class="d-flex gap-3 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg"></i>
                        Зберегти зміни
                    </button>
                    <button type="reset" class="btn btn-outline-secondary btn-lg" onclick="return confirm('Скасувати всі зміни?')">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Скинути
                    </button>
                </div>
            </form>

            <!-- Інформаційна панель -->
            <div class="info-panel">
                <h6 class="mb-3">
                    <i class="bi bi-info-circle me-2 text-primary"></i>
                    Інформація про поточну версію
                </h6>
                <div class="info-panel-item">
                    <span class="info-panel-label">
                        <i class="bi bi-calendar"></i>
                        Останнє оновлення:
                    </span>
                    <span class="info-panel-value">
                        <?php echo date('d.m.Y H:i'); ?>
                    </span>
                </div>
                <div class="info-panel-item">
                    <span class="info-panel-label">
                        <i class="bi bi-person"></i>
                        Ким оновлено:
                    </span>
                    <span class="info-panel-value">
                        <?php echo htmlspecialchars($username); ?>
                    </span>
                </div>
                <div class="info-panel-item">
                    <span class="info-panel-label">
                        <i class="bi bi-box"></i>
                        Назва CMS:
                    </span>
                    <span class="info-panel-value">
                        <?php echo htmlspecialchars($settings['cms_name'] ?? '—'); ?>
                    </span>
                </div>
                <div class="info-panel-item">
                    <span class="info-panel-label">
                        <i class="bi bi-tag"></i>
                        Версія:
                    </span>
                    <span class="info-panel-value">
                        <?php echo htmlspecialchars($settings['cms_version'] ?? '—'); ?>
                    </span>
                </div>
            </div>

            <!-- Довідка з Markdown -->
            <div class="mt-4 small text-muted">
                <details>
                    <summary class="fw-bold">
                        <i class="bi bi-question-circle me-1"></i>
                        Довідка по Markdown
                    </summary>
                    <div class="mt-2 p-3 bg-light rounded">
                        <ul class="list-unstyled">
                            <li><code># Заголовок 1</code> - найбільший заголовок</li>
                            <li><code>## Заголовок 2</code> - заголовок другого рівня</li>
                            <li><code>### Заголовок 3</code> - заголовок третього рівня</li>
                            <li><code>- пункт</code> - маркований список</li>
                            <li><code>1. пункт</code> - нумерований список</li>
                            <li><code>**жирний**</code> - жирний текст</li>
                            <li><code>*курсив*</code> - курсив</li>
                            <li><code>[текст](url)</code> - гіперпосилання</li>
                            <li><code>`код`</code> - виділення коду</li>
                        </ul>
                    </div>
                </details>
            </div>
        </div>
    </div>
</div>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>