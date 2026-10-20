<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';

header('Content-Type: application/json');
requireAdmin();
requirePostRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['batch_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No batch_id provided']);
    exit;
}

$db = Database::getInstance();

$batchId = filter_var($input['batch_id'], FILTER_VALIDATE_INT);
if (!$batchId || $batchId < 1) { http_response_code(400); echo json_encode(['error' => 'Invalid batch ID']); exit; }
$db->beginTransaction();
try {
    $batch = $db->fetchOne('SELECT id, batch_number FROM batches WHERE id = ? FOR UPDATE', [$batchId]);
    if (!$batch) throw new InvalidArgumentException('Batch not found.');
    $trays = $db->fetchAll('SELECT tray_id FROM egg_trays WHERE batch_id = ? ORDER BY tray_id FOR UPDATE', [$batchId]);
    $trayCount = count($trays);
    $sales = $db->fetchOne('SELECT ri.id FROM receipt_items ri JOIN egg_trays t ON t.tray_id = ri.tray_id WHERE t.batch_id = ? LIMIT 1 FOR UPDATE', [$batchId]);
    $damage = $db->fetchOne('SELECT d.id FROM damaged_log d JOIN egg_trays t ON t.tray_id = d.tray_id WHERE t.batch_id = ? LIMIT 1 FOR UPDATE', [$batchId]);
    if ($sales || $damage) throw new InvalidArgumentException('This batch has sales or damage records. Keep it for traceability.');
    $db->delete('DELETE FROM batches WHERE id = ?', [$batchId]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->getPdo()->inTransaction()) $db->rollback();
    http_response_code(400); echo json_encode(['error' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Batch could not be deleted.']); exit;
}

logActivity(getCurrentUserId(), 'delete_batch', "Deleted batch #{$batch['batch_number']} ({$trayCount} trays)");

echo json_encode(['success' => true]);
