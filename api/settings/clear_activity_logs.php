<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';

requireAdmin();
requirePostRequest();

header('Content-Type: application/json');

$db = Database::getInstance();

$count = $db->fetchOne("SELECT COUNT(*) as total FROM activity_logs");
$total = $count['total'] ?? 0;

if ($total === 0) {
    echo json_encode(['success' => true, 'deleted' => 0, 'message' => 'No logs to clear']);
    exit;
}

$db->delete("DELETE FROM activity_logs");

logActivity(getCurrentUserId(), 'clear_activity_logs', "Cleared {$total} activity log records");

echo json_encode(['success' => true, 'deleted' => (int)$total]);
