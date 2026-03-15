<?php
class QRGenerator {
    public static function generateBase64PNG($data, $size = 200) {
        return self::generateViaAPI($data, $size);
    }

    /**
     * Завантажує QR-код і зберігає як PNG-файл у вказаній папці.
     * Повертає шлях до файлу відносно DOCUMENT_ROOT або false при помилці.
     */
    public static function saveToFile($data, $username, $size = 250) {
        $url = "https://api.qrserver.com/v1/create-qr-code/?size=" . $size . "x" . $size . "&data=" . urlencode($data);

        $png = null;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            $png = @curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpcode !== 200) $png = null;
        } elseif (ini_get('allow_url_fopen')) {
            $png = @file_get_contents($url);
        }

        if (!$png) return false;

        // Папка cms/uploads/qr/
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/qr';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'qr_' . md5($username . microtime(true)) . '.png';
        $fullPath = $dir . '/' . $filename;

        if (file_put_contents($fullPath, $png) === false) return false;

        return '/uploads/qr/' . $filename;
    }

    /**
     * Видаляє QR-файл користувача (викликається після першого входу).
     */
    public static function deleteUserQR($qrPath) {
        if (empty($qrPath)) return;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $qrPath;
        if (
            file_exists($fullPath) &&
            is_file($fullPath) &&
            strpos(realpath($fullPath), realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/qr')) === 0
        ) {
            @unlink($fullPath);
        }
    }
    
    private static function generateViaAPI($data, $size) {
        $url = "https://api.qrserver.com/v1/create-qr-code/?size=" . $size . "x" . $size . "&data=" . urlencode($data);
        
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            
            $response = @curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpcode == 200 && $response) {
                return "data:image/png;base64," . base64_encode($response);
            }
        } elseif (ini_get('allow_url_fopen')) {
            $response = @file_get_contents($url);
            if ($response) {
                return "data:image/png;base64," . base64_encode($response);
            }
        }
        
        return $url;
    }
}
?>