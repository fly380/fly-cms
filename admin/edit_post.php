<?php
// admin/edit_post.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ── Виправлення upload_tmp_dir для Windows/IIS ──────────────────────────────
(function () {
    $tmp = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    if (is_dir($tmp) && is_writable($tmp)) ini_set('upload_tmp_dir', $tmp);
})();
// ────────────────────────────────────────────────────────────────────────────

session_start();
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

// ── Вбудований API для ревізій ────────────────────────────────────────
if (isset($_GET['revision_id'])) {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $revId = (int)$_GET['revision_id'];
    if (!$revId) { echo json_encode(['ok'=>false,'error'=>'Невірний ID']); exit; }
    $pdo = connectToDatabase();
    $rev = $pdo->prepare("SELECT * FROM post_revisions WHERE id = ?");
    $rev->execute([$revId]);
    $row = $rev->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Ревізію не знайдено']); exit; }
    echo json_encode(['ok'=>true,'title'=>$row['title'],'content'=>$row['content'],'saved_by'=>$row['saved_by'],'saved_at'=>$row['saved_at']]);
    exit;
}

$username = $_SESSION['username'] ?? 'невідомо';
$postSlug = $_GET['post'] ?? '';
if (!$postSlug) {
    die("❌ Не вказано пост.");
}

$pdo = connectToDatabase();
$message = '';
$messageType = '';

