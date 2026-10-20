<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/AlertManager.php';

requireAuth();
requirePostRequest();

header('Content-Type: application/json');

$alertManager = new AlertManager();
$countBefore = $alertManager->getAlertCount();
$pruned = $alertManager->pruneOldAlerts();
$alertManager->refresh();
$countAfter = $alertManager->getAlertCount();
$newAlerts = max(0, $countAfter - $countBefore);

echo json_encode([
    'success' => true,
    'alerts_created' => $newAlerts,
    'resolved' => max(0, $countBefore - $countAfter),
    'pruned_history' => $pruned,
    'total_active' => $countAfter
]);
