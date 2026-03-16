<?php
/**
 * config.php — Центральна конфігурація fly-CMS
 *
 * Підключається скрізь замість повторного оголошення get_setting/ts.
 * Завантажує .env для секретів.
 *
 * Використання:
 *   require_once __DIR__ . '/config.php';          // з кореня
 *   require_once __DIR__ . '/../config.php';        // з admin/ або templates/
 *   require_once __DIR__ . '/../../config.php';     // з підкаталогів
 */

// ── Захист від прямого виклику ─────────────────────────────────────
if (!defined('FLY_CMS')) {
    define('FLY_CMS', true);
}

// ── Шлях до кореня проекту (webroot) ─────────────────────────────
if (!defined('FLY_ROOT')) {
    define('FLY_ROOT', rtrim(__DIR__, '/\\'));
}

// ── Шлях до сховища даних поза webroot ───────────────────────────
// За замовчуванням — на один рівень вище від webroot, що забезпечує
// недоступність через HTTP навіть без .htaccess.
//
// Якщо твій webroot = /var/www/html  → сховище = /var/www/cms_storage
// Якщо твій webroot = /public_html   → сховище = /cms_storage  (поряд)
//
// Щоб змінити шлях — задай змінну оточення або встав константу ДО
// підключення config.php:
//   define('FLY_STORAGE_ROOT', '/home/myuser/cms_storage');
//
// АБО задай у .env (якщо .env ще в webroot):
//   FLY_STORAGE_ROOT=/home/myuser/cms_storage
//
// ⚠️  Переміщення файлів на сервері:
//   1. mkdir -p <FLY_STORAGE_ROOT>/data/BD
//   2. mv <webroot>/data/BD/database.sqlite <FLY_STORAGE_ROOT>/data/BD/
//   3. mv <webroot>/.env <FLY_STORAGE_ROOT>/.env
//   4. Задай FLY_STORAGE_ROOT у середовищі або нижче як константу.
if (!defined('FLY_STORAGE_ROOT')) {
    // Пріоритет 1: змінна оточення (задається на сервері або в .htaccess SetEnv)
    $__storageEnv = getenv('FLY_STORAGE_ROOT') ?: ($_ENV['FLY_STORAGE_ROOT'] ?? '');
    if ($__storageEnv !== '') {
        define('FLY_STORAGE_ROOT', rtrim($__storageEnv, '/'));
    } else {
        // Пріоритет 2: батьківська директорія webroot + /cms_storage
        // /var/www/html  → /var/www/cms_storage
        // /public_html   → /cms_storage
        define('FLY_STORAGE_ROOT', rtrim(dirname(FLY_ROOT), '/') . '/cms_storage');
    }
    unset($__storageEnv);
}

// ── Централізовані HTTP Security Headers ──────────────────────────
// Викликається один раз з будь-якого ентрипоінту.
// $extra — масив додаткових директив CSP (напр. для TinyMCE).
if (!function_exists('fly_send_security_headers')) {
    function fly_send_security_headers(array $extra = []): void {
        if (headers_sent()) {
            return;
        }
        $csp  = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; ";
        $csp .= "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; ";
        $csp .= "font-src 'self' https://cdn.jsdelivr.net; ";
        $csp .= "img-src 'self' data: https:; ";
        $csp .= "connect-src 'self' https://cdn.jsdelivr.net;";
        if (!empty($extra)) {
            $csp .= ' ' . implode(' ', $extra);
        }
        header("Content-Security-Policy: {$csp}");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: no-referrer");
        header("X-XSS-Protection: 1; mode=block");
    }
}

// ── Глобальний PDO singleton (fly_db()) ───────────────────────────
// Повертає єдине SQLite-з'єднання на PHP-процес.
// Всі компоненти (HomeService, page.php, get_setting, get_display_name)
// використовують цей самий екземпляр замість власних new PDO().
if (!function_exists('fly_db')) {
    function fly_db(): PDO {
        static $instance = null;
        if ($instance !== null) return $instance;

        // SQLite — шукаємо БД в FLY_STORAGE_ROOT, fallback у webroot
        $storagePath = FLY_STORAGE_ROOT . '/data/BD/database.sqlite';
        $legacyPath  = FLY_ROOT         . '/data/BD/database.sqlite';
        $path = file_exists($storagePath) ? $storagePath : $legacyPath;

        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 3000');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('fly_db(): ' . $e->getMessage());
            exit('Помилка підключення до бази даних.');
        }

        $instance = $pdo;
        return $instance;
    }
}

// ── Завантаження .env ─────────────────────────────────────────────
// Шукаємо .env спочатку в FLY_STORAGE_ROOT (поза webroot — безпечно),
// потім у FLY_ROOT (webroot — захищений через .htaccess як fallback).
(function () {
    $candidates = [
        FLY_STORAGE_ROOT . '/.env',   // ✅ поза webroot — рекомендовано
        FLY_ROOT         . '/.env',   // ⚠️  у webroot  — лише з .htaccess
    ];
    $envFile = null;
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            $envFile = $candidate;
            break;
        }
    }
    if ($envFile === null) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Пропускаємо коментарі та порожні рядки
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);
        // Знімаємо лапки якщо є
        if (preg_match('/^"(.*)"$/s', $value, $m) || preg_match("/^'(.*)'$/s", $value, $m)) {
            $value = $m[1];
        }
        if ($name !== '' && !array_key_exists($name, $_ENV)) {
            $_ENV[$name]    = $value;
            putenv("{$name}={$value}");
        }
    }
})();

// ── Допоміжна: отримати значення з .env ───────────────────────────
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v !== false && $v !== null) ? $v : $default;
    }
}

// ── get_setting(): читає з таблиці settings або theme_settings ────
if (!function_exists('get_setting')) {
    function get_setting(string $key, string $table = 'settings') {
        static $cache = [];
        $ckey = $table . ':' . $key;
        if (array_key_exists($ckey, $cache)) {
            return $cache[$ckey];
        }
        try {
            $db   = fly_db();
            $stmt = $db->prepare("SELECT value FROM {$table} WHERE key = ?");
            $stmt->execute([$key]);
            $r = $stmt->fetchColumn();
            $cache[$ckey] = ($r !== false) ? $r : null;
        } catch (Exception $e) {
            $cache[$ckey] = null;
        }
        return $cache[$ckey];
    }
}

// ── ts(): скорочення для theme_settings ───────────────────────────
if (!function_exists('ts')) {
    function ts(string $key, string $default = ''): string {
        $v = get_setting($key, 'theme_settings');
        return ($v !== null) ? $v : $default;
    }
}

// ── get_display_name(): відображуване ім'я користувача ───────────
if (!function_exists('get_display_name')) {
    function get_display_name(string $login): string {
        if (empty($login)) {
            return $login;
        }
        try {
            $db   = fly_db();
            $stmt = $db->prepare("SELECT display_name FROM users WHERE LOWER(login) = LOWER(?)");
            $stmt->execute([$login]);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['display_name'])) {
                return $row['display_name'];
            }
        } catch (Exception $e) {}
        return $login;
    }
}
