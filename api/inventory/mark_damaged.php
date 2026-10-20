<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/TrayManager.php';

header('Content-Type: application/json');
requireAuth();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['tray_id']) || !isset($input['quantity'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$trayManager = new TrayManager();
$result = $trayManager->markDamaged(
    $input['tray_id'],
    $input['quantity'],
    $input['issue_type'] ?? 'other',
    getCurrentUserId()
);

if ($result) {
    logActivity(getCurrentUserId(), 'mark_damaged', "Marked {$input['quantity']} eggs as damaged from tray {$input['tray_id']}");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to mark damaged']);
}
