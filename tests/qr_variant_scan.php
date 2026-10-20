<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/classes/QRManager.php';
$db = Database::getInstance();
$pdo = $db->getPdo();
$manager = new QRManager();
$variants = $db->fetchAll('SELECT size_code, tray_capacity FROM egg_sizes WHERE is_active = 1 LIMIT 2');
$staff = $db->fetchOne("SELECT id FROM staff_users WHERE role = 'staff' AND is_active = 1 LIMIT 1");
if (count($variants) < 2 || !$staff) throw new RuntimeException('Required variants/staff unavailable');
$code = $variants[0]['size_code'];
$other = $variants[1]['size_code'];
$prefix = 'VERIFY-' . bin2hex(random_bytes(5));
$checks = 0;
function checkResult($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function batchFixture($db, $code, $number) { return $db->insert('INSERT INTO batches (batch_number, production_date, supplier, egg_size) VALUES (?, CURDATE(), ?, ?)', [$number, 'Rollback verification', $code]); }
function trayFixture($db, $code, $batch, $quantity, $sequence, $status = 'in_stock') {
    $id = bin2hex(random_bytes(16));
    $db->insert("INSERT INTO egg_trays (tray_id, egg_size, batch_id, quantity, production_date, expiry_date, tray_sequence, status) VALUES (?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 11 DAY), ?, ?)", [$id, $code, $batch, $quantity, $sequence, $status]);
    return $id;
}
$pdo->beginTransaction();
try {
    $payload = generateVariantQRData($code);
    $initial = $manager->processScan($payload, $staff['id']);
    $batch1 = batchFixture($db, $code, $prefix . '-1');
    $tray1 = trayFixture($db, $code, $batch1, 17, 1);
    $sold = trayFixture($db, $code, $batch1, 30, 2, 'sold');
    $otherBatch = batchFixture($db, $other, $prefix . '-OTHER');
    $otherTray = trayFixture($db, $other, $otherBatch, 30, 1);
    $first = $manager->processScan($payload, $staff['id']);
    checkResult($first['success'] && $first['variant_scan'], 'Variant scan failed');
    checkResult($first['summary']['total_trays'] === $initial['summary']['total_trays'] + 1, 'Sold/other variant filtering failed');
    checkResult($first['summary']['total_eggs'] === $initial['summary']['total_eggs'] + 17, 'Egg count incorrect');
    $scannedTrays = array_merge(...array_column($first['batches'], 'trays'));
    $tray = array_values(array_filter($scannedTrays, fn($t) => $t['tray_id'] === $tray1))[0];
    checkResult($tray['quantity'] == 17 && $tray['tray_sequence'] == 1, 'Per-tray quantities missing');
    checkResult($tray['production_date'] && $tray['expiry_date'] && (int)$tray['days_until_expiry'] === 11, 'Actual tray dates missing');
    checkResult(!in_array($sold, array_column($scannedTrays, 'tray_id')) && !in_array($otherTray, array_column($scannedTrays, 'tray_id')), 'Incorrect trays exposed');
    $batch2 = batchFixture($db, $code, $prefix . '-2');
    trayFixture($db, $code, $batch2, 23, 1);
    $next = $manager->processScan($payload, $staff['id']);
    checkResult($next['summary']['total_trays'] === $first['summary']['total_trays'] + 1, 'New batch not visible through old QR');
    checkResult($next['summary']['total_eggs'] === $first['summary']['total_eggs'] + 23, 'New batch eggs not counted');
    checkResult($payload === generateVariantQRData($code), 'QR payload changed');
    checkResult(isset($manager->processScan('{"type":"variant","egg_size":[]}', $staff['id'])['error']), 'Malformed QR not rejected');
    checkResult(isset($manager->processScan([], $staff['id'])['error']), 'Non-string QR not rejected');
    echo json_encode(['passed' => $checks, 'reusable_qr' => true, 'staff_scan' => true, 'actual_dates' => true, 'sold_and_other_variants_excluded' => true, 'new_batch_visible_without_reprint' => true]);
} finally { $pdo->rollBack(); }
checkResult(!$db->fetchOne('SELECT id FROM batches WHERE batch_number LIKE ?', [$prefix . '%']), 'Fixture cleanup failed');
echo "\nAll fixtures and scan logs rolled back.\n";
