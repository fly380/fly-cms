<?php
// admin/notes.php — Нотатки
session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../data/log_action.php';

$pdo      = connectToDatabase();
$username = $_SESSION['username'] ?? 'невідомо';
$role     = $_SESSION['role'] ?? 'user';

// ── Міграція: створити таблицю notes якщо не існує ────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS notes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner       TEXT NOT NULL,
    scope       TEXT NOT NULL DEFAULT 'personal',
    title       TEXT NOT NULL DEFAULT '',
    body        TEXT NOT NULL DEFAULT '',
    color       TEXT NOT NULL DEFAULT 'yellow',
    remind_at   DATETIME DEFAULT NULL,
    reminded    INTEGER NOT NULL DEFAULT 0,
    linked_type TEXT DEFAULT NULL,
    linked_id   INTEGER DEFAULT NULL,
    pinned      INTEGER NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── CSRF ──────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$message = '';
$msgType = '';

// ── Завантажити списки posts/pages для прив'язки ──────────────────────
$allPosts = $pdo->query("SELECT id, title, slug FROM posts ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$allPages = $pdo->query("SELECT id, title, slug FROM pages ORDER BY title ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

// ── POST-обробники ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        $message = 'Невірний токен безпеки.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Створити нотатку ──────────────────────────────────────────
        if ($action === 'create') {
            $title       = trim($_POST['title'] ?? '');
            $body        = trim($_POST['body'] ?? '');
            $color       = in_array($_POST['color'] ?? '', ['yellow','blue','green','red','purple','gray']) ? $_POST['color'] : 'yellow';
            $scope       = ($_POST['scope'] ?? 'personal') === 'shared' ? 'shared' : 'personal';
            $remind_raw  = trim($_POST['remind_at'] ?? '');
            $remind_at   = $remind_raw !== '' ? $remind_raw : null;
            $linked_type = in_array($_POST['linked_type'] ?? '', ['post','page']) ? $_POST['linked_type'] : null;
            $linked_id   = !empty($_POST['linked_id']) ? (int)$_POST['linked_id'] : null;
            if ($linked_type === null) $linked_id = null;

            if ($title === '' && $body === '') {
                $message = 'Нотатка порожня.';
                $msgType = 'warning';
            } else {
                $pdo->prepare("INSERT INTO notes (owner, scope, title, body, color, remind_at, linked_type, linked_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$username, $scope, $title, $body, $color, $remind_at, $linked_type, $linked_id]);
                log_action("Створено нотатку: " . ($title ?: '(без заголовку)') . " [{$scope}]", $username);
                $message = 'Нотатку створено.';
                $msgType = 'success';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $csrf = $_SESSION['csrf_token'];
            }
        }

        // ── Оновити нотатку ───────────────────────────────────────────
        if ($action === 'update') {
            $id          = (int)($_POST['note_id'] ?? 0);
            $title       = trim($_POST['title'] ?? '');
            $body        = trim($_POST['body'] ?? '');
            $color       = in_array($_POST['color'] ?? '', ['yellow','blue','green','red','purple','gray']) ? $_POST['color'] : 'yellow';
            $scope       = ($_POST['scope'] ?? 'personal') === 'shared' ? 'shared' : 'personal';
            $remind_raw  = trim($_POST['remind_at'] ?? '');
            $remind_at   = $remind_raw !== '' ? $remind_raw : null;
            $linked_type = in_array($_POST['linked_type'] ?? '', ['post','page']) ? $_POST['linked_type'] : null;
            $linked_id   = !empty($_POST['linked_id']) ? (int)$_POST['linked_id'] : null;
            if ($linked_type === null) $linked_id = null;

            // Перевірка власника або shared
            $existing = $pdo->prepare("SELECT * FROM notes WHERE id = ?");
            $existing->execute([$id]);
            $note = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$note) {
                $message = 'Нотатку не знайдено.';
                $msgType = 'danger';
            } elseif ($note['scope'] !== 'shared' && $note['owner'] !== $username && !in_array($role, ['admin','superadmin'])) {
                $message = 'Немає доступу до цієї нотатки.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE notes SET title=?, body=?, color=?, scope=?, remind_at=?, reminded=0,
                               linked_type=?, linked_id=?, updated_at=datetime('now','localtime')
                               WHERE id=?")
                    ->execute([$title, $body, $color, $scope, $remind_at, $linked_type, $linked_id, $id]);
                log_action("Оновлено нотатку #{$id}", $username);
                $message = 'Нотатку збережено.';
                $msgType = 'success';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $csrf = $_SESSION['csrf_token'];
            }
        }

        // ── Закріпити/відкріпити ──────────────────────────────────────
        if ($action === 'toggle_pin') {
            $id = (int)($_POST['note_id'] ?? 0);
            $n  = $pdo->prepare("SELECT * FROM notes WHERE id=?");
            $n->execute([$id]);
            $note = $n->fetch(PDO::FETCH_ASSOC);
            if ($note && ($note['owner'] === $username || $note['scope'] === 'shared' || $role === 'admin')) {
                $newPin = $note['pinned'] ? 0 : 1;
                $pdo->prepare("UPDATE notes SET pinned=? WHERE id=?")->execute([$newPin, $id]);
            }
            header('Location: /admin/notes.php'); exit;
        }

        // ── Видалити ──────────────────────────────────────────────────
        if ($action === 'delete') {
            $id = (int)($_POST['note_id'] ?? 0);
            $n  = $pdo->prepare("SELECT * FROM notes WHERE id=?");
            $n->execute([$id]);
            $note = $n->fetch(PDO::FETCH_ASSOC);
            if ($note && ($note['owner'] === $username || $role === 'admin')) {
                $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$id]);
                log_action("Видалено нотатку #{$id}: " . $note['title'], $username);
                $message = 'Нотатку видалено.';
                $msgType = 'success';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $csrf = $_SESSION['csrf_token'];
            } else {
                $message = 'Немає доступу.';
                $msgType = 'danger';
            }
        }
    }
}

