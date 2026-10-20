<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/BatchManager.php';

header('Content-Type: application/json');
requireAuth();
requirePostRequest();

$data = [
    'production_date' => $_POST['production_date'] ?? date('Y-m-d'),
    'supplier' => $_POST['supplier'] ?? '',
    'egg_size' => $_POST['egg_size'] ?? '',
    'tray_count' => $_POST['tray_count'] ?? 0,
    'generate_trays' => true
];

if (empty($data['egg_size']) || $data['tray_count'] <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$batchManager = new BatchManager();

try {
    $result = $batchManager->createBatch($data);
    logActivity(getCurrentUserId(), 'create_batch', "Created batch {$result['batch_number']} with {$data['tray_count']} trays of {$data['egg_size']}");
    echo json_encode(['success' => true, 'batch_id' => $result['batch_id'], 'batch_number' => $result['batch_number']]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
