<?php
// admin/support.php — Технічна підтримка
// Спілкування між адміністраторами сайту та розробником (fly380.it@gmail.com)
// Доступ: admin + superadmin

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/smtp_helper.php';

fly_send_security_headers();

$pdo      = connectToDatabase();
$username = $_SESSION['username']  ?? 'admin';
$role     = $_SESSION['role']      ?? 'admin';

define('SUPPORT_EMAIL', 'fly380.it@gmail.com');
define('SUPPORT_NAME',  'fly-CMS Розробник');

// ─── CSRF ────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_support'])) {
    $_SESSION['csrf_support'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_support'];

// ─── Ініціалізація таблиць ───────────────────────────────────────
function support_init(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            uid        TEXT NOT NULL UNIQUE,
            author     TEXT NOT NULL,
            subject    TEXT NOT NULL DEFAULT '',
            category   TEXT NOT NULL DEFAULT 'general',
            priority   TEXT NOT NULL DEFAULT 'normal',
            status     TEXT NOT NULL DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at  DATETIME DEFAULT NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_messages (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id  INTEGER NOT NULL,
            sender     TEXT NOT NULL,
            sender_type TEXT NOT NULL DEFAULT 'user',
            body       TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_attachments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            filename   TEXT NOT NULL,
            size       INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
}
support_init($pdo);

// Додати reply_token якщо ще немає (міграція)
$_cols = array_column($pdo->query("PRAGMA table_info(support_tickets)")->fetchAll(), 'name');
if (!in_array('reply_token', $_cols)) {
    $pdo->exec("ALTER TABLE support_tickets ADD COLUMN reply_token TEXT DEFAULT NULL");
}
unset($_cols);

// ─── Хелпер відправки email ─────────────────────────────────────
function support_send_email(string $to, string $toName, string $subject, string $bodyHtml, string $bodyText): array {
	$res = fly_smtp_send($to, $subject, $bodyHtml, $bodyText);
	return ['sent' => $res['sent'], 'error' => $res['error']];
}
// ─── Побудова HTML-листа для підтримки ───────────────────────────
function support_email_html(string $ticketUid, string $subject, string $body, string $sender, string $senderType, string $siteName, string $replyUrl = ''): string {
    $isSupport   = $senderType === 'support';
    $accentColor = $isSupport ? '#2E5FA3' : '#1a3d6e';
    $senderLabel = $isSupport ? SUPPORT_NAME : 'Адмін: ' . $sender . ' (' . $siteName . ')';
    $bodyEsc     = nl2br(htmlspecialchars($body));
    $dateStr     = date('d.m.Y H:i');
    $ticketEsc   = htmlspecialchars($ticketUid);
    $subjectEsc  = htmlspecialchars($subject);
    $replyHref   = $replyUrl ? htmlspecialchars($replyUrl) : '#';

    $html  = '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">';
    $html .= '<style>';
    $html .= 'body{font-family:Arial,sans-serif;background:#f0f4fb;margin:0;padding:20px}';
    $html .= '.wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}';
    $html .= '.hdr{background:linear-gradient(135deg,#1a3d6e,#2E5FA3);color:#fff;padding:28px 32px}';
    $html .= '.hdr h2{margin:0 0 4px;font-size:1.2rem}';
    $html .= '.hdr .tid{opacity:.7;font-size:.8rem;font-family:monospace}';
    $html .= '.bdy{padding:28px 32px}';
    $html .= '.meta{font-size:.82rem;color:#6b7280;margin-bottom:16px}';
    $html .= '.meta span{background:#f3f4f6;padding:3px 10px;border-radius:20px;margin-right:6px}';
    $html .= '.msg{background:#f8faff;border-left:4px solid ' . $accentColor . ';border-radius:0 8px 8px 0;padding:16px 20px;line-height:1.7;color:#1f2937}';
    $html .= '.footer{background:#f3f4f6;padding:18px 32px;font-size:.78rem;color:#9ca3af;text-align:center}';
    // .btn стиль не потрібен — використовуємо inline стилі на тегу <a>
    $html .= '</style></head><body>';
    $html .= '<div class="wrap">';
    $html .= '<div class="hdr">';
    $html .= '<div>fly-CMS &middot; Технічна підтримка</div>';
    $html .= '<h2>Нове повідомлення</h2>';
    $html .= '<div class="tid">Тікет #' . $ticketEsc . ' &middot; ' . $subjectEsc . '</div>';
    $html .= '</div>';
    $html .= '<div class="bdy">';
    $html .= '<div class="meta"><span>' . htmlspecialchars($senderLabel) . '</span><span>' . $dateStr . '</span></div>';
    $html .= '<div class="msg">' . $bodyEsc . '</div>';
    $html .= '</div>';
    if ($replyUrl) {
        $html .= '<div style="padding:0 32px 24px">';
        $html .= '<a href="' . $replyHref . '" style="display:inline-block;margin-top:20px;padding:12px 28px;background:#2E5FA3;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:15px;font-family:Arial,sans-serif">Відповісти в CMS</a>';
        $html .= '<span style="font-size:.75rem;color:#9ca3af;margin-left:12px">Посилання одноразове</span>';
        $html .= '</div>';
    }
    $html .= '<div class="footer">fly-CMS Support &middot; fly380.it@gmail.com</div>';
    $html .= '</div></body></html>';
    return $html;
}

// ─── AJAX: лічильник повідомлень для polling ─────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'msgcount') {
    $tid = (int)($_GET['ticket'] ?? 0);
    if ($tid) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE ticket_id=?");
        $n->execute([$tid]);
        header('Content-Type: application/json');
        echo json_encode(['count' => (int)$n->fetchColumn()]);
    }
    exit;
}

