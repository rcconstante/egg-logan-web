<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/StaffManager.php';
header('Content-Type: application/json');
requireAdmin();
requirePostRequest();
$input = json_decode(file_get_contents('php://input'), true);
try {
    $id = positiveInteger(is_array($input) ? ($input['user_id'] ?? null) : null, 'User ID');
    (new StaffManager())->resetPassword($id, $input['password'] ?? null);
    logActivity(getCurrentUserId(), 'reset_password', 'Reset password for staff ' . $id);
    echo json_encode(['success' => true]);
} catch (Throwable $error) { http_response_code(400); echo json_encode(['error' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Password could not be reset.']); }
