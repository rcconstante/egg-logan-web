<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/ReceiptManager.php';

header('Content-Type: application/json');
requireAuth();

$receiptNumber = $_GET['receipt'] ?? '';
if (empty($receiptNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'No receipt number provided']);
    exit;
}

$receiptManager = new ReceiptManager();
$receipt = $receiptManager->getReceipt($receiptNumber);

if ($receipt) {
    echo json_encode($receipt);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Receipt not found']);
}
