<?php
// Показ усіх помилок
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

// Перевірка прав доступу
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'redaktor', 'superadmin'])) {
	header('Location: /templates/login.php');
	exit;
}

// Підключення функцій
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../data/totp.php';
require_once __DIR__ . '/qr_generator.php';

$error = '';
$success = '';
$registration_data = null;
$pdo = connectToDatabase();

// ── Міграція: додаємо колонку qr_file якщо відсутня ──────────────
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('qr_file', $cols)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN qr_file TEXT DEFAULT NULL");
    $cols[] = 'qr_file';
}

// Перевірка наявності TOTP полів в БД
$hasTotp  = in_array('totp_enabled', $cols) && in_array('totp_secret', $cols);
$hasEmail = in_array('email', $cols);

// Функція для відправки email через socket SMTP
function sendRegistrationEmail($email, $username, $password, $totp_secret = '') {
	$subject = '📧 Реєстрація у системі CMS';
	
	// Генеруємо QR код для 2FA якщо є секрет
	$qr_url = '';
	if (!empty($totp_secret)) {
		// Формат для Google Authenticator
		$qr_data = "otpauth://totp/CMS:$username?secret=$totp_secret&issuer=CMS";
		// Посилання на QR прямо через API — файл не зберігаємо тут
		$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($qr_data);
	}
	
	// HTML версія листа з QR кодом
	$body_html = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>\n<style>\n";
	$body_html .= "body { font-family: Arial, sans-serif; color: #2d3748; }\n";
	$body_html .= ".container { max-width: 600px; margin: 0 auto; padding: 20px; }\n";
	$body_html .= ".header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }\n";
	$body_html .= ".data-block { background: #f7fafc; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #667eea; }\n";
	$body_html .= ".data-label { font-weight: bold; color: #667eea; margin-bottom: 5px; }\n";
	$body_html .= ".data-value { font-family: monospace; background: white; padding: 10px; border-radius: 4px; word-break: break-all; }\n";
	$body_html .= ".qr-block { text-align: center; margin: 20px 0; padding: 20px; background: #f7fafc; border-radius: 6px; border-left: 4px solid #667eea; }\n";
	$body_html .= ".qr-block img { max-width: 200px; height: auto; }\n";
	$body_html .= ".warning { background: #fef08a; border-left: 4px solid #ffc107; padding: 15px; border-radius: 6px; margin: 20px 0; }\n";
	$body_html .= ".footer { text-align: center; color: #718096; font-size: 12px; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 15px; }\n";
	$body_html .= "</style>\n</head>\n<body>\n";
	
	$body_html .= "<div class='container'>\n";
	$body_html .= "<div class='header'>\n";
	$body_html .= "<h2 style='margin: 0;'>📧 Ласкаво просимо!</h2>\n";
	$body_html .= "<p style='margin: 5px 0 0 0;'>Ви були зареєстровані в системі CMS</p>\n";
	$body_html .= "</div>\n";
	
	$body_html .= "<div class='data-block'>\n";
	$body_html .= "<div class='data-label'>👤 Логін</div>\n";
	$body_html .= "<div class='data-value'>" . htmlspecialchars($username) . "</div>\n";
	$body_html .= "</div>\n";
	
	$body_html .= "<div class='data-block'>\n";
	$body_html .= "<div class='data-label'>🔐 Пароль</div>\n";
	$body_html .= "<div class='data-value'>" . htmlspecialchars($password) . "</div>\n";
	$body_html .= "</div>\n";
	
	if (!empty($totp_secret)) {
		$body_html .= "<div class='data-block'>\n";
		$body_html .= "<div class='data-label'>🔑 Секретний ключ 2FA</div>\n";
		$body_html .= "<div class='data-value'>" . htmlspecialchars($totp_secret) . "</div>\n";
		$body_html .= "<p style='margin: 10px 0 0 0; font-size: 12px; color: #718096;'>Збережіть цей ключ в безпечному місці</p>\n";
		$body_html .= "</div>\n";
		
		if (!empty($totp_secret)) {
			$qr_link = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode("otpauth://totp/CMS:$username?secret=$totp_secret&issuer=CMS");
			
			$body_html .= "<div class='qr-block'>\n";
			$body_html .= "<p style='color: #718096; margin-bottom: 10px;'><strong>📱 Сканіруйте QR код</strong></p>\n";
			$body_html .= "<p style='color: #718096; margin: 0 0 10px 0; font-size: 12px;'>Натисніть на посилання щоб завантажити QR код</p>\n";
			$body_html .= "<a href='" . htmlspecialchars($qr_link) . "' style='display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;'>📥 Завантажити QR код</a>\n";
			$body_html .= "</div>\n";
		}
	}
	
	$body_html .= "<div class='warning'>\n";
	$body_html .= "<strong>⚠️ ВАЖЛИВО:</strong><br>\n";
	$body_html .= "1. Зберігайте ці дані в безпеці<br>\n";
	$body_html .= "2. Не передавайте ці дані третім особам\n";
	$body_html .= "</div>\n";
	
	$body_html .= "<div class='footer'>\n";
	$body_html .= "<p>З повагою,<br><strong>Адміністрація CMS</strong></p>\n";
	$body_html .= "</div>\n";
	$body_html .= "</div>\n";
	$body_html .= "</body>\n</html>\n";
	
	// Текстова версія для fallback
	$body_text = "Привіт!\n\n";
	$body_text .= "Ви були зареєстровані в системі управління контентом.\n\n";
	$body_text .= "═══════════════════════════════════════════\n";
	$body_text .= "РЕЄСТРАЦІЙНІ ДАНІ\n";
	$body_text .= "═══════════════════════════════════════════\n\n";
	$body_text .= "👤 Логін: " . $username . "\n";
	$body_text .= "🔐 Пароль: " . $password . "\n";
	
	if (!empty($totp_secret)) {
		$body_text .= "🔑 Секретний ключ 2FA: " . $totp_secret . "\n";
		$body_text .= "   (Збережіть цей ключ в безпечному місці)\n\n";
		$body_text .= "📱 QR код для Google Authenticator:\n";
		$body_text .= "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode("otpauth://totp/CMS:$username?secret=$totp_secret&issuer=CMS") . "\n\n";
	}
	
	$body_text .= "\n═══════════════════════════════════════════\n\n";
	$body_text .= "⚠️  ВАЖЛИВО:\n";
	$body_text .= "1. Будь ласка, змініть пароль при першому вході\n";
	$body_text .= "2. Зберігайте ці дані в безпеці\n";
	$body_text .= "3. Не передавайте ці дані третім особам\n\n";
	$body_text .= "З повагою,\nАдміністрація CMS\n";
	
	$mailResult = ['sent' => false, 'error' => 'Email не налаштований', 'method' => 'None'];
	
	// Завантажуємо конфіг
	$configPath = __DIR__ . '/email_config.php';
	
	if (!file_exists($configPath)) {
		$mailResult['error'] = 'email_config.php не знайдено';
		goto log_result;
	}
	
	// Завантажимо конфіг
	$config = @include($configPath);
	
	// Перевіримо чи конфіг завантажений правильно
	if (!is_array($config)) {
		$mailResult['error'] = 'email_config.php не повертає масив';
		goto log_result;
	}
	
	if (!isset($config['smtp'])) {
		$mailResult['error'] = 'smtp ключ відсутній в конфігу';
		goto log_result;
	}
	
	$smtp = $config['smtp'];
	
	// Перевіримо чи SMTP активна
	if (empty($smtp['enabled'])) {
		$mailResult['error'] = 'SMTP вимкнена в конфігу';
		goto log_result;
	}
	
	// Перевіримо всі необхідні параметри
	if (empty($smtp['host'])) {
		$mailResult['error'] = 'host не налаштований';
		goto log_result;
	}
	if (empty($smtp['port'])) {
		$mailResult['error'] = 'port не налаштований';
		goto log_result;
	}
	if (empty($smtp['username'])) {
		$mailResult['error'] = 'username не налаштований';
		goto log_result;
	}
	if (empty($smtp['password'])) {
		$mailResult['error'] = 'password не налаштований';
		goto log_result;
	}
	
	// Всі параметри є - спробуємо SMTP
	$host = $smtp['host'];
	$port = (int)$smtp['port'];
	$username_smtp = $smtp['username'];
	$password_smtp = $smtp['password'];
	$encryption = $smtp['encryption'] ?? 'tls';
	$from_email = $smtp['from_email'] ?? 'noreply@cms.local';
	$from_name = $smtp['from_name'] ?? 'CMS';
	
	try {
		$res = fly_smtp_send($email, $subject, $body_html, $body_text);
		if ($res['sent']) {
			$mailResult = ['sent' => true, 'error' => '', 'method' => 'SMTP Socket'];
		} else {
			$mailResult['error'] = $res['error'];
		}
	} catch (Exception $e) {
		$mailResult['error'] = 'Exception: ' . $e->getMessage();
	}
	
	// Fallback на PHP mail() якщо SMTP не вдалася
	log_result:
	if (!$mailResult['sent'] && function_exists('mail')) {
		$boundary = '====Boundary_' . md5(time()) . '====';
		
		$headers = "From: noreply@cms.local\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
		
		$message = "--" . $boundary . "\r\n";
		$message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
		$message .= $body_text . "\r\n";
		
		$message .= "--" . $boundary . "\r\n";
		$message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
		$message .= $body_html . "\r\n";
		
		$message .= "--" . $boundary . "--\r\n";
		
		if (@mail($email, $subject, $message, $headers)) {
			$mailResult = ['sent' => true, 'error' => '', 'method' => 'PHP mail()'];
		} else {
			$mailResult['method'] = 'PHP mail()';
			if (empty($mailResult['error'])) {
				$mailResult['error'] = 'mail() failed';
			}
		}
	}
	
	// Логування
	$logDir = __DIR__ . '/../logs';
	if (!is_dir($logDir)) {
		@mkdir($logDir, 0755, true);
	}
	
	$logFile = $logDir . '/registration_emails.log';
	$logEntry = date('Y-m-d H:i:s') . " | " . $email . " | " . $username . " Status: ";
	$logEntry .= ($mailResult['sent'] ? "SENT ✅" : "FAILED") . " | Method: " . $mailResult['method'];
	if (!$mailResult['sent']) {
		$logEntry .= " | Error: " . $mailResult['error'];
	}
	$logEntry .= "\n────\n";
	
	@file_put_contents($logFile, $logEntry, FILE_APPEND);
	
	return $mailResult;
}

