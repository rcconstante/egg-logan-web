<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/ReceiptManager.php';
require_once __DIR__ . '/../../includes/qr_generator.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();

$receiptNumber = $_GET['receipt'] ?? '';
if (empty($receiptNumber)) {
    die('No receipt number provided');
}

$receiptManager = new ReceiptManager();
$receipt = $receiptManager->getReceipt($receiptNumber);
if (!$receipt) {
    die('Receipt not found');
}

// "Printed" must mean a copy actually came out of the printer, so the flag is
// set by mark_receipt_printed.php when the user clicks Print -- not when this tab
// is merely opened. Marking on load made the first real print show up as a
// DUPLICATE COPY, because opening the tab had already consumed the flag.
$wasPrinted = !empty($receipt['printed_at']);
$isDuplicate = $wasPrinted;

$db = Database::getInstance();
$businessName = 'EggLogan Egg Storage';
$businessAddress = 'Philippines';
$footerText = 'Thank you for your patronage!';
foreach ($db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('business_name','business_address','receipt_footer')") as $s) {
    if ($s['setting_key'] === 'business_name') $businessName = $s['setting_value'] ?: $businessName;
    if ($s['setting_key'] === 'business_address') $businessAddress = $s['setting_value'] ?: $businessAddress;
    if ($s['setting_key'] === 'receipt_footer') $footerText = $s['setting_value'] ?: $footerText;
}

$qrGenerator = new QRGenerator();
$verifyQr = $qrGenerator->receiptDataUri($receiptNumber);
$verificationUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/Egglogan/verify/' . rawurlencode($receiptNumber);

