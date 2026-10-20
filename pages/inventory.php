<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/TrayManager.php';
require_once __DIR__ . '/../classes/BatchManager.php';
require_once __DIR__ . '/../classes/QRManager.php';
require_once __DIR__ . '/../includes/qr_generator.php';

requireAuth();

$db = Database::getInstance();
$trayManager = new TrayManager();
$batchManager = new BatchManager();
$qrManager = new QRManager();
$qrGenerator = new QRGenerator();

$inventory = $trayManager->getCurrentInventory();
$eggSizes = $db->fetchAll("SELECT * FROM egg_sizes ORDER BY FIELD(category, 'fresh', 'salted'), size_code");
$recentBatches = $batchManager->getAllBatches(1, 10);

foreach ($eggSizes as &$es) {
    if (empty($es['variant_qr_data'])) {
        $qrManager->generateVariantQR($es['size_code']);
        $refreshed = $db->fetchOne("SELECT variant_qr_data FROM egg_sizes WHERE size_code = ?", [$es['size_code']]);
        $es['variant_qr_data'] = $refreshed['variant_qr_data'] ?? null;
    }
    $es['variant_qr_uri'] = !empty($es['variant_qr_data']) ? $qrGenerator->variantDataUri($es['size_code']) : '';
    $es['stock'] = $db->fetchOne(
        "SELECT COUNT(*) as tray_count, COALESCE(SUM(t.quantity), 0) as total_eggs
         FROM egg_trays t WHERE t.egg_size = ? AND t.status = 'in_stock'",
        [$es['size_code']]
    );
}
unset($es);

$action = $_GET['action'] ?? 'list';

$expiredTrays = $db->fetchAll(
    "SELECT t.tray_id, t.quantity, t.egg_size, t.production_date, t.expiry_date,
            t.batch_id, t.tray_sequence, b.batch_number, s.size_label, s.category,
            DATEDIFF(NOW(), t.expiry_date) as days_expired
     FROM egg_trays t
     JOIN batches b ON t.batch_id = b.id
     JOIN egg_sizes s ON t.egg_size = s.size_code
     WHERE t.status = 'in_stock' AND t.expiry_date < CURDATE()
     ORDER BY b.id ASC, COALESCE(t.tray_sequence, t.id) ASC"
);
$expiredCount = count($expiredTrays);

