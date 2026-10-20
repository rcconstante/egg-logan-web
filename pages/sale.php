<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ReceiptManager.php';
require_once __DIR__ . '/../classes/FIFOManager.php';

requireAuth();

$db = Database::getInstance();
$receiptManager = new ReceiptManager();
$fifoManager = new FIFOManager();

if (isset($_GET['ajax']) && $_GET['ajax'] === 'fifo' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: text/html');
    $filterCategory = $_GET['category'] ?? '';
    $filterSize = $_GET['size'] ?? '';
    $expanded = ($filterCategory || $filterSize);

    $pickList = $fifoManager->getPickList();
    $grouped = ['fresh' => [], 'salted' => []];
    foreach ($pickList['all'] as $t) {
        $cat = $t['category'] ?? 'fresh';
        $grouped[$cat][] = $t;
    }

    $categoryLabels = ['fresh' => 'Fresh', 'salted' => 'Salted'];
    $categoryOrder = ['fresh', 'salted'];
    $firstPriority = true;

    foreach ($categoryOrder as $cat):
        $trays = $grouped[$cat];
        if (empty($trays)) continue;
        if ($filterCategory && $cat !== $filterCategory) continue;
        if ($filterSize) $trays = array_filter($trays, fn($t) => $t['egg_size'] === $filterSize);
        if (empty($trays)) continue;

        $expiredTrays = array_filter($trays, fn($t) => (int)$t['days_until_expiry'] < 0);
        $expiredCount = count($expiredTrays);
        $expiredEggs = array_sum(array_map(fn($t) => (int)$t['quantity'], $expiredTrays));

        $bySize = [];
        foreach ($trays as $t) {
            $sc = $t['egg_size'];
            if (!isset($bySize[$sc])) $bySize[$sc] = ['label' => $t['size_label'], 'count' => 0, 'total_eggs' => 0, 'oldest_days' => 999, 'has_urgent' => false, 'expired_count' => 0, 'expired_eggs' => 0];
            $bySize[$sc]['count']++;
            $bySize[$sc]['total_eggs'] += (int)$t['quantity'];
            $days = (int)$t['days_in_storage'];
            if ($days < $bySize[$sc]['oldest_days']) $bySize[$sc]['oldest_days'] = $days;
            if ($days > 21) $bySize[$sc]['has_urgent'] = true;
            if ((int)$t['days_until_expiry'] < 0) {
                $bySize[$sc]['expired_count']++;
                $bySize[$sc]['expired_eggs'] += (int)$t['quantity'];
            }
        }
    ?>
    <div class="fifo-category-group <?= $expanded ? 'fifo-expanded' : 'fifo-compact' ?>" data-category="<?= $cat ?>">
        <div class="fifo-category-header"><?= $categoryLabels[$cat] ?> <span class="fifo-category-count"><?= count($trays) ?> tray<?= count($trays) !== 1 ? 's' : '' ?></span><?php if ($expiredCount > 0): ?><span class="fifo-expired-badge"><?= $expiredCount ?> expired</span><?php endif; ?></div>
        <?php if ($expanded): ?>
            <?php if ($expiredCount > 0): ?>
            <div class="fifo-expired-alert">
                <div class="fifo-expired-alert-icon"><?= e_icon('alert-triangle', 20) ?></div>
                <div class="fifo-expired-alert-text">
                    <strong><?= $expiredCount ?> expired tray<?= $expiredCount !== 1 ? 's' : '' ?> (<?= $expiredEggs ?> eggs)</strong>
                    <span>These items have passed their expiry date and cannot be sold. Dispose them from Inventory.</span>
                </div>
                <a href="inventory.php#expiredSection" class="btn btn-danger btn-sm">Dispose Now</a>
            </div>
            <?php endif; ?>
            <?php foreach ($trays as $t):
                $days = (int)$t['days_in_storage'];
                $isExpired = (int)$t['days_until_expiry'] < 0;
                $level = $isExpired ? 'expired' : ($days > 21 ? 'urgent' : ($days >= 14 ? 'soon' : ''));
                $isRecommended = !$isExpired && $firstPriority;
                if (!$isExpired) $firstPriority = false;
            ?>
            <div class="fifo-tray-row <?= $isExpired ? 'fifo-tray-expired' : 'fifo-item-clickable' ?> <?= $isRecommended ? 'fifo-recommended ' : '' ?><?= $level === 'urgent' ? 'fifo-highlight-urgent' : ($level === 'soon' ? 'fifo-highlight-soon' : ($isExpired ? '' : 'fifo-highlight-ok')) ?>" data-category="<?= $cat ?>" data-size="<?= $t['egg_size'] ?>" data-tray-id="<?= htmlspecialchars($t['tray_id']) ?>" data-qty="<?= $t['quantity'] ?>" <?= $isExpired ? '' : 'onclick="selectTrayFromFifo(this)" title="Click to sell from this tray"' ?>>
                <?php if ($isRecommended): ?>
                <div class="fifo-recommended-banner"><?= e_icon('star', 18) ?> HIGHLY RECOMMENDED — SELL THIS FIRST</div>
                <?php endif; ?>
                <?php if ($isExpired): ?>
                <div class="fifo-expired-row-banner"><?= e_icon('alert-triangle', 20) ?> EXPIRED — CANNOT BE SOLD</div>
                <?php endif; ?>
                <div class="fifo-tray-row-main">
                    <span class="fifo-tray-size"><?= htmlspecialchars($t['size_label']) ?></span>
                    <span class="fifo-tray-id">B<?= htmlspecialchars($t['batch_number']) ?> #<?= $t['tray_sequence'] ?></span>
                    <span class="fifo-tray-qty"><?= $t['quantity'] ?> eggs</span>
                    <?php if ($isExpired): ?>
                    <span class="fifo-flag fifo-flag-expired"><?= abs((int)$t['days_until_expiry']) ?>d expired</span>
                    <?php elseif ($level === 'urgent'): ?>
                    <span class="age-label age-expired"><?= $days ?>d</span>
                    <span class="fifo-flag fifo-flag-urgent">SELL FIRST</span>
                    <?php elseif ($level === 'soon'): ?>
                    <span class="age-label age-soon"><?= $days ?>d</span>
                    <span class="fifo-flag fifo-flag-soon">use soon</span>
                    <?php else: ?>
                    <span class="age-label age-ok"><?= $days ?>d</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="fifo-compact-grid">
            <?php foreach ($bySize as $sc => $info): ?>
                <div class="fifo-compact-chip <?= $info['has_urgent'] ? 'chip-urgent' : '' ?> <?= $info['expired_count'] > 0 ? 'chip-expired' : '' ?>" onclick="<?= $info['expired_count'] > 0 && $info['count'] === $info['expired_count'] ? '' : "selectSizeFromFifo('{$sc}')" ?>" title="<?= $info['label'] ?>: <?= $info['count'] ?> tray<?= $info['count']!==1?'s':'' ?> (<?= $info['total_eggs'] ?> eggs)<?= $info['expired_count'] > 0 ? " — {$info['expired_count']} expired ({$info['expired_eggs']} eggs)" : '' ?><?= $info['expired_count'] > 0 && $info['count'] === $info['expired_count'] ? ' — ALL EXPIRED, dispose from Inventory' : '' ?>">
                    <span class="chip-size"><?= $sc ?></span>
                    <span class="chip-count"><?= $info['count'] ?>×</span>
                    <?php if ($info['expired_count'] > 0): ?><span class="chip-expired-count"><?= $info['expired_count'] ?>exp</span><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach;
    exit;
}

