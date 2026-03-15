<?php
/**
 * Email конфіг для відправки через SMTP
 *
 * Всі чутливі дані читаються з .env (або змінних середовища).
 * Додайте до .env (поза webroot):
 *
 *   SMTP_ENABLED=true
 *   SMTP_HOST=smtp.gmail.com
 *   SMTP_PORT=587
 *   SMTP_USERNAME=your@gmail.com
 *   SMTP_PASSWORD=xxxx xxxx xxxx xxxx
 *   SMTP_FROM_NAME=CMS Admin
 *   SMTP_FROM_EMAIL=noreply@cms.local
 *   SMTP_ENCRYPTION=tls
 */

// env() визначена в config.php; підключаємо його якщо ще не підключено
if (!function_exists('env')) {
    require_once dirname(__DIR__) . '/config.php';
}

return [
    'smtp' => [
        'enabled'    => filter_var(env('SMTP_ENABLED',    'false'), FILTER_VALIDATE_BOOLEAN),
        'host'       => env('SMTP_HOST',       'smtp.gmail.com'),
        'port'       => (int) env('SMTP_PORT', '587'),
        'username'   => env('SMTP_USERNAME',   ''),
        'password'   => env('SMTP_PASSWORD',   ''),   // ← більше не в коді
        'from_name'  => env('SMTP_FROM_NAME',  'CMS Admin'),
        'from_email' => env('SMTP_FROM_EMAIL', 'noreply@cms.local'),
        'encryption' => env('SMTP_ENCRYPTION', 'tls'),
    ],
];