<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/FIFOManager.php';

requireAuth();

$fifoManager = new FIFOManager();
$db = Database::getInstance();

$sizeFilter = $_GET['size'] ?? null;
$categoryFilter = $_GET['category'] ?? null;
$highlightTray = $_GET['tray'] ?? null;

$pickList = $fifoManager->getPickList();
$eggSizes = $db->fetchAll("SELECT * FROM egg_sizes ORDER BY size_code");

if ($highlightTray) {
    foreach ($pickList['all'] as $t) {
        if ($t['tray_id'] === $highlightTray) {
            $sizeFilter = $sizeFilter ?: $t['egg_size'];
            break;
        }
    }
}

// Filter by category if specified
if ($categoryFilter && isset($pickList[$categoryFilter])) {
    $filteredTrays = $pickList[$categoryFilter];
    if ($sizeFilter) {
        $filteredTrays = array_filter($filteredTrays, fn($t) => $t['egg_size'] === $sizeFilter);
    }
    $pickList = ['all' => array_values($filteredTrays)];
} elseif ($sizeFilter) {
    $pickList = ['all' => array_filter($pickList['all'], fn($t) => $t['egg_size'] === $sizeFilter)];
    $pickList['all'] = array_values($pickList['all']);
}

function getUrgencyClass($tray) {
    if ($tray['days_until_expiry'] < 0) return 'urgency-expired';
    if ($tray['days_until_expiry'] <= 3) return 'urgency-high';
    if ($tray['days_until_expiry'] <= 7) return 'urgency-medium';
    if ($tray['days_in_storage'] >= 14) return 'urgency-low';
    return '';
}

