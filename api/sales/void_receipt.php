<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/ReceiptManager.php';

header('Content-Type: application/json');
requireAdmin();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['receipt_number']) || empty($input['reason'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing receipt_number or reason']);
    exit;
}

$receiptManager = new ReceiptManager();
$result = $receiptManager->voidReceipt($input['receipt_number'], $input['reason'], getCurrentUserId());

if ($result) {
    logActivity(getCurrentUserId(), 'void_receipt', "Voided receipt {$input['receipt_number']}: {$input['reason']}");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Receipt not found or already voided']);
}
