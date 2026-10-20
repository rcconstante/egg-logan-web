<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/QRManager.php';

header('Content-Type: application/json');
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST to scan a QR code']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['qr_data']) || !is_string($input['qr_data']) || trim($input['qr_data']) === '' || strlen($input['qr_data']) > 4096) {
    http_response_code(400);
    echo json_encode(['error' => 'No QR data provided']);
    exit;
}

$qrManager = new QRManager();
$result = $qrManager->processScan($input['qr_data'], getCurrentUserId());

echo json_encode($result);
