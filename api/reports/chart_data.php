<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/KNNPredictor.php';

requireAuth();

header('Content-Type: application/json');

$db = Database::getInstance();
$predictor = new KNNPredictor();
$type = $_GET['type'] ?? 'historical';

function periodKey($period, $date) {
    $ts = strtotime($date);
    if ($period === 'weekly') return date('o-\WW', $ts);
    if ($period === 'monthly') return date('Y-m', $ts);
    return $date;
}

function periodLabel($period, $date) {
    $ts = strtotime($date);
    if ($period === 'weekly') {
        $monday = strtotime('monday this week', $ts);
        $sunday = strtotime('+6 days', $monday);
        return date('M d', $monday) . ' - ' . date('M d', $sunday);
    }
    if ($period === 'monthly') return date('M Y', $ts);
    return date('M d', $ts);
}

function validDate($value, $fallback) {
    if (!is_string($value) || $value === '') return $fallback;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return $fallback;
    return $value;
}

function normalizePeriod($value) {
    return in_array($value, ['daily', 'weekly', 'monthly'], true) ? $value : 'daily';
}

function getPeriodExprs($period, $col) {
    if ($period === 'weekly') {
        return [
            'key' => "DATE_FORMAT($col, '%x-W%v')",
            'label' => "CONCAT(DATE(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY)), ' - ', DATE(DATE_ADD(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY), INTERVAL 6 DAY)))",
            'order' => "YEARWEEK($col, 1)"
        ];
    }
    if ($period === 'monthly') {
        return [
            'key' => "DATE_FORMAT($col, '%Y-%m')",
            'label' => "DATE_FORMAT($col, '%Y-%m')",
            'order' => "DATE_FORMAT($col, '%Y-%m')"
        ];
    }
    return [
        'key' => "$col",
        'label' => "DATE_FORMAT($col, '%b %d')",
        'order' => "$col"
    ];
}

/** Resolve which egg sizes a category/size filter should include. */
function resolveSizeCodes($predictor, $size, $category) {
    $meta = $predictor->getSizeMeta();

    if ($size !== '') {
        return isset($meta[$size]) ? [$size] : [];
    }
    if ($category !== '') {
        return $predictor->getSizeCodesByCategory($category);
    }
    return array_keys($meta);
}

if ($type === 'future') {
    $futurePeriod = normalizePeriod($_GET['future_period'] ?? 'daily');
    $futureCategory = (string)($_GET['future_category'] ?? '');
    $futureSize = (string)($_GET['future_size'] ?? '');

    $sizeCodes = resolveSizeCodes($predictor, $futureSize, $futureCategory);

    $lastSample = $predictor->latestSampleDate();
    $forecastStart = new DateTime('today');
    $forecastStart->modify('+1 day');

    if ($futurePeriod === 'daily') {
        $spanDays = 30;
    } elseif ($futurePeriod === 'weekly') {
        // Start on a Monday so every weekly point covers a complete week.
        $forecastStart->modify('monday last week');
        $spanDays = 56;
    } else {
        $forecastStart->modify('first day of this month');
        $spanDays = 182;
    }

    $forecastEnd = clone $forecastStart;
    $forecastEnd->modify('+' . ($spanDays - 1) . ' days');

    $forecast = $predictor->forecastSeries(
        $forecastStart->format('Y-m-d'),
        $forecastEnd->format('Y-m-d'),
        $sizeCodes
    );

    $labels = [];
    $demand = [];
    $sales = [];
    $bucketPeriod = $futurePeriod === 'daily' ? 'daily' : $futurePeriod;
    $buckets = [];

    foreach ($forecast['dates'] as $d) {
        $dayEggs = 0;
        $daySales = 0.0;
        $usable = false;

        foreach ($forecast['sizes'] as $info) {
            if (empty($info['available']) || !isset($info['days'][$d])) continue;
            $dayEggs += (int)$info['days'][$d]['eggs'];
            $daySales += (float)$info['days'][$d]['sales'];
            $usable = true;
        }

        $pk = periodKey($bucketPeriod, $d);
        if (!isset($buckets[$pk])) {
            $buckets[$pk] = ['label' => periodLabel($bucketPeriod, $d), 'eggs' => 0, 'sales' => 0.0];
        }
        $buckets[$pk]['eggs'] += $dayEggs;
        $buckets[$pk]['sales'] += $daySales;

        if ($usable && $bucketPeriod === 'daily') {
            $buckets[$pk]['usable'] = true;
        }
    }

    foreach ($buckets as $b) {
        $labels[] = $b['label'];
        $demand[] = round($b['eggs'], 0);
        $sales[] = round($b['sales'], 2);
    }

    $usableSizes = array_keys(array_filter(
        $forecast['sizes'],
        fn($s) => !empty($s['available'])
    ));

    $message = null;
    if (empty($sizeCodes)) {
        $message = 'No egg sizes match the selected filter.';
    } elseif (empty($usableSizes)) {
        $message = 'Not enough sales history yet to forecast. Record at least 7 days of sales per egg size.';
    }

    echo json_encode([
        'future_labels' => $labels,
        'future_demand' => $demand,
        'future_sales' => $sales,
        'future_period' => $futurePeriod,
        'forecast_start' => $forecastStart->format('Y-m-d'),
        'forecast_end' => $forecastEnd->format('Y-m-d'),
        'history_through' => $lastSample,
        'sizes_used' => $usableSizes,
        'message' => $message
    ]);
    exit;
}

