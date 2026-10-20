<?php
require_once __DIR__ . '/../vendor/phpqrcode/phpqrcode.php';
require_once __DIR__ . '/../config/database.php';

class QRGenerator {
    public static function dataUri($text, $size = 6, $margin = 2) {
        if (!class_exists('QRcode') || $text === '') {
            return '';
        }

        try {
            if (function_exists('imagecreatetruecolor')) {
                ob_start();
                QRcode::png($text, false, QR_ECLEVEL_M, $size, $margin);
                $png = ob_get_clean();
                self::restorePageContentType();
                if ($png !== false && $png !== '') {
                    return 'data:image/png;base64,' . base64_encode($png);
                }
            }
            ob_start();
            $svgReturn = QRcode::svg($text, false, QR_ECLEVEL_M, $size, $margin);
            $svgEchoed = ob_get_clean();
            self::restorePageContentType();
            $svg = is_string($svgReturn) && strpos($svgReturn, '<svg') !== false ? $svgReturn : $svgEchoed;
            if (is_string($svg) && strpos($svg, '<svg') !== false) {
                return 'data:image/svg+xml;base64,' . base64_encode($svg);
            }
        } catch (Exception $e) {
            if (ob_get_level() > 0) { ob_end_clean(); }
            self::restorePageContentType();
            error_log('QR generation failed: ' . $e->getMessage());
        }
        return '';
    }

    /**
     * The phpqrcode library sends its own Content-Type header (image/svg+xml or
     * image/png) while encoding. Since we embed the output as a data URI inside
     * HTML pages, restore the normal page content type afterwards.
     */
    private static function restorePageContentType() {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8', true);
        }
    }

    public function trayDataUri($tray) {
        $data = json_encode([
            'tray_id' => $tray['tray_id'],
            'egg_size' => $tray['egg_size'] ?? '',
            'batch' => $tray['batch_number'] ?? '',
            'production_date' => $tray['production_date'] ?? '',
            'expiry_date' => $tray['expiry_date'] ?? '',
            'quantity' => $tray['quantity'] ?? 0,
            'verification_url' => self::baseUrl() . '/Egglogan/verify/tray/' . $tray['tray_id']
        ], JSON_UNESCAPED_SLASHES);

        return self::dataUri($data, 5, 2);
    }

    public function variantDataUri($sizeCode) {
        $data = generateVariantQRData($sizeCode);
        return self::dataUri($data, 8, 3);
    }

    public function receiptDataUri($receiptNumber) {
        return self::dataUri(self::baseUrl() . '/Egglogan/verify/' . rawurlencode($receiptNumber), 5, 2);
    }

    private static function baseUrl() {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
