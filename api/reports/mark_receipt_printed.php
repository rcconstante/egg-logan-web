<?php
// Records that a receipt copy actually came out of the printer.
// Called by the Print button on the receipt tab, so merely opening that tab
// does not consume the "already printed" flag.
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/ReceiptManager.php';

header('Content-Type: application/json');
requireAuth();
requirePostRequest();

$receiptNumber = trim((string)($_GET['receipt'] ?? ''));
if ($receiptNumber === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No receipt number provided']);
    exit;
}

$receiptManager = new ReceiptManager();
$affected = $receiptManager->markPrinted($receiptNumber);

echo json_encode(['success' => true, 'receipt_number' => $receiptNumber, 'newly_marked' => (bool)$affected]);