// Отримання існуючого поста
$stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = :slug");
$stmt->execute([':slug' => $postSlug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    die("❌ Пост не знайдено.");
}

// ── Автопублікація запланованих записів ──────────────────────────────
require_once __DIR__ . '/../data/publish_scheduler.php';
run_publish_scheduler($pdo);

// ── Завантаження ревізій ─────────────────────────────────────────────
$revisions = [];
try {
    $revStmt = $pdo->prepare("SELECT id, saved_by, saved_at FROM post_revisions WHERE post_id = ? ORDER BY saved_at DESC LIMIT 20");
    $revStmt->execute([$post['id']]);
    $revisions = $revStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $content = $_POST['content'] ?? '';
    
    // ========== ВАЖЛИВО ==========
    // Змінна $draft отримує значення з прихованого поля draftInput
    // А НЕ з чекбокса безпосередньо
    $draft = isset($_POST['draft']) ? intval($_POST['draft']) : 0;
    // =============================
    
    $visibility = in_array($_POST['visibility'] ?? '', ['public', 'private']) ? $_POST['visibility'] : 'public';
    $show_on_main = isset($_POST['show_on_main']) ? 1 : 0;
    $meta_title = $_POST['meta_title'] ?? '';
    $meta_description = $_POST['meta_description'] ?? '';
    $meta_keywords = $_POST['meta_keywords'] ?? '';

    $thumbnailPath = $post['thumbnail'] ?? '';
    $oldThumbnailPath = $post['thumbnail'] ?? '';

    // Функція для безпечного видалення файлу
    function safeDeleteFile($filePath) {
        if (empty($filePath)) return false;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $filePath;
        if (file_exists($fullPath) && is_file($fullPath) && strpos($fullPath, '/uploads/') !== false) {
            return unlink($fullPath);
        }
        return false;
    }

    // Видалення мініатюри
    if (isset($_POST['remove_thumbnail']) && $_POST['remove_thumbnail'] === '1') {
        if (!empty($oldThumbnailPath)) {
            safeDeleteFile($oldThumbnailPath);
        }
        $thumbnailPath = '';
    }
    // Вибір із медіатеки (через media_picker.php)
    elseif (!empty($_POST['thumbnail_url'])) {
        $thumbnailPath = $_POST['thumbnail_url'];
    }

    // ── Планування публікації ────────────────────────────────────────
    $publish_at = null;
    $rawPublishAt = trim($_POST['publish_at'] ?? '');

    if (!empty($rawPublishAt)) {
        // datetime-local повертає "2025-01-15T14:30" без секунд
        $ts = strtotime($rawPublishAt);
        if ($ts !== false && $ts > 0) {
            $publish_at = date('Y-m-d H:i:s', $ts);
            // Якщо час ще в майбутньому — примусово чернетка
            // $draft НЕ залежить від форми, виставляємо самі
            $draft = 1;
        }
    }
    // Якщо publish_at порожнє — $draft береться з форми як є (рядок вище)

    // ── Зберігаємо ревізію перед оновленням ─────────────────────────
    $hasRevTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='post_revisions'")->fetchColumn();
    if ($hasRevTable) {
        // Зберігаємо тільки якщо контент реально змінився
        if ($title !== $post['title'] || $content !== $post['content']) {
            $pdo->prepare("INSERT INTO post_revisions (post_id, title, content, saved_by) VALUES (?, ?, ?, ?)")
                ->execute([$post['id'], $post['title'], $post['content'], $username]);
            // Тримаємо максимум 20 ревізій на запис
            $pdo->prepare("DELETE FROM post_revisions WHERE post_id = ? AND id NOT IN (
                SELECT id FROM post_revisions WHERE post_id = ? ORDER BY saved_at DESC LIMIT 20
            )")->execute([$post['id'], $post['id']]);
        }
    }

    // ── Оновлення запису ─────────────────────────────────────────────
    $stmt = $pdo->prepare("UPDATE posts SET 
        title = :title,
        content = :content,
        draft = :draft,
        visibility = :visibility,
        show_on_main = :show_on_main,
        meta_title = :meta_title,
        meta_description = :meta_description,
        meta_keywords = :meta_keywords,
        thumbnail = :thumbnail,
        publish_at = :publish_at,
        updated_at = datetime('now')
        WHERE slug = :slug");

    $result = $stmt->execute([
        ':title' => $title,
        ':content' => $content,
        ':draft' => $draft,
        ':visibility' => $visibility,
        ':show_on_main' => $show_on_main,
        ':meta_title' => $meta_title,
        ':meta_description' => $meta_description,
        ':meta_keywords' => $meta_keywords,
        ':thumbnail' => $thumbnailPath,
        ':publish_at' => $publish_at,
        ':slug' => $postSlug
    ]);

    if ($result) {
        if ($publish_at && $draft) {
            $message = "⏰ Заплановано публікацію на " . date('d.m.Y H:i', strtotime($publish_at));
        } else {
            $message = "✅ Запис успішно оновлено! Статус: " . ($draft ? 'Чернетка' : 'Опубліковано');
        }
        $messageType = 'success';
        log_action("📝 Оновив пост '$postSlug'" . ($publish_at ? " (заплановано на $publish_at)" : ""), $username);

        if (($_POST['save_action'] ?? '') === 'save_and_close') {
            header('Location: /admin/create_post.php');
            exit;
        }
    } else {
        $errorInfo = $stmt->errorInfo();
        $message = "❌ Помилка оновлення: " . ($errorInfo[2] ?? 'Невідома помилка');
        $messageType = 'danger';
    }
    
    // Оновлюємо дані поста після збереження
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = :slug");
    $stmt->execute([':slug' => $postSlug]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<?php ob_start(); ?>
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

.edit-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

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

.edit-header .post-info {
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

.content-grid {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 24px;
}

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
}

.form-label {
    font-weight: 500;
    color: #1d2327;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-control, .form-select {
    border: 1px solid #dcdcde;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    background: #fff;
}

.thumbnail-preview {
    background: #f8f9fa;
    border: 2px dashed #dcdcde;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    margin-bottom: 15px;
    transition: border-color 0.2s;
}

.thumbnail-preview img {
    max-width: 100%;
    max-height: 200px;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 10px;
    object-fit: cover;
    width: 100%;
}

.btn {
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-primary:hover {
    background: var(--primary-hover);
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

.btn-outline-danger {
    background: transparent;
    color: var(--danger);
    border-color: var(--danger);
}

.btn-outline-danger:hover {
    background: var(--danger);
    color: white;
}

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

@media (max-width: 992px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

/* ── Drag & Drop зона ────────────────────────────────────────────── */
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
.editor-drop-zone.drag-over {
    border-color: #0d6efd;
    background: #e8f0fe;
}
.editor-drop-zone.uploading {
    opacity: .7;
    pointer-events: none;
}
.editor-drop-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    color: #6c757d;
}
.editor-drop-inner i { color: #0d6efd; }
.drop-link {
    color: #0d6efd;
    cursor: pointer;
    text-decoration: underline;
}
</style>

<script>
// ── Завантаження зображення в TinyMCE 7 (drag & drop / вставка) ──
// TinyMCE 7: handler(blobInfo, progress) де progress(число 0-100)
const tinymceUploadHandler = function(blobInfo, progress) {
    return new Promise(function(resolve, reject) {
        const fd = new FormData();
        fd.append('file', blobInfo.blob(), blobInfo.filename());

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/upload_image.php');
        xhr.withCredentials = true; // передаємо cookie сесії

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

    // ── Drag & drop / вставка зображень ───────────────────────────
    images_upload_handler: tinymceUploadHandler,
    automatic_uploads: true,
    paste_data_images: true,
    images_reuse_filename: false,

    file_picker_callback: function(callback, value, meta) {
        if (meta.filetype === 'image') {
            const win = window.open('/admin/media_picker.php?callback=tinymceImageCallback', 'media', 'width=800,height=600');
            window.tinymceImageCallback = callback;
        }
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

    // Drag events
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

    // Клік по зоні (крім label — у нього свій handler)
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

        // Послідовно, щоб не перевантажувати сервер
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
                // Вставляємо в TinyMCE
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
            const fileProgress = (idx / total + (e.loaded / e.total) / total) * 100;
            setProgress(Math.round(fileProgress), `Завантаження ${idx + 1} / ${total}...`);
        });

        xhr.addEventListener('load', () => {
            console.log('[dropzone upload] status:', xhr.status, 'response:', xhr.responseText);
            let json = null;
            try { json = JSON.parse(xhr.responseText); } catch (_) {}
            if (json && json.location) {
                cb(json.location);
            } else {
                const err = (json?.error || 'Помилка') + ' | raw: ' + xhr.responseText.substring(0, 300);
                showUploadError(file.name, err);
                cb(null);
            }
        });

        xhr.addEventListener('error', () => { showUploadError(file.name, 'Мережева помилка'); cb(null); });
        xhr.send(fd);
    }

    function setProgress(pct, label) {
        progBar.style.width  = pct + '%';
        progPct.textContent  = pct + '%';
        progLabel.textContent = label;
    }

    function showUploadError(name, msg) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger alert-dismissible py-2 mt-2';
        alert.innerHTML = `<i class="bi bi-exclamation-circle me-1"></i><strong>${name}</strong>: ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        zone.after(alert);
    }
});
</script>

<div class="edit-container">
    <div class="edit-card">
        <div class="edit-header">
            <h1>
                <i class="bi bi-pencil-square"></i>
                Редагування запису
            </h1>
            <div class="post-info">
                <span class="badge bg-light">
                    <i class="bi bi-link me-1"></i>
                    /<?php echo htmlspecialchars($postSlug); ?>
                </span>
                <?php if ($post['draft']): ?>
                    <span class="status-badge draft">
                        <i class="bi bi-pencil"></i> Чернетка
                    </span>
                <?php else: ?>
                    <span class="status-badge published">
                        <i class="bi bi-check-circle"></i> Опубліковано
                    </span>
                <?php endif; ?>
                <a href="/post/<?php echo urlencode($postSlug); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-eye"></i> Переглянути
                </a>
                <span id="autosaveStatus" class="text-muted small ms-2"></span>
            </div>
        </div>

        <div class="edit-body">
            <!-- Банер відновлення автозбереження -->
            <div id="autosaveBanner" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin-bottom:16px;align-items:center;gap:12px;flex-wrap:wrap">
                <span>💾 <strong>Знайдено незбережену чернетку</strong> від <span id="autosaveTime"></span></span>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-warning btn-sm" onclick="restoreAutosave()">↩ Відновити</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="dismissAutosave()">✕ Ігнорувати</button>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="postForm">
                <div class="content-grid">
                    <div class="content-main">
                        <div class="form-section">
                            <h3>
                                <i class="bi bi-type"></i>
                                Заголовок та контент
                            </h3>
                            
                            <div class="mb-4">
                                <label class="form-label">Заголовок запису</label>
                                <input type="text" name="title" class="form-control form-control-lg" 
                                       value="<?php echo htmlspecialchars($post['title']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Контент</label>

                                <!-- ── Drag & Drop зона для швидкого завантаження ── -->
                                <div id="editorDropZone" class="editor-drop-zone" aria-label="Зона drag & drop для зображень">
                                    <div class="editor-drop-inner">
                                        <i class="bi bi-cloud-upload fs-3"></i>
                                        <span>Перетягни зображення сюди або <label for="editorFileInput" class="drop-link">вибери файл</label></span>
                                        <small class="text-muted">JPEG, PNG, WebP, GIF · до 20 MB</small>
                                    </div>
                                    <input type="file" id="editorFileInput" accept="image/*" multiple style="display:none">
                                </div>

                                <!-- Прогрес-бар завантаження -->
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

                                <textarea name="content"><?php echo htmlspecialchars($post['content']); ?></textarea>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>
                                <i class="bi bi-search"></i>
                                SEO налаштування
                            </h3>
                            
                            <div class="mb-3">
                                <label class="form-label">Мета-заголовок</label>
                                <input type="text" name="meta_title" class="form-control" 
                                       value="<?php echo htmlspecialchars($post['meta_title'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Мета-опис</label>
                                <textarea name="meta_description" class="form-control no-editor" 
                                          rows="3"><?php echo htmlspecialchars($post['meta_description'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ключові слова</label>
                                <input type="text" name="meta_keywords" class="form-control" 
                                       value="<?php echo htmlspecialchars($post['meta_keywords'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="content-sidebar">
                        <div class="form-section">
                            <h3>
                                <i class="bi bi-gear"></i>
                                Налаштування
                            </h3>

                            <div class="mb-4">
                                <label class="form-label">Видимість</label>
                                <select name="visibility" class="form-select">
                                    <option value="public" <?php echo $post['visibility'] === 'public' ? 'selected' : ''; ?>>🌍 Для всіх</option>
                                    <option value="private" <?php echo $post['visibility'] === 'private' ? 'selected' : ''; ?>>🔒 Тільки для авторизованих</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Статус</label>
                                <div class="toggle-switch">
                                    <div class="toggle-option <?php echo !$post['draft'] ? 'active' : ''; ?>" onclick="setStatus('published')">
                                        <i class="bi bi-check-circle"></i> Опубліковано
                                    </div>
                                    <div class="toggle-option <?php echo $post['draft'] ? 'active' : ''; ?>" onclick="setStatus('draft')">
                                        <i class="bi bi-pencil"></i> Чернетка
                                    </div>
                                </div>
                                <!-- ЦЕ ВАЖЛИВО: саме це поле передає статус -->
                                <input type="hidden" name="draft" id="draftInput" value="<?php echo $post['draft'] ? '1' : '0'; ?>">
                            </div>

                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="show_on_main" id="show_on_main" 
                                           <?php echo $post['show_on_main'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="show_on_main">
                                        Показувати на головній
                                    </label>
                                </div>
                            </div>

                            <!-- Планування публікації -->
                            <div class="mb-4">
                                <label class="form-label">
                                    ⏰ Запланувати публікацію
                                </label>
                                <input type="datetime-local" name="publish_at" id="publishAtInput" class="form-control form-control-sm"
                                       value="<?php echo $post['publish_at'] ? date('Y-m-d\\TH:i', strtotime($post['publish_at'])) : ''; ?>">
                                <?php if (!empty($post['publish_at']) && $post['draft']): ?>
                                    <div class="mt-1 small text-warning">
                                        ⏳ Заплановано на <?= date('d.m.Y H:i', strtotime($post['publish_at'])) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-1 small text-muted">Залиште порожнім для негайної публікації</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Ревізії -->
                        <?php if (!empty($revisions)): ?>
                        <div class="form-section">
                            <h3><i class="bi bi-clock-history"></i> Ревізії <span class="badge bg-secondary"><?= count($revisions) ?></span></h3>
                            <div style="max-height:200px;overflow-y:auto">
                                <?php foreach ($revisions as $rev): ?>
                                <div class="d-flex align-items-center gap-2 py-1 border-bottom" style="font-size:.82rem">
                                    <div class="flex-grow-1">
                                        <span class="text-muted"><?= htmlspecialchars($rev['saved_by']) ?></span>
                                        <div class="text-muted" style="font-size:.75rem"><?= date('d.m H:i', strtotime($rev['saved_at'])) ?></div>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                            onclick="restoreRevision(<?= $rev['id'] ?>)">↩</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Мініатюра -->
                        <div class="form-section">
                            <h3>
                                <i class="bi bi-image"></i>
                                Мініатюра
                            </h3>

                            <!-- Прев'ю з мініатюрою -->
                            <div id="thumbnailPreviewWrap" class="thumbnail-preview <?php echo empty($post['thumbnail']) ? 'd-none' : ''; ?>">
                                <img id="thumbnailPreview"
                                     src="<?php echo htmlspecialchars($post['thumbnail'] ?? ''); ?>"
                                     alt="Мініатюра">
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm w-100"
                                        onclick="removeThumbnail()">
                                    <i class="bi bi-trash"></i> Видалити мініатюру
                                </button>
                            </div>

                            <!-- Заглушка якщо немає мініатюри -->
                            <div id="thumbnailEmpty" class="thumbnail-preview <?php echo !empty($post['thumbnail']) ? 'd-none' : ''; ?>">
                                <i class="bi bi-image" style="font-size:40px; opacity:.3; color:#aaa; display:block; margin-bottom:8px;"></i>
                                <p class="text-muted small mb-0">Мініатюра не вибрана</p>
                            </div>

                            <!-- Приховані поля -->
                            <input type="hidden" name="thumbnail_url" id="thumbnailInput"
                                   value="<?php echo htmlspecialchars($post['thumbnail'] ?? ''); ?>">
                            <input type="hidden" name="remove_thumbnail" id="removeThumbnailInput" value="0">

                            <!-- Кнопка відкриття медіатеки -->
                            <div class="d-grid mt-3">
                                <button type="button" class="btn btn-outline-primary" onclick="openThumbnailPicker()">
                                    <i class="bi bi-folder2-open"></i> Вибрати із медіатеки
                                </button>
                            </div>
                        </div>

                        <div class="info-panel">
                            <h6 class="mb-3">
                                <i class="bi bi-info-circle me-2 text-primary"></i>
                                Інформація
                            </h6>
                            <div class="info-panel-item">
                                <span>Створено:</span>
                                <span><?php echo date('d.m.Y H:i', strtotime($post['created_at'])); ?></span>
                            </div>
                            <div class="info-panel-item">
                                <span>Оновлено:</span>
                                <span><?php echo $post['updated_at'] ? date('d.m.Y H:i', strtotime($post['updated_at'])) : '—'; ?></span>
                            </div>
                            <div class="info-panel-item">
                                <span>Автор:</span>
                                <span><?php 
                                    $authorName = $post['author'] ?? $username;
                                    if (!empty($authorName)) {
                                        try {
                                            $stmt = $pdo->prepare("SELECT display_name FROM users WHERE LOWER(login) = LOWER(?)");
                                            $stmt->execute([$authorName]);
                                            $authorInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                                            if ($authorInfo && !empty($authorInfo['display_name'])) {
                                                echo htmlspecialchars($authorInfo['display_name']);
                                            } else {
                                                echo htmlspecialchars($authorName);
                                            }
                                        } catch (Exception $e) {
                                            echo htmlspecialchars($authorName);
                                        }
                                    }
                                ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3 mt-4 pt-3 border-top">
                    <button type="submit" name="save_action" value="save" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg"></i>
                        Зберегти зміни
                    </button>
                    <button type="submit" name="save_action" value="save_and_close" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-box-arrow-left"></i>
                        Зберегти і закрити
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>

// ══════════════════════════════════════════════════════════════════════
// АВТОЗБЕРЕЖЕННЯ В localStorage
// ══════════════════════════════════════════════════════════════════════
var AUTOSAVE_KEY = 'autosave_post_<?= $post['id'] ?>';
var autosaveTimer = null;
var lastSavedHash = '';

function getContentHash(title, body) {
    return (title + '|' + body.length + '|' + body.substring(0, 100)).replace(/\s+/g,' ');
}

function doAutosave() {
    var title = document.getElementById('titleInput') ? document.getElementById('titleInput').value : '';
    var body  = '';
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        body = tinymce.activeEditor.getContent();
    } else {
        var ta = document.getElementById('content');
        if (ta) body = ta.value;
    }
    var hash = getContentHash(title, body);
    if (hash === lastSavedHash) return; // нічого не змінилось
    lastSavedHash = hash;
    var data = { title: title, content: body, savedAt: new Date().toISOString() };
    try {
        localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(data));
        showAutosaveIndicator();
    } catch(e) {}
}

function showAutosaveIndicator() {
    var el = document.getElementById('autosaveStatus');
    if (!el) return;
    el.textContent = '💾 Збережено ' + new Date().toLocaleTimeString('uk-UA', {hour:'2-digit',minute:'2-digit'});
    el.className = 'text-success small';
}

function clearAutosave() {
    try { localStorage.removeItem(AUTOSAVE_KEY); } catch(e) {}
}

function checkAutosaveOnLoad() {
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(AUTOSAVE_KEY)); } catch(e) {}
    if (!saved) return;

    // Порівнюємо з поточним збереженим контентом
    var currentTitle = document.getElementById('titleInput') ? document.getElementById('titleInput').value : '';
    var currentBody  = '';
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        currentBody = tinymce.activeEditor.getContent();
    }
    var savedHash    = getContentHash(saved.title, saved.content);
    var currentHash  = getContentHash(currentTitle, currentBody);
    if (savedHash === currentHash) { clearAutosave(); return; } // вже актуально

    var banner = document.getElementById('autosaveBanner');
    if (banner) {
        var timeStr = saved.savedAt ? new Date(saved.savedAt).toLocaleString('uk-UA') : '';
        document.getElementById('autosaveTime').textContent = timeStr;
        banner.style.display = 'flex';
    }
}

function restoreAutosave() {
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(AUTOSAVE_KEY)); } catch(e) {}
    if (!saved) return;
    if (saved.title && document.getElementById('titleInput')) {
        document.getElementById('titleInput').value = saved.title;
    }
    if (saved.content) {
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.setContent(saved.content);
        } else {
            var ta = document.getElementById('content');
            if (ta) ta.value = saved.content;
        }
    }
    clearAutosave();
    document.getElementById('autosaveBanner').style.display = 'none';
}

function dismissAutosave() {
    clearAutosave();
    document.getElementById('autosaveBanner').style.display = 'none';
}

// Очищуємо автозбереження при успішному збереженні форми
document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            clearAutosave();
        });
    }

    // Старт таймера автозбереження
    autosaveTimer = setInterval(doAutosave, 30000);

    // Перевіряємо через 1.5с (TinyMCE може ще вантажитись)
    setTimeout(checkAutosaveOnLoad, 1500);

    // Також зберігаємо при зміні заголовка
    var titleEl = document.getElementById('titleInput');
    if (titleEl) titleEl.addEventListener('input', function() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setInterval(doAutosave, 30000);
    });
});

// ══════════════════════════════════════════════════════════════════════
// РЕВІЗІЇ — відновлення через AJAX
// ══════════════════════════════════════════════════════════════════════
function restoreRevision(revId) {
    if (!confirm('Відновити цю ревізію? Поточний текст буде замінено (але не збережено).')) return;
    var postSlug = new URLSearchParams(window.location.search).get('post');
    fetch('/admin/edit_post.php?post=' + encodeURIComponent(postSlug) + '&revision_id=' + revId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) { alert('Помилка: ' + (data.error || 'невідома')); return; }
        if (document.getElementById('titleInput')) {
            document.getElementById('titleInput').value = data.title;
        }
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.setContent(data.content);
        } else {
            var ta = document.getElementById('content');
            if (ta) ta.value = data.content;
        }
        // Підсвічуємо що є незбережені зміни
        var el = document.getElementById('autosaveStatus');
        if (el) { el.textContent = '⚠️ Ревізію відновлено — збережіть форму'; el.className = 'text-warning small'; }
    })
    .catch(function(e) { alert('Мережева помилка: ' + e.message); });
}

function setStatus(status) {
    const draftInput = document.getElementById('draftInput');
    const toggleOptions = document.querySelectorAll('.toggle-option');
    
    if (status === 'published') {
        draftInput.value = '0';
        toggleOptions[0].classList.add('active');
        toggleOptions[1].classList.remove('active');
    } else {
        draftInput.value = '1';
        toggleOptions[0].classList.remove('active');
        toggleOptions[1].classList.add('active');
    }
}

// === Мініатюра через медіатеку ===
function openThumbnailPicker() {
    var win = window.open(
        '/admin/media_picker.php?callback=thumbnailPickerCallback',
        'thumbnail_picker',
        'width=900,height=650,resizable=yes,scrollbars=yes'
    );
    window.thumbnailPickerCallback = function(url) {
        setThumbnail(url);
        win.close();
    };
}

function setThumbnail(url) {
    document.getElementById('thumbnailInput').value = url;
    document.getElementById('thumbnailPreview').src = url;
    document.getElementById('thumbnailPreviewWrap').classList.remove('d-none');
    document.getElementById('thumbnailEmpty').classList.add('d-none');
    document.getElementById('removeThumbnailInput').value = '0';
}

function removeThumbnail() {
    document.getElementById('thumbnailInput').value = '';
    document.getElementById('removeThumbnailInput').value = '1';
    document.getElementById('thumbnailPreviewWrap').classList.add('d-none');
    document.getElementById('thumbnailEmpty').classList.remove('d-none');
}
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>