<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/functions.php';
// logActivity() lives in auth.php. Without this the scheduled retrain ran but
// wrote nothing to activity_logs, so it was invisible in the audit trail.
require_once __DIR__ . '/../includes/auth.php';

class KNNPredictor {
    private $db;
    private $k;
    private $features;

    private $seriesCache = [];
    private $metaCache = null;
    private $seriesKCache = [];
    private $kCache = [];
    private $holidayCache = [];
    private $recencyTau = 45.0;
    private static $retrainInProgress = false;

    // Tiered accuracy thresholds
    private const MIN_ACCURACY_FLOOR = 0.60;   // 60% = absolute minimum to save
    private const TARGET_ACCURACY = 0.80;      // 80% = production ready target

    public function __construct($k = 5) {
        $this->db = Database::getInstance();
        $this->k = $k;
        // Only sales-based features (no inventory dependencies)
        $this->features = [
            'day_of_week', 'is_weekend', 'is_holiday',
            'demand_7d', 'demand_14d', 'demand_30d',
            'demand_trend'
        ];
    }

    public function predict($eggSize, $date = null) {
        $date = $date ?? date('Y-m-d');
        $features = $this->extractFeatures($eggSize, $date);
        $history = $this->getTrainingData($eggSize, $date);

        if (count($history) < 7) {
            return $this->noDataPrediction($eggSize, $date, count($history));
        }

        // Walk-forward k selection: crossValidate() only ever trains on days strictly
        // older than the day being scored, so k is chosen without seeing the future.
        $optimalK = $this->findOptimalK($eggSize, $history);
        $this->k = $optimalK;

        // Compute dynamic normalization ranges from training data
        $normRanges = $this->computeNormRanges($history);

        $predictions = $this->weightedKNN($features, $history, $normRanges, 'actual_demand');
        $salesPrediction = $this->weightedKNN($features, $history, $normRanges, 'actual_sales');
        $confidenceInterval = $this->bootstrapConfidence($history, $predictions['prediction']);

        // Confidence must account for how stale the sales history is. With a long gap
        // the rolling demand features collapse to zero and the model can only match on
        // weekday, so sample count alone would overstate how much to trust this.
        $gapDays = $this->daysSinceLastSale($eggSize, $date);
        $effectiveSamples = $gapDays > 30 ? min(count($history), 8) : count($history);

        // Derive eggs from the ROUNDED tray count. Previously eggs were computed
        // from the raw fractional prediction while trays were already rounded, so
        // the page showed impossible pairs like "2 trays / 45 eggs" for a 30-egg
        // tray. Rounding once, then converting, keeps the two figures consistent.
        $traysRounded = round($predictions['prediction']);
        $trayCapacity = $this->getTrayCapacity($eggSize);

        $result = [
            'prediction_date' => $date,
            'egg_size' => $eggSize,
            'predicted_trays' => $traysRounded,
            'predicted_eggs' => (int)round($traysRounded * $trayCapacity),
            'tray_capacity' => $trayCapacity,
            'predicted_sales' => round($salesPrediction['prediction'], 2),
            'confidence_lower' => round(max(0, $predictions['prediction'] - $confidenceInterval)),
            'confidence_upper' => round($predictions['prediction'] + $confidenceInterval),
            'confidence_level' => $this->getConfidenceLevel($effectiveSamples),
            'confidence_raw_level' => $this->getConfidenceLevel(count($history)),
            'confidence_downgraded' => $this->getConfidenceLevel($effectiveSamples)
                !== $this->getConfidenceLevel(count($history)),
            'k_used' => $optimalK,
            'samples_used' => count($history),
            'effective_samples' => $effectiveSamples,
            'effective_neighbors' => $predictions['effective_neighbors'] ?? null,
            'days_since_last_sale' => $gapDays,
            'features_used' => $this->features,
            'top_neighbors' => $predictions['neighbors'],
            'feature_breakdown' => $features
        ];

        $this->savePrediction($result);
        return $result;
    }

    /** Whole days between the target date and the most recent recorded sale for this size. */
    public function daysSinceLastSale($eggSize, $beforeDate) {
        $row = $this->db->fetchOne(
            "SELECT MAX(sale_date) AS d FROM sales_history WHERE egg_size = ? AND sale_date < ?",
            [$eggSize, $beforeDate]
        );
        if (empty($row['d'])) return null;
        return (int)floor((strtotime($beforeDate) - strtotime($row['d'])) / 86400);
    }

    private function extractFeatures($eggSize, $date) {
        $ts = strtotime($date);

        $features = [
            'day_of_week' => (int)date('w', $ts),
            'is_weekend' => in_array((int)date('w', $ts), [0, 6]) ? 1 : 0,
            'is_holiday' => $this->isPHHoliday($date) ? 1 : 0,
        ];

        // Demand windows - strictly BEFORE target date
        $features['demand_7d'] = $this->getAvgDemand($eggSize, $date, 7);
        $features['demand_14d'] = $this->getAvgDemand($eggSize, $date, 14);
        $features['demand_30d'] = $this->getAvgDemand($eggSize, $date, 30);

        // Trend: compare last 7 days vs previous 7 days
        $recent7 = $this->getAvgDemand($eggSize, $date, 7);
        $prev7 = $this->getAvgDemandBetween($eggSize, $date, 14, 7);
        $features['demand_trend'] = $recent7 - $prev7;

        return $features;
    }

