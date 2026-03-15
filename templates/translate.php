<?php
/**
 * translate.php — Супер-швидкий перекладач
 * Використовує паралельні запити до Google Translate
 */

// Вимкнути вивід помилок для продакшну
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

// CORS: дозволяємо тільки запити з власного домену (не '*')
$_allowed_origin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$_request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($_request_origin !== '' && $_request_origin === $_allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $_allowed_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight OPTIONS запит
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'POST only']));
}

// ── Rate limiting: 20 запитів за 60 секунд з однієї IP ───────────
require_once __DIR__ . '/../data/rate_limiter.php';
rate_limit('translate', 20, 60);

$input = json_decode(file_get_contents('php://input'), true);
$lang = $input['lang'] ?? '';
$texts = $input['texts'] ?? [];

// Валідація
if (!in_array($lang, ['en', 'pl', 'de']) || !is_array($texts)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid input']));
}

// Фільтруємо порожні тексти
$texts = array_values(array_filter($texts, function($t) {
    return !empty(trim($t));
}));

if (empty($texts)) {
    exit(json_encode(['translations' => []]));
}

// Обмежуємо кількість текстів за один раз
$texts = array_slice($texts, 0, 30);

// Масив для результатів
$results = array_fill(0, count($texts), '');

// Створюємо мульти-курл для паралельних запитів
$mh = curl_multi_init();
$curlHandles = [];

// Ініціалізація всіх з'єднань
foreach ($texts as $index => $text) {
    if (empty(trim($text))) {
        $results[$index] = $text;
        continue;
    }

    // Обмежуємо довжину тексту
    $shortText = mb_substr($text, 0, 1000);
    
    $url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query([
        'client' => 'gtx',
        'sl'     => 'uk',
        'tl'     => $lang,
        'dt'     => 't',
        'q'      => $shortText,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; fly-CMS/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    curl_multi_add_handle($mh, $ch);
    $curlHandles[$index] = $ch;
}

// Виконуємо всі запити паралельно
$running = null;
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.1); // Таймаут 100ms
    }
} while ($running > 0 && $status == CURLM_OK);

// Збираємо результати
foreach ($curlHandles as $index => $ch) {
    $response = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data[0]) && is_array($data[0])) {
            $translated = '';
            foreach ($data[0] as $part) {
                if (isset($part[0])) $translated .= $part[0];
            }
            $results[$index] = $translated ?: $texts[$index];
        } else {
            $results[$index] = $texts[$index];
        }
    } else {
        // Fallback: MyMemory — офіційний безкоштовний API (до 500 слів/день без ключа)
        $fbUrl = 'https://api.mymemory.translated.net/get?' . http_build_query([
            'q'        => mb_substr($texts[$index], 0, 500),
            'langpair' => 'uk|' . $lang,
        ]);
        $fbCh = curl_init();
        curl_setopt_array($fbCh, [
            CURLOPT_URL            => $fbUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $fbResp = curl_exec($fbCh);
        $fbCode = curl_getinfo($fbCh, CURLINFO_HTTP_CODE);
        curl_close($fbCh);

        if ($fbCode === 200 && $fbResp) {
            $fbData = json_decode($fbResp, true);
            $results[$index] = $fbData['responseData']['translatedText'] ?? $texts[$index];
        } else {
            $results[$index] = $texts[$index];
        }
    }
    
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

// Відправляємо результат
echo json_encode(['translations' => $results], JSON_UNESCAPED_UNICODE);