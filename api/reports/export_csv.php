<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';

requireAuth();

$db = Database::getInstance();
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');

$reportType = $_GET['type'] ?? 'sales';

try {
    $from = validCalendarDate($from); $to = validCalendarDate($to);
    if ($from > $to) throw new InvalidArgumentException('Start date must precede end date.');
    if (!in_array($reportType, ['sales', 'inventory', 'wastage', 'fifo', 'activity'], true)) throw new InvalidArgumentException('Unknown export type.');
    if ($reportType === 'activity' && !isAdmin()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => 'Admin access required']); exit; }
} catch (InvalidArgumentException $error) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => $error->getMessage()]); exit; }
function writeCsvRow($stream, array $row): void {
    $safe = array_map(static function($cell) { return is_string($cell) && preg_match('/^[=+\-@\t\r\n]/', ltrim($cell, ' ')) ? "'" . $cell : $cell; }, $row);
    fputcsv($stream, $safe);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="egglogan_report_' . $from . '_to_' . $to . '.csv"');

$output = fopen('php://output', 'w');

if ($reportType === 'sales') {
    writeCsvRow($output, ['Receipt #', 'Date', 'Total Amount', 'Payment Method', 'Staff', 'Status']);
    $data = $db->fetchAll(
        "SELECT r.receipt_number, r.created_at, r.total_amount, r.payment_method, 
                COALESCE(s.full_name, 'N/A') as staff_name,
                CASE WHEN r.is_voided = 1 THEN 'VOIDED' ELSE 'ACTIVE' END as status
         FROM receipts r
         LEFT JOIN staff_users s ON r.staff_id = s.id
         WHERE DATE(r.created_at) BETWEEN ? AND ?
         ORDER BY r.created_at DESC",
        [$from, $to]
    );
    foreach ($data as $row) {
        writeCsvRow($output, $row);
    }
} elseif ($reportType === 'inventory') {
    writeCsvRow($output, ['Size', 'Trays', 'Total Eggs', 'Oldest Batch']);
    $data = $db->fetchAll(
        "SELECT s.size_label, COUNT(t.id) as trays, SUM(t.quantity) as total_eggs,
                MIN(t.production_date) as oldest
         FROM egg_trays t
         JOIN egg_sizes s ON t.egg_size = s.size_code
         WHERE t.status = 'in_stock'
         GROUP BY s.size_code, s.size_label"
    );
    foreach ($data as $row) {
        writeCsvRow($output, $row);
    }
} elseif ($reportType === 'wastage') {
    writeCsvRow($output, ['Date', 'Category', 'Egg Size', 'Eggs Lost', 'Issue Type']);
    $data = $db->fetchAll(
        "SELECT DATE(dl.created_at) as damage_date, s.category, s.size_label,
                SUM(dl.quantity) as eggs_lost, dl.issue_type
         FROM damaged_log dl
         JOIN egg_trays t ON dl.tray_id = t.tray_id
         JOIN egg_sizes s ON t.egg_size = s.size_code
         WHERE DATE(dl.created_at) BETWEEN ? AND ?
         GROUP BY DATE(dl.created_at), s.category, s.size_label, dl.issue_type
         ORDER BY damage_date DESC",
        [$from, $to]
    );
    foreach ($data as $row) {
        writeCsvRow($output, $row);
    }
} elseif ($reportType === 'fifo') {
    writeCsvRow($output, ['Date/Time', 'Action', 'User', 'Details']);
    $data = $db->fetchAll(
        "SELECT a.created_at, a.action,
                COALESCE(s.full_name, 'System') as user_name, a.details
         FROM activity_logs a
         LEFT JOIN staff_users s ON a.user_id = s.id
         WHERE a.action IN ('fifo_violation', 'fifo_update', 'sale')
           AND DATE(a.created_at) BETWEEN ? AND ?
         ORDER BY a.created_at DESC",
        [$from, $to]
    );
    foreach ($data as $row) {
        writeCsvRow($output, $row);
    }
} elseif ($reportType === 'activity') {
    writeCsvRow($output, ['Date/Time', 'User', 'Action', 'Details', 'IP Address']);
    $data = $db->fetchAll(
        "SELECT a.created_at, COALESCE(s.full_name, 'System') as user_name,
                a.action, a.details, a.ip_address
         FROM activity_logs a
         LEFT JOIN staff_users s ON a.user_id = s.id
         WHERE DATE(a.created_at) BETWEEN ? AND ?
         ORDER BY a.created_at DESC",
        [$from, $to]
    );
    foreach ($data as $row) {
        writeCsvRow($output, $row);
    }
}

fclose($output);
exit;
