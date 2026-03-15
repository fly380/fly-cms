<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') { die('403'); }
require_once __DIR__ . '/functions.php';
$pdo = connectToDatabase();
header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP INFO ===\n";
echo "PHP date():          " . date('Y-m-d H:i:s') . "\n";
echo "PHP time():          " . time() . "\n";
echo "date_default_timezone_get(): " . date_default_timezone_get() . "\n";

echo "\n=== SQLITE INFO ===\n";
echo "datetime('now'):         " . $pdo->query("SELECT datetime('now')")->fetchColumn() . "\n";
echo "datetime('now','localtime'): " . $pdo->query("SELECT datetime('now','localtime')")->fetchColumn() . "\n";

echo "\n=== ALL POSTS ===\n";
$rows = $pdo->query("SELECT id, title, slug, draft, publish_at FROM posts")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "id={$r['id']} draft={$r['draft']} publish_at=" . var_export($r['publish_at'], true) . " title={$r['title']}\n";
}

echo "\n=== COMPARISON ===\n";
$nowLocal = date('Y-m-d H:i:s');
echo "nowLocal (PHP): $nowLocal\n";
$check = $pdo->prepare("SELECT id, publish_at, (publish_at <= ?) as should_publish FROM posts WHERE draft=1 AND publish_at IS NOT NULL");
$check->execute([$nowLocal]);
foreach ($check->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "id={$r['id']} publish_at={$r['publish_at']} <= nowLocal=$nowLocal => should_publish={$r['should_publish']}\n";
}

echo "\n=== MANUAL UPDATE ===\n";
$stmt = $pdo->prepare("UPDATE posts SET draft=0, publish_at=NULL, updated_at=? WHERE draft=1 AND publish_at IS NOT NULL AND publish_at != '' AND publish_at <= ?");
$stmt->execute([$nowLocal, $nowLocal]);
echo "rowCount(): " . $stmt->rowCount() . "\n";

echo "\n=== POSTS AFTER UPDATE ===\n";
$rows = $pdo->query("SELECT id, title, draft, publish_at FROM posts")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "id={$r['id']} draft={$r['draft']} publish_at=" . var_export($r['publish_at'], true) . "\n";
}

echo "\n=== PDO DRIVER ===\n";
echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "Server info: " . @$pdo->getAttribute(PDO::ATTR_SERVER_INFO) . "\n";
