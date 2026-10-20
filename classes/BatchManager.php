<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TrayManager.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/validation.php';

class BatchManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function createBatch($data) {
        $trayCount = positiveInteger($data['tray_count'] ?? null, 'Number of trays', 500);
        $data['tray_count'] = $trayCount;
        $data['production_date'] = validCalendarDate($data['production_date'] ?? null);
        if ($data['production_date'] > date('Y-m-d')) throw new InvalidArgumentException('Production date cannot be in the future.');
        if (!is_string($data['supplier'] ?? '') || strlen($data['supplier'] ?? '') > 100) throw new InvalidArgumentException('Supplier name must be at most 100 characters.');
        if (!$this->db->fetchOne("SELECT size_code FROM egg_sizes WHERE size_code = ? AND is_active = 1", [$data['egg_size']])) {
            throw new Exception('Unknown egg size: ' . ($data['egg_size'] ?: '(empty)'));
        }
        if ($trayCount < 1 || $trayCount > 500) {
            throw new Exception('Number of trays must be between 1 and 500');
        }

        $this->db->beginTransaction();
        try {
            $this->db->fetchAll('SELECT size_code FROM egg_sizes ORDER BY size_code FOR UPDATE');
            $last = $this->db->fetchOne("SELECT COALESCE(MAX(CAST(batch_number AS UNSIGNED)), 0) AS last_number FROM batches WHERE batch_number REGEXP '^[0-9]+$'");
            $batchNumber = (int)$last['last_number'] + 1;
            $sql = "INSERT INTO batches (batch_number, production_date, supplier, egg_size, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
            $batchId = $this->db->insert($sql, [
                $batchNumber, $data['production_date'], $data['supplier'], $data['egg_size']
            ]);

            if (!empty($data['generate_trays']) && $trayCount > 0) {
                $this->generateTraysForBatch($batchId, $data);
            }

            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->getPdo()->inTransaction()) {
                $this->db->rollback();
            }
            throw new Exception('Could not create batch: ' . $e->getMessage(), 0, $e);
        }

        return [
            'batch_id' => $batchId,
            'batch_number' => $batchNumber
        ];
    }

    public function generateTraysForBatch($batchId, $data) {
        $trayManager = new TrayManager();
        $sizeInfo = $this->db->fetchOne(
            "SELECT * FROM egg_sizes WHERE size_code = ?", [$data['egg_size']]
        );
        
        if (!$sizeInfo) return false;

        $trayCapacity = $sizeInfo['tray_capacity'];
        $shelfLife = $sizeInfo['shelf_life'];
        $produceDate = $data['production_date'];
        $expiryDate = date('Y-m-d', strtotime($produceDate . " + {$shelfLife} days"));

        $trayIds = [];
        for ($i = 0; $i < $data['tray_count']; $i++) {
            $trayData = [
                'egg_size' => $data['egg_size'],
                'batch_id' => $batchId,
                'quantity' => $trayCapacity,
                'production_date' => $produceDate,
                'expiry_date' => $expiryDate,
                'tray_sequence' => $i + 1
            ];
            $trayIds[] = $trayManager->createTray($trayData);
        }

        return $trayIds;
    }

    public function getBatch($batchId) {
        $sql = "SELECT b.*, s.size_label, s.tray_capacity, s.category
                FROM batches b
                JOIN egg_sizes s ON b.egg_size = s.size_code
                WHERE b.id = ?";
        return $this->db->fetchOne($sql, [$batchId]);
    }

    public function getAllBatches($page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT b.*, s.size_label, COUNT(t.id) as tray_count, 
                       SUM(t.quantity) as total_eggs
                FROM batches b
                JOIN egg_sizes s ON b.egg_size = s.size_code
                LEFT JOIN egg_trays t ON b.id = t.batch_id AND t.status != 'deleted'
                GROUP BY b.id, b.batch_number, b.production_date, b.supplier, 
                         b.egg_size, b.created_at, s.size_label
                ORDER BY b.created_at DESC
                LIMIT ? OFFSET ?";
        return $this->db->fetchAll($sql, [$perPage, $offset]);
    }

    public function searchBatches($query) {
        $sql = "SELECT b.*, s.size_label, COUNT(t.id) as tray_count
                FROM batches b
                JOIN egg_sizes s ON b.egg_size = s.size_code
                LEFT JOIN egg_trays t ON b.id = t.batch_id AND t.status != 'deleted'
                WHERE b.batch_number LIKE ? OR b.supplier LIKE ? OR s.size_label LIKE ?
                GROUP BY b.id, b.batch_number, b.production_date, b.supplier,
                         b.egg_size, b.created_at, s.size_label
                ORDER BY b.created_at DESC
                LIMIT 20";
        $search = "%{$query}%";
        return $this->db->fetchAll($sql, [$search, $search, $search]);
    }
}