$highlightTray = $_GET['tray'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Inventory Management</title>
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
                <span class="page-eyebrow">Inventory control</span>
                <h1>Inventory Management</h1>
                <p>Manage egg stock, batches, and availability.</p>
            <div class="page-actions no-print" data-toolbar role="group" aria-label="Page actions">
                <button onclick="showAddBatch()" class="btn btn-primary"><?= e_icon('plus', 18) ?> New Batch</button>
            </div></div>

            

            <?php if ($expiredCount > 0): ?>
            <div class="expired-notification" id="expiredNotification">
                <div class="expired-notification-content">
                    <span class="expired-notification-icon"><?= e_icon('alert-triangle', 20) ?></span>
                    <div class="expired-notification-text">
                        <strong><?= $expiredCount ?> expired tray<?= $expiredCount !== 1 ? 's' : '' ?> detected</strong>
                        <span>These items have passed their expiry date and should be disposed.</span>
                    </div>
                    <div class="expired-notification-actions">
                        <button onclick="scrollToExpired()" class="btn btn-sm btn-warning">View Expired</button>
                        <button onclick="disposeAllExpired()" class="btn btn-sm btn-danger">Dispose All</button>
                        <button onclick="dismissNotification()" class="btn btn-sm btn-secondary"><?= e_icon('close', 18) ?></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <dialog id="addBatchDialog" class="confirmation-dialog form-dialog" aria-labelledby="addBatchTitle">
                <div class="form-dialog-heading"><div><h2 id="addBatchTitle">Add New Batch</h2><p>Receive trays and update your inventory.</p></div><button type="button" class="btn-small icon-action" onclick="closeAddBatch()" aria-label="Close new batch"><?= e_icon('close', 20) ?></button></div>
                <div>
                    <form id="addBatchForm" method="POST" action="../api/inventory/create_batch.php">
                        <?= csrfField() ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="batchEggSize">Egg Size</label>
                                <select id="batchEggSize" name="egg_size" required>
                                    <?php foreach ($eggSizes as $size): ?>
                                    <option value="<?= $size['size_code'] ?>"><?= $size['size_label'] ?> (<?= $size['tray_capacity'] ?>/tray)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="batchTrayCount">Number of Trays</label>
                                <input id="batchTrayCount" type="number" name="tray_count" min="1" max="500" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="batchProductionDate">Production Date</label>
                                <input id="batchProductionDate" type="date" name="production_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="batchSupplier">Supplier</label>
                                <input id="batchSupplier" type="text" name="supplier" maxlength="100" placeholder="Supplier name">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-success">Create Batch</button>
                            <button type="button" onclick="closeAddBatch()" class="btn btn-secondary">Cancel</button>
                        </div>
                    </form>
                </div>
            </dialog>

            <div class="card">
                <div class="card-header">
                    <h3>Current Stock Summary</h3>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Size</th>
                                <th>Category</th>
                                <th>Trays</th>
                                <th>Total Eggs</th>
                                <th>Capacity</th>
                                <th>Oldest Batch</th>
                                <th>Price/Tray</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventory)): ?>
                            <tr><td colspan="8"><div class="empty-state"><strong>No data yet</strong><p>Add a batch to see inventory here.</p></div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($inventory as $item): ?>
                            <tr>
                                <td><span class="size-badge" style="background:<?= getEggColorClass($item['size_code']) ?>"><?= $item['size_label'] ?></span></td>
                                <td><?= ucfirst($item['category']) ?></td>
                                <td><?= $item['tray_count'] ?></td>
                                <td><?= number_format($item['total_eggs']) ?></td>
                                <td><?= $item['tray_capacity'] ?>/tray</td>
                                <td><?= formatDate($item['oldest_batch']) ?></td>
                                <td><?= formatCurrency($item['price_per_tray']) ?></td>
                                <td>
                                    <a href="picklist.php?size=<?= $item['size_code'] ?>" class="btn-small icon-action" aria-label="View pick list" title="View pick list"><?= e_icon('clipboard', 18) ?></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($expiredCount > 0): ?>
            <div class="card expired-card" id="expiredSection">
                <div class="card-header">
                    <h3>Expired Items <span class="expired-count-badge"><?= $expiredCount ?></span></h3>
                    <button onclick="disposeAllExpired()" class="btn btn-danger btn-sm">Dispose All Expired</button>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Size</th>
                                <th>Category</th>
                                <th>Batch</th>
                                <th>Tray #</th>
                                <th>Qty</th>
                                <th>Expiry Date</th>
                                <th>Days Expired</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiredTrays as $et): ?>
                            <tr id="expired-row-<?= htmlspecialchars($et['tray_id']) ?>" class="<?= $highlightTray === $et['tray_id'] ? 'row-highlight' : '' ?>">
                                <td><span class="size-badge" style="background:<?= getEggColorClass($et['egg_size']) ?>"><?= $et['size_label'] ?></span></td>
                                <td><?= ucfirst($et['category']) ?></td>
                                <td><?= htmlspecialchars($et['batch_number']) ?></td>
                                <td>#<?= $et['tray_sequence'] ?></td>
                                <td><?= $et['quantity'] ?></td>
                                <td style="color:#c00;"><?= formatDate($et['expiry_date']) ?></td>
                                <td><span class="days-expired-badge"><?= $et['days_expired'] ?>d</span></td>
                                <td>
                                    <button onclick="disposeTray('<?= htmlspecialchars($et['tray_id']) ?>', this)" class="btn-small btn-danger icon-action dispose-btn" aria-label="Dispose expired tray" title="Dispose expired tray">
                                        <?= e_icon('trash', 18) ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php // Nothing expired -> render no section at all. A "no expired items"
                  // banner would sit there permanently, including after a dispose
                  // followed by a refresh; the disposal toast is the only feedback. ?>

            <div class="card qr-print-section" id="qrPrintSection">
                <div class="card-header">
                    <h3>Master QR Codes (Reusable per Variant)</h3>
                    <button type="button" class="btn btn-secondary no-print" data-qr-preview=""><?= e_icon('printer', 18) ?> Print QR codes</button>
                </div>
                <div class="card-body">
                    <p style="color:var(--gray-500);font-size:12px;margin-bottom:12px;">One QR code per egg variant. Scan to view all in-stock trays. Automatically links new batches — no reprinting needed.</p>
                    <div class="variant-qr-grid">
                        <?php foreach ($eggSizes as $es): ?>
                        <div class="variant-qr-card" data-qr-code="<?= htmlspecialchars($es['size_code'], ENT_QUOTES, 'UTF-8') ?>" style="border:1px solid var(--gray-200);border-radius:var(--r-md);padding:12px;text-align:center;background:var(--gray-50);">
                            <div style="font-weight:700;font-size:13px;margin-bottom:6px;color:<?= getEggColorClass($es['size_code']) ?>;"><?= $es['size_label'] ?> (<?= $es['size_code'] ?>)</div>
                            <?php if (!empty($es['variant_qr_uri'])): ?>
                            <img src="<?= $es['variant_qr_uri'] ?>" alt="<?= htmlspecialchars($es['size_label'], ENT_QUOTES, 'UTF-8') ?> variant QR code" style="width:100px;height:100px;margin:4px auto;display:block;">
                            <?php else: ?>
                            <div style="width:100px;height:100px;margin:4px auto;background:var(--gray-200);display:flex;align-items:center;justify-content:center;color:var(--gray-500);font-size:11px;">No QR</div>
                            <?php endif; ?>
                            <div class="qr-stock-count" style="font-size:11px;color:var(--gray-500);"><?= ($es['stock']['tray_count'] ?? 0) ?> trays | <?= number_format($es['stock']['total_eggs'] ?? 0) ?> eggs</div>
                            <div class="qr-card-actions no-print" style="margin-top:6px;display:flex;gap:4px;justify-content:center;">
                                <button onclick="showVariantQR('<?= $es['size_code'] ?>')" class="btn-small icon-action" aria-label="View QR code" title="View QR code"><?= e_icon('eye', 18) ?></button><button type="button" class="btn-small icon-action" aria-label="Print QR label" title="Print QR label" data-qr-preview="<?= htmlspecialchars($es['size_code'], ENT_QUOTES, 'UTF-8') ?>"><?= e_icon('printer', 18) ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card recent-batches-card">
                <div class="card-header"><h3>Recent Batches</h3></div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr><th>Batch #</th><th>Size</th><th>Date</th><th>Supplier</th><th>Trays</th><th>Eggs</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentBatches)): ?>
                            <tr><td colspan="7"><div class="empty-state"><strong>No data yet</strong><p>New batches will appear here.</p></div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($recentBatches as $batch): ?>
                            <tr id="batch-<?= $batch['id'] ?>">
                                <td><?= $batch['batch_number'] ?></td>
                                <td><?= $batch['size_label'] ?></td>
                                <td><?= formatDate($batch['production_date']) ?></td>
                                <td><?= htmlspecialchars($batch['supplier'] ?? 'N/A') ?></td>
                                <td><?= $batch['tray_count'] ?? 0 ?></td>
                                <td><?= number_format($batch['total_eggs'] ?? 0) ?></td>
                                <td>
                                    <?php if (isAdmin()): ?><button onclick="deleteBatch(<?= $batch['id'] ?>, '<?= htmlspecialchars($batch['batch_number']) ?>')" class="btn-small btn-danger icon-action" aria-label="Delete batch" title="Delete batch"><?= e_icon('trash', 18) ?></button><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    document.getElementById('addBatchForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form)
        })
        .then(function(r) {
            return r.json().catch(function() {
                throw new Error('Server returned an invalid response (HTTP ' + r.status + ')');
            });
        })
        .then(function(d) {
            if (d.success) {
                window.location.href = 'inventory.php?batch_created=1&batch_id=' + d.batch_id;
            } else {
                showToast('Cannot add batch: ' + (d.error || 'Unknown error'), 'error');
                if (btn) btn.disabled = false;
            }
        })
        .catch(function(err) {
            showToast('Cannot add batch: ' + err.message, 'error');
            if (btn) btn.disabled = false;
        });
    });

    function showAddBatch() {
        var dialog = document.getElementById('addBatchDialog');
        if (!dialog.open) dialog.showModal();
    }
    function closeAddBatch() { document.getElementById('addBatchDialog').close(); }
    document.getElementById('addBatchDialog').addEventListener('click', function(event) {
        if (event.target !== this) return;
        var rect = this.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) this.close();
    });
    <?php if ($action === 'add_batch'): ?>
    document.addEventListener('DOMContentLoaded', showAddBatch);
    <?php endif; ?>


    async function deleteBatch(batchId, batchNum) {
        if (!await confirmAction('Delete batch #' + batchNum + '? This will also delete all trays in this batch.')) return;
        fetch('../api/inventory/delete_batch.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ batch_id: batchId })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                var row = document.getElementById('batch-' + batchId);
                if (row) row.remove();
            } else {
                showToast('Error: ' + d.error, 'error');
            }
        });
    }

    async function showVariantQR(sizeCode) {
        try {
            var response = await fetch('../api/inventory/variant_qr.php?size_code=' + encodeURIComponent(sizeCode));
            var data = await response.json();
            if (!response.ok || data.error || !data.qr_data_uri) throw new Error(data.error || 'QR code is unavailable.');
            var dialog = document.createElement('dialog');
            dialog.className = 'confirmation-dialog qr-detail-dialog';
            var heading = document.createElement('h2');
            heading.id = 'variantQrTitle';
            heading.textContent = data.size_label + ' (' + data.size_code + ')';
            dialog.setAttribute('aria-labelledby', heading.id);
            var image = document.createElement('img');
            image.src = data.qr_data_uri;
            image.alt = data.size_label + ' permanent variant QR code';
            image.width = 200;
            image.height = 200;
            var description = document.createElement('p');
            description.textContent = 'Print once and place beside this egg variant. Staff can scan it in Egglogan to see current trays, production and expiry dates, and eggs per tray. New batches appear automatically.';
            var actions = document.createElement('div');
            actions.className = 'confirmation-actions';
            var close = document.createElement('button');
            close.type = 'button'; close.className = 'btn btn-secondary'; close.textContent = 'Close';
            close.addEventListener('click', function() { dialog.close(); });
            var print = document.createElement('button');
            print.type = 'button'; print.className = 'btn btn-primary';
            print.append(createUiIcon('printer', 18), document.createTextNode('Print label'));
            print.addEventListener('click', function() {
                dialog.close();
                Array.from(document.querySelectorAll('.qr-card-actions [data-qr-preview]')).find(function(button) { return button.dataset.qrPreview === sizeCode; })?.click();
            });
            actions.append(close, print);
            dialog.append(heading, image, description, actions);
            dialog.addEventListener('close', function() { dialog.remove(); }, { once: true });
            document.body.appendChild(dialog);
            dialog.showModal();
        } catch (error) { showToast('Could not load QR code', 'error', { message: error.message }); }
    }

    async function disposeTray(trayId, btn) {
        if (!await confirmAction('Dispose this expired tray? This action cannot be undone.')) return;
        btn.disabled = true;
        btn.textContent = 'Disposing...';
        fetch('../api/inventory/dispose_expired.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ tray_id: trayId, reason: 'expired' })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                var row = document.getElementById('expired-row-' + trayId);
                var announce = function() {
                    // The last expired tray is gone: the whole section leaves with it,
                    // and the toast is the only thing left to say so.
                    if (checkExpiredEmpty()) {
                        showToast('All expired items disposed', 'success', {
                            message: 'No expired stock remaining.'
                        });
                    } else {
                        showToast('Tray disposed', 'success', {
                            message: 'Removed from stock.'
                        });
                    }
                };
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); announce(); }, 300);
                } else {
                    announce();
                }
                updateExpiredBadge(-1);
            } else {
                showToast('Could not dispose tray', 'error', { message: d.error, duration: 7000 });
                btn.disabled = false;
                btn.textContent = 'Dispose';
            }
        })
        .catch(function(err) {
            showToast('Network error', 'error', { message: 'Nothing was disposed. Check your connection and try again.', duration: 7000 });
            btn.disabled = false;
            btn.textContent = 'Dispose';
        });
    }

    async function disposeAllExpired() {
        if (!await confirmAction('Dispose ALL expired trays? This action cannot be undone.')) return;
        var btn = document.querySelector('.expired-card .btn-danger');
        if (btn) { btn.disabled = true; btn.textContent = 'Disposing...'; }
        fetch('../api/inventory/dispose_all_expired.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'dispose_all', reason: 'expired' })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                if (d.disposed > 0) {
                    fadeOutExpiredSection();
                    clearExpiredChrome();
                    showToast('All expired items disposed', 'success', { message: d.message });
                } else {
                    showToast('Nothing to dispose', 'info', { message: d.message });
                }
            } else {
                showToast('Could not dispose', 'error', { message: d.error, duration: 7000 });
                if (btn) { btn.disabled = false; btn.textContent = 'Dispose All Expired'; }
            }
        })
        .catch(function(err) {
            showToast('Network error', 'error', { message: 'Nothing was disposed. Check your connection and try again.', duration: 7000 });
            if (btn) { btn.disabled = false; btn.textContent = 'Dispose All Expired'; }
        });
    }

    <?php if ($highlightTray): ?>
    (function() {
        var row = document.getElementById('expired-row-<?= htmlspecialchars($highlightTray, ENT_QUOTES) ?>');
        if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    })();
    <?php endif; ?>

    function scrollToExpired() {
        var section = document.getElementById('expiredSection');
        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function dismissNotification() {
        var notif = document.getElementById('expiredNotification');
        if (notif) notif.remove();
    }

    function updateExpiredBadge(delta) {
        var badge = document.querySelector('.nav-badge-alert');
        if (!badge) return;
        var count = parseInt(badge.textContent) + delta;
        if (count <= 0) {
            badge.remove();
        } else {
            badge.textContent = count;
        }
    }

    // Once nothing is expired the section is taken off the page entirely. Nothing
    // is left behind saying "no expired items" -- the toast that reported the
    // disposal is the only confirmation, and it retires itself.
    function fadeOutExpiredSection() {
        var section = document.getElementById('expiredSection');
        if (!section) return;
        section.style.transition = 'opacity 0.3s';
        section.style.opacity = '0';
        setTimeout(function() { section.remove(); }, 300);
    }

    function clearExpiredChrome() {
        var notif = document.getElementById('expiredNotification');
        if (notif) notif.remove();
        var badge = document.querySelector('.nav-badge-alert');
        if (badge) badge.remove();
    }

    function checkExpiredEmpty() {
        var tbody = document.querySelector('#expiredSection tbody');
        if (!tbody || tbody.querySelectorAll('tr').length > 0) return false;
        fadeOutExpiredSection();
        clearExpiredChrome();
        return true;
    }

    <?php if (isset($_GET['batch_created'])): ?>
    showToast('Batch created', 'success', {
        message: 'Batch ID: <?= htmlspecialchars($_GET['batch_id'] ?? '', ENT_QUOTES) ?>'
    });
    <?php endif; ?>
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