// Обробка форми
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username     = trim($_POST['username'] ?? '');
	$password     = trim($_POST['password'] ?? '');
	$email        = trim($_POST['email'] ?? '');
	$role         = $_POST['role'] ?? 'user';
	$display_name = trim($_POST['display_name'] ?? '');
	$enable_2fa   = isset($_POST['enable_2fa']) ? 1 : 0;

	if (empty($username)) {
		$error = '❌ Логін обов\'язковий.';
	} elseif (strlen($username) < 3) {
		$error = '❌ Логін має бути мінімум 3 символи.';
	} elseif (empty($password)) {
		$error = '❌ Пароль обов\'язковий.';
	} elseif (strlen($password) < 6) {
		$error = '❌ Пароль має бути мінімум 6 символів.';
	} elseif ($hasEmail && empty($email)) {
		$error = '❌ Email обов\'язковий.';
	} elseif ($hasEmail && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$error = '❌ Некоректний формат email.';
	} elseif (userExists($pdo, $username)) {
		$error = '❌ Користувач з таким логіном вже існує.';
	} else {
		try {
			$totp_secret = '';
			
			if ($enable_2fa && $hasTotp) {
				$totp_secret = TOTP::generateSecret();
			}
			
			createUser($pdo, $username, $password, $role, $display_name === '' ? $username : $display_name);
			
			if ($hasEmail && !empty($email)) {
				$pdo->prepare("UPDATE users SET email = ? WHERE login = ?")->execute([$email, $username]);
			}
			
			if ($enable_2fa && $hasTotp && !empty($totp_secret)) {
				$pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE login = ?")->execute([$totp_secret, $username]);
				
				// Зберігаємо QR у uploads/qr/ — один файл, шлях в БД
				$qr_otpauth = "otpauth://totp/CMS:{$username}?secret={$totp_secret}&issuer=CMS";
				$qr_saved   = QRGenerator::saveToFile($qr_otpauth, $username);
				if ($qr_saved) {
					$pdo->prepare("UPDATE users SET qr_file = ? WHERE login = ?")->execute([$qr_saved, $username]);
				}
			}
			
			// Відправляємо email
			$mailResult = sendRegistrationEmail($email, $username, $password, $totp_secret);
			
			if ($mailResult['sent']) {
				$success = '✅ Користувач успішно створений! ✉️ Email відправлено!';
				header('Refresh: 3; url=/admin/user_list.php');
			} else {
				// Email не вдалося - показуємо дані
				$registration_data = [
					'username' => $username,
					'password' => $password,
					'email' => $email,
					'totp_secret' => $totp_secret,
					'created_at' => date('Y-m-d H:i:s')
				];
			}
		} catch (Exception $e) {
			$error = '❌ Помилка: ' . $e->getMessage();
		}
	}
}

