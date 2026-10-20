<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';

header('Content-Type: application/json');
requireAuth();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['tray_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No tray_id provided']);
    exit;
}

$db = Database::getInstance();
$result = $db->update(
    "UPDATE egg_trays SET last_scanned_at = NOW(), last_scanned_by = ? WHERE tray_id = ?",
    [getCurrentUserId(), $input['tray_id']]
);

logActivity(getCurrentUserId(), 'picked', "Marked tray {$input['tray_id']} as picked");

echo json_encode(['success' => true]);
