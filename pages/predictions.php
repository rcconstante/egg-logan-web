<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/KNNPredictor.php';
require_once __DIR__ . '/../classes/ModelEvaluator.php';

requireAuth();

$db = Database::getInstance();
$predictor = new KNNPredictor();
$evaluator = new ModelEvaluator();

$autoRetrain = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_retrain'");
$autoRetrainOn = ($autoRetrain['setting_value'] ?? '1') == '1';

$eggSizes = $db->fetchAll("SELECT * FROM egg_sizes ORDER BY size_code");
$predictions = $predictor->predictAllSizes();
$metrics = $evaluator->evaluateAllMetrics();
$activeModel = $db->fetchOne("SELECT * FROM model_versions WHERE is_active = 1");
$overallGauge = $evaluator->getOverallAccuracyGauge();
$overallMetrics = $evaluator->getOverallMetrics();

$plain = metricPlainInfo();

// --- Data freshness: how stale is the sales history the model learns from? ---
$lastSample = $predictor->latestSampleDate();
$daysSinceSale = $lastSample ? (int)floor((time() - strtotime($lastSample)) / 86400) : null;
$freshness = 'unknown';
$freshnessLabel = 'No sales data yet';
if ($daysSinceSale !== null) {
    if ($daysSinceSale <= 2) {
        $freshness = 'fresh';
        $freshnessLabel = 'Up to date';
    } elseif ($daysSinceSale <= 7) {
        $freshness = 'recent';
        $freshnessLabel = 'A few days behind';
    } elseif ($daysSinceSale <= 30) {
        $freshness = 'stale';
        $freshnessLabel = 'Behind by ' . $daysSinceSale . ' days';
    } else {
        $freshness = 'very_stale';
        $freshnessLabel = 'Behind by ' . $daysSinceSale . ' days';
    }
}
// A gap in the last 30 days zeroes out the 7/14/30-day demand windows, so the
// forecast can only match on weekday. Flag it instead of quietly showing "high".
$demandIsStale = $daysSinceSale !== null && $daysSinceSale > 30;

// --- Per-size stock, plus a plain-language read of each prediction ---
$stockBySize = [];
foreach ($eggSizes as $es) {
    $s = $db->fetchOne(
        "SELECT COALESCE(SUM(quantity),0) as total, COUNT(*) as trays
         FROM egg_trays WHERE egg_size = ? AND status = 'in_stock'",
        [$es['size_code']]
    );
    $stockBySize[$es['size_code']] = [
        'eggs' => (int)($s['total'] ?? 0),
        'trays' => (int)($s['trays'] ?? 0),
        'capacity' => (int)$es['tray_capacity'],
        'category' => $es['category']
    ];
}

// --- Day totals so the client sees one number first ---
$totalTrays = 0;
$totalEggs = 0;
$totalSales = 0.0;
$shortages = [];
$byCategory = [];
$CATEGORY_LABELS = ['fresh' => 'Fresh', 'salted' => 'Salted'];
foreach ($predictions as $size => $pred) {
    if (!isset($stockBySize[$size])) continue;
    if (!empty($pred['is_no_data'])) continue;
    // Kept as a float and rounded once at the end. Truncating each size to an int
    // as it was added up lost up to one tray per size and no longer matched the
    // per-category figures shown underneath.
    $trays = (float)($pred['predicted_trays'] ?? 0);
    $eggs = (int)($pred['predicted_eggs'] ?? 0);
    $sales = (float)($pred['predicted_sales'] ?? 0);
    $totalTrays += $trays;
    $totalEggs += $eggs;
    $totalSales += $sales;

    $cat = $stockBySize[$size]['category'] ?? 'fresh';
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = [
            'label' => $CATEGORY_LABELS[$cat] ?? ucfirst($cat),
            'trays' => 0.0, 'eggs' => 0, 'sales' => 0.0, 'sizes' => [],
        ];
    }
    $byCategory[$cat]['trays'] += $trays;
    $byCategory[$cat]['eggs'] += $eggs;
    $byCategory[$cat]['sales'] += $sales;
    $byCategory[$cat]['sizes'][$size] = [
        'label' => getEggSizeLabel($size),
        'trays' => $trays,
        'eggs' => $eggs,
        'sales' => $sales,
    ];

    $stock = $stockBySize[$size] ?? ['eggs' => 0, 'trays' => 0];
    $short = max(0, ($pred['predicted_eggs'] ?? 0) - $stock['eggs']);
    if ($short > 0) {
        $shortages[] = [
            'size' => $size,
            'label' => getEggSizeLabel($size),
            'short' => $short,
            'trays_to_buy' => (int)ceil($short / max(1, ($stock['capacity'] ?: 30)))
        ];
    }
}
usort($shortages, fn($a, $b) => $b['short'] <=> $a['short']);