function getUrgencyLabel($tray) {
    if ($tray['days_until_expiry'] < 0) return 'EXPIRED';
    if ($tray['days_until_expiry'] <= 3) return 'SELL NOW';
    if ($tray['days_until_expiry'] <= 7) return 'USE SOON';
    if ($tray['days_in_storage'] >= 14) return 'AGING';
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - FIFO Pick List</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
    <style>
    .pick-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .pick-filters select { padding: 6px 12px; border-radius: 6px; border: 1px solid #ddd; font-size: 13px; }
    .pick-table { width: 100%; border-collapse: collapse; }
    .pick-table th { background: #f8f9fa; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #666; border-bottom: 2px solid #ddd; }
    .pick-table td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
    .pick-row { transition: background 0.15s; }
    .pick-row:hover { background: #f5f5f5; }
    
    .urgency-critical { background: #FDEAEA; border-left: 4px solid #E74C3C; }
    .urgency-expired { background: #F5F5F5; border-left: 4px solid #999; }
    .urgency-high { background: #FEF3E2; border-left: 4px solid #E67E22; }
    .urgency-medium { background: #FEF9E7; border-left: 4px solid #F1C40F; }
    .urgency-low { background: #EAFAF1; border-left: 4px solid #27AE60; }
    
    .urgency-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .tag-critical { background: #E74C3C; color: #fff; }
    .tag-expired { background: #999; color: #fff; }
    .tag-high { background: #E67E22; color: #fff; }
    .tag-medium { background: #F1C40F; color: #333; }
    .tag-low { background: #27AE60; color: #fff; }
    
    .tray-id { font-family: monospace; font-size: 12px; color: #555; }
    .row-highlight { animation: row-flash 2.2s ease-out; }
    @keyframes row-flash {
        0%, 40% { box-shadow: inset 0 0 0 2px var(--primary, #E67E22); background: #FEF3E2; }
        100% { box-shadow: none; }
    }
    tr:target { box-shadow: inset 0 0 0 2px var(--primary, #E67E22); }
    .batch-num { font-weight: 600; }
    .pick-number { font-size: 18px; font-weight: 800; color: #333; text-align: center; width: 30px; }
    
    .pick-legend { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
    .legend-item { display: flex; align-items: center; gap: 5px; font-size: 12px; }
    .legend-color { width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,0.1); }
    
    @media print {
        .pick-filters, .no-print { display: none !important; }
        .pick-table th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .pick-row { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    </style>
    <link rel="icon" type="image/png" href="../assets/images/favicon-icon.png">
</head>
<body class="app-shell">
    <div class="app-container">
        <nav class="sidebar" id="sidebar"><?php include 'sidebar.php'; ?></nav>
        <main class="main-content">
            <header class="top-bar">
                <?php include __DIR__ . '/../includes/utility-controls.php'; ?>
                <div class="top-bar-actions no-print"><?php include __DIR__ . '/../includes/header-actions.php'; ?></div>
            </header>

            <div class="page-heading">
                <span class="page-eyebrow">Stock rotation</span>
                <h1>FIFO Pick List</h1>
                <p>Review the order in which trays should be sold.</p>
            </div>

            <div class="page-toolbar no-print" data-toolbar role="group" aria-label="Page actions">
                <div class="pick-filters">
                        <select onchange="applyFilter()" id="categoryFilter">
                            <option value="">All Categories</option>
                            <option value="fresh" <?= $categoryFilter === 'fresh' ? 'selected' : '' ?>>Fresh</option>
                            <option value="salted" <?= $categoryFilter === 'salted' ? 'selected' : '' ?>>Salted</option>
                        </select>
                        <select onchange="applyFilter()" id="sizeFilter">
                            <option value="">All Sizes</option>
                            <?php foreach ($eggSizes as $size): ?>
                            <option value="<?= $size['size_code'] ?>" <?= $sizeFilter === $size['size_code'] ? 'selected' : '' ?>><?= $size['size_label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
            </div>

            <div class="pick-legend no-print">
                <div class="legend-item"><div class="legend-color" style="background:#999;"></div> Expired</div>
                <div class="legend-item"><div class="legend-color" style="background:#E67E22;"></div> Sell Now (3d)</div>
                <div class="legend-item"><div class="legend-color" style="background:#F1C40F;"></div> Use Soon (7d)</div>
                <div class="legend-item"><div class="legend-color" style="background:#27AE60;"></div> OK</div>
            </div>

            <?php if (!empty($pickList['all'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Pick These Trays First</h3>
                    <span style="font-size:13px;color:#666;"><?= count($pickList['all']) ?> tray(s)</span>
                </div>
                <div class="card-body" style="padding:0;">
                    <table class="pick-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tray ID</th>
                                <th>Size</th>
                                <th>Batch</th>
                                <th>Tray #</th>
                                <th>Age</th>
                                <th>Expiry</th>
                                <th>Qty</th>
                                <th>Urgency</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $pickNum = 1; foreach ($pickList['all'] as $tray): ?>
                            <?php $urgency = getUrgencyClass($tray); $tag = getUrgencyLabel($tray); ?>
                            <tr class="pick-row <?= $urgency ?><?= $highlightTray === $tray['tray_id'] ? ' row-highlight' : '' ?>"
                                id="tray-<?= htmlspecialchars($tray['tray_id']) ?>">
                                <td class="pick-number"><?= $pickNum++ ?></td>
                                <td class="tray-id"><?= substr($tray['tray_id'], 0, 8) ?>...</td>
                                <td><span class="size-badge" style="background:<?= getEggColorClass($tray['egg_size']) ?>"><?= $tray['size_label'] ?></span></td>
                                <td class="batch-num"><?= $tray['batch_number'] ?></td>
                                <td><?= $tray['tray_sequence'] ?? '?' ?></td>
                                <td><span class="age-label age-<?= getAgeColorClass($tray['days_in_storage']) ?>"><?= $tray['days_in_storage'] ?>d</span></td>
                                <td><?= $tray['days_until_expiry'] >= 0 ? $tray['days_until_expiry'] . 'd' : '<span style="color:#c00;">Expired</span>' ?></td>
                                <td><?= $tray['quantity'] ?></td>
                                <td><?= $tag ? '<span class="urgency-tag tag-' . ($urgency === 'urgency-critical' ? 'critical' : ($urgency === 'urgency-expired' ? 'expired' : ($urgency === 'urgency-high' ? 'high' : ($urgency === 'urgency-medium' ? 'medium' : 'low')))) . '">' . $tag . '</span>' : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card"><div class="card-body"><div class="empty-state"><strong>No data yet</strong><p>No trays in inventory</p></div></div></div>
            <?php endif; ?>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    function applyFilter() {
        var cat = document.getElementById('categoryFilter').value;
        var size = document.getElementById('sizeFilter').value;
        var params = [];
        if (cat) params.push('category=' + cat);
        if (size) params.push('size=' + size);
        window.location.href = 'picklist.php' + (params.length ? '?' + params.join('&') : '');
    }

    <?php if ($highlightTray): ?>
    (function() {
        var row = document.getElementById('tray-<?= htmlspecialchars($highlightTray, ENT_QUOTES) ?>');
        if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    })();
    <?php endif; ?>
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
