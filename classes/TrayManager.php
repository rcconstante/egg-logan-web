<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/FIFOManager.php';

class TrayManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getTray($trayId) {
        $sql = "SELECT t.*, b.batch_number, b.supplier, s.tray_capacity, s.price_per_tray, 
                       s.price_per_egg, s.category
                FROM egg_trays t
                JOIN batches b ON t.batch_id = b.id
                JOIN egg_sizes s ON t.egg_size = s.size_code
                WHERE t.tray_id = ? AND t.status != 'deleted'";
        return $this->db->fetchOne($sql, [$trayId]);
    }

    public function getTraysByBatch($batchId) {
        $sql = "SELECT t.*, s.size_label
                FROM egg_trays t
                JOIN egg_sizes s ON t.egg_size = s.size_code
                WHERE t.batch_id = ? AND t.status != 'deleted'
                ORDER BY t.tray_id";
        return $this->db->fetchAll($sql, [$batchId]);
    }

    public function createTray($data, $status = 'in_stock') {
        $trayId = generateUUID();
        $sql = "INSERT INTO egg_trays (tray_id, qr_code_data, egg_size, batch_id, quantity, 
                                       production_date, expiry_date, tray_sequence, fifo_priority_score, 
                                       status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $fifoScore = $this->calculateFIFOScore($data);
        $qrData = json_encode([
            'tray_id' => $trayId,
            'egg_size' => $data['egg_size'],
            'quantity' => $data['quantity'],
            'production_date' => $data['production_date'],
            'expiry_date' => $data['expiry_date']
        ]);

        $this->db->insert($sql, [
            $trayId, $qrData, $data['egg_size'], $data['batch_id'],
            $data['quantity'], $data['production_date'], $data['expiry_date'],
            $data['tray_sequence'] ?? null, $fifoScore, $status
        ]);

        return $trayId;
    }

    public function moveTray($trayId, $userId) {
        $tray = $this->getTray($trayId);
        if (!$tray) return false;

        $this->db->update(
            "UPDATE egg_trays SET updated_at = NOW(), last_scanned_at = NOW(), last_scanned_by = ? WHERE tray_id = ?",
            [$userId, $trayId]
        );

        return true;
    }

    public function markDamaged($trayId, $quantityAffected, $issueType, $userId) {
        if ((!is_int($quantityAffected) && !is_string($quantityAffected)) || filter_var($quantityAffected, FILTER_VALIDATE_INT) === false || (int)$quantityAffected <= 0 || !in_array($issueType, ['leaking', 'other'], true)) return false;
        $db = $this->db;
        $db->beginTransaction();
        try {
            $tray = $db->fetchOne("SELECT * FROM egg_trays WHERE tray_id = ? AND status = 'in_stock' FOR UPDATE", [$trayId]);
            if (!$tray || $quantityAffected > (int)$tray['quantity']) { $db->rollback(); return false; }
            $newQty = $tray['quantity'] - $quantityAffected;
            
            if ($newQty <= 0) {
                $this->db->update("UPDATE egg_trays SET status = 'damaged', quantity = 0, 
                                    updated_at = NOW() WHERE tray_id = ?", [$trayId]);
            } else {
                $this->db->update("UPDATE egg_trays SET quantity = ?, updated_at = NOW() 
                                    WHERE tray_id = ?", [$newQty, $trayId]);
                
                $damagedTrayData = [
                    'egg_size' => $tray['egg_size'],
                    'batch_id' => $tray['batch_id'],
                    'quantity' => $quantityAffected,
                    'production_date' => $tray['production_date'],
                    'expiry_date' => $tray['expiry_date']
                ];
                $this->createTray($damagedTrayData, 'damaged');
            }

            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("INSERT INTO damaged_log (tray_id, quantity, issue_type, reported_by, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$trayId, $quantityAffected, $issueType, $userId]);

            if ($newQty <= 0) {
                require_once __DIR__ . '/AlertManager.php';
                $alertManager = new AlertManager();
                $alertManager->resolveAlertsForTray($trayId);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollback();
            return false;
        }
    }

    public function getCurrentInventory() {
        $sql = "SELECT s.size_code, s.size_label, s.category, s.tray_capacity, 
                       COUNT(t.id) as tray_count, SUM(t.quantity) as total_eggs,
                       MIN(t.production_date) as oldest_batch,
                       MAX(t.expiry_date) as latest_expiry,
                       s.price_per_tray, s.price_per_egg
                FROM egg_trays t
                JOIN egg_sizes s ON t.egg_size = s.size_code
                WHERE t.status = 'in_stock'
                GROUP BY s.size_code, s.size_label, s.category, s.tray_capacity, 
                         s.price_per_tray, s.price_per_egg
                ORDER BY FIELD(s.category, 'fresh', 'salted'), s.size_code";
        return $this->db->fetchAll($sql);
    }

    public function getAgingSummary() {
        $sql = "SELECT t.*, s.size_label, s.category, 
                       DATEDIFF(NOW(), t.production_date) as days_in_storage,
                       DATEDIFF(t.expiry_date, NOW()) as days_until_expiry
                FROM egg_trays t
                JOIN egg_sizes s ON t.egg_size = s.size_code
                WHERE t.status = 'in_stock'
                HAVING days_in_storage >= 0
                ORDER BY days_until_expiry ASC";
        return $this->db->fetchAll($sql);
    }

    public function verifyBatchCount($batchId, $physicalCount) {
        $trays = $this->getTraysByBatch($batchId);
        $systemCount = count($trays);
        $diff = $physicalCount - $systemCount;
        return [
            'system_count' => $systemCount,
            'physical_count' => $physicalCount,
            'difference' => $diff,
            'match' => $diff === 0
        ];
    }

    private function calculateFIFOScore($data) {
        $daysInStorage = 0;
        if (isset($data['production_date'])) {
            $daysInStorage = (int)date_diff(
                new DateTime($data['production_date']),
                new DateTime()
            )->format('%a');
        }

        $maxShelfLife = 21;
        if (isset($data['egg_size'])) {
            $sizeData = $this->db->fetchOne(
                "SELECT shelf_life FROM egg_sizes WHERE size_code = ?", 
                [$data['egg_size']]
            );
            if ($sizeData) $maxShelfLife = $sizeData['shelf_life'];
        }

        $batchSeq = isset($data['tray_sequence']) ? max($data['tray_sequence'], 1) : 1;

        $score = (0.5 * ($daysInStorage / max($maxShelfLife, 1))) + 
                 (0.3 * (1 / $batchSeq)) + 
                 (0.2 * 0.5);

        return min($score, 1.0);
    }

    private function logScan($trayId, $staffId, $action) {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("INSERT INTO scan_logs (tray_id, staff_id, scan_type, action_taken, scanned_at) 
                                VALUES (?, ?, 'scan', ?, NOW())");
        $stmt->execute([$trayId, $staffId, $action]);
    }
}