$eggSizes = $db->fetchAll("SELECT * FROM egg_sizes ORDER BY FIELD(category, 'fresh', 'salted'), size_code");

// Get available stock per size
$stockBySize = [];
$stockRows = $db->fetchAll(
    "SELECT egg_size, SUM(quantity) as total_qty, COUNT(*) as tray_count 
     FROM egg_trays WHERE status = 'in_stock' AND quantity > 0 AND expiry_date >= CURDATE() 
     GROUP BY egg_size"
);
foreach ($stockRows as $row) {
    $stockBySize[$row['egg_size']] = [
        'total_qty' => (int)$row['total_qty'],
        'tray_count' => (int)$row['tray_count']
    ];
}

// Top FIFO pick per size: what should be sold first right now
$fifoBySize = [];
foreach ($fifoManager->getPickList()['all'] as $t) {
    if (($t['expiry_date'] ?? '') < date('Y-m-d') || (int)$t['quantity'] <= 0) continue;
    $sc = $t['egg_size'];
    if (!isset($fifoBySize[$sc])) {
        $days = (int)$t['days_in_storage'];
        $fifoBySize[$sc] = [
            'days' => $days,
            'level' => $days > 21 ? 'urgent' : ($days >= 14 ? 'soon' : ''),
            'batch_number' => $t['batch_number'] ?? '',
            'tray_sequence' => $t['tray_sequence'] ?? '?',
            'tray_id' => $t['tray_id'] ?? '',
            'quantity' => $t['quantity'] ?? 0,
            'expiry_date' => $t['expiry_date'] ?? ''
        ];
    }
}

// Full tray lineup grouped by category
$fifoTraysByCategory = ['fresh' => [], 'salted' => []];
foreach ($fifoManager->getPickList()['all'] as $t) {
    $cat = $t['category'] ?? 'fresh';
    $fifoTraysByCategory[$cat][] = $t;
}
function fifoOptionLabel($sizeLabel, array $f) {
    if ($f['level'] === 'urgent') return "{$sizeLabel} \u{26A0} SELL FIRST — ({$f['days']}d old)";
    if ($f['level'] === 'soon')   return "{$sizeLabel} · use soon ({$f['days']}d)";
    return $sizeLabel;
}

