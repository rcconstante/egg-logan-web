<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/QRManager.php';

header('Content-Type: application/json');
requireAuth();

echo json_encode(['error' => 'Location scanning is no longer supported']);
