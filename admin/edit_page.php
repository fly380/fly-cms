<?php
// admin/edit_page.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

$username = $_SESSION['username'] ?? 'невідомо';
$pageSlug = $_GET['page'] ?? 'main';

$pdo = connectToDatabase();

$isLocked = false;
$lockedBy = '';
$message = '';
$messageType = '';

// Обробка блокування сторінки (тільки для сторінок, які не є головною)
$lockFile = null;
if ($pageSlug !== 'main') {
    $lockDir = __DIR__ . '/../data/locks/';
    if (!is_dir($lockDir)) mkdir($lockDir, 0777, true);
    
    $lockFile = $lockDir . $pageSlug . '.lock';
    if (file_exists($lockFile)) {
        $lockedBy = trim(file_get_contents($lockFile));
        if ($lockedBy !== $username) {
            $isLocked = true;
        }
    } else {
        file_put_contents($lockFile, $username);
        log_action("🔒 Заблокував сторінку '$pageSlug'", $username);
    }
}

// Обробка POST-запиту збереження
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    $title = $_POST['title'] ?? '';
    $content = $_POST['content'] ?? '';
    // custom_css/js — тільки admin може змінювати (захист від XSS для ролі redaktor)
    $current_css = $pageData['custom_css'] ?? '';
    $current_js  = $pageData['custom_js']  ?? '';
    $custom_css  = (in_array($_SESSION['role'], ['admin','superadmin'])) ? ($_POST['custom_css'] ?? '') : $current_css;
    $custom_js   = (in_array($_SESSION['role'], ['admin','superadmin'])) ? ($_POST['custom_js']  ?? '') : $current_js;

    if ($pageSlug === 'main') {
        // Збереження головної сторінки
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM main_page WHERE id = 1");
            $exists = $stmt->fetchColumn() > 0;

            if ($exists) {
                $stmt = $pdo->prepare("UPDATE main_page SET title = :title, content = :content WHERE id = 1");
            } else {
                $stmt = $pdo->prepare("INSERT INTO main_page (id, title, content) VALUES (1, :title, :content)");
            }
            $stmt->execute([':title' => $title, ':content' => $content]);
            
            $message = "✅ Зміни на головній сторінці успішно збережено!";
            $messageType = 'success';
            log_action("📝 Оновив головну сторінку", $username);
        } catch (Exception $e) {
            $message = "❌ Помилка збереження: " . $e->getMessage();
            $messageType = 'danger';
        }

    } else {
        // Збереження звичайної сторінки
        try {
            $draft = isset($_POST['draft']) ? 1 : 0;
            $visibility = in_array($_POST['visibility'] ?? '', ['public', 'private']) ? $_POST['visibility'] : 'public';

            $stmt = $pdo->prepare("UPDATE pages SET title = :title, content = :content, draft = :draft, visibility = :visibility, custom_css = :custom_css, custom_js = :custom_js WHERE slug = :slug");
            $stmt->execute([
                ':title' => $title,
                ':content' => $content,
                ':draft' => $draft,
                ':visibility' => $visibility,
                ':custom_css' => $custom_css,
                ':custom_js' => $custom_js,
                ':slug' => $pageSlug
            ]);
            
            $message = "✅ Зміни на сторінці успішно збережено!";
            $messageType = 'success';
            log_action("📝 Оновив сторінку '$pageSlug'", $username);

            // Розблокування сторінки
            if ($lockFile && file_exists($lockFile)) {
                unlink($lockFile);
                log_action("🔓 Розблокував сторінку '$pageSlug'", $username);
            }
        } catch (Exception $e) {
            $message = "❌ Помилка збереження: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Отримання даних сторінки для форми
if ($pageSlug === 'main') {
    $stmt = $pdo->query("SELECT * FROM main_page WHERE id = 1");
    $pageData = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'content' => ''];
} else {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug");
    $stmt->execute([':slug' => $pageSlug]);
    $pageData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Починаємо буферизацію виводу
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
    background: #f0f0f1;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

/* Основний контейнер */
.edit-container {
    max-width: 1400px;
    margin: 30px auto;
    padding: 0 20px;
}

/* Картка редагування */
.edit-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.edit-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #dcdcde;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.edit-header h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: #1d2327;
    display: flex;
    align-items: center;
    gap: 10px;
}

.edit-header h1 i {
    color: var(--primary);
    font-size: 24px;
}

.edit-header .page-info {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.edit-header .badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.edit-body {
    padding: 24px;
}

/* Сітка для основного контенту */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 24px;
}

.content-main {
    min-width: 0;
}

.content-sidebar {
    min-width: 0;
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

.form-control, .form-select {
    border: 1px solid #dcdcde;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    transition: all 0.2s;
    background: #fff;
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
    outline: none;
}

/* Стилі для блокування */
.locked-overlay {
    position: relative;
}

.locked-banner {
    background: #fcf9e8;
    border: 1px solid var(--warning);
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 15px;
    color: #614d05;
}

.locked-banner i {
    font-size: 24px;
    color: var(--warning);
}

.locked-banner strong {
    font-weight: 600;
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

.btn-success {
    background: var(--success);
    color: white;
    border-color: var(--success);
}

.btn-success:hover {
    background: #008a20;
    border-color: #008a20;
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

/* Стилі для перемикачів */
.toggle-switch {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8f9fa;
    border: 1px solid #dcdcde;
    border-radius: 30px;
    padding: 4px;
}

.toggle-option {
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    flex: 1;
    text-align: center;
}

.toggle-option.active {
    background: var(--primary);
    color: white;
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

.status-badge.published {
    background: #edfaef;
    color: #00450c;
}

.status-badge.draft {
    background: #fcf9e8;
    color: #614d05;
}

.status-badge.private {
    background: #f0f0f1;
    color: #2c3338;
}

.status-badge.locked {
    background: #fcf9e8;
    color: var(--warning);
}

/* Стилі для CSS/JS полів */
.code-field {
    font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.5;
    background: #1d2327;
    color: #e5e5e5;
    border-radius: 6px;
    padding: 15px;
}

.code-field:focus {
    background: #1d2327;
    color: #fff;
}

/* Стилі для інформаційної панелі */
.info-panel {
    background: #f8f9fa;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 16px;
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

.alert-danger {
    background: #fcf0f1;
    color: #8a1f1f;
    border-color: #f2b6bb;
}

.alert-warning {
    background: #fcf9e8;
    color: #614d05;
    border-color: #f2e9b6;
}

.alert i {
    font-size: 20px;
}

/* Адаптивність */
@media (max-width: 992px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    
    .edit-header {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 768px) {
    .edit-body {
        padding: 16px;
    }
    
    .form-section {
        padding: 16px;
    }
    
    .btn {
        width: 100%;
    }
}
</style>

<script>
// ── Завантаження зображення в TinyMCE 7 (drag & drop / вставка) ──
const tinymceUploadHandler = function(blobInfo, progress) {
    return new Promise(function(resolve, reject) {
        const fd = new FormData();
        fd.append('file', blobInfo.blob(), blobInfo.filename());

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/upload_image.php');
        xhr.withCredentials = true;

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                progress(Math.round(e.loaded / e.total * 100));
            }
        };

        xhr.onload = function() {
            console.log('[TinyMCE upload] status:', xhr.status, 'response:', xhr.responseText);
            if (xhr.status < 200 || xhr.status >= 300) {
                reject('HTTP ' + xhr.status + ': ' + xhr.responseText.substring(0, 200));
                return;
            }
            let json;
            try {
                json = JSON.parse(xhr.responseText);
            } catch(e) {
                reject('Не JSON: ' + xhr.responseText.substring(0, 200));
                return;
            }
            if (json && json.location) {
                resolve(json.location);
            } else {
                reject(json.error || 'Немає поля location');
            }
        };

        xhr.onerror = function() {
            reject('Мережева помилка');
        };

        xhr.send(fd);
    });
};

tinymce.init({
    selector: 'textarea:not(.no-editor)',
    language_url: '/assets/tinymce/langs/uk.js',
    language: 'uk',
    license_key: 'off',
    height: 700,
    toolbar_mode: 'wrap',
    plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
        'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
        'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount', 'emoticons'
    ],
    toolbar: 'aihelper | undo redo | styles | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table preview code fullscreen | help',

    images_upload_handler: tinymceUploadHandler,
    automatic_uploads: true,
    paste_data_images: true,
    images_reuse_filename: false,

    file_picker_callback: function(callback, value, meta) {
        if (meta.filetype === 'image') {
            const win = window.open('/admin/media_picker.php?callback=tinymceImageCallback', 'media', 'width=800,height=600');
            window.tinymceImageCallback = callback;
        }
    },

    setup: function (editor) {
        editor.ui.registry.addButton('aihelper', {
            text: '🤖 ШІ-помічник',
            onAction: function () {
                const userPrompt = prompt("Введіть запит для генерації:");
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
                        console.log('Відповідь від сервера:', text);
                        
                        try {
                            const data = JSON.parse(text);
                            if (data.text) {
                                editor.insertContent(data.text);
                            } else if (data.error) {
                                alert("Помилка: " + data.error + (data.details ? "\n\nДеталі: " + data.details : ""));
                            } else {
                                alert("Помилка генерації: невідома відповідь");
                            }
                        } catch (e) {
                            alert("Сервер повернув некоректну відповідь. Перевірте консоль (F12)");
                            console.error("Помилка парсингу JSON:", text);
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

        // Додаємо кнопку для обгортання таблиць
        editor.ui.registry.addButton('wraptables', {
            text: '📊 Обгорнути таблиці',
            onAction: function () {
                wrapTables();
            }
        });
    },
    content_style: 'body { font-family:Arial,sans-serif; font-size:14px }'
});

function wrapTables() {
    const editor = tinymce.get('editor');
    const wrapper = document.createElement('div');
    wrapper.innerHTML = editor.getContent();

    wrapper.querySelectorAll('table').forEach(function(table) {
        const parent = table.parentElement;
        if (!parent || !parent.classList.contains('table-responsive')) {
            const div = document.createElement('div');
            div.className = 'table-responsive';
            table.parentNode.insertBefore(div, table);
            div.appendChild(table);
        }
    });

    editor.setContent(wrapper.innerHTML);
}

document.addEventListener("DOMContentLoaded", function () {
    const toggleCss = document.getElementById('toggleCss');
    const cssField = document.getElementById('cssField');
    const toggleJs = document.getElementById('toggleJs');
    const jsField = document.getElementById('jsField');

    // Автовідображення, якщо вже є збережені значення
    const cssValue = document.querySelector('[name="custom_css"]')?.value.trim() || '';
    const jsValue = document.querySelector('[name="custom_js"]')?.value.trim() || '';
    
    if (cssValue !== "") {
        if (toggleCss) {
            toggleCss.checked = true;
            cssField?.classList.remove('d-none');
        }
    }
    if (jsValue !== "") {
        if (toggleJs) {
            toggleJs.checked = true;
            jsField?.classList.remove('d-none');
        }
    }

    if (toggleCss) {
        toggleCss.addEventListener('change', function () {
            cssField?.classList.toggle('d-none', !this.checked);
        });
    }

    if (toggleJs) {
        toggleJs.addEventListener('change', function () {
            jsField?.classList.toggle('d-none', !this.checked);
        });
    }
});

// Функція для зміни статусу
function setStatus(status) {
    const draftInput = document.getElementById('draftInput');
    const toggleOptions = document.querySelectorAll('.toggle-option');
    
    if (draftInput) {
        if (status === 'published') {
            draftInput.value = '0';
            toggleOptions[0]?.classList.add('active');
            toggleOptions[1]?.classList.remove('active');
        } else {
            draftInput.value = '1';
            toggleOptions[0]?.classList.remove('active');
            toggleOptions[1]?.classList.add('active');
        }
    }
}

// Попередження перед виходом з незбереженими змінами
let formChanged = false;
const editForm = document.getElementById('editForm');
if (editForm) {
    editForm.addEventListener('input', function() {
        formChanged = true;
    });
}

window.addEventListener('beforeunload', function(e) {
    if (formChanged && !<?php echo $isLocked ? 'true' : 'false'; ?>) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<script>
// ── Drag & Drop зона — завантаження з прогрес-баром ──────────────
document.addEventListener('DOMContentLoaded', function () {
    const zone      = document.getElementById('editorDropZone');
    const fileInput = document.getElementById('editorFileInput');
    const progWrap  = document.getElementById('uploadProgressWrap');
    const progBar   = document.getElementById('uploadProgressBar');
    const progPct   = document.getElementById('uploadProgressPct');
    const progLabel = document.getElementById('uploadProgressLabel');

    if (!zone) return;

    ['dragenter', 'dragover'].forEach(ev => {
        zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(ev => {
        zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('drag-over'); });
    });

    zone.addEventListener('drop', e => {
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
        if (files.length) uploadFiles(files);
    });

    zone.addEventListener('click', e => {
        if (e.target.tagName !== 'LABEL') fileInput.click();
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) uploadFiles(Array.from(fileInput.files));
        fileInput.value = '';
    });

    function uploadFiles(files) {
        let done = 0;
        const total = files.length;
        zone.classList.add('uploading');
        progWrap.style.display = 'block';
        setProgress(0, `Завантаження 0 / ${total}...`);

        function uploadNext(idx) {
            if (idx >= total) {
                zone.classList.remove('uploading');
                setProgress(100, `✓ Завантажено ${total} файл(ів)`);
                setTimeout(() => { progWrap.style.display = 'none'; }, 2500);
                return;
            }
            uploadOne(files[idx], idx, total, function (url) {
                done++;
                setProgress(Math.round(done / total * 100), `Завантаження ${done} / ${total}...`);
                if (url && typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                    tinymce.activeEditor.insertContent(
                        `<img src="${url}" alt="" style="max-width:100%">`
                    );
                }
                uploadNext(idx + 1);
            });
        }
        uploadNext(0);
    }

    function uploadOne(file, idx, total, cb) {
        const fd = new FormData();
        fd.append('file', file, file.name);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/upload_image.php');

        xhr.upload.addEventListener('progress', e => {
            if (!e.lengthComputable) return;
            const p = (idx / total + (e.loaded / e.total) / total) * 100;
            setProgress(Math.round(p), `Завантаження ${idx + 1} / ${total}...`);
        });

        xhr.addEventListener('load', () => {
            let json = null;
            try { json = JSON.parse(xhr.responseText); } catch (_) {}
            if (json && json.location) { cb(json.location); }
            else { showUploadError(file.name, json?.error || 'Помилка'); cb(null); }
        });

        xhr.addEventListener('error', () => { showUploadError(file.name, 'Мережева помилка'); cb(null); });
        xhr.send(fd);
    }

    function setProgress(pct, label) {
        progBar.style.width   = pct + '%';
        progPct.textContent   = pct + '%';
        progLabel.textContent = label;
    }

    function showUploadError(name, msg) {
        const a = document.createElement('div');
        a.className = 'alert alert-danger alert-dismissible py-2 mt-2';
        a.innerHTML = `<i class="bi bi-exclamation-circle me-1"></i><strong>${name}</strong>: ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        zone.after(a);
    }
});
</script>

<style>
.editor-drop-zone {
    border: 2px dashed #adb5bd;
    border-radius: 10px;
    padding: 18px 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: #f8f9fa;
    margin-bottom: 10px;
    user-select: none;
}
.editor-drop-zone.drag-over { border-color: #0d6efd; background: #e8f0fe; }
.editor-drop-zone.uploading { opacity: .7; pointer-events: none; }
.editor-drop-inner { display:flex; flex-direction:column; align-items:center; gap:4px; color:#6c757d; }
.editor-drop-inner i { color: #0d6efd; }
.drop-link { color: #0d6efd; cursor: pointer; text-decoration: underline; }
</style>

<div class="edit-container">
    <!-- Картка редагування -->
    <div class="edit-card">
        <div class="edit-header">
            <h1>
                <i class="bi bi-file-text"></i>
                <?php echo $pageSlug === 'main' ? 'Редагування головної сторінки' : 'Редагування сторінки'; ?>
            </h1>
            <div class="page-info">
                <?php if ($pageSlug !== 'main'): ?>
                    <span class="badge bg-light">
                        <i class="bi bi-link me-1"></i>
                        /<?php echo htmlspecialchars($pageSlug); ?>
                    </span>
                <?php endif; ?>
                
                <?php if ($pageSlug !== 'main'): ?>
                    <?php if (!empty($pageData['draft'])): ?>
                        <span class="status-badge draft">
                            <i class="bi bi-pencil"></i> Чернетка
                        </span>
                    <?php else: ?>
                        <span class="status-badge published">
                            <i class="bi bi-check-circle"></i> Опубліковано
                        </span>
                    <?php endif; ?>
                    
                    <?php if (isset($pageData['visibility']) && $pageData['visibility'] === 'private'): ?>
                        <span class="status-badge private">
                            <i class="bi bi-lock"></i> Приватна
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($isLocked): ?>
                    <span class="status-badge locked">
                        <i class="bi bi-lock"></i> Заблоковано
                    </span>
                <?php endif; ?>
                
                <a href="<?php echo $pageSlug === 'main' ? '/' : '/' . urlencode($pageSlug); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-eye"></i> Переглянути
                </a>
            </div>
        </div>

        <div class="edit-body">
            <!-- Повідомлення -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Банер блокування -->
            <?php if ($isLocked): ?>
                <div class="locked-banner">
                    <i class="bi bi-lock"></i>
                    <div>
                        <strong>Сторінку зараз редагує користувач <?php echo htmlspecialchars($lockedBy); ?></strong>
                        <p class="mb-0 small">Ви не можете редагувати цю сторінку, поки він не завершить роботу.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Форма редагування -->
            <form method="POST" id="editForm" onsubmit="wrapTables();" <?php echo $isLocked ? 'style="pointer-events:none;opacity:0.6;"' : ''; ?>>
                <div class="content-grid">
                    <!-- Основний контент (ліва колонка) -->
                    <div class="content-main">
                        <!-- Заголовок та контент -->
                        <div class="form-section">
                            <h3>
                                <i class="bi bi-type"></i>
                                Основний контент
                            </h3>
                            
                            <div class="mb-4">
                                <label class="form-label">
                                    <i class="bi bi-pencil"></i>
                                    Заголовок сторінки
                                </label>
                                <input type="text" 
                                       name="title" 
                                       class="form-control form-control-lg" 
                                       value="<?php echo htmlspecialchars($pageData['title'] ?? ''); ?>" 
                                       placeholder="Введіть заголовок сторінки"
                                       <?php echo $isLocked ? 'disabled' : ''; ?>
                                       <?php echo $pageSlug !== 'main' ? 'required' : ''; ?>>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-file-text"></i>
                                    Контент
                                </label>

                                <!-- ── Drag & Drop зона ── -->
                                <?php if (!$isLocked): ?>
                                <div id="editorDropZone" class="editor-drop-zone" aria-label="Зона drag & drop для зображень">
                                    <div class="editor-drop-inner">
                                        <i class="bi bi-cloud-upload fs-3"></i>
                                        <span>Перетягни зображення сюди або <label for="editorFileInput" class="drop-link">вибери файл</label></span>
                                        <small class="text-muted">JPEG, PNG, WebP, GIF · до 20 MB</small>
                                    </div>
                                    <input type="file" id="editorFileInput" accept="image/*" multiple style="display:none">
                                </div>
                                <div id="uploadProgressWrap" style="display:none" class="mt-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <small id="uploadProgressLabel" class="text-muted">Завантаження...</small>
                                        <small id="uploadProgressPct" class="ms-auto fw-bold">0%</small>
                                    </div>
                                    <div class="progress" style="height:6px">
                                        <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                                             role="progressbar" style="width:0%"></div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <textarea name="content" <?php echo $isLocked ? 'disabled' : ''; ?>><?php echo htmlspecialchars($pageData['content'] ?? ''); ?></textarea>
                                <div class="char-counter mt-2">
                                    <i class="bi bi-info-circle"></i>
                                    Використовуйте TinyMCE для форматування тексту
                                </div>
                            </div>
                        </div>

                        <!-- CSS та JS для адміністратора -->
                        <?php if (in_array($_SESSION['role'], ['admin','superadmin'])): ?>
                        <div class="form-section">
                            <h3>
                                <i class="bi bi-code-square"></i>
                                Додатковий код
                            </h3>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="toggleCss" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="toggleCss">
                                    <i class="bi bi-palette"></i>
                                    Додати користувацький CSS
                                </label>
                            </div>
                            <div class="mb-3 d-none" id="cssField">
                                <label class="form-label">
                                    <i class="bi bi-code"></i>
                                    Користувацький CSS
                                </label>
                                <textarea name="custom_css" 
                                          class="form-control no-editor code-field" 
                                          rows="6" 
                                          placeholder="/* Ваш CSS код */"
                                          <?php echo $isLocked ? 'disabled' : ''; ?>><?php echo htmlspecialchars($pageData['custom_css'] ?? ''); ?></textarea>
                                <small class="text-muted">Буде вставлено в &lt;style&gt; на сторінці</small>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="toggleJs" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="toggleJs">
                                    <i class="bi bi-filetype-js"></i>
                                    Додати користувацький JavaScript
                                </label>
                            </div>
                            <div class="mb-3 d-none" id="jsField">
                                <label class="form-label">
                                    <i class="bi bi-code"></i>
                                    Користувацький JavaScript
                                </label>
                                <textarea name="custom_js" 
                                          class="form-control no-editor code-field" 
                                          rows="6" 
                                          placeholder="// Ваш JavaScript код"
                                          <?php echo $isLocked ? 'disabled' : ''; ?>><?php echo htmlspecialchars($pageData['custom_js'] ?? ''); ?></textarea>
                                <small class="text-muted">Буде вставлено перед &lt;/body&gt;</small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Бічна панель (права колонка) -->
                    <div class="content-sidebar">
                        <?php if ($pageSlug !== 'main'): ?>
                            <!-- Налаштування публікації -->
                            <div class="form-section">
                                <h3>
                                    <i class="bi bi-gear"></i>
                                    Налаштування публікації
                                </h3>

                                <div class="mb-4">
                                    <label class="form-label">
                                        <i class="bi bi-eye"></i>
                                        Видимість
                                    </label>
                                    <select name="visibility" class="form-select" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                        <option value="public" <?php echo (isset($pageData['visibility']) && $pageData['visibility'] === 'public') ? 'selected' : ''; ?>>🌍 Для всіх</option>
                                        <option value="private" <?php echo (isset($pageData['visibility']) && $pageData['visibility'] === 'private') ? 'selected' : ''; ?>>🔒 Тільки для авторизованих</option>
                                    </select>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">
                                        <i class="bi bi-toggle-on"></i>
                                        Статус
                                    </label>
                                    <div class="toggle-switch">
                                        <div class="toggle-option <?php echo empty($pageData['draft']) ? 'active' : ''; ?>" onclick="setStatus('published')">
                                            <i class="bi bi-check-circle"></i> Опубліковано
                                        </div>
                                        <div class="toggle-option <?php echo !empty($pageData['draft']) ? 'active' : ''; ?>" onclick="setStatus('draft')">
                                            <i class="bi bi-pencil"></i> Чернетка
                                        </div>
                                    </div>
                                    <input type="hidden" name="draft" id="draftInput" value="<?php echo !empty($pageData['draft']) ? '1' : '0'; ?>">
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Інформація про сторінку -->
                        <div class="info-panel">
                            <h6 class="mb-3">
                                <i class="bi bi-info-circle me-2 text-primary"></i>
                                Інформація
                            </h6>
                            <?php if ($pageSlug !== 'main'): ?>
                                <div class="info-panel-item">
                                    <span class="info-panel-label">
                                        <i class="bi bi-link"></i>
                                        Slug:
                                    </span>
                                    <span class="info-panel-value">
                                        <?php echo htmlspecialchars($pageSlug); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="info-panel-item">
                                <span class="info-panel-label">
                                    <i class="bi bi-calendar"></i>
                                    Створено:
                                </span>
                                <span class="info-panel-value">
                                    <?php echo isset($pageData['created_at']) ? date('d.m.Y H:i', strtotime($pageData['created_at'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="info-panel-item">
                                <span class="info-panel-label">
                                    <i class="bi bi-clock"></i>
                                    Оновлено:
                                </span>
                                <span class="info-panel-value">
                                    <?php echo isset($pageData['updated_at']) ? date('d.m.Y H:i', strtotime($pageData['updated_at'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="info-panel-item">
                                <span class="info-panel-label">
                                    <i class="bi bi-person"></i>
                                    Автор:
                                </span>
                                <span class="info-panel-value">
                                    <?php echo htmlspecialchars($pageData['author'] ?? $username); ?>
                                </span>
                            </div>
                            <?php if ($pageSlug === 'main'): ?>
                                <div class="info-panel-item">
                                    <span class="info-panel-label">
                                        <i class="bi bi-star"></i>
                                        Тип:
                                    </span>
                                    <span class="info-panel-value">
                                        Головна сторінка
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Кнопки збереження -->
                <div class="d-flex gap-3 mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary btn-lg" <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <i class="bi bi-check-lg"></i>
                        Зберегти зміни
                    </button>
                    <a href="create_page.php" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-x-lg"></i>
                        Скасувати
                    </a>
                    <?php if ($pageSlug !== 'main' && !$isLocked): ?>
                        <button type="button" class="btn btn-outline-danger btn-lg ms-auto" onclick="if(confirm('Ви впевнені, що хочете розблокувати сторінку?')) window.location.href='unlock_page.php?page=<?php echo urlencode($pageSlug); ?>'">
                            <i class="bi bi-unlock"></i>
                            Розблокувати
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>