// ── Фільтри ───────────────────────────────────────────────────────────
$filterScope = $_GET['scope'] ?? 'all';
$filterColor = $_GET['color'] ?? '';
$search      = trim($_GET['q'] ?? '');

// ── Завантажити нотатки ───────────────────────────────────────────────
$where   = [];
$params  = [];

// Видимість: особисті (свої) + всі shared
$where[]  = "(owner = ? OR scope = 'shared')";
$params[] = $username;

if ($filterScope === 'personal') { $where[] = "scope = 'personal' AND owner = ?"; $params[] = $username; }
if ($filterScope === 'shared')   { $where[] = "scope = 'shared'"; }
if ($filterColor !== '')         { $where[] = "color = ?"; $params[] = $filterColor; }
if ($search !== '')              { $where[] = "(title LIKE ? OR body LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$sql   = "SELECT * FROM notes WHERE " . implode(' AND ', $where) . " ORDER BY pinned DESC, updated_at DESC";
$stmt  = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Перевірка нагадувань ──────────────────────────────────────────────
$reminders = $pdo->prepare("SELECT * FROM notes
    WHERE (owner=? OR scope='shared')
      AND remind_at IS NOT NULL
      AND remind_at <= datetime('now','localtime')
      AND reminded = 0");
$reminders->execute([$username]);
$dueReminders = $reminders->fetchAll(PDO::FETCH_ASSOC);
// Позначити як нагадані
if (!empty($dueReminders)) {
    $ids = implode(',', array_column($dueReminders, 'id'));
    $pdo->exec("UPDATE notes SET reminded=1 WHERE id IN ($ids)");
}

// ── Колонки кольорів ──────────────────────────────────────────────────
$colorMap = [
    'yellow' => ['bg' => '#fff9c4', 'border' => '#f9a825', 'label' => '🟡 Звичайна'],
    'blue'   => ['bg' => '#e3f2fd', 'border' => '#1565c0', 'label' => '🔵 Інформація'],
    'green'  => ['bg' => '#e8f5e9', 'border' => '#2e7d32', 'label' => '🟢 Виконано'],
    'red'    => ['bg' => '#ffebee', 'border' => '#c62828', 'label' => '🔴 Терміново'],
    'purple' => ['bg' => '#f3e5f5', 'border' => '#6a1b9a', 'label' => '🟣 Ідея'],
    'gray'   => ['bg' => '#f5f5f5', 'border' => '#616161', 'label' => '⚫ Архів'],
];

$page_title = '📝 Нотатки';
ob_start();
?>
<div class="container-fluid px-4">

<!-- ── Нагадування-тости ─────────────────────────────────────────── -->
<?php if (!empty($dueReminders)): ?>
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999">
    <?php foreach($dueReminders as $dr): ?>
    <div class="toast show align-items-center text-white bg-danger border-0 mb-2" role="alert">
        <div class="d-flex">
            <div class="toast-body">
                <strong>⏰ Нагадування!</strong><br>
                <?= htmlspecialchars($dr['title'] ?: 'Нотатка #'.$dr['id']) ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Заголовок ─────────────────────────────────────────────────── -->
<div class="d-flex align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">📝 Нотатки</h1>
        <p class="text-muted small mt-1">Особисті та спільні нотатки команди</p>
    </div>
    <div class="ms-auto">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#noteModal" onclick="openCreateModal()">
            ＋ Нова нотатка
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Фільтри ───────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <select name="scope" class="form-select form-select-sm">
                    <option value="all"      <?= $filterScope==='all'      ?'selected':'' ?>>Всі нотатки</option>
                    <option value="personal" <?= $filterScope==='personal' ?'selected':'' ?>>Мої особисті</option>
                    <option value="shared"   <?= $filterScope==='shared'   ?'selected':'' ?>>Спільні</option>
                </select>
            </div>
            <div class="col-auto">
                <select name="color" class="form-select form-select-sm">
                    <option value="">Всі кольори</option>
                    <?php foreach($colorMap as $k => $c): ?>
                    <option value="<?= $k ?>" <?= $filterColor===$k?'selected':'' ?>><?= $c['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="🔍 Пошук..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Фільтр</button>
                <a href="/admin/notes.php" class="btn btn-outline-secondary btn-sm">✕</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Сітка нотаток ─────────────────────────────────────────────── -->
<?php if (empty($notes)): ?>
<div class="text-center py-5 text-muted">
    <div style="font-size:3rem">📋</div>
    <p class="mt-2">Нотаток не знайдено. Створіть першу!</p>
</div>
<?php else: ?>
<div class="row g-3" id="notesGrid">
    <?php foreach($notes as $note):
        $cm = $colorMap[$note['color']] ?? $colorMap['yellow'];
        $isOwner = ($note['owner'] === $username) || ($role === 'admin');
        $isPinned = (bool)$note['pinned'];
        $isShared = $note['scope'] === 'shared';

        // Прив'язка до контенту
        $linkedLabel = '';
        if ($note['linked_type'] && $note['linked_id']) {
            if ($note['linked_type'] === 'post') {
                foreach($allPosts as $p) {
                    if ($p['id'] == $note['linked_id']) { $linkedLabel = '📝 '.$p['title']; break; }
                }
            } else {
                foreach($allPages as $pg) {
                    if ($pg['id'] == $note['linked_id']) { $linkedLabel = '📄 '.$pg['title']; break; }
                }
            }
        }

        // Нагадування
        $reminderLabel = '';
        $reminderClass = '';
        if ($note['remind_at']) {
            $remTs = strtotime($note['remind_at']);
            $now   = time();
            if ($note['reminded']) {
                $reminderLabel = '✅ ' . date('d.m.Y H:i', $remTs);
                $reminderClass = 'text-success';
            } elseif ($remTs <= $now) {
                $reminderLabel = '⏰ ' . date('d.m.Y H:i', $remTs) . ' (прострочено)';
                $reminderClass = 'text-danger fw-bold';
            } else {
                $reminderLabel = '⏰ ' . date('d.m.Y H:i', $remTs);
                $reminderClass = 'text-warning';
            }
        }
    ?>
    <div class="col-sm-6 col-lg-4 col-xl-3 note-card-wrap">
        <div class="note-card h-100 shadow-sm"
             style="background:<?= $cm['bg'] ?>;border-left:4px solid <?= $cm['border'] ?>;border-radius:10px;padding:14px 16px;position:relative;">

            <!-- Шапка -->
            <div class="d-flex align-items-start gap-1 mb-2">
                <div class="flex-grow-1">
                    <?php if ($isPinned): ?><span title="Закріплено" style="font-size:.85rem">📌</span><?php endif; ?>
                    <?php if ($isShared): ?><span class="badge bg-primary" style="font-size:.65rem">Спільна</span><?php endif; ?>
                    <?php if (!$isShared && $note['owner'] !== $username): ?>
                        <span class="badge bg-secondary" style="font-size:.65rem"><?= htmlspecialchars($note['owner']) ?></span>
                    <?php endif; ?>
                </div>
                <!-- Кнопки -->
                <div class="d-flex gap-1 flex-shrink-0">
                    <!-- Закріпити -->
                    <?php if ($isOwner || $isShared): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="toggle_pin">
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent" title="<?= $isPinned?'Відкріпити':'Закріпити' ?>" style="font-size:1rem;line-height:1"><?= $isPinned?'📌':'📍' ?></button>
                    </form>
                    <?php endif; ?>
                    <!-- Редагувати -->
                    <?php if ($isOwner || $isShared): ?>
                    <button class="btn btn-sm p-0 border-0 bg-transparent" style="font-size:1rem;line-height:1"
                            title="Редагувати"
                            onclick='openEditModal(<?= json_encode($note) ?>)'>✏️</button>
                    <?php endif; ?>
                    <!-- Видалити -->
                    <?php if ($isOwner): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Видалити нотатку?')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent" title="Видалити" style="font-size:1rem;line-height:1">🗑️</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Заголовок -->
            <?php if ($note['title']): ?>
            <h6 class="fw-bold mb-1" style="font-size:.9rem;word-break:break-word"><?= htmlspecialchars($note['title']) ?></h6>
            <?php endif; ?>

            <!-- Тіло -->
            <?php if ($note['body']): ?>
            <p class="mb-2 small" style="white-space:pre-wrap;word-break:break-word;max-height:120px;overflow:hidden"><?= htmlspecialchars($note['body']) ?></p>
            <?php endif; ?>

            <!-- Мета -->
            <div class="mt-auto" style="font-size:.72rem;color:#555">
                <?php if ($linkedLabel): ?>
                <div class="text-truncate mb-1" title="<?= htmlspecialchars($linkedLabel) ?>">🔗 <?= htmlspecialchars($linkedLabel) ?></div>
                <?php endif; ?>
                <?php if ($reminderLabel): ?>
                <div class="<?= $reminderClass ?> mb-1"><?= $reminderLabel ?></div>
                <?php endif; ?>
                <div class="text-muted"><?= date('d.m.Y H:i', strtotime($note['updated_at'])) ?> · <?= htmlspecialchars($note['owner']) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ══════════════════════════════════════════════════════════════════
     МОДАЛЬНЕ ВІКНО НОТАТКИ
═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="noteForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" id="noteAction" value="create">
                <input type="hidden" name="note_id" id="noteId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="noteModalTitle">➕ Нова нотатка</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Заголовок -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Заголовок</label>
                            <input type="text" name="title" id="noteTitle" class="form-control" placeholder="Короткий заголовок нотатки" maxlength="200">
                        </div>

                        <!-- Текст -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Текст нотатки</label>
                            <textarea name="body" id="noteBody" class="form-control" rows="5" placeholder="Детальний опис..."></textarea>
                        </div>

                        <!-- Колір та тип -->
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Мітка / Пріоритет</label>
                            <select name="color" id="noteColor" class="form-select">
                                <?php foreach($colorMap as $k => $c): ?>
                                <option value="<?= $k ?>"><?= $c['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Тип нотатки</label>
                            <select name="scope" id="noteScope" class="form-select">
                                <option value="personal">🔒 Особиста</option>
                                <option value="shared">👥 Спільна</option>
                            </select>
                        </div>

                        <!-- Нагадування -->
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">⏰ Нагадати</label>
                            <input type="datetime-local" name="remind_at" id="noteRemindAt" class="form-control">
                        </div>

                        <!-- Прив'язка -->
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">🔗 Прив'язати до</label>
                            <select name="linked_type" id="noteLinkedType" class="form-select" onchange="updateLinkedList()">
                                <option value="">— Без прив'язки —</option>
                                <option value="post">📝 Запис</option>
                                <option value="page">📄 Сторінка</option>
                            </select>
                        </div>

                        <div class="col-12" id="linkedListWrap" style="display:none">
                            <label class="form-label fw-semibold" id="linkedListLabel">Виберіть запис</label>
                            <select name="linked_id" id="noteLinkedId" class="form-select">
                                <option value="">— Виберіть —</option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">💾 Зберегти</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.note-card { transition: box-shadow .15s, transform .15s; }
.note-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.15) !important; transform: translateY(-2px); }
</style>

<script>
// Дані для прив'язки
const allPosts = <?= json_encode(array_map(fn($p)=>['id'=>$p['id'],'title'=>$p['title']], $allPosts)) ?>;
const allPages = <?= json_encode(array_map(fn($p)=>['id'=>$p['id'],'title'=>$p['title']], $allPages)) ?>;

function updateLinkedList() {
    const type = document.getElementById('noteLinkedType').value;
    const wrap = document.getElementById('linkedListWrap');
    const sel  = document.getElementById('noteLinkedId');
    const lbl  = document.getElementById('linkedListLabel');
    if (!type) { wrap.style.display='none'; return; }
    wrap.style.display = '';
    lbl.textContent = type === 'post' ? 'Виберіть запис' : 'Виберіть сторінку';
    const items = type === 'post' ? allPosts : allPages;
    sel.innerHTML = '<option value="">— Виберіть —</option>' +
        items.map(i => `<option value="${i.id}">${i.title}</option>`).join('');
}

function openCreateModal() {
    document.getElementById('noteModalTitle').textContent = '➕ Нова нотатка';
    document.getElementById('noteAction').value  = 'create';
    document.getElementById('noteId').value      = '';
    document.getElementById('noteTitle').value   = '';
    document.getElementById('noteBody').value    = '';
    document.getElementById('noteColor').value   = 'yellow';
    document.getElementById('noteScope').value   = 'personal';
    document.getElementById('noteRemindAt').value= '';
    document.getElementById('noteLinkedType').value = '';
    document.getElementById('linkedListWrap').style.display = 'none';
    document.getElementById('noteLinkedId').value = '';
}

function openEditModal(note) {
    document.getElementById('noteModalTitle').textContent = '✏️ Редагувати нотатку';
    document.getElementById('noteAction').value  = 'update';
    document.getElementById('noteId').value      = note.id;
    document.getElementById('noteTitle').value   = note.title || '';
    document.getElementById('noteBody').value    = note.body  || '';
    document.getElementById('noteColor').value   = note.color || 'yellow';
    document.getElementById('noteScope').value   = note.scope || 'personal';
    // datetime-local формат: "YYYY-MM-DDTHH:MM"
    if (note.remind_at) {
        document.getElementById('noteRemindAt').value = note.remind_at.replace(' ', 'T').substring(0, 16);
    } else {
        document.getElementById('noteRemindAt').value = '';
    }
    document.getElementById('noteLinkedType').value = note.linked_type || '';
    updateLinkedList();
    if (note.linked_id) {
        document.getElementById('noteLinkedId').value = note.linked_id;
    }
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}
</script>

<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
