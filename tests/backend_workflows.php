<?php
if (PHP_SAPI !== 'cli' || !preg_match('/^egglogan_audit_[a-f0-9]{12}$/', getenv('DB_NAME') ?: '')) { http_response_code(404); exit(1); }
session_save_path(getenv('EGGLOGAN_TEST_SESSION_DIR'));
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/classes/StaffManager.php';
require_once dirname(__DIR__) . '/classes/BatchManager.php';
require_once dirname(__DIR__) . '/classes/TrayManager.php';
require_once dirname(__DIR__) . '/classes/ReceiptManager.php';
require_once dirname(__DIR__) . '/classes/SettingsManager.php';
require_once dirname(__DIR__) . '/classes/BackupManager.php';
require_once dirname(__DIR__) . '/classes/QRManager.php';
if (($argv[1] ?? '') === '--race-sale' || ($argv[1] ?? '') === '--race-void') {
    startSession(); $_SESSION['user_id'] = (int)$argv[4]; $_SESSION['role'] = 'admin';
    $manager = new ReceiptManager();
    if ($argv[1] === '--race-sale') $result = $manager->createReceipt(['items' => [['egg_size' => $argv[2], 'quantity' => (int)$argv[3]]], 'staff_id' => (int)$argv[4], 'payment_method' => 'cash', 'amount_tendered' => 100000]);
    else $result = ['success' => $manager->voidReceipt($argv[2], 'Parallel void audit', (int)$argv[4])];
    echo json_encode($result); exit;
}
function parallelChecks(array $arguments): array {
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__)); fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) { $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($process) !== 0) throw new RuntimeException('Parallel worker failed: ' . $errors); $result = json_decode($output, true); if (!is_array($result)) throw new RuntimeException('Parallel worker returned invalid JSON'); $results[] = $result; }
    return $results;
}
$checks = [];
function verify($condition, $name) { global $checks; if (!$condition) throw new RuntimeException('FAILED: ' . $name); $checks[] = $name; }
function rejects(callable $action, $name) { try { $action(); } catch (InvalidArgumentException $error) { verify(true, $name); return; } throw new RuntimeException('FAILED: ' . $name); }
$db = Database::getInstance(); $accounts = new StaffManager(); $batches = new BatchManager(); $trays = new TrayManager(); $receipts = new ReceiptManager(); $settings = new SettingsManager();
$admin = $accounts->create(['username' => 'auditadmin', 'full_name' => 'Audit Admin', 'password' => 'audit-password', 'role' => 'admin']);
$staff = $accounts->create(['username' => 'auditstaff', 'full_name' => 'Audit Staff', 'password' => 'audit-password', 'role' => 'staff']);
verify((int)$db->fetchOne('SELECT is_approved FROM staff_users WHERE id = ?', [$staff])['is_approved'] === 1, 'Admin-created staff approved');
rejects(fn() => $accounts->create(['username' => 'x', 'password' => 'short', 'full_name' => 'X']), 'Account validation');
rejects(fn() => $accounts->create(['username' => 'auditrole', 'password' => 'audit-password', 'full_name' => 'Audit Role', 'role' => 'owner']), 'Unknown roles rejected');
verify((bool)loginUser('auditadmin', 'audit-password'), 'Administrator login');
$_SERVER['HTTP_X_CSRF_TOKEN'] = csrfToken(); verify(csrfRequestIsValid(), 'CSRF token accepted'); unset($_SERVER['HTTP_X_CSRF_TOKEN']); verify(!csrfRequestIsValid(), 'Missing CSRF rejected');
rejects(fn() => $accounts->toggleStatus($admin, $admin), 'Self-deactivation blocked');
rejects(fn() => $accounts->toggleStatus($admin, $staff), 'Last administrator protected');
$pending = signupUser('auditpending', 'audit-password', 'Audit Pending');
verify(!loginUser('auditpending', 'audit-password'), 'Pending signup cannot log in');
$accounts->toggleStatus((int)$pending, $admin, true); verify((bool)loginUser('auditpending', 'audit-password'), 'Approved signup can log in');
verify((bool)loginUser('auditstaff', 'audit-password'), 'Staff login');
$accounts->toggleStatus($staff, $admin); verify(!checkSession(), 'Deactivated account session revoked'); $accounts->toggleStatus($staff, $admin);
loginUser('auditstaff', 'audit-password'); $accounts->resetPassword($staff, 'changed-audit-password'); verify(!checkSession(), 'Password reset revokes old session');
loginUser('auditadmin', 'audit-password');
$size = $db->fetchOne("SELECT * FROM egg_sizes WHERE is_active = 1 AND price_per_egg > 0 ORDER BY size_code LIMIT 1"); $code = $size['size_code']; $capacity = (int)$size['tray_capacity'];
$batchData = ['production_date' => date('Y-m-d'), 'supplier' => 'Audit supplier', 'egg_size' => $code, 'tray_count' => 2, 'generate_trays' => true];
rejects(fn() => $batches->createBatch(array_merge($batchData, ['production_date' => '2026-02-31'])), 'Invalid production dates rejected');
rejects(fn() => $batches->createBatch(array_merge($batchData, ['tray_count' => '1.5'])), 'Fractional batch counts rejected');
$batch = $batches->createBatch($batchData); $stock = $db->fetchAll('SELECT * FROM egg_trays WHERE batch_id = ? ORDER BY tray_sequence', [$batch['batch_id']]);
verify(count($stock) === 2 && (int)$stock[0]['quantity'] === $capacity, 'Batch creates expected trays and quantities');
verify($stock[0]['expiry_date'] === date('Y-m-d', strtotime('+' . $size['shelf_life'] . ' days')), 'Batch expiry matches shelf life');
$payload = generateVariantQRData($code); $qr = new QRManager(); $before = $qr->processScan($payload, $staff); $newBatch = $batches->createBatch(array_merge($batchData, ['tray_count' => 1])); $after = $qr->processScan($payload, $staff);
verify($after['summary']['total_trays'] === $before['summary']['total_trays'] + 1, 'Permanent QR includes new batches');
$target = $stock[1]['tray_id']; verify(!$trays->markDamaged($target, -1, 'other', $staff), 'Negative damage rejected'); verify(!$trays->markDamaged($target, $capacity + 1, 'other', $staff), 'Damage cannot exceed remaining eggs'); verify(!$trays->markDamaged($target, 1.5, 'other', $staff), 'Fractional damage rejected');
verify($trays->markDamaged($target, 2, 'other', $staff), 'Partial damage succeeds'); verify((int)$db->fetchOne('SELECT quantity FROM egg_trays WHERE tray_id = ?', [$target])['quantity'] === $capacity - 2, 'Damage deducts correct stock');
$sale = ['items' => [['egg_size' => $code, 'quantity' => 3]], 'staff_id' => $staff, 'payment_method' => 'cash', 'amount_tendered' => 10000, 'change_amount' => 999999, 'customer_info' => ['type' => 'retail']];
$receipt = $receipts->createReceipt($sale); verify(!isset($receipt['error']), 'Sale creates receipt');
$saved = $db->fetchOne('SELECT * FROM receipts WHERE id = ?', [$receipt['receipt_id']]); $expected = round(3 * (float)$size['price_per_egg'], 2);
verify((float)$saved['total_amount'] === $expected && (float)$saved['change_amount'] === round(10000 - $expected, 2), 'Server derives price and change');
verify(isset($receipts->createReceipt(array_merge($sale, ['amount_tendered' => 0]))['error']), 'Insufficient cash rejected');
verify(isset($receipts->createReceipt(array_merge($sale, ['items' => [['egg_size' => $code, 'quantity' => 1.5]]]))['error']), 'Fractional sale quantities rejected');
verify(isset($receipts->createReceipt(array_merge($sale, ['items' => [['egg_size' => $code, 'quantity' => 1000000]]]))['error']), 'Overselling rejected');
$beforeVoid = (int)$db->fetchOne("SELECT SUM(quantity) AS n FROM egg_trays WHERE status = 'in_stock'")['n'];
verify($receipts->voidReceipt($receipt['receipt_number'], 'Audit void', $admin), 'Administrator void succeeds');
verify(!$receipts->voidReceipt($receipt['receipt_number'], 'Second void', $admin), 'Receipt cannot be voided twice');
verify((int)$db->fetchOne("SELECT SUM(quantity) AS n FROM egg_trays WHERE status = 'in_stock'")['n'] === $beforeVoid + 3, 'Void restores eggs exactly once');
verify((int)$db->fetchOne('SELECT COUNT(*) AS n FROM sales_history WHERE receipt_id = ?', [$receipt['receipt_id']])['n'] === 0, 'Voided sale removed from prediction history');
$expired = $stock[0]['tray_id']; $db->update('UPDATE egg_trays SET expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE tray_id = ?', [$expired]);
verify(isset($receipts->createReceipt(array_merge($sale, ['fifo_override' => true, 'items' => [['egg_size' => $code, 'quantity' => 1, 'tray_id' => $expired]]]))['error']), 'Expired trays blocked even with FIFO override');
rejects(fn() => $settings->update(['action' => 'update_price', 'size_code' => $code, 'price_per_tray' => -1, 'price_per_egg' => 3], $admin), 'Negative settings prices rejected');
rejects(fn() => $settings->update(['action' => 'update_setting', 'setting_key' => 'low_stock_threshold', 'setting_value' => '50'], $admin), 'Invalid stock thresholds rejected');
$settings->update(['action' => 'update_price', 'size_code' => $code, 'price_per_tray' => $size['price_per_tray'], 'price_per_egg' => $size['price_per_egg']], $admin);
verify((int)$db->fetchOne('SELECT COUNT(*) AS n FROM price_history')['n'] === 1, 'Settings price audit recorded');
$raceSize = $db->fetchOne('SELECT * FROM egg_sizes WHERE is_active = 1 AND size_code <> ? LIMIT 1', [$code]);
$raceBatch = $batches->createBatch(array_merge($batchData, ['egg_size' => $raceSize['size_code'], 'tray_count' => 1]));
$parallelSales = parallelChecks(['--race-sale', $raceSize['size_code'], (string)$raceSize['tray_capacity'], (string)$admin]);
$successful = array_values(array_filter($parallelSales, fn($row) => isset($row['receipt_number'])));
verify(count($successful) === 1, 'Parallel cashiers cannot sell the same stock twice');
verify((int)$db->fetchOne('SELECT SUM(quantity) AS n FROM egg_trays WHERE batch_id = ?', [$raceBatch['batch_id']])['n'] === 0, 'Parallel sale stock remains consistent');
$parallelVoids = parallelChecks(['--race-void', $successful[0]['receipt_number'], '0', (string)$admin]);
verify(count(array_filter($parallelVoids, fn($row) => !empty($row['success']))) === 1, 'Parallel void requests restore stock once');
verify((int)$db->fetchOne('SELECT SUM(quantity) AS n FROM egg_trays WHERE batch_id = ?', [$raceBatch['batch_id']])['n'] === (int)$raceSize['tray_capacity'], 'Parallel void stock remains consistent');
$backup = (new BackupManager())->create(); $backupFile = getenv('EGGLOGAN_BACKUP_DIR') . '/' . $backup;
verify(is_file($backupFile) && filesize($backupFile) > 0, 'Private backup created without shell command');
$restore = getenv('DB_NAME') . '_restore'; $pdo = $db->getPdo(); $pdo->exec('CREATE DATABASE `' . $restore . '`');
try { $pdo->exec('USE `' . $restore . '`'); $pdo->exec(file_get_contents($backupFile)); verify((int)$pdo->query('SELECT COUNT(*) FROM staff_users')->fetchColumn() === 3, 'Backup restores schema and records'); verify($pdo->query('SELECT COUNT(*) FROM vw_current_inventory')->fetchColumn() !== false, 'Backup restores dashboard views'); verify(!str_contains($pdo->query('SHOW CREATE VIEW vw_current_inventory')->fetch(PDO::FETCH_ASSOC)['Create View'], '`' . getenv('DB_NAME') . '`.'), 'Restored views use restored database'); } finally { $pdo->exec('USE `' . getenv('DB_NAME') . '`'); $pdo->exec('DROP DATABASE `' . $restore . '`'); }
$base = $argv[1]; $cookies = [];
function requestApp($path, $method = 'GET', $body = null, array $extra = []) {
    global $base, $cookies; $headers = $extra;
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(fn($name, $value) => $name . '=' . $value, array_keys($cookies), array_values($cookies)));
    if (is_array($body)) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; $body = http_build_query($body); }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
    $content = file_get_contents($base . '/' . $path, false, $context);
    foreach ($http_response_header as $header) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    return [(int)$status[1], $content, $http_response_header];
}
function httpLogin($username, $password) {
    global $cookies; $cookies = []; [, $html] = requestApp('index.php?view=home'); preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $match);
    [$status] = requestApp('index.php', 'POST', ['username' => $username, 'password' => $password, 'csrf_token' => $match[1]]); verify($status === 302, 'HTTP login ' . $username);
    [$dashboardStatus, $html] = requestApp('pages/dashboard.php'); preg_match('/data-csrf-token="([a-f0-9]+)"/', $html, $match); if (!isset($match[1])) throw new RuntimeException('Dashboard status ' . $dashboardStatus . ': ' . substr(strip_tags($html), 0, 350)); return $match[1];
}
[$status] = requestApp('api/scan/process.php', 'POST', '{}', ['Content-Type: application/json']); verify($status === 401, 'API requires authentication');
[$status] = requestApp('api/alerts/live_alerts.php', 'GET', null, ['X-Requested-With: XMLHttpRequest']); verify($status === 401, 'Live alerts reject unauthenticated requests');
$token = httpLogin('auditadmin', 'audit-password');
[$status] = requestApp('api/inventory/create_batch.php', 'POST', []); verify($status === 403, 'API rejects missing CSRF');
$secure = ['X-CSRF-Token: ' . $token];
foreach (['dashboard', 'inventory', 'scan', 'sale', 'picklist', 'predictions', 'reports', 'receipts', 'staff', 'settings'] as $page) {
    [$status, $html] = requestApp('pages/' . $page . '.php'); verify($status === 200 && !str_contains($html, 'Fatal error') && !str_contains($html, 'Warning:'), 'Page renders: ' . $page);
}
foreach (['api/predictions/get.php', 'api/predictions/metrics.php', 'api/sales/get_receipt.php?receipt=' . urlencode($receipt['receipt_number']), 'api/inventory/variant_qr.php?size_code=' . $code] as $endpoint) {
    [$status, $json] = requestApp($endpoint); verify($status === 200 && is_array(json_decode($json, true)), 'Read API works: ' . strtok($endpoint, '?'));
}
[$status, $html] = requestApp('api/reports/print_receipt.php?receipt=' . urlencode($receipt['receipt_number'])); verify($status === 200 && str_contains($html, $receipt['receipt_number']), 'Receipt print page renders');
[$status, $json] = requestApp('api/alerts/live_alerts.php', 'GET', null, ['X-Requested-With: XMLHttpRequest']); verify($status === 200 && is_array(json_decode($json, true)), 'Live alerts require and support authenticated requests');
$db->update('UPDATE staff_users SET full_name = ? WHERE id = ?', ['=1+1', $staff]);
[$status, $csv] = requestApp('api/reports/export_csv.php?type=sales'); verify($status === 200 && str_contains($csv, "'=1+1"), 'CSV formula-like cells escaped');
$db->update('UPDATE staff_users SET full_name = ? WHERE id = ?', ['Audit Staff', $staff]);

