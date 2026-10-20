<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AlertManager.php';
require_once __DIR__ . '/../classes/TrayManager.php';
require_once __DIR__ . '/../classes/ModelEvaluator.php';

requireAuth();

if (isset($_GET['ajax']) && $_GET['ajax'] === 'alerts' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $am = new AlertManager();
    // One call reconciles every derived alert, so the polling endpoint and the
    // page can never disagree about the badge count.
    $am->refresh();
    $alerts = $am->getActiveAlerts();
    $count = $am->getAlertCount();
    $expiredCount = $am->getExpiredCount();

    $sevIcon = ['high' => 'alert-triangle', 'medium' => 'clock', 'low' => 'check-circle'];
    $html = '';
    $links = [];
    if (empty($alerts)) {
        $html = '<div class="empty-state"><strong>No data yet</strong><p>No active alerts</p></div>';
    } else {
        foreach ($alerts as $alert) {
            $icon = $sevIcon[$alert['severity']] ?? 'bell';
            $link = $am->getAlertLink($alert);
            if ($link) $links[$alert['id']] = $link;
            $html .= '<div class="alert-item alert-' . $alert['severity'] . ($link ? ' alert-clickable' : '') . '" data-alert-id="' . $alert['id'] . '"'
                   . ($link ? ' data-href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '') . '>';
            $html .= '<span class="alert-icon">' . e_icon($icon, 16) . '</span>';
            $html .= '<span class="alert-message">' . htmlspecialchars($alert['message']) . '</span>';
            if ($link) $html .= '<span class="alert-go">' . e_icon('chevron-right', 14) . '</span>';
            $html .= '<button onclick="event.stopPropagation(); dismissAlert(' . $alert['id'] . ')" class="btn-small">Dismiss</button>';
            $html .= '</div>';
        }
    }

    echo json_encode(['success' => true, 'count' => $count, 'expired_count' => $expiredCount, 'html' => $html, 'links' => $links]);
    exit;
}

$isAdmin = isAdmin();
$role = getCurrentUserRole();
$userName = getCurrentUserName();

$db = Database::getInstance();

$alertManager = new AlertManager();
$trayManager = new TrayManager();
$evaluator = new ModelEvaluator();

$totalTrays = ($db->fetchOne("SELECT COUNT(*) as count FROM egg_trays WHERE status = 'in_stock'") ?? [])['count'] ?? 0;
$totalEggs = ($db->fetchOne("SELECT SUM(quantity) as total FROM egg_trays WHERE status = 'in_stock'") ?? [])['total'] ?? 0;
$expiredCount = $alertManager->getExpiredCount();
$expiringCount = ($db->fetchOne("SELECT COUNT(*) as count FROM egg_trays WHERE status = 'in_stock' AND DATEDIFF(expiry_date, NOW()) BETWEEN 1 AND " . max(1, (int)($db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'alert_expiry_days'")['setting_value'] ?? 3))) ?? [])['count'] ?? 0;
$todaySales = ($db->fetchOne("SELECT COALESCE(SUM(total_amount), 0) as total FROM receipts WHERE DATE(created_at) = CURDATE() AND is_voided = 0") ?? [])['total'] ?? 0;
$salesCount = ($db->fetchOne("SELECT COUNT(*) as count FROM receipts WHERE DATE(created_at) = CURDATE() AND is_voided = 0") ?? [])['count'] ?? 0;

// Reconcile alerts on page load
$alertManager->refresh();
$alerts = $alertManager->getActiveAlerts();
$inventory = $trayManager->getCurrentInventory();
$modelMetrics = $evaluator->evaluateAllMetrics();
// Must come from the evaluator, not a local calculation: this is the same number
// the Predictions page shows, and re-deriving it here is what let the two drift
// apart (this used to average accuracy while the label claimed F1, and it counted
// only sizes with 5+ samples instead of any size with data).
$accuracyGauge = $evaluator->getOverallAccuracyGauge();
// Per-size rows use the same "> 0 samples" rule as the average above, so the
// listed sizes are exactly the ones that fed the headline number.
$sizedMetrics = array_filter($modelMetrics, fn($m) => ($m['sample_count'] ?? 0) > 0);

$recentPredictions = $db->fetchAll("SELECT k.*, s.size_label FROM knn_predictions k JOIN egg_sizes s ON k.egg_size = s.size_code WHERE k.prediction_date = CURDATE() ORDER BY k.predicted_trays DESC");
$recentSales = $db->fetchAll("SELECT r.*, s.full_name as staff_name FROM receipts r LEFT JOIN staff_users s ON r.staff_id = s.id WHERE DATE(r.created_at) = CURDATE() ORDER BY r.created_at DESC LIMIT 10");
$weeklySales = $db->fetchAll("SELECT sale_date, receipt_count, total_sales FROM vw_daily_sales_summary WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY sale_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#E67E22">
    <link rel="icon" type="image/png" href="../assets/images/favicon-icon.png">
</head>
<body class="app-shell">
    <div class="app-container">
        <nav class="sidebar" id="sidebar"><?php include 'sidebar.php'; ?></nav>
        <main class="main-content">
            <header class="top-bar">
                <?php include __DIR__ . '/../includes/utility-controls.php'; ?>
                <div class="top-bar-actions"><?php include __DIR__ . '/../includes/header-actions.php'; ?></div>
            </header>

            <div class="page-heading">
                <span class="page-eyebrow">Overview</span>
                <h1>Dashboard</h1>
                <p>Monitor inventory, sales, and daily operations.</p>
            </div>

<div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-ico ico-primary"><?= e_icon('tray', 22) ?></span>
                    <div class="stat-label">Total Trays</div>
                    <div class="stat-value"><?= number_format($totalTrays) ?></div>
                </div>
                <div class="stat-card">
                    <span class="stat-ico ico-egg"><?= e_icon('egg', 22) ?></span>
                    <div class="stat-label">Total Eggs</div>
                    <div class="stat-value"><?= number_format($totalEggs) ?></div>
                </div>
                <div class="stat-card stat-warning">
                    <span class="stat-ico ico-warning"><?= e_icon('clock', 22) ?></span>
                    <div class="stat-label">Expiring / Expired</div>
                    <div class="stat-value"><?= $expiredCount + $expiringCount ?></div>
                </div>
                <div class="stat-card stat-success">
                    <span class="stat-ico ico-success"><?= e_icon('banknote', 22) ?></span>
                    <div class="stat-label">Today's Sales (<?= $salesCount ?>)</div>
                    <div class="stat-value"><?= formatCurrency($todaySales) ?></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Current Inventory</h3>
                        <a href="inventory.php" class="btn-small">View All</a>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Size</th>
                                    <th>Trays</th>
                                    <th>Eggs</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($inventory)): ?>
                            <tr><td colspan="4"><div class="empty-state"><strong>No data yet</strong><p>Add a batch to see inventory here.</p></div></td></tr>
                            <?php endif; ?>
                                <?php foreach ($inventory as $item): ?>
                                <tr>
                                    <td><span class="size-badge" style="background:<?= getEggColorClass($item['size_code']) ?>"><?= $item['size_label'] ?></span></td>
                                    <td><?= $item['tray_count'] ?></td>
                                    <td><?= number_format($item['total_eggs']) ?></td>
                                    <td><span class="age-label <?= getAgeColorClass($item['oldest_batch'] ? (int)date_diff(new DateTime($item['oldest_batch']), new DateTime())->format('%a') : 0) ?>"><?= $item['oldest_batch'] ? formatDate($item['oldest_batch']) : 'N/A' ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Today's Predictions</h3>
                        <a href="predictions.php" class="btn-small">Details</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentPredictions)): ?>
                            <div class="empty-state"><strong>No data yet</strong><p>No predictions for today. Record sales data to enable predictions.</p></div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr><th>Size</th><th>Predicted</th><th>Sales</th><th>Range</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPredictions as $p): ?>
                                    <tr>
                                        <td><?= $p['size_label'] ?></td>
                                        <td><strong><?= round($p['predicted_trays']) ?></strong> trays</td>
                                        <td><?= formatCurrency($p['predicted_sales'] ?? 0) ?></td>
                                        <td><?= round($p['confidence_lower']) ?>-<?= round($p['confidence_upper']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <div class="accuracy-gauge">
                            <span>Balanced Score: </span>
                            <span class="gauge gauge-<?= $accuracyGauge['color'] ?>"><?= $accuracyGauge['score'] ?>%</span>
                        </div>
                        <?php if ($isAdmin): ?>
                        <?php if (!empty($sizedMetrics)): ?>
                        <table class="table" style="margin-top:10px;">
                            <thead><tr><th>Size</th><th>Hit Rate</th><th>No Over-Predict</th><th>No Under-Predict</th><th>Balanced Score</th></tr></thead>
                            <tbody>
                                <?php foreach ($sizedMetrics as $code => $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['egg_size']) ?></td>
                                    <td><span class="gauge gauge-<?= $m['accuracy_status'] ?>"><?= $m['accuracy'] ?>%</span></td>
                                    <td><span class="gauge gauge-<?= $m['precision_status'] ?>"><?= $m['precision'] ?>%</span></td>
                                    <td><span class="gauge gauge-<?= $m['recall_status'] ?>"><?= $m['recall'] ?>%</span></td>
                                    <td><span class="gauge gauge-<?= $m['f1_status'] ?>"><?= $m['f1_score'] ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state" style="padding:10px 0;"><strong>No data yet</strong><p>Model metrics will appear after a few days of predictions.</p></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($isAdmin && !empty($weeklySales)): ?>
            <div class="card">
                <div class="card-header"><h3>Last 7 Days Revenue (Admin)</h3></div>
                <div class="card-body">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Receipts</th><th>Sales</th><th></th></tr></thead>
                        <tbody>
                            <?php $maxSale = max(array_column($weeklySales, 'total_sales') ?: [1]); ?>
                            <?php foreach ($weeklySales as $d): ?>
                            <tr>
                                <td><?= date('M d', strtotime($d['sale_date'])) ?></td>
                                <td><?= (int)$d['receipt_count'] ?></td>
                                <td><?= formatCurrency($d['total_sales']) ?></td>
                                <td style="width:40%"><div style="background:var(--primary);height:10px;border-radius:5px;width:<?= $maxSale > 0 ? round(($d['total_sales'] / $maxSale) * 100) : 0 ?>%"></div></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Recent Transactions</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($recentSales)): ?>
                        <div class="empty-state"><strong>No data yet</strong><p>No transactions today</p></div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr><th>Receipt</th><th>Amount</th><th>Payment</th><th>Staff</th><th>Time</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSales as $sale): ?>
                                <tr class="<?= $sale['is_voided'] ? 'voided-row' : '' ?>">
                                    <td><?= $sale['receipt_number'] ?></td>
                                    <td><?= formatCurrency($sale['total_amount']) ?></td>
                                    <td><?= paymentMethodLabel($sale['payment_method']) ?></td>
                                    <td><?= htmlspecialchars($sale['staff_name'] ?? 'N/A') ?></td>
                                    <td><?= date('h:i A', strtotime($sale['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="quick-action-grid">
                    <a href="scan.php" class="quick-action-card"><span class="stat-ico ico-primary"><?= e_icon('scan', 24) ?></span><strong>Scan QR</strong><span>Look up trays using a reusable variant code.</span><?= e_icon('chevron-right', 18) ?></a>
                    <a href="sale.php" class="quick-action-card"><span class="stat-ico ico-success"><?= e_icon('cart', 24) ?></span><strong>Quick Sale</strong><span>Create a sale and issue a receipt.</span><?= e_icon('chevron-right', 18) ?></a>
                    <a href="picklist.php" class="quick-action-card"><span class="stat-ico ico-warning"><?= e_icon('clipboard', 24) ?></span><strong>Pick List</strong><span>Find the oldest stock to sell first.</span><?= e_icon('chevron-right', 18) ?></a>
                    <a href="inventory.php" class="quick-action-card"><span class="stat-ico ico-egg"><?= e_icon('tray', 24) ?></span><strong>Manage Stock</strong><span>Receive batches and print permanent QR labels.</span><?= e_icon('chevron-right', 18) ?></a>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>

    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
