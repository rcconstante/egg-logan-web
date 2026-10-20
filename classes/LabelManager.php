<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/qr_generator.php';

class LabelManager {
    private $db;
    private $qrGenerator;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->qrGenerator = new QRGenerator();
    }

    public function generateLabelHtml($tray) {
        $color = $this->getSizeColor($tray['egg_size']);
        $isCracked = $tray['egg_size'] === 'CR';

        $batchDisplay = $tray['batch_number'] ?? 'N/A';
        if (preg_match('/^BATCH-/', $batchDisplay)) {
            $batchDisplay = substr($batchDisplay, 6);
        }

        $traySeq = $tray['tray_sequence'] ?? '';
        $totalTrays = $tray['total_trays'] ?? '';
        $trayLabel = $traySeq ? "TRAY {$traySeq}" . ($totalTrays ? "/{$totalTrays}" : '') : '';

        $html = '<div class="sticker" style="border:2px solid ' . $color . ';padding:10px;width:250px;font-family:Arial,sans-serif;position:relative;">';
        
        if ($isCracked) {
            $html .= '<div style="position:absolute;top:-5px;right:-5px;background:red;color:white;padding:2px 8px;font-size:10px;font-weight:bold;border-radius:3px;">⚠ URGENT</div>';
        }

        $html .= '<div style="background:' . $color . ';color:white;padding:5px;text-align:center;font-weight:bold;font-size:14px;">';
        $html .= $this->getSizeLabel($tray['egg_size']) . ' - ' . strtoupper($tray['egg_size']);
        $html .= '</div>';

        $html .= '<div style="text-align:center;padding:6px 0;border-bottom:2px solid #ddd;margin-bottom:5px;">';
        $html .= '<div style="font-size:11px;color:#666;font-weight:600;">BATCH</div>';
        $html .= '<div style="font-size:22px;font-weight:900;color:#333;line-height:1;">#' . htmlspecialchars($batchDisplay) . '</div>';
        if ($trayLabel) {
            $html .= '<div style="font-size:13px;font-weight:800;color:' . $color . ';margin-top:3px;">' . $trayLabel . '</div>';
        }
        $html .= '</div>';

        $html .= '<div style="padding:0 5px;font-size:11px;">';
        $html .= '<strong>Prod:</strong> ' . date('M d, Y', strtotime($tray['production_date'])) . '<br>';
        $html .= '<strong>Exp:</strong> ' . date('M d, Y', strtotime($tray['expiry_date'])) . '<br>';
        $html .= '<strong>Qty:</strong> ' . ($tray['quantity'] ?? $tray['tray_capacity'] ?? 30) . ' pcs';
        $html .= '</div>';

        if (!empty($tray['qr_data_uri'])) {
            $html .= '<div style="text-align:center;margin-top:5px;">';
            $html .= '<img src="' . $tray['qr_data_uri'] . '" style="width:80px;height:80px;">';
            $html .= '</div>';
        }

        $html .= '<div style="font-size:8px;text-align:center;color:#999;margin-top:3px;">';
        $html .= 'EggLogan Egg Storage System';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function generateNumberSticker($tray) {
        $batchDisplay = $tray['batch_number'] ?? 'N/A';
        if (preg_match('/^BATCH-/', $batchDisplay)) {
            $batchDisplay = substr($batchDisplay, 6);
        }
        $traySeq = $tray['tray_sequence'] ?? 1;
        $sizeLabel = $tray['egg_size'] ?? '';

        $html = '<div style="border:2px solid #000;padding:6px 8px;margin:6px 0;text-align:center;">';
        $html .= '<div style="font-size:9px;color:#333;font-weight:600;">B' . htmlspecialchars($batchDisplay) . '</div>';
        $html .= '<div style="font-size:28px;font-weight:900;color:#000;line-height:1.1;margin:2px 0;">' . $traySeq . '</div>';
        $html .= '<div style="font-size:8px;font-weight:700;color:#fff;background:#000;padding:1px 5px;display:inline-block;">' . $sizeLabel . '</div>';
        $html .= '</div>';

        return $html;
    }

    public function batchLabelHtml($trays) {
        $html = '<html><head><style>
            @page { margin: 5mm; size: 58mm 40mm; }
            body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
            .sticker-page { display: flex; flex-wrap: wrap; gap: 5px; padding: 5px; }
            .sticker { width: 58mm; height: 40mm; box-sizing: border-box; page-break-inside: avoid; }
        </style></head><body><div class="sticker-page">';
        
        foreach ($trays as $tray) {
            $html .= $this->generateLabelHtml($tray);
        }

        $html .= '</div></body></html>';
        return $html;
    }

    public function batchNumberStickersHtml($trays) {
        $html = '<html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:5px;font-family:Arial,sans-serif;">';
        
        foreach ($trays as $tray) {
            $html .= $this->generateNumberSticker($tray);
        }

        $html .= '</body></html>';
        return $html;
    }

    public function getBatchLabels($batchId) {
        $trays = $this->db->fetchAll(
            "SELECT t.*, b.batch_number, s.tray_capacity,
                    COALESCE(t.tray_sequence, ROW_NUMBER() OVER (ORDER BY t.created_at, t.tray_id)) as tray_sequence,
                    (SELECT COUNT(*) FROM egg_trays WHERE batch_id = ? AND status != 'deleted') as total_trays
             FROM egg_trays t
             JOIN batches b ON t.batch_id = b.id
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.batch_id = ? AND t.status != 'deleted'
             ORDER BY t.tray_sequence, t.created_at, t.tray_id",
            [$batchId, $batchId]
        );

        foreach ($trays as &$tray) {
            $tray['qr_data_uri'] = $this->qrGenerator->trayDataUri($tray);
        }

        return $trays;
    }

    public function getSingleLabel($trayId) {
        $tray = $this->db->fetchOne(
            "SELECT t.*, b.batch_number, s.tray_capacity,
                    (SELECT COUNT(*) FROM egg_trays WHERE batch_id = t.batch_id AND status != 'deleted') as total_trays
             FROM egg_trays t
             JOIN batches b ON t.batch_id = b.id
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.tray_id = ? AND t.status != 'deleted'",
            [$trayId]
        );
        if (!$tray) return null;
        $tray['qr_data_uri'] = $this->qrGenerator->trayDataUri($tray);
        return $tray;
    }

    public function generateVariantLabelHtml($eggSize) {
        $color = $this->getSizeColor($eggSize);
        $label = $this->getSizeLabel($eggSize);

        $qrData = generateVariantQRData($eggSize);
        $qrUri = $this->qrGenerator->variantDataUri($eggSize);

        $stock = $this->db->fetchOne(
            "SELECT COUNT(*) as tray_count, COALESCE(SUM(t.quantity), 0) as total_eggs
             FROM egg_trays t WHERE t.egg_size = ? AND t.status = 'in_stock'",
            [$eggSize]
        );

        $price = $this->db->fetchOne(
            "SELECT price_per_tray, tray_capacity FROM egg_sizes WHERE size_code = ?",
            [$eggSize]
        );

        $html = '<div class="sticker" style="border:3px solid ' . $color . ';padding:12px;width:280px;font-family:Arial,sans-serif;position:relative;">';
        $html .= '<div style="background:' . $color . ';color:white;padding:8px;text-align:center;font-weight:bold;font-size:16px;">';
        $html .= strtoupper($label) . ' - ' . $eggSize;
        $html .= '</div>';
        $html .= '<div style="text-align:center;padding:4px 0;background:#f0f0f0;font-size:10px;font-weight:700;color:#555;letter-spacing:1px;">MASTER QR CODE</div>';
        if (!empty($qrUri)) {
            $html .= '<div style="text-align:center;margin:8px 0;">';
            $html .= '<img src="' . $qrUri . '" style="width:120px;height:120px;">';
            $html .= '</div>';
        }
        $html .= '<div style="text-align:center;font-size:10px;color:#666;margin-bottom:5px;">Scan to view all stock</div>';
        $html .= '<div style="padding:0 5px;font-size:11px;border-top:1px solid #ddd;padding-top:5px;">';
        $html .= '<strong>Price:</strong> ' . formatCurrency($price['price_per_tray'] ?? 0) . '/tray<br>';
        $html .= '<strong>Capacity:</strong> ' . ($price['tray_capacity'] ?? 30) . ' eggs/tray<br>';
        $html .= '<strong>In Stock:</strong> ' . ($stock['tray_count'] ?? 0) . ' trays (' . number_format($stock['total_eggs'] ?? 0) . ' eggs)';
        $html .= '</div>';
        $html .= '<div style="font-size:8px;text-align:center;color:#999;margin-top:5px;">EggLogan Egg Storage — Reusable Variant Code</div>';
        $html .= '</div>';

        return $html;
    }

    public function getVariantLabels() {
        $sizes = $this->db->fetchAll(
            "SELECT size_code FROM egg_sizes WHERE is_active = 1 ORDER BY size_code"
        );

        foreach ($sizes as &$size) {
            $size['label_html'] = $this->generateVariantLabelHtml($size['size_code']);
        }

        return $sizes;
    }

    public function variantLabelsPrintHtml($labels) {
        $html = '<html><head><style>
            @page { margin: 5mm; size: A4; }
            body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
            .sticker-page { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; justify-content: center; }
            .sticker { width: 280px; box-sizing: border-box; page-break-inside: avoid; }
        </style></head><body><div class="sticker-page">';
        foreach ($labels as $label) {
            $html .= $label['label_html'];
        }
        $html .= '</div></body></html>';
        return $html;
    }

    private function getSizeLabel($size) {
        $labels = [
            'SS' => 'Super Small', 'SM' => 'Small', 'MD' => 'Medium',
            'LG' => 'Large', 'XL' => 'Extra Large', 'JB' => 'Jumbo',
            'CR' => 'Cracked', 'SA' => 'Salted'
        ];
        return $labels[$size] ?? $size;
    }

    private function getSizeColor($size) {
        $colors = [
            'SS' => '#ADD8E6', 'SM' => '#90EE90', 'MD' => '#FFD700',
            'LG' => '#FFA500', 'XL' => '#FF6B6B', 'JB' => '#9370DB',
            'CR' => '#FF69B4', 'SA' => '#8B4513'
        ];
        return $colors[$size] ?? '#CCCCCC';
    }
}
