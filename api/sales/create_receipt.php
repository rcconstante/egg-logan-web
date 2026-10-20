<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/ReceiptManager.php';
require_once __DIR__ . '/../../classes/FIFOManager.php';

header('Content-Type: application/json');
requireAuth();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['items']) || !is_array($input['items']) || array_keys($input['items']) !== range(0, count($input['items']) - 1) || count($input['items']) > 100 || !isset($input['total_amount'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required sale data']);
    exit;
}

$overrideRequested = !empty($input['fifo_override']);
if ($overrideRequested && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Only administrators can override FIFO order']);
    exit;
}

$db = Database::getInstance();

$validSizes = [];
foreach ($db->fetchAll("SELECT size_code, price_per_egg FROM egg_sizes") as $pr) {
    $validSizes[$pr['size_code']] = (float)$pr['price_per_egg'];
}

$cleanItems = [];
foreach ($input['items'] as $idx => $item) {
    if (!is_array($item) || !is_string($item['egg_size'] ?? null) || (!is_int($item['quantity'] ?? null) && !is_string($item['quantity'] ?? null)) || (isset($item['tray_id']) && !is_string($item['tray_id']))) { http_response_code(400); echo json_encode(['error' => 'Invalid sale item.']); exit; }
    $size = is_array($item) ? trim((string)($item['egg_size'] ?? '')) : '';
    $qty = is_array($item) ? filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) : false;

    if ($size === '' || !isset($validSizes[$size])) {
        http_response_code(400);
        echo json_encode(['error' => "Item " . ($idx + 1) . ": unknown egg size '" . $size . "'"]);
        exit;
    }
    if ($qty === false || $qty <= 0) {
        http_response_code(400);
        echo json_encode(['error' => "Item " . ($idx + 1) . ": quantity must be a positive whole number"]);
        exit;
    }

    $trayId = is_array($item) ? trim((string)($item['tray_id'] ?? '')) : '';
    if ($trayId !== '') {
        $tray = $db->fetchOne(
            "SELECT tray_id, egg_size, quantity FROM egg_trays WHERE tray_id = ? AND status = 'in_stock'",
            [$trayId]
        );
        if (!$tray) {
            http_response_code(400);
            echo json_encode(['error' => "Item " . ($idx + 1) . ": tray {$trayId} is no longer in stock"]);
            exit;
        }
        if ($tray['egg_size'] !== $size) {
            http_response_code(400);
            echo json_encode(['error' => "Item " . ($idx + 1) . ": tray {$trayId} holds {$tray['egg_size']}, not {$size}"]);
            exit;
        }
        if ($qty > (int)$tray['quantity']) {
            http_response_code(400);
            echo json_encode(['error' => "Item " . ($idx + 1) . ": only {$tray['quantity']} eggs left in tray {$trayId}"]);
            exit;
        }
    }

    $cleanItems[] = [
        'egg_size' => $size,
        'quantity' => $qty,
        'unit_price' => $validSizes[$size],
        'tray_id' => $trayId !== '' ? $trayId : null
    ];
}

if (isset($input['customer_info']) && !is_array($input['customer_info'])) { http_response_code(400); echo json_encode(['error' => 'Invalid customer information.']); exit; }
$data = [
    'items' => $cleanItems,
    'total_amount' => (float)$input['total_amount'],
    'payment_method' => $input['payment_method'] ?? 'cash',
    'amount_tendered' => isset($input['amount_tendered']) && $input['amount_tendered'] !== '' ? $input['amount_tendered'] : null,
    'change_amount' => isset($input['change_amount']) && $input['change_amount'] !== '' ? (float)$input['change_amount'] : null,
    'fifo_override' => $overrideRequested,
    'customer_info' => [
        'type' => $input['customer_type'] ?? 'retail',
        'name' => ($input['customer_info'] ?? [])['name'] ?? '',
        'email' => ($input['customer_info'] ?? [])['email'] ?? '',
        'phone' => ($input['customer_info'] ?? [])['phone'] ?? ''
    ],
    'staff_id' => getCurrentUserId()
];

if (!$overrideRequested) {
    $fifoManager = new FIFOManager();
    foreach ($data['items'] as $item) {
        if (!empty($item['tray_id'])) {
            $compliance = $fifoManager->validateFIFOCompliance($item['tray_id']);
            if (empty($compliance['compliant'])) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'FIFO violation: ' . ($compliance['reason'] ?? 'older stock available'),
                    'tray_id' => $item['tray_id'],
                    'best_score' => $compliance['best_score'] ?? null,
                    'tray_score' => $compliance['tray_score'] ?? null,
                    'can_override' => isAdmin()
                ]);
                exit;
            }
        }
    }
}

$receiptManager = new ReceiptManager();
$recalculatedTotal = 0;
foreach ($data['items'] as &$item) {
    $item['subtotal'] = round($item['unit_price'] * $item['quantity'], 2);
    $recalculatedTotal += $item['subtotal'];
}
unset($item);
$data['total_amount'] = round($recalculatedTotal, 2);
$result = $receiptManager->createReceipt($data);

if (isset($result['error'])) {
    if (strpos($result['error'], 'FIFO_BLOCKED:') === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'FIFO violation blocked this sale', 'details' => json_decode(substr($result['error'], 13), true), 'can_override' => isAdmin()]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $result['error']]);
    }
} else {
    logActivity(getCurrentUserId(), 'sale', "Created receipt {$result['receipt_number']} for " . formatCurrency($data['total_amount']));
    echo json_encode([
        'success' => true,
        'receipt_number' => $result['receipt_number'],
        'receipt_id' => $result['receipt_id']
    ]);
}