$prefillTrays = [];
$prefillTrayId = trim($_GET['tray_id'] ?? '');
$prefillBatch = trim($_GET['batch'] ?? '');
$prefillTraysParam = trim($_GET['trays'] ?? '');
if ($prefillTrayId !== '') {
    $tray = $db->fetchOne(
        "SELECT t.tray_id, t.quantity, t.egg_size FROM egg_trays t WHERE t.tray_id = ? AND t.status = 'in_stock' AND t.quantity > 0 AND t.expiry_date >= CURDATE()",
        [$prefillTrayId]
    );
    if ($tray) $prefillTrays[] = $tray;
} elseif ($prefillTraysParam !== '') {
    $ids = array_filter(array_map('trim', explode(',', $prefillTraysParam)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $prefillTrays = $db->fetchAll(
            "SELECT tray_id, quantity, egg_size FROM egg_trays 
             WHERE tray_id IN ($placeholders) AND status = 'in_stock' AND quantity > 0",
            array_values($ids)
        );
    }
} elseif ($prefillBatch !== '') {
    $prefillTrays = $db->fetchAll(
        "SELECT t.tray_id, t.quantity, t.egg_size
         FROM egg_trays t JOIN batches b ON t.batch_id = b.id
         WHERE b.batch_number = ? AND t.status = 'in_stock' AND t.quantity > 0 AND t.expiry_date >= CURDATE()
         ORDER BY t.fifo_priority_score DESC",
        [$prefillBatch]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Quick Sale</title>
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
                <span class="page-eyebrow">Sales</span>
                <h1>Quick Sale</h1>
                <p>Create receipts and manage your current sale.</p>
            </div>

            <form id="saleForm" class="sale-workspace">
                <div class="sale-layout">
                <div class="sale-form card">
                    <div class="card-header"><h3>Sale Entry</h3></div>
                    <div class="card-body">
                            <section class="sale-items-panel" aria-label="Sale details">
                            <div class="sale-customer-fields">
                            <div class="form-group">
                                <label>Customer Type</label>
                                <select name="customer_type">
                                    <option value="retail">Retail</option>
                                    <option value="wholesale">Wholesale</option>
                                    <option value="contract">Contract</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Payment Method</label>
                                <select name="payment_method">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="credit">Credit</option>
                                </select>
                            </div>
                            </div>
                            <div class="sale-section-heading"><h4>Items</h4><span id="saleItemCount">1 item</span></div>
                            <div id="saleItems">
                                <?php foreach ($prefillTrays as $pt): ?>
                                <div class="sale-item-row" data-tray-id="<?= htmlspecialchars($pt['tray_id']) ?>" data-tray-qty="<?= (int)$pt['quantity'] ?>">
                                    <select name="egg_size[]" class="egg-size-select" aria-label="Egg size" required>
                                        <option value="">Select Size</option>
                                        <?php foreach ($eggSizes as $size): $f = $fifoBySize[$size['size_code']] ?? null; $stk = $stockBySize[$size['size_code']] ?? null; ?>
                                        <option value="<?= $size['size_code'] ?>" data-price="<?= $size['price_per_egg'] ?>" data-cap="<?= $size['tray_capacity'] ?>" <?= $f ? 'data-fifo-days="' . $f['days'] . '"' : '' ?> data-stock="<?= $stk ? $stk['total_qty'] : 0 ?>" data-trays="<?= $stk ? $stk['tray_count'] : 0 ?>" <?= $size['size_code'] === $pt['egg_size'] ? 'selected' : '' ?>><?= $f ? fifoOptionLabel($size['size_label'], $f) : $size['size_label'] ?> (₱<?= number_format($size['price_per_egg'], 2) ?>/pc)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="sale-quantity-field"><span>Quantity (eggs)</span><input type="number" name="quantity[]" class="qty-input" aria-label="Quantity in eggs" min="1" max="<?= (int)$pt['quantity'] ?>" value="<?= (int)$pt['quantity'] ?>" required></label>
                                    <span class="stock-hint"></span>
                                    <div class="tray-badge" style="display:none;"></div>
                                    <input type="hidden" name="tray_id[]" class="tray-id-input" value="<?= htmlspecialchars($pt['tray_id']) ?>">
                                    <span class="sale-item-subtotal">Item total <strong>₱0.00</strong></span>
                                    <button type="button" onclick="removeSaleItem(this)" class="btn-small btn-danger" aria-label="Remove sale item"><?= e_icon('close', 16) ?></button>
                                </div>
                                <?php endforeach; ?>
                                <div class="sale-item-row" data-sale-template="1">
                                    <select name="egg_size[]" class="egg-size-select" aria-label="Egg size" required>
                                        <option value="">Select Size</option>
                                        <?php foreach ($eggSizes as $size): $f = $fifoBySize[$size['size_code']] ?? null; $stk = $stockBySize[$size['size_code']] ?? null; ?>
                                        <option value="<?= $size['size_code'] ?>" data-price="<?= $size['price_per_egg'] ?>" data-cap="<?= $size['tray_capacity'] ?>" <?= $f ? 'data-fifo-days="' . $f['days'] . '"' : '' ?> data-stock="<?= $stk ? $stk['total_qty'] : 0 ?>" data-trays="<?= $stk ? $stk['tray_count'] : 0 ?>"><?= $f ? fifoOptionLabel($size['size_label'], $f) : $size['size_label'] ?> (₱<?= number_format($size['price_per_egg'], 2) ?>/pc)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="sale-quantity-field"><span>Quantity (eggs)</span><input type="number" name="quantity[]" class="qty-input" aria-label="Quantity in eggs" min="1" placeholder="Qty (eggs)" required></label>
                                    <span class="stock-hint"></span>
                                    <div class="tray-badge" style="display:none;"></div>
                                    <input type="hidden" name="tray_id[]" class="tray-id-input" value="">
                                    <span class="sale-item-subtotal">Item total <strong>₱0.00</strong></span>
                                    <button type="button" onclick="removeSaleItem(this)" class="btn-small btn-danger" aria-label="Remove sale item"><?= e_icon('close', 16) ?></button>
                                </div>
                            </div>
                            <button type="button" onclick="addSaleItem()" class="btn btn-secondary btn-sm"><?= e_icon('plus', 18) ?> Add Item</button>
                            </section>
                    </div>
                </div>
                            <section class="sale-payment-panel card" aria-labelledby="paymentSummaryTitle">
                            <div class="card-header"><h3 id="paymentSummaryTitle">Payment summary</h3></div>
                            <div class="card-body">
                            <div class="sale-total">
                                <span>Total amount</span>
                                <span id="saleTotalDisplay">₱0.00</span>
                            </div>
                            <div class="form-group">
                                <label for="amountTendered">Amount tendered</label>
                                <input type="number" name="amount_tendered" step="0.01" min="0" id="amountTendered" oninput="calculateChange()">
                            </div>
                            <div class="sale-change">
                                <span>Change</span>
                                <span id="changeDisplay">₱0.00</span>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-success btn-lg">Complete Sale</button>
                                <button type="button" onclick="clearSale()" class="btn btn-secondary">Clear</button>
                            </div>
                            </div>
                            </section>

                <div class="sale-tips card">
                    <div class="card-header"><h3>FIFO Pick List</h3></div>
                    <div class="card-body">
                        <?php if (array_sum(array_column($stockBySize, 'total_qty')) <= 0): ?>
                        <div class="alert alert-error" style="margin-bottom:14px;">
                            <strong>No stock available.</strong>
                            Every egg size shows zero available, so no sale can be completed.
                            Receive a batch from Inventory first.
                        </div>
                        <?php endif; ?>
                        <div id="fifoList">
                        <?php
                        $categoryLabels = ['fresh' => 'Fresh', 'salted' => 'Salted'];
                        $categoryOrder = ['fresh', 'salted'];
                        $firstPriority = true;
                        foreach ($categoryOrder as $cat):
                            $trays = $fifoTraysByCategory[$cat] ?? [];
                            if (empty($trays)) continue;

                            $bySize = [];
                            foreach ($trays as $t) {
                                $sc = $t['egg_size'];
                                if (!isset($bySize[$sc])) $bySize[$sc] = ['label' => $t['size_label'], 'count' => 0, 'total_eggs' => 0, 'oldest_days' => 999, 'has_urgent' => false];
                                $bySize[$sc]['count']++;
                                $bySize[$sc]['total_eggs'] += (int)$t['quantity'];
                                $days = (int)$t['days_in_storage'];
                                if ($days < $bySize[$sc]['oldest_days']) $bySize[$sc]['oldest_days'] = $days;
                                if ($days > 21) $bySize[$sc]['has_urgent'] = true;
                            }
                        ?>
                        <div class="fifo-category-group fifo-compact" data-category="<?= $cat ?>">
                            <div class="fifo-category-header"><?= $categoryLabels[$cat] ?> <span class="fifo-category-count"><?= count($trays) ?> tray<?= count($trays) !== 1 ? 's' : '' ?></span></div>
                            <div class="fifo-compact-grid">
                            <?php foreach ($bySize as $sc => $info): ?>
                                <div class="fifo-compact-chip <?= $info['has_urgent'] ? 'chip-urgent' : '' ?>" onclick="selectSizeFromFifo('<?= $sc ?>')" title="<?= $info['label'] ?>: <?= $info['count'] ?> tray<?= $info['count']!==1?'s':'' ?> (<?= $info['total_eggs'] ?> eggs) — click to sell">
                                    <span class="chip-size"><?= $sc ?></span>
                                    <span class="chip-count"><?= $info['count'] ?>×</span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php
                        $anyTrays = false;
                        foreach ($categoryOrder as $cat) {
                            if (!empty($fifoTraysByCategory[$cat])) { $anyTrays = true; break; }
                        }
                        if (!$anyTrays): ?>
                        <div class="fifo-empty-msg">No in-stock trays. Receive a batch before selling.</div>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            </form>

            <div class="card" id="receiptResult" style="display:none;">
                <div class="card-header"><h3>Sale Complete</h3></div>
                <div class="card-body" id="receiptContent"></div>
            </div>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    var sizeCategoryMap = { <?php foreach ($eggSizes as $i => $size): ?><?= $i > 0 ? ',' : '' ?>'<?= $size['size_code'] ?>':'<?= $size['category'] ?>'<?php endforeach; ?> };
    function addSaleItem() {
        var container = document.getElementById('saleItems');
        var template = container.querySelector('[data-sale-template]') || container.querySelector('.sale-item-row');
        if (!template) return;
        var clone = template.cloneNode(true);
        clone.querySelectorAll('[data-bound], [data-bound-change]').forEach(function(field) { field.removeAttribute('data-bound'); field.removeAttribute('data-bound-change'); });
        clone.querySelector('.egg-size-select').value = '';
        clone.querySelector('.qty-input').value = '';
        clone.querySelector('.qty-input').removeAttribute('max');
        clone.removeAttribute('data-tray-id');
        clone.removeAttribute('data-sale-template');
        var trayInput = clone.querySelector('.tray-id-input');
        if (trayInput) trayInput.value = '';
        var badge = clone.querySelector('.tray-badge');
        if (badge) { badge.style.display = 'none'; badge.innerHTML = ''; }
        var oldHint = clone.querySelector('.fifo-hint');
        if (oldHint) oldHint.remove();
        var stockHint = clone.querySelector('.stock-hint');
        if (stockHint) { stockHint.textContent = ''; stockHint.removeAttribute('style'); }
        container.appendChild(clone);
        attachSaleEvents();
        updateTotal();
    }

    function removeSaleItem(btn) {
        var container = document.getElementById('saleItems');
        var rows = container.querySelectorAll('.sale-item-row');
        if (rows.length <= 1) return;
        btn.parentElement.remove();
        updateTotal();
    }

    function fifoLevel(days) {
        days = parseInt(days, 10);
        if (isNaN(days)) return '';
        return days > 21 ? 'urgent' : (days >= 14 ? 'soon' : '');
    }

    function applyFifoHint(row) {
        var select = row.querySelector('.egg-size-select');
        if (!select) return;
        select.classList.remove('fifo-urgent', 'fifo-soon');
        var opt = select.selectedOptions[0];
        var days = opt && opt.dataset.fifoDays;
        var hint = row.querySelector('.fifo-hint');
        var level = fifoLevel(days);
        if (!level) {
            if (hint) hint.remove();
            return;
        }
        select.classList.add('fifo-' + level);
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'fifo-hint';
            row.appendChild(hint);
        }
        hint.className = 'fifo-hint fifo-' + level;
        hint.textContent = (level === 'urgent' ? '\u26A0 Sell first: ' : 'Use soon: ') +
            'stock is ' + days + ' days old';
    }

    function refreshAllFifoHints() {
        document.querySelectorAll('.sale-item-row').forEach(applyFifoHint);
    }

    function selectSizeFromFifo(sizeCode) {
        var rows = document.querySelectorAll('.sale-item-row');
        var row = null;
        for (var i = 0; i < rows.length; i++) {
            if (!rows[i].querySelector('.egg-size-select').value) { row = rows[i]; break; }
        }
        if (!row) { addSaleItem(); row = document.querySelector('#saleItems .sale-item-row:last-child'); }
        var sel = row.querySelector('.egg-size-select');
        sel.value = sizeCode;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        sel.focus();
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    async function selectTrayFromFifo(trayEl) {
        if (trayEl.classList.contains('fifo-tray-expired')) return;
        var trayId = trayEl.getAttribute('data-tray-id');
        var sizeCode = trayEl.getAttribute('data-size');
        var trayQty = parseInt(trayEl.getAttribute('data-qty')) || 0;
        var trayLabel = trayEl.querySelector('.fifo-tray-id')?.textContent || '';

        // strict FIFO check: is there an older tray for this size that should go first?
        var group = trayEl.closest('.fifo-category-group');
        if (group) {
            var rows = group.querySelectorAll('.fifo-tray-row');
            var olderTray = null;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i] === trayEl) break;
                if (rows[i].getAttribute('data-size') === sizeCode) {
                    olderTray = rows[i];
                    break;
                }
            }
            if (olderTray) {
                var olderLabel = olderTray.querySelector('.fifo-tray-id')?.textContent || '';
                var olderDays = olderTray.querySelector('.age-label')?.textContent || '';
                if (!await confirmAction('FIFO WARNING\n\nOlder tray exists: ' + olderLabel + ' (' + olderDays + ')\nYou are about to sell from: ' + trayLabel + '\n\nSell from older stock first. Continue anyway?')) {
                    return;
                }
            }
        }

        // find an empty row or the row already locked to this tray
        var rows = document.querySelectorAll('.sale-item-row');
        var row = null;
        for (var i = 0; i < rows.length; i++) {
            var existingId = rows[i].querySelector('.tray-id-input')?.value;
            if (existingId === trayId) { row = rows[i]; break; }
        }
        if (!row) {
            for (var i = 0; i < rows.length; i++) {
                if (!rows[i].querySelector('.egg-size-select').value) { row = rows[i]; break; }
            }
        }
        if (!row) { addSaleItem(); row = document.querySelector('#saleItems .sale-item-row:last-child'); }

        // set size
        var sel = row.querySelector('.egg-size-select');
        sel.value = sizeCode;
        sel.dispatchEvent(new Event('change', { bubbles: true }));

        // lock tray
        var trayInput = row.querySelector('.tray-id-input');
        if (trayInput) trayInput.value = trayId;
        row.setAttribute('data-tray-id', trayId);
        row.setAttribute('data-tray-qty', trayQty);

        // set max qty to tray quantity
        var qtyInput = row.querySelector('.qty-input');
        qtyInput.max = trayQty;
        if (parseInt(qtyInput.value) > trayQty || !qtyInput.value) qtyInput.value = trayQty;
        qtyInput.dispatchEvent(new Event('input', { bubbles: true }));

        // show tray badge
        var badge = row.querySelector('.tray-badge');
        if (badge) {
            badge.style.display = 'inline-flex';
            badge.innerHTML = trayLabel + ' (' + trayQty + ' eggs) <span class="tray-badge-clear" onclick="clearTraySelection(this.closest(\'.sale-item-row\'))"><?= e_icon('close', 18) ?></span>';
        }

        // highlight selected tray in FIFO list
        document.querySelectorAll('#fifoList .fifo-tray-row').forEach(function(r) { r.classList.remove('fifo-selected'); });
        trayEl.classList.add('fifo-selected');

        // stock hint
        var hint = row.querySelector('.stock-hint');
        if (hint) {
            hint.textContent = trayQty + ' eggs in this tray';
            hint.style.color = '#5B8DEF';
        }

        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearTraySelection(row) {
        var trayInput = row.querySelector('.tray-id-input');
        if (trayInput) trayInput.value = '';
        row.removeAttribute('data-tray-id');
        row.removeAttribute('data-tray-qty');
        var badge = row.querySelector('.tray-badge');
        if (badge) { badge.style.display = 'none'; badge.innerHTML = ''; }
        applyStockLimit(row);
        document.querySelectorAll('#fifoList .fifo-tray-row').forEach(function(r) { r.classList.remove('fifo-selected'); });
    }

    function attachSaleEvents() {
        // Bind only elements that have not been wired yet, otherwise every addSaleItem()
        // call re-attaches listeners and updateTotal fires several times per keystroke.
        document.querySelectorAll('.egg-size-select:not([data-bound]), .qty-input:not([data-bound])').forEach(function(el) {
            el.setAttribute('data-bound', '1');
            el.addEventListener('change', updateTotal);
            el.addEventListener('input', updateTotal);
        });
        document.querySelectorAll('.egg-size-select:not([data-bound-change])').forEach(function(el) {
            el.setAttribute('data-bound-change', '1');
            el.addEventListener('change', function() {
                applyFifoHint(el.closest('.sale-item-row'));
                applyStockLimit(el.closest('.sale-item-row'));
                onSaleSizeChange(el.value);
            });
        });
        refreshAllFifoHints();
        document.querySelectorAll('.sale-item-row').forEach(applyStockLimit);
    }

    function applyStockLimit(row) {
        var select = row.querySelector('.egg-size-select');
        var qtyInput = row.querySelector('.qty-input');
        var hint = row.querySelector('.stock-hint');
        if (!select || !qtyInput) return;

        var opt = select.selectedOptions[0];
        if (!opt || !opt.value) {
            qtyInput.removeAttribute('max');
            if (hint) hint.textContent = '';
            return;
        }

        // A tray-locked row is bounded by that tray, not by the size-wide stock total.
        var lockedTrayQty = parseInt(row.getAttribute('data-tray-qty')) || 0;

        if (lockedTrayQty > 0) {
            qtyInput.max = lockedTrayQty;
            if (hint) {
                hint.textContent = lockedTrayQty + ' eggs in this tray';
                hint.style.color = '#5B8DEF';
            }
            if (parseInt(qtyInput.value) > lockedTrayQty) qtyInput.value = lockedTrayQty;
            updateTotal();
            return;
        }

        var stock = parseInt(opt.dataset.stock) || 0;
        var trays = parseInt(opt.dataset.trays) || 0;
        qtyInput.max = stock;
        if (hint) {
            hint.textContent = stock > 0 ? stock + ' eggs available (' + trays + ' tray' + (trays !== 1 ? 's' : '') + ')' : 'No stock';
            hint.style.color = stock === 0 ? '#c00' : '#666';
        }
        if (parseInt(qtyInput.value) > stock) {
            qtyInput.value = stock;
        }
        updateTotal();
    }

    function updateTotal() {
        if (window.enhanceControls) window.enhanceControls(document.getElementById('saleItems'));
        var total = 0;
        document.querySelectorAll('.sale-item-row').forEach(function(row) {
            var select = row.querySelector('.egg-size-select');
            var qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            var price = 0;
            if (select.selectedOptions[0]) {
                price = parseFloat(select.selectedOptions[0].dataset.price) || 0;
            }
            var subtotal = price * qty;
            row.querySelector('.sale-item-subtotal strong').textContent = '₱' + subtotal.toFixed(2);
            total += subtotal;
        });
        var count = document.querySelectorAll('#saleItems .sale-item-row').length;
        document.getElementById('saleItemCount').textContent = count + (count === 1 ? ' item' : ' items');
        document.getElementById('saleTotalDisplay').textContent = '₱' + total.toFixed(2);
        calculateChange();
    }

    function calculateChange() {
        var totalText = document.getElementById('saleTotalDisplay').textContent.replace('₱', '');
        var total = parseFloat(totalText) || 0;
        var tendered = parseFloat(document.getElementById('amountTendered').value) || 0;
        var change = tendered - total;
        document.getElementById('changeDisplay').textContent = '₱' + Math.max(0, change).toFixed(2);
    }

    function clearSale() {
        document.getElementById('saleForm').reset();
        document.getElementById('saleTotalDisplay').textContent = '₱0.00';
        document.getElementById('changeDisplay').textContent = '₱0.00';
        document.getElementById('receiptResult').style.display = 'none';
        var items = document.querySelectorAll('.sale-item-row');
        for (var i = 1; i < items.length; i++) items[i].remove();
        // clear all tray selections
        var firstRow = document.querySelector('.sale-item-row');
        if (firstRow) clearTraySelection(firstRow);
        document.querySelectorAll('#fifoList .fifo-tray-row').forEach(function(r) { r.classList.remove('fifo-selected'); });
        onSaleSizeChange('');
        updateTotal();
    }

    document.getElementById('saleForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        submitSale(false);
    });

    function submitSale(fifoOverride) {
        // Enter-key submission can still fire while the button is disabled.
        if (!fifoOverride && saleIsBusy()) return;
        var formData = new FormData(document.getElementById('saleForm'));
        var data = {
            customer_type: formData.get('customer_type'),
            payment_method: formData.get('payment_method'),
            amount_tendered: formData.get('amount_tendered') || null,
            fifo_override: fifoOverride,
            items: []
        };

        if (data.amount_tendered !== null && data.amount_tendered !== '') {
            data.change_amount = Math.max(0, parseFloat(data.amount_tendered) - (parseFloat(document.getElementById('saleTotalDisplay').textContent.replace('₱', '')) || 0));
        }

        var sizes = formData.getAll('egg_size[]');
        var qties = formData.getAll('quantity[]');
        var rows = document.querySelectorAll('.sale-item-row');
        var totalAmount = 0;

        for (var i = 0; i < sizes.length; i++) {
            if (!sizes[i] || !qties[i]) continue;
            var qty = parseInt(qties[i]);
            var price = 0;
            var select = rows[i] ? rows[i].querySelector('.egg-size-select') : null;
            if (select && select.selectedOptions[0]) {
                price = parseFloat(select.selectedOptions[0].dataset.price);
            }
            var subtotal = price * qty;
            totalAmount += subtotal;
            var item = {
                egg_size: sizes[i],
                quantity: qty,
                unit_price: price,
                subtotal: subtotal
            };
            if (rows[i]) {
                var tid = rows[i].getAttribute('data-tray-id') || (rows[i].querySelector('.tray-id-input') || {}).value || '';
                if (tid) item.tray_id = tid;
            }
            data.items.push(item);
        }

        if (!data.items.length) {
            showToast('Nothing to sell', 'warning', { message: 'Add at least one item before completing the sale.' });
            return;
        }

        var stockErrors = [];
        for (var i = 0; i < sizes.length; i++) {
            if (!sizes[i] || !qties[i]) continue;
            var select = rows[i] ? rows[i].querySelector('.egg-size-select') : null;
            var opt = select ? select.selectedOptions[0] : null;
            var stock = opt ? parseInt(opt.dataset.stock) || 0 : 0;
            var qty = parseInt(qties[i]);
            var lockedTrayQty = rows[i] ? parseInt(rows[i].getAttribute('data-tray-qty')) || 0 : 0;
            var limit = lockedTrayQty > 0 ? lockedTrayQty : stock;
            if (qty > limit) {
                stockErrors.push(sizes[i] + ': requested ' + qty + ', available ' + limit + (lockedTrayQty > 0 ? ' in selected tray' : ''));
            }
        }
        if (stockErrors.length) {
            showToast('Not enough stock', 'error', { message: stockErrors.join(' \u00B7 '), duration: 7000 });
            return;
        }

        data.total_amount = totalAmount;
        data.customer_info = { type: formData.get('customer_type') };

        setSaleBusy(true);
        fetch('../api/sales/create_receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json().then(function(j) { return { status: r.status, body: j }; }); })
        .then(async function(resp) {
            var result = resp.body;
            if (resp.status === 409 || result.error === 'FIFO violation blocked this sale') {
                var detail = result.details ? result.details.tray_id + ': ' + result.details.reason : result.error;
                if (result.can_override) {
                    if (await confirmAction('FIFO ALERT\n\n' + detail + '\n\nOlder stock should be sold first. Override and continue anyway?')) {
                        submitSale(true);
                    } else {
                        showToast('Sale blocked', 'warning', { message: 'Sell from the FIFO pick list first.' });
                    }
                } else {
                    showToast('Sale blocked', 'error', { message: 'Older stock must be sold first. A manager override is required.' });
                }
                return;
            }
            if (result.error) {
                showToast('Could not complete sale', 'error', { message: result.error, duration: 7000 });
                return;
            }
            showReceiptSuccess(result, totalAmount);
        })
        .catch(function(err) {
            showToast('Network error', 'error', { message: 'The sale was not saved. Check your connection and try again.', duration: 7000 });
            console.error(err);
        })
        .finally(function() { setSaleBusy(false); });
    }

// Guards against a double click turning one sale into two receipts. A counter
// rather than a boolean because the FIFO-override path re-enters submitSale()
// while the first request is still settling, and the button must stay disabled
// until the last one finishes.
var pendingSales = 0;
function saleIsBusy() { return pendingSales > 0; }
function setSaleBusy(busy) {
    if (busy) pendingSales++;
    else pendingSales = Math.max(0, pendingSales - 1);
    var btn = document.querySelector('#saleForm button[type="submit"]');
    if (!btn) return;
    btn.disabled = pendingSales > 0;
    btn.textContent = pendingSales > 0 ? 'Saving\u2026' : 'Complete Sale';
}

    function showReceiptSuccess(result, totalAmount) {
        var html = '<div class="receipt-success">';
        html += '<h4>Sale Complete!</h4>';
        html += '<p>Receipt: <strong>' + result.receipt_number + '</strong></p>';
        html += '<p>Total: <strong>' + '₱' + totalAmount.toFixed(2) + '</strong></p>';
        html += '<div class="form-actions">';
        html += '<button onclick="window.open(\'../api/reports/print_receipt.php?receipt=\' + encodeURIComponent(result.receipt_number), \'\' , \'width=520,height=760\')" class="btn btn-primary">Open Receipt</button>';
        html += '<button onclick="clearSale()" class="btn btn-secondary">New Sale</button>';
        html += '<a href="dashboard.php" class="btn btn-info">Dashboard</a>';
        html += '</div></div>';
        document.getElementById('receiptResult').style.display = 'block';
        document.getElementById('receiptContent').innerHTML = html;
        document.getElementById('receiptResult').scrollIntoView({ behavior: 'smooth' });
        clearSale();
        refreshFifoSidebar();
        showToast('Sale Complete', 'success', {
            message: result.receipt_number + ' \u00B7 ' + '\u20B1' + totalAmount.toFixed(2) + ' recorded'
        });
    }

    function refreshFifoSidebar() {
        fetch('sale.php?ajax=fifo', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newGroups = doc.querySelectorAll('.fifo-category-group');
                var target = document.getElementById('fifoList');
                if (target) {
                    target.innerHTML = '';
                    newGroups.forEach(function(g) { target.appendChild(g.cloneNode(true)); });
                }
                bindCategoryHeaders();
            })
            .catch(function() {});
    }

    function bindCategoryHeaders() {
        document.querySelectorAll('#fifoList .fifo-category-header').forEach(function(hdr) {
            hdr.style.cursor = 'pointer';
            hdr.onclick = function() {
                var group = hdr.closest('.fifo-category-group');
                if (!group) return;
                var isExpanded = group.classList.contains('fifo-expanded');
                if (isExpanded) {
                    group.classList.remove('fifo-expanded');
                    group.classList.add('fifo-compact');
                } else {
                    group.classList.remove('fifo-compact');
                    group.classList.add('fifo-expanded');
                    highlightPriority(group);
                }
            };
        });
    }

    function highlightPriority(group) {
        var firstRow = null;
        group.querySelectorAll('.fifo-tray-row').forEach(function(row) {
            var banner = row.querySelector('.fifo-recommended-banner');
            if (banner) banner.remove();
            row.classList.remove('fifo-recommended');
            if (!firstRow && !row.classList.contains('fifo-tray-expired')) firstRow = row;
        });
        if (firstRow) {
            firstRow.classList.add('fifo-recommended');
            var banner = document.createElement('div');
            banner.className = 'fifo-recommended-banner';
            banner.innerHTML = '<?= e_icon('star', 18) ?> HIGHLY RECOMMENDED — SELL THIS FIRST';
            firstRow.prepend(banner);
        }
    }

    function onSaleSizeChange(sizeCode) {
        var fifoList = document.getElementById('fifoList');
        if (!sizeCode) {
            fetch('sale.php?ajax=fifo', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var newGroups = doc.querySelectorAll('.fifo-category-group');
                    fifoList.innerHTML = '';
                    newGroups.forEach(function(g) { fifoList.appendChild(g.cloneNode(true)); });
                    bindCategoryHeaders();
                });
            return;
        }
        fetch('sale.php?ajax=fifo&size=' + encodeURIComponent(sizeCode), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newGroups = doc.querySelectorAll('.fifo-category-group');
                fifoList.innerHTML = '';
                if (newGroups.length === 0) {
                    fifoList.innerHTML = '<div class="fifo-empty-msg">No in-stock trays for this size.</div>';
                    return;
                }
                newGroups.forEach(function(g) { fifoList.appendChild(g.cloneNode(true)); });
                bindCategoryHeaders();
                highlightPriority(document.querySelector('#fifoList .fifo-category-group'));
            });
    }

    bindCategoryHeaders();
    attachSaleEvents();
    updateTotal();
    // Surface trays that arrived via ?tray_id= / ?trays= / ?batch= from Scan or Inventory
    document.querySelectorAll('.sale-item-row[data-tray-id]').forEach(function(row) {
        var trayInput = row.querySelector('.tray-id-input');
        if (trayInput && !trayInput.value) trayInput.value = row.getAttribute('data-tray-id');
        var badge = row.querySelector('.tray-badge');
        var label = row.getAttribute('data-tray-id');
        if (badge) {
            badge.style.display = 'inline-flex';
            badge.innerHTML = 'Tray ' + label + ' (' + (row.getAttribute('data-tray-qty') || '') + ' eggs) <span class="tray-badge-clear" onclick="clearTraySelection(this.closest(\'.sale-item-row\'))"><?= e_icon('close', 18) ?></span>';
        }
    });
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
