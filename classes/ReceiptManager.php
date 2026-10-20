<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/FIFOManager.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

class ReceiptManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function createReceipt($data) {
        $fifoManager = new FIFOManager();
        $pdo = $this->db->getPdo();

        // FIFO scores are refreshed before the transaction opens on purpose.
        // ensureFreshScores() rewrites every in-stock tray and appends to
        // activity_logs; running that inside the receipt transaction would hold
        // row locks on the whole inventory for the length of the sale and stall
        // every other till.
        $fifoManager->ensureFreshScores();

        // The receipt number is allocated before the transaction opens too.
        // Inside a REPEATABLE READ transaction MAX(receipt_number) keeps
        // returning the pre-transaction snapshot, so a duplicate-key retry would
        // regenerate the identical number and the sale would be rejected even
        // though free numbers were available.
        $pendingReceiptNumber = generateReceiptNumber();

        $pdo->beginTransaction();

        try {
            $override = !empty($data['fifo_override']);

            // Normalise and validate every item before anything is written, and derive
            // the receipt total from them so receipts, receipt_items and sales_history
            // can never disagree. Pricing is read from egg_sizes here rather than
            // trusted from the caller, so no client can dictate what a sale costs.
            $items = [];
            $receiptTotal = 0.0;
            $priceCache = [];

            foreach ($data['items'] as $raw) {
                if (!is_array($raw)) throw new InvalidArgumentException('Invalid sale item.');
                $quantity = positiveInteger($raw['quantity'] ?? null, 'Quantity', 1000000);
                $eggSize = trim((string)($raw['egg_size'] ?? ''));

                if ($eggSize === '') {
                    throw new Exception('A sale item is missing its egg size');
                }
                if ($quantity <= 0) {
                    throw new Exception("Invalid quantity ({$quantity}) for {$eggSize}");
                }
                if (!array_key_exists($eggSize, $priceCache)) {
                    $priceRow = $this->db->fetchOne(
                        "SELECT size_code, price_per_egg, is_active FROM egg_sizes WHERE size_code = ?",
                        [$eggSize]
                    );
                    if (!$priceRow || (int)$priceRow['is_active'] === 0) {
                        throw new Exception("Unknown egg size '{$eggSize}'");
                    }
                    $priceCache[$eggSize] = (float)$priceRow['price_per_egg'];
                }

                $unitPrice = round($priceCache[$eggSize], 2);
                $subtotal = round($unitPrice * $quantity, 2);
                $trayId = trim((string)($raw['tray_id'] ?? ''));

                $items[] = [
                    'egg_size' => $eggSize,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'tray_id' => $trayId !== '' ? $trayId : null
                ];
                $receiptTotal += $subtotal;
            }

            if (empty($items)) {
                throw new Exception('A sale must contain at least one item');
            }

            $receiptTotal = round($receiptTotal, 2);
            $data['total_amount'] = $receiptTotal;
            if (!in_array($data['payment_method'] ?? '', ['cash', 'gcash', 'bank_transfer', 'credit'], true)) throw new InvalidArgumentException('Invalid payment method.');
            $tendered = $data['amount_tendered'] ?? null;
            if ($tendered !== null && $tendered !== '') {
                $tendered = nonNegativeAmount($tendered, 'Amount tendered');
                if ($data['payment_method'] === 'cash' && $tendered < $receiptTotal) throw new InvalidArgumentException('Cash tendered is below the sale total.');
                $data['amount_tendered'] = $tendered;
                $data['change_amount'] = max(0, round($tendered - $receiptTotal, 2));
            } else { $data['amount_tendered'] = null; $data['change_amount'] = null; }


            // Work out exactly which trays this receipt will touch before writing
            // anything. Explicit tray picks are honoured as chosen; anything else
            // is filled from the FIFO pick list (oldest stock first).
            $plan = [];
            $touches = [];
            foreach ($items as $item) {
                if ($item['tray_id'] !== null) {
                    $assignments = [['tray_id' => $item['tray_id'], 'take' => $item['quantity']]];
                } else {
                    $picked = $fifoManager->getBestTraysForSale($item['egg_size'], $item['quantity'], $touches);
                    $available = array_sum(array_column($picked, 'take'));
                    if ($available < $item['quantity']) {
                        throw new Exception('Insufficient stock for ' . $item['egg_size']
                            . " (requested {$item['quantity']}, available {$available})");
                    }
                    $assignments = array_map(
                        fn($p) => ['tray_id' => $p['tray']['tray_id'], 'take' => (int)$p['take']],
                        $picked
                    );
                }

                foreach ($assignments as $assignment) {
                    $touches[$assignment['tray_id']] = ($touches[$assignment['tray_id']] ?? 0) + $assignment['take'];
                    // An explicitly picked tray must actually hold the sold size.
                    if ($item['tray_id'] !== null && $assignment['tray_id'] !== $item['tray_id']) {
                        throw new Exception("Tray {$assignment['tray_id']} does not hold {$item['egg_size']}");
                    }
                }

                $plan[] = ['item' => $item, 'assignments' => $assignments];
            }

            // Lock every tray this receipt touches, in a stable order, for the rest
            // of the transaction. Without this two overlapping sales both read the
            // same quantity, both pass the stock check, and the second write
            // discards the first -- quietly selling stock that does not exist.
            // Locking in sorted order also stops two multi-tray sales from
            // deadlocking on each other by grabbing the same pair of trays in
            // opposite order.
            $trayIds = array_keys($touches);
            sort($trayIds, SORT_STRING);
            $placeholders = implode(',', array_fill(0, count($trayIds), '?'));
            $lockedRows = $this->db->fetchAll(
                "SELECT id, tray_id, egg_size, quantity, status, fifo_priority_score, expiry_date
                 FROM egg_trays WHERE tray_id IN ($placeholders)
                 ORDER BY tray_id FOR UPDATE",
                $trayIds
            );

            $locked = [];
            foreach ($lockedRows as $row) {
                $locked[$row['tray_id']] = $row;
            }

            foreach ($touches as $trayId => $needed) {
                $row = $locked[$trayId] ?? null;
                if (!$row) {
                    throw new Exception("Tray {$trayId} was not found");
                }
                if ($row['status'] !== 'in_stock') {
                    throw new Exception("Tray {$trayId} is not available for sale (status: {$row['status']})");
                }
                if ($row['expiry_date'] < date('Y-m-d')) throw new InvalidArgumentException('Expired trays cannot be sold.');
                if ((int)$row['quantity'] < $needed) {
                    throw new Exception("Insufficient stock in tray {$trayId} (requested {$needed}, available {$row['quantity']})");
                }
            }

            // A tray the cashier picked by hand must be the oldest stock of its
            // size, otherwise FIFO is quietly broken.
            $nonCompliant = false;
            foreach ($plan as $entry) {
                if ($entry['item']['tray_id'] === null) continue;
                $compliance = $fifoManager->validateFIFOCompliance($entry['item']['tray_id']);
                if (!empty($compliance['compliant'])) continue;
                if (!$override) {
                    throw new Exception('FIFO_BLOCKED:' . json_encode([
                        'tray_id' => $entry['item']['tray_id'],
                        'reason' => $compliance['reason'] ?? 'Non-FIFO tray'
                    ]));
                }
                $nonCompliant = true;
                $fifoManager->logFIFOViolation(
                    $entry['item']['tray_id'],
                    getCurrentUserId(),
                    'Sold with manager override (score ' . round($compliance['tray_score'] ?? 0, 3)
                        . ' vs best ' . round($compliance['best_score'] ?? 0, 3) . ')'
                );
            }

            // Receipt numbers are sequential per day and there is a UNIQUE index on
            // them, so two cashiers saving at the same moment can collide. On a
            // clash, step to the next sequence number directly -- re-reading
            // MAX(receipt_number) here would only replay the stale snapshot.
            $receiptNumber = null;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = $pendingReceiptNumber;
                $pendingReceiptNumber = generateReceiptNumber(receiptNumberSequence($candidate));
                try {
                    $this->db->insert(
                        "INSERT INTO receipts (receipt_number, sale_data, total_amount, payment_method,
                         customer_info, staff_id, amount_tendered, change_amount, is_fifo_compliant, is_voided, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())",
                        [
                            $candidate,
                            json_encode($items),
                            $receiptTotal,
                            $data['payment_method'],
                            json_encode($data['customer_info'] ?? []),
                            $data['staff_id'],
                            $data['amount_tendered'] ?? null,
                            $data['change_amount'] ?? null
                        ]
                    );
                    $receiptNumber = $candidate;
                    break;
                } catch (PDOException $e) {
                    // 1062 = duplicate key. Any other integrity error is a real fault
                    // and must surface instead of being masked as a numbering clash.
                    $isDuplicateKey = (int)($e->errorInfo[1] ?? 0) === 1062;
                    if (!$isDuplicateKey) throw $e;
                }
            }

            if ($receiptNumber === null) {
                throw new Exception('Could not allocate a unique receipt number. Please retry.');
            }

            $receiptId = $pdo->lastInsertId();

            if ($nonCompliant) {
                $this->db->update(
                    "UPDATE receipts SET is_fifo_compliant = 0 WHERE id = ?",
                    [$receiptId]
                );
            }

            $currentQty = [];
            foreach ($lockedRows as $row) {
                $currentQty[$row['tray_id']] = (int)$row['quantity'];
            }

            foreach ($plan as $entry) {
                $item = $entry['item'];

                foreach ($entry['assignments'] as $assignment) {
                    $trayId = $assignment['tray_id'];
                    $take = $assignment['take'];

                    if ($locked[$trayId]['egg_size'] !== $item['egg_size']) {
                        throw new Exception("Tray {$trayId} contains {$locked[$trayId]['egg_size']}, not {$item['egg_size']}");
                    }

                    $this->db->insert(
                        "INSERT INTO receipt_items (receipt_id, tray_id, egg_size, quantity,
                         unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $receiptId,
                            $trayId,
                            $item['egg_size'],
                            $take,
                            $item['unit_price'],
                            round($take * $item['unit_price'], 2)
                        ]
                    );

                    $currentQty[$trayId] -= $take;
                    $this->writeTrayStock($trayId, $currentQty[$trayId]);
                }

                $this->recordSale($item['egg_size'], $item['quantity'], $item['subtotal'], $data, $receiptId);
            }

            $pdo->commit();
            return ['receipt_number' => $receiptNumber, 'receipt_id' => $receiptId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => $e->getMessage()];
        }
    }

    public function markPrinted($receiptNumber) {
        return $this->db->update(
            "UPDATE receipts SET printed_at = NOW() WHERE receipt_number = ? AND printed_at IS NULL",
            [$receiptNumber]
        );
    }

    public function voidReceipt($receiptNumber, $reason, $userId) {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            $receipt = $this->db->fetchOne('SELECT * FROM receipts WHERE receipt_number = ? AND is_voided = 0 FOR UPDATE', [$receiptNumber]);
            if (!$receipt) { $pdo->rollBack(); return false; }
            $items = $this->db->fetchAll(
                "SELECT * FROM receipt_items WHERE receipt_id = ?",
                [$receipt['id']]
            );

            // Put the eggs back, holding locks on the trays first so a sale
            // running at the same time cannot interleave with the restoration
            // and lose one of the two deductions.
            $trayIds = array_values(array_unique(array_filter(array_column($items, 'tray_id'))));
            $locked = [];
            if ($trayIds) {
                sort($trayIds, SORT_STRING);
                $placeholders = implode(',', array_fill(0, count($trayIds), '?'));
                $rows = $this->db->fetchAll(
                    "SELECT tray_id, quantity, status, expiry_date FROM egg_trays
                     WHERE tray_id IN ($placeholders) ORDER BY tray_id FOR UPDATE",
                    $trayIds
                );
                foreach ($rows as $row) {
                    $locked[$row['tray_id']] = $row;
                }
            }

            $this->db->update(
                "UPDATE receipts SET is_voided = 1, void_reason = ?, voided_by = ?, voided_at = NOW()
                 WHERE receipt_number = ?",
                [$reason, $userId, $receiptNumber]
            );

            $restored = [];
            foreach ($items as $item) {
                if (empty($item['tray_id'])) continue;
                $trayId = $item['tray_id'];
                $restored[$trayId] = ($restored[$trayId] ?? 0) + (int)$item['quantity'];
            }

            foreach ($restored as $trayId => $quantity) {
                $row = $locked[$trayId] ?? null;
                if (!$row) continue;
                // Only bring a tray back to sellable if it is still sitting at
                // 'sold'. If it was damaged, discarded or deleted after the sale,
                // voiding the receipt must not quietly resurrect sellable stock.
                $newStatus = $row['status'] === 'sold' ? 'in_stock' : $row['status'];
                // The eggs always go back, but a tray whose expiry date has already
                // passed cannot go back on the shelf as sellable -- the pick list and
                // dispose flow both treat expiry_date < CURDATE() as unsellable, so
                // reviving it here would offer spoiled stock for sale.
                if ($newStatus === 'in_stock' && !empty($row['expiry_date']) && $row['expiry_date'] < date('Y-m-d')) {
                    $newStatus = 'discarded';
                }
                $this->db->update(
                    "UPDATE egg_trays SET quantity = quantity + ?, status = ?, updated_at = NOW()
                     WHERE tray_id = ?",
                    [$quantity, $newStatus, $trayId]
                );
                if ($newStatus === 'discarded') {
                    $this->db->update(
                        "INSERT INTO damaged_log (tray_id, quantity, issue_type, reported_by, created_at)
                         VALUES (?, ?, 'expired', ?, NOW())",
                        [$trayId, $quantity, $userId]
                    );
                }
            }

            // A voided sale must disappear from reporting and from KNN training data,
            // otherwise the analytics keep counting revenue that was never collected.
            // Legacy rows written before receipt_id existed cannot be matched safely.
            $this->db->update(
                "DELETE FROM sales_history WHERE receipt_id = ?",
                [$receipt['id']]
            );

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function getReceipt($receiptNumber) {
        $receipt = $this->db->fetchOne(
            "SELECT r.*, s.full_name as staff_name
             FROM receipts r
             LEFT JOIN staff_users s ON r.staff_id = s.id
             WHERE r.receipt_number = ?",
            [$receiptNumber]
        );

        if (!$receipt) return null;

        $receipt['items'] = $this->db->fetchAll(
            "SELECT ri.*, es.size_label 
             FROM receipt_items ri
             JOIN egg_sizes es ON ri.egg_size = es.size_code
             WHERE ri.receipt_id = ?",
            [$receipt['id']]
        );

        $receipt['sale_data'] = json_decode($receipt['sale_data'], true);
        $receipt['customer_info'] = json_decode($receipt['customer_info'], true);

        return $receipt;
    }

    public function getReceipts($page = 1, $perPage = 20, $filters = []) {
        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "r.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "r.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['staff_id'])) {
            $where[] = "r.staff_id = ?";
            $params[] = $filters['staff_id'];
        }
        if (isset($filters['is_voided'])) {
            $where[] = "r.is_voided = ?";
            $params[] = $filters['is_voided'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countResult = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM receipts r {$whereClause}",
            $params
        );
        $total = $countResult['total'] ?? 0;

        $offset = ($page - 1) * $perPage;
        $receipts = $this->db->fetchAll(
            "SELECT r.*, s.full_name as staff_name
             FROM receipts r
             LEFT JOIN staff_users s ON r.staff_id = s.id
             {$whereClause}
             ORDER BY r.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        return [
            'receipts' => $receipts,
            'pagination' => getPagination($page, $perPage, $total)
        ];
    }

    public function getDailySummary($date) {
        return $this->db->fetchOne(
            "SELECT COUNT(*) as receipt_count, SUM(total_amount) as total_sales,
                    AVG(total_amount) as avg_sale,
                    COUNT(DISTINCT staff_id) as active_staff
             FROM receipts 
             WHERE DATE(created_at) = ? AND is_voided = 0",
            [$date]
        );
    }

    /**
     * Persist a tray's new egg count. Callers must already hold the tray's
     * `SELECT ... FOR UPDATE` row lock for the current transaction, which is
     * what makes writing an absolute value safe here instead of a lost update.
     */
    private function writeTrayStock($trayId, $newQty) {
        $newQty = max((int)$newQty, 0);
        $newStatus = $newQty <= 0 ? 'sold' : 'in_stock';

        $this->db->update(
            "UPDATE egg_trays SET quantity = ?, status = ?, updated_at = NOW()
             WHERE tray_id = ?",
            [$newQty, $newStatus, $trayId]
        );

        if ($newStatus === 'sold') {
            require_once __DIR__ . '/AlertManager.php';
            $alertManager = new AlertManager();
            $alertManager->resolveAlertsForTray($trayId);
        }
    }

    private function recordSale($eggSize, $quantity, $amount, $data, $receiptId = null) {
        $sizeRow = $this->db->fetchOne(
            "SELECT tray_capacity FROM egg_sizes WHERE size_code = ?",
            [$eggSize]
        );
        $trayCapacity = (int)($sizeRow['tray_capacity'] ?? 0);

        // Quick Sale is priced per individual egg, so the quantity is stored as a
        // tray-equivalent demand figure with zero loose eggs. Writing the same eggs
        // into individual_eggs_sold as well would double-count the sale, because
        // reporting derives revenue as trays*price_per_tray + loose*price_per_egg
        // and volume as trays*tray_capacity + loose. price_per_tray always equals
        // tray_capacity * price_per_egg, so the tray-equivalent is revenue-exact.
        $traysSold = $trayCapacity > 0 ? round($quantity / $trayCapacity, 2) : 0.0;

        $saleDate = date('Y-m-d');

        $this->db->insert(
            "INSERT INTO sales_history (sale_date, egg_size, trays_sold, individual_eggs_sold,
             total_amount, customer_type, day_of_week, is_weekend, is_holiday, month, year, receipt_id)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $saleDate,
                $eggSize,
                $traysSold,
                $amount,
                $data['customer_info']['type'] ?? 'retail',
                (int)date('w', strtotime($saleDate)),
                isWeekend($saleDate) ? 1 : 0,
                isHoliday($saleDate) ? 1 : 0,
                (int)date('n', strtotime($saleDate)),
                (int)date('Y', strtotime($saleDate)),
                $receiptId
            ]
        );
    }
}
