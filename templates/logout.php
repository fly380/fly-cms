<?php
session_start();
require_once __DIR__ . '/../data/log_action.php';

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
