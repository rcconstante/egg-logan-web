<?php
require_once __DIR__ . '/../config/database.php';

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function generateQRData($tray) {
    return json_encode([
        'tray_id' => $tray['tray_id'],
        'egg_size' => $tray['egg_size'],
        'batch' => $tray['batch_number'],
        'production_date' => $tray['production_date'],
        'expiry_date' => $tray['expiry_date'],
        'quantity' => $tray['quantity'],
        'verification_url' => rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/Egglogan/verify/tray/' . $tray['tray_id'], '/')
    ], JSON_UNESCAPED_SLASHES);
}

function generateVariantQRData($sizeCode) {
    return json_encode([
        'type' => 'variant',
        'egg_size' => $sizeCode
    ], JSON_UNESCAPED_SLASHES);
}

function getEggSizeLabel($size) {
    $labels = [
        'SS' => 'Super Small',
        'SM' => 'Small',
        'MD' => 'Medium',
        'LG' => 'Large',
        'XL' => 'Extra Large',
        'JB' => 'Jumbo',
        'CR' => 'Cracked',
        'SA' => 'Salted'
    ];
    return $labels[$size] ?? $size;
}

function getEggColorClass($size) {
    $colors = [
        'SS' => '#ADD8E6',
        'SM' => '#90EE90',
        'MD' => '#FFD700',
        'LG' => '#FFA500',
        'XL' => '#FF6B6B',
        'JB' => '#9370DB',
        'CR' => '#FF69B4',
        'SA' => '#8B4513'
    ];
    return $colors[$size] ?? '#CCCCCC';
}

function getAgeColorClass($days) {
    if ($days <= 7) return 'age-green';
    if ($days <= 14) return 'age-yellow';
    if ($days <= 21) return 'age-orange';
    return 'age-red';
}

function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function formatDateTime($date) {
    return date('M d, Y h:i A', strtotime($date));
}

function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

/**
 * Payment methods are stored snake_cased (bank_transfer, gcash), so ucfirst alone
 * rendered "Bank_transfer" on screen and on the printed receipt. Turning the
 * underscore into a space and title-casing each word is correct for every value the
 * column actually holds.
 */
function paymentMethodLabel($method) {
    $method = trim((string)$method);
    if ($method === '') return 'N/A';
    return ucwords(str_replace(['_', '-'], ' ', $method));
}

function receiptNumberPrefix() {
    return 'RCP-' . date('Ymd') . '-';
}

