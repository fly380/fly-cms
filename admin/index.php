<?php
session_start();
require_once __DIR__ . '/../admin/functions.php';

$pdo = connectToDatabase();

$stmt = $pdo->query("SELECT key, value FROM settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Статистика ────────────────────────────────────────────────────────
$stats = [];
$stats['pages']      = $pdo->query("SELECT COUNT(*) FROM pages")->fetchColumn();
$stats['posts']      = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$stats['posts_pub']  = $pdo->query("SELECT COUNT(*) FROM posts WHERE draft=0")->fetchColumn();
$stats['users']      = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stats['users_2fa']  = 0;
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (in_array('totp_enabled', $cols)) {
    $stats['users_2fa'] = $pdo->query("SELECT COUNT(*) FROM users WHERE totp_enabled=1")->fetchColumn();
}

// ── Хто онлайн (активні сесії за останні 15 хв) ───────────────────────
$online = [];
$hasSessions = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_sessions'")->fetchColumn();
if ($hasSessions) {
    $online = $pdo->query("
        SELECT DISTINCT login, ip, last_seen_at
        FROM user_sessions
        WHERE is_active = 1 AND last_seen_at > datetime('now', '-15 minutes')
        ORDER BY last_seen_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Оновлюємо last_seen для поточного адміна
    if (!empty($_SESSION['username'])) {
        $pdo->prepare("UPDATE user_sessions SET last_seen_at = datetime('now') WHERE login = ? AND is_active = 1")
            ->execute([$_SESSION['username']]);
    }
}

// ── Останні входи ─────────────────────────────────────────────────────
$recentLogins = [];
if ($hasSessions) {
    $recentLogins = $pdo->query("
        SELECT s.login, s.ip, s.logged_in_at, u.role
        FROM user_sessions s
        LEFT JOIN users u ON u.login = s.login
        ORDER BY s.logged_in_at DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Сповіщення про нові IP ─────────────────────────────────────────────
$newIpAlerts = [];
if (is_admin()) {
    $alertRows = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'new_ip_alert_%' ORDER BY key DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($alertRows as $row) {
        $data = json_decode($row['value'], true);
        if ($data) {
            $data['_key'] = $row['key'];
            $newIpAlerts[] = $data;
        }
    }
}

// ── Останні дії з лог-файлу ───────────────────────────────────────────
$recentActions = [];
$logFile = $_SERVER['DOCUMENT_ROOT'] . '/data/logs/activity.log';
if (file_exists($logFile)) {
    $lines = array_filter(explode("\n", file_get_contents($logFile)));
    $lines = array_values(array_reverse($lines));
    foreach (array_slice($lines, 0, 10) as $line) {
        if (preg_match('/^\[(.+?)\] \[(.+?)\] (.+)$/', $line, $m)) {
            $recentActions[] = ['time' => $m[1], 'user' => $m[2], 'action' => $m[3]];
        }
    }
}

// ── Нотатки для віджету дашборду ─────────────────────────────────────
$dashNotes = [];
$hasnotes = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notes'")->fetchColumn();
if ($hasnotes) {
    $nStmt = $pdo->prepare("SELECT * FROM notes
        WHERE (owner = ? OR scope = 'shared')
        ORDER BY pinned DESC, updated_at DESC LIMIT 6");
    $nStmt->execute([$_SESSION['username'] ?? '']);
    $dashNotes = $nStmt->fetchAll(PDO::FETCH_ASSOC);

    // Нагадування, що спрацювали
    $rStmt = $pdo->prepare("SELECT id FROM notes
        WHERE (owner=? OR scope='shared')
          AND remind_at IS NOT NULL
          AND remind_at <= datetime('now','localtime')
          AND reminded = 0");
    $rStmt->execute([$_SESSION['username'] ?? '']);
    $dueIds = $rStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($dueIds) {
        $ids = implode(',', $dueIds);
        $pdo->exec("UPDATE notes SET reminded=1 WHERE id IN ($ids)");
    }
}

// ── Dismiss IP alert через POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dismiss_alert'])) {
    $key = $_POST['dismiss_alert'];
    if (strpos($key, 'new_ip_alert_') === 0) {
        $pdo->prepare("DELETE FROM settings WHERE key = ?")->execute([$key]);
        header('Location: /admin/index.php'); exit;
    }
}

$page_title = 'Dashboard';
ob_start();
?>
<div class="container-fluid px-4">

    <div class="d-flex align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">👋 Dashboard</h1>
            <p class="text-muted small mt-1"><?= htmlspecialchars($settings['site_title'] ?? 'fly-CMS') ?></p>
        </div>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="text-muted small"><?= date('d.m.Y H:i') ?></span>
            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#cmsInfoModal">
                🧠 Про CMS
            </button>
        </div>
    </div>

    <!-- Модалка: Про CMS -->
    <div class="modal fade" id="cmsInfoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">🧠 Про CMS</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-4">
                            <div class="text-muted small">Назва</div>
                            <div class="fw-bold"><?= htmlspecialchars($settings['cms_name'] ?? '—') ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="text-muted small">Версія</div>
                            <div class="fw-bold"><?= htmlspecialchars($settings['cms_version'] ?? '—') ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="text-muted small">Автор</div>
                            <div class="fw-bold"><?= htmlspecialchars($settings['site_author'] ?? '—') ?></div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-bold">📝 Список змін (Changelog)</h6>
                    <div class="bg-light p-3 rounded" style="max-height:340px;overflow-y:auto;font-size:.9rem">
                        <?= $settings['cms_changelog'] ?? '<em class="text-muted">Немає даних</em>' ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Сповіщення про нові IP -->
    <?php foreach ($newIpAlerts as $alert): ?>
        <div class="alert alert-warning alert-dismissible d-flex align-items-center gap-3 shadow-sm">
            <span style="font-size:1.5rem">🚨</span>
            <div>
                <strong>Новий IP-адрес!</strong>
                Користувач <strong><?= htmlspecialchars($alert['login']) ?></strong>
                увійшов з нового IP: <code><?= htmlspecialchars($alert['ip']) ?></code>
                <span class="text-muted small ms-2"><?= htmlspecialchars($alert['time']) ?></span>
            </div>
            <form method="POST" class="ms-auto">
                <input type="hidden" name="dismiss_alert" value="<?= htmlspecialchars($alert['_key']) ?>">
                <button type="submit" class="btn-close" title="Закрити"></button>
            </form>
        </div>
    <?php endforeach; ?>

    <!-- Статистика -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-sm-3">
            <a href="/admin/create_page.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-primary"><?= $stats['pages'] ?></div>
                    <div class="text-muted small">📄 Сторінок</div>
                </div>
            </div></a>
        </div>
        <div class="col-6 col-sm-3">
            <a href="/admin/create_post.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-success"><?= $stats['posts_pub'] ?></div>
                    <div class="text-muted small">📝 Записів</div>
                </div>
            </div></a>
        </div>
        <div class="col-6 col-sm-3">
            <a href="/admin/user_list.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-warning"><?= $stats['users'] ?></div>
                    <div class="text-muted small">👥 Користувачів</div>
                </div>
            </div></a>
        </div>
        <div class="col-6 col-sm-3">
            <a href="/admin/totp_users.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-<?= $stats['users_2fa'] > 0 ? 'success' : 'secondary' ?>"><?= $stats['users_2fa'] ?></div>
                    <div class="text-muted small">🔐 З 2FA</div>
                </div>
            </div></a>
        </div>
    </div>

    <div class="row g-4">

        <!-- Хто онлайн -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <strong>🟢 Зараз онлайн</strong>
                    <span class="badge bg-success ms-2"><?= count($online) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($online)): ?>
                        <p class="text-muted text-center py-4 small">Нікого немає</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($online as $o):
                                $roleMap = ['admin'=>'danger','redaktor'=>'warning','user'=>'secondary'];
                                $u = $pdo->prepare("SELECT role FROM users WHERE login = ?");
                                $u->execute([$o['login']]);
                                $role = $u->fetchColumn() ?: 'user';
                            ?>
                            <li class="list-group-item d-flex align-items-center gap-2 py-2">
                                <span class="badge bg-<?= $roleMap[$role] ?? 'secondary' ?> flex-shrink-0">
                                    <?= htmlspecialchars($o['login']) ?>
                                </span>
                                <span class="text-muted small text-truncate"><?= htmlspecialchars($o['ip']) ?></span>
                                <span class="ms-auto text-muted" style="font-size:.7rem;white-space:nowrap">
                                    <?= date('H:i', strtotime($o['last_seen_at'])) ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Останні входи -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <strong>🔑 Останні входи</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentLogins)): ?>
                        <p class="text-muted text-center py-4 small">Немає даних</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recentLogins as $rl):
                                $roleMap = ['admin'=>'danger','redaktor'=>'warning','user'=>'secondary'];
                                $rc = $roleMap[$rl['role'] ?? 'user'] ?? 'secondary';
                            ?>
                            <li class="list-group-item py-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-<?= $rc ?>"><?= htmlspecialchars($rl['login']) ?></span>
                                    <code class="small text-muted"><?= htmlspecialchars($rl['ip']) ?></code>
                                    <span class="ms-auto text-muted" style="font-size:.7rem">
                                        <?= date('d.m H:i', strtotime($rl['logged_in_at'])) ?>
                                    </span>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Останні дії -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex align-items-center">
                    <strong>📋 Останні дії</strong>
                    <a href="/admin/logs.php" class="btn btn-outline-secondary btn-sm ms-auto">Всі логи</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentActions)): ?>
                        <p class="text-muted text-center py-4 small">Немає даних</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recentActions as $ra): ?>
                            <li class="list-group-item py-2">
                                <div class="small fw-bold text-truncate"><?= htmlspecialchars($ra['action']) ?></div>
                                <div class="d-flex gap-2 mt-1">
                                    <span class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($ra['user']) ?></span>
                                    <span class="ms-auto text-muted" style="font-size:.72rem"><?= htmlspecialchars(substr($ra['time'],5,11)) ?></span>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Нотатки ─────────────────────────────────────────────────── -->
    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 d-flex align-items-center">
                    <strong>📝 Нотатки</strong>
                    <?php if (!empty($dueIds)): ?>
                    <span class="badge bg-danger ms-2"><?= count($dueIds) ?> нагадувань!</span>
                    <?php endif; ?>
                    <a href="/admin/notes.php" class="btn btn-outline-secondary btn-sm ms-auto">Всі нотатки</a>
                    <a href="/admin/notes.php" class="btn btn-primary btn-sm ms-2">＋ Нова</a>
                </div>
                <div class="card-body">
                    <?php
                    $noteColorMap = [
                        'yellow' => ['bg'=>'#fff9c4','border'=>'#f9a825'],
                        'blue'   => ['bg'=>'#e3f2fd','border'=>'#1565c0'],
                        'green'  => ['bg'=>'#e8f5e9','border'=>'#2e7d32'],
                        'red'    => ['bg'=>'#ffebee','border'=>'#c62828'],
                        'purple' => ['bg'=>'#f3e5f5','border'=>'#6a1b9a'],
                        'gray'   => ['bg'=>'#f5f5f5','border'=>'#616161'],
                    ];
                    if (empty($dashNotes)): ?>
                        <p class="text-muted text-center py-3 small mb-0">Нотаток немає. <a href="/admin/notes.php">Створити першу →</a></p>
                    <?php else: ?>
                    <div class="row g-2">
                        <?php foreach($dashNotes as $dn):
                            $cm = $noteColorMap[$dn['color']] ?? $noteColorMap['yellow'];
                            $isPinned = (bool)$dn['pinned'];
                            $isShared = $dn['scope'] === 'shared';
                        ?>
                        <div class="col-sm-6 col-md-4 col-xl-2">
                            <div style="background:<?= $cm['bg'] ?>;border-left:3px solid <?= $cm['border'] ?>;border-radius:8px;padding:10px 12px;height:100%;min-height:80px">
                                <div class="d-flex align-items-start gap-1 mb-1">
                                    <small class="fw-bold text-truncate flex-grow-1"
                                           style="font-size:.8rem">
                                        <?= $isPinned?'📌 ':'' ?><?= htmlspecialchars($dn['title'] ?: '(без заголовку)') ?>
                                    </small>
                                    <?php if ($isShared): ?>
                                    <span class="badge bg-primary flex-shrink-0" style="font-size:.6rem">Спільна</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($dn['body']): ?>
                                <p class="mb-0 text-muted" style="font-size:.72rem;white-space:pre-wrap;overflow:hidden;max-height:48px"><?= htmlspecialchars(mb_substr($dn['body'],0,80)) ?><?= mb_strlen($dn['body'])>80?'…':'' ?></p>
                                <?php endif; ?>
                                <?php if ($dn['remind_at'] && !$dn['reminded']): ?>
                                <div class="mt-1" style="font-size:.68rem;color:#e65100">⏰ <?= date('d.m H:i', strtotime($dn['remind_at'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';