<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/KNNPredictor.php';

header('Content-Type: application/json');
requireAdmin();
requirePostRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

try {
    $predictor = new KNNPredictor();
    $result = $predictor->retrainModel();
} catch (Throwable $e) {
    // Previously an uncaught failure returned an HTML fatal error, so the page's
    // r.json() rejected and the button appeared to do nothing at all.
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

logActivity(getCurrentUserId(), 'retrain_model',
    "Retrained KNN model: {$result['version']} (MAPE: {$result['mape']}%, K={$result['k']})");

echo json_encode(array_merge(['success' => true], $result));