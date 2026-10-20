<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/LabelManager.php';

requireAuth();

$batchId = (int)($_GET['batch_id'] ?? 0);
$trayId = trim($_GET['tray_id'] ?? '');
$qtyParam = (int)($_GET['qty'] ?? 0);
$type = $_GET['type'] ?? 'label';

$labelManager = new LabelManager();

if ($trayId !== '') {
    $tray = $labelManager->getSingleLabel($trayId);
    if (!$tray) {
        die('Tray not found');
    }
    $copies = max(1, min(50, $qtyParam > 0 ? $qtyParam : 1));
    $trays = array_fill(0, $copies, $tray);
} elseif ($batchId > 0) {
    $trays = $labelManager->getBatchLabels($batchId);
    if (empty($trays)) {
        die('No trays in this batch');
    }
} else {
    die('Provide batch_id or tray_id');
}

if ($type === 'numbers') {
    echo $labelManager->batchNumberStickersHtml($trays);
} else {
    echo $labelManager->batchLabelHtml($trays);
}
?>
<script>
window.onload = function() { window.print(); };
</script>
