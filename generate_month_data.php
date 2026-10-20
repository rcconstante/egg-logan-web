<?php
/**
 * Generate 1 month of realistic sales data for EggLogan
 * Run: php generate_month_data.php
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

echo "Generating 1 month of sales data...\n";

// Clear existing sales history for clean test
$pdo->exec("DELETE FROM sales_history");
$pdo->exec("DELETE FROM knn_predictions");
$pdo->exec("DELETE FROM model_versions");
$pdo->exec("DELETE FROM receipts");
$pdo->exec("DELETE FROM receipt_items");
$pdo->exec("DELETE FROM egg_trays");
$pdo->exec("DELETE FROM batches");

// Egg sizes with base demand (trays per day)
$sizes = [
    'SS' => ['label' => 'Super Small', 'capacity' => 30, 'base' => 2.4, 'price_tray' => 180, 'price_egg' => 6],
    'SM' => ['label' => 'Small', 'capacity' => 30, 'base' => 3.1, 'price_tray' => 210, 'price_egg' => 7],
    'MD' => ['label' => 'Medium', 'capacity' => 30, 'base' => 5.6, 'price_tray' => 240, 'price_egg' => 8],
    'LG' => ['label' => 'Large', 'capacity' => 30, 'base' => 5.0, 'price_tray' => 270, 'price_egg' => 9],
    'XL' => ['label' => 'Extra Large', 'capacity' => 24, 'base' => 2.0, 'price_tray' => 240, 'price_egg' => 10],
    'JB' => ['label' => 'Jumbo', 'capacity' => 24, 'base' => 1.5, 'price_tray' => 288, 'price_egg' => 12],
    'SA' => ['label' => 'Salted', 'capacity' => 30, 'base' => 1.3, 'price_tray' => 360, 'price_egg' => 12],
];

// Day of week multipliers (Mon=1 ... Sun=0)
$dowMul = [1 => 0.92, 2 => 1.05, 3 => 1.00, 4 => 1.02, 5 => 1.08, 6 => 1.35, 0 => 1.25];

// Growth trend: slight increase over month
$growthPerDay = 0.0022;

// Start 30 days ago
$startDate = new DateTime('-30 days');
$endDate = new DateTime('yesterday');

$stmtSales = $pdo->prepare("
    INSERT INTO sales_history (sale_date, egg_size, trays_sold, individual_eggs_sold, total_amount, customer_type, day_of_week, is_weekend, is_holiday, month, year, data_quality)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'exact')
");

$stmtReceipt = $pdo->prepare("
    INSERT INTO receipts (receipt_number, sale_data, total_amount, payment_method, customer_info, staff_id, amount_tendered, change_amount, is_fifo_compliant, is_voided, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)
");

$stmtReceiptItem = $pdo->prepare("
    INSERT INTO receipt_items (receipt_id, tray_id, egg_size, quantity, unit_price, subtotal)
    VALUES (?, ?, ?, ?, ?, ?)
");

$receiptCounter = 1000;

$currentDate = clone $startDate;
while ($currentDate <= $endDate) {
    $dateStr = $currentDate->format('Y-m-d');
    $dow = (int)$currentDate->format('w');
    $isWeekend = ($dow === 0 || $dow === 6) ? 1 : 0;
    $month = (int)$currentDate->format('n');
    $year = (int)$currentDate->format('Y');
    
    // Philippines holidays (simplified)
    $holidays = ['01-01', '12-25', '12-30', '11-30', '06-12'];
    $isHoliday = in_array($currentDate->format('m-d'), $holidays) ? 1 : 0;
    
    $dayGrowth = 1 + ($growthPerDay * $currentDate->diff($startDate)->days);
    
    $dailyTotalSales = 0;
    $dailyReceipts = [];
    
    foreach ($sizes as $code => $info) {
        // Base demand with day-of-week and growth
        $demand = $info['base'] * $dowMul[$dow] * $dayGrowth;
        
        // Add realistic noise (±20%)
        $noise = 0.8 + (mt_rand() / mt_getrandmax()) * 0.4;
        $trays = max(0, round($demand * $noise, 1));
        
        // Sometimes sell loose eggs
        $looseEggs = mt_rand(0, 5);
        if ($trays === 0 && $looseEggs === 0) {
            // Occasionally zero sales
            if (mt_rand(1, 10) > 3) continue;
        }
        
        // Calculate sales amount
        $trayAmount = $trays * $info['price_tray'];
        $eggAmount = $looseEggs * $info['price_egg'];
        $totalAmount = round($trayAmount + $eggAmount, 2);
        
        $customerTypes = ['retail', 'wholesale', 'mixed'];
        $customerType = $customerTypes[array_rand($customerTypes)];
        
        // Insert sales history
        $stmtSales->execute([
            $dateStr, $code, $trays, $looseEggs, $totalAmount, $customerType,
            $dow, $isWeekend, $isHoliday, $month, $year
        ]);
        
        $dailyTotalSales += $totalAmount;
        
        // Build receipt data (group 1-3 sizes per receipt)
        if ($trays > 0 || $looseEggs > 0) {
            $dailyReceipts[] = [
                'size' => $code,
                'trays' => $trays,
                'loose' => $looseEggs,
                'amount' => $totalAmount,
                'price_tray' => $info['price_tray'],
                'price_egg' => $info['price_egg'],
            ];
        }
    }
    
    // Create 1-3 receipts per day from the day's sales
    if (!empty($dailyReceipts)) {
        $numReceipts = mt_rand(1, min(3, count($dailyReceipts)));
        shuffle($dailyReceipts);
        
        $chunkSize = ceil(count($dailyReceipts) / $numReceipts);
        $chunks = array_chunk($dailyReceipts, $chunkSize);
        
        foreach ($chunks as $chunk) {
            $receiptNumber = 'R-' . date('Ymd', strtotime($dateStr)) . '-' . str_pad($receiptCounter++, 4, '0', STR_PAD_LEFT);
            $receiptTotal = array_sum(array_column($chunk, 'amount'));
            $receiptTime = $dateStr . ' ' . sprintf('%02d:%02d:%02d', mt_rand(8, 19), mt_rand(0, 59), mt_rand(0, 59));
            
            $saleData = [];
            foreach ($chunk as $item) {
                $saleData[] = [
                    'size' => $item['size'],
                    'trays' => $item['trays'],
                    'loose_eggs' => $item['loose'],
                    'amount' => $item['amount'],
                ];
            }
            
            $paymentMethods = ['cash', 'gcash', 'card'];
            $paymentMethod = $paymentMethods[array_rand($paymentMethods)];
            
            $stmtReceipt->execute([
                $receiptNumber,
                json_encode($saleData),
                round($receiptTotal, 2),
                $paymentMethod,
                json_encode(['type' => $customerType]),
                1, // admin user
                $paymentMethod === 'cash' ? round($receiptTotal + mt_rand(0, 50), 2) : null,
                $paymentMethod === 'cash' ? round(mt_rand(0, 50), 2) : 0,
                $receiptTime
            ]);
            
            $receiptId = $pdo->lastInsertId();
            
            foreach ($chunk as $item) {
                if ($item['trays'] > 0) {
                    $stmtReceiptItem->execute([
                        $receiptId, null, $item['size'], (int)round($item['trays'] * $sizes[$item['size']]['capacity']),
                        $item['price_tray'], round($item['trays'] * $item['price_tray'], 2)
                    ]);
                }
                if ($item['loose'] > 0) {
                    $stmtReceiptItem->execute([
                        $receiptId, null, $item['size'], $item['loose'],
                        $item['price_egg'], round($item['loose'] * $item['price_egg'], 2)
                    ]);
                }
            }
        }
    }
    
    $currentDate->modify('+1 day');
}

echo "Sales history generated.\n";

// Generate some inventory (trays in stock)
echo "Generating inventory...\n";

$batchStmt = $pdo->prepare("
    INSERT INTO batches (batch_number, production_date, supplier, egg_size)
    VALUES (?, ?, ?, ?)
");

$trayStmt = $pdo->prepare("
    INSERT INTO egg_trays (tray_id, qr_code_data, egg_size, batch_id, quantity, production_date, expiry_date, status, fifo_priority_score)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'in_stock', ?)
");

$suppliers = ['EggLogan Farm', 'Cavite Poultry', 'Naic Egg Supply', 'Golden Yolk'];

foreach ($sizes as $code => $info) {
    // Create 2-3 batches per size over the month
    $numBatches = mt_rand(2, 3);
    for ($b = 0; $b < $numBatches; $b++) {
        $batchDate = (clone $startDate)->modify('+' . mt_rand(0, 20) . ' days');
        $batchNumber = 'B-' . $code . '-' . $batchDate->format('Ymd') . '-' . ($b + 1);
        $supplier = $suppliers[array_rand($suppliers)];
        
        $batchStmt->execute([$batchNumber, $batchDate->format('Y-m-d'), $supplier, $code]);
        $batchId = $pdo->lastInsertId();
        
        // 5-15 trays per batch
        $numTrays = mt_rand(5, 15);
        for ($t = 0; $t < $numTrays; $t++) {
            $trayId = 'TRAY-' . strtoupper(bin2hex(random_bytes(8)));
            $prodDate = (clone $batchDate)->modify('+' . mt_rand(0, 3) . ' days');
            $expiryDate = (clone $prodDate)->modify('+' . $info['capacity'] . ' days'); // shelf_life approximation
            $quantity = $info['capacity'];
            $fifoScore = mt_rand(1000, 9999) / 10000;
            
            $qrData = json_encode([
                'tray_id' => $trayId,
                'batch' => $batchNumber,
                'size' => $code,
                'qty' => $quantity,
                'prod' => $prodDate->format('Y-m-d'),
                'exp' => $expiryDate->format('Y-m-d'),
            ]);
            
            $trayStmt->execute([$trayId, $qrData, $code, $batchId, $quantity, $prodDate->format('Y-m-d'), $expiryDate->format('Y-m-d'), $fifoScore]);
        }
    }
}

echo "Inventory generated.\n";

// Run initial prediction to populate knn_predictions
echo "Running initial predictions...\n";
require_once __DIR__ . '/classes/KNNPredictor.php';
$predictor = new KNNPredictor();
$predictions = $predictor->predictAllSizes();

echo "Predictions for today (" . date('Y-m-d') . "):\n";
foreach ($predictions as $code => $pred) {
    if ($code === '_model_meta') continue;
    if (!empty($pred['is_no_data'])) {
        echo "  $code: " . $pred['message'] . "\n";
    } else {
        echo "  $code: {$pred['predicted_trays']} trays ({$pred['predicted_eggs']} eggs) - {$pred['confidence_level']} confidence\n";
    }
}

// Check model version
$model = $db->fetchOne("SELECT * FROM model_versions WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
if ($model) {
    echo "\nActive model: {$model['version_name']} (MAPE: {$model['mape_score']}%, K: {$model['optimal_k_value']})\n";
    $features = json_decode($model['features_used'] ?? '{}', true);
    if (isset($features['avg_accuracy'])) {
        echo "Accuracy: {$features['avg_accuracy']}% ({$features['accuracy_tier']})\n";
    }
}

echo "\n✅ 1 month data generation complete!\n";
echo "You can now test the dashboard, predictions, and inventory pages.\n";