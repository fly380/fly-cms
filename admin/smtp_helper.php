<?php
/**
 * admin/smtp_helper.php
 * Єдина функція відправки email для всієї CMS.
 * Підтримує:
 *   - SSL  (port 465)  — з'єднання одразу через ssl://
 *   - TLS  (port 587)  — plain + STARTTLS upgrade
 *   - none (port 25)   — plain, без шифрування
 */

if (function_exists('fly_smtp_send')) return;

/**
 * @param string $to         Email одержувача
 * @param string $subject    Тема
 * @param string $bodyHtml   HTML тіло
 * @param string $bodyText   Текстове тіло
 * @return array ['sent'=>bool, 'error'=>string]
 */
function fly_smtp_send(string $to, string $subject, string $bodyHtml, string $bodyText): array {
    $result = ['sent' => false, 'error' => ''];

    // ── Завантажити конфіг ────────────────────────────────────────
    $cfgPath = __DIR__ . '/email_config.php';
    if (!file_exists($cfgPath)) {
        $result['error'] = 'email_config.php не знайдено';
        return $result;
    }
    $cfg = @include $cfgPath;
    if (!is_array($cfg) || empty($cfg['smtp'])) {
        $result['error'] = 'Невірний email_config.php';
        return $result;
    }
    $s = $cfg['smtp'];

    if (empty($s['enabled']))  { $result['error'] = 'SMTP вимкнено';                return $result; }
    if (empty($s['host']))     { $result['error'] = 'SMTP_HOST не заданий';          return $result; }
    if (empty($s['port']))     { $result['error'] = 'SMTP_PORT не заданий';          return $result; }
    if (empty($s['username'])) { $result['error'] = 'SMTP_USERNAME не заданий';      return $result; }
    if (empty($s['password'])) { $result['error'] = 'SMTP_PASSWORD не заданий';      return $result; }

    $host      = $s['host'];
    $port      = (int)$s['port'];
    $user      = $s['username'];
    $pass      = $s['password'];
    $enc       = strtolower($s['encryption'] ?? 'tls');
    $fromEmail = !empty($s['from_email']) ? $s['from_email'] : $user;
    $fromName  = !empty($s['from_name'])  ? $s['from_name']  : 'fly-CMS';

    // ── Відкрити з'єднання ────────────────────────────────────────
    // SSL (порт 465) — одразу ssl://, без STARTTLS
    // TLS (порт 587) — plain потім STARTTLS
    // none           — plain без шифрування
    $connectHost = ($enc === 'ssl') ? 'ssl://' . $host : $host;

    try {
        $errno  = 0;
        $errstr = '';
        $socket = @fsockopen($connectHost, $port, $errno, $errstr, 15);

        if (!$socket) {
            $result['error'] = "Не вдалося підключитися до {$connectHost}:{$port} — {$errstr}";
            return $result;
        }
        stream_set_timeout($socket, 15);

        // ── Привітання ────────────────────────────────────────────
        $r = fgets($socket, 1024);
        if (strpos($r, '220') === false) {
            fclose($socket);
            $result['error'] = 'Handshake: ' . trim($r);
            return $result;
        }

        // ── EHLO ──────────────────────────────────────────────────
        fwrite($socket, "EHLO localhost\r\n");
        $ehlo = '';
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            $ehlo .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }

        // ── STARTTLS (тільки для enc=tls) ─────────────────────────
        if ($enc === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $r = fgets($socket, 1024);
            if (strpos($r, '220') !== false) {
                stream_context_set_option($socket, 'ssl', 'verify_peer',      true);
                stream_context_set_option($socket, 'ssl', 'verify_peer_name', true);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                    // fallback TLS 1.0/1.1
                    @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                }
                // Повторний EHLO після апгрейду
                fwrite($socket, "EHLO localhost\r\n");
                $ehlo = '';
                while (!feof($socket)) {
                    $line = fgets($socket, 1024);
                    $ehlo .= $line;
                    if (substr($line, 3, 1) === ' ') break;
                }
            }
        }

        // ── AUTH ──────────────────────────────────────────────────
        // Спробуємо PLAIN, якщо відмова — LOGIN
        fwrite($socket, "AUTH PLAIN\r\n");
        $r = fgets($socket, 1024);

        if (strpos($r, '334') !== false) {
            fwrite($socket, base64_encode(chr(0) . $user . chr(0) . $pass) . "\r\n");
            $r = fgets($socket, 1024);
        } else {
            fwrite($socket, "AUTH LOGIN\r\n");
            $r = fgets($socket, 1024);
            if (strpos($r, '334') === false) {
                fclose($socket);
                $result['error'] = 'AUTH методи не підтримуються: ' . trim($r);
                return $result;
            }
            fwrite($socket, base64_encode($user) . "\r\n");
            fgets($socket, 1024);
            fwrite($socket, base64_encode($pass) . "\r\n");
            $r = fgets($socket, 1024);
        }

        if (strpos($r, '235') === false) {
            fclose($socket);
            $result['error'] = 'AUTH failed: ' . trim($r);
            return $result;
        }

        // ── MAIL FROM ─────────────────────────────────────────────
        fwrite($socket, "MAIL FROM: <{$fromEmail}>\r\n");
        $r = fgets($socket, 1024);
        if (strpos($r, '250') === false) {
            fclose($socket);
            $result['error'] = 'MAIL FROM: ' . trim($r);
            return $result;
        }

        // ── RCPT TO ───────────────────────────────────────────────
        fwrite($socket, "RCPT TO: <{$to}>\r\n");
        $r = fgets($socket, 1024);
        if (strpos($r, '250') === false) {
            fclose($socket);
            $result['error'] = 'RCPT TO: ' . trim($r);
            return $result;
        }

        // ── DATA ──────────────────────────────────────────────────
        fwrite($socket, "DATA\r\n");
        $r = fgets($socket, 1024);
        if (strpos($r, '354') === false) {
            fclose($socket);
            $result['error'] = 'DATA: ' . trim($r);
            return $result;
        }

        // ── Тіло листа ────────────────────────────────────────────
        $bnd  = '====FlyBnd_' . md5(uniqid('', true)) . '====';
        $hdrs = "From: {$fromName} <{$fromEmail}>\r\n"
              . "To: {$to}\r\n"
              . "Subject: {$subject}\r\n"
              . "MIME-Version: 1.0\r\n"
              . "Content-Type: multipart/alternative; boundary=\"{$bnd}\"\r\n"
              . "\r\n";

        $body = "--{$bnd}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $bodyText . "\r\n"
              . "--{$bnd}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $bodyHtml . "\r\n"
              . "--{$bnd}--\r\n";

        fwrite($socket, $hdrs . $body . "\r\n.\r\n");
        $r = fgets($socket, 1024);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        if (strpos($r, '250') !== false) {
            $result['sent'] = true;
        } else {
            $result['error'] = 'Сервер відхилив лист: ' . trim($r);
        }

    } catch (Exception $e) {
        $result['error'] = 'Exception: ' . $e->getMessage();
    }

    return $result;
}
