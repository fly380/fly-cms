<?php
// Помилки — тільки в error_log, не у відповідь (API-ендпоінт)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

// ── Сесія та авторизація ──────────────────────────────────────────
session_start();
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'] ?? '', ['admin', 'redaktor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ заборонено']);
    exit;
}

// ── Завантаження config для env() ────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── Rate limiting: 10 запитів за 60 секунд з однієї IP ───────────
require_once __DIR__ . '/../data/rate_limiter.php';
rate_limit('ai_helper', 10, 60);

// ── API-ключ з .env (НЕ з коду) ──────────────────────────────────
$apiKey = env('GROQ_API_KEY', '');
if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'GROQ_API_KEY не налаштований у .env']);
    exit;
}

// ── Вхідні дані ───────────────────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true);
$prompt = "Відповідай українською. " . trim($input['prompt'] ?? '');

if (strlen($prompt) <= 20) { // порожній після префіксу
    echo json_encode(['error' => 'Немає prompt']);
    exit;
}

// ── Модель ────────────────────────────────────────────────────────
// Основна: llama-3.1-8b-instant (швидка), fallback: gemma2-9b-it
$model = 'llama-3.1-8b-instant';

$data = [
    'model'       => $model,
    'messages'    => [['role' => 'user', 'content' => $prompt]],
    'max_tokens'  => 1000,
    'temperature' => 0.7,
];

$url = 'https://api.groq.com/openai/v1/chat/completions';

// ── Запит (SSL верифікація увімкнена) ────────────────────────────
$options = [
    'http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]),
        'content'       => json_encode($data),
        'timeout'       => 30,
        'ignore_errors' => true,
    ],
    // SSL верифікацію увімкнено (verify_peer: true за замовчуванням)
];

$context  = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    $err = error_get_last();
    error_log('fly-CMS ai_helper: запит до Groq провалився: ' . ($err['message'] ?? '?'));
    echo json_encode(['error' => 'Не вдалося зв\'язатися з Groq API']);
    exit;
}

$result = json_decode($response, true);

// ── Fallback на gemma2-9b-it якщо модель знята з обслуговування ──
if (isset($result['error'])) {
    $errMsg = $result['error']['message'] ?? '';
    if (str_contains($errMsg, 'decommissioned') || str_contains($errMsg, 'not found')) {
        $data['model']          = 'gemma2-9b-it';
        $options['http']['content'] = json_encode($data);
        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            $result = json_decode($response, true);
        }
    }
    if (isset($result['error'])) {
        error_log('fly-CMS ai_helper: Groq API error: ' . json_encode($result['error']));
        echo json_encode(['error' => 'Помилка Groq API. Деталі — в error_log сервера.']);
        exit;
    }
}

$text = $result['choices'][0]['message']['content'] ?? 'Не вдалося згенерувати відповідь';
echo json_encode(['text' => $text]);