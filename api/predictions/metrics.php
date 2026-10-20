<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/ModelEvaluator.php';
require_once __DIR__ . '/../../classes/AlertManager.php';

header('Content-Type: application/json');
requireAuth();

$evaluator = new ModelEvaluator();
$alertManager = new AlertManager();
$eggSize = $_GET['size'] ?? null;

$metrics = $evaluator->evaluateAllMetrics($eggSize);
if (!$eggSize) {
    $alertManager->checkModelHealth($metrics);
}
$gauge = $evaluator->getOverallAccuracyGauge();

echo json_encode([
    'metrics' => $metrics,
    'overall' => $gauge
]);
