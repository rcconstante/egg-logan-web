<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/StaffManager.php';
header('Content-Type: application/json');
requireAdmin();
requirePostRequest();
try {
    $id = (new StaffManager())->create($_POST);
    logActivity(getCurrentUserId(), 'create_staff', 'Created approved staff account ' . $id);
    echo json_encode(['success' => true]);
} catch (Throwable $error) { http_response_code(400); echo json_encode(['error' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Account could not be created.']); }