[$status] = requestApp('api/settings/run_alerts.php'); verify($status === 405, 'Mutation endpoint rejects GET');
[$status] = requestApp('api/inventory/create_batch.php', 'POST', ['egg_size' => $code, 'tray_count' => '1.5', 'production_date' => date('Y-m-d')], $secure); verify($status === 400, 'API rejects fractional batch counts');
[$status, $body] = requestApp('api/scan/process.php', 'POST', $payload, array_merge($secure, ['Content-Type: application/json'])); verify($status === 400, 'Scan endpoint rejects malformed request envelope');
[$status, $body] = requestApp('api/scan/process.php', 'POST', json_encode(['qr_data' => $payload]), array_merge($secure, ['Content-Type: application/json'])); verify($status === 200 && !empty(json_decode($body, true)['variant_scan']), 'Authenticated QR API works with CSRF');
[$status, $body] = requestApp('api/sales/create_receipt.php', 'POST', json_encode(array_merge($sale, ['total_amount' => 1, 'items' => [['egg_size' => $code, 'quantity' => 1]]])), array_merge($secure, ['Content-Type: application/json']));
$httpSale = json_decode($body, true); if ($status !== 200 || empty($httpSale['success'])) throw new RuntimeException('HTTP sale status ' . $status . ': ' . substr(strip_tags($body), 0, 400)); verify(true, 'HTTP sale completes with CSRF and server pricing');
[$status, $body] = requestApp('api/reports/mark_receipt_printed.php?receipt=' . urlencode($httpSale['receipt_number']), 'POST', null, $secure); verify($status === 200 && !empty(json_decode($body, true)['success']), 'Receipt print marker works with CSRF');
[$status, $body] = requestApp('api/sales/void_receipt.php', 'POST', json_encode(['receipt_number' => $httpSale['receipt_number'], 'reason' => 'HTTP audit']), array_merge($secure, ['Content-Type: application/json'])); verify($status === 200 && !empty(json_decode($body, true)['success']), 'HTTP administrator void completes');