// Order the breakdown the way the pick list groups it, then anything unexpected.
$categoryRows = [];
foreach (['fresh', 'salted'] as $key) {
    if (!empty($byCategory[$key])) $categoryRows[$key] = $byCategory[$key];
}
foreach ($byCategory as $key => $row) {
    if (!isset($categoryRows[$key])) $categoryRows[$key] = $row;
}

// Round once, at the smallest unit, then add up. Each category total is the sum of
// its own size totals, and the headline is the sum of the categories -- so every
// level the user adds up agrees with the level above it, with no rounding drift.
$totalTraysWhole = 0;
foreach ($categoryRows as $key => $row) {
    $sizeRows = [];
    $catWhole = 0;
    foreach ($row['sizes'] as $code => $sizeRow) {
        $whole = (int)round($sizeRow['trays']);
        $catWhole += $whole;
        $sizeRows[$code] = $sizeRow + ['trays_whole' => $whole];
    }
    $categoryRows[$key]['trays_whole'] = $catWhole;
    $categoryRows[$key]['size_rows'] = $sizeRows;
    $totalTraysWhole += $catWhole;
}

// --- How accurate has it actually been, in one sentence? ---
$hitRate = (float)($overallMetrics['accuracy'] ?? 0);
$gradedSizes = array_filter($metrics, fn($m) => ($m['sample_count'] ?? 0) > 0);
$evalSummary = $evaluator->getEvaluationSummary();

// Calculate distinct trading days from the evaluation window
$distinctDays = 0;
if ($evalSummary['from'] && $evalSummary['to']) {
    $start = new DateTime($evalSummary['from']);
    $end = new DateTime($evalSummary['to']);
    $interval = $start->diff($end);
    $distinctDays = $interval->days + 1;
} elseif (!empty($gradedSizes)) {
    $distinctDays = array_sum(array_map(fn($m) => (int)$m['sample_count'], $gradedSizes));
}

