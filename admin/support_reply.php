<?php
/**
 * admin/support_reply.php
 * Сторінка відповіді розробника на тікет підтримки.
 * Доступ через токен з листа — без логіну.
 * URL: /admin/support_reply.php?token=XXXX
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/smtp_helper.php';

$pdo = fly_db();

// ─── Ініціалізація — додати reply_token якщо немає ───────────────
$cols = array_column($pdo->query("PRAGMA table_info(support_tickets)")->fetchAll(), 'name');
if (!in_array('reply_token', $cols)) {
    $pdo->exec("ALTER TABLE support_tickets ADD COLUMN reply_token TEXT DEFAULT NULL");
}

$token   = trim($_GET['token'] ?? '');
$flash   = '';
$success = false;

if (!$token) {
    http_response_code(404);
    die('<h2>Посилання недійсне.</h2>');
}

// ─── Знайти тікет за токеном ─────────────────────────────────────
$st = $pdo->prepare("SELECT * FROM support_tickets WHERE reply_token = ?");
$st->execute([$token]);
$ticket = $st->fetch();

if (!$ticket) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Не знайдено</title>'
      . '<style>body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f4fb;margin:0}'
      . '.box{background:#fff;border-radius:12px;padding:40px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.08);max-width:400px}'
      . '</style></head><body><div class="box"><div style="font-size:3rem">🔒</div>'
      . '<h2>Посилання недійсне або вже використане</h2>'
      . '<p style="color:#6b7280">Кожне посилання одноразове.<br>Нове посилання буде в наступному листі.</p></div></body></html>');
}

// ─── Повідомлення тікета ──────────────────────────────────────────
$msgs = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at ASC");
$msgs->execute([$ticket['id']]);
$messages = $msgs->fetchAll();

// ─── Обробка відповіді ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = trim($_POST['body'] ?? '');
    $devName  = trim($_POST['dev_name'] ?? 'Розробник');

    if (!$body) {
        $flash = 'Введіть текст відповіді.';
    } else {
        // Зберегти повідомлення
        $pdo->prepare("INSERT INTO support_messages (ticket_id, sender, sender_type, body) VALUES (?, ?, 'support', ?)")
            ->execute([$ticket['id'], $devName, $body]);

        // Оновити тікет — скинути токен (одноразовий), оновити час
        $pdo->prepare("UPDATE support_tickets SET reply_token=NULL, updated_at=datetime('now'), status='open' WHERE id=?")
            ->execute([$ticket['id']]);

        // Надіслати email адміну що є відповідь
        $siteName = function_exists('get_setting') ? (get_setting('site_name') ?: get_setting('site_title') ?: 'fly-CMS') : 'fly-CMS';
        support_reply_notify($ticket, $body, $devName, $siteName);

        $success = true;
    }
}

// ─── Нотифікація адміну про відповідь розробника ─────────────────
function support_reply_notify(array $ticket, string $body, string $devName, string $siteName): void {
    $smtpCfg = @include __DIR__ . '/email_config.php';
    $smtpArr = is_array($smtpCfg) ? (isset($smtpCfg['smtp']) ? $smtpCfg['smtp'] : []) : [];
    if (empty($smtpArr['enabled']) || empty($smtpArr['host']) || empty($smtpArr['username']) || empty($smtpArr['password'])) return;

    $host       = $smtpArr['host'];
    $port       = (int)(isset($smtpArr['port']) ? $smtpArr['port'] : 587);
    $smtpUser   = $smtpArr['username'];
    $smtpPass   = $smtpArr['password'];
    $encryption = isset($smtpArr['encryption']) ? $smtpArr['encryption'] : 'tls';
    $fromEmail  = isset($smtpArr['from_email']) ? $smtpArr['from_email'] : $smtpUser;
    $fromName   = isset($smtpArr['from_name'])  ? $smtpArr['from_name']  : 'fly-CMS';

    // Знайти email адміна (SMTP логін як відправник = і як одержувач нотифікації)
    $to = $smtpUser;

    $subject   = '[' . $siteName . ' Support #' . $ticket['uid'] . '] Відповідь розробника: ' . $ticket['subject'];
    $bodyEsc   = nl2br(htmlspecialchars($body));
    $uid       = $ticket['uid'];
    $subj      = htmlspecialchars($ticket['subject']);
    $cmsUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
               . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
               . '/admin/support.php?ticket=' . $ticket['id'];

    $body_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,sans-serif;background:#f0f4fb;padding:20px">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">'
        . '<div style="background:linear-gradient(135deg,#1a3d6e,#2E5FA3);color:#fff;padding:24px 32px">'
        . '<div style="opacity:.8;font-size:.85rem">fly-CMS &middot; Технічна підтримка</div>'
        . '<div style="font-size:1.1rem;font-weight:700;margin-top:4px">Відповідь розробника</div>'
        . '<div style="opacity:.7;font-size:.8rem;font-family:monospace;margin-top:2px">Тікет #' . $uid . ' &middot; ' . $subj . '</div>'
        . '</div>'
        . '<div style="padding:24px 32px">'
        . '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.82rem;color:#166534">'
        . '✅ Розробник відповів на ваше звернення. Відповідь збережена в CMS.'
        . '</div>'
        . '<div style="background:#f3f4f6;border-left:4px solid #2E5FA3;border-radius:0 8px 8px 0;padding:16px 20px;line-height:1.7;color:#1f2937;font-size:.9rem">'
        . $bodyEsc
        . '</div>'
        . '<div style="margin-top:20px">'
        . '<a href="' . htmlspecialchars($cmsUrl) . '" style="display:inline-block;background:#1e3a6e;color:#fff;text-decoration:none;padding:10px 24px;border-radius:8px;font-weight:600;font-size:.9rem">'
        . '📂 Відкрити тікет в CMS</a>'
        . '</div></div>'
        . '<div style="padding:14px 32px;background:#f3f4f6;font-size:.75rem;color:#9ca3af">'
        . $siteName . ' &middot; Підтримка</div>'
        . '</div></body></html>';

    $body_text = "Розробник відповів на тікет #{$ticket['uid']}.\n\nТема: {$ticket['subject']}\n\n---\n{$body}\n---\n\nВідкрити в CMS: {$cmsUrl}";

    fly_smtp_send($to, $subject, $body_html, $body_text);
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>fly-CMS · Відповідь на тікет</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Arial,sans-serif; background:#f0f4fb; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.wrap { width:100%; max-width:680px; }
.card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,.1); }
.card-head { background:linear-gradient(135deg,#1a3d6e,#2E5FA3); color:#fff; padding:28px 32px; }
.card-head .label { font-size:.8rem; opacity:.75; margin-bottom:4px; }
.card-head .title { font-size:1.2rem; font-weight:700; }
.card-head .meta  { font-family:monospace; font-size:.78rem; opacity:.65; margin-top:4px; }
.card-body { padding:28px 32px; }
.history { margin-bottom:24px; display:flex; flex-direction:column; gap:10px; max-height:320px; overflow-y:auto; }
.msg { display:flex; gap:10px; }
.msg.msg-user    { flex-direction:row-reverse; }
.msg-avatar { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; font-weight:700; }
.msg-user    .msg-avatar { background:#1e3a6e; color:#fff; }
.msg-support .msg-avatar { background:#e0e7ff; color:#2E5FA3; }
.msg-inner { flex:1; }
.msg-meta  { font-size:.72rem; color:#9ca3af; margin-bottom:3px; }
.msg-user .msg-meta { text-align:right; }
.msg-bubble { padding:10px 14px; border-radius:12px; font-size:.88rem; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
.msg-user    .msg-bubble { background:#1e3a6e; color:#fff; border-radius:12px 12px 0 12px; }
.msg-support .msg-bubble { background:#f3f4f6; color:#1f2937; border-radius:12px 12px 12px 0; border:1px solid #e5e7eb; }
.divider { border:none; border-top:1px solid #e5e7eb; margin:20px 0; }
.form-group { margin-bottom:16px; }
label { display:block; font-weight:600; font-size:.85rem; color:#374151; margin-bottom:6px; }
input, textarea { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px 14px; font-size:.9rem; font-family:Arial,sans-serif; transition:border-color .15s; }
input:focus, textarea:focus { outline:none; border-color:#2E5FA3; box-shadow:0 0 0 3px rgba(46,95,163,.15); }
textarea { resize:vertical; min-height:140px; }
.btn { display:inline-block; background:#1e3a6e; color:#fff; border:none; padding:12px 28px; border-radius:8px; font-size:.95rem; font-weight:700; cursor:pointer; transition:background .15s; }
.btn:hover { background:#2E5FA3; }
.alert-err { background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:12px 16px; color:#991b1b; font-size:.85rem; margin-bottom:16px; }
.success-box { text-align:center; padding:32px; }
.success-box .ico { font-size:3.5rem; margin-bottom:12px; }
.success-box h2 { color:#166534; margin-bottom:8px; }
.success-box p  { color:#6b7280; font-size:.9rem; }
.ticket-closed { background:#fff8e1; border:1px solid #f59e0b; border-radius:8px; padding:12px 16px; color:#92400e; font-size:.85rem; margin-bottom:16px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="card-head">
      <div class="label">fly-CMS &middot; Технічна підтримка</div>
      <div class="title">Відповідь на звернення</div>
      <div class="meta">
        #<?= htmlspecialchars($ticket['uid']) ?> &middot;
        <?= htmlspecialchars($ticket['subject']) ?> &middot;
        Автор: <?= htmlspecialchars($ticket['author']) ?>
      </div>
    </div>
    <div class="card-body">

      <?php if ($success): ?>
      <div class="success-box">
        <div class="ico">✅</div>
        <h2>Відповідь збережено!</h2>
        <p>Адміністратора сайту сповіщено листом.<br>Відповідь з'явилася в CMS.</p>
      </div>

      <?php else: ?>

      <?php if ($flash): ?>
      <div class="alert-err"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <?php if ($ticket['status'] === 'closed'): ?>
      <div class="ticket-closed">⚠ Тікет закрито адміністратором, але ви все одно можете відповісти.</div>
      <?php endif; ?>

      <!-- Історія переписки -->
      <?php if (!empty($messages)): ?>
      <div class="history">
        <?php foreach ($messages as $m):
            $isUser = $m['sender_type'] === 'user';
        ?>
        <div class="msg <?= $isUser ? 'msg-user' : 'msg-support' ?>">
          <div class="msg-avatar"><?= $isUser ? htmlspecialchars(strtoupper(substr($m['sender'],0,1))) : '🛠' ?></div>
          <div class="msg-inner">
            <div class="msg-meta">
              <?= htmlspecialchars($m['sender']) ?> &middot;
              <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?>
            </div>
            <div class="msg-bubble"><?= htmlspecialchars($m['body']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <hr class="divider">
      <?php endif; ?>

      <!-- Форма відповіді -->
      <form method="post">
        <div class="form-group">
          <label>Ваше ім'я</label>
          <input type="text" name="dev_name" value="Розробник" maxlength="60">
        </div>
        <div class="form-group">
          <label>Відповідь <span style="color:#ef4444">*</span></label>
          <textarea name="body" placeholder="Напишіть відповідь на звернення..." required></textarea>
        </div>
        <button type="submit" class="btn">📨 Надіслати відповідь</button>
      </form>

      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>
