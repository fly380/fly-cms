<?php
/**
 * templates/login.php — Контролер входу
 * HTML — у views/auth/login.twig та views/auth/totp.twig
 */

ini_set('session.cookie_httponly', 1);
if (!empty($_SERVER['HTTPS'])) ini_set('session.cookie_secure', 1);
session_set_cookie_params(['lifetime'=>3600,'path'=>'/','httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']);
session_start();

$max_inactive = 3600;
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $max_inactive) {
    session_unset(); session_destroy(); session_start();
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../data/log_action.php';
require_once __DIR__ . '/../data/totp.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../data/TwigFactory.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;

$twig   = TwigFactory::create();
$error  = '';
$recaptcha_enabled  = get_setting('recaptcha_enabled') === 'on';
$recaptcha_site_key = get_setting('recaptcha_site_key');
$recaptcha_secret   = get_setting('recaptcha_secret_key');

// Скасування 2FA
if (isset($_GET['cancel_2fa'])) {
    session_unset(); session_destroy();
    header('Location: /templates/login.php'); exit;
}

// ── КРОК 2: верифікація TOTP ──
if (!empty($_SESSION['totp_pending'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $error = 'Невірний токен безпеки.';
        } else {
            $code   = trim($_POST['totp_code'] ?? '');
            $secret = $_SESSION['totp_secret_pending'] ?? '';
            if (TOTP::verify($secret, $code)) {
                session_regenerate_id(true);
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $_SESSION['pending_username'];
                $_SESSION['role']     = $_SESSION['pending_role'];
                log_action("Вхід з 2FA ({$_SESSION['role']})", $_SESSION['username']);

                // Видалення QR-файлу після першого входу
                try {
                    $pdo_qr = fly_db();
                    $qr_cols = $pdo_qr->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
                    if (in_array('qr_file', $qr_cols)) {
                        $qr_stmt = $pdo_qr->prepare("SELECT qr_file FROM users WHERE login = ?");
                        $qr_stmt->execute([$_SESSION['username']]);
                        $qr_file = $qr_stmt->fetchColumn();
                        if ($qr_file && strpos($qr_file, '/uploads/qr/') === 0) {
                            @unlink(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $qr_file);
                        }
                        if ($qr_file) {
                            $pdo_qr->prepare("UPDATE users SET qr_file = NULL WHERE login = ?")->execute([$_SESSION['username']]);
                        }
                    }
                } catch (Exception $e) {}

                unset($_SESSION['totp_pending'],$_SESSION['totp_secret_pending'],
                      $_SESSION['pending_username'],$_SESSION['pending_role'],
                      $_SESSION['csrf_token'],$_SESSION['login_attempts']);
                header('Location: /index.php'); exit;
            } else {
                sleep(1);
                $_SESSION['login_attempts']++;
                log_action("Невірний TOTP-код", "Логін: {$_SESSION['pending_username']}, IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
                $error = 'Невірний код. Перевірте Google Authenticator та спробуйте ще раз.';
                if ($_SESSION['login_attempts'] >= 8) {
                    session_unset(); session_destroy();
                    header('Location: /templates/login.php?blocked=1'); exit;
                }
            }
        }
    }
    echo $twig->render('auth/totp.twig', ['csrf_token' => $_SESSION['csrf_token'], 'error' => $error]);
    exit;
}

// ── КРОК 1: логін/пароль ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['totp_code'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Невірний токен безпеки.';
    } elseif ($_SESSION['login_attempts'] >= 5) {
        $error = 'Забагато невдалих спроб. Спробуйте пізніше.';
    } else {
        if ($recaptcha_enabled) {
            $token = $_POST['recaptcha_token'] ?? '';
            $resp  = @file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret='
                     . urlencode($recaptcha_secret) . '&response=' . urlencode($token));
            $data  = json_decode($resp, true);
            if (!($data['success'] ?? false) || ($data['score'] ?? 0) < 0.5) {
                $error = 'Перевірка reCAPTCHA не пройдена.';
            }
        }
        if (!$error) {
            $username = trim($_POST['login'] ?? '');
            $password = $_POST['password'] ?? '';
            if (empty($username) || empty($password)) {
                $error = 'Усі поля обовязкові.';
            } else {
                try {
                    $pdo     = fly_db();
                    $cols    = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
                    $hasTotp = in_array('totp_enabled', $cols);
                    $fields  = $hasTotp ? "id, login, password, role, totp_secret, totp_enabled" : "id, login, password, role";
                    $stmt    = $pdo->prepare("SELECT {$fields} FROM users WHERE login = :login");
                    $stmt->execute([':login' => $username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && password_verify($password, $user['password'])) {
                        if ($hasTotp && !empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                            $_SESSION['totp_pending']        = true;
                            $_SESSION['totp_secret_pending'] = $user['totp_secret'];
                            $_SESSION['pending_username']    = $username;
                            $_SESSION['pending_role']        = $user['role'];
                            $_SESSION['csrf_token']          = bin2hex(random_bytes(32));
                            header('Location: /templates/login.php'); exit;
                        }
                        session_regenerate_id(true);
                        $_SESSION['loggedin'] = true;
                        $_SESSION['username'] = $username;
                        $_SESSION['role']     = $user['role'];
                        log_action("Вхід ({$user['role']})", $username);

                        // Видалення QR після першого входу
                        try {
                            if (in_array('qr_file', $cols)) {
                                $qr_stmt = $pdo->prepare("SELECT qr_file FROM users WHERE login = ?");
                                $qr_stmt->execute([$username]);
                                $qr_file = $qr_stmt->fetchColumn();
                                if ($qr_file && strpos($qr_file, '/uploads/qr/') === 0) {
                                    @unlink(rtrim($_SERVER['DOCUMENT_ROOT'],'/') . $qr_file);
                                    $pdo->prepare("UPDATE users SET qr_file = NULL WHERE login = ?")->execute([$username]);
                                }
                            }
                        } catch (Exception $e) {}

                        $_SESSION['login_attempts'] = 0;
                        unset($_SESSION['csrf_token']);
                        header('Location: /index.php'); exit;
                    } else {
                        sleep(1);
                        $_SESSION['login_attempts']++;
                        log_action("Невдала спроба входу", "Логін: {$username}, IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
                        $error = 'Невірний логін або пароль.';
                    }
                } catch (PDOException $e) {
                    error_log('DB error: ' . $e->getMessage());
                    $error = 'Помилка бази даних. Спробуйте пізніше.';
                }
            }
        }
    }
}

echo $twig->render('auth/login.twig', [
    'csrf_token'        => $_SESSION['csrf_token'],
    'error'             => $error,
    'blocked'           => isset($_GET['blocked']),
    'recaptcha_enabled' => $recaptcha_enabled,
    'recaptcha_site_key'=> $recaptcha_site_key,
]);
