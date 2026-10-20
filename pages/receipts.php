<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ReceiptManager.php';

requireAuth();

$db = Database::getInstance();
$receiptManager = new ReceiptManager();

$page = max(1, (int)($_GET['page'] ?? 1));
$filters = [];
if (!empty($_GET['from'])) $filters['date_from'] = $_GET['from'];
if (!empty($_GET['to'])) $filters['date_to'] = $_GET['to'];
if (isset($_GET['voided']) && in_array($_GET['voided'], ['0', '1'], true)) $filters['is_voided'] = (int)$_GET['voided'];

$receiptData = $receiptManager->getReceipts($page, 20, $filters);
$dailySummary = $receiptManager->getDailySummary(date('Y-m-d'));

// Pagination must carry the active filters, otherwise paging silently drops the
// date range and status the user just applied.
$pageLink = function ($p) use ($filters) {
    $q = array_filter([
        'from' => $filters['date_from'] ?? '',
        'to' => $filters['date_to'] ?? '',
        'voided' => isset($filters['is_voided']) ? (string)$filters['is_voided'] : '',
        'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
$hasFilters = !empty($filters['date_from']) || !empty($filters['date_to']) || isset($filters['is_voided']);
$receipts = $receiptData['receipts'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Receipts</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
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
                <span class="page-eyebrow">Sales records</span>
                <h1>Receipts</h1>
                <p>Find and review sales receipts.</p>
            </div>

            <div class="stats-grid rc-stats">
                <div class="stat-card">
                    <span class="stat-ico ico-success"><?= e_icon('banknote', 20) ?></span>
                    <div class="stat-label">Sales today<?= ($dailySummary['receipt_count'] ?? 0) > 0 ? ' from ' . ($dailySummary['receipt_count']) . ' ' . plural((int)($dailySummary['receipt_count']), 'receipt', 'receipts') : '' ?></div>
                    <div class="stat-value"><?= formatCurrency($dailySummary['total_sales'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <span class="stat-ico ico-primary"><?= e_icon('receipt', 20) ?></span>
                    <div class="stat-label"><?= $hasFilters ? 'Matching this filter' : 'Receipts on record' ?></div>
                    <div class="stat-value"><?= number_format($receiptData['pagination']['total'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <span class="stat-ico ico-warning"><?= e_icon('users', 20) ?></span>
                    <div class="stat-label">Staff selling today</div>
                    <div class="stat-value"><?= number_format($dailySummary['active_staff'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <span class="stat-ico ico-primary"><?= e_icon('cart', 20) ?></span>
                    <div class="stat-label">Average sale today</div>
                    <div class="stat-value"><?= formatCurrency((float)($dailySummary['avg_sale'] ?? 0)) ?></div>
                </div>
            </div>

            <div class="rc-filters">
                <div class="filter-controls">
                    <form method="GET" class="form-inline" data-auto-filter>
                        <div class="form-group">
                            <label>From</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-group">
                            <label>To</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="voided">
                                <option value="">All</option>
                                <option value="0" <?= ($_GET['voided'] ?? '') === '0' ? 'selected' : '' ?>>Active</option>
                                <option value="1" <?= ($_GET['voided'] ?? '') === '1' ? 'selected' : '' ?>>Voided</option>
                            </select>
                        </div>
                        <?php if ($hasFilters): ?>
                        <a href="receipts.php" class="btn btn-secondary">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><?= $hasFilters ? 'Filtered Receipts' : 'All Receipts' ?></h3>
                    <span class="card-hint"><?= count($receipts) ?> shown<?= ($receiptData['pagination']['total_pages'] ?? 1) > 1 ? ' of ' . number_format($receiptData['pagination']['total']) : '' ?></span>
                </div>
                <div class="card-body">
                    <div class="table-wrap">
                    <table class="table rc-table">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Date</th>
                                <th class="rc-num">Amount</th>
                                <th>Payment</th>
                                <th>Staff</th>
                                <th class="rc-status">Status</th>
                                <th class="rc-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($receipts)): ?>
                            <tr>
                                <td colspan="7"><div class="empty-state"><strong>No data yet</strong><p>
                                    <?= $hasFilters ? 'No receipts match this filter.' : 'Completed sales receipts will appear here.' ?>
                                </p></div></td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($receipts as $r): ?>
                            <tr class="<?= $r['is_voided'] ? 'voided-row' : '' ?>">
                                <td class="rc-num-id"><?= $r['receipt_number'] ?></td>
                                <td class="rc-nowrap"><?= formatDateTime($r['created_at']) ?></td>
                                <td class="rc-num"><?= formatCurrency($r['total_amount']) ?></td>
                                <td class="rc-nowrap"><?= paymentMethodLabel($r['payment_method']) ?></td>
                                <td class="rc-nowrap"><?= htmlspecialchars($r['staff_name'] ?? 'N/A') ?></td>
                                <td class="rc-status"><?= $r['is_voided'] ? '<span class="badge badge-danger">VOIDED</span>' : '<span class="badge badge-success">Active</span>' ?></td>
                                <td class="rc-actions">
                                    <?php
                                    // Both slots are always rendered, at the same width, so
                                    // the action column keeps one geometry on every row. A
                                    // missing button used to narrow the cell and leave the
                                    // remaining button hanging off the alignment.
                                    $canVoid = isAdmin() && !$r['is_voided'];
                                    ?>
                                    <span class="rc-actions-group">
                                        <a href="../api/reports/print_receipt.php?receipt=<?= urlencode($r['receipt_number']) ?>"
                                           target="_blank" rel="noopener" class="btn-small icon-action" aria-label="Open receipt" title="Open receipt"><?= e_icon('receipt', 18) ?></a>
                                        <?php if ($canVoid): ?>
                                        <button onclick="voidReceipt('<?= $r['receipt_number'] ?>')" class="btn-small btn-danger icon-action" aria-label="Void receipt" title="Void receipt"><?= e_icon('close', 18) ?></button>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($receiptData['pagination']['total_pages'] > 1): ?>
                    <div class="pagination rc-pagination">
                        <?php if ($receiptData['pagination']['has_prev']): ?>
                        <a href="<?= $pageLink($receiptData['pagination']['prev']) ?>" class="btn-small btn-prev"><?= e_icon('chevron-left', 16) ?> Previous</a>
                        <?php else: ?>
                        <span class="btn-small btn-prev" aria-disabled="true"><?= e_icon('chevron-left', 16) ?> Previous</span>
                        <?php endif; ?>
                        <span class="page-info">Page <?= $receiptData['pagination']['current'] ?> of <?= $receiptData['pagination']['total_pages'] ?></span>
                        <?php if ($receiptData['pagination']['has_next']): ?>
                        <a href="<?= $pageLink($receiptData['pagination']['next']) ?>" class="btn-small btn-next">Next <?= e_icon('chevron-right', 16) ?></a>
                        <?php else: ?>
                        <span class="btn-small btn-next" aria-disabled="true">Next <?= e_icon('chevron-right', 16) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    async function voidReceipt(receiptNumber) {
        var reason = await requestInput({ title: 'Void receipt ' + receiptNumber, label: 'Reason for voiding this receipt', confirmLabel: 'Continue' });
        if (!reason) return;
        if (!await confirmAction('Void ' + receiptNumber + '?\n\nThe eggs go back into stock and the sale is removed from reports. This cannot be undone.')) return;

        fetch('../api/sales/void_receipt.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ receipt_number: receiptNumber, reason: reason })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                showToast('Could not void receipt', 'error', { message: d.error || 'Unknown error', duration: 8000 });
                return;
            }
            showToast('Receipt voided', 'success', {
                message: receiptNumber + ' \u00B7 stock restored \u00B7 removed from reports'
            });
            setTimeout(function() { location.reload(); }, 1100);
        })
        .catch(function() {
            showToast('Network error', 'error', { message: 'Nothing was voided. Check your connection and try again.', duration: 8000 });
        });
    }
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