    private function getAvgDemand($eggSize, $beforeDate, $days) {
        $result = $this->db->fetchOne(
            "SELECT COALESCE(AVG(trays_sold), 0) as avg_demand FROM sales_history
             WHERE egg_size = ? AND sale_date >= DATE_SUB(?, INTERVAL ? DAY) AND sale_date < ?",
            [$eggSize, $beforeDate, $days, $beforeDate]
        );
        return (float)($result['avg_demand'] ?? 0);
    }

    private function getAvgDemandBetween($eggSize, $beforeDate, $startDaysAgo, $endDaysAgo) {
        $result = $this->db->fetchOne(
            "SELECT COALESCE(AVG(trays_sold), 0) as avg_demand FROM sales_history
             WHERE egg_size = ?
             AND sale_date >= DATE_SUB(?, INTERVAL ? DAY)
             AND sale_date < DATE_SUB(?, INTERVAL ? DAY)",
            [$eggSize, $beforeDate, $startDaysAgo, $beforeDate, $endDaysAgo]
        );
        return (float)($result['avg_demand'] ?? 0);
    }

    private function getTrainingData($eggSize, $asOf = null) {
        $sql = "SELECT sh.*,
                       DAYOFWEEK(sh.sale_date) - 1 as day_of_week_calc,
                       sh.trays_sold as actual_demand
                FROM sales_history sh
                WHERE sh.egg_size = ?";
        $params = [$eggSize];
        if ($asOf !== null) {
            $sql .= " AND sh.sale_date < ?";
            $params[] = $asOf;
        }
        $sql .= " ORDER BY sh.sale_date DESC LIMIT 365";

        $data = $this->db->fetchAll($sql, $params);

        $samples = [];
        foreach ($data as $row) {
            $features = $this->extractFeatures($row['egg_size'], $row['sale_date']);
            $features['actual_demand'] = (float)$row['trays_sold'];
            $features['actual_sales'] = (float)$row['total_amount'];
            $features['date'] = $row['sale_date'];
            $features['egg_size'] = $row['egg_size'];
            $samples[] = $features;
        }
        return $samples;
    }

    private function computeNormRanges($samples) {
        $ranges = [];
        foreach ($this->features as $f) {
            $values = array_column($samples, $f);
            if (empty($values)) continue;
            $min = min($values);
            $max = max($values);
            $ranges[$f] = [$min, $max];
        }
        return $ranges;
    }

    private function weightedKNN($target, $samples, $normRanges, $targetCol = 'actual_demand') {
        $distances = [];

        foreach ($samples as $sample) {
            $dist = $this->euclideanDistance($target, $sample, $normRanges);
            // Time weight: more recent data gets higher weight
            $daysAgo = (int)(strtotime($sample['date']) - strtotime(date('Y-m-d'))) / 86400;
            $timeWeight = exp(-max(0, -$daysAgo) / 30); // days in the past get decayed

            $distances[] = [
                'distance' => $dist,
                'target_value' => $sample[$targetCol] ?? 0,
                'date' => $sample['date'],
                'weight' => $timeWeight
            ];
        }

        usort($distances, fn($a, $b) => $a['distance'] <=> $b['distance']);
        $neighbors = array_slice($distances, 0, $this->k);

        $weightedSum = 0;
        $weightTotal = 0;

        // 1/d concentrates almost all weight on the single nearest neighbour, which makes k
        // behave like "nearest 1" with extra steps. 1/sqrt(d) keeps closer days ahead
        // while still letting the whole k window contribute to the average.
        foreach ($neighbors as $n) {
            $w = $n['distance'] > 0.0001
                ? (1.0 / sqrt($n['distance'])) * $n['weight']
                : 1000.0;
            $weightedSum += $w * $n['target_value'];
            $weightTotal += $w;
        }

        $prediction = $weightTotal > 0 ? $weightedSum / $weightTotal : 0;

        $weights = [];
        foreach ($neighbors as $n) {
            $weights[] = $n['distance'] > 0.0001 ? (1.0 / sqrt($n['distance'])) * $n['weight'] : 1000.0;
        }
        $weightTotal = array_sum($weights) ?: 1.0;
        $shares = array_map(fn($w) => $w / $weightTotal, $weights);
        $ess = 1.0 / max(1e-9, array_sum(array_map(fn($s) => $s * $s, $shares)));

        return [
            'prediction' => round($prediction, 2),
            'neighbors' => array_slice($neighbors, 0, 5),
            'effective_neighbors' => round($ess, 2)
        ];
    }

    private function euclideanDistance($a, $b, $normRanges) {
        $sum = 0;
        foreach ($this->features as $f) {
            $v1 = $a[$f] ?? 0;
            $v2 = $b[$f] ?? 0;

            if (isset($normRanges[$f])) {
                [$min, $max] = $normRanges[$f];
                $range = $max - $min;
                if ($range > 0) {
                    $v1 = ($v1 - $min) / $range;
                    $v2 = ($v2 - $min) / $range;
                } else {
                    $v1 = 0;
                    $v2 = 0;
                }
            }
            $sum += pow($v1 - $v2, 2);
        }
        return sqrt($sum);
    }