// ---------------------------------------------------------------- historical
$range = $db->fetchOne("SELECT MAX(sale_date) as last_day FROM sales_history");
$anchor = validDate($range['last_day'] ?? null, date('Y-m-d'));

$from = validDate($_GET['from'] ?? null, date('Y-m-d', strtotime($anchor . ' -29 days')));
$to = validDate($_GET['to'] ?? null, $anchor);

if ($from > $to) {
    $swap = $from;
    $from = $to;
    $to = $swap;
}

$period = normalizePeriod($_GET['period'] ?? 'daily');
$filterType = (string)($_GET['category'] ?? '');
$filterValue = (string)($_GET['size'] ?? '');

$sizeCodes = resolveSizeCodes($predictor, $filterValue, $filterType);

$actualSales = [];
if (!empty($sizeCodes)) {
    $inClause = implode(',', array_fill(0, count($sizeCodes), '?'));
    $dateExprs = getPeriodExprs($period, 'sh.sale_date');

    $actualSales = $db->fetchAll(
        "SELECT {$dateExprs['key']} as period_key, {$dateExprs['label']} as label,
                COALESCE(SUM(sh.total_amount), 0) as actual_sales,
                COALESCE(SUM(sh.trays_sold * es.tray_capacity + sh.individual_eggs_sold), 0) as actual_eggs
         FROM sales_history sh
         JOIN egg_sizes es ON es.size_code = sh.egg_size
         WHERE es.size_code IN ({$inClause}) AND sh.sale_date BETWEEN ? AND ?
         GROUP BY period_key, label
         ORDER BY {$dateExprs['order']}",
        array_merge($sizeCodes, [$from, $to])
    );
}

// Model-driven backtest: for every day in range the predictor only sees sales
// recorded strictly before that day, so the line is a true out-of-sample fit.
$forecast = $predictor->forecastSeries($from, $to, $sizeCodes);

$predictions = [];
foreach ($forecast['dates'] as $d) {
    $pk = periodKey($period, $d);
    if (!isset($predictions[$pk])) {
        $predictions[$pk] = ['label' => periodLabel($period, $d), 'pred_sales' => 0.0, 'pred_eggs' => 0];
    }
    foreach ($forecast['sizes'] as $info) {
        if (empty($info['available']) || !isset($info['days'][$d])) continue;
        $predictions[$pk]['pred_sales'] += (float)$info['days'][$d]['sales'];
        $predictions[$pk]['pred_eggs'] += (int)$info['days'][$d]['eggs'];
    }
}

$actualByPeriod = [];
foreach ($actualSales as $row) {
    $actualByPeriod[$row['period_key']] = [
        'label' => $row['label'],
        'sales' => (float)$row['actual_sales'],
        'eggs' => (int)$row['actual_eggs']
    ];
}

$allPeriods = array_keys($predictions);
foreach (array_keys($actualByPeriod) as $pk) {
    if (!in_array($pk, $allPeriods, true)) $allPeriods[] = $pk;
}
sort($allPeriods);

$labels = [];
$actualSalesData = [];
$predictedSalesData = [];
$predictedVolumeData = [];
$actualVolumeData = [];

foreach ($allPeriods as $pk) {
    $labels[] = $predictions[$pk]['label'] ?? $actualByPeriod[$pk]['label'] ?? $pk;
    $actualSalesData[] = round($actualByPeriod[$pk]['sales'] ?? 0, 2);
    $predictedSalesData[] = round($predictions[$pk]['pred_sales'] ?? 0, 2);
    $predictedVolumeData[] = (int)($predictions[$pk]['pred_eggs'] ?? 0);
    $actualVolumeData[] = (int)($actualByPeriod[$pk]['eggs'] ?? 0);
}

$usableSizes = array_keys(array_filter($forecast['sizes'], fn($s) => !empty($s['available'])));

$message = null;
if (empty($sizeCodes)) {
    $message = 'No egg sizes match the selected filter.';
} elseif (empty($usableSizes)) {
    $message = 'Not enough sales history yet to generate predictions. Record at least 7 days of sales per egg size.';
}

echo json_encode([
    'labels' => $labels,
    'actual_sales' => $actualSalesData,
    'predicted_sales' => $predictedSalesData,
    'predicted_volume' => $predictedVolumeData,
    'actual_volume' => $actualVolumeData,
    'period' => $period,
    'from' => $from,
    'to' => $to,
    'sizes_used' => $usableSizes,
    'message' => $message
]);