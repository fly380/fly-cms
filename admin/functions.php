<?php

// ── Підключаємо центральну конфігурацію ───────────────────────────
// config.php визначає: fly_send_security_headers(), fly_db(), get_setting(), ts()
// fly_send_security_headers() викликається в кожному ентрипоінті окремо.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

// ── Composer autoload ─────────────────────────────────────────────
// Підключається один раз тут — всі admin/*.php отримують автозавантаження
// через ланцюжок: admin/*.php → functions.php → vendor/autoload.php
$__autoload = $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
if (file_exists($__autoload)) {
    require_once $__autoload;
}
unset($__autoload);

/**
 * Підключення до бази даних SQLite.
 *
 * Тепер є тонка обгортка над fly_db() з config.php:
 *  - fly_db() — глобальний singleton для основної БД.
 *  - $path за замовчуванням веде до тієї самої БД.
 *  - Нестандартний $path (інша БД) — відкривається окремо.
 *  - Міграції запускаються один раз через lock-файл.
 *
 * @param string $path Шлях до БД відносно DOCUMENT_ROOT
 * @return PDO
 */
function connectToDatabase(string $path = ''): PDO {
    // Порожній $path або стара константа → основна БД через fly_db() singleton
    $legacyDefault = '/data/BD/database.sqlite';
    if ($path === '' || $path === $legacyDefault) {
        $pdo = fly_db(); // singleton з config.php (FLY_STORAGE_ROOT або legacy)

        // Міграції централізовано (lock-файл гарантує один запуск)
        static $migrationsDone = false;
        if (!$migrationsDone) {
            $migrFile = $_SERVER['DOCUMENT_ROOT'] . '/data/migrations.php';
            if (file_exists($migrFile)) {
                require_once $migrFile;
                run_migrations($pdo);
            }
            $migrationsDone = true;
        }

        return $pdo;
    }

    // Нестандартний шлях — власний singleton per-path
    static $extras = [];
    if (isset($extras[$path])) {
        return $extras[$path];
    }
    try {
        $pdo = new PDO('sqlite:' . $_SERVER['DOCUMENT_ROOT'] . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 3000');
        $extras[$path] = $pdo;
        return $pdo;
    } catch (PDOException $e) {
        die('Не вдалося підключитися до бази даних: ' . $e->getMessage());
    }
}

/**
 * Виконує callback у транзакції з retry при SQLITE_BUSY.
 *
 * Використання:
 *   $ok = db_transaction($pdo, function(PDO $pdo) use ($id) {
 *       $pdo->prepare('UPDATE posts SET views=views+1 WHERE id=?')->execute([$id]);
 *       return true;
 *   });
 *
 * @param PDO      $pdo
 * @param callable $callback    Отримує PDO, повертає довільне значення
 * @param int      $maxRetries  Максимум спроб після busy_timeout
 * @return mixed
 * @throws PDOException якщо всі спроби вичерпано
 */
function db_transaction(PDO $pdo, callable $callback, int $maxRetries = 5): mixed {
    $attempt = 0;
    while (true) {
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (PDOException $rb) {}
            }
            $isBusy = ($e->getCode() === 'HY000'
                && str_contains($e->getMessage(), 'database is locked'));
            if ($isBusy && $attempt < $maxRetries) {
                $attempt++;
                usleep((int)(50000 * (2 ** ($attempt - 1))));
                continue;
            }
            throw $e;
        }
    }
}

/**
 * Ієрархія ролей fly-CMS:
 *   superadmin  — власник сайту: всі права admin + ексклюзивні (meta_settings, очистка логів)
 *   admin       — адміністратор: керує контентом, користувачами, налаштуваннями
 *   redaktor    — редактор: контент, медіа, меню
 *   user        — звичайний авторизований користувач
 *   guest       — неавторизований
 */

/**
 * Чи є поточний (або переданий) користувач адміністратором або вище.
 * Використовуй замість $_SESSION['role'] === 'admin' скрізь де admin і superadmin рівноправні.
 */
if (!function_exists('is_admin')) {
    function is_admin(?string $role = null): bool {
        $r = $role ?? ($_SESSION['role'] ?? '');
        return in_array($r, ['admin', 'superadmin'], true);
    }
}

/**
 * Чи є поточний (або переданий) користувач саме superadmin.
 * Використовуй для ексклюзивних дій: meta_settings, очистка логів, призначення superadmin.
 */
if (!function_exists('is_superadmin')) {
    function is_superadmin(?string $role = null): bool {
        $r = $role ?? ($_SESSION['role'] ?? '');
        return $r === 'superadmin';
    }
}

/**
 * Перевірка, чи існує користувач
 *
 * @param PDO $pdo
 * @param string $username
 * @return bool
 */
function userExists(PDO $pdo, string $username): bool {
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login = :login");
	$stmt->execute([':login' => $username]);
	return $stmt->fetchColumn() > 0;
}

/**
 * Створення нового користувача
 *
 * @param PDO $pdo
 * @param string $username
 * @param string $password
 * @param string $role
 * @param string $display_name
 */
function createUser(PDO $pdo, string $username, string $password, string $role = 'user', string $display_name = ''): void {
	$passwordHash = password_hash($password, PASSWORD_BCRYPT);
	$display_name = $display_name !== '' ? $display_name : $username;
	$stmt = $pdo->prepare("INSERT INTO users (login, password, role, display_name) VALUES (:login, :password, :role, :display_name)");
	$stmt->execute([
		':login'        => $username,
		':password'     => $passwordHash,
		':role'         => $role,
		':display_name' => $display_name,
	]);
}

/**
 * Отримання списку всіх користувачів
 *
 * @param PDO $pdo
 * @return array
 */
function getAllUsers(PDO $pdo): array {
	$stmt = $pdo->query("SELECT id, login, display_name, role FROM users");
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Видалення користувача
 *
 * @param PDO $pdo
 * @param string $username
 */
function deleteUser(PDO $pdo, string $username): void {
	$stmt = $pdo->prepare("DELETE FROM users WHERE login = :login");
	$stmt->execute([':login' => $username]);
}

/**
 * Оновлення ролі, пароля та/або відображуваного імені користувача
 *
 * @param PDO $pdo
 * @param string $username
 * @param string|null $newPassword
 * @param string $newRole
 * @param string|null $display_name
 */
function updateUser(PDO $pdo, string $username, ?string $newPassword = null, string $newRole = 'user', ?string $display_name = null): void {
	$params = [':role' => $newRole, ':login' => $username];
	$sets = ['role = :role'];

	if (!empty($newPassword)) {
		$sets[] = 'password = :password';
		$params[':password'] = password_hash($newPassword, PASSWORD_BCRYPT);
	}
	if ($display_name !== null) {
		$sets[] = 'display_name = :display_name';
		$params[':display_name'] = $display_name;
	}

	$sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE login = :login";
	$pdo->prepare($sql)->execute($params);
}


?>