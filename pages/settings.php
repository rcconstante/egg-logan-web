<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';

requirePageAdmin();

$db = Database::getInstance();



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../classes/SettingsManager.php';
    try {
        (new SettingsManager())->update($_POST, getCurrentUserId());
        logActivity(getCurrentUserId(), 'settings_update', 'Updated ' . ($_POST['action'] ?? 'setting'));
        $success = 'Changes saved successfully.';
    } catch (Throwable $exception) { $settingsError = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Changes could not be saved. Please try again.'; }
}
$eggSizes = $db->fetchAll("SELECT * FROM egg_sizes ORDER BY size_code");
$systemSettings = $db->fetchAll("SELECT * FROM system_settings");
$settingsByKey = array_column($systemSettings, 'setting_value', 'setting_key');
$settingGroups = [
    ['title' => 'Business & receipts', 'icon' => 'receipt', 'description' => 'The business details and message used on receipts.', 'keys' => ['business_name', 'business_address', 'receipt_footer']],
    ['title' => 'Stock alerts', 'icon' => 'bell', 'description' => 'Choose when the system warns you about expiry and low stock.', 'keys' => ['alert_expiry_days', 'low_stock_threshold']],
    ['title' => 'Forecast automation', 'icon' => 'trending-up', 'description' => 'Control automatic updates to the demand forecasting model.', 'keys' => ['auto_retrain']],
    ['title' => 'Region & time', 'icon' => 'clock', 'description' => 'Keep dates and times consistent across your operations.', 'keys' => ['timezone']],
];
$settingLabels = [
    'business_name' => ['Business name', 'Displayed as the business name on receipts.'],
    'business_address' => ['Business address', 'The address printed on customer receipts.'],
    'receipt_footer' => ['Receipt message', 'A short closing message for your customers.'],
    'alert_expiry_days' => ['Expiry warning', 'Warn this many days before a tray expires. Use 0 for the expiry day.'],
    'low_stock_threshold' => ['Low-stock coverage', 'Share of 7-day demand remaining before a warning. 0.5 means about 3.5 days.'],
    'auto_retrain' => ['Automatic retraining', 'Allow the system to refresh the model automatically as sales data changes.'],
    'timezone' => ['Time zone', 'Used for application dates, reports, and recorded activity.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Settings</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
    <link rel="icon" type="image/png" href="../assets/images/favicon-icon.png">
</head>
<body class="app-shell settings-page">
    <div class="app-container">
        <nav class="sidebar" id="sidebar"><?php include 'sidebar.php'; ?></nav>
        <main class="main-content">
            <header class="top-bar">
                <?php include __DIR__ . '/../includes/utility-controls.php'; ?>
                <div class="top-bar-actions"><?php include __DIR__ . '/../includes/header-actions.php'; ?></div>
            </header>

            <div class="page-heading">
                <span class="page-eyebrow">Administration</span>
                <h1>System Settings</h1>
                <p>Manage egg pricing and system settings.</p>
            </div>

            <nav class="settings-navigation no-print" aria-label="Settings sections">
                <a href="#settings-pricing">Pricing</a><a href="#settings-shelf-life">Shelf life</a><a href="#settings-system">System</a><a href="#settings-stock-coverage">Stock coverage</a><a href="#settings-maintenance">Maintenance</a>
            </nav>

            <?php if (isset($settingsError)): ?><div class="alert alert-error"><?= htmlspecialchars($settingsError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if (isset($success)): ?>
            <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <?php foreach (['pricing', 'shelf-life'] as $editor): $isPricing = $editor === 'pricing'; ?>
            <section class="settings-panel" id="settings-<?= $editor ?>" aria-label="<?= $isPricing ? 'Egg pricing' : 'Shelf life' ?>">
                <div class="preferences-intro"><h2><?= $isPricing ? 'Egg pricing' : 'Shelf life' ?></h2><p><?= $isPricing ? 'Set tray and individual egg prices for each size. Save a size to apply its prices.' : 'Set how long each egg size stays fresh, measured from its production date.' ?></p></div>
                <div class="card settings-table-card">
                    <table class="table settings-edit-table">
                        <thead><tr><th scope="col">Egg size</th><th scope="col">Category</th><th scope="col">Eggs per tray</th><?php if ($isPricing): ?><th scope="col">Price per tray (₱)</th><th scope="col">Price per egg (₱)</th><?php else: ?><th scope="col">Shelf life (days)</th><?php endif; ?><th scope="col">Action</th></tr></thead>
                        <tbody>
                        <?php if (empty($eggSizes)): ?><tr><td colspan="<?= $isPricing ? 6 : 5 ?>"><div class="empty-state"><strong>No data yet</strong><p>Configured egg sizes will appear here.</p></div></td></tr><?php endif; ?>
                        <?php foreach ($eggSizes as $size): $code = htmlspecialchars($size['size_code'], ENT_QUOTES, 'UTF-8'); $formId = $editor . '-form-' . $code; ?>
                            <tr>
                                <th scope="row"><span class="settings-size-name"><?= htmlspecialchars($size['size_label']) ?><span class="size-setting-code"><?= $code ?></span></span></th>
                                <td><?= htmlspecialchars(ucfirst($size['category'])) ?></td>
                                <td><?= (int)$size['tray_capacity'] ?></td>
                                <?php if ($isPricing): ?>
                                <td><input form="<?= $formId ?>" aria-label="<?= htmlspecialchars($size['size_label']) ?> price per tray" type="number" name="price_per_tray" value="<?= htmlspecialchars($size['price_per_tray']) ?>" min="0" max="99999999" step="0.01" required></td>
                                <td><input form="<?= $formId ?>" aria-label="<?= htmlspecialchars($size['size_label']) ?> price per egg" type="number" name="price_per_egg" value="<?= htmlspecialchars($size['price_per_egg']) ?>" min="0" max="99999999" step="0.01" required></td>
                                <?php else: ?>
                                <td><input form="<?= $formId ?>" aria-label="<?= htmlspecialchars($size['size_label']) ?> shelf life in days" type="number" name="shelf_life" value="<?= (int)$size['shelf_life'] ?>" min="1" max="3650" step="1" required></td>
                                <?php endif; ?>
                                <td><form method="POST" action="settings.php#settings-<?= $editor ?>" id="<?= $formId ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="<?= $isPricing ? 'update_price' : 'update_shelf_life' ?>">
                                    <input type="hidden" name="size_code" value="<?= $code ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" aria-label="Save <?= htmlspecialchars($size['size_label']) ?> <?= $isPricing ? 'prices' : 'shelf life' ?>"><?= e_icon('save', 17) ?> Save</button>
                                </form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endforeach; ?>

            <section class="settings-panel system-preferences" id="settings-system" aria-label="System preferences">
                <div class="preferences-intro"><h2>System preferences</h2><p>Manage your business details, alerts, and automation. Save each setting after editing.</p></div>
                <div class="preferences-grid">
                <?php foreach ($settingGroups as $group): ?>
                    <section class="card preference-group" aria-labelledby="group-<?= $group['keys'][0] ?>">
                        <div class="preference-group-heading"><span class="preference-icon" aria-hidden="true"><?= e_icon($group['icon'], 22) ?></span><div><h3 id="group-<?= $group['keys'][0] ?>"><?= $group['title'] ?></h3><p><?= $group['description'] ?></p></div></div>
                        <?php foreach ($group['keys'] as $key): if (!array_key_exists($key, $settingsByKey)) continue;
                            [$label, $help] = $settingLabels[$key];
                            $value = htmlspecialchars($settingsByKey[$key], ENT_QUOTES, 'UTF-8');
                        ?>
                        <form method="POST" action="settings.php#settings-system" class="preference-field" id="setting-form-<?= $key ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_setting">
                            <input type="hidden" name="setting_key" value="<?= $key ?>">
                            <label for="preference-<?= $key ?>"><?= $label ?></label>
                            <p id="help-<?= $key ?>"><?= $help ?></p>
                            <div class="preference-control">
                            <?php if ($key === 'auto_retrain'): ?>
                                <select id="preference-<?= $key ?>" name="setting_value" aria-describedby="help-<?= $key ?>">
                                    <option value="1" <?= $settingsByKey[$key] === '1' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= $settingsByKey[$key] !== '1' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                            <?php elseif ($key === 'timezone'): ?>
                                <select id="preference-<?= $key ?>" name="setting_value" aria-describedby="help-<?= $key ?>">
                                    <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option value="<?= htmlspecialchars($zone) ?>" <?= $zone === $settingsByKey[$key] ? 'selected' : '' ?>><?= htmlspecialchars(str_replace('_', ' ', $zone)) ?></option><?php endforeach; ?>
                                </select>
                            <?php elseif (in_array($key, ['business_address', 'receipt_footer'], true)): ?>
                                <textarea id="preference-<?= $key ?>" name="setting_value" rows="2" maxlength="500" required aria-describedby="help-<?= $key ?>"><?= $value ?></textarea>
                            <?php else: ?>
                                <input id="preference-<?= $key ?>" name="setting_value" value="<?= $value ?>" required aria-describedby="help-<?= $key ?>" <?= $key === 'alert_expiry_days' ? 'type="number" min="0" max="365" step="1"' : ($key === 'low_stock_threshold' ? 'type="number" min="0.1" max="1" step="0.01"' : 'type="text" maxlength="500"') ?>>
                            <?php endif; ?>
                                <button type="submit" class="btn btn-secondary preference-save" aria-label="Save <?= strtolower($label) ?>"><?= e_icon('save', 17) ?><span>Save</span></button>
                            </div>
                        </form>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
                </div>
            </section>

            <?php
            // Plain-language answer to "what does low_stock_threshold actually do?".
            // The setting is a bare decimal in a generic table, which is why its effect
            // is invisible. This recomputes the same comparison the alert uses, per
            // size, so the number can be judged instead of guessed at.
            $lowStockRate = 0.5;
            foreach ($systemSettings as $s) {
                if ($s['setting_key'] === 'low_stock_threshold') {
                    $lowStockRate = max(0.1, min(1.0, (float)$s['setting_value']));
                }
            }

            $coverPreview = $db->fetchAll(
                "SELECT s.size_code, s.size_label, s.tray_capacity,
                        COALESCE(SUM(t.quantity), 0) AS stock,
                        COALESCE(r.sold_7d, 0) AS sold_7d,
                        COALESCE(p.pred_eggs, 0) AS pred_eggs
                 FROM egg_sizes s
                 LEFT JOIN egg_trays t ON s.size_code = t.egg_size AND t.status = 'in_stock'
                 LEFT JOIN (
                    SELECT ri.egg_size, SUM(ri.quantity) AS sold_7d
                    FROM receipt_items ri
                    JOIN receipts r ON ri.receipt_id = r.id
                    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND r.is_voided = 0
                    GROUP BY ri.egg_size
                 ) r ON s.size_code = r.egg_size
                 LEFT JOIN (
                    SELECT kp.egg_size, AVG(kp.predicted_trays) * es.tray_capacity AS pred_eggs
                    FROM knn_predictions kp
                    JOIN egg_sizes es ON kp.egg_size = es.size_code
                    WHERE kp.prediction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY kp.egg_size, es.tray_capacity
                 ) p ON s.size_code = p.egg_size
                 GROUP BY s.size_code, s.size_label, s.tray_capacity, r.sold_7d, p.pred_eggs
                 ORDER BY s.size_code"
            );
            $rateLabel = rtrim(rtrim(number_format($lowStockRate, 2), '0'), '.');
            ?>

            <section class="settings-panel" id="settings-stock-coverage" aria-label="Stock coverage">
                <div class="preferences-intro"><h2>Stock coverage</h2><p>Understand your warning threshold and compare it with current inventory.</p></div>
                <div class="coverage-explainer card">
                    <span class="preference-icon" aria-hidden="true"><?= e_icon('bell', 24) ?></span>
                    <div><h3>Your low-stock warning</h3><p>Warn when inventory falls below <strong><?= htmlspecialchars($rateLabel) ?> × 7-day demand</strong> — approximately <?= number_format($lowStockRate * 7, 1) ?> days of stock. Recent sales are used first, then the forecast when sales are unavailable.</p></div>
                    <a href="#settings-system" class="btn btn-secondary">Edit threshold <?= e_icon('chevron-right', 16) ?></a>
                </div>
                <div class="coverage-examples" aria-label="Coverage examples">
                    <?php foreach ([['0.1', '0.7 days'], ['0.3', '2.1 days'], ['0.5', '3.5 days'], ['0.7', '4.9 days'], ['1.0', '7 days']] as $opt): ?>
                    <div class="coverage-example <?= abs((float)$opt[0] - $lowStockRate) < 0.001 ? 'is-current' : '' ?>"><strong><?= $opt[0] ?></strong><span><?= $opt[1] ?> of coverage</span></div>
                    <?php endforeach; ?>
                </div>
                <div class="card"><div class="card-header"><h3>Coverage by egg size</h3></div><div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Size</th>
                                <th class="mt-val">On hand</th>
                                <th class="mt-val">7-day demand</th>
                                <th class="mt-val">&times; <?= htmlspecialchars($rateLabel) ?> = warn below</th>
                                <th class="mt-val">Days of cover</th>
                                <th>Right now</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($coverPreview)): ?>
                            <tr><td colspan="6"><div class="empty-state"><strong>No data yet</strong><p>Stock coverage will appear once data is available.</p></div></td></tr>
                            <?php endif; ?>
                        <?php foreach ($coverPreview as $row):
                            $stock = (int)$row['stock'];
                            $sold = (int)$row['sold_7d'];
                            $pred = (int)$row['pred_eggs'];
                            $demand = $sold > 0 ? $sold : $pred;
                            $source = $sold > 0 ? 'sales' : 'forecast';
                            $line = (int)round($demand * $lowStockRate);
                            $days = $demand > 0 ? $stock / ($demand / 7) : null;

                            if ($stock <= 0) {
                                $badge = '<span class="badge badge-danger">Out of stock</span>';
                            } elseif ($line > 0 && $stock < $line) {
                                $badge = '<span class="badge badge-warning">Low stock</span>';
                            } else {
                                $badge = '<span class="badge badge-success">In stock</span>';
                            }
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($row['size_label']) ?> <span class="size-setting-code"><?= htmlspecialchars($row['size_code']) ?></span></td>
                                <td class="mt-val"><?= number_format($stock) ?></td>
                                <td class="mt-val"><?= $demand > 0 ? number_format($demand) . ' <span style="color:var(--gray-500);font-size:11px;">(' . $source . ')</span>' : '<span style="color:var(--gray-500);">none</span>' ?></td>
                                <td class="mt-val"><strong><?= number_format($line) ?></strong></td>
                                <td class="mt-val"><?= $days !== null ? number_format($days, 1) : '&mdash;' ?></td>
                                <td><?= $badge ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                </div></div>
                <p class="settings-footnote">Zero stock always triggers a warning. Days of cover are unavailable until there is sales or forecast data.</p>
            </section>

            <section class="settings-panel" id="settings-maintenance" aria-label="Maintenance">
                <div class="preferences-intro"><h2>Maintenance</h2><p>Keep your records backed up and operational checks up to date.</p></div>
                <div class="maintenance-grid">
                    <article class="card maintenance-card"><span class="preference-icon" aria-hidden="true"><?= e_icon('shield', 24) ?></span><h3>Database backup</h3><p>Create a private SQL backup of your database, including records and table definitions.</p><button onclick="backupDatabase()" class="btn btn-primary">Backup database <?= e_icon('chevron-right', 16) ?></button></article>
                    <article class="card maintenance-card"><span class="preference-icon" aria-hidden="true"><?= e_icon('clipboard', 24) ?></span><h3>FIFO priorities</h3><p>Refresh tray priority scores to keep the pick list in the correct selling order.</p><button onclick="recalculateFIFO()" class="btn btn-secondary">Recalculate FIFO <?= e_icon('chevron-right', 16) ?></button></article>
                    <article class="card maintenance-card"><span class="preference-icon" aria-hidden="true"><?= e_icon('bell', 24) ?></span><h3>Inventory alerts</h3><p>Check inventory for expired trays, upcoming expiry, and low-stock warnings.</p><button onclick="runAlerts()" class="btn btn-secondary">Run alert checks <?= e_icon('chevron-right', 16) ?></button></article>
                </div>
            </section>
        </main>
    </div>
    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    async function runMaintenanceAction(name, endpoint, successMessage) {
        var button = document.querySelector('[onclick="' + name + '()"]');
        if (button?.disabled) return;
        var original = button ? Array.from(button.childNodes).map(function(node) { return node.cloneNode(true); }) : [];
        if (button) { button.disabled = true; button.textContent = 'Working…'; }
        try {
            var response = await fetch(endpoint, { method: 'POST' });
            var data = await response.json();
            if (!response.ok || data.success === false) throw new Error(data.error || 'The action could not be completed.');
            showToast(successMessage, 'success', { message: data.filename || (data.alerts_created !== undefined ? data.alerts_created + ' new alerts' : '') });
        } catch (error) { showToast('Action failed', 'error', { message: error.message || 'Please try again.' }); }
        finally { if (button) { button.disabled = false; button.replaceChildren.apply(button, original); } }
    }
    function backupDatabase() { return runMaintenanceAction('backupDatabase', '../api/settings/backup.php', 'Backup created'); }
    function recalculateFIFO() { return runMaintenanceAction('recalculateFIFO', '../api/settings/recalculate_fifo.php', 'FIFO scores recalculated'); }
    function runAlerts() { return runMaintenanceAction('runAlerts', '../api/settings/run_alerts.php', 'Alerts checked'); }
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