$accuracySentence = 'Not enough data yet to rate accuracy.';
if (!empty($gradedSizes)) {
    $best = $gradedSizes;
    usort($best, fn($a, $b) => ($b['f1_score'] ?? 0) <=> ($a['f1_score'] ?? 0));
    $bestName = getEggSizeLabel($best[0]['egg_size']) . ' (' . $best[0]['egg_size'] . ')';
    $worst = array_slice($best, -1)[0];
    $worstName = getEggSizeLabel($worst['egg_size']) . ' (' . $worst['egg_size'] . ')';

    $withinCount = array_sum(array_map(
        fn($m) => (int)($m['confusion_matrix']['within_tolerance'] ?? 0),
        $gradedSizes
    ));
    $totalScored = array_sum(array_map(fn($m) => (int)$m['sample_count'], $gradedSizes));

    $window = $evalSummary['from'] && $evalSummary['to']
        ? formatDate($evalSummary['from']) . ' &ndash; ' . formatDate($evalSummary['to'])
        : 'recent days';

    $accuracySentence = sprintf(
        'Tested on %s trading days. %s of %s forecasts were within 1 tray (%.1f%%). Best: %s. Weakest: %s.',
        number_format($distinctDays),
        number_format($withinCount),
        number_format($totalScored),
        $hitRate,
        $bestName,
        $worstName
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Sales Forecast</title>
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
                <span class="page-eyebrow">Demand planning</span>
                <h1>Sales Forecast</h1>
                <p>Review forecasts and model performance.</p>
            <div class="page-actions no-print" data-toolbar role="group" aria-label="Page actions">
                <?php if (isAdmin()): ?>
                    <button onclick="toggleAutoRetrain()" id="autoRetrainBtn" class="btn <?= $autoRetrainOn ? 'btn-success' : 'btn-secondary' ?>"><?= $autoRetrainOn ? 'Auto-Retrain: ON' : 'Auto-Retrain: OFF' ?></button>
                    <button onclick="retrainModel()" id="retrainBtn" class="btn btn-primary">Retrain Model</button>
                    <?php endif; ?>
            </div></div>

            

            <?php if ($demandIsStale): ?>
            <div class="alert alert-warning">
                <strong>Your sales records stop <?= formatDate($lastSample) ?> &mdash; <?= $daysSinceSale ?> days ago.</strong>
                Forecasts below can only match on weekday, because the model has no recent
                7/14/30-day demand to compare against. Confidence is marked down for that reason.
                Record today's sales to bring it back up to strength.
            </div>
            <?php endif; ?>

            <section class="card forecast-overview" aria-labelledby="forecastOverviewTitle">
                <div class="card-body">
                    <div class="forecast-overview-heading">
                        <div><h2 id="forecastOverviewTitle">Today's outlook</h2><p>Estimated demand for <?= date('l, F j, Y') ?></p></div>
                        <span class="forecast-freshness"><?= e_icon('clock', 16) ?><?= htmlspecialchars($freshnessLabel) ?></span>
                    </div>
                    <div class="fc-layout">
                        <div>
                            <div class="fc-hero-figures">
                                <div class="forecast-stat">
                                    <span class="forecast-stat-icon forecast-stat-trays"><?= e_icon('tray', 26) ?></span>
                                    <div><span class="forecast-stat-label">Expected trays</span><strong class="forecast-stat-value"><?= number_format($totalTraysWhole) ?></strong><span class="forecast-stat-caption">Combined demand across sizes</span></div>
                                </div>
                                <div class="forecast-stat">
                                    <span class="forecast-stat-icon forecast-stat-eggs"><?= e_icon('egg', 26) ?></span>
                                    <div><span class="forecast-stat-label">Individual eggs</span><strong class="forecast-stat-value"><?= number_format($totalEggs) ?></strong><span class="forecast-stat-caption">Estimated pieces to prepare</span></div>
                                </div>
                                <div class="forecast-stat">
                                    <span class="forecast-stat-icon forecast-stat-sales"><?= e_icon('banknote', 26) ?></span>
                                    <div><span class="forecast-stat-label">Projected sales</span><strong class="forecast-stat-value"><?= formatCurrency($totalSales) ?></strong><span class="forecast-stat-caption">Based on predicted demand</span></div>
                                </div>
                            </div>

                            <?php if (!empty($categoryRows)): ?>
                            <div class="fc-cats">
                                <span class="fc-cats-label">
                                    Demand by category
                                    <span class="fc-cats-hint">Select a category to view egg sizes</span>
                                </span>
                                <?php foreach ($categoryRows as $key => $cat): ?>
                                <details class="fc-cat">
                                    <summary>
                                        <span class="fc-cat-dot fc-cat-<?= htmlspecialchars($key) ?>"></span>
                                        <span class="fc-cat-name"><?= htmlspecialchars($cat['label']) ?></span>
                                        <span class="fc-cat-count"><?= count($cat['size_rows']) ?> <?= plural(count($cat['size_rows']), 'size', 'sizes') ?></span>
                                        <span class="fc-cat-trays"><?= number_format($cat['trays_whole']) ?> <small><?= plural($cat['trays_whole'], 'tray', 'trays') ?></small></span>
                                        <?= e_icon('chevron-right', 16, 'fc-cat-chevron') ?>
                                    </summary>
                                    <div class="fc-cat-sizes">
                                        <?php foreach ($cat['size_rows'] as $code => $sizeRow): ?>
                                        <span class="fc-cat-size">
                                            <span class="fc-cat-size-code" style="background:<?= getEggColorClass($code) ?>"><?= $code ?></span>
                                            <span class="fc-cat-size-name"><?= htmlspecialchars($sizeRow['label']) ?></span>
                                            <span class="fc-cat-size-trays"><?= number_format($sizeRow['trays_whole']) ?></span>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="forecast-waiting"><?= e_icon('info', 18) ?>Record sales to build a demand forecast. These totals will update as sales history becomes available.</p>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($shortages)): ?>
                        <div class="plain-action-box fc-restock">
                            <strong>Restock before you sell</strong>
                            <ul>
                                <?php foreach ($shortages as $s): ?>
                                <li>
                                    <span class="size-badge" style="background:<?= getEggColorClass($s['size']) ?>"><?= $s['label'] ?></span>
                                    short <strong><?= number_format($s['short']) ?> eggs</strong>
                                    &asymp; <?= $s['trays_to_buy'] ?> <?= plural($s['trays_to_buy'], 'tray', 'trays') ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php elseif ($totalTrays > 0): ?>
                        <div class="plain-action-box plain-action-ok fc-restock">
                            <strong>Stock looks sufficient</strong>
                            Everything you expect to sell today is already in stock.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="card">
                <div class="card-header"><h3>Forecast by Egg Size</h3></div>
                <div class="card-body">
<table class="table fc-table">
                            <thead>
                                <tr>
                                    <th>Size</th>
                                    <th class="mt-val">Expect</th>
                                    <th class="mt-val">Sales</th>
                                    <th class="mt-val">You have</th>
                                    <th>Sure</th>
                                    <th>Verdict</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($eggSizes)): ?>
                            <tr><td colspan="6"><div class="empty-state"><strong>No data yet</strong><p>Configured egg sizes will appear here.</p></div></td></tr>
                            <?php endif; ?>
                        <?php foreach ($eggSizes as $es): ?>
                        <?php
                            $size = $es['size_code'];
                            $pred = $predictions[$size] ?? null;
                            $stock = $stockBySize[$size] ?? ['eggs' => 0, 'trays' => 0];
                            if (!$pred) continue;
                            $read = describePrediction($pred, $stock['eggs'], $stock['trays']);
                            // The predictor already lowers confidence when a size's own sales
                            // records have a long gap, and the page lowered High a second time
                            // using the single global freshness check. Use the predictor's own
                            // level (one rule, applied once) and say out loud when it was cut.
                            $levelKey = $pred['confidence_level'] ?? 'low';
                            $wasCut = !empty($pred['confidence_downgraded']);
                            $gapDays = $pred['days_since_last_sale'] ?? null;
                        ?>
                        <tr>
                            <td><span class="size-badge" style="background:<?= getEggColorClass($size) ?>"><?= getEggSizeLabel($size) ?></span></td>
                            <?php if (!empty($pred['is_no_data'])): ?>
                            <td colspan="2" style="color:var(--gray-500);font-style:italic;"><?= htmlspecialchars($pred['message']) ?></td>
                            <?php else: ?>
                            <td class="mt-val">
                                <strong><?= $pred['predicted_trays'] ?></strong> <?= plural((int)$pred['predicted_trays'], 'tray', 'trays') ?>
                                <div class="plain-sub"><?= number_format($pred['predicted_eggs'] ?? 0) ?> eggs</div>
                            </td>
                            <td class="mt-val"><?= formatCurrency($pred['predicted_sales'] ?? 0) ?></td>
                            <?php endif; ?>
                            <td class="mt-val"><?= number_format($stock['eggs']) ?> eggs<div class="plain-sub"><?= $stock['trays'] ?> <?= plural($stock['trays'], 'tray', 'trays') ?></div></td>
                            <td>
                                <?php if (!empty($pred['is_no_data'])): ?>
                                <span class="plain-verb plain-verb-muted">Record sales</span>
                                <?php else: ?>
                                <?php
                                    $confidenceLabels = [
                                        'high' => 'High confidence',
                                        'medium' => 'Medium confidence',
                                        'low' => 'Low confidence',
                                        'none' => 'Not enough data'
                                    ];
                                    $levelKey = $pred['confidence_level'] ?? 'low';
                                    $wasCut = !empty($pred['confidence_downgraded']);
                                    $gapDays = $pred['days_since_last_sale'] ?? null;
                                ?>
                                <span class="confidence-badge confidence-<?= $levelKey ?>"><?= $confidenceLabels[$levelKey] ?? ucfirst($levelKey) ?></span>
                                <div class="plain-sub">
                                    <?= number_format($pred['samples_used'] ?? 0) ?> days<?= $wasCut ? ' &middot; cut' : '' ?>
                                </div>
                                <?php if ($wasCut): ?>
                                <div class="plain-sub fc-why">
                                    <?= $gapDays !== null ? (int)$gapDays . 'd since last sale' : 'stale records' ?>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($pred['is_no_data'])): ?>
                                <span class="plain-verb plain-verb-muted">&mdash;</span>
                                <?php elseif ($read['tone'] === 'danger'): ?>
                                <span class="plain-verb plain-verb-danger">Restock needed</span>
                                <?php elseif ($read['tone'] === 'warning'): ?>
                                <span class="plain-verb plain-verb-warning">Order soon</span>
                                <?php else: ?>
                                <span class="plain-verb plain-verb-success">Well stocked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <details class="plain-details">
                        <summary>How the forecast works</summary>
                        <div class="plain-details-body">
                            <p>For each egg size, the model finds past days that match today (same weekday, similar recent sales) and averages what was sold.</p>
                            <p><strong>Confidence</strong> = how many past days it learned from: 30+ days = High, 10-29 = Medium, less = Low. It drops one level if that size hasn't sold in 30+ days.</p>
                            <p><strong>Stock Status</strong> compares expected sales against your stock: "Well stocked" = covered, "Order soon" = running low, "Restock needed" = will run out.</p>
                        </div>
                    </details>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3>Model Accuracy</h3></div>
                <div class="card-body">
                    <p class="plain-lede"><?= htmlspecialchars($accuracySentence) ?></p>

                    <table class="table metric-table">
                        <thead>
                            <tr>
                                <th style="width:24%;">Metric</th>
                                <th>What it measures</th>
                                <th class="mt-val" style="width:12%;">Value</th>
                                <th class="mt-val" style="width:11%;">Target</th>
                                <th class="mt-status" style="width:11%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $verdictText = [
                                'green' => 'Good',
                                'yellow' => 'Watch',
                                'red' => 'Weak'
                            ];

                            $metricRows = [
                                'How close the forecast was',
                                ['Hit Rate', 'accuracy', 'Within ±1 tray',
                                    'Forecast was within 1 tray of actual.',
                                    $overallMetrics['accuracy'], '%', '&ge; 90%', metricStatus($overallMetrics['accuracy'])],

                                'Direction — did it overshoot or undershoot?',
                                ['Does Not Over-Predict', 'precision', 'No over-prediction',
                                    'Stayed at or below actual.',
                                    $overallMetrics['precision'], '%', '&ge; 90%', metricStatus($overallMetrics['precision'])],
                                ['Does Not Under-Predict', 'recall', 'No under-prediction',
                                    'Reached at least actual.',
                                    $overallMetrics['recall'], '%', '&ge; 90%', metricStatus($overallMetrics['recall'])],
                                ['Balanced Score', 'f1_score', 'Balance of both',
                                    'Balance of the two above.',
                                    $overallMetrics['f1'], '%', '&ge; 90%', metricStatus($overallMetrics['f1'])],

                                ];
                            ?>

                            <?php foreach ($metricRows as $row):
                                if (is_string($row)): ?>
                                    <tr class="mt-group"><td colspan="5"><?= htmlspecialchars($row) ?></td></tr>
                                <?php else:
                                    [$term, $key, $alias, $def, $figure, $unit, $target, $status] = $row;
                                    $shown = $unit === '' ? rtrim(rtrim(number_format((float)$figure, 4), '0'), '.') : number_format((float)$figure, ($unit === '%' || $unit === 'trays') ? 1 : 2);
                                    if ($unit === 'trays' && (float)$figure == (int)(float)$figure) $shown = number_format((float)$figure, 1);
                                ?>
                                    <tr>
                                        <td>
                                            <span class="mt-name">
                                                <span class="mt-term"><?= $term ?></span>
                                                <span class="mt-alias"><?= $alias ?></span>
                                            </span>
                                        </td>
                                        <td><span class="mt-def"><?= $def ?></span></td>
                                        <td class="mt-val">
                                            <span class="mt-figure"><?= $shown ?></span><span class="mt-figure-unit"><?= htmlspecialchars($unit) ?></span>
                                        </td>
                                        <td class="mt-val"><span class="mt-target"><?= $target ?></span></td>
                                        <td class="mt-status">
                                            <span class="mt-verdict mt-verdict-<?= $status ?>"><?= $verdictText[$status] ?? $status ?></span>
                                        </td>
                                    </tr>
                                <?php endif;
                            endforeach; ?>
                        </tbody>
                    </table>

                    <details class="plain-details">
                        <summary>How we measure this</summary>
                        <div class="plain-details-body">
                            <p>We replay the forecast on every past day using only data before that day. This is a true test — no cheating by looking at future sales.</p>
                            <p><strong>Hit Rate</strong>: Within 1 tray of what was actually sold.</p>
                            <p><strong>Does Not Over-Predict</strong>: Forecast stayed at or below actual (protects against wasted stock).</p>
                            <p><strong>Does Not Under-Predict</strong>: Forecast reached at least actual (protects against lost sales).</p>
                            <p><strong>Balanced Score</strong>: Balance of both. Above 80% means you can trust the forecast for ordering.</p>
                        </div>
                    </details>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3>Model Status <span class="plain-sub" style="display:inline;">(admin)</span></h3></div>
                <div class="card-body">
                    <div class="model-info-grid">
                        <div class="model-info-item">
                            <span class="label">Version:</span>
                            <span><?= ($activeModel && isset($activeModel['version_name'])) ? $activeModel['version_name'] : 'Not trained' ?></span>
                        </div>
                        <div class="model-info-item">
                            <span class="label">Last trained:</span>
                            <span><?= ($activeModel && !empty($activeModel['training_date'])) ? formatDateTime($activeModel['training_date']) : 'N/A' ?></span>
                        </div>
                        <div class="model-info-item">
                            <span class="label">Accuracy:</span>
                            <span><?= number_format($overallMetrics['accuracy'], 2) ?>%</span>
                        </div>
                        <div class="model-info-item">
                            <span class="label">Trading days:</span>
                            <span><?= number_format($distinctDays) ?></span>
                        </div>
                        <div class="model-info-item">
                            <span class="label">Data through:</span>
                            <span><?= $lastSample ? formatDate($lastSample) : 'None' ?></span>
                        </div>
                        <div class="model-info-item">
                            <span class="label">Sales records:</span>
                            <span><?= number_format((int)($db->fetchOne("SELECT COUNT(*) c FROM sales_history")['c'] ?? 0)) ?> rows</span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (isAdmin()): ?>
            <div class="card">
                <div class="card-header"><h3>Per-Size Breakdown <span class="plain-sub" style="display:inline;">(for admin reference)</span></h3></div>
                <div class="card-body">
                    <div class="dashboard-grid">
                        <?php foreach ($metrics as $size => $m): ?>
                        <div class="card metric-card">
                            <div class="card-header">
                                <h4><?= getEggSizeLabel($size) ?> (<?= $size ?>)</h4>
                                <span class="gauge gauge-<?= $m['accuracy_status'] ?? 'red' ?>"><?= $m['accuracy'] ?? 0 ?>%</span>
                            </div>
                            <div class="card-body">
                                <?php if (isset($m['message'])): ?>
                                    <div class="empty-state"><strong>No data yet</strong><p><?= $m['message'] ?></p></div>
                                <?php else: ?>
                                    <p class="plain-lede" style="font-size:12.5px;">
                                        <?= $m['recall'] >= 95 ? 'Rarely under-predicts.' : ($m['recall'] >= 85 ? 'Sometimes under-predicts on busy days.' : 'Often under-predicts &mdash; watch for stockouts.') ?>
                                    </p>
                                <div class="metrics-grid">
