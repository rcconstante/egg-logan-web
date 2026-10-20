<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/KNNPredictor.php';

header('Content-Type: application/json');
requireAuth();

$predictor = new KNNPredictor();
$eggSize = $_GET['size'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d');

if ($eggSize) {
    $result = $predictor->predict($eggSize, $date);
    // predict() bypasses predictAllSizes(), so trigger the weekly check here too.
    $predictor->runScheduledRetrainIfDue();
} else {
    // predictAllSizes() already runs the weekly auto-retrain check.
    $result = $predictor->predictAllSizes($date);
}

echo json_encode($result);