// ─── Обробка POST-дій ─────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf']??'') !== $csrf) {
        $flash = '<div class="alert alert-danger">Невірний CSRF токен</div>';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Новий тікет ──────────────────────────────────────────
        if ($action === 'new_ticket') {
            $subject  = trim($_POST['subject']  ?? '');
            $category = trim($_POST['category'] ?? 'general');
            $priority = trim($_POST['priority'] ?? 'normal');
            $body     = trim($_POST['body']     ?? '');

            if (!$subject || !$body) {
                $flash = '<div class="alert alert-warning">Заповніть тему та текст звернення</div>';
            } else {
                $uid = strtoupper(substr(md5(uniqid($username,true)),0,8));
                $pdo->prepare("INSERT INTO support_tickets (uid,author,subject,category,priority,status) VALUES (?,?,?,?,?,'open')")
                    ->execute([$uid,$username,$subject,$category,$priority]);
                $tid = $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO support_messages (ticket_id,sender,sender_type,body) VALUES (?,?,?,?)")
                    ->execute([$tid,$username,'user',$body]);

                // Генерувати reply_token для відповіді розробника
                $replyToken = bin2hex(random_bytes(24));
                $pdo->prepare("UPDATE support_tickets SET reply_token=? WHERE id=?")->execute([$replyToken,$tid]);
                $replyUrl = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http')
                    .'://'.$_SERVER['HTTP_HOST'].'/admin/support_reply.php?token='.$replyToken;

                // Email розробнику
                $siteName = get_setting('site_name') ?: get_setting('site_title') ?: 'fly-CMS';
                $emailBody = "Нове звернення від адміністратора сайту.\n\nСайт: $siteName\nАдмін: $username\nТікет: #$uid\nТема: $subject\nКатегорія: $category\nПріоритет: $priority\n\n---\n$body\n---\n\nВідповісти в CMS: $replyUrl";
                $emailHtml = support_email_html($uid,$subject,$body,$username,'user',$siteName,$replyUrl);
                $res = support_send_email(SUPPORT_EMAIL,SUPPORT_NAME,"[fly-CMS Support #$uid] $subject",$emailHtml,$emailBody);

                $sent = $res['sent'] ? '✅ Email розробнику надіслано' : '⚠ Email не надіслано: '.$res['error'];
                log_action($username,"Відкрито тікет підтримки #$uid: $subject");
                $flash = '<div class="alert alert-success">✅ Звернення #'.$uid.' створено. '.$sent.'</div>';
            }
        }

        // ── Відповідь у тікет ────────────────────────────────────
        if ($action === 'reply') {
            $tid  = (int)($_POST['ticket_id'] ?? 0);
            $body = trim($_POST['body'] ?? '');
            if (!$tid || !$body) {
                $flash = '<div class="alert alert-warning">Введіть текст відповіді</div>';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id=?");
                $stmt->execute([$tid]);
                $ticket = $stmt->fetch();

                if ($ticket) {
                    $pdo->prepare("INSERT INTO support_messages (ticket_id,sender,sender_type,body) VALUES (?,?,?,?)")
                        ->execute([$tid,$username,'user',$body]);
                    // Оновити reply_token і статус
                    $replyToken = bin2hex(random_bytes(24));
                    $pdo->prepare("UPDATE support_tickets SET reply_token=?, status='open', updated_at=datetime('now') WHERE id=?")->execute([$replyToken,$ticket['id']]);
                    $replyUrl = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http')
                        .'://'.$_SERVER['HTTP_HOST'].'/admin/support_reply.php?token='.$replyToken;

                    // Email розробнику
                    $siteName = get_setting('site_name') ?: get_setting('site_title') ?: 'fly-CMS';
                    $emailBody = "Нова відповідь у тікеті #".$ticket['uid'].".

Адмін: $username
Тема: ".$ticket['subject']."

---
$body
---

Відповісти в CMS: $replyUrl";
                    $emailHtml = support_email_html($ticket['uid'],$ticket['subject'],$body,$username,'user',$siteName,$replyUrl);
                    support_send_email(SUPPORT_EMAIL,SUPPORT_NAME,"[fly-CMS Support #".$ticket['uid']."] Re: ".$ticket['subject'],$emailHtml,$emailBody);

                    log_action($username,"Відповідь у тікет #".$ticket['uid']);
                    $flash = '<div class="alert alert-success">✅ Відповідь надіслано</div>';
                }
            }
        }

        // ── Закрити тікет ────────────────────────────────────────
        if ($action === 'close_ticket') {
            $tid = (int)($_POST['ticket_id'] ?? 0);
            $pdo->prepare("UPDATE support_tickets SET status='closed',closed_at=datetime('now'),updated_at=datetime('now') WHERE id=? AND author=?")
                ->execute([$tid,$username]);
            $flash = '<div class="alert alert-secondary">Тікет закрито</div>';
        }

        // ── Відкрити повторно ────────────────────────────────────
        if ($action === 'reopen_ticket') {
            $tid = (int)($_POST['ticket_id'] ?? 0);
            $pdo->prepare("UPDATE support_tickets SET status='open',closed_at=NULL,updated_at=datetime('now') WHERE id=?")
                ->execute([$tid]);
            $flash = '<div class="alert alert-info">Тікет відкрито повторно</div>';
        }

        // ── Додати відповідь від розробника вручну (superadmin) ─────
        if ($action === 'add_support_reply' && $role === 'superadmin') {
            $tid      = (int)($_POST['ticket_id'] ?? 0);
            $body     = trim($_POST['body'] ?? '');
            $devName  = trim($_POST['dev_name'] ?? 'Розробник');
            if ($tid && $body) {
                $pdo->prepare("INSERT INTO support_messages (ticket_id,sender,sender_type,body) VALUES (?,?,'support',?)")
                    ->execute([$tid, $devName, $body]);
                $pdo->prepare("UPDATE support_tickets SET reply_token=NULL, updated_at=datetime('now'), status='open' WHERE id=?")
                    ->execute([$tid]);
                log_action($username, "Додано відповідь розробника у тікет #" . $tid);
                $flash = '<div class="alert alert-success">✅ Відповідь розробника додано</div>';
            }
        }

        // ── Видалити тікет (тільки superadmin) ───────────────────
        if ($action === 'delete_ticket' && $role === 'superadmin') {
            $tid = (int)($_POST['ticket_id'] ?? 0);
            $pdo->prepare("DELETE FROM support_messages WHERE ticket_id=?")->execute([$tid]);
            $pdo->prepare("DELETE FROM support_tickets WHERE id=?")->execute([$tid]);
            $flash = '<div class="alert alert-danger">Тікет видалено</div>';
        }
    }
    // після POST — redirect щоб уникнути double-submit
    // ticket_id беремо з POST (форма) або GET (URL)
    $redirTicket = !empty($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : (!empty($_GET['ticket']) ? (int)$_GET['ticket'] : 0);
    $redirStatus = $_GET['status'] ?? 'open';
    $redirQuery  = $redirTicket ? '?ticket=' . $redirTicket . '&status=' . $redirStatus : ('?status=' . $redirStatus);
    $_SESSION['support_flash'] = $flash;
    header('Location: ' . $_SERVER['PHP_SELF'] . $redirQuery);
    exit;
}