function receiptNumberForSequence($seq) {
    return receiptNumberPrefix() . str_pad((int)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Numeric part of a receipt number issued today, or 0 for anything else.
 * Lets a caller that already tried RCP-YYYYMMDD-0042 ask for the *next* number
 * without re-reading MAX(receipt_number) -- which, inside an open
 * REPEATABLE READ transaction, would return the pre-transaction snapshot and
 * hand back the very same colliding number forever.
 */
function receiptNumberSequence($receiptNumber) {
    $prefix = receiptNumberPrefix();
    if (strpos((string)$receiptNumber, $prefix) !== 0) return 0;
    return (int)substr($receiptNumber, strlen($prefix));
}

/**
 * @param int|null $minSeq When given, start from this sequence instead of
 *        reading the highest number issued so far. Callers retrying after a
 *        duplicate-key error must pass the previous sequence + 1 so the retry
 *        makes progress.
 */
function generateReceiptNumber($minSeq = null) {
    $pdo = DatabaseConnection::connect();
    $prefix = receiptNumberPrefix();

    if ($minSeq === null) {
        // Derive the next sequence from the highest number issued today rather
        // than from a row count: voiding or deleting receipts leaves gaps in the
        // sequence, and COUNT would then hand back a number that is already
        // taken, blocking every sale.
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(receipt_number, ?) AS UNSIGNED)) as seq FROM receipts WHERE receipt_number LIKE ?");
        $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
        $seq = ((int)($stmt->fetch()['seq'] ?? 0)) + 1;
    } else {
        $seq = (int)$minSeq + 1;
    }

    // Safety net: skip any number that somehow already exists.
    for ($i = 0; $i < 1000; $i++) {
        $candidate = receiptNumberForSequence($seq);
        $exists = $pdo->prepare("SELECT 1 FROM receipts WHERE receipt_number = ? LIMIT 1");
        $exists->execute([$candidate]);
        if (!$exists->fetch()) return $candidate;
        $seq++;
    }

    return receiptNumberForSequence($seq);
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getPagination($page, $perPage, $total) {
    $totalPages = ceil($total / $perPage);
    return [
        'current' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'has_prev' => $page > 1,
        'has_next' => $page < $totalPages,
        'prev' => $page > 1 ? $page - 1 : null,
        'next' => $page < $totalPages ? $page + 1 : null
    ];
}

function isWeekend($date) {
    return in_array(date('w', strtotime($date)), [0, 6]);
}

function isHoliday($date) {
    $holidays = getPHHolidays(date('Y', strtotime($date)));
    return in_array(date('Y-m-d', strtotime($date)), $holidays);
}

function getPHHolidays($year) {
    $fixed = [
        "$year-01-01",
        "$year-04-09",
        "$year-05-01",
        "$year-06-12",
        "$year-08-21",
        "$year-11-01",
        "$year-11-30",
        "$year-12-25",
        "$year-12-30",
    ];
    $movable = [];
    $easter = getEasterDate($year);
    if ($easter) {
        $movable[] = date('Y-m-d', strtotime($easter . ' -3 days'));
        $movable[] = date('Y-m-d', strtotime($easter . ' -2 days'));
        $movable[] = date('Y-m-d', strtotime($easter));
    }
    return array_merge($fixed, $movable);
}

function getEasterDate($year) {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return "$year-$month-$day";
}

function getSeasonIndex($date) {
    $month = (int)date('m', strtotime($date));
    if ($month >= 3 && $month <= 5) return 0;
    if ($month >= 6 && $month <= 8) return 1;
    if ($month >= 9 && $month <= 11) return 2;
    return 3;
}

function metricPlainInfo() {
    return [
        'accuracy' => [
            'label' => 'Hit Rate',
            'plain' => 'Out of 100 past days, how many times the forecast was within 1 tray of what you actually sold.',
            'good' => '90%+ means you can plan stock around it.'
        ],
        'exact_accuracy' => [
            'label' => 'Exact Match Rate',
            'plain' => 'How often the forecast matched the real number to the tray.',
            'good' => 'Stricter than the accuracy band — this is the perfect-score number.'
        ],
        'precision' => [
            'label' => 'Does Not Over-Predict',
            'plain' => 'How often the forecast stayed at or below what you actually sold.',
            'good' => 'High precision means you will not overstock from a bad forecast.'
        ],
        'recall' => [
            'label' => 'Does Not Under-Predict',
            'plain' => 'How often the forecast reached at least what you actually sold.',
            'good' => 'High recall means you will not be caught short on a busy day.'
        ],
        'f1_score' => [
            'label' => 'Balanced Score',
            'plain' => 'A single score balancing over- and under-prediction. Higher is steadier in both directions.',
            'good' => 'Use this when you want one number instead of two.'
        ],
        'mae' => [
            'label' => 'Typical Miss (trays)',
            'plain' => 'On an average day, the forecast was off by this many trays.',
            'good' => 'Under 1 tray means the number is good enough to order against.'
        ],
        'rmse' => [
            'label' => 'Worst-Day Miss',
            'plain' => 'How big the misses get on the bad days. RMSE punishes large errors more than the typical miss does.',
            'good' => 'Close to the typical miss means there are no wild surprises.'
        ],
        'mape' => [
            'label' => 'Error %',
            'plain' => 'Average error as a percentage of the real number. Watch this on small numbers — 1 tray off a 2-tray day is 50%.',
            'good' => 'Under 10% is comfortable.'
        ],
        'r2' => [
            'label' => 'Trend Match',
            'plain' => 'How well the forecast follows your real ups and downs, not just the average. 100% means it tracks every wiggle.',
            'good' => 'Above 75% means it catches busy days and slow days.'
        ],
        'adj_r2' => [
            'label' => 'Trend Match (Fair)',
            'plain' => 'Trend Match, adjusted to be fair given how many inputs the model uses.',
            'good' => 'Compared against Trend Match to check the model is not just over-fitted.'
        ],
        'mse' => [
            'label' => 'Squared Error',
            'plain' => 'The raw squared miss behind the Worst-Day Miss number. Useful for engineers, not for daily decisions.',
            'good' => 'Ignore this unless you are comparing two models directly.'
        ],
    ];
}

function metricStatus($value, $threshold = 90) {
    if ($value >= $threshold) return 'green';
    if ($value >= $threshold * 0.85) return 'yellow';
    return 'red';
}

function metricStatusLowerBetter($value, $good, $ok) {
    if ($value <= $good) return 'green';
    if ($value <= $ok) return 'yellow';
    return 'red';
}

/**
 * Turns raw prediction + stock figures into one plain sentence a non-technical
 * reader can act on.
 */
function describePrediction($pred, $stockEggs, $stockTrays) {
    if (!empty($pred['is_no_data'])) {
        return [
            'tone' => 'muted',
            'text' => 'No forecast yet — record a few days of sales and this will fill in.'
        ];
    }

    $trays = (int)($pred['predicted_trays'] ?? 0);
    $needed = max(0, ($pred['predicted_eggs'] ?? 0) - $stockEggs);

    if ($stockEggs <= 0) {
        return [
            'tone' => 'danger',
            'text' => "Expect around {$trays} " . plural($trays, 'tray', 'trays')
                . '. You have none in stock right now, so plan to restock before selling.'
        ];
    }

    if ($needed > 0) {
        return [
            'tone' => 'warning',
            'text' => "Expect around {$trays} " . plural($trays, 'tray', 'trays')
                . ' today. You have ' . number_format($stockEggs) . ' eggs ('
                . $stockTrays . ' ' . plural($stockTrays, 'tray', 'trays')
                . '), which is about ' . number_format($needed) . ' short.'
        ];
    }

    $surplus = $stockEggs - ($pred['predicted_eggs'] ?? 0);
    return [
        'tone' => 'success',
        'text' => "Expect around {$trays} " . plural($trays, 'tray', 'trays')
            . ' today. Your ' . number_format($stockEggs) . ' eggs in stock should cover it'
            . ($surplus > 0 ? ', leaving roughly ' . number_format($surplus) . ' spare.' : '.')
    ];
}

function plural($count, $singular, $pluralForm) {
    return $count == 1 ? $singular : $pluralForm;
}
