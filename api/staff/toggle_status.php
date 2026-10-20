<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/StaffManager.php';
header('Content-Type: application/json');
requireAdmin();
requirePostRequest();
$input = json_decode(file_get_contents('php://input'), true);
try {
    $id = positiveInteger(is_array($input) ? ($input['user_id'] ?? null) : null, 'User ID');
    $approve = ($input['approve'] ?? false) === true;
    $active = (new StaffManager())->toggleStatus($id, getCurrentUserId(), $approve);
    logActivity(getCurrentUserId(), $approve ? 'approve_staff' : 'toggle_staff_status', 'Updated staff account ' . $id);
    echo json_encode(['success' => true, 'is_active' => $active]);
} catch (Throwable $error) { http_response_code(400); echo json_encode(['error' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Account could not be updated.']); }