// Прочитати flash з сесії
if (!empty($_SESSION['support_flash'])) {
    $flash = $_SESSION['support_flash'];
    unset($_SESSION['support_flash']);
}

// ─── Дані для відображення ───────────────────────────────────────
$viewTicketId = (int)($_GET['ticket'] ?? 0);
$statusFilter = $_GET['status'] ?? 'open';
$siteName = get_setting('site_name') ?: get_setting('site_title') ?: 'fly-CMS';
$smtpConfigured = !empty(env('SMTP_ENABLED')) && filter_var(env('SMTP_ENABLED'),FILTER_VALIDATE_BOOLEAN);

// Поточний тікет
$currentTicket   = null;
$currentMessages = [];
if ($viewTicketId) {
    $st = $pdo->prepare("SELECT * FROM support_tickets WHERE id=?");
    $st->execute([$viewTicketId]);
    $currentTicket = $st->fetch();
    if ($currentTicket) {
        $sm = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at ASC");
        $sm->execute([$viewTicketId]);
        $currentMessages = $sm->fetchAll();
    }
}

// Список тікетів
$where = $statusFilter === 'all' ? '' : "WHERE status='$statusFilter'";
$tickets = $pdo->query("SELECT t.*,
    (SELECT COUNT(*) FROM support_messages WHERE ticket_id=t.id) as msg_count
    FROM support_tickets t $where ORDER BY t.updated_at DESC")->fetchAll();

// Лічильники
$counts = $pdo->query("SELECT status,COUNT(*) as n FROM support_tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalOpen   = $counts['open']   ?? 0;
$totalClosed = $counts['closed'] ?? 0;

// ─── Категорії та пріоритети ─────────────────────────────────────
$categories = [
    'general'  => ['label'=>'Загальне питання',    'icon'=>'💬'],
    'bug'      => ['label'=>'Помилка / Баг',        'icon'=>'🐛'],
    'feature'  => ['label'=>'Нова функція',         'icon'=>'✨'],
    'security' => ['label'=>'Безпека',              'icon'=>'🔐'],
    'hosting'  => ['label'=>'Хостинг / Сервер',    'icon'=>'🖥'],
    'update'   => ['label'=>'Оновлення CMS',        'icon'=>'🔄'],
    'other'    => ['label'=>'Інше',                 'icon'=>'📌'],
];
$priorities = [
    'low'      => ['label'=>'Низький',    'badge'=>'bg-secondary'],
    'normal'   => ['label'=>'Звичайний',  'badge'=>'bg-primary'],
    'high'     => ['label'=>'Високий',   'badge'=>'bg-warning text-dark'],
    'critical' => ['label'=>'Критичний', 'badge'=>'bg-danger'],
];
$statuses = [
    'open'   => ['label'=>'Відкритий',  'badge'=>'bg-success'],
    'closed' => ['label'=>'Закритий',   'badge'=>'bg-secondary'],
];

// ─── HTML ─────────────────────────────────────────────────────────
$page_title = '🛠 Технічна підтримка';
ob_start();
?>
<style>
/* ── Support layout ──────────────────────────────────────────────── */
.support-wrap { display:grid; grid-template-columns:300px 1fr; gap:0; height:calc(100vh - 140px); overflow:hidden; }
.support-sidebar { border-right:1px solid #e5e7eb; display:flex; flex-direction:column; overflow:hidden; }
.support-main    { display:flex; flex-direction:column; overflow:hidden; }

/* Sidebar header */
.sb-header { padding:1rem 1.1rem .75rem; border-bottom:1px solid #e5e7eb; background:#f8faff; flex-shrink:0; }
.sb-stats   { display:flex; gap:.5rem; margin-top:.5rem; }
.sb-stat    { flex:1; background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:.3rem .6rem; text-align:center; font-size:.75rem; }
.sb-stat .n { font-size:1.1rem; font-weight:700; color:#1e3a6e; display:block; }
.sb-stat .l { color:#6b7280; font-size:.7rem; }

/* Ticket list */
.ticket-list { overflow-y:auto; flex:1; }
.ticket-item {
    padding:.75rem 1.1rem; cursor:pointer;
    border-bottom:1px solid #f0f4fb;
    transition:background .15s; position:relative;
}
.ticket-item:hover    { background:#f0f4fb; }
.ticket-item.active   { background:#eef3fa; border-left:3px solid #2E5FA3; }
.ticket-item .t-uid   { font-family:monospace; font-size:.72rem; color:#9ca3af; }
.ticket-item .t-subj  { font-size:.85rem; font-weight:600; color:#1f2937; line-height:1.3; margin:.15rem 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ticket-item .t-meta  { font-size:.72rem; color:#9ca3af; display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.ticket-item .t-count { position:absolute; right:.8rem; top:.75rem; background:#e0e7ff; color:#2E5FA3; border-radius:10px; padding:.1rem .45rem; font-size:.7rem; font-weight:700; }
.ticket-item .t-count.unread { background:#fee2e2; color:#b91c1c; }

/* Main area */
.main-header { padding:1rem 1.5rem .75rem; border-bottom:1px solid #e5e7eb; flex-shrink:0; background:#f8faff; }
.messages-wrap { flex:1; overflow-y:auto; padding:1.25rem 1.5rem; display:flex; flex-direction:column; gap:.85rem; background:#fff; }

/* Message bubble */
.msg { display:flex; gap:.7rem; max-width:90%; }
.msg.msg-user    { align-self:flex-end; flex-direction:row-reverse; }
.msg.msg-support { align-self:flex-start; }
.msg-avatar { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.msg-user    .msg-avatar { background:#1e3a6e; color:#fff; }
.msg-support .msg-avatar { background:#e0e7ff; color:#2E5FA3; }
.msg-content { flex:1; }
.msg-meta    { font-size:.72rem; color:#9ca3af; margin-bottom:.3rem; }
.msg-user .msg-meta { text-align:right; }
.msg-bubble  {
    padding:.7rem 1rem; border-radius:12px; font-size:.88rem; line-height:1.6;
    white-space:pre-wrap; word-break:break-word;
}
.msg-user    .msg-bubble { background:#1e3a6e; color:#fff; border-radius:12px 12px 0 12px; }
.msg-support .msg-bubble { background:#f3f4f6; color:#1f2937; border-radius:12px 12px 12px 0; border:1px solid #e5e7eb; }

/* Reply area */
.reply-area { padding:.85rem 1.5rem; border-top:1px solid #e5e7eb; flex-shrink:0; background:#f8faff; }
.reply-area textarea { resize:none; font-size:.88rem; border-radius:8px; }

/* New ticket form */
.new-ticket-panel { flex:1; overflow-y:auto; padding:2rem; }

/* Empty state */
.empty-state { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#9ca3af; gap:.5rem; }
.empty-state .ico { font-size:3rem; }

/* SMTP warning */
.smtp-status-bar { border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-bottom:.25rem; }
.smtp-ok   { background:#f0fdf4; border:1px solid #86efac; color:#166534; }
.smtp-warn { background:#fff8e1; border:1px solid #f59e0b; color:#92400e; }
.smtp-gmail-note { background:rgba(0,0,0,.06); border-radius:4px; padding:.15rem .5rem; font-size:.77rem; }
.smtp-gmail-note a { color:inherit; text-decoration:underline; }
.smtp-settings-link { font-size:.8rem; font-weight:600; white-space:nowrap; color:#1e3a6e; text-decoration:none; border:1px solid #1e3a6e; border-radius:5px; padding:.2rem .6rem; }
.smtp-settings-link:hover { background:#1e3a6e; color:#fff; }

/* Status filters */
.status-tabs { display:flex; gap:.3rem; margin-bottom:.5rem; }
.status-tab  { padding:.25rem .7rem; border-radius:20px; font-size:.75rem; font-weight:600; cursor:pointer; border:none; background:#f3f4f6; color:#6b7280; transition:all .15s; }
.status-tab.active { background:#1e3a6e; color:#fff; }

/* Priority dot */
.prio { display:inline-block; width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.prio-low      { background:#9ca3af; }
.prio-normal   { background:#3b82f6; }
.prio-high     { background:#f59e0b; }
.prio-critical { background:#ef4444; }

@media(max-width:768px){
  .support-wrap { grid-template-columns:1fr; }
  .support-sidebar { height:auto; max-height:240px; }
}
</style>

<div class="container-fluid px-0">
  <div class="d-flex align-items-center px-4 py-2 border-bottom bg-white gap-3" style="flex-shrink:0">
    <div>
      <h1 class="h4 mb-0">🛠 Технічна підтримка</h1>
      <small class="text-muted">Спілкування з розробником · <a href="mailto:<?= SUPPORT_EMAIL ?>"><?= SUPPORT_EMAIL ?></a></small>
    </div>
    <div class="ms-auto d-flex gap-2">
      <button class="btn btn-primary btn-sm" onclick="showNewTicket()">+ Нове звернення</button>
    </div>
  </div>

  <?php if ($flash): echo '<div class="px-4 pt-2">'.$flash.'</div>'; endif; ?>

  <div class="px-4 pt-2">
    <?php
    // Перевірка стану SMTP з деталями
    $smtpCfg    = @include __DIR__ . '/email_config.php';
    $smtpArr    = is_array($smtpCfg) ? ($smtpCfg['smtp'] ?? []) : [];
    $smtpOn     = !empty($smtpArr['enabled']);
    $smtpHost   = $smtpArr['host']     ?? '';
    $smtpUser   = $smtpArr['username'] ?? '';
    $smtpPass   = $smtpArr['password'] ?? '';
    $smtpOk     = $smtpOn && $smtpHost && $smtpUser && $smtpPass;
    $isGmail    = (strpos($smtpHost, 'gmail') !== false);
    ?>
    <div class="smtp-status-bar <?= $smtpOk ? 'smtp-ok' : 'smtp-warn' ?>">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span><?= $smtpOk ? '✅' : '⚠' ?></span>
        <span>
          <?php if ($smtpOk): ?>
            SMTP активний &mdash; <strong><?= htmlspecialchars($smtpHost) ?></strong> / <?= htmlspecialchars($smtpUser) ?>
          <?php elseif (!$smtpOn): ?>
            <strong>SMTP вимкнено.</strong> Повідомлення не надсилаються на email розробника.
          <?php elseif (!$smtpHost): ?>
            <strong>SMTP_HOST порожній.</strong> Вкажіть сервер (напр. <code>smtp.gmail.com</code>).
          <?php elseif (!$smtpUser || !$smtpPass): ?>
            <strong>SMTP: відсутній логін або пароль.</strong>
          <?php endif; ?>
        </span>
        <?php if ($isGmail && ($smtpUser || !$smtpOk)): ?>
        <span class="smtp-gmail-note">
          📌 Gmail: потрібен <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener"><strong>пароль додатків</strong></a>, не звичайний пароль
        </span>
        <?php endif; ?>
        <a href="/admin/smtp_settings.php" class="ms-auto smtp-settings-link">⚙ Налаштування SMTP</a>
      </div>
    </div>
  </div>

  <div class="support-wrap" id="supportWrap">

    <!-- ─── SIDEBAR ─────────────────────────────────────────────── -->
    <div class="support-sidebar">
      <div class="sb-header">
        <div class="d-flex justify-content-between align-items-center">
          <strong style="font-size:.9rem">Звернення</strong>
        </div>
        <div class="sb-stats mt-1">
          <div class="sb-stat">
            <span class="n"><?= $totalOpen ?></span>
            <span class="l">відкритих</span>
          </div>
          <div class="sb-stat">
            <span class="n"><?= $totalOpen + $totalClosed ?></span>
            <span class="l">всього</span>
          </div>
        </div>
        <div class="status-tabs mt-2">
          <?php foreach (['open'=>'Відкриті','closed'=>'Закриті','all'=>'Всі'] as $sv=>$sl): ?>
          <button class="status-tab <?= $statusFilter===$sv?'active':'' ?>"
            onclick="location.href='?status=<?= $sv ?>'">
            <?= $sl ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="ticket-list">
        <?php if (empty($tickets)): ?>
        <div class="text-center text-muted py-4" style="font-size:.85rem">
          Немає звернень
        </div>
        <?php else: foreach ($tickets as $t):
          $cat = $categories[$t['category']] ?? $categories['general'];
          $pri = $t['priority'] ?? 'normal';
          $isActive = $viewTicketId === (int)$t['id'];
        ?>
        <div class="ticket-item <?= $isActive?'active':'' ?>" onclick="location.href='?ticket=<?= $t['id'] ?>&status=<?= $statusFilter ?>'">
          <div class="d-flex align-items-center gap-1 mb-1">
            <span class="prio prio-<?= htmlspecialchars($pri) ?>"></span>
            <span class="t-uid">#<?= htmlspecialchars($t['uid']) ?></span>
            <span class="badge <?= $statuses[$t['status']]['badge'] ?? 'bg-secondary' ?> ms-auto" style="font-size:.65rem"><?= $statuses[$t['status']]['label'] ?? $t['status'] ?></span>
          </div>
          <div class="t-subj"><?= htmlspecialchars($t['subject']) ?></div>
          <div class="t-meta">
            <span><?= $cat['icon'] ?> <?= $cat['label'] ?></span>
            <span><?= date('d.m.Y', strtotime($t['updated_at'])) ?></span>
          </div>
          <?php
          // Остання відповідь від розробника?
          $lastSender = $pdo->prepare("SELECT sender_type FROM support_messages WHERE ticket_id=? ORDER BY created_at DESC LIMIT 1");
          $lastSender->execute([$t['id']]);
          $lastType = $lastSender->fetchColumn();
          ?>
          <?php if ($lastType === 'support'): ?>
          <span class="t-count" style="background:#d1fae5;color:#065f46" title="Є відповідь розробника">↩ <?= $t['msg_count'] ?></span>
          <?php else: ?>
          <span class="t-count"><?= $t['msg_count'] ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ─── MAIN AREA ────────────────────────────────────────────── -->
    <div class="support-main" id="mainArea">

      <?php if ($currentTicket): ?>
      <!-- ── Перегляд тікета ──────────────────────────────────── -->
      <div class="main-header">
        <div class="d-flex align-items-start gap-2 flex-wrap">
          <div style="flex:1">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <span class="badge bg-secondary font-monospace">#<?= htmlspecialchars($currentTicket['uid']) ?></span>
              <span class="badge <?= $priorities[$currentTicket['priority']]['badge'] ?? 'bg-primary' ?>">
                <?= $priorities[$currentTicket['priority']]['label'] ?? $currentTicket['priority'] ?>
              </span>
              <span class="badge <?= $statuses[$currentTicket['status']]['badge'] ?? 'bg-secondary' ?>">
                <?= $statuses[$currentTicket['status']]['label'] ?? $currentTicket['status'] ?>
              </span>
              <?php $cat = $categories[$currentTicket['category']] ?? $categories['general']; ?>
              <span class="text-muted small"><?= $cat['icon'] ?> <?= $cat['label'] ?></span>
            </div>
            <h2 class="h5 mb-0"><?= htmlspecialchars($currentTicket['subject']) ?></h2>
            <small class="text-muted">
              Автор: <?= htmlspecialchars($currentTicket['author']) ?> ·
              Відкрито: <?= date('d.m.Y H:i', strtotime($currentTicket['created_at'])) ?>
              <?php if ($currentTicket['status']==='closed' && $currentTicket['closed_at']): ?>
              · Закрито: <?= date('d.m.Y H:i', strtotime($currentTicket['closed_at'])) ?>
              <?php endif; ?>
            </small>
          </div>
          <div class="d-flex gap-2">
            <?php
            // Посилання для розробника — показати адміну щоб скопіювати/перевірити
            if (!empty($currentTicket['reply_token'])):
              $devReplyUrl = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http')
                . '://' . $_SERVER['HTTP_HOST']
                . '/admin/support_reply.php?token=' . $currentTicket['reply_token'];
            ?>
            <a href="<?= htmlspecialchars($devReplyUrl) ?>" target="_blank"
               class="btn btn-outline-success btn-sm" title="Відкрити сторінку відповіді розробника">
              🛠 Відповідь розробника
            </a>
            <?php endif; ?>
            <?php if ($currentTicket['status']==='open'): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="close_ticket">
              <input type="hidden" name="ticket_id" value="<?= $currentTicket['id'] ?>">
              <button class="btn btn-outline-secondary btn-sm">✓ Закрити</button>
            </form>
            <?php else: ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="reopen_ticket">
              <input type="hidden" name="ticket_id" value="<?= $currentTicket['id'] ?>">
              <button class="btn btn-outline-primary btn-sm">↩ Відкрити</button>
            </form>
            <?php endif; ?>
            <?php if ($role === 'superadmin'): ?>
            <form method="post" onsubmit="return confirm('Видалити тікет і всі повідомлення?')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_ticket">
              <input type="hidden" name="ticket_id" value="<?= $currentTicket['id'] ?>">
              <button class="btn btn-outline-danger btn-sm">🗑</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Messages -->
      <div class="messages-wrap" id="messagesWrap">
        <?php foreach ($currentMessages as $msg):
          $isUser = $msg['sender_type'] === 'user';
          $cls    = $isUser ? 'msg-user' : 'msg-support';
          $avatar = $isUser ? strtoupper(substr($msg['sender'],0,1)) : '🛠';
        ?>
        <div class="msg <?= $cls ?>">
          <div class="msg-avatar"><?= $avatar ?></div>
          <div class="msg-content">
            <div class="msg-meta">
              <?= $isUser ? htmlspecialchars($msg['sender']) : SUPPORT_NAME ?> ·
              <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?>
            </div>
            <div class="msg-bubble"><?= htmlspecialchars($msg['body']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Reply box -->
      <?php if ($currentTicket['status']==='open'): ?>
      <div class="reply-area">
        <form method="post" class="d-flex gap-2 align-items-end">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="ticket_id" value="<?= $currentTicket['id'] ?>">
          <textarea class="form-control" name="body" rows="2"
            placeholder="Напишіть повідомлення розробнику..." required style="flex:1"></textarea>
          <button class="btn btn-primary px-3">➤ Надіслати</button>
        </form>
        <div class="text-muted" style="font-size:.72rem;margin-top:.3rem">
          Надсилається на <?= SUPPORT_EMAIL ?> &mdash; в листі буде кнопка «Відповісти в CMS»
        </div>
      </div>

      <?php else: ?>
      <div class="reply-area text-center text-muted" style="font-size:.85rem">
        Тікет закрито ·
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="reopen_ticket">
          <input type="hidden" name="ticket_id" value="<?= $currentTicket['id'] ?>">
          <button class="btn btn-link btn-sm p-0">Відкрити повторно</button>
        </form>
      </div>
      <?php endif; ?>

      <?php elseif (isset($_GET['new'])): ?>
      <!-- ── Нова форма звернення ──────────────────────────────── -->
      <div class="main-header">
        <h2 class="h5 mb-0">+ Нове звернення</h2>
        <small class="text-muted">Буде надіслано на <?= SUPPORT_EMAIL ?></small>
      </div>
      <div class="new-ticket-panel">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="new_ticket">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Тема звернення <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="subject" required
                placeholder="Коротко опишіть проблему або питання">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Категорія</label>
              <select class="form-select" name="category">
                <?php foreach ($categories as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v['icon'] ?> <?= $v['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Пріоритет</label>
              <select class="form-select" name="priority">
                <?php foreach ($priorities as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Опис проблеми <span class="text-danger">*</span></label>
            <textarea class="form-control" name="body" rows="10" required
              placeholder="Детально опишіть проблему або запитання:
• Що відбулося?
• Як відтворити?
• Яка версія PHP, сервер?
• Що очікували побачити?

Чим детальніше — тим швидше допоможемо."></textarea>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary px-4">🚀 Надіслати звернення</button>
            <a href="?" class="btn btn-outline-secondary">Скасувати</a>
          </div>
        </form>
      </div>

      <?php else: ?>
      <!-- ── Empty state ──────────────────────────────────────── -->
      <div class="empty-state">
        <div class="ico">🛠</div>
        <div style="font-size:.95rem;font-weight:600;color:#374151">Технічна підтримка fly-CMS</div>
        <div style="font-size:.85rem;text-align:center;max-width:300px">
          Оберіть звернення зліва або створіть нове.<br>
          Всі повідомлення надсилаються розробнику на<br>
          <strong><?= SUPPORT_EMAIL ?></strong>
        </div>
        <button class="btn btn-primary mt-2" onclick="showNewTicket()">+ Нове звернення</button>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
function showNewTicket() {
  location.href = '?new=1';
}
// Scroll to bottom
const mw = document.getElementById('messagesWrap');
if (mw) mw.scrollTop = mw.scrollHeight;

// ── Авто-оновлення: перевіряємо нові повідомлення кожні 15 сек ──
(function() {
  var ticketId = <?= $viewTicketId ?: 0 ?>;
  if (!ticketId) return;

  var lastCount = <?= count($currentMessages) ?>;
  var checking  = false;

  function checkNew() {
    if (checking) return;
    checking = true;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?ajax=msgcount&ticket=' + ticketId, true);
    xhr.onload = function() {
      checking = false;
      if (xhr.status === 200) {
        var data = JSON.parse(xhr.responseText);
        if (data.count > lastCount) {
          // Є нові — перезавантажити сторінку щоб показати
          location.reload();
        }
      }
    };
    xhr.onerror = function() { checking = false; };
    xhr.send();
  }

  setInterval(checkNew, 15000);
})();
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';