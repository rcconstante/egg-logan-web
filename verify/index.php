<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$type = $_GET['type'] ?? null;
$id = $_GET['id'] ?? null;

if (!$type || !$id) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = preg_replace('#^.*?/verify#', '', $path);
    $segments = explode('/', trim($path, '/'));
    if (count($segments) >= 2 && $segments[0] !== '' && $segments[1] !== '') {
        $type = $segments[0];
        $id = implode('/', array_slice($segments, 1));
    } elseif ($segments[0] !== '') {
        $type = '';
        $id = $segments[0];
    }
}

$pdo = DatabaseConnection::connect();

if ($type === 'receipt' || $type === '') {
    $receiptNumber = $id;
    $stmt = $pdo->prepare("SELECT r.*, s.full_name as staff_name FROM receipts r LEFT JOIN staff_users s ON r.staff_id = s.id WHERE r.receipt_number = ?");
    $stmt->execute([$receiptNumber]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        die('<h1>Verification Failed</h1><p>Receipt not found in system.</p>');
    }

    $items = $pdo->prepare("SELECT ri.*, es.size_label FROM receipt_items ri JOIN egg_sizes es ON ri.egg_size = es.size_code WHERE ri.receipt_id = ?");
    $items->execute([$receipt['id']]);
    $receiptItems = $items->fetchAll();
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Receipt Verification - <?= htmlspecialchars($receiptNumber) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; }
        .verified { border: 2px solid #2E7D32; padding: 20px; border-radius: 10px; background: #E8F5E9; }
        .voided { border: 2px solid #C62828; padding: 20px; border-radius: 10px; background: #FFEBEE; }
        h1 { margin: 0 0 15px; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-valid { background: #C8E6C9; color: #1B5E20; }
        .badge-invalid { background: #FFCDD2; color: #B71C1C; }
    </style>
    </head>
    <body>
        <div class="<?= $receipt['is_voided'] ? 'voided' : 'verified' ?>">
            <h1><?= $receipt['is_voided'] ? '⚠ Receipt VOIDED' : '✓ Receipt Verified' ?></h1>
            <p><strong>Receipt #:</strong> <?= htmlspecialchars($receipt['receipt_number']) ?></p>
            <p><strong>Date:</strong> <?= formatDateTime($receipt['created_at']) ?></p>
            <p><strong>Status:</strong> <span class="badge <?= $receipt['is_voided'] ? 'badge-invalid' : 'badge-valid' ?>"><?= $receipt['is_voided'] ? 'VOIDED' : 'VALID' ?></span></p>
            <?php if ($receipt['is_voided']): ?>
            <p><strong>Void Reason:</strong> <?= htmlspecialchars($receipt['void_reason'] ?? 'N/A') ?></p>
            <?php endif; ?>
            <p><strong>Total Amount:</strong> <?= formatCurrency($receipt['total_amount']) ?></p>
            <p><strong>Payment:</strong> <?= paymentMethodLabel($receipt['payment_method']) ?></p>
            <p><strong>Staff:</strong> <?= htmlspecialchars($receipt['staff_name'] ?? 'N/A') ?></p>

            <h3>Items</h3>
            <table>
                <tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr>
                <?php foreach ($receiptItems as $item): ?>
                <tr><td><?= htmlspecialchars($item['size_label']) ?></td><td><?= $item['quantity'] ?></td><td><?= formatCurrency($item['unit_price']) ?></td><td><?= formatCurrency($item['subtotal']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p style="font-size:12px;color:#888;">Verified via EggLogan Egg Storage System</p>
        </div>
    </body>
    </html>
    <?php
} elseif ($type === 'tray') {
    $trayId = $id;
    $stmt = $pdo->prepare("SELECT t.*, b.batch_number, s.size_label FROM egg_trays t JOIN batches b ON t.batch_id = b.id JOIN egg_sizes s ON t.egg_size = s.size_code WHERE t.tray_id = ?");
    $stmt->execute([$trayId]);
    $tray = $stmt->fetch();

    if (!$tray) {
        die('<h1>Tray Not Found</h1><p>This tray ID does not exist in the system.</p>');
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Tray Verification - EggLogan</title><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 40px auto; padding: 20px; }
        .card { border: 1px solid #ddd; padding: 20px; border-radius: 10px; }
        h1 { margin: 0 0 15px; font-size: 22px; color: #1976D2; }
        .info { margin: 10px 0; }
        .label { font-weight: bold; color: #555; }
        .valid { background: #E8F5E9; border: 2px solid #2E7D32; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; background: #C8E6C9; color: #1B5E20; }
    </style>
    </head>
    <body>
        <div class="card valid">
            <h1>✓ Tray Verified</h1>
            <div class="info"><span class="label">Tray ID:</span> <?= htmlspecialchars($tray['tray_id']) ?></div>
            <div class="info"><span class="label">Size:</span> <?= htmlspecialchars($tray['size_label']) ?></div>
            <div class="info"><span class="label">Batch:</span> <?= htmlspecialchars($tray['batch_number']) ?></div>
            <div class="info"><span class="label">Status:</span> <span class="status-badge"><?= ucfirst($tray['status']) ?></span></div>
            <div class="info"><span class="label">Production:</span> <?= formatDate($tray['production_date']) ?></div>
            <div class="info"><span class="label">Expiry:</span> <?= formatDate($tray['expiry_date']) ?></div>
            <div class="info"><span class="label">Quantity:</span> <?= $tray['quantity'] ?> eggs</div>
        </div>
    </body>
    </html>
    <?php
} else {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>Not Found</h1>';
}
