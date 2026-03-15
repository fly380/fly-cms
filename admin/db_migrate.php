<?php
// admin/db_migrate.php — Міграція SQLite → MySQL
// Доступ: тільки superadmin

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: /templates/login.php');
    exit;
}

require_once __DIR__ . '/../admin/functions.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/../data/DbDriver.php';

fly_send_security_headers();

$username = $_SESSION['username'] ?? 'superadmin';
$ROOT     = rtrim($_SERVER['DOCUMENT_ROOT'], '/');

// ── Читаємо поточний стан з .env ─────────────────────────────────
$currentDriver = strtolower(env('DB_DRIVER', 'sqlite'));
$envPath = null;
foreach ([FLY_STORAGE_ROOT . '/.env', FLY_ROOT . '/.env'] as $c) {
    if (file_exists($c)) { $envPath = $c; break; }
}

// ── CSRF ──────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── JSON API для AJAX-кроків ──────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF для всіх POST-дій
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $body['csrf'] ?? $_POST['csrf'] ?? '';
        if ($tok !== $_SESSION['csrf_token']) {
            echo json_encode(['ok' => false, 'error' => 'Невірний CSRF токен']);
            exit;
        }
    }

    switch ($_GET['action']) {

        // ── Тест з'єднання з MySQL ────────────────────────────────
        case 'test_connection':
            $host    = trim($body['host']    ?? '');
            $port    = (int)($body['port']   ?? 3306);
            $dbname  = trim($body['dbname']  ?? '');
            $user    = trim($body['user']    ?? '');
            $pass    = $body['pass']         ?? '';
            $charset = 'utf8mb4';

            if (!$host || !$dbname || !$user) {
                echo json_encode(['ok' => false, 'error' => 'Заповніть всі поля']);
                exit;
            }
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT          => 5,
                ]);
                $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
                echo json_encode(['ok' => true, 'version' => $ver]);
            } catch (PDOException $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        // ── Створення схеми таблиць у MySQL ──────────────────────
        case 'create_schema':
            $host   = trim($body['host']   ?? '');
            $port   = (int)($body['port']  ?? 3306);
            $dbname = trim($body['dbname'] ?? '');
            $user   = trim($body['user']   ?? '');
            $pass   = $body['pass']        ?? '';

            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $mysql = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                $schemaFile = $ROOT . '/data/mysql_schema.sql';
                if (!file_exists($schemaFile)) {
                    echo json_encode(['ok' => false, 'error' => 'Файл data/mysql_schema.sql не знайдено']);
                    exit;
                }
                $sql = file_get_contents($schemaFile);
                // Розбиваємо на окремі statements
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    fn($s) => $s !== '' && !preg_match('/^--/', $s) && !preg_match('/^SET\s/i', $s)
                );
                $created = 0;
                foreach ($statements as $stmt) {
                    if (trim($stmt)) {
                        $mysql->exec($stmt);
                        if (stripos($stmt, 'CREATE TABLE') !== false) $created++;
                    }
                }
                echo json_encode(['ok' => true, 'tables' => $created]);
            } catch (PDOException $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        // ── Перенос даних SQLite → MySQL ──────────────────────────
        case 'migrate_data':
            $host   = trim($body['host']   ?? '');
            $port   = (int)($body['port']  ?? 3306);
            $dbname = trim($body['dbname'] ?? '');
            $user   = trim($body['user']   ?? '');
            $pass   = $body['pass']        ?? '';

            $sqlitePath = FLY_STORAGE_ROOT . '/data/BD/database.sqlite';
            if (!file_exists($sqlitePath)) {
                $sqlitePath = $ROOT . '/data/BD/database.sqlite';
            }
            if (!file_exists($sqlitePath)) {
                echo json_encode(['ok' => false, 'error' => 'SQLite файл не знайдено: ' . $sqlitePath]);
                exit;
            }

            try {
                $sqlite = new PDO('sqlite:' . $sqlitePath);
                $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                $dsn   = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $mysql = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // Таблиці у правильному порядку (спочатку батьківські)
                $tables = [
                    'users','settings','theme_settings',
                    'pages','categories','tags',
                    'posts','post_categories','post_tags',
                    'menu_items','user_sessions','invitations',
                    'notes','backup_settings','backup_log','post_revisions',
                ];

                $existingInSqlite = $sqlite
                    ->query("SELECT name FROM sqlite_master WHERE type='table'")
                    ->fetchAll(PDO::FETCH_COLUMN);

                $results = [];
                $mysql->exec('SET FOREIGN_KEY_CHECKS=0');

                foreach ($tables as $table) {
                    if (!in_array($table, $existingInSqlite)) {
                        $results[$table] = ['status' => 'skip', 'count' => 0];
                        continue;
                    }
                    $rows = $sqlite->query("SELECT * FROM `{$table}`")->fetchAll();
                    if (empty($rows)) {
                        $results[$table] = ['status' => 'empty', 'count' => 0];
                        continue;
                    }
                    $cols         = array_keys($rows[0]);
                    $colList      = '`' . implode('`, `', $cols) . '`';
                    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                    $sql          = "INSERT IGNORE INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
                    $stmt         = $mysql->prepare($sql);

                    $done = 0; $errors = 0;
                    $mysql->beginTransaction();
                    foreach ($rows as $row) {
                        try {
                            $stmt->execute(array_values($row));
                            $done++;
                        } catch (PDOException $e) {
                            $errors++;
                        }
                    }
                    $mysql->commit();
                    $results[$table] = ['status' => 'ok', 'count' => $done, 'errors' => $errors];
                }

                $mysql->exec('SET FOREIGN_KEY_CHECKS=1');

                log_action($username, "Міграція даних SQLite→MySQL: БД {$dbname}@{$host}");
                echo json_encode(['ok' => true, 'results' => $results]);

            } catch (PDOException $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        // ── Збереження MySQL параметрів у .env ───────────────────
        case 'save_env':
            $host   = trim($body['host']   ?? '');
            $port   = trim($body['port']   ?? '3306');
            $dbname = trim($body['dbname'] ?? '');
            $user   = trim($body['user']   ?? '');
            $pass   = $body['pass']        ?? '';
            $driver = trim($body['driver'] ?? 'sqlite'); // 'mysql' або 'sqlite'

            if (!$envPath) {
                // Якщо .env не існує — створюємо у FLY_STORAGE_ROOT
                $envPath = FLY_STORAGE_ROOT . '/.env';
                if (!is_dir(dirname($envPath))) {
                    @mkdir(dirname($envPath), 0750, true);
                }
                file_put_contents($envPath, '');
            }

            $lines   = file($envPath, FILE_IGNORE_NEW_LINES);
            $updated = [];
            $keys    = ['DB_DRIVER','DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','DB_CHARSET'];
            $new     = [
                'DB_DRIVER'  => $driver,
                'DB_HOST'    => $host,
                'DB_PORT'    => $port,
                'DB_NAME'    => $dbname,
                'DB_USER'    => $user,
                'DB_PASS'    => $pass,
                'DB_CHARSET' => 'utf8mb4',
            ];

            // Оновлюємо існуючі рядки
            $seen = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                $matched = false;
                foreach ($keys as $k) {
                    if (str_starts_with($trimmed, $k . '=') || $trimmed === $k) {
                        $updated[] = $k . '=' . $new[$k];
                        $seen[$k]  = true;
                        $matched   = true;
                        break;
                    }
                }
                if (!$matched) $updated[] = $line;
            }
            // Додаємо нові ключі яких ще не було
            $needHeader = !in_array('# ── База даних ────────', $updated);
            $toAdd = [];
            foreach ($keys as $k) {
                if (empty($seen[$k])) $toAdd[] = $k . '=' . $new[$k];
            }
            if ($toAdd) {
                $updated[] = '';
                $updated[] = '# ── База даних ──────────────────────────────';
                foreach ($toAdd as $l) $updated[] = $l;
            }

            file_put_contents($envPath, implode("\n", $updated) . "\n");
            log_action($username, "DB_DRIVER перемкнуто на: {$driver}");
            echo json_encode(['ok' => true, 'driver' => $driver]);
            exit;

        // ── Перевірка поточного активного драйвера ───────────────
        case 'current_status':
            $driver = strtolower(env('DB_DRIVER', 'sqlite'));
            $info   = [];
            if ($driver === 'mysql') {
                try {
                    $pdo = fly_db();
                    $info['version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
                    $info['db']      = env('DB_NAME');
                    $info['host']    = env('DB_HOST');
                } catch (Exception $e) {
                    $info['error'] = $e->getMessage();
                }
            }
            echo json_encode(['ok' => true, 'driver' => $driver, 'info' => $info]);
            exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Невідома дія']);
    exit;
}

// ── HTML ──────────────────────────────────────────────────────────
$page_title = '🗄️ Міграція бази даних';
ob_start();
?>
<div class="container-fluid px-4 py-2">

  <div class="d-flex align-items-center mb-4 gap-3">
    <div>
      <h1 class="h3 mb-0">🗄️ Міграція бази даних</h1>
      <p class="text-muted small mt-1">Перехід з SQLite на MySQL без втрати даних</p>
    </div>
    <div class="ms-auto">
      <span id="driverBadge" class="badge fs-6 px-3 py-2
        <?= $currentDriver === 'mysql' ? 'bg-success' : 'bg-secondary' ?>">
        <?= $currentDriver === 'mysql' ? '🟢 MySQL' : '🔵 SQLite' ?>
      </span>
    </div>
  </div>

  <!-- Поточний статус -->
  <div id="statusCard" class="alert <?= $currentDriver === 'mysql' ? 'alert-success' : 'alert-info' ?> mb-4">
    <?php if ($currentDriver === 'mysql'): ?>
      <strong>✅ CMS працює на MySQL.</strong>
      Хост: <code><?= htmlspecialchars(env('DB_HOST','—')) ?></code>,
      БД: <code><?= htmlspecialchars(env('DB_NAME','—')) ?></code>
      <button class="btn btn-sm btn-outline-danger ms-3" id="btnRollback">
        ↩ Повернутися до SQLite
      </button>
    <?php else: ?>
      <strong>ℹ️ CMS працює на SQLite.</strong>
      Використовуйте майстер нижче щоб перейти на MySQL.
    <?php endif; ?>
  </div>

  <!-- Wizard -->
  <div class="card shadow-sm" id="wizardCard" <?= $currentDriver === 'mysql' ? 'style="display:none"' : '' ?>>
    <div class="card-header bg-white py-3 d-flex align-items-center">
      <strong>⚡ Майстер переходу на MySQL</strong>
      <span class="ms-auto text-muted small">Кроки виконуються послідовно</span>
    </div>
    <div class="card-body p-0">

      <!-- Прогрес -->
      <div class="px-4 pt-4 pb-2">
        <div class="d-flex justify-content-between mb-1">
          <?php foreach ([
            '1' => 'З\'єднання',
            '2' => 'Схема',
            '3' => 'Дані',
            '4' => 'Перемикання',
          ] as $n => $label): ?>
          <div class="text-center" style="flex:1">
            <div class="step-circle mx-auto mb-1" id="stepCircle<?= $n ?>"><?= $n ?></div>
            <div class="step-label small text-muted" id="stepLabel<?= $n ?>"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="progress mb-4" style="height:4px">
          <div class="progress-bar bg-primary" id="wizardProgress" style="width:0%"></div>
        </div>
      </div>

      <!-- КРОК 1: Параметри з'єднання -->
      <div class="wizard-step px-4 pb-4" id="step1">
        <h5 class="mb-3">Крок 1 — Параметри MySQL з'єднання</h5>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Хост</label>
            <input type="text" class="form-control" id="dbHost" value="127.0.0.1" placeholder="127.0.0.1 або mysql.example.com">
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-semibold">Порт</label>
            <input type="number" class="form-control" id="dbPort" value="3306">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Назва бази даних</label>
            <input type="text" class="form-control" id="dbName" placeholder="flycms">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Користувач MySQL</label>
            <input type="text" class="form-control" id="dbUser" placeholder="flycms_user">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Пароль</label>
            <div class="input-group">
              <input type="password" class="form-control" id="dbPass" placeholder="••••••••">
              <button class="btn btn-outline-secondary" type="button" id="togglePass">👁</button>
            </div>
          </div>
        </div>
        <div id="connResult" class="mt-3"></div>
        <div class="mt-4">
          <button class="btn btn-primary" id="btnTestConn">
            <span class="spinner-border spinner-border-sm d-none me-2" id="spinConn"></span>
            🔌 Перевірити з'єднання
          </button>
        </div>
      </div>

      <!-- КРОК 2: Схема -->
      <div class="wizard-step px-4 pb-4 d-none" id="step2">
        <h5 class="mb-3">Крок 2 — Створення таблиць</h5>
        <p class="text-muted small">Буде виконано <code>mysql_schema.sql</code> — створяться всі таблиці CMS у вашій MySQL базі.</p>
        <div class="alert alert-warning small">
          <strong>⚠ Увага:</strong> Якщо таблиці вже існують — вони не будуть змінені (<code>CREATE TABLE IF NOT EXISTS</code>). Існуючі дані не видаляються.
        </div>
        <div id="schemaResult" class="mb-3"></div>
        <button class="btn btn-primary" id="btnCreateSchema">
          <span class="spinner-border spinner-border-sm d-none me-2" id="spinSchema"></span>
          🏗 Створити таблиці
        </button>
        <button class="btn btn-outline-secondary ms-2" id="btnBackToStep1">← Назад</button>
      </div>

      <!-- КРОК 3: Перенос даних -->
      <div class="wizard-step px-4 pb-4 d-none" id="step3">
        <h5 class="mb-3">Крок 3 — Перенос даних</h5>
        <p class="text-muted small">Всі дані з вашої SQLite бази будуть скопійовані до MySQL. Використовується <code>INSERT IGNORE</code> — якщо запис вже є, він пропускається.</p>
        <div id="migrateProgress" class="mb-3 d-none">
          <div class="progress mb-2" style="height:8px">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="dataProgressBar" style="width:0%"></div>
          </div>
          <div id="migrateLog" class="bg-dark text-success rounded p-3 font-monospace small" style="max-height:280px;overflow-y:auto;font-size:.78rem"></div>
        </div>
        <div id="migrateResult" class="mb-3"></div>
        <button class="btn btn-success" id="btnMigrate">
          <span class="spinner-border spinner-border-sm d-none me-2" id="spinMigrate"></span>
          🚀 Перенести дані
        </button>
        <button class="btn btn-outline-secondary ms-2" id="btnBackToStep2">← Назад</button>
      </div>

      <!-- КРОК 4: Перемикання -->
      <div class="wizard-step px-4 pb-4 d-none" id="step4">
        <h5 class="mb-3">Крок 4 — Перемикання на MySQL</h5>
        <div class="alert alert-success">
          <strong>✅ Дані перенесено!</strong> Тепер можна перемкнути CMS на MySQL.
          Параметри з'єднання будуть збережені у <code>.env</code>.
        </div>
        <p class="text-muted small">Після натискання кнопки в <code>.env</code> запишеться <code>DB_DRIVER=mysql</code> і CMS одразу почне працювати з MySQL. Відкат займає 5 секунд — кнопка "Повернутися до SQLite" буде доступна після.</p>
        <div id="switchResult" class="mb-3"></div>
        <button class="btn btn-danger btn-lg" id="btnSwitch">
          <span class="spinner-border spinner-border-sm d-none me-2" id="spinSwitch"></span>
          ⚡ Перемкнути на MySQL
        </button>
        <button class="btn btn-outline-secondary ms-2" id="btnBackToStep3">← Назад</button>
      </div>

    </div><!-- /card-body -->
  </div><!-- /wizardCard -->

  <!-- Rollback card (завжди видима якщо MySQL активний) -->
  <div class="card shadow-sm mt-4 border-warning" id="rollbackCard" <?= $currentDriver !== 'mysql' ? 'style="display:none"' : '' ?>>
    <div class="card-header bg-warning-subtle py-3">
      <strong>↩ Повернення до SQLite</strong>
    </div>
    <div class="card-body">
      <p class="mb-3 text-muted small">CMS одразу перемкнеться назад на SQLite. MySQL з'єднання збережеться у <code>.env</code> для майбутнього використання.</p>
      <div id="rollbackResult" class="mb-3"></div>
      <button class="btn btn-warning" id="btnRollback2">
        <span class="spinner-border spinner-border-sm d-none me-2" id="spinRollback"></span>
        ↩ Повернутися до SQLite
      </button>
    </div>
  </div>

</div><!-- /container -->

<style>
.step-circle {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: #e9ecef;
  color: #6c757d;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: .9rem;
  transition: background .3s, color .3s;
}
.step-circle.active  { background: #0d6efd; color: #fff; }
.step-circle.done    { background: #198754; color: #fff; }
.step-circle.done::after { content: '✓'; position: absolute; }
.wizard-step { border-top: 1px solid #f0f0f0; padding-top: 1.5rem; }
</style>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

// ── Хелпери ───────────────────────────────────────────────────────
function getConn() {
  return {
    host:   document.getElementById('dbHost').value.trim(),
    port:   document.getElementById('dbPort').value.trim(),
    dbname: document.getElementById('dbName').value.trim(),
    user:   document.getElementById('dbUser').value.trim(),
    pass:   document.getElementById('dbPass').value,
    csrf:   CSRF,
  };
}

function api(action, data) {
  return fetch(`/admin/db_migrate.php?action=${action}`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(data),
  }).then(r => r.json());
}

function alertHtml(type, msg) {
  return `<div class="alert alert-${type} py-2 small mb-0">${msg}</div>`;
}

function spin(id, on) {
  document.getElementById(id).classList.toggle('d-none', !on);
}

// ── Прогрес wizard ────────────────────────────────────────────────
function setProgress(step) {
  const pct = [0, 25, 50, 75, 100];
  document.getElementById('wizardProgress').style.width = pct[step] + '%';
  for (let i = 1; i <= 4; i++) {
    const c = document.getElementById('stepCircle' + i);
    c.classList.remove('active','done');
    if (i < step)  c.classList.add('done');
    if (i === step) c.classList.add('active');
  }
}

function goStep(n) {
  document.querySelectorAll('.wizard-step').forEach(s => s.classList.add('d-none'));
  document.getElementById('step' + n).classList.remove('d-none');
  setProgress(n);
}
goStep(1);

// ── Показ пароля ──────────────────────────────────────────────────
document.getElementById('togglePass').addEventListener('click', function() {
  const f = document.getElementById('dbPass');
  f.type = f.type === 'password' ? 'text' : 'password';
  this.textContent = f.type === 'password' ? '👁' : '🙈';
});

// ── Крок 1: Тест з'єднання ────────────────────────────────────────
document.getElementById('btnTestConn').addEventListener('click', async function() {
  const el = document.getElementById('connResult');
  spin('spinConn', true); this.disabled = true;
  el.innerHTML = '';
  try {
    const r = await api('test_connection', getConn());
    if (r.ok) {
      el.innerHTML = alertHtml('success', `✅ З'єднання успішне! MySQL ${r.version}`);
      setTimeout(() => goStep(2), 800);
    } else {
      el.innerHTML = alertHtml('danger', '❌ ' + r.error);
    }
  } catch(e) {
    el.innerHTML = alertHtml('danger', '❌ Помилка запиту: ' + e.message);
  }
  spin('spinConn', false); this.disabled = false;
});

// ── Крок 2: Схема ─────────────────────────────────────────────────
document.getElementById('btnCreateSchema').addEventListener('click', async function() {
  const el = document.getElementById('schemaResult');
  spin('spinSchema', true); this.disabled = true;
  el.innerHTML = '';
  try {
    const r = await api('create_schema', getConn());
    if (r.ok) {
      el.innerHTML = alertHtml('success', `✅ Таблиці створено (${r.tables} шт.)`);
      setTimeout(() => goStep(3), 800);
    } else {
      el.innerHTML = alertHtml('danger', '❌ ' + r.error);
    }
  } catch(e) {
    el.innerHTML = alertHtml('danger', '❌ ' + e.message);
  }
  spin('spinSchema', false); this.disabled = false;
});

// ── Крок 3: Перенос даних ─────────────────────────────────────────
document.getElementById('btnMigrate').addEventListener('click', async function() {
  const el    = document.getElementById('migrateResult');
  const log   = document.getElementById('migrateLog');
  const prog  = document.getElementById('migrateProgress');
  const bar   = document.getElementById('dataProgressBar');
  spin('spinMigrate', true); this.disabled = true;
  el.innerHTML = ''; log.innerHTML = ''; prog.classList.remove('d-none');

  try {
    const r = await api('migrate_data', getConn());
    if (r.ok) {
      const tables = Object.entries(r.results);
      let done = 0, total = 0;
      tables.forEach(([t, res]) => {
        total += (res.count || 0);
        let icon = res.status === 'ok' ? '✅' : (res.status === 'skip' ? '⏭' : '—');
        let line = `${icon} ${t}: `;
        if (res.status === 'ok')    line += `${res.count} рядків`;
        else if (res.status === 'skip') line += 'пропущено (немає в SQLite)';
        else line += 'порожня';
        if (res.errors > 0) line += ` ⚠ ${res.errors} помилок`;
        log.innerHTML += line + '\n';
        done++;
        bar.style.width = Math.round(done / tables.length * 100) + '%';
      });
      bar.classList.remove('progress-bar-animated');
      log.innerHTML += `\n✔ Всього перенесено: ${total} рядків`;
      log.scrollTop = log.scrollHeight;
      el.innerHTML = alertHtml('success', `✅ Дані перенесено! Всього рядків: ${total}`);
      setTimeout(() => goStep(4), 1200);
    } else {
      el.innerHTML = alertHtml('danger', '❌ ' + r.error);
    }
  } catch(e) {
    el.innerHTML = alertHtml('danger', '❌ ' + e.message);
  }
  spin('spinMigrate', false); this.disabled = false;
});

// ── Крок 4: Перемикання ───────────────────────────────────────────
document.getElementById('btnSwitch').addEventListener('click', async function() {
  if (!confirm('Перемкнути CMS на MySQL? Сайт одразу почне використовувати нову БД.')) return;
  const el = document.getElementById('switchResult');
  spin('spinSwitch', true); this.disabled = true;
  try {
    const conn = getConn();
    conn.driver = 'mysql';
    const r = await api('save_env', conn);
    if (r.ok) {
      el.innerHTML = alertHtml('success', '✅ CMS перемкнуто на MySQL! Оновлюємо сторінку...');
      setProgress(5);
      setTimeout(() => location.reload(), 1500);
    } else {
      el.innerHTML = alertHtml('danger', '❌ ' + r.error);
    }
  } catch(e) {
    el.innerHTML = alertHtml('danger', '❌ ' + e.message);
  }
  spin('spinSwitch', false); this.disabled = false;
});

// ── Rollback ──────────────────────────────────────────────────────
async function doRollback() {
  if (!confirm('Повернутися до SQLite? CMS одразу перемкнеться назад.')) return;
  const el = document.getElementById('rollbackResult');
  spin('spinRollback', true);
  try {
    const conn = getConn();
    conn.driver = 'sqlite';
    const r = await api('save_env', conn);
    if (r.ok) {
      el.innerHTML = alertHtml('success', '✅ Повернуто до SQLite! Оновлюємо...');
      setTimeout(() => location.reload(), 1200);
    } else {
      el.innerHTML = alertHtml('danger', '❌ ' + r.error);
    }
  } catch(e) {
    el.innerHTML = alertHtml('danger', '❌ ' + e.message);
  }
  spin('spinRollback', false);
}
document.getElementById('btnRollback2').addEventListener('click', doRollback);
const rb1 = document.getElementById('btnRollback');
if (rb1) rb1.addEventListener('click', doRollback);

// ── Кнопки "Назад" ────────────────────────────────────────────────
document.getElementById('btnBackToStep1').addEventListener('click', () => goStep(1));
document.getElementById('btnBackToStep2').addEventListener('click', () => goStep(2));
document.getElementById('btnBackToStep3').addEventListener('click', () => goStep(3));
</script>
<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
