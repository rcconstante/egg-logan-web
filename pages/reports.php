<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/KNNPredictor.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$db = Database::getInstance();
$knn = new KNNPredictor();

function validReportDate($value, $fallback) {
    if (!is_string($value) || $value === '') return $fallback;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return $fallback;
    return $value;
}

// Anchor reports to the latest day that actually has sales so the charts are never empty.
$lastSaleDay = $knn->latestSampleDate() ?? date('Y-m-d');
$dateTo = validReportDate($_GET['to'] ?? null, $lastSaleDay);
$dateFrom = validReportDate($_GET['from'] ?? null, date('Y-m-d', strtotime($dateTo . ' -29 days')));
if ($dateFrom > $dateTo) {
    $swap = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $swap;
}
$dateFromEsc = htmlspecialchars($dateFrom, ENT_QUOTES);
$dateToEsc = htmlspecialchars($dateTo, ENT_QUOTES);

$salesSummary = $db->fetchAll(
    "SELECT sh.egg_size, es.size_label, es.category,
            SUM(sh.trays_sold) as total_trays,
            SUM(sh.individual_eggs_sold) as total_eggs,
            SUM(sh.total_amount) as total_amount,
            COUNT(*) as sale_count
     FROM sales_history sh
     JOIN egg_sizes es ON sh.egg_size = es.size_code
     WHERE sh.sale_date BETWEEN ? AND ?
     GROUP BY sh.egg_size, es.size_label, es.category
     ORDER BY es.size_code",
    [$dateFrom, $dateTo]
);

$salesBySize = $db->fetchAll(
    "SELECT es.size_label, SUM(sh.trays_sold * es.tray_capacity + sh.individual_eggs_sold) as total_qty,
            SUM(sh.total_amount) as total_amount, COUNT(sh.id) as sale_count
     FROM sales_history sh
     JOIN egg_sizes es ON sh.egg_size = es.size_code
     WHERE sh.sale_date BETWEEN ? AND ?
     GROUP BY es.size_code, es.size_label
     ORDER BY es.size_code",
    [$dateFrom, $dateTo]
);

$wasteSummary = $db->fetchAll(
    "SELECT s.size_label, s.size_code, s.category, COUNT(d.id) as waste_count, COALESCE(SUM(d.quantity),0) as total_waste
     FROM egg_sizes s
     LEFT JOIN damaged_log d ON d.tray_id IN (
         SELECT t.tray_id FROM egg_trays t WHERE t.egg_size = s.size_code
     ) AND DATE(d.created_at) BETWEEN ? AND ?
     GROUP BY s.size_code, s.size_label, s.category",
    [$dateFrom, $dateTo]
);

$wasteSummary = array_values(array_filter($wasteSummary, static fn($row) => (int)$row['total_waste'] > 0 || (int)$row['waste_count'] > 0));

$wasteByCategory = ['fresh' => 0, 'salted' => 0];
foreach ($wasteSummary as $w) {
    $cat = $w['category'] ?? 'fresh';
    if (isset($wasteByCategory[$cat])) $wasteByCategory[$cat] += (int)$w['total_waste'];
}

