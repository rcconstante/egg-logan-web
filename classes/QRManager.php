<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/functions.php';

class QRManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function processScan($qrData, $userId) {
        if (!is_string($qrData) || trim($qrData) === '' || strlen($qrData) > 4096) return ['error' => 'Enter valid QR data or a tray ID.'];
        $data = json_decode($qrData, true);
        if (!$data) {
            return $this->processLegacyQR($qrData);
        }

        if (is_array($data) && ($data['type'] ?? null) === 'variant') {
            if (!isset($data['egg_size']) || !is_string($data['egg_size']) || !preg_match('/^[A-Z]{2,5}$/', $data['egg_size'])) return ['error' => 'Invalid egg variant code.'];
            return $this->processVariantScan($data['egg_size'], $userId);
        }

        if (!is_array($data) || !isset($data['tray_id']) || !is_string($data['tray_id'])) {
            return $this->processLegacyQR($qrData);
        }

        $tray = $this->db->fetchOne(
            "SELECT t.*, b.batch_number,
                    s.size_label, s.tray_capacity, s.category,
                    DATEDIFF(NOW(), t.production_date) as days_in_storage,
                    DATEDIFF(t.expiry_date, NOW()) as days_until_expiry
             FROM egg_trays t
             JOIN batches b ON t.batch_id = b.id
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.tray_id = ? AND t.status != 'deleted'",
            [$data['tray_id']]
        );

        if (!$tray) {
            $this->logScan(null, $userId, 'unknown_qr', $qrData);
            return ['error' => 'Tray not found or has been deleted'];
        }

        $this->db->update(
            "UPDATE egg_trays SET last_scanned_at = NOW(), last_scanned_by = ? WHERE tray_id = ?",
            [$userId, $tray['tray_id']]
        );

        $this->logScan($tray['tray_id'], $userId, 'scan', $qrData);