    /**
     * Picks k by walk-forward accuracy, breaking ties toward the SMALLER k so that a
     * tie does not silently inflate the neighbour count. The value is cached per size
     * because the training set does not change within a single request.
     */
    public function findOptimalK($eggSize, $history = null) {
        $cacheKey = 'opt:' . $eggSize . ':' . count($history ?? []);
        if (isset($this->kCache[$cacheKey])) {
            $this->k = $this->kCache[$cacheKey];
            return $this->k;
        }

        $history = $history ?? $this->getTrainingData($eggSize);
        if (count($history) < 14) return 3;

        $kValues = [3, 5, 7, 9];
        $bestK = 5;
        $bestScore = -1;

        foreach ($kValues as $k) {
            $this->k = $k;
            $score = $this->crossValidate($history);
            // Strict > plus ascending k order means the first (smallest) k wins ties.
            if ($score > $bestScore + 1e-9) {
                $bestScore = $score;
                $bestK = $k;
            }
        }

        $this->k = $bestK;
        $this->kCache[$cacheKey] = $bestK;
        return $bestK;
    }

    /**
     * Walk-forward validation. Samples arrive newest-first, so the test window is the
     * most recent block and each test day is predicted using ONLY samples strictly
     * older than it. Training on the whole set (the previous implementation) let the
     * model see days that happened after the day it was being scored on, which inflated
     * accuracy and made k selection meaningless.
     */
    private function crossValidate($samples) {
        $total = count($samples);
        $minTrain = 7;
        $window = min($total - $minTrain, 20);
        if ($window <= 0) return 0;

        $correct = 0;
        $tested = 0;

        // Walk newest -> oldest, holding out one day at a time.
        for ($i = 0; $i < $window; $i++) {
            $test = $samples[$i];
            // Everything strictly older than the test day (indices $i+1..end).
            $train = array_slice($samples, $i + 1);
            if (count($train) < $minTrain) continue;

            $normRanges = $this->computeNormRanges($train);
            $target = $this->extractFeatures($test['egg_size'], $test['date']);
            $result = $this->weightedKNN($target, $train, $normRanges, 'actual_demand');

            if (abs(round($result['prediction']) - $test['actual_demand']) <= 1) {
                $correct++;
            }
            $tested++;
        }

        return $tested > 0 ? $correct / $tested : 0;
    }

    private function bootstrapConfidence($samples, $prediction, $targetCol = 'actual_demand') {
        $n = count($samples);
        if ($n < 5) return $prediction * 0.5;

        $bootstrapSamples = 100;
        $resampledMeans = [];

        for ($b = 0; $b < $bootstrapSamples; $b++) {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                $idx = rand(0, $n - 1);
                $sum += $samples[$idx][$targetCol] ?? 0;
            }
            $resampledMeans[] = $sum / $n;
        }

        sort($resampledMeans);
        $lowerIdx = (int)($bootstrapSamples * 0.05);
        $upperIdx = (int)($bootstrapSamples * 0.95);

        $ciLower = $resampledMeans[$lowerIdx] ?? 0;
        $ciUpper = $resampledMeans[$upperIdx] ?? 0;

