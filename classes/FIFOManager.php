<?php
require_once __DIR__ . '/Database.php';

class FIFOManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function ensureFreshScores($maxAgeHours = 20) {
        $last = $this->db->fetchOne(
            "SELECT created_at FROM activity_logs 
             WHERE action = 'fifo_update' ORDER BY created_at DESC LIMIT 1"
        );
        if ($last && (time() - strtotime($last['created_at'])) < $maxAgeHours * 3600) {
            return false;
        }
        return $this->recalculateAllScores();
    }

    public function rescoreTray($trayId) {
        $tray = $this->db->fetchOne(
            "SELECT t.*, s.shelf_life, s.category,
                    b.id as batch_id_ref,
                    COALESCE(t.tray_sequence, 
                        (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                    ) as tray_sequence
             FROM egg_trays t
             JOIN egg_sizes s ON t.egg_size = s.size_code
             JOIN batches b ON t.batch_id = b.id
             WHERE t.tray_id = ?",
            [$trayId]
        );
        if (!$tray) return false;
        $score = $this->calculateScore($tray);
        $this->db->update(
            "UPDATE egg_trays SET fifo_priority_score = ? WHERE id = ?",
            [$score, $tray['id']]
        );
        return true;
    }

    public function recalculateAllScores() {
        $trays = $this->db->fetchAll(
            "SELECT t.*, s.shelf_life, s.category,
                    b.id as batch_id_ref,
                    COALESCE(t.tray_sequence, 
                        (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                    ) as tray_sequence
             FROM egg_trays t
             JOIN egg_sizes s ON t.egg_size = s.size_code
             JOIN batches b ON t.batch_id = b.id
             WHERE t.status = 'in_stock'"
        );

        foreach ($trays as $tray) {
            $score = $this->calculateScore($tray);
            $this->db->update(
                "UPDATE egg_trays SET fifo_priority_score = ? WHERE id = ?",
                [$score, $tray['id']]
            );
        }

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) 
                                VALUES (0, 'fifo_update', 'Daily FIFO scores recalculated', NOW())");
        $stmt->execute();

        return true;
    }

    public function getPickList() {
        $this->ensureFreshScores();
        $sql = "SELECT t.*, b.batch_number,
                       s.size_label, s.tray_capacity, s.category,
                       COALESCE(t.tray_sequence, 
                           (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                       ) as tray_sequence,
                       DATEDIFF(NOW(), t.production_date) as days_in_storage,
                       DATEDIFF(t.expiry_date, NOW()) as days_until_expiry
                FROM egg_trays t
                JOIN batches b ON t.batch_id = b.id
                JOIN egg_sizes s ON t.egg_size = s.size_code
                WHERE t.status = 'in_stock'
                ORDER BY 
                    t.fifo_priority_score DESC,
                    t.expiry_date ASC,
                    COALESCE(t.tray_sequence, 
                        (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                    ) ASC";

        $trays = $this->db->fetchAll($sql);

        return [
            'fresh' => array_filter($trays, fn($t) => $t['category'] === 'fresh'),
            'salted' => array_filter($trays, fn($t) => $t['category'] === 'salted'),
            'all' => $trays
        ];
    }

    public function getPickListBySize($eggSize) {
        $this->ensureFreshScores();
        $sql = "SELECT t.*, b.batch_number,
                       s.size_label, s.tray_capacity,
                       COALESCE(t.tray_sequence, 
                           (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                       ) as tray_sequence,
                       DATEDIFF(NOW(), t.production_date) as days_in_storage,
                       DATEDIFF(t.expiry_date, NOW()) as days_until_expiry
                FROM egg_trays t
                JOIN batches b ON t.batch_id = b.id
                JOIN egg_sizes s ON t.egg_size = s.size_code
                WHERE t.status = 'in_stock' AND t.egg_size = ?
                ORDER BY t.fifo_priority_score DESC, t.expiry_date ASC,
                         COALESCE(t.tray_sequence, 
                             (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                         ) ASC";

        return $this->db->fetchAll($sql, [$eggSize]);
    }

    /**
 * @param array $reserved tray_id => eggs already promised by the current sale.
 *        A receipt with two lines of the same size would otherwise plan both
 *        lines against the same tray and oversell it.
 */
public function getBestTraysForSale($eggSize, $quantity, $reserved = []) {
    $this->ensureFreshScores();
    $trays = $this->db->fetchAll(
        "SELECT t.*, b.batch_number,
                s.tray_capacity, s.category,
                COALESCE(t.tray_sequence, 
                    (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
                ) as tray_sequence
         FROM egg_trays t
         JOIN batches b ON t.batch_id = b.id
         JOIN egg_sizes s ON t.egg_size = s.size_code
         WHERE t.status = 'in_stock' AND t.egg_size = ? AND t.quantity > 0 AND t.expiry_date >= CURDATE()
         ORDER BY 
            t.fifo_priority_score DESC,
            t.expiry_date ASC,
            COALESCE(t.tray_sequence, 
                (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = t.batch_id AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
            ) ASC",
        [$eggSize]
    );

    $selected = [];
    $remaining = $quantity;

    foreach ($trays as $tray) {
        if ($remaining <= 0) break;
        $available = (int)$tray['quantity'] - (int)($reserved[$tray['tray_id']] ?? 0);
        if ($available <= 0) continue;
        $take = min($available, $remaining);
        $selected[] = [
            'tray' => $tray,
            'take' => $take
        ];
        $remaining -= $take;
    }

    return $selected;
}

    public function validateFIFOCompliance($trayId) {
        $tray = $this->db->fetchOne(
            "SELECT t.*, s.category
             FROM egg_trays t
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.tray_id = ?",
            [$trayId]
        );
        if (!$tray) return ['compliant' => false, 'reason' => 'Tray not found'];
        if ($tray['status'] !== 'in_stock' || (int)$tray['quantity'] <= 0 || $tray['expiry_date'] < date('Y-m-d')) return ['compliant' => false, 'reason' => 'Tray is not available for sale.'];

        $bestScore = $this->db->fetchOne(
            "SELECT MAX(fifo_priority_score) as best_score
             FROM egg_trays t
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.status = 'in_stock' AND t.egg_size = ? AND t.quantity > 0 AND t.expiry_date >= CURDATE()",
            [$tray['egg_size']]
        );

        if ($bestScore && $tray['fifo_priority_score'] < $bestScore['best_score']) {
            return [
                'compliant' => false,
                'reason' => 'Older stock available for this egg size',
                'best_score' => $bestScore['best_score'],
                'tray_score' => $tray['fifo_priority_score']
            ];
        }

        return ['compliant' => true];
    }

    public function logFIFOViolation($trayId, $userId, $reason) {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare(
            "INSERT INTO system_alerts (alert_type, severity, message, reference_id, is_resolved, created_at) 
             VALUES ('fifo_violation', 'high', ?, ?, 0, NOW())"
        );
        $stmt->execute([
            "FIFO violation: {$reason} for tray {$trayId}",
            $trayId
        ]);

        $stmt2 = $pdo->prepare(
            "INSERT INTO activity_logs (user_id, action, details, created_at) 
             VALUES (?, 'fifo_violation', ?, NOW())"
        );
        $stmt2->execute([$userId, "FIFO violation on tray {$trayId}: {$reason}"]);
    }

    private function calculateScore($tray) {
        $daysInStorage = (int)date_diff(
            new DateTime($tray['production_date']),
            new DateTime()
        )->format('%a');

        $maxShelfLife = $tray['shelf_life'] ?? 21;
        $ageRatio = min($daysInStorage / max($maxShelfLife, 1), 1.0);

        $batchSeq = $this->getBatchSequence($tray['batch_id_ref'], $tray['id']);

        // 80% age (older = sell first), 20% sequence (lower # = sell first)
        return (0.8 * $ageRatio) + (0.2 * (1 / max($batchSeq, 1)));
    }

    private function getBatchSequence($batchId, $trayId) {
        $result = $this->db->fetchOne(
            "SELECT COALESCE(tray_sequence, 
                (SELECT COUNT(*) + 1 FROM egg_trays WHERE batch_id = ? AND created_at < t.created_at AND status NOT IN ('deleted', 'sold'))
            ) as seq FROM egg_trays t WHERE id = ?",
            [$batchId, $trayId]
        );
        return $result ? max(1, (int)$result['seq']) : 1;
    }
}
