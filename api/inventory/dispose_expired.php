<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AlertManager.php';

requireAuth();
requirePostRequest();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$trayId = trim($input['tray_id'] ?? '');
$reason = trim($input['reason'] ?? 'expired');

if (!$trayId) {
    http_response_code(400);
    echo json_encode(['error' => 'tray_id required']);
    exit;
}

$db = Database::getInstance();

$tray = $db->fetchOne(
    "SELECT t.*, b.batch_number, s.size_label, s.category
     FROM egg_trays t
     JOIN batches b ON t.batch_id = b.id
     JOIN egg_sizes s ON t.egg_size = s.size_code
     WHERE t.tray_id = ? AND t.status = 'in_stock'",
    [$trayId]
);

if (!$tray) {
    http_response_code(404);
    echo json_encode(['error' => 'Tray not found or already processed']);
    exit;
}

$isExpired = strtotime($tray['expiry_date']) < time();

$db->getPdo()->beginTransaction();
try {
    $db->update(
        "UPDATE egg_trays SET status = 'discarded', updated_at = NOW() WHERE tray_id = ?",
        [$trayId]
    );

    $userId = getCurrentUserId();
    $details = sprintf(
        "Disposed tray %s | Size: %s (%s) | Batch: %s | Qty: %d | Reason: %s%s",
        $trayId,
        $tray['size_label'],
        $tray['egg_size'],
        $tray['batch_number'],
        $tray['quantity'],
        $reason,
        $isExpired ? ' | EXPIRED (expiry: ' . $tray['expiry_date'] . ')' : ''
    );
    logActivity($userId, 'tray_disposed', $details);

    $alertManager = new AlertManager();
    $alertManager->resolveAlertsForTray($trayId);

    $db->getPdo()->commit();

    echo json_encode([
        'success' => true,
        'tray_id' => $trayId,
        'size' => $tray['egg_size'],
        'size_label' => $tray['size_label'],
        'batch' => $tray['batch_number'],
        'quantity' => (int)$tray['quantity'],
        'was_expired' => $isExpired
    ]);
} catch (\Exception $e) {
    $db->getPdo()->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to dispose tray: ' . $e->getMessage()]);
}
