<?php
session_start();
//дозволяємо доступ адміну і редактору
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
	http_response_code(403);
	exit("Доступ заборонено");
}

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/data/logs/activity.log';
if (file_exists($logFile)) {
	echo html_entity_decode(implode('', array_reverse(file($logFile))));
} else {
	echo "Файл логів не знайдено.";
}