ob_start();
?>

<style>
.form-card { background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); max-width: 600px; margin: 0 auto; }
.form-header { margin-bottom: 30px; text-align: center; border-bottom: 2px solid #667eea; padding-bottom: 20px; }
.form-header h1 { color: #2d3748; font-weight: 700; font-size: 1.75rem; margin: 0; }
.form-header p { color: #718096; font-size: 0.95rem; margin-top: 8px; }
.form-group { margin-bottom: 20px; }
.form-label { font-weight: 600; color: #2d3748; margin-bottom: 8px; display: block; }
.form-control, .form-select { border: 1px solid #cbd5e0; border-radius: 6px; padding: 0.65rem 0.95rem; font-size: 0.95rem; transition: all 0.2s; width: 100%; }
.form-control:focus, .form-select:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); outline: none; }
.form-text { font-size: 0.85rem; color: #718096; margin-top: 6px; }
.form-check { display: flex; align-items: center; gap: 10px; padding: 15px; background: #f7fafc; border-radius: 6px; border-left: 4px solid #667eea; }
.form-check-input { width: 1.2rem; height: 1.2rem; border: 1px solid #cbd5e0; border-radius: 4px; cursor: pointer; flex-shrink: 0; }
.form-check-input:checked { background: #667eea; border-color: #667eea; }
.form-check-label { cursor: pointer; color: #2d3748; font-weight: 500; }
.form-actions { display: flex; gap: 12px; margin-top: 30px; justify-content: center; }
.btn { padding: 0.65rem 1.5rem; border-radius: 6px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; }
.btn-primary { background: #667eea; color: white; }
.btn-primary:hover { background: #5568d3; }
.btn-secondary { background: #e2e8f0; color: #2d3748; }
.btn-secondary:hover { background: #cbd5e0; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid; }
.alert-danger { background: #fee2e2; color: #991b1b; border-color: #dc3545; }
.alert-success { background: #dcfce7; color: #166534; border-color: #28a745; }
.password-note { background: #eef2ff; border-left: 4px solid #667eea; color: #4338ca; padding: 12px 16px; border-radius: 6px; font-size: 0.85rem; margin-top: 20px; }
.registration-data-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; padding: 30px; margin-top: 20px; }
.registration-data-card h3 { color: white; margin: 0 0 20px 0; font-size: 1.3rem; text-align: center; }
.registration-field { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 6px; padding: 12px 16px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.registration-field label { font-weight: 600; min-width: 100px; }
.registration-field-value { font-family: monospace; word-break: break-all; flex: 1; }
.copy-btn { background: white; color: #667eea; border: none; padding: 0.5rem 1rem; border-radius: 4px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.registration-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: center; }
.btn-back { background: white; color: #667eea; padding: 0.7rem 1.5rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; }
</style>

<div class="form-card">
	<div class="form-header">
		<h1><i class="bi bi-person-plus me-2" style="color: #667eea;"></i>Новий користувач</h1>
	</div>

	<?php
	// ── SMTP статус ──────────────────────────────────────────────
	$_smtpCfg  = @include __DIR__ . '/email_config.php';
	$_smtpArr  = is_array($_smtpCfg) ? ($_smtpCfg['smtp'] ?? []) : [];
	$_smtpOn   = !empty($_smtpArr['enabled']);
	$_smtpHost = $_smtpArr['host']     ?? '';
	$_smtpUser = $_smtpArr['username'] ?? '';
	$_smtpPass = $_smtpArr['password'] ?? '';
	$_smtpOk   = $_smtpOn && $_smtpHost && $_smtpUser && $_smtpPass;
	$_isGmail  = (strpos($_smtpHost, 'gmail') !== false);
	?>
	<div style="border-radius:7px;padding:.6rem 1rem;font-size:.82rem;margin-bottom:1rem;
		<?= $_smtpOk ? 'background:#f0fdf4;border:1px solid #86efac;color:#166534' : 'background:#fff8e1;border:1px solid #f59e0b;color:#92400e' ?>">
		<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
			<span><?= $_smtpOk ? '✅' : '⚠' ?></span>
			<span>
			<?php if ($_smtpOk): ?>
				Email-відправка активна &mdash; <strong><?= htmlspecialchars($_smtpHost) ?></strong> / <?= htmlspecialchars($_smtpUser) ?>
			<?php elseif (!$_smtpOn): ?>
				<strong>SMTP вимкнено</strong> &mdash; реєстраційні листи не надсилаються.
			<?php elseif (!$_smtpHost): ?>
				<strong>SMTP_HOST порожній</strong> &mdash; вкажіть сервер (напр. <code>smtp.gmail.com</code>).
			<?php else: ?>
				<strong>SMTP не налаштовано</strong> &mdash; відсутній логін або пароль.
			<?php endif; ?>
			</span>
			<?php if ($_isGmail): ?>
			<span style="background:rgba(0,0,0,.07);border-radius:4px;padding:.1rem .5rem;font-size:.76rem">
				📌 Gmail: потрібен <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener"
					style="color:inherit;font-weight:700">пароль додатків</a>, не звичайний пароль
			</span>
			<?php endif; ?>
			<a href="/admin/smtp_settings.php"
				style="margin-left:auto;font-size:.8rem;font-weight:600;color:#1e3a6e;text-decoration:none;border:1px solid #1e3a6e;border-radius:5px;padding:.2rem .6rem;white-space:nowrap">
				⚙ Налаштування SMTP
			</a>
		</div>
	</div>

	<?php if ($error): ?>
		<div class="alert alert-danger"><strong>Помилка!</strong><br><?= htmlspecialchars($error) ?></div>
	<?php endif; ?>

	<?php if ($success): ?>
		<div class="alert alert-success"><strong>Успіх!</strong><br><?= htmlspecialchars($success) ?></div>
	<?php elseif ($registration_data): ?>
		<div class="registration-data-card">
			<h3>✅ Користувач створений!</h3>
			<p style="margin: 0 0 20px 0;">Email не відправлено. Скопіюйте дані:</p>
			
			<div class="registration-field">
				<label>👤 Логін:</label>
				<span class="registration-field-value"><?= htmlspecialchars($registration_data['username']) ?></span>
				<button class="copy-btn" onclick="navigator.clipboard.writeText('<?= $registration_data['username'] ?>')">📋</button>
			</div>

			<div class="registration-field">
				<label>🔐 Пароль:</label>
				<span class="registration-field-value"><?= htmlspecialchars($registration_data['password']) ?></span>
				<button class="copy-btn" onclick="navigator.clipboard.writeText('<?= $registration_data['password'] ?>')">📋</button>
			</div>

			<?php if (!empty($registration_data['totp_secret'])): ?>
			<div class="registration-field">
				<label>🔑 2FA Ключ:</label>
				<span class="registration-field-value"><?= htmlspecialchars($registration_data['totp_secret']) ?></span>
				<button class="copy-btn" onclick="navigator.clipboard.writeText('<?= $registration_data['totp_secret'] ?>')">📋</button>
			</div>
			<?php endif; ?>

			<div class="registration-actions">
				<a href="user_list.php" class="btn-back"><i class="bi bi-arrow-left"></i>Список користувачів</a>
			</div>
		</div>
	<?php else: ?>
	<form action="add_user.php" method="POST">
		<div class="form-group">
			<label for="username" class="form-label"><i class="bi bi-person me-1"></i>Логін</label>
			<input type="text" id="username" name="username" class="form-control" placeholder="user123" required>
		</div>

		<div class="form-group">
			<label for="email" class="form-label"><i class="bi bi-envelope me-1"></i>Email</label>
			<input type="email" id="email" name="email" class="form-control" placeholder="user@example.com" <?php echo $hasEmail ? 'required' : ''; ?>>
		</div>

		<div class="form-group">
			<label for="display_name" class="form-label"><i class="bi bi-chat-left-text me-1"></i>Ім'я</label>
			<input type="text" id="display_name" name="display_name" class="form-control" placeholder="Вася Учинський">
		</div>

		<div class="form-group">
			<label for="password" class="form-label"><i class="bi bi-lock me-1"></i>Пароль</label>
			<input type="password" id="password" name="password" class="form-control" required>
		</div>

		<div class="form-group">
			<label for="role" class="form-label"><i class="bi bi-shield-check me-1"></i>Роль</label>
			<select id="role" name="role" class="form-select">
				<option value="user">👤 Користувач</option>
				<option value="redaktor">✏️ Редактор</option>
				<option value="admin" selected>🔐 Адміністратор</option>
				<?php if (is_superadmin()): ?>
				<option value="superadmin">👑 SuperAdmin</option>
				<?php endif; ?>
			</select>
		</div>

		<?php if ($hasTotp): ?>
		<div class="form-check">
			<input type="checkbox" id="enable_2fa" name="enable_2fa" class="form-check-input" checked>
			<label for="enable_2fa" class="form-check-label">
				<strong>Увімкнути 2FA</strong><br><small>Користувач підтвердить при вході</small>
			</label>
		</div>
		<?php endif; ?>

		<div class="form-actions">
			<a href="user_list.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i>Назад</a>
			<button type="submit" class="btn btn-primary"><i class="bi bi-person-plus"></i>Додати</button>
		</div>
	</form>
	<?php endif; ?>

	<?php if (!$registration_data): ?>
	<div class="password-note">
		<strong>Система намагається відправити email.</strong> Якщо не вдалося - дані будуть показані на екрані.
	</div>
	<?php endif; ?>
</div>

<?php
$content_html = ob_get_clean();
include __DIR__ . '/admin_template.php';
?>