$fifoCompliance = $db->fetchOne(
    "SELECT COUNT(*) as total_violations FROM system_alerts WHERE alert_type = 'fifo_violation' AND DATE(created_at) BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EggLogan - Reports</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime('../assets/css/style.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
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
                <span class="page-eyebrow">Business insights</span>
                <h1>Reports</h1>
                <p>Review sales, inventory, and operational reports.</p>
            <div class="page-actions no-print" data-toolbar role="group" aria-label="Page actions">
                <select aria-label="Export CSV" data-control-icon="upload" data-export-select onchange="if(this.value){exportCSV(this.value); this.value='';}">
                        <option value="" hidden>Export CSV</option>
                        <option value="sales">Sales</option>
                        <option value="inventory">Inventory</option>
                        <option value="wastage">Wastage</option>
                        <option value="fifo">FIFO / Sales Log</option>
                        <?php if (isAdmin()): ?><option value="activity">Activity Log</option><?php endif; ?>
                    </select>
                    <button onclick="window.print()" class="btn btn-secondary">Print</button>
            </div></div>

            

            <div class="stats-grid" style="margin-bottom:20px;">
                <div class="stat-card">
                    <span class="stat-ico ico-primary"><?= e_icon('banknote', 22) ?></span>
                    <div class="stat-label">Total Period Sales</div>
                    <div class="stat-value">
                        <?= formatCurrency(array_sum(array_column($salesSummary, 'total_amount'))) ?>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-ico ico-primary"><?= e_icon('tray', 22) ?></span>
                    <div class="stat-label">Trays Sold</div>
                    <div class="stat-value">
                        <?= number_format(array_sum(array_column($salesSummary, 'total_trays'))) ?>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-ico ico-primary"><?= e_icon('egg', 22) ?></span>
                    <div class="stat-label">Loose Eggs Sold</div>
                    <div class="stat-value">
                        <?= number_format(array_sum(array_column($salesSummary, 'total_eggs'))) ?>
                    </div>
                </div>
                <div class="stat-card <?= $fifoCompliance['total_violations'] > 0 ? 'stat-warning' : 'stat-success' ?>">
                    <span class="stat-ico ico-primary"><?= e_icon('clipboard', 22) ?></span>
                    <div class="stat-label">FIFO Violations</div>
                    <div class="stat-value"><?= $fifoCompliance['total_violations'] ?></div>
                </div>
            </div>

            <!-- Chart 1: Historical Performance -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <h3>Sales Performance & Historical Analysis</h3>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <select id="chartCategory" aria-label="Sales chart category" onchange="switchCategory(this.value)" class="chart-category-select">
                            <option value="">All</option>
                            <optgroup label="Categories">
                                <option value="cat:fresh">Fresh Eggs</option>
                                <option value="cat:salted">Salted Eggs</option>
                            </optgroup>
                            <optgroup label="Sizes">
                                <option value="size:SS">Super Small (SS)</option>
                                <option value="size:SM">Small (SM)</option>
                                <option value="size:MD">Medium (MD)</option>
                                <option value="size:LG">Large (LG)</option>
                                <option value="size:XL">Extra Large (XL)</option>
                                <option value="size:JB">Jumbo (JB)</option>
                                <option value="size:SA">Salted (SA)</option>
                            </optgroup>
                        </select>
                        <div class="chart-period-toggle">
                            <button class="period-btn active" data-period="daily" onclick="switchPeriod('daily', this)">Daily</button>
                            <button class="period-btn" data-period="weekly" onclick="switchPeriod('weekly', this)">Weekly</button>
                            <button class="period-btn" data-period="monthly" onclick="switchPeriod('monthly', this)">Monthly</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                    <div class="chart-legend-custom">
                        <span class="legend-item"><span class="legend-dot" style="background:#E67E22;"></span>Actual Sales</span>
                        <span class="legend-item"><span class="legend-dot" style="background:#237A57;"></span>Sales Predictions</span>
                        <span class="legend-item"><span class="legend-dot" style="background:#2A6F97;"></span>Predicted Egg Volume</span>
                        <span class="legend-item"><span class="legend-dot" style="background:#8E44AD; border:2px solid #8E44AD; border-radius:50%;"></span>Actual Egg Volume</span>
                    </div>
                </div>
            </div>

            <!-- Chart 2: Future Demand Predictions Only -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <h3>Egg Demand Forecast</h3>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <select id="futureChartCategory" aria-label="Forecast category" onchange="switchFutureCategory(this.value)" class="chart-category-select">
                            <option value="">All</option>
                            <optgroup label="Categories">
                                <option value="cat:fresh">Fresh Eggs</option>
                                <option value="cat:salted">Salted Eggs</option>
                            </optgroup>
                            <optgroup label="Sizes">
                                <option value="size:SS">Super Small (SS)</option>
                                <option value="size:SM">Small (SM)</option>
                                <option value="size:MD">Medium (MD)</option>
                                <option value="size:LG">Large (LG)</option>
                                <option value="size:XL">Extra Large (XL)</option>
                                <option value="size:JB">Jumbo (JB)</option>
                                <option value="size:SA">Salted (SA)</option>
                            </optgroup>
                        </select>
                        <div class="chart-period-toggle">
                            <button class="period-btn active" data-future-period="daily" onclick="switchFuturePeriod('daily', this)">Daily</button>
                            <button class="period-btn" data-future-period="weekly" onclick="switchFuturePeriod('weekly', this)">Weekly</button>
                            <button class="period-btn" data-future-period="monthly" onclick="switchFuturePeriod('monthly', this)">Monthly</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="futureDemandChart"></canvas>
                    </div>
                    <div class="chart-legend-custom">
                        <span class="legend-item"><span class="legend-dot" style="background:#2A6F97;"></span>Predicted Egg Demand (pcs)</span>
                        <span class="legend-item"><span class="legend-dot" style="background:#E67E22;"></span>Predicted Sales</span>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header"><h3>Sales by Egg Size</h3></div>
                    <div class="card-body">
                        <table class="table">
                            <thead><tr><th>Size</th><th>Qty Sold</th><th>Amount</th><th>Transactions</th></tr></thead>
                            <tbody>
                            <?php if (empty($salesBySize)): ?>
                            <tr><td colspan="4"><div class="empty-state"><strong>No data yet</strong><p>Completed sales will appear here.</p></div></td></tr>
                            <?php endif; ?>
                                <?php foreach ($salesBySize as $s): ?>
                                <tr>
                                    <td><?= $s['size_label'] ?></td>
                                    <td><?= number_format($s['total_qty']) ?> pcs</td>
                                    <td><?= formatCurrency($s['total_amount']) ?></td>
                                    <td><?= $s['sale_count'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Waste Report (by Category)</h3></div>
                    <div class="card-body">
                        <div class="stats-grid waste-summary" style="margin-bottom:15px;">
                            <div class="stat-card stat-warning">
                                <span class="stat-ico ico-primary"><?= e_icon('egg', 22) ?></span>
                    <div class="stat-label">Fresh Egg Waste</div>
                                <div class="stat-value"><?= number_format($wasteByCategory['fresh']) ?></div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-ico ico-primary"><?= e_icon('egg', 22) ?></span>
                    <div class="stat-label">Salted Egg Waste</div>
                                <div class="stat-value"><?= number_format($wasteByCategory['salted']) ?></div>
                            </div>
                        </div>
                        <table class="table">
                            <thead><tr><th>Size</th><th>Category</th><th>Incidents</th><th>Total Waste</th></tr></thead>
                            <tbody>
                            <?php if (empty($wasteSummary)): ?>
                            <tr><td colspan="4"><div class="empty-state"><strong>No data yet</strong><p>Recorded wastage will appear here.</p></div></td></tr>
                            <?php endif; ?>
                                <?php foreach ($wasteSummary as $w): ?>
                                <tr>
                                    <td><?= $w['size_label'] ?></td>
                                    <td><?= ucfirst($w['category'] ?? 'fresh') ?></td>
                                    <td><?= $w['waste_count'] ?></td>
                                    <td><?= $w['total_waste'] ?> pcs</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="../assets/js/main.js?v=<?= filemtime('../assets/js/main.js') ?>"></script>
    <script>
    var performanceChart = null;
    var futureDemandChart = null;
    var currentPeriod = 'daily';
    var currentCategory = '';
    var futurePeriod = 'daily';
    var futureCategory = '';

    function loadHistoricalChart() {
        var url = '../api/reports/chart_data.php?type=historical&from=<?= $dateFromEsc ?>&to=<?= $dateToEsc ?>&period=' + encodeURIComponent(currentPeriod);
        if (currentCategory) {
            if (currentCategory.indexOf('cat:') === 0) {
                url += '&category=' + encodeURIComponent(currentCategory.substring(4));
            } else if (currentCategory.indexOf('size:') === 0) {
                url += '&size=' + encodeURIComponent(currentCategory.substring(5));
            }
        }
        fetch(url)
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) { renderHistoricalChart(data); })
        .catch(function(err) { showChartMessage('performanceChart', 'Failed to load sales chart: ' + err.message, true); });
    }

    function loadFutureChart() {
        var url = '../api/reports/chart_data.php?type=future&future_period=' + encodeURIComponent(futurePeriod);
        if (futureCategory) {
            if (futureCategory.indexOf('cat:') === 0) {
                url += '&future_category=' + encodeURIComponent(futureCategory.substring(4));
            } else if (futureCategory.indexOf('size:') === 0) {
                url += '&future_size=' + encodeURIComponent(futureCategory.substring(5));
            }
        }
        fetch(url)
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) { renderFutureChart(data); })
        .catch(function(err) { showChartMessage('futureDemandChart', 'Failed to load forecast chart: ' + err.message, true); });
    }

    function showChartMessage(canvasId, text, isError) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var holder = canvas.parentElement;
        var box = holder.querySelector('.chart-message');
        if (!box) {
            box = document.createElement('div');
            box.className = 'chart-message';
            holder.appendChild(box);
        }
        box.textContent = text;
        box.style.display = 'flex';
        box.classList.toggle('chart-message-error', !!isError);
        if (isError) canvas.style.visibility = 'hidden';
    }

    function clearChartMessage(canvasId) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        canvas.style.visibility = 'visible';
        var box = canvas.parentElement.querySelector('.chart-message');
        if (box) box.style.display = 'none';
    }

    function renderHistoricalChart(data) {
        if (!data || !data.labels || !data.labels.length) {
            showChartMessage('performanceChart', 'No sales recorded in the selected range.', false);
            return;
        }
        if (data.message) {
            showChartMessage('performanceChart', data.message, false);
        } else {
            clearChartMessage('performanceChart');
        }
        var ctx1 = document.getElementById('performanceChart').getContext('2d');
        if (performanceChart) performanceChart.destroy();
        var css = getComputedStyle(document.documentElement);
        var gray200 = css.getPropertyValue('--gray-200').trim() || '#E9E4DD';
        var gray600 = css.getPropertyValue('--gray-600').trim() || '#77685A';

        performanceChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Actual Sales',
                        data: data.actual_sales,
                        borderColor: '#E67E22',
                        backgroundColor: 'rgba(230,126,34,0.08)',
                        borderWidth: 2.5,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#E67E22',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Sales Predictions',
                        data: data.predicted_sales,
                        borderColor: '#237A57',
                        backgroundColor: 'rgba(35,122,87,0.06)',
                        borderWidth: 2,
                        borderDash: [6, 3],
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#237A57',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Predicted Egg Volume',
                        data: data.predicted_volume,
                        borderColor: '#2A6F97',
                        backgroundColor: 'rgba(42,111,151,0.06)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#2A6F97',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Actual Egg Volume',
                        data: data.actual_volume,
                        borderColor: '#8E44AD',
                        borderDash: [8, 4],
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#8E44AD',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointStyle: 'triangle',
                        tension: 0.35,
                        fill: false,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(37,30,24,0.92)',
                        titleColor: '#fff',
                        bodyColor: '#F3F0EB',
                        borderColor: 'rgba(230,126,34,0.3)',
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: 12,
                        titleFont: { family: "'Manrope', sans-serif", weight: '700', size: 13 },
                        bodyFont: { family: "'Manrope', sans-serif", size: 12 },
                        callbacks: {
                            label: function(ctx) {
                                var val = ctx.parsed.y;
                                if (ctx.datasetIndex < 2) {
                                    return ctx.dataset.label + ': \u20B1' + val.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                                return ctx.dataset.label + ': ' + val.toLocaleString() + ' eggs';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gray200, drawBorder: false },
                        ticks: { color: gray600, font: { family: "'Manrope', sans-serif", size: 11, weight: '600' }, maxRotation: 45 }
                    },
                    y: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: 'Sales Revenue (\u20B1)', color: gray600, font: { family: "'Manrope', sans-serif", size: 12, weight: '700' } },
                        grid: { color: gray200, drawBorder: false },
                        ticks: {
                            color: gray600,
                            font: { family: "'Manrope', sans-serif", size: 11 },
                            callback: function(v) { return '\u20B1' + v.toLocaleString(); }
                        },
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: 'Egg Volume', color: '#2A6F97', font: { family: "'Manrope', sans-serif", size: 12, weight: '700' } },
                        grid: { drawOnChartArea: false },
                        ticks: {
                            color: '#2A6F97',
                            font: { family: "'Manrope', sans-serif", size: 11 },
                            callback: function(v) { return v.toLocaleString(); }
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function renderFutureChart(data) {
        if (!data || !data.future_labels || !data.future_labels.length) {
            showChartMessage('futureDemandChart', 'No forecast available for the selected filter.', false);
            return;
        }
        if (data.message) {
            showChartMessage('futureDemandChart', data.message, false);
        } else {
            clearChartMessage('futureDemandChart');
        }
        var ctx2 = document.getElementById('futureDemandChart').getContext('2d');
        if (futureDemandChart) futureDemandChart.destroy();
        var css = getComputedStyle(document.documentElement);
        var gray200 = css.getPropertyValue('--gray-200').trim() || '#E9E4DD';
        var gray600 = css.getPropertyValue('--gray-600').trim() || '#77685A';

        futureDemandChart = new Chart(ctx2, {
            type: 'line',
            data: {
                labels: data.future_labels,
                datasets: [
                    {
                        label: 'Predicted Egg Demand',
                        data: data.future_demand,
                        borderColor: '#2A6F97',
                        backgroundColor: 'rgba(42,111,151,0.12)',
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#2A6F97',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Predicted Sales',
                        data: data.future_sales,
                        borderColor: '#E67E22',
                        backgroundColor: 'rgba(230,126,34,0.06)',
                        borderWidth: 2.5,
                        borderDash: [6, 3],
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#E67E22',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(37,30,24,0.92)',
                        titleColor: '#fff',
                        bodyColor: '#F3F0EB',
                        borderColor: 'rgba(42,111,151,0.3)',
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: 12,
                        titleFont: { family: "'Manrope', sans-serif", weight: '700', size: 13 },
                        bodyFont: { family: "'Manrope', sans-serif", size: 12 },
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.parsed.y === null || isNaN(ctx.parsed.y)) return ctx.dataset.label + ': n/a';
                                if (ctx.dataset.label === 'Predicted Sales') {
                                    return ctx.dataset.label + ': \u20B1' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                                return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() + ' eggs';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gray200, drawBorder: false },
                        ticks: { color: gray600, font: { family: "'Manrope', sans-serif", size: 11, weight: '600' }, maxRotation: 45 }
                    },
                    y: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: 'Predicted Egg Demand (pcs)', color: '#2A6F97', font: { family: "'Manrope', sans-serif", size: 12, weight: '700' } },
                        grid: { color: gray200, drawBorder: false },
                        ticks: {
                            color: '#2A6F97',
                            font: { family: "'Manrope', sans-serif", size: 11 },
                            callback: function(v) { return v.toLocaleString(); }
                        },
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: 'Predicted Sales (\u20B1)', color: '#E67E22', font: { family: "'Manrope', sans-serif", size: 12, weight: '700' } },
                        grid: { drawOnChartArea: false },
                        ticks: {
                            color: '#E67E22',
                            font: { family: "'Manrope', sans-serif", size: 11 },
                            callback: function(v) { return '\u20B1' + v.toLocaleString(); }
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    function switchPeriod(period, btn) {
        document.querySelectorAll('[data-period]').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        currentPeriod = period;
        loadHistoricalChart();
    }

    function switchCategory(category) {
        currentCategory = category;
        loadHistoricalChart();
    }

    function switchFuturePeriod(period, btn) {
        document.querySelectorAll('[data-future-period]').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        futurePeriod = period;
        loadFutureChart();
    }

    function switchFutureCategory(category) {
        futureCategory = category;
        loadFutureChart();
    }

    loadHistoricalChart();
    loadFutureChart();

    function exportCSV(type) {
        window.location.href = '../api/reports/export_csv.php?from=<?= $dateFromEsc ?>&to=<?= $dateToEsc ?>&type=' + encodeURIComponent(type || 'sales');
    }
    </script>
    <script src="../assets/js/controls.js?v=<?= filemtime(__DIR__ . '/../assets/js/controls.js') ?>"></script>
    <script src="../assets/js/notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/notifications.js') ?>"></script>
</body>
</html>