        return [
            'success' => true,
            'tray' => $tray,
            'age_color' => $this->getAgeColor($tray['days_in_storage']),
            'is_expiring' => $tray['days_until_expiry'] <= 3,
            'is_expired' => $tray['days_until_expiry'] < 0
        ];
    }

    public function scanLocation($locationCode, $userId) {
        return ['error' => 'Location scanning is no longer supported'];
    }

    public function processVariantScan($sizeCode, $userId) {
        $size = $this->db->fetchOne(
            "SELECT size_code, size_label, tray_capacity, price_per_tray, price_per_egg, category
             FROM egg_sizes WHERE size_code = ?",
            [$sizeCode]
        );

        if (!$size) {
            $this->logScan(null, $userId, 'unknown_variant', $sizeCode);
            return ['error' => 'Variant not found: ' . $sizeCode];
        }

        $this->logScan(null, $userId, 'variant_scan', 'Scanned variant: ' . $sizeCode);

        $rows = $this->db->fetchAll(
            "SELECT b.id AS batch_id, b.batch_number, b.production_date AS batch_production_date, b.supplier,
                    t.tray_id, t.quantity, t.production_date, t.expiry_date,
                    t.tray_sequence, t.status, t.last_scanned_at,
                    DATEDIFF(NOW(), t.production_date) AS days_in_storage,
                    DATEDIFF(t.expiry_date, NOW()) AS days_until_expiry
             FROM batches b
             JOIN egg_trays t ON t.batch_id = b.id AND t.egg_size = b.egg_size
             WHERE b.egg_size = ? AND t.status = 'in_stock'
             ORDER BY b.production_date DESC, b.id DESC, t.tray_sequence, t.created_at",
            [$sizeCode]
        );

        $grouped = [];
        $totalEggs = 0;
        foreach ($rows as $row) {
            $batchId = $row['batch_id'];
            if (!isset($grouped[$batchId])) {
                $grouped[$batchId] = [
                    'batch_id' => $batchId,
                    'batch_number' => $row['batch_number'],
                    'production_date' => $row['batch_production_date'],
                    'expiry_date' => $row['expiry_date'],
                    'supplier' => $row['supplier'],
                    'trays' => [],
                    'tray_count' => 0,
                    'total_eggs' => 0,
                    'age_color' => $this->getAgeColor((int)$row['days_in_storage'])
                ];
            }
            $grouped[$batchId]['expiry_date'] = min($grouped[$batchId]['expiry_date'], $row['expiry_date']);
            $tray = array_intersect_key($row, array_flip([
                'tray_id', 'quantity', 'production_date', 'expiry_date', 'tray_sequence',
                'status', 'last_scanned_at', 'days_in_storage', 'days_until_expiry'
            ]));
            $grouped[$batchId]['trays'][] = $tray;
            $grouped[$batchId]['tray_count']++;
            $grouped[$batchId]['total_eggs'] += (int)$row['quantity'];
            $totalEggs += (int)$row['quantity'];
        }
        $resultBatches = array_values($grouped);
        $totalTrays = count($rows);

        return [
            'success' => true,
            'variant_scan' => true,
            'egg_size' => $size['size_code'],
            'size_label' => $size['size_label'],
            'category' => $size['category'],
            'tray_capacity' => $size['tray_capacity'],
            'price_per_tray' => $size['price_per_tray'],
            'price_per_egg' => $size['price_per_egg'],
            'batches' => $resultBatches,
            'summary' => [
                'total_trays' => $totalTrays,
                'total_eggs' => $totalEggs,
                'batch_count' => count($resultBatches)
            ]
        ];
    }

    public function generateVariantQR($sizeCode) {
        $existing = $this->db->fetchOne(
            "SELECT variant_qr_data FROM egg_sizes WHERE size_code = ?",
            [$sizeCode]
        );

        if ($existing && isset($existing['variant_qr_data']) && $existing['variant_qr_data']) {
            return json_decode($existing['variant_qr_data'], true);
        }

        $qrData = generateVariantQRData($sizeCode);
        try {
            $this->db->update(
                "UPDATE egg_sizes SET variant_qr_data = ? WHERE size_code = ?",
                [$qrData, $sizeCode]
            );
        } catch (Exception $e) {
            error_log("Failed to store variant QR for {$sizeCode}: " . $e->getMessage());
        }

        return json_decode($qrData, true);
    }

    public function verifyTray($trayId) {
        $tray = $this->db->fetchOne(
            "SELECT t.*, b.batch_number, b.supplier, s.size_label, s.tray_capacity
             FROM egg_trays t
             JOIN batches b ON t.batch_id = b.id
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.tray_id = ?",
            [$trayId]
        );

        if (!$tray) {
            return ['valid' => false, 'message' => 'Tray not found in system'];
        }

        $expectedData = generateQRData($tray);
        $expectedHash = md5($expectedData);

        return [
            'valid' => true,
            'tray' => $tray,
            'verification_time' => date('Y-m-d H:i:s'),
            'business_name' => 'EggLogan Egg Storage'
        ];
    }

    public function getBatchScanData($batchNumber) {
        $batch = $this->db->fetchOne(
            "SELECT b.*, s.size_label, COUNT(t.id) as tray_count,
                    SUM(t.quantity) as total_eggs
             FROM batches b
             JOIN egg_sizes s ON b.egg_size = s.size_code
             LEFT JOIN egg_trays t ON b.id = t.batch_id AND t.status != 'deleted'
             WHERE b.batch_number = ?
             GROUP BY b.id",
            [$batchNumber]
        );

        if (!$batch) return null;

        $trayIds = $this->db->fetchAll(
            "SELECT tray_id FROM egg_trays WHERE batch_id = ? AND status != 'deleted'",
            [$batch['id']]
        );

        return [
            'batch' => $batch,
            'tray_count' => count($trayIds),
            'tray_ids' => array_column($trayIds, 'tray_id')
        ];
    }

    private function processLegacyQR($data) {
        $tray = $this->db->fetchOne(
            "SELECT t.*, b.batch_number, s.size_label
             FROM egg_trays t
             JOIN batches b ON t.batch_id = b.id
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.tray_id = ? OR b.batch_number = ?",
            [$data, $data]
        );

        if ($tray) {
            return [
                'success' => true,
                'tray' => $tray,
                'legacy_mode' => true
            ];
        }

        return ['error' => 'Invalid QR code data'];
    }

    private function getAgeColor($days) {
        if ($days <= 7) return 'green';
        if ($days <= 14) return 'yellow';
        if ($days <= 21) return 'orange';
        return 'red';
    }

    private function logScan($trayId, $userId, $type, $data) {
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "INSERT INTO scan_logs (tray_id, staff_id, scan_type, action_taken, scanned_at) 
                 VALUES (?, ?, 'qr_scan', ?, NOW())"
            );
            $stmt->execute([$trayId, $userId, $type . ': ' . substr($data, 0, 100)]);
        } catch (Exception $e) {
            error_log("Scan log failed: " . $e->getMessage());
        }
    }
}
