<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Записує дію в activity.log з автоматичною ротацією.
 *
 * Ротація: якщо файл перевищує LOG_MAX_SIZE байт — він перейменовується
 * у activity.log.1, старий .1 → .2, ... до LOG_KEEP_FILES архівів.
 * Гарантує що основний файл ніколи не зростає безмежно.
 */
function log_action(string $action, ?string $username = null): void {
    $logDir  = $_SERVER['DOCUMENT_ROOT'] . '/data/logs/';
    $logFile = $logDir . 'activity.log';

    // Налаштування ротації
    $maxSize   = 2 * 1024 * 1024; // 2 MB
    $keepFiles = 5;               // скільки архівних файлів зберігати

    // Директорія
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("fly-CMS: не вдалося створити директорію логів: {$logDir}");
            return;
        }
    }

    // Ротація якщо файл занадто великий
    if (file_exists($logFile) && filesize($logFile) >= $maxSize) {
        _rotate_log($logFile, $keepFiles);
    }

    // Запис рядка
    $username = $username ?? ($_SESSION['username'] ?? 'невідомо');
    $line = sprintf(
        "[%s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        $action
    );

    if (file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log("fly-CMS: помилка запису в лог: {$logFile}");
    }
}

/**
 * Ротація: activity.log → .1, .1 → .2 ... найстаріший видаляється.
 */
function _rotate_log(string $logFile, int $keep): void {
    $oldest = "{$logFile}.{$keep}";
    if (file_exists($oldest)) {
        @unlink($oldest);
    }
    for ($i = $keep - 1; $i >= 1; $i--) {
        $from = "{$logFile}.{$i}";
        $to   = "{$logFile}." . ($i + 1);
        if (file_exists($from)) {
            @rename($from, $to);
        }
    }
    @rename($logFile, "{$logFile}.1");
}
