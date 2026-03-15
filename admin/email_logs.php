<?php
// Email logs viewer
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

// Перевірка прав доступу
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
	header('Location: /templates/login.php');
	exit;
}

// Початок буферизації
ob_start();
?>

<style>
.logs-container {
	max-width: 1200px;
	margin: 0 auto;
	background: white;
	border-radius: 10px;
	padding: 30px;
	box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}

.logs-header {
	margin-bottom: 30px;
	border-bottom: 2px solid #667eea;
	padding-bottom: 20px;
}

.logs-header h1 {
	color: #2d3748;
	font-weight: 700;
	font-size: 1.75rem;
	margin: 0;
}

.logs-content {
	background: #f7fafc;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	padding: 20px;
	font-family: 'Courier New', monospace;
	font-size: 12px;
	line-height: 1.6;
	white-space: pre-wrap;
	word-wrap: break-word;
	max-height: 600px;
	overflow-y: auto;
	color: #2d3748;
}

.logs-content:empty::before {
	content: "📭 Логи відправлення email відсутні";
	color: #718096;
	font-style: italic;
}

.back-btn {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.65rem 1.5rem;
	background: #667eea;
	color: white;
	border-radius: 6px;
	text-decoration: none;
	font-weight: 600;
	margin-top: 20px;
	transition: all 0.2s;
}

.back-btn:hover {
	background: #5568d3;
	transform: translateY(-1px);
}

.info-box {
	background: #eef2ff;
	border-left: 4px solid #667eea;
	color: #4338ca;
	padding: 15px;
	border-radius: 6px;
	margin-bottom: 20px;
}

.clear-logs {
	padding: 0.65rem 1.5rem;
	background: #fecaca;
	color: #991b1b;
	border: none;
	border-radius: 6px;
	font-weight: 600;
	cursor: pointer;
	transition: all 0.2s;
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
}

.clear-logs:hover {
	background: #fca5a5;
}
</style>

<div class="logs-container">
	<div class="logs-header">
		<h1><i class="bi bi-file-text me-2" style="color: #667eea;"></i>Логи реєстраційних email</h1>
	</div>

	<div class="info-box">
		<strong>ℹ️ Інформація:</strong><br>
		Тут виводяться всі спроби відправлення реєстраційних email користувачам.
		Дані зберігаються локально в системі навіть якщо email не пройшов через SMTP сервер.
	</div>

	<div class="logs-content">
<?php
	$logFile = __DIR__ . '/../logs/registration_emails.log';
	
	if (file_exists($logFile) && filesize($logFile) > 0) {
		echo htmlspecialchars(file_get_contents($logFile));
	} else {
		echo "📭 Логи відправлення email відсутні";
	}
?>
	</div>

	<div style="margin-top: 20px;">
		<a href="user_list.php" class="back-btn">
			<i class="bi bi-arrow-left"></i>Назад до списку користувачів
		</a>
		<?php if (file_exists($logFile) && filesize($logFile) > 0): ?>
			<form method="POST" style="display: inline; margin-left: 10px;">
				<button type="submit" name="clear_logs" class="clear-logs" 
						onclick="return confirm('Ви впевнені? Це видалить всі логи!');">
					<i class="bi bi-trash"></i>Очистити логи
				</button>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php
// Обробка видалення логів
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
	$logFile = __DIR__ . '/../logs/registration_emails.log';
	if (file_exists($logFile)) {
		unlink($logFile);
		header('Location: email_logs.php');
		exit;
	}
}

$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>