<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/TrayManager.php';

header('Content-Type: application/json');
requireAuth();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['batch_id']) || !isset($input['physical_count'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

if (filter_var($input['physical_count'], FILTER_VALIDATE_INT) === false || (int)$input['physical_count'] < 0) { http_response_code(400); echo json_encode(['error' => 'Physical count must be a non-negative whole number']); exit; }
$trayManager = new TrayManager();
$result = $trayManager->verifyBatchCount($input['batch_id'], (int)$input['physical_count']);

echo json_encode($result);
