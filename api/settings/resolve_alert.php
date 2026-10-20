<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/AlertManager.php';

requireAuth();
requirePostRequest();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$alertId = (int)($input['alert_id'] ?? 0);

if ($alertId <= 0) {
    echo json_encode(['error' => 'Invalid alert ID']);
    exit;
}

$alertManager = new AlertManager();
$alertManager->resolveAlert($alertId);

echo json_encode(['success' => true]);