        return max(abs($prediction - $ciLower), abs($ciUpper - $prediction), 0.5);
    }

    private function getConfidenceLevel($sampleCount) {
        if ($sampleCount >= 30) return 'high';
        if ($sampleCount >= 10) return 'medium';
        return 'low';
    }

    private function noDataPrediction($eggSize, $date, $sampleCount) {
        return [
            'prediction_date' => $date,
            'egg_size' => $eggSize,
            'predicted_trays' => null,
            'predicted_eggs' => null,
            'predicted_sales' => null,
            'confidence_lower' => null,
            'confidence_upper' => null,
            'confidence_level' => 'none',
            'k_used' => $this->k,
            'samples_used' => $sampleCount,
            'is_no_data' => true,
            'message' => $sampleCount === 0
                ? 'No sales data available. Record sales to enable predictions.'
                : "Not enough data ({$sampleCount}/7 samples). Need more sales history."
        ];
    }

    public function predictAllSizes($date = null) {
        $date = $date ?? date('Y-m-d');
        // Weekly auto-retrain is checked here because this is the one funnel every
        // forecast read goes through. It used to live only in api/predictions/get.php,
        // which nothing in the app called, so the "Auto-Retrain" switch flipped a
        // setting that was never acted on.
        $this->runScheduledRetrainIfDue();
        $sizes = $this->db->fetchAll("SELECT size_code FROM egg_sizes");
        $results = [];

        foreach ($sizes as $size) {
            $results[$size['size_code']] = $this->predict($size['size_code'], $date);
        }

        // Include active model metadata for UI
        $activeModel = $this->db->fetchOne(
            "SELECT mape_score, features_used FROM model_versions WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1"
        );
        $modelMeta = [];
        if ($activeModel) {
            $features = json_decode($activeModel['features_used'] ?? '{}', true);
            $modelMeta = [
                'version_accuracy' => isset($features['avg_accuracy']) ? $features['avg_accuracy'] : round(100 - $activeModel['mape_score'], 1),
                'accuracy_tier' => $features['accuracy_tier'] ?? 'unknown',
                'target_accuracy' => $features['target_accuracy'] ?? 80,
            ];
        }
        $results['_model_meta'] = $modelMeta;

        return $results;
    }

    /**
     * Retrains at most once a week, and only while the auto_retrain setting is on.
     * Safe to call on every forecast read: the two guard queries are trivial and the
     * retrain itself only fires when a full week has passed since the last version.
     * Only attempts retrain once there's sufficient sales data (at least 14 days for any size).
     *
     * @return array|null summary of what happened, or null when nothing was due
     */
    public function runScheduledRetrainIfDue($maxAgeDays = 7) {
        if (self::$retrainInProgress) return null;   // never recurse into a retrain
        self::$retrainInProgress = true;
        $lockFile = null;
        try {
            $enabled = $this->db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'auto_retrain'"
            );
            if (($enabled['setting_value'] ?? '1') != '1') return null;

            $last = $this->db->fetchOne("SELECT MAX(created_at) AS last FROM model_versions");
            $lastAt = $last['last'] ?? null;
            if ($lastAt && (time() - strtotime($lastAt)) <= $maxAgeDays * 86400) return null;

            // Check if there's enough data to train (at least 14 days for any size)
            $hasEnoughData = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM (
                    SELECT egg_size, COUNT(DISTINCT sale_date) as days
                    FROM sales_history
                    GROUP BY egg_size
                    HAVING days >= 14
                ) as sufficient"
            );
            if (empty($hasEnoughData) || ($hasEnoughData['cnt'] ?? 0) == 0) {
                // Not enough data yet - log and skip
                error_log('Auto-retrain deferred: no egg size has 14+ days of sales data yet');
                return null;
            }

            // Belt-and-braces lock so two servers hitting this at once cannot both
            // retrain. A stale lock is treated as expired so a crashed run recovers.
            $lockFile = sys_get_temp_dir() . '/egglogan_retrain.lock';
            if (file_exists($lockFile) && (time() - filemtime($lockFile)) <= 3600) return null;
            @touch($lockFile);

            $result = $this->retrainModel();
            $tierLabel = $result['accuracy_tier'] === 'target_met' ? 'TARGET MET (≥80%)' : 'IMPROVING (<80%)';
            logActivity(getCurrentUserId() ?? 0, 'auto_retrain',
                "Scheduled auto-retrain: {$result['version']} (Accuracy: {$result['accuracy']}% [{$tierLabel}], MAPE: {$result['mape']}%, K={$result['k']})");
            return $result;
        } catch (RuntimeException $e) {
            // Accuracy floor not met - log but don't error
            if (str_contains($e->getMessage(), '60%') || str_contains($e->getMessage(), 'floor')) {
                error_log('Auto-retrain deferred: ' . $e->getMessage());
                logActivity(getCurrentUserId() ?? 0, 'auto_retrain_deferred', $e->getMessage());
            } else {
                error_log('Auto-retrain error: ' . $e->getMessage());
            }
            return null;
        } catch (Throwable $e) {
            error_log('Auto-retrain skipped: ' . $e->getMessage());
            return null;
        } finally {
            if ($lockFile !== null && file_exists($lockFile)) @unlink($lockFile);
            self::$retrainInProgress = false;
        }
    }

    public function getSizeMeta() {
        if ($this->metaCache === null) {
            $this->metaCache = [];
            $rows = $this->db->fetchAll(
                "SELECT size_code, size_label, category, tray_capacity, price_per_tray, price_per_egg
                 FROM egg_sizes ORDER BY size_code"
            );
            foreach ($rows as $r) {
                $this->metaCache[$r['size_code']] = [
                    'code' => $r['size_code'],
                    'label' => $r['size_label'],
                    'category' => $r['category'],
                    'capacity' => (int)$r['tray_capacity'],
                    'price' => (float)$r['price_per_tray'],
                    'price_per_egg' => (float)$r['price_per_egg']
                ];
            }
        }
        return $this->metaCache;
    }

    public function getSizeCodesByCategory($category) {
        return array_values(array_map(
            fn($m) => $m['code'],
            array_filter($this->getSizeMeta(), fn($m) => $m['category'] === $category)
        ));
    }

    /** Most recent date that actually has recorded sales, or null when the table is empty. */
    public function latestSampleDate() {
        $row = $this->db->fetchOne("SELECT MAX(sale_date) as d FROM sales_history");
        return $row['d'] ?? null;
    }

    /**
     * Daily training samples for one egg size, built with a single aggregate query.
     * Rolling demand windows are true calendar-day averages (gap days count as zero),
     * and every window looks strictly backwards so backtests never leak future data.
     */
    public function getSeriesSamples($eggSize, $lookbackDays = 365) {
        $key = $eggSize . ':' . $lookbackDays;
        if (isset($this->seriesCache[$key])) {
            return $this->seriesCache[$key];
        }

        $rows = $this->db->fetchAll(
            "SELECT sale_date,
                    SUM(trays_sold) as trays,
                    SUM(individual_eggs_sold) as loose_eggs,
                    SUM(total_amount) as amount
             FROM sales_history
             WHERE egg_size = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY sale_date
             ORDER BY sale_date ASC",
            [$eggSize, $lookbackDays]
        );

        $this->seriesCache[$key] = $this->buildSamplesFromRows($rows, $eggSize);
        return $this->seriesCache[$key];
    }

    private function buildSamplesFromRows($rows, $eggSize) {
        if (empty($rows)) return [];

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['sale_date']] = [
                'trays' => (float)$r['trays'],
                'loose' => (float)$r['loose_eggs'],
                'amount' => (float)$r['amount']
            ];
        }

        $dateKeys = array_keys($byDate);
        $firstTs = strtotime($dateKeys[0]);
        $lastTs = strtotime($dateKeys[count($dateKeys) - 1]);

        $dates = [];
        $trays = [];
        $loose = [];
        $amount = [];
        for ($ts = $firstTs; $ts <= $lastTs; $ts += 86400) {
            $d = date('Y-m-d', $ts);
            $row = $byDate[$d] ?? null;
            $dates[] = $d;
            $trays[] = $row ? $row['trays'] : 0.0;
            $loose[] = $row ? $row['loose'] : 0.0;
            $amount[] = $row ? $row['amount'] : 0.0;
        }

        $traysPrefix = $this->prefixSums($trays);
        $samples = [];

        foreach ($dates as $i => $d) {
            $d7 = $this->backRangeMean($traysPrefix, $i, 7, 1);
            $d14 = $this->backRangeMean($traysPrefix, $i, 14, 1);
            $d30 = $this->backRangeMean($traysPrefix, $i, 30, 1);
            $prev7 = $this->backRangeMean($traysPrefix, $i, 14, 8);
            $dow = (int)date('w', strtotime($d));

            $samples[] = [
                'date' => $d,
                'egg_size' => $eggSize,
                'day_of_week' => $dow,
                'is_weekend' => ($dow === 0 || $dow === 6) ? 1 : 0,
                'is_holiday' => $this->isPHHolidayCached($d),
                'demand_7d' => $d7,
                'demand_14d' => $d14,
                'demand_30d' => $d30,
                'demand_trend' => $d7 - $prev7,
                'actual_demand' => $trays[$i],
                'actual_loose' => $loose[$i],
                'actual_sales' => $amount[$i]
            ];
        }

        return $samples;
    }

    private function prefixSums($values) {
        $prefix = [0.0];
        $running = 0.0;
        foreach ($values as $v) {
            $running += $v;
            $prefix[] = $running;
        }
        return $prefix;
    }

    /** Mean of the calendar days from $fromOff back to $toOff back, relative to index $i. */
    private function backRangeMean($prefix, $i, $fromOff, $toOff) {
        $hi = $i - $toOff;
        if ($hi < 0) return 0.0;
        $lo = $i - $fromOff;
        if ($lo < 0) $lo = 0;
        $n = $hi - $lo + 1;
        if ($n <= 0) return 0.0;
        return ($prefix[$hi + 1] - $prefix[$lo]) / $n;
    }

    private function isPHHolidayCached($date) {
        $year = date('Y', strtotime($date));
        if (!isset($this->holidayCache[$year])) {
            $this->holidayCache[$year] = getPHHolidays($year);
        }
        return in_array($date, $this->holidayCache[$year], true) ? 1 : 0;
    }

    public function dateRange($from, $to, $maxDays = 400) {
        $start = new DateTime($from);
        $end = new DateTime($to);
        if ($end < $start) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        $dates = [];
        $cursor = clone $start;
        while ($cursor <= $end && count($dates) < $maxDays) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
        return $dates;
    }

    private function lowerBoundDates($sorted, $date) {
        $lo = 0;
        $hi = count($sorted);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($sorted[$mid] < $date) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Walk-forward backtest over real recorded sales.
     *
     * Returns one row per scorable day: the forecast that could legitimately have been
     * made on that date using only strictly-earlier days, plus what was actually sold.
     * This is the honest accuracy source — it never trains on a day it is scoring.
     *
     * @return array{0: array<int, array{date:string,predicted:float,actual:float,tray_capacity:int}>, 1: array{date:string,predicted:float,actual:float,tray_capacity:int}[]}
     */
    public function backtestSeries($eggSize, $minTrainDays = 14, $maxTrainDays = 120) {
        $meta = $this->getSizeMeta();
        $capacity = (int)($meta[$eggSize]['capacity'] ?? 30);
        $samples = $this->getSeriesSamples($eggSize);

        if (count($samples) < $minTrainDays + 1) {
            return [[], []];
        }

        $k = $this->seriesOptimalK($eggSize, $samples);
        $n = count($samples);

        $rows = [];
        $walk = [];

        // Samples are ascending by date, so index $i is predicted from 0..$i-1.
        for ($i = $minTrainDays; $i < $n; $i++) {
            $train = array_slice($samples, max(0, $i - $maxTrainDays), $i);
            if (count($train) < $minTrainDays) continue;

            $ranges = $this->computeNormRanges($train);
            $target = $this->featuresFromSample($samples[$i]);
            $predicted = max(0.0, $this->knnWeighted($target, $train, $ranges, 'actual_demand', $k));
            $actual = (float)$samples[$i]['actual_demand'];

            $row = [
                'date' => $samples[$i]['date'],
                'predicted' => $predicted,
                'actual' => $actual,
                'tray_capacity' => $capacity
            ];
            $rows[] = $row;
            $walk[] = $row;
        }

        return [$rows, $walk];
    }

    /**
     * KNN predictions for every size on every date in [from, to].
     * Dates covered by real sales are predicted from strictly-prior samples (backtest);
     * later dates reuse the most recent rolling baseline as the demand anchor.
     */
    public function forecastSeries($from, $to, $sizeCodes = null, $maxTrainDays = 120) {
        $meta = $this->getSizeMeta();
        if ($sizeCodes === null) {
            $sizeCodes = array_keys($meta);
        }
        $sizeCodes = array_values(array_filter($sizeCodes, fn($c) => isset($meta[$c])));

        $dates = $this->dateRange($from, $to);

        $result = [
            'dates' => $dates,
            'from' => $from,
            'to' => $to,
            'sizes' => []
        ];

        foreach ($sizeCodes as $code) {
            $samples = $this->getSeriesSamples($code);
            $info = $meta[$code];

            if (count($samples) < 7) {
                $result['sizes'][$code] = [
                    'available' => false,
                    'samples' => count($samples),
                    'days' => []
                ];
                continue;
            }

            $k = $this->seriesOptimalK($code, $samples);
            $ranges = $this->computeNormRanges($samples);
            $sampleDates = array_column($samples, 'date');
            $baseline = $samples[count($samples) - 1];
            $lastSampleDate = $sampleDates[count($sampleDates) - 1];

            $days = [];
            foreach ($dates as $d) {
                $trainEnd = $this->lowerBoundDates($sampleDates, $d);

                if ($d > $lastSampleDate) {
                    $target = $this->baselineTargetFeatures($d, $baseline);
                } else {
                    $target = $this->featuresFromSample($samples[$trainEnd]);
                }

                $trainStart = max(0, $trainEnd - $maxTrainDays);
                $train = array_slice($samples, $trainStart, $trainEnd - $trainStart);

                if (count($train) < 7) {
                    $days[$d] = [
                        'trays' => 0.0,
                        'eggs' => 0,
                        'sales' => 0.0,
                        'confidence_level' => 'none'
                    ];
                    continue;
                }

                $trays = max(0.0, $this->knnWeighted($target, $train, $ranges, 'actual_demand', $k));
                $loose = max(0.0, $this->knnWeighted($target, $train, $ranges, 'actual_loose', $k));

                $days[$d] = [
                    'trays' => round($trays, 2),
                    'loose' => (int)round($loose),
                    'eggs' => (int)round($trays * $info['capacity'] + $loose),
                    // Revenue is priced per tray plus per loose egg, matching how sales are recorded.
                    'sales' => round($trays * $info['price'] + $loose * $info['price_per_egg'], 2),
                    'confidence_level' => count($train) >= 30 ? 'high' : (count($train) >= 14 ? 'medium' : 'low')
                ];
            }

            $result['sizes'][$code] = [
                'available' => true,
                'k' => $k,
                'samples' => count($samples),
                'capacity' => $info['capacity'],
                'price' => $info['price'],
                'price_per_egg' => $info['price_per_egg'],
                'days' => $days
            ];
        }

        return $result;
    }

    private function featuresFromSample($sample) {
        return [
            'day_of_week' => $sample['day_of_week'],
            'is_weekend' => $sample['is_weekend'],
            'is_holiday' => $sample['is_holiday'],
            'demand_7d' => $sample['demand_7d'],
            'demand_14d' => $sample['demand_14d'],
            'demand_30d' => $sample['demand_30d'],
            'demand_trend' => $sample['demand_trend']
        ];
    }

    private function baselineTargetFeatures($date, $baseline) {
        $dow = (int)date('w', strtotime($date));
        return [
            'day_of_week' => $dow,
            'is_weekend' => ($dow === 0 || $dow === 6) ? 1 : 0,
            'is_holiday' => $this->isPHHolidayCached($date),
            'demand_7d' => $baseline['demand_7d'],
            'demand_14d' => $baseline['demand_14d'],
            'demand_30d' => $baseline['demand_30d'],
            'demand_trend' => $baseline['demand_trend']
        ];
    }

    private function seriesOptimalK($eggSize, $samples) {
        if (isset($this->seriesKCache[$eggSize])) {
            return $this->seriesKCache[$eggSize];
        }

        $n = count($samples);
        if ($n < 14) {
            return $this->seriesKCache[$eggSize] = 3;
        }

        $window = min($n - 1, 16);
        $bestK = 5;
        $bestScore = -1.0;

        foreach ([3, 5, 7, 9] as $k) {
            if ($k >= $n) continue;
            $correct = 0;
            $tested = 0;

            for ($i = $n - $window; $i < $n; $i++) {
                $train = array_slice($samples, max(0, $i - 90), $i);
                if (count($train) < $k) continue;
                $ranges = $this->computeNormRanges($train);
                $pred = $this->knnWeighted($samples[$i], $train, $ranges, 'actual_demand', $k);
                $tested++;
                if (abs(round($pred) - $samples[$i]['actual_demand']) <= 1) $correct++;
            }

            if ($tested === 0) continue;
            $score = $correct / $tested;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestK = $k;
            }
        }

        return $this->seriesKCache[$eggSize] = $bestK;
    }

    /** Inverse-distance weighted KNN with exponential recency decay, explicit k. */
    private function knnWeighted($target, $train, $ranges, $targetCol, $k) {
        if (empty($train)) return 0.0;

        $todayTs = strtotime(date('Y-m-d'));
        $scored = [];

        foreach ($train as $sample) {
            $distance = $this->euclideanDistance($target, $sample, $ranges);
            $daysAgo = (int)round(($todayTs - strtotime($sample['date'])) / 86400);
            $timeWeight = ($daysAgo > 0 && $this->recencyTau > 0)
                ? exp(-$daysAgo / $this->recencyTau)
                : 1.0;

            $scored[] = [
                $distance,
                (float)($sample[$targetCol] ?? 0),
                $timeWeight
            ];
        }

        usort($scored, fn($a, $b) => $a[0] <=> $b[0]);
        $neighbors = array_slice($scored, 0, max(1, $k));

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        // 1/sqrt(d) instead of 1/d: see weightedKNN() for why.
        foreach ($neighbors as $n) {
            $w = $n[0] > 0.0001 ? (1.0 / sqrt($n[0])) * $n[2] : 1000.0 * $n[2];
            $weightedSum += $w * $n[1];
            $weightTotal += $w;
        }

        return $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;
    }

    public function setRecencyTau($days) {
        $this->recencyTau = (float)$days;
        return $this;
    }

    public function trainTestSplit($samples, $testRatio = 0.2) {
        $n = count($samples);
        $testSize = max(1, (int)round($n * $testRatio));
        $shuffled = $samples;
        shuffle($shuffled);
        $test = array_slice($shuffled, 0, $testSize);
        $train = array_slice($shuffled, $testSize);
        return ['train' => $train, 'test' => $test, 'train_count' => count($train), 'test_count' => count($test)];
    }

    public function evaluateRegression($eggSize) {
        $history = $this->getTrainingData($eggSize);
        if (count($history) < 14) {
            return [
                'egg_size' => $eggSize,
                'sample_count' => count($history),
                'message' => 'Need at least 14 samples for regression evaluation',
                'mse' => 0, 'mae' => 0, 'rmse' => 0, 'mape' => 0,
                'r2' => 0, 'r2_percent' => 0, 'adj_r2' => 0,
                'train_count' => 0, 'test_count' => 0
            ];
        }

        $split = $this->trainTestSplit($history, 0.2);
        $train = $split['train'];
        $test = $split['test'];

        $normRanges = $this->computeNormRanges($train);

        $predictions = [];
        $actuals = [];

        foreach ($test as $sample) {
            $target = $this->extractFeatures($sample['egg_size'], $sample['date']);
            $result = $this->weightedKNN($target, $train, $normRanges, 'actual_demand');
            $predictions[] = $result['prediction'];
            $actuals[] = $sample['actual_demand'];
        }

        $n = count($predictions);
        $totalAbsError = 0;
        $totalSquaredError = 0;
        $totalPercError = 0;

        for ($i = 0; $i < $n; $i++) {
            $error = abs($actuals[$i] - $predictions[$i]);
            $totalAbsError += $error;
            $totalSquaredError += pow($actuals[$i] - $predictions[$i], 2);
            $totalPercError += $actuals[$i] > 0 ? ($error / $actuals[$i]) * 100 : 0;
        }

        $mae = $totalAbsError / $n;
        $mse = $totalSquaredError / $n;
        $rmse = sqrt($mse);
        $mape = $totalPercError / $n;

        $actualMean = array_sum($actuals) / $n;
        $ssRes = 0;
        $ssTot = 0;
        for ($i = 0; $i < $n; $i++) {
            $ssRes += pow($actuals[$i] - $predictions[$i], 2);
            $ssTot += pow($actuals[$i] - $actualMean, 2);
        }
        $r2 = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;
        $k_features = count($this->features);
        $adjR2 = $n > $k_features + 1 ? 1 - (1 - $r2) * ($n - 1) / ($n - $k_features - 1) : $r2;

        return [
            'egg_size' => $eggSize,
            'sample_count' => count($history),
            'train_count' => $split['train_count'],
            'test_count' => $split['test_count'],
            'mse' => round($mse, 4),
            'mae' => round($mae, 2),
            'rmse' => round($rmse, 2),
            'mape' => round($mape, 2),
            'r2' => round($r2, 4),
            'r2_percent' => round($r2 * 100, 2),
            'adj_r2' => round($adjR2, 4),
            'adj_r2_percent' => round($adjR2 * 100, 2),
            'ss_res' => round($ssRes, 4),
            'ss_tot' => round($ssTot, 4),
            'test_predictions' => array_map(fn($i) => [
                'actual' => $actuals[$i],
                'predicted' => round($predictions[$i], 2),
                'error' => round(abs($actuals[$i] - $predictions[$i]), 2)
            ], range(0, $n - 1))
        ];
    }

    public function retrainModel() {
        $sizes = $this->db->fetchAll("SELECT size_code FROM egg_sizes");
        $version = $this->getNextVersion();

        $totalMape = 0;
        $sizeCount = 0;
        $kPerSize = [];
        $accuracyPerSize = [];

        foreach ($sizes as $size) {
            $history = $this->getTrainingData($size['size_code']);
            if (count($history) >= 14) {
                $k = $this->findOptimalK($size['size_code'], $history);
                $score = $this->crossValidate($history);
                $accuracyPerSize[$size['size_code']] = $score;
                // Convert accuracy score to pseudo-MAPE for logging
                $mape = round((1 - $score) * 100, 2);
                $totalMape += $mape;
                $sizeCount++;
                $kPerSize[$size['size_code']] = $k;
            }
        }

        // Without this guard the average stayed 0, which the UI reads as 100%
        // accuracy -- recording a perfect score for a model that never trained.
        if ($sizeCount === 0) {
            throw new RuntimeException(
                'Not enough sales history to train a model. Each size needs at least 14 recorded days.'
            );
        }

        $avgMape = $totalMape / $sizeCount;
        $avgAccuracy = 1 - ($avgMape / 100);

        // Hard floor: reject if below 60%
        if ($avgAccuracy < self::MIN_ACCURACY_FLOOR) {
            throw new RuntimeException(
                'Model accuracy below 60% floor (got ' . round($avgAccuracy * 100, 1) . '%). ' .
                'Need more data before retraining.'
            );
        }

        // Determine accuracy tier
        $accuracyTier = $avgAccuracy >= self::TARGET_ACCURACY ? 'target_met' : 'improving';

        // K is chosen per size, so $this->k at this point only holds the LAST size
        // processed. Store the most common value instead, which is what a single
        // integer column can honestly represent, and keep the full map alongside it.
        $kCounts = array_count_values($kPerSize);
        arsort($kCounts);
        $modalK = (int)array_key_first($kCounts);

        $this->db->insert(
            "INSERT INTO model_versions (version_name, training_date, training_samples, optimal_k_value, mape_score, features_used, is_active, created_at)
             VALUES (?, NOW(), ?, ?, ?, ?, 1, NOW())",
            [
                $version,
                $this->getTotalTrainingSamples(),
                $modalK,
                $avgMape,
                json_encode([
                    'features' => $this->features,
                    'k_per_size' => $kPerSize,
                    'accuracy_per_size' => $accuracyPerSize,
                    'avg_accuracy' => round($avgAccuracy * 100, 1),
                    'accuracy_tier' => $accuracyTier,
                    'target_accuracy' => self::TARGET_ACCURACY * 100
                ])
            ]
        );

        $this->db->update(
            "UPDATE model_versions SET is_active = 0 WHERE version_name != ?",
            [$version]
        );

        return [
            'version' => $version,
            'mape' => round($avgMape, 2),
            'accuracy' => round($avgAccuracy * 100, 1),
            'accuracy_tier' => $accuracyTier,
            'target_accuracy' => self::TARGET_ACCURACY * 100,
            'k' => $modalK,
            'k_per_size' => $kPerSize,
            'accuracy_per_size' => $accuracyPerSize,
            'samples' => $this->getTotalTrainingSamples(),
            'sizes_trained' => $sizeCount
        ];
    }

    private function getTotalTrainingSamples() {
        $result = $this->db->fetchOne("SELECT COUNT(*) as total FROM sales_history");
        return $result['total'] ?? 0;
    }

    private function getTrayCapacity($eggSize) {
        $result = $this->db->fetchOne(
            "SELECT tray_capacity FROM egg_sizes WHERE size_code = ?",
            [$eggSize]
        );
        return $result['tray_capacity'] ?? 30;
    }

    private function getNextVersion() {
        $last = $this->db->fetchOne(
            "SELECT version_name FROM model_versions ORDER BY id DESC LIMIT 1"
        );

        if (!$last || !preg_match('/^v(\d+)(?:\.(\d+))?$/', $last['version_name'], $m)) {
            return 'v1';
        }

        $major = (int)$m[1];
        $minor = isset($m[2]) ? (int)$m[2] : 0;

        if ($minor >= 9) {
            return 'v' . ($major + 1);
        }

        return 'v' . $major . '.' . ($minor + 1);
    }

    private function isPHHoliday($date) {
        return isHoliday($date);
    }

    private function getSeasonIndex($date) {
        return getSeasonIndex($date);
    }

    private function savePrediction($prediction) {
        $existing = $this->db->fetchOne(
            "SELECT id FROM knn_predictions WHERE prediction_date = ? AND egg_size = ?",
            [$prediction['prediction_date'], $prediction['egg_size']]
        );

        if ($existing) {
            $this->db->update(
                "UPDATE knn_predictions SET predicted_trays = ?, predicted_sales = ?,
                 confidence_lower = ?, confidence_upper = ?, confidence_level = ?,
                 model_version = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $prediction['predicted_trays'],
                    $prediction['predicted_sales'] ?? null,
                    $prediction['confidence_lower'],
                    $prediction['confidence_upper'],
                    $prediction['confidence_level'] ?? 'low',
                    $prediction['k_used'] ?? 5,
                    $existing['id']
                ]
            );
        } else {
            $this->db->insert(
                "INSERT INTO knn_predictions (prediction_date, egg_size, predicted_trays,
                 predicted_sales, confidence_lower, confidence_upper, confidence_level,
                 model_version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $prediction['prediction_date'],
                    $prediction['egg_size'],
                    $prediction['predicted_trays'],
                    $prediction['predicted_sales'] ?? null,
                    $prediction['confidence_lower'],
                    $prediction['confidence_upper'],
                    $prediction['confidence_level'] ?? 'low',
                    $prediction['k_used'] ?? 5
                ]
            );
        }
    }
}
