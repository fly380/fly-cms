<?php
/**
 * admin/revision_api.php
 * Повертає контент ревізії для відновлення в редакторі
 */

// Вимикаємо display_errors — щоб PHP-попередження не ламали JSON
ini_set('display_errors', 0);
error_reporting(0);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Доступ заборонено']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Не вказано ID ревізії']);
    exit;
}

require_once __DIR__ . '/functions.php';
$pdo = connectToDatabase();

$rev = $pdo->prepare("SELECT r.* FROM post_revisions r WHERE r.id = ?");
$rev->execute([$id]);
$row = $rev->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Ревізію не знайдено']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'title'    => $row['title'],
    'content'  => $row['content'],
    'saved_by' => $row['saved_by'],
    'saved_at' => $row['saved_at'],
]);