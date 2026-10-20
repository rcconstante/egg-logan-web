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

$trayId = $input['tray_id'];
$db = Database::getInstance();
$userId = getCurrentUserId();

$tray = $db->fetchOne(
    "SELECT tray_id, status, verified_at FROM egg_trays WHERE tray_id = ? AND status != 'deleted'",
    [$trayId]
);

if (!$tray) {
    http_response_code(404);
    echo json_encode(['error' => 'Tray not found']);
    exit;
}

if ($tray['status'] !== 'in_stock') {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot verify a tray that is not in stock']);
    exit;
}

$db->update(
    "UPDATE egg_trays SET verified_at = NOW(), verified_by = ?, updated_at = NOW() WHERE tray_id = ?",
    [$userId, $trayId]
);

echo json_encode([
    'success' => true,
    'message' => 'Tray verified for sale',
    'verified_at' => date('Y-m-d H:i:s')
]);
