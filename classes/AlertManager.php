<?php
require_once __DIR__ . '/Database.php';
// plural() lives in functions.php and is used when sizing a restock in the low-stock
// message. Without this the alert panel fataled with an undefined function.
require_once __DIR__ . '/../includes/functions.php';

class AlertManager {
    private $db;
    private $expiryDays = 3;
    private $lowStockThreshold = 0.5;

    /**
     * Expiry and low-stock alerts are DERIVED from current stock, so they are
     * reconciled on every check instead of being appended to. Without this the
     * table grew one row per tray per condition and stale rows lingered long after
     * the stock was sold or disposed.
     */
    private $dismissCooldownHours = 24;

    public function __construct() {
        $this->db = Database::getInstance();
        foreach ($this->db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('alert_expiry_days','low_stock_threshold')") as $s) {
            if ($s['setting_key'] === 'alert_expiry_days') $this->expiryDays = max(1, (int)$s['setting_value']);
            if ($s['setting_key'] === 'low_stock_threshold') $this->lowStockThreshold = max(0.1, min(1.0, (float)$s['setting_value']));
        }
    }

    /**
     * One alert per size per urgency bucket instead of one per tray.
     * 270 trays expiring on the same day is a single decision for the shopkeeper,
     * not 270 separate pieces of news.
     */
    public function checkExpiringTrays() {
        $expiring = $this->db->fetchAll(
            "SELECT t.egg_size, s.size_label,
                    COUNT(*) AS tray_count,
                    COALESCE(SUM(t.quantity), 0) AS egg_count,
                    MIN(DATEDIFF(t.expiry_date, NOW())) AS soonest
             FROM egg_trays t
             JOIN egg_sizes s ON t.egg_size = s.size_code
             WHERE t.status = 'in_stock'
             AND DATEDIFF(t.expiry_date, NOW()) <= ?
             GROUP BY t.egg_size, s.size_label",
            [$this->expiryDays]
        );

        $wanted = [];
        $trayTotal = 0;
        foreach ($expiring as $row) {
            $soonest = (int)$row['soonest'];
            $n = (int)$row['tray_count'];
            $eggs = (int)$row['egg_count'];
            $trayTotal += $n;

            if ($soonest < 0) {
                $days = abs($soonest);
                $severity = 'high';
                $msg = sprintf(
                    '%d %s tray%s expired %s ago (%s eggs)',
                    $n, $row['size_label'], $n !== 1 ? 's' : '', $days === 1 ? '1 day' : $days . ' days',
                    number_format($eggs)
                );
            } elseif ($soonest === 0) {
                $severity = 'high';
                $msg = sprintf(
                    '%d %s tray%s expire today (%s eggs)',
                    $n, $row['size_label'], $n !== 1 ? 's' : '', number_format($eggs)
                );
            } else {
                $severity = $soonest <= 1 ? 'high' : 'medium';
                $msg = sprintf(
                    '%d %s tray%s expiring in %s (%s eggs)',
                    $n, $row['size_label'], $n !== 1 ? 's' : '',
                    $soonest === 1 ? '1 day' : $soonest . ' days',
                    number_format($eggs)
                );
            }
            // Namespace keeps a size reference from ever colliding with a tray id.
            $wanted['size:' . $row['egg_size']] = [$severity, $msg];
        }

        $this->reconcileDerived('expiring', $wanted);

        return $trayTotal;
    }