[$status] = requestApp('api/inventory/delete_batch.php', 'POST', json_encode(['batch_id' => $batch['batch_id']]), array_merge($secure, ['Content-Type: application/json'])); verify($status === 400, 'Batch with traceability records cannot be deleted');
[$status, $csv] = requestApp('api/reports/export_csv.php?type=sales'); verify($status === 200 && str_contains($csv, 'Receipt #'), 'CSV export works');
[$status] = requestApp('api/reports/export_csv.php?type=invalid'); verify($status === 400, 'Unknown CSV export rejected');
[$status, $body] = requestApp('api/reports/chart_data.php?type=historical'); verify($status === 200 && is_array(json_decode($body, true)), 'Report chart endpoint returns JSON');
[$status, $html] = requestApp('index.php?view=home'); verify($status === 200 && str_contains($html, '14.325991%2C120.775719'), 'Landing map uses requested coordinates');
$staffToken = httpLogin('auditstaff', 'changed-audit-password');
[$status] = requestApp('api/settings/backup.php', 'POST', null, ['X-CSRF-Token: ' . $staffToken]); verify($status === 403, 'Staff cannot access administrator API');
[$status, $html] = requestApp('pages/dashboard.php'); verify(!str_contains($html, 'href="staff.php"') && !str_contains($html, 'href="settings.php"'), 'Staff navigation hides administrator pages');
[$status] = requestApp('api/reports/export_csv.php?type=activity'); verify($status === 403, 'Staff cannot export private activity logs');
[$status, $html] = requestApp('pages/settings.php'); verify($status === 302, 'Administrator pages enforce role restrictions');
[$status] = requestApp('api/auth/logout.php'); verify($status === 405, 'Logout rejects cross-site GET');
[$status] = requestApp('api/auth/logout.php', 'POST', ['csrf_token' => $staffToken]); verify($status === 302, 'CSRF-protected logout works');
[$status] = requestApp('api/auth/check_session.php'); verify($status === 401, 'Logout invalidates authenticated session');
verify(date_default_timezone_get() === 'Asia/Manila' && $db->fetchOne('SELECT @@session.time_zone AS zone')['zone'] === '+08:00', 'PHP and database business timezone aligned');
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
