<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/FIFOManager.php';

requireAuth();
requirePostRequest();

header('Content-Type: application/json');

$fifoManager = new FIFOManager();
$fifoManager->recalculateAllScores();

logActivity(getCurrentUserId(), 'recalculate_fifo', 'Manually recalculated FIFO scores');
echo json_encode(['success' => true]);