    public function checkLowStock() {
        $lowStock = $this->db->fetchAll(
            "SELECT s.size_code, s.size_label, s.tray_capacity, s.price_per_egg,
                    COALESCE(SUM(t.quantity), 0) as current_stock,
                    COALESCE(avg_pred.avg_predicted_eggs, 0) as predicted_demand,
                    COALESCE(recent.actual_sold, 0) as recent_sold
             FROM egg_sizes s
             LEFT JOIN egg_trays t ON s.size_code = t.egg_size AND t.status = 'in_stock'
             LEFT JOIN (
                SELECT kp.egg_size,
                       AVG(kp.predicted_trays) * es.tray_capacity as avg_predicted_eggs
                FROM knn_predictions kp
                JOIN egg_sizes es ON kp.egg_size = es.size_code
                WHERE kp.prediction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY kp.egg_size, es.tray_capacity
             ) avg_pred ON s.size_code = avg_pred.egg_size
             LEFT JOIN (
                SELECT ri.egg_size, SUM(ri.quantity) as actual_sold
                FROM receipt_items ri
                JOIN receipts r ON ri.receipt_id = r.id
                WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND r.is_voided = 0
                GROUP BY ri.egg_size
             ) recent ON s.size_code = recent.egg_size
             GROUP BY s.size_code, s.size_label, s.tray_capacity, s.price_per_egg, avg_pred.avg_predicted_eggs, recent.actual_sold
             HAVING current_stock = 0
                OR (
                   (predicted_demand > 0 AND current_stock < predicted_demand * ?)
                   OR
                   (recent_sold > 0 AND current_stock < recent_sold * ?)
                )",
            [$this->lowStockThreshold, $this->lowStockThreshold]
        );

        $wanted = [];
        foreach ($lowStock as $item) {
            $stock = (int)$item['current_stock'];
            $predicted = (int)$item['predicted_demand'];
            $sold = (int)$item['recent_sold'];

            // The SQL alerts when EITHER benchmark is breached, so each is scored on its
            // own here. Explaining every alert with recent sales was wrong whenever the
            // forecast was the benchmark that actually tripped the rule.
            $benchmarks = [];
            if ($sold > 0) $benchmarks[] = ['label' => 'sold in 7d', 'eggs' => $sold];
            if ($predicted > 0) $benchmarks[] = ['label' => '7d forecast', 'eggs' => $predicted];

            // The most demanding breached line is the one that has to be cleared.
            $binding = null;
            foreach ($benchmarks as $b) {
                $b['cutoff'] = (int)round($b['eggs'] * $this->lowStockThreshold);
                if ($stock < $b['cutoff'] && ($binding === null || $b['cutoff'] > $binding['cutoff'])) {
                    $binding = $b;
                }
            }

            $message = $this->buildStockMessage($item, $stock, $binding, $stock <= 0);
            $wanted[$item['size_code']] = [ $stock <= 0 ? 'high' : 'medium', $message ];
        }

        $this->reconcileDerived('low_stock', $wanted);

        return count($lowStock);
    }

    /**
     * Builds the low-stock message so the reason for the alert is visible without any
     * mental arithmetic: what you have, the cutoff it was measured against, the
     * subtraction that proved it, how many days of cover that is, and how many trays
     * to buy. An alert that cannot explain itself gets dismissed.
     */
    private function buildStockMessage($item, $stock, $binding, $outOfStock) {
        $label = $item['size_label'];
        $capacity = max(1, (int)$item['tray_capacity']);
        $rate = rtrim(rtrim(number_format($this->lowStockThreshold, 2), '0'), '.');

        if ($binding === null) {
            // Out of stock with no demand to compare against. Still needs a quantity to
            // act on, so fall back to one tray's worth per egg size.
            return $outOfStock
                ? sprintf(
                    'Out of stock: %s — 0 eggs left, and no recent sales or forecast to measure against. Buy at least %d %s to reopen.',
                    $label, $capacity, plural($capacity, 'tray', 'trays')
                )
                : sprintf('Low stock: %s — %s eggs left.', $label, number_format($stock));
        }

        $cutoff = $binding['cutoff'];
        $perDay = $binding['eggs'] / 7;
        $daysLeft = $perDay > 0 ? $stock / $perDay : null;
        $shortfall = max(0, $cutoff - $stock);
        $traysToClear = (int)ceil($shortfall / $capacity);

        // Days of cover is what people actually reason in, so it leads. The raw
        // inequality is kept because it is the auditable part of the decision.
        $head = $outOfStock
            ? sprintf('Out of stock: %s — 0 eggs left.', $label)
            : sprintf(
                'Low stock: %s — %s eggs left (%s).',
                $label, number_format($stock),
                $daysLeft !== null ? 'about ' . number_format($daysLeft, 1) . ' days of cover' : 'cover unknown'
            );

        $why = sprintf(
            ' Check: %s < %s cutoff  =  %s %s  ×  %s threshold.',
            number_format($stock), number_format($cutoff), number_format($binding['eggs']), $binding['label'], $rate
        );

        $action = $traysToClear > 0
            ? sprintf(' Short by %s %s — buy %d %s to clear it.', number_format($shortfall), plural($shortfall, 'egg', 'eggs'), $traysToClear, plural($traysToClear, 'tray', 'trays'))
            : ' You already have enough trays to clear the line.';

        return $head . $why . $action;
    }

