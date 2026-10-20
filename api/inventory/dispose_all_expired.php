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
$action = $input['action'] ?? 'dispose_all';
$reason = trim($input['reason'] ?? 'expired');

$db = Database::getInstance();

$expiredTrays = $db->fetchAll(
    "SELECT t.tray_id, t.quantity, t.egg_size, t.expiry_date, b.batch_number, s.size_label
     FROM egg_trays t
     JOIN batches b ON t.batch_id = b.id
     JOIN egg_sizes s ON t.egg_size = s.size_code
     WHERE t.status = 'in_stock' AND t.expiry_date < CURDATE()
     ORDER BY t.expiry_date ASC"
);

if (empty($expiredTrays)) {
    echo json_encode(['success' => true, 'disposed' => 0, 'message' => 'No expired trays found']);
    exit;
}

$userId = getCurrentUserId();
$disposed = 0;
$errors = [];
$alertManager = new AlertManager();

$db->getPdo()->beginTransaction();
try {
    foreach ($expiredTrays as $tray) {
        $db->update(
            "UPDATE egg_trays SET status = 'discarded', updated_at = NOW() WHERE tray_id = ? AND status = 'in_stock'",
            [$tray['tray_id']]
        );

        $details = sprintf(
            "Bulk disposed tray %s | Size: %s (%s) | Batch: %s | Qty: %d | Reason: %s | EXPIRED (expiry: %s)",
            $tray['tray_id'],
            $tray['size_label'],
            $tray['egg_size'],
            $tray['batch_number'],
            $tray['quantity'],
            $reason,
            $tray['expiry_date']
        );
        logActivity($userId, 'tray_disposed', $details);
        $alertManager->resolveAlertsForTray($tray['tray_id']);
        $disposed++;
    }

    $db->getPdo()->commit();

    echo json_encode([
        'success' => true,
        'disposed' => $disposed,
        'message' => $disposed . ' expired tray' . ($disposed !== 1 ? 's' : '') . ' disposed'
    ]);
} catch (\Exception $e) {
    $db->getPdo()->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Bulk dispose failed: ' . $e->getMessage()]);
}
