<?php
/**
 * data/DbDriver.php — Абстракція драйвера БД для fly-CMS
 *
 * Приховує відмінності між SQLite і MySQL.
 * Всі SQLite-специфічні методи централізовані тут —
 * решта коду просто викликає DbDriver::*(…).
 *
 * Додайте у .env:
 *   DB_DRIVER=sqlite          # або mysql
 *   # MySQL:
 *   DB_HOST=127.0.0.1
 *   DB_PORT=3306
 *   DB_NAME=flycms
 *   DB_USER=flycms_user
 *   DB_PASS=secret
 *   DB_CHARSET=utf8mb4
 */

if (!defined('FLY_CMS')) {
    require_once dirname(__DIR__) . '/config.php';
}

class DbDriver
{
    // ── Ідентифікатор активного драйвера ─────────────────────────
    public static function driver(): string
    {
        static $d = null;
        if ($d === null) {
            $d = strtolower(env('DB_DRIVER', 'sqlite'));
        }
        return $d;
    }

    public static function isMySQL(): bool  { return self::driver() === 'mysql';  }
    public static function isSQLite(): bool { return self::driver() === 'sqlite'; }

    // ── NOW() — поточний час ──────────────────────────────────────
    // SQLite : datetime('now')
    // MySQL  : NOW()
    public static function now(): string
    {
        return self::isMySQL() ? 'NOW()' : "datetime('now')";
    }

    // ── NOW() localtime ───────────────────────────────────────────
    // SQLite : datetime('now','localtime')
    // MySQL  : NOW()   (MySQL повертає час сервера, що зазвичай = localtime)
    public static function nowLocal(): string
    {
        return self::isMySQL() ? 'NOW()' : "datetime('now','localtime')";
    }

    // ── Інтервал у майбутньому: NOW + N годин ─────────────────────
    // SQLite : datetime('now', '+N hours')   — але N може бути параметром!
    //          Хак: datetime('now', '+' || ? || ' hours')
    // MySQL  : DATE_ADD(NOW(), INTERVAL ? HOUR)
    // Повертає SQL-фрагмент; ? — bind-параметр для кількості годин.
    public static function nowPlusHoursParam(): string
    {
        return self::isMySQL()
            ? 'DATE_ADD(NOW(), INTERVAL ? HOUR)'
            : "datetime('now', '+' || ? || ' hours')";
    }

    // ── Перевірка: час < NOW - N хвилин ───────────────────────────
    // SQLite : col > datetime('now', '-15 minutes')
    // MySQL  : col > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    public static function olderThanMinutes(string $col, int $minutes): string
    {
        return self::isMySQL()
            ? "{$col} > DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
            : "{$col} > datetime('now', '-{$minutes} minutes')";
    }

    // ── INSERT OR REPLACE ─────────────────────────────────────────
    // SQLite : INSERT OR REPLACE INTO t (k,v) VALUES (?,?)
    // MySQL  : INSERT INTO t (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)
    //
    // $table   — назва таблиці
    // $cols    — масив колонок ['key','value']
    // $extras  — додаткові колонки для UPDATE (якщо відрізняються від $cols)
    //            якщо null — оновлюються всі $cols крім першої (PK)
    public static function upsert(string $table, array $cols, ?array $updateCols = null): string
    {
        $colList    = implode(',', $cols);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));

        if (self::isSQLite()) {
            return "INSERT OR REPLACE INTO {$table} ({$colList}) VALUES ({$placeholders})";
        }

        // MySQL: ON DUPLICATE KEY UPDATE
        $upd = $updateCols ?? array_slice($cols, 1); // всі крім PK
        $updateStr = implode(', ', array_map(fn($c) => "{$c}=VALUES({$c})", $upd));
        return "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders}) "
             . "ON DUPLICATE KEY UPDATE {$updateStr}";
    }

    // ── INSERT OR IGNORE ──────────────────────────────────────────
    // SQLite : INSERT OR IGNORE INTO …
    // MySQL  : INSERT IGNORE INTO …
    public static function insertIgnore(string $table, array $cols): string
    {
        $colList      = implode(',', $cols);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $keyword      = self::isMySQL() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        return "{$keyword} INTO {$table} ({$colList}) VALUES ({$placeholders})";
    }

    // ── Перевірка існування таблиці ───────────────────────────────
    // SQLite : SELECT name FROM sqlite_master WHERE type='table' AND name=?
    // MySQL  : SELECT TABLE_NAME FROM information_schema.TABLES
    //          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
    public static function tableExistsSQL(): string
    {
        if (self::isMySQL()) {
            return "SELECT TABLE_NAME FROM information_schema.TABLES "
                 . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
        }
        return "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
    }

    // ── Зручний хелпер: чи існує таблиця? ────────────────────────
    public static function tableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare(self::tableExistsSQL());
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Перевірка колонок таблиці ─────────────────────────────────
    // SQLite : PRAGMA table_info(tbl)  → fetchAll, column index 1 = name
    // MySQL  : SHOW COLUMNS FROM tbl  → fetchAll, key 'Field'
    public static function getColumns(PDO $pdo, string $table): array
    {
        if (self::isMySQL()) {
            $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            return array_column($rows, 'Field');
        }
        return $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    }

    // ── AUTO_INCREMENT / AUTOINCREMENT ────────────────────────────
    // Використовуйте цей метод при динамічному CREATE TABLE.
    // Для статичних DDL у migrations.php — дивіться окремий файл.
    public static function autoIncrement(): string
    {
        return self::isMySQL() ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';
    }

    // ── PRIMARY KEY з auto_increment ──────────────────────────────
    // SQLite : id INTEGER PRIMARY KEY AUTOINCREMENT
    // MySQL  : id INT NOT NULL AUTO_INCREMENT PRIMARY KEY
    public static function pkDef(): string
    {
        return self::isMySQL()
            ? 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    // ── CURRENT_TIMESTAMP default ─────────────────────────────────
    // Обидва підтримують CURRENT_TIMESTAMP у DEFAULT — ОК без абстракції.
    // Але DATETIME vs DATETIME — однакові. Нічого не потрібно.

    // ── WAL checkpoint (тільки SQLite) ────────────────────────────
    public static function walCheckpoint(PDO $pdo): void
    {
        if (self::isSQLite()) {
            $pdo->exec('PRAGMA wal_checkpoint(FULL)');
        }
        // MySQL: нічого не потрібно
    }
}