    public function checkModelHealth($metrics) {
        $thresholds = ['accuracy' => 80, 'precision' => 75, 'recall' => 80, 'f1_score' => 75];
        $labels = [
            'accuracy' => ['Hit Rate', 'high'],
            'precision' => ['No Over-Predict', 'medium'],
            'recall' => ['No Under-Predict', 'high'],
            'f1_score' => ['Balanced Score', 'medium']
        ];
        $count = 0;

        foreach ($metrics as $sizeCode => $m) {
            if (empty($m['sample_count']) || $m['sample_count'] < 5) continue;
            foreach ($thresholds as $key => $min) {
                if (!isset($m[$key]) || $m[$key] >= $min) continue;
                [$label, $severity] = $labels[$key];
                $this->syncAlert(
                    'system',
                    $severity,
                    "Model {$label} for {$m['egg_size']} is {$m[$key]}% (below {$min}% target). Consider retraining the model.",
                    "metric_{$key}_{$sizeCode}"
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * Brings the open alerts of a derived type in line with the current situation:
     * anything no longer true is resolved, anything new is raised, and anything
     * still true keeps its existing row (with refreshed wording).
     *
     * @param array $wanted reference_id => [severity, message]
     * @return int number of stale alerts resolved
     */
    private function reconcileDerived($type, array $wanted) {
        $open = $this->db->fetchAll(
            "SELECT id, reference_id FROM system_alerts WHERE alert_type = ? AND is_resolved = 0",
            [$type]
        );
        $resolved = 0;
        foreach ($open as $a) {
            if (array_key_exists($a['reference_id'], $wanted)) continue;
            // Removed rather than marked resolved on purpose: a derived alert that
            // stopped being true was never "handled" by anyone, and leaving it as a
            // resolved row made it look like a dismissal -- which then suppressed
            // the same alert for the whole cooldown if the condition came back.
            $this->db->delete("DELETE FROM system_alerts WHERE id = ?", [$a['id']]);
            $resolved++;
        }
        foreach ($wanted as $ref => $info) {
            $this->syncAlert($type, $info[0], $info[1], $ref);
        }
        return $resolved;
    }

    /**
     * Creates or refreshes a single alert.
     *
     * A recent dismissal is respected for a cooldown window. Previously dismissing
     * an alert did nothing at all: the dashboard re-polls every 30 seconds, the
     * check found no open row, and the alert was straight back in the list.
     */
    private function syncAlert($type, $severity, $message, $referenceId = null) {
        $recent = $this->db->fetchOne(
            "SELECT id FROM system_alerts
             WHERE alert_type = ? AND reference_id <=> ? AND is_resolved = 1
               AND resolved_at IS NOT NULL
               AND resolved_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             LIMIT 1",
            [$type, $referenceId, $this->dismissCooldownHours]
        );
        if ($recent) return false;

        $open = $this->db->fetchOne(
            "SELECT id, message, severity FROM system_alerts
             WHERE alert_type = ? AND reference_id <=> ? AND is_resolved = 0",
            [$type, $referenceId]
        );

        if ($open) {
            // Keep the row (and its age) but refresh the wording if the situation moved on.
            if ($open['message'] !== $message || $open['severity'] !== $severity) {
                $this->db->update(
                    "UPDATE system_alerts SET message = ?, severity = ? WHERE id = ?",
                    [$message, $severity, $open['id']]
                );
            }
            return false;
        }

        $this->db->insert(
            "INSERT INTO system_alerts (alert_type, severity, message, reference_id, is_resolved, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())",
            [$type, $severity, $message, $referenceId]
        );
        return true;
    }

    /**
     * Housekeeping for the resolved pile, which otherwise grows forever.
     */
    public function pruneOldAlerts($keepDays = 30, $maxResolved = 500) {
        $deleted = $this->db->update(
            "DELETE FROM system_alerts
             WHERE is_resolved = 1
               AND (resolved_at IS NULL OR resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY))",
            [$keepDays]
        );

        $count = (int)$this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM system_alerts WHERE is_resolved = 1"
        )['c'];
        if ($count > $maxResolved) {
            $deleted += $this->db->update(
                "DELETE FROM system_alerts WHERE is_resolved = 1 ORDER BY resolved_at ASC LIMIT " . (int)($count - $maxResolved)
            );
        }
        return $deleted;
    }

    /**
     * One call that brings the whole alert list up to date. Called by every page
     * and by the polling endpoint, so the badge can never drift from reality.
     */
    public function refresh($includeModel = false, $metrics = null) {
        $this->autoResolveStaleAlerts();
        $this->checkExpiringTrays();
        $this->checkLowStock();
        if ($includeModel && is_array($metrics)) $this->checkModelHealth($metrics);
        return $this->getAlertCount();
    }

    public function getActiveAlerts() {
        return $this->db->fetchAll(
            "SELECT a.*,
                    t.expiry_date,
                    t.status AS tray_status,
                    CASE
                        WHEN a.alert_type = 'expiring' THEN 'clock'
                        WHEN a.alert_type = 'low_stock' THEN 'exclamation-triangle'
                        WHEN a.alert_type = 'fifo_violation' THEN 'hand'
                        ELSE 'bell'
                    END as icon
             FROM system_alerts a
             LEFT JOIN egg_trays t
                    ON a.alert_type IN ('expiring', 'fifo_violation')
                   AND a.reference_id NOT LIKE 'size:%'
                   AND t.tray_id = a.reference_id
             WHERE a.is_resolved = 0
             ORDER BY
                CASE a.severity
                    WHEN 'high' THEN 0
                    WHEN 'medium' THEN 1
                    WHEN 'low' THEN 2
                    ELSE 3
                END,
                a.created_at DESC
             LIMIT 50"
        );
    }

    public function getAlertLink($alert) {
        $ref = $alert['reference_id'] ?? null;

        // Aggregated alerts reference a size, not one tray. Send the user to the
        // pick list for that size (or the disposal queue when stock has expired).
        if (is_string($ref) && strpos($ref, 'size:') === 0) {
            $code = substr($ref, 5);
            if ($alert['alert_type'] === 'expiring') {
                return $this->sizeHasExpiredStock($code)
                    ? 'inventory.php#expiredSection'
                    : 'picklist.php?size=' . rawurlencode($code);
            }
            return 'picklist.php?size=' . rawurlencode($code);
        }

        switch ($alert['alert_type']) {
            case 'expiring':
                if (empty($ref)) return 'inventory.php';
                if (empty($alert['tray_status'])) return 'inventory.php';
                $days = $this->daysUntilExpiry($ref);
                if ($days !== null && $days < 0) {
                    return 'inventory.php?tray=' . rawurlencode($ref);
                }
                return 'picklist.php?tray=' . rawurlencode($ref);

            case 'fifo_violation':
                if (empty($ref)) return 'picklist.php';
                return 'picklist.php?tray=' . rawurlencode($ref);

            case 'low_stock':
                if (empty($ref)) return 'inventory.php';
                return 'picklist.php?size=' . rawurlencode($ref);

            case 'system':
                return 'predictions.php';
        }

        return null;
    }

    private function sizeHasExpiredStock($sizeCode) {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM egg_trays
             WHERE egg_size = ? AND status = 'in_stock' AND expiry_date < CURDATE()",
            [$sizeCode]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    public function daysUntilExpiry($trayId) {
        $row = $this->db->fetchOne(
            "SELECT DATEDIFF(expiry_date, CURDATE()) AS days FROM egg_trays WHERE tray_id = ?",
            [$trayId]
        );
        return isset($row['days']) ? (int)$row['days'] : null;
    }

    public function resolveAlert($alertId) {
        return $this->db->update(
            "UPDATE system_alerts SET is_resolved = 1, resolved_at = NOW() WHERE id = ?",
            [$alertId]
        );
    }

    public function resolveAlertsByReference($referenceId) {
        return $this->db->update(
            "UPDATE system_alerts SET is_resolved = 1, resolved_at = NOW() WHERE reference_id = ?",
            [$referenceId]
        );
    }

    public function resolveAlertsForTray($trayId) {
        return $this->db->update(
            "UPDATE system_alerts SET is_resolved = 1, resolved_at = NOW() WHERE reference_id = ? AND alert_type IN ('expiring','fifo_violation')",
            [$trayId]
        );
    }

    public function getExpiredCount() {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM egg_trays WHERE status = 'in_stock' AND expiry_date < CURDATE()"
        );
        return $result['count'] ?? 0;
    }

    public function getAlertCount() {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM system_alerts WHERE is_resolved = 0"
        );
        return $result['count'] ?? 0;
    }

    public function getHighSeverityCount() {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM system_alerts WHERE is_resolved = 0 AND severity = 'high'"
        );
        return $result['count'] ?? 0;
    }

    public function autoResolveStaleAlerts() {
        $resolved = 0;

        // Legacy per-tray expiry rows (created before alerts were aggregated by
        // size). reconcileDerived() now owns this type, so any leftover open row
        // pointing at a single tray is stale by definition.
        $resolved += $this->db->update(
            "UPDATE system_alerts SET is_resolved = 1, resolved_at = NOW()
             WHERE alert_type = 'expiring' AND is_resolved = 0 AND reference_id IS NOT NULL
               AND reference_id NOT LIKE 'size:%'"
        );

        // resolve fifo_violation alerts for trays no longer in_stock
        $resolved += $this->db->update(
            "UPDATE system_alerts SET is_resolved = 1, resolved_at = NOW()
             WHERE alert_type = 'fifo_violation' AND is_resolved = 0
             AND reference_id IS NOT NULL
             AND reference_id NOT IN (
                SELECT tray_id FROM egg_trays WHERE status = 'in_stock'
             )"
        );

        return $resolved;
    }

    private function createAlert($type, $severity, $message, $referenceId = null) {
        return $this->syncAlert($type, $severity, $message, $referenceId);
    }
}
