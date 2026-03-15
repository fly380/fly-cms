<?php
/**
 * data/rate_limiter.php — Файловий rate limiter (без Redis/Memcached)
 *
 * Алгоритм: sliding window (ковзне вікно).
 * Зберігає мітки часу запитів у JSON-файлі на диску.
 * Підходить для невеликого трафіку (до ~100 req/хв на ендпоінт).
 *
 * Використання:
 *   require_once __DIR__ . '/rate_limiter.php';
 *   rate_limit('translate', 20, 60);   // 20 запитів за 60 секунд
 *   rate_limit('ai_helper', 10, 60);   // 10 запитів за 60 секунд
 *
 * При перевищенні ліміту — повертає HTTP 429 і завершує скрипт.
 */

/**
 * @param string $channel   Назва ендпоінту (використовується у назві файлу)
 * @param int    $maxHits   Максимум запитів у вікні
 * @param int    $windowSec Розмір вікна в секундах
 * @param string $ip        IP клієнта (за замовчуванням — з $_SERVER)
 */
function rate_limit(string $channel, int $maxHits, int $windowSec, string $ip = ''): void {
    if (empty($ip)) {
        // Підтримка проксі (CloudFlare, Nginx)
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        // Беремо тільки перший IP якщо список через кому
        $ip = trim(explode(',', $ip)[0]);
    }

    // Хешуємо IP щоб не зберігати його у відкритому вигляді у файлі
    $ipHash   = substr(hash('sha256', $ip), 0, 16);
    $lockDir  = __DIR__ . '/locks';
    $lockFile = $lockDir . '/rl_' . $channel . '_' . $ipHash . '.json';

    // Гарантуємо директорію
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    $now       = microtime(true);
    $windowStart = $now - $windowSec;
    $hits      = [];

    // Читаємо існуючі мітки
    if (file_exists($lockFile)) {
        $raw = @file_get_contents($lockFile);
        if ($raw !== false) {
            $hits = json_decode($raw, true) ?? [];
        }
    }

    // Видаляємо мітки поза вікном (sliding window)
    $hits = array_values(array_filter($hits, fn($t) => $t > $windowStart));

    if (count($hits) >= $maxHits) {
        // Вираховуємо скільки секунд чекати
        $retryAfter = (int)ceil($hits[0] + $windowSec - $now);
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . max(1, $retryAfter));
        echo json_encode([
            'error'       => 'Забагато запитів. Спробуй пізніше.',
            'retry_after' => max(1, $retryAfter),
        ]);
        exit;
    }

    // Додаємо поточний запит
    $hits[] = $now;

    // Атомарний запис через тимчасовий файл
    $tmp = $lockFile . '.tmp';
    if (@file_put_contents($tmp, json_encode($hits), LOCK_EX) !== false) {
        @rename($tmp, $lockFile);
    }
}

/**
 * Очищення старих lock-файлів (запускати раз на добу через cron або вручну).
 * Видаляє файли старіші за $maxAgeSec секунд.
 */
function rate_limit_cleanup(int $maxAgeSec = 3600): void {
    $lockDir = __DIR__ . '/locks';
    if (!is_dir($lockDir)) return;
    $cutoff = time() - $maxAgeSec;
    foreach (glob($lockDir . '/rl_*.json') as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
