<?php

// ── Redirect to installer if CMS is not set up ───────────────────
if (!defined('FLY_INSTALLER') && !defined('FLY_CMS')) {
    $__r = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $__noEnv = !file_exists($__r.'/.env') && !file_exists(dirname($__r).'/cms_storage/.env');
    $__noLock = !file_exists($__r.'/data/.installed');
    if ($__noEnv && $__noLock && file_exists($__r.'/install.php')) {
        header('Location: /install.php'); exit;
    }
    unset($__r, $__noEnv, $__noLock);
}

/**
 * config.php — Центральна конфігурація fly-CMS
 *
 * Підтримує два драйвери: sqlite (дефолт) і mysql.
 * Налаштування у .env:
 *
 *   DB_DRIVER=sqlite          ← або mysql
 *
 *   # MySQL тільки:
 *   DB_HOST=127.0.0.1
 *   DB_PORT=3306
 *   DB_NAME=flycms
 *   DB_USER=flycms_user
 *   DB_PASS=secret
 *   DB_CHARSET=utf8mb4
 */

if (!defined('FLY_CMS')) {
    define('FLY_CMS', true);
}

if (!defined('FLY_ROOT')) {
    define('FLY_ROOT', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
}

if (!defined('FLY_STORAGE_ROOT')) {
    $__storageEnv = getenv('FLY_STORAGE_ROOT') ?: ($_ENV['FLY_STORAGE_ROOT'] ?? '');
    if ($__storageEnv !== '') {
        define('FLY_STORAGE_ROOT', rtrim($__storageEnv, '/'));
    } else {
        define('FLY_STORAGE_ROOT', rtrim(dirname(FLY_ROOT), '/') . '/cms_storage');
    }
    unset($__storageEnv);
}

// ── Security Headers ──────────────────────────────────────────────
if (!function_exists('fly_send_security_headers')) {
    function fly_send_security_headers(array $extra = []): void {
        if (headers_sent()) return;
        $csp  = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; ";
        $csp .= "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; ";
        $csp .= "font-src 'self' https://cdn.jsdelivr.net; ";
        $csp .= "img-src 'self' data: https:; ";
        $csp .= "connect-src 'self' https://cdn.jsdelivr.net;";
        if (!empty($extra)) $csp .= ' ' . implode(' ', $extra);
        header("Content-Security-Policy: {$csp}");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: no-referrer");
        header("X-XSS-Protection: 1; mode=block");
    }
}

// ── .env завантаження (до fly_db, бо потрібні DB_* змінні) ───────
(function () {
    $candidates = [
        FLY_STORAGE_ROOT . '/.env',
        FLY_ROOT         . '/.env',
    ];
    foreach ($candidates as $envFile) {
        if (!file_exists($envFile)) continue;
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);
            if (preg_match('/^"(.*)"$/s', $value, $m) || preg_match("/^'(.*)'$/s", $value, $m)) {
                $value = $m[1];
            }
            if ($name !== '' && !array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
        break;
    }
})();

// ── env() хелпер ─────────────────────────────────────────────────
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v !== false && $v !== null) ? $v : $default;
    }
}

// ── PDO Singleton — підтримує SQLite і MySQL ─────────────────────
if (!function_exists('fly_db')) {
    function fly_db(): PDO {
        static $instance = null;
        if ($instance !== null) return $instance;

        $driver = strtolower(env('DB_DRIVER', 'sqlite'));

        try {
            if ($driver === 'mysql') {
                $host    = env('DB_HOST',    '127.0.0.1');
                $port    = env('DB_PORT',    '3306');
                $dbname  = env('DB_NAME',    'flycms');
                $user    = env('DB_USER',    '');
                $pass    = env('DB_PASS',    '');
                $charset = env('DB_CHARSET', 'utf8mb4');

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Тримаємо з'єднання живим (важливо для довгих запитів)
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
                ]);

            } else {
                // SQLite (дефолт — зворотна сумісність)
                $storagePath = FLY_STORAGE_ROOT . '/data/BD/database.sqlite';
                $legacyPath  = FLY_ROOT         . '/data/BD/database.sqlite';
                $path = file_exists($storagePath) ? $storagePath : $legacyPath;

                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 3000');
            }

        } catch (PDOException $e) {
            http_response_code(500);
            error_log('fly_db(): не вдалося підключитися: ' . $e->getMessage());
            exit('Помилка з\'єднання з базою даних.');
        }

        $instance = $pdo;
        return $instance;
    }
}

// ── get_setting() ─────────────────────────────────────────────────
if (!function_exists('get_setting')) {
    function get_setting(string $key, string $table = 'settings') {
        static $cache = [];
        $ckey = $table . ':' . $key;
        if (array_key_exists($ckey, $cache)) return $cache[$ckey];
        try {
            $stmt = fly_db()->prepare("SELECT value FROM {$table} WHERE `key` = ?");
            $stmt->execute([$key]);
            $r = $stmt->fetchColumn();
            $cache[$ckey] = ($r !== false) ? $r : null;
        } catch (Exception $e) {
            $cache[$ckey] = null;
        }
        return $cache[$ckey];
    }
}

// ── ts() ──────────────────────────────────────────────────────────
if (!function_exists('ts')) {
    function ts(string $key, string $default = ''): string {
        $v = get_setting($key, 'theme_settings');
        return ($v !== null) ? $v : $default;
    }
}

// ── get_display_name() ────────────────────────────────────────────
if (!function_exists('get_display_name')) {
    function get_display_name(string $login): string {
        if (empty($login)) return $login;
        try {
            $stmt = fly_db()->prepare("SELECT display_name FROM users WHERE LOWER(login) = LOWER(?)");
            $stmt->execute([$login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['display_name'])) return $row['display_name'];
        } catch (Exception $e) {}
        return $login;
    }
}