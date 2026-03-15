#!/usr/bin/env php
<?php
/**
 * data/cron_publish.php — CLI-скрипт для планувальника публікацій
 *
 * Запуск вручну:
 *   php /path/to/cms/data/cron_publish.php
 *
 * Налаштування через crontab (crontab -e):
 *   * * * * * php /var/www/html/data/cron_publish.php >> /var/www/html/data/logs/cron.log 2>&1
 *   (кожну хвилину — найточніший варіант)
 *
 *   або кожні 5 хвилин:
 *   *\/5 * * * * php /var/www/html/data/cron_publish.php >> /var/www/html/data/logs/cron.log 2>&1
 *
 * Якщо cron недоступний (shared hosting) — poor man's cron залишається
 * у router.php та index.php як запасний варіант, але з меншою точністю.
 */

// Дозволяємо запуск тільки з командного рядка
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Цей скрипт призначений тільки для запуску з CLI.\n");
}

// Визначаємо корінь проекту (на рівень вище від data/)
$root = dirname(__DIR__);

// Підключаємось до БД
try {
    $pdo = new PDO('sqlite:' . $root . '/data/BD/database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    fwrite(STDERR, '[cron_publish] БД недоступна: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Запускаємо планувальник
require_once __DIR__ . '/publish_scheduler.php';
run_publish_scheduler($pdo, true); // cron: завжди виконувати

echo '[' . date('Y-m-d H:i:s') . '] [cron_publish] Завершено.' . PHP_EOL;
