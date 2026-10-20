<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';

header('Content-Type: application/json');
requireAdmin();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['egg_size']) || !isset($input['predicted_trays'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing egg_size or predicted_trays']);
    exit;
}

$db = Database::getInstance();
try {
    if (!is_string($input['egg_size']) || !$db->fetchOne('SELECT size_code FROM egg_sizes WHERE size_code = ? AND is_active = 1', [$input['egg_size']])) throw new InvalidArgumentException('Unknown egg size.');
    $input['predicted_trays'] = nonNegativeAmount($input['predicted_trays'], 'Predicted trays');
    if (isset($input['predicted_sales'])) $input['predicted_sales'] = nonNegativeAmount($input['predicted_sales'], 'Predicted sales');
} catch (InvalidArgumentException $error) { http_response_code(400); echo json_encode(['error' => $error->getMessage()]); exit; }

$date = date('Y-m-d');
$predictedSales = isset($input['predicted_sales']) ? (float)$input['predicted_sales'] : null;

$existing = $db->fetchOne(
    "SELECT id FROM knn_predictions WHERE prediction_date = ? AND egg_size = ?",
    [$date, $input['egg_size']]
);

if ($existing) {
    $db->update(
        "UPDATE knn_predictions SET predicted_trays = ?, predicted_sales = COALESCE(?, predicted_sales),
         is_override = 1, overridden_by = ?, updated_at = NOW() WHERE id = ?",
        [$input['predicted_trays'], $predictedSales, getCurrentUserId(), $existing['id']]
    );
} else {
    $db->insert(
        "INSERT INTO knn_predictions (prediction_date, egg_size, predicted_trays, predicted_sales,
         is_override, overridden_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())",
        [$date, $input['egg_size'], $input['predicted_trays'], $predictedSales, getCurrentUserId()]
    );
}

logActivity(getCurrentUserId(), 'override_prediction', "Overrode {$input['egg_size']} prediction to {$input['predicted_trays']} trays" . ($predictedSales !== null ? ", sales: {$predictedSales}" : ""));
echo json_encode(['success' => true]);
