-- ============================================================
-- fly-CMS — MySQL схема
-- Запустити один раз при переході з SQLite на MySQL:
--   mysql -u flycms_user -p flycms < mysql_schema.sql
--
-- Або імпортувати через phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Основні таблиці ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `login`         VARCHAR(100) NOT NULL UNIQUE,
    `password`      VARCHAR(255) NOT NULL,
    `role`          VARCHAR(20)  NOT NULL DEFAULT 'user',
    `display_name`  VARCHAR(100) NOT NULL DEFAULT '',
    `totp_enabled`  TINYINT(1)   NOT NULL DEFAULT 0,
    `totp_secret`   VARCHAR(64)           DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pages` (
    `id`               INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title`            VARCHAR(255) NOT NULL DEFAULT '',
    `slug`             VARCHAR(255) NOT NULL UNIQUE,
    `content`          LONGTEXT,
    `draft`            TINYINT(1)   NOT NULL DEFAULT 0,
    `visibility`       VARCHAR(20)  NOT NULL DEFAULT 'public',
    `meta_title`       VARCHAR(255)          DEFAULT '',
    `meta_description` TEXT                  DEFAULT NULL,
    `custom_css`       TEXT                  DEFAULT NULL,
    `custom_js`        TEXT                  DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `posts` (
    `id`               INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title`            VARCHAR(255) NOT NULL DEFAULT '',
    `slug`             VARCHAR(255) NOT NULL UNIQUE,
    `content`          LONGTEXT,
    `excerpt`          TEXT                  DEFAULT NULL,
    `draft`            TINYINT(1)   NOT NULL DEFAULT 0,
    `author`           VARCHAR(100)          DEFAULT '',
    `thumbnail`        VARCHAR(500)          DEFAULT NULL,
    `views`            INT          NOT NULL DEFAULT 0,
    `published_at`     DATETIME              DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
    `id`    INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name`  VARCHAR(100) NOT NULL,
    `slug`  VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
    `id`    INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name`  VARCHAR(100) NOT NULL,
    `slug`  VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_categories` (
    `post_id`     INT NOT NULL,
    `category_id` INT NOT NULL,
    PRIMARY KEY (`post_id`, `category_id`),
    FOREIGN KEY (`post_id`)     REFERENCES `posts`(`id`)      ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_tags` (
    `post_id` INT NOT NULL,
    `tag_id`  INT NOT NULL,
    PRIMARY KEY (`post_id`, `tag_id`),
    FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`)  REFERENCES `tags`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_items` (
    `id`              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title`           VARCHAR(255) NOT NULL,
    `url`             VARCHAR(500) NOT NULL,
    `position`        INT          NOT NULL DEFAULT 0,
    `parent_id`       INT                   DEFAULT NULL,
    `visibility_role` VARCHAR(20)  NOT NULL DEFAULT 'user',
    FOREIGN KEY (`parent_id`) REFERENCES `menu_items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`      LONGTEXT     NOT NULL DEFAULT '',
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `theme_settings` (
    `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`      TEXT         NOT NULL DEFAULT '',
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `login`        VARCHAR(100) NOT NULL,
    `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
    `logged_in_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    INDEX `idx_sessions_login`     (`login`),
    INDEX `idx_sessions_active`    (`is_active`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitations` (
    `id`         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `token`      VARCHAR(64)  NOT NULL UNIQUE,
    `role`       VARCHAR(20)  NOT NULL DEFAULT 'user',
    `email`      VARCHAR(255)          DEFAULT NULL,
    `created_by` VARCHAR(100) NOT NULL,
    `expires_at` DATETIME              DEFAULT NULL,
    `used_at`    DATETIME              DEFAULT NULL,
    `used_by`    VARCHAR(100)          DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notes` (
    `id`          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `owner`       VARCHAR(100) NOT NULL,
    `scope`       VARCHAR(20)  NOT NULL DEFAULT 'personal',
    `title`       VARCHAR(255) NOT NULL DEFAULT '',
    `body`        TEXT         NOT NULL DEFAULT '',
    `color`       VARCHAR(20)  NOT NULL DEFAULT 'yellow',
    `remind_at`   DATETIME              DEFAULT NULL,
    `reminded`    TINYINT(1)   NOT NULL DEFAULT 0,
    `linked_type` VARCHAR(50)           DEFAULT NULL,
    `linked_id`   INT                   DEFAULT NULL,
    `pinned`      TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_notes_owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_settings` (
    `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT         NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_log` (
    `id`         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `type`       VARCHAR(20)  NOT NULL,
    `filename`   VARCHAR(255) NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_revisions` (
    `id`         INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `post_id`    INT      NOT NULL,
    `content`    LONGTEXT NOT NULL,
    `saved_by`   VARCHAR(100)      DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE,
    INDEX `idx_revisions_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
