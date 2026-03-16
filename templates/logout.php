<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/log_action.php';

// Деактивуємо сесію у user_sessions
if (!empty($_SESSION['username'])) {
    try {
        fly_db()->prepare(
            "UPDATE user_sessions SET is_active = 0 WHERE login = ? AND is_active = 1"
        )->execute([$_SESSION['username']]);
    } catch (Exception $e) { error_log('logout user_sessions: ' . $e->getMessage()); }
}

// Логування виходу, якщо користувач був авторизований
if (!empty($_SESSION['username']) && !empty($_SESSION['role'])) {
	log_action("Вихід ({$_SESSION['role']})", $_SESSION['username']);
}

// Очищення всіх даних сесії
$_SESSION = [];

// Видалення cookie сесії
if (ini_get("session.use_cookies")) {
	$params = session_get_cookie_params();
	setcookie(
		session_name(), '', time() - 42000,
		$params["path"], $params["domain"],
		$params["secure"], $params["httponly"]
	);
}

// Знищення самої сесії
session_destroy();

// 🔒 Регенерація ID після знищення (захист від повторного використання старого ID)
session_start();
session_regenerate_id(true);

// Перенаправлення
header("Location: /index.php");
exit;