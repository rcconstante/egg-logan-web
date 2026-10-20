<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/TrayManager.php';

header('Content-Type: application/json');
requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['tray_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tray_id']);
    exit;
}

$trayManager = new TrayManager();
$result = $trayManager->moveTray($input['tray_id'], getCurrentUserId());

if ($result) {
    logActivity(getCurrentUserId(), 'move_tray', "Updated tray {$input['tray_id']}");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to update tray']);
}
