<?php
/**
 * data/migrations.php — Централізовані міграції БД
 *
 * Виконуються ОДИН РАЗ при першому виклику за допомогою lock-файлу.
 * Всі ALTER TABLE виключені з admin/functions.php та інших файлів.
 *
 * Підключення:
 *   require_once __DIR__ . '/migrations.php';
 *   run_migrations($pdo);
 */

function run_migrations(PDO $pdo): void {
    // Lock-файл: якщо він існує і в ньому та сама версія — пропускаємо
    $lockFile = __DIR__ . '/locks/migrations.lock';
    $currentVersion = 8; // збільшуй при додаванні нових міграцій

    if (file_exists($lockFile) && (int)file_get_contents($lockFile) >= $currentVersion) {
        return;
    }

    // Гарантуємо існування директорії locks/
    if (!is_dir(dirname($lockFile))) {
        mkdir(dirname($lockFile), 0755, true);
    }

    try {
        $pdo->beginTransaction();

        // ── Міграція 1: users.display_name ────────────────────────────
        _add_column_if_missing($pdo, 'users', 'display_name', "TEXT NOT NULL DEFAULT ''");

        // ── Міграція 2: users.qr_file ──────────────────────────────────
        _add_column_if_missing($pdo, 'users', 'qr_file', "TEXT DEFAULT NULL");

        // ── Міграція 3: users.email ────────────────────────────────────
        _add_column_if_missing($pdo, 'users', 'email', "TEXT DEFAULT NULL");

        // ── Міграція 4: menu_items.visibility_role ────────────────────
        _add_column_if_missing($pdo, 'menu_items', 'visibility_role', "TEXT DEFAULT 'all'");

        // ── Міграція 5: menu_items.icon, target, lang_settings ────────
        _add_column_if_missing($pdo, 'menu_items', 'icon',          "TEXT DEFAULT ''");
        _add_column_if_missing($pdo, 'menu_items', 'target',        "TEXT DEFAULT '_self'");
        _add_column_if_missing($pdo, 'menu_items', 'lang_settings', "TEXT DEFAULT ''");

        // ── Міграція 6: posts.show_on_main ────────────────────────────
        _add_column_if_missing($pdo, 'posts', 'show_on_main', "INTEGER DEFAULT 1");

        // ── Міграція 7: pages.custom_css, custom_js ───────────────────
        _add_column_if_missing($pdo, 'pages', 'custom_css', "TEXT DEFAULT ''");
        _add_column_if_missing($pdo, 'pages', 'custom_js',  "TEXT DEFAULT ''");

        // ── Таблиця invitations (якщо не існує) ───────────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS invitations (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            token       TEXT UNIQUE NOT NULL,
            email       TEXT,
            role        TEXT DEFAULT 'user',
            created_by  TEXT NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at  DATETIME NOT NULL,
            used_at     DATETIME,
            used_by     TEXT,
            require_2fa INTEGER DEFAULT 0
        )");

        // ── Таблиця notes (якщо не існує) ─────────────────────────────
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

        // ── Таблиця theme_settings (якщо не існує) ────────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS theme_settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // ── Міграція 8: superadmin роль ───────────────────────────────
        // SQLite не підтримує ALTER COLUMN для CHECK constraints,
        // тому роль superadmin підтримується лише на рівні логіки PHP.
        // Тут ми лише оновлюємо дефолтні дані: якщо є тільки один admin
        // і він ще не superadmin — нічого не змінюємо (апгрейд вручну через user_list).
        // Таблиця invitations: дозволяємо роль superadmin при генерації запрошень.
        // (значення role TEXT — обмежень CHECK немає, superadmin вже валідне)

        $pdo->commit();

        // Записуємо версію в lock-файл
        file_put_contents($lockFile, $currentVersion);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fly-CMS migrations error: ' . $e->getMessage());
    }
}

/**
 * Додає колонку якщо її ще немає (безпечно, без Exception)
 */
function _add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $cols, true)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
