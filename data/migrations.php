<?php
/**
 * data/migrations.php — Централізовані міграції БД
 *
 * Виконуються ОДИН РАЗ при першому виклику за допомогою lock-файлу.
 * При оновленні CMS збільшуй $currentVersion і додавай нові блоки знизу.
 *
 * Підключення:
 *   require_once __DIR__ . '/migrations.php';
 *   run_migrations($pdo);
 */

function run_migrations(PDO $pdo): void {
    $lockFile       = __DIR__ . '/locks/migrations.lock';
    $currentVersion = 9; // збільшуй при додаванні нових міграцій

    if (file_exists($lockFile) && (int)file_get_contents($lockFile) >= $currentVersion) {
        return;
    }

    if (!is_dir(dirname($lockFile))) {
        mkdir(dirname($lockFile), 0755, true);
    }

    try {
        $pdo->beginTransaction();

        // ── 1: users ──────────────────────────────────────────────────
        _add_column_if_missing($pdo, 'users', 'display_name', "TEXT NOT NULL DEFAULT ''");
        _add_column_if_missing($pdo, 'users', 'email',        'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'users', 'qr_file',      'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'users', 'totp_enabled', 'INTEGER NOT NULL DEFAULT 0');
        _add_column_if_missing($pdo, 'users', 'totp_secret',  'TEXT DEFAULT NULL');

        // ── 2: posts ──────────────────────────────────────────────────
        _add_column_if_missing($pdo, 'posts', 'visibility',       "TEXT NOT NULL DEFAULT 'public'");
        _add_column_if_missing($pdo, 'posts', 'show_on_main',     'INTEGER NOT NULL DEFAULT 1');
        _add_column_if_missing($pdo, 'posts', 'meta_title',       'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'posts', 'meta_description', 'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'posts', 'meta_keywords',    'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'posts', 'allow_comments',   'INTEGER NOT NULL DEFAULT 1');
        _add_column_if_missing($pdo, 'posts', 'sticky',           'INTEGER NOT NULL DEFAULT 0');
        _add_column_if_missing($pdo, 'posts', 'post_password',    'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'posts', 'publish_at',       'DATETIME DEFAULT NULL');
        _add_column_if_missing($pdo, 'posts', 'updated_at',       'DATETIME DEFAULT CURRENT_TIMESTAMP');

        // ── 3: pages ──────────────────────────────────────────────────
        _add_column_if_missing($pdo, 'pages', 'visibility', "TEXT NOT NULL DEFAULT 'public'");
        _add_column_if_missing($pdo, 'pages', 'custom_css', 'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'pages', 'custom_js',  'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'pages', 'updated_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');

        // ── 4: categories ─────────────────────────────────────────────
        _add_column_if_missing($pdo, 'categories', 'description', 'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'categories', 'parent_id',   'INTEGER DEFAULT 0');
        _add_column_if_missing($pdo, 'categories', 'created_at',  'DATETIME DEFAULT CURRENT_TIMESTAMP');

        // ── 5: tags ───────────────────────────────────────────────────
        _add_column_if_missing($pdo, 'tags', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');

        // ── 6: menu_items ─────────────────────────────────────────────
        _add_column_if_missing($pdo, 'menu_items', 'visible',         'INTEGER NOT NULL DEFAULT 1');
        _add_column_if_missing($pdo, 'menu_items', 'auth_only',       'INTEGER NOT NULL DEFAULT 0');
        _add_column_if_missing($pdo, 'menu_items', 'type',            "TEXT NOT NULL DEFAULT 'link'");
        _add_column_if_missing($pdo, 'menu_items', 'visibility_role', "TEXT NOT NULL DEFAULT 'all'");
        _add_column_if_missing($pdo, 'menu_items', 'icon',            "TEXT DEFAULT ''");
        _add_column_if_missing($pdo, 'menu_items', 'target',          "TEXT DEFAULT '_self'");
        _add_column_if_missing($pdo, 'menu_items', 'lang_settings',   "TEXT DEFAULT ''");

        // ── 7: post_revisions ─────────────────────────────────────────
        _add_column_if_missing($pdo, 'post_revisions', 'title',   'TEXT DEFAULT NULL');
        _add_column_if_missing($pdo, 'post_revisions', 'saved_at','DATETIME DEFAULT CURRENT_TIMESTAMP');
        _add_column_if_missing($pdo, 'post_revisions', 'note',    'TEXT DEFAULT NULL');

        // ── 8: invitations ────────────────────────────────────────────
        _add_column_if_missing($pdo, 'invitations', 'created_at',  'DATETIME DEFAULT CURRENT_TIMESTAMP');
        _add_column_if_missing($pdo, 'invitations', 'require_2fa', 'INTEGER NOT NULL DEFAULT 0');
        _add_column_if_missing($pdo, 'invitations', 'email_sent',  'INTEGER NOT NULL DEFAULT 0');

        // ── 9: нові таблиці (CREATE IF NOT EXISTS — безпечно) ─────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS main_page (
            id      INTEGER PRIMARY KEY CHECK (id = 1),
            title   TEXT,
            content TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS theme_settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

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

        $pdo->exec("CREATE TABLE IF NOT EXISTS invitations (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            token       TEXT NOT NULL UNIQUE,
            email       TEXT DEFAULT NULL,
            role        TEXT NOT NULL DEFAULT 'user',
            created_by  TEXT NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at  DATETIME NOT NULL,
            used_at     DATETIME DEFAULT NULL,
            used_by     TEXT DEFAULT NULL,
            require_2fa INTEGER NOT NULL DEFAULT 0,
            email_sent  INTEGER NOT NULL DEFAULT 0
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            login        TEXT NOT NULL,
            ip           TEXT NOT NULL DEFAULT '',
            logged_in_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active    INTEGER NOT NULL DEFAULT 1
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS backup_settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS backup_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            type       TEXT NOT NULL,
            filename   TEXT NOT NULL,
            size       INTEGER DEFAULT 0,
            created_by TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            note       TEXT DEFAULT ''
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS post_revisions (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id  INTEGER NOT NULL,
            title    TEXT DEFAULT NULL,
            content  TEXT NOT NULL DEFAULT '',
            saved_by TEXT DEFAULT NULL,
            saved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            note     TEXT DEFAULT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            uid         TEXT NOT NULL UNIQUE,
            author      TEXT NOT NULL,
            subject     TEXT NOT NULL DEFAULT '',
            category    TEXT NOT NULL DEFAULT 'general',
            priority    TEXT NOT NULL DEFAULT 'normal',
            status      TEXT NOT NULL DEFAULT 'open',
            reply_token TEXT DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at   DATETIME DEFAULT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id   INTEGER NOT NULL,
            sender      TEXT NOT NULL,
            sender_type TEXT NOT NULL DEFAULT 'user',
            body        TEXT NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS support_attachments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            filename   TEXT NOT NULL,
            size       INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->commit();
        file_put_contents($lockFile, $currentVersion);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fly-CMS migrations error: ' . $e->getMessage());
    }
}

/**
 * Додає колонку якщо її ще немає (безпечно, ігнорує якщо таблиця відсутня)
 */
function _add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!empty($cols) && !in_array($column, $cols, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    } catch (Exception $e) {
        error_log("_add_column_if_missing({$table}.{$column}): " . $e->getMessage());
    }
}
