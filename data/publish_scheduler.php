<?php
/**
 * data/publish_scheduler.php
 * publish_at зберігається в локальному часі браузера/сервера.
 * Порівнюємо через datetime('now','localtime') — SQLite-еквівалент локального часу.
 */

/**
 * Запускає планувальник публікацій.
 *
 * Два режими роботи:
 *
 * 1. CRON-режим (рекомендовано): cron_publish.php викликає з $force = true.
 *    Виконується кожну хвилину, без TTL-перевірки.
 *    Налаштування (crontab -e):
 *      * * * * * php /шлях/до/cms/data/cron_publish.php >> /шлях/до/logs/cron.log 2>&1
 *
 * 2. Web-fallback (shared hosting без cron): router.php та index.php викликають
 *    з $force = false. TTL-файл обмежує реальне виконання до 1 разу на хвилину,
 *    тому на кожен HTTP-запит виконується лише stat() — мінімальне навантаження.
 *
 * @param PDO  $pdo
 * @param bool $force  true — завжди виконати (cron), false — з TTL (web)
 * @param int  $ttlSec Інтервал між виконаннями у web-режимі (секунди)
 */
function run_publish_scheduler(PDO $pdo, bool $force = false, int $ttlSec = 60): void {
    // ── Web-режим: перевіряємо TTL-файл ──────────────────────────
    if (!$force) {
        $lockDir  = __DIR__ . '/locks';
        $lockFile = $lockDir . '/scheduler_ttl.lock';

        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        // Якщо файл свіжіший за $ttlSec — пропускаємо
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $ttlSec) {
            return;
        }

        // Оновлюємо mtime lock-файлу (touch безпечний при конкуренції)
        @touch($lockFile);
    }

    // ── Виконання публікації ──────────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            "UPDATE posts
             SET draft = 0, publish_at = NULL, updated_at = datetime('now','localtime')
             WHERE draft = 1
               AND publish_at IS NOT NULL
               AND publish_at != ''
               AND publish_at <= datetime('now','localtime')"
        );
        $stmt->execute();
        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $logFile = __DIR__ . '/logs/activity.log';
            if (is_writable(dirname($logFile))) {
                $mode = $force ? 'cron' : 'web-fallback';
                $line = '[' . date('Y-m-d H:i:s') . '] [scheduler:' . $mode . '] ✅ Опубліковано ' . $affected . ' запис(ів) за розкладом' . PHP_EOL;
                file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            }
        }
    } catch (Exception $e) {
        error_log('publish_scheduler error: ' . $e->getMessage());
    }
}