$fifoCompliant = (int)($receipt['is_fifo_compliant'] ?? 1) === 1;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?= $receiptNumber ?></title>
    <style>
        body { font-family: 'Courier New', monospace; font-size: 12px; width: 80mm; margin: 0 auto; padding: 10px; }
        .receipt { text-align: center; }
        .receipt h2 { margin: 5px 0; }
        .receipt hr { border-top: 1px dashed #000; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .total-row { font-weight: bold; font-size: 14px; }
        .footer { margin-top: 10px; font-size: 10px; }
        .voided { color: red; font-weight: bold; font-size: 18px; border: 2px solid red; padding: 5px; margin: 10px 0; }
        .rcpt-void { text-decoration: line-through; opacity: 0.6; }
        .duplicate { border: 2px solid #000; padding: 3px; font-weight: bold; letter-spacing: 2px; margin: 5px 0; }
        .tray-ids { font-size: 9px; color: #333; }
        .fifo-ok { color: green; font-weight: bold; }
        .fifo-bad { color: red; font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="receipt <?= $receipt['is_voided'] ? 'rcpt-void' : '' ?>">
        <?php if ($isDuplicate): ?>
        <div class="duplicate">DUPLICATE COPY</div>
        <?php endif; ?>

        <h2><?= $receipt['is_voided'] ? 'VOID' : htmlspecialchars($businessName) ?></h2>
        <p><?= htmlspecialchars($businessAddress) ?></p>
        <hr>

        <?php if ($receipt['is_voided']): ?>
        <div class="voided">*** VOIDED ***</div>
        <p>Reason: <?= htmlspecialchars($receipt['void_reason'] ?? 'N/A') ?></p>
        <hr>
        <?php endif; ?>

        <p><strong>Receipt #:</strong> <?= $receipt['receipt_number'] ?></p>
        <p><strong>Date:</strong> <?= date('M d, Y h:i A', strtotime($receipt['created_at'])) ?></p>
        <p><strong>Staff:</strong> <?= htmlspecialchars($receipt['staff_name'] ?? 'N/A') ?></p>
        <p><strong>Payment:</strong> <?= paymentMethodLabel($receipt['payment_method']) ?></p>
        <hr>

        <table>
            <tr><td class="text-left"><strong>Item</strong></td><td class="text-right"><strong>Qty</strong></td><td class="text-right"><strong>Price</strong></td><td class="text-right"><strong>Total</strong></td></tr>
            <tr><td colspan="4"><hr></td></tr>
            <?php foreach ($receipt['items'] as $item): ?>
            <tr>
                <td class="text-left"><?= $item['size_label'] ?></td>
                <td class="text-right"><?= $item['quantity'] ?></td>
                <td class="text-right">&#8369;<?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($item['subtotal'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <hr>

        <p class="total-row">TOTAL: &#8369;<?= number_format($receipt['total_amount'], 2) ?></p>
        <?php if ($receipt['amount_tendered'] !== null && $receipt['amount_tendered'] !== ''): ?>
        <p>Tendered: &#8369;<?= number_format((float)$receipt['amount_tendered'], 2) ?></p>
        <p>Change: &#8369;<?= number_format((float)($receipt['change_amount'] ?? 0), 2) ?></p>
        <?php endif; ?>
        <?php if (isset($receipt['customer_info']['type'])): ?>
        <p>Customer: <?= ucfirst($receipt['customer_info']['type']) ?></p>
        <?php endif; ?>
        <p>FIFO Compliance:
            <?php if ($fifoCompliant): ?>
            <span class="fifo-ok">&#10003; COMPLIANT</span>
            <?php else: ?>
            <span class="fifo-bad">&#9888; OVERRIDE APPROVED</span>
            <?php endif; ?>
        </p>
        <hr>

        <div class="tray-ids">
            Trays sold:
            <?php
            $trayIds = array_values(array_unique(array_filter(array_column($receipt['items'], 'tray_id'))));
            echo $trayIds ? htmlspecialchars(implode(', ', array_map(fn($t) => substr($t, 0, 8), $trayIds))) : 'N/A';
            ?>
        </div>
        <hr>

        <?php if ($verifyQr): ?>
        <img src="<?= $verifyQr ?>" style="width:110px;height:110px;" alt="Verification QR">
        <div class="footer">
            <p>Scan to verify this receipt</p>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p><?= htmlspecialchars($footerText) ?></p>
            <p><?= $receipt['receipt_number'] ?> | <?= date('Y-m-d') ?></p>
            <p>EggLogan v1.0</p>
        </div>
    </div>

    <div class="no-print" style="text-align:center;margin-top:10px;">
        <button id="printBtn" onclick="doPrint()" style="padding:6px 14px;">Print</button>
        <button onclick="shareReceipt()" style="padding:6px 14px;">Share</button>
        <button onclick="emailReceipt()" style="padding:6px 14px;">Email</button>
        <p style="font-size:10px;color:#666;margin-top:8px;">
            <?= $wasPrinted ? 'This receipt has been printed before \u2014 this copy is marked DUPLICATE.' : 'Printing for the first time.' ?>
        </p>
    </div>

    <script>
    // Opening this tab does not print. The Print button above is the only thing
    // that triggers a copy, so simply looking at a receipt no longer marks it
    // printed (and the next print is no longer wrongly labelled DUPLICATE).
    var markedPrinted = <?= $wasPrinted ? 'true' : 'false' ?>;
    function doPrint() {
        var btn = document.getElementById('printBtn');
        btn.disabled = true;
        var go = function() { window.print(); };
        if (markedPrinted) { go(); return; }
        markedPrinted = true;
        fetch('mark_receipt_printed.php?receipt=' + encodeURIComponent(<?= json_encode($receiptNumber) ?>), { method: 'POST', headers: { 'X-CSRF-Token': <?= json_encode(csrfToken()) ?> } })
            .catch(function() {})
            .then(go);
    }
    var verifyUrl = <?= json_encode($verificationUrl) ?>;
    function shareReceipt() {
        if (navigator.share) {
            navigator.share({ title: 'Receipt <?= $receiptNumber ?>', text: 'EggLogan receipt <?= $receiptNumber ?>', url: verifyUrl }).catch(function() {});
        } else {
            prompt('Copy this receipt link:', verifyUrl);
        }
    }
    function emailReceipt() {
        var mailto = 'mailto:?subject=' + encodeURIComponent('EggLogan Receipt <?= $receiptNumber ?>') +
                     '&body=' + encodeURIComponent('Your receipt <?= $receiptNumber ?> (total ₱<?= number_format((float)$receipt["total_amount"], 2) ?>). Verify it here: ' + verifyUrl);
        window.location.href = mailto;
    }
    </script>
</body>
</html>