<div class="metric-item">
                                                        <span class="metric-label">Hit Rate</span>
                                                        <span class="metric-value gauge-<?= $m['accuracy_status'] ?>"><?= $m['accuracy'] ?>%</span>
                                                    </div>
                                                    <div class="metric-item">
                                                        <span class="metric-label">No Over-Predict</span>
                                                        <span class="metric-value gauge-<?= $m['precision_status'] ?>"><?= $m['precision'] ?>%</span>
                                                    </div>
                                                    <div class="metric-item">
                                                        <span class="metric-label">No Under-Predict</span>
                                                        <span class="metric-value gauge-<?= $m['recall_status'] ?>"><?= $m['recall'] ?>%</span>
                                                    </div>
                                                    <div class="metric-item">
                                                        <span class="metric-label">Balanced Score</span>
                                                        <span class="metric-value gauge-<?= $m['f1_status'] ?>"><?= $m['f1_score'] ?>%</span>
                                                    </div>
                                </div>
                                <details class="plain-details" style="margin-top:10px;">
                                    <summary>How these were calculated</summary>
                                    <div class="plain-details-body">
                                        <?php if (!empty($m['calculation_details'])): ?>
                                        <?php $cd = $m['calculation_details']; ?>
                                        <div class="calculation-details" style="display:block;margin-top:0;">
                                            <p><?= $cd['accuracy_formula'] ?></p>
                                            <p><?= $cd['precision_formula'] ?></p>
                                            <p><?= $cd['recall_formula'] ?></p>
                                            <p><?= $cd['f1_formula'] ?></p>
                                            <hr style="margin:8px 0;border-color:var(--gray-200);">
                                            <p>Samples scored: <?= $m['sample_count'] ?></p>
                                            <p>Direction breakdown: within &plusmn;1 tray <strong><?= $m['confusion_matrix']['within_tolerance'] ?></strong> | over-predicted <strong><?= $m['confusion_matrix']['over_predicted'] ?></strong> | under-predicted <strong><?= $m['confusion_matrix']['under_predicted'] ?></strong></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    async function retrainModel() {
        if (!await confirmAction('Retrain the KNN model with the latest data. This may take a moment.', { title: 'Retrain model?', confirmLabel: 'Retrain model' })) return;
        var btn = document.querySelector('#retrainBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Retraining...'; }
        fetch('../api/predictions/retrain.php', { method: 'POST' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            // Check success before touching d.mape -- doing the maths first printed
            // "Accuracy: NaN%" on every failure.
            if (!d.success) {
                if (btn) { btn.disabled = false; btn.textContent = 'Retrain Model'; }
                showToast('Retrain failed', 'error', { message: d.error || 'Unknown error', duration: 9000 });
                return;
            }
            var accuracy = (100 - Number(d.mape)).toFixed(2);
            showToast('Model retrained', 'success', {
                message: d.version + ' \u00B7 ' + accuracy + '% accuracy \u00B7 K=' + d.k
            });
            setTimeout(function() { location.reload(); }, 1200);
        })
        .catch(function(err) {
            if (btn) { btn.disabled = false; btn.textContent = 'Retrain Model'; }
            showToast('Retrain failed', 'error', {
                message: 'The server did not respond. Try again in a moment.', duration: 9000
            });
            console.error(err);
        });
    }

    function toggleAutoRetrain() {
        var btn = document.getElementById('autoRetrainBtn');
        if (btn) btn.disabled = true;
        fetch('../api/predictions/toggle_auto_retrain.php', { method: 'POST' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                if (btn) btn.disabled = false;
                showToast('Could not change the setting', 'error', { message: d.error || 'Unknown error' });
                return;
            }
            // Reflect the stored state immediately, then reload to pick up
            // everything that depends on it.
            showToast('Auto-Retrain ' + (d.auto_retrain ? 'ON' : 'OFF'), 'success', {
                message: d.auto_retrain
                    ? 'The model will retrain itself once a week.'
                    : 'Automatic retraining is paused.'
            });
            setTimeout(function() { location.reload(); }, 900);
        })
        .catch(function(err) {
            if (btn) btn.disabled = false;
            showToast('Could not change the setting', 'error', { message: 'The server did not respond.', duration: 9000 });
            console.error(err);
        });
    }
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>

