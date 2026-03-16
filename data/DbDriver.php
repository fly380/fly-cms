<?php
/**
 * data/DbDriver.php — SQLite-хелпери для fly-CMS
 *
 * Централізовані утиліти для роботи з SQLite:
 *   DbDriver::now()              — datetime('now','localtime')
 *   DbDriver::upsert(...)        — INSERT OR REPLACE INTO …
 *   DbDriver::insertIgnore(...)  — INSERT OR IGNORE INTO …
 *   DbDriver::tableExists(...)   — перевірка наявності таблиці
 *   DbDriver::getColumns(...)    — список колонок через PRAGMA
 *   DbDriver::walCheckpoint(...) — WAL checkpoint
 */

if (!defined('FLY_CMS')) {
    require_once dirname(__DIR__) . '/config.php';
}

class DbDriver
{
    // ── Поточний час (локальний) ──────────────────────────────────
    public static function now(): string
    {
        return "datetime('now','localtime')";
    }

    // ── INSERT OR REPLACE ─────────────────────────────────────────
    // $cols — масив колонок, напр. ['key', 'value']
    public static function upsert(string $table, array $cols): string
    {
        $colList      = implode(', ', $cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        return "INSERT OR REPLACE INTO {$table} ({$colList}) VALUES ({$placeholders})";
    }

    // ── INSERT OR IGNORE ──────────────────────────────────────────
    public static function insertIgnore(string $table, array $cols): string
    {
        $colList      = implode(', ', $cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        return "INSERT OR IGNORE INTO {$table} ({$colList}) VALUES ({$placeholders})";
    }

    // ── Перевірка існування таблиці ───────────────────────────────
    public static function tableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
        );
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Список колонок таблиці ────────────────────────────────────
    public static function getColumns(PDO $pdo, string $table): array
    {
        return $pdo->query("PRAGMA table_info({$table})")
                   ->fetchAll(PDO::FETCH_COLUMN, 1);
    }

    // ── WAL checkpoint ────────────────────────────────────────────
    public static function walCheckpoint(PDO $pdo): void
    {
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    }
}
