<?php
require_once __DIR__ . '/Database.php';

class ModelEvaluator {
    private $db;
    private $predictor = null;
    private $backtestCache = [];
    private $metricsCache = null;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function evaluateAllMetrics($eggSize = null) {
        // Scoring runs a walk-forward backtest per size, which is far too expensive to
        // repeat for every gauge on the page.
        if ($eggSize === null && $this->metricsCache !== null) {
            return $this->metricsCache;
        }

        $this->syncActualSales();
        $metrics = [];
        $sizes = $eggSize ? [['size_code' => $eggSize]] : $this->db->fetchAll("SELECT size_code FROM egg_sizes");

        foreach ($sizes as $size) {
            $sc = is_array($size) ? $size['size_code'] : $size;
            $metrics[$sc] = $this->evaluateForSize($sc);
        }

        if ($eggSize === null) {
            $this->metricsCache = $metrics;
        }

        return $metrics;
    }

    public function syncActualSales() {
        try {
            $this->db->update(
                "UPDATE knn_predictions k
                 LEFT JOIN (
                     SELECT sale_date, egg_size, SUM(trays_sold) AS trays
                     FROM sales_history
                     GROUP BY sale_date, egg_size
                 ) sh ON sh.sale_date = k.prediction_date AND sh.egg_size = k.egg_size
                 SET k.actual_trays_sold = COALESCE(sh.trays, 0), k.updated_at = NOW()
                 WHERE k.actual_trays_sold IS NULL AND k.prediction_date < CURDATE()"
            );
        } catch (Exception $e) {
            error_log("syncActualSales failed: " . $e->getMessage());
        }
        return true;
    }

    /**
     * Scores the model against a chronological walk-forward backtest rather than against
     * whatever happens to be sitting in knn_predictions. The stored table can be seeded,
 * stale, or partially written by cron; the backtest is reproducible from sales_history
 * alone and can never train on a day it is scoring.
     */
    public function evaluateForSize($eggSize) {
        $walk = $this->backtestRows($eggSize);

        if (count($walk) < 5) {
            return $this->emptyMetrics($eggSize);
        }

        $predictions = [];
        foreach ($walk as $row) {
            $predictions[] = [
                'prediction_date' => $row['date'],
                'predicted_trays' => round($row['predicted']),
                'actual_trays_sold' => (float)$row['actual'],
                'tray_capacity' => $row['tray_capacity']
            ];
        }

        $n = count($predictions);

        // Calculate basic error metrics
        $totalAbsError = 0;
        $totalPercError = 0;
        $totalSquaredError = 0;

        foreach ($predictions as $p) {
            $actual = (float)$p['actual_trays_sold'];
            $predicted = (float)$p['predicted_trays'];
            $error = abs($actual - $predicted);
            $totalAbsError += $error;
            $totalPercError += $actual > 0 ? ($error / $actual) * 100 : 0;
            $totalSquaredError += pow($actual - $predicted, 2);
        }

        $mae = $totalAbsError / $n;
        $mse = $totalSquaredError / $n;
        $rmse = sqrt($mse);
        $mape = $totalPercError / $n;

        // R² Score (Coefficient of Determination)
        $actualMean = 0;
        foreach ($predictions as $p) {
            $actualMean += (float)$p['actual_trays_sold'];
        }
        $actualMean /= $n;

        $ssRes = 0; // sum of squared residuals
        $ssTot = 0; // total sum of squares
        foreach ($predictions as $p) {
            $actual = (float)$p['actual_trays_sold'];
            $predicted = (float)$p['predicted_trays'];
            $ssRes += pow($actual - $predicted, 2);
            $ssTot += pow($actual - $actualMean, 2);
        }
        $r2 = $ssTot > 0 ? round(1 - ($ssRes / $ssTot), 4) : 0;
        $r2Percent = round($r2 * 100, 2);

        // Adjusted R² (penalizes for number of features used)
        $k_features = 7; // number of features in KNNPredictor
        $adjR2 = $n > $k_features + 1 ? round(1 - (1 - $r2) * ($n - 1) / ($n - $k_features - 1), 4) : $r2;
        $adjR2Percent = round($adjR2 * 100, 2);

        // All metrics based on ±1 tray tolerance (realistic for egg business)
        // Count predictions as "correct" if within ±1 tray of actual
        $within1 = 0;  // within ±1 tray
        $within0_5 = 0; // within ±0.5 tray (exact)
        $overPredict = 0;
        $underPredict = 0;

        foreach ($predictions as $p) {
            $actual = (float)$p['actual_trays_sold'];
            $predicted = (float)$p['predicted_trays'];
            $diff = $predicted - $actual;
            $absDiff = abs($diff);

            if ($absDiff <= 0.5) $within0_5++;
            if ($absDiff <= 1) $within1++;

            if ($diff > 0.5) $overPredict++;
            elseif ($diff < -0.5) $underPredict++;
        }

        // Tolerance-based metrics
        $accuracy = ($within1 / $n) * 100;  // % within ±1 tray
        $exactAccuracy = ($within0_5 / $n) * 100; // % within ±0.5 tray

        // No Over-Predict = how many predictions were not over-predictions
        $precision = (($n - $overPredict) / $n) * 100;
        // No Under-Predict = how many predictions were not under-predictions
        $recall = (($n - $underPredict) / $n) * 100;
        // Balanced Score = harmonic mean of the two above
        $f1 = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0;

        $confusionMatrix = [
            'within_tolerance' => $within1,
            'exact_match' => $within0_5,
            'over_predicted' => $overPredict,
            'under_predicted' => $underPredict,
            'total' => $n
        ];

        $firstDate = $predictions[0]['prediction_date'];
        $lastDate = $predictions[$n - 1]['prediction_date'];

        return [
            'egg_size' => $eggSize,
            'sample_count' => $n,
            // Classification-style metrics (existing)
            'accuracy' => round($accuracy, 2),
            'accuracy_status' => $this->getStatus($accuracy, 90),
            'exact_accuracy' => round($exactAccuracy, 2),
            'precision' => round($precision, 2),
            'precision_status' => $this->getStatus($precision, 90),
            'recall' => round($recall, 2),
            'recall_status' => $this->getStatus($recall, 90),
            'f1_score' => round($f1, 2),
            'f1_status' => $this->getStatus($f1, 90),
            // Regression metrics (new)
            'mse' => round($mse, 4),
            'mae' => round($mae, 2),
            'rmse' => round($rmse, 2),
            'mape' => round($mape, 2),
            'r2' => $r2,
            'r2_percent' => $r2Percent,
            'r2_status' => $this->getR2Status($r2),
            'adj_r2' => $adjR2,
            'adj_r2_percent' => $adjR2Percent,
            'ss_res' => round($ssRes, 4),
            'ss_tot' => round($ssTot, 4),
            // Existing
            'confusion_matrix' => $confusionMatrix,
            'tolerance_correct' => $within1,
            'exact_correct' => $within0_5,
            'evaluation_date' => date('Y-m-d'),
            'evaluation_source' => 'walk_forward_backtest',
            'evaluation_window' => $firstDate . ' .. ' . $lastDate,
            'calculation_details' => [
                'accuracy_formula' => "Hit Rate (within ±1 tray) = ({$within1} / {$n}) × 100 = " . round($accuracy, 2) . "%",
                'precision_formula' => "No Over-Predict (did not over-predict, sign only) = (" . ($n - $overPredict) . " / {$n}) × 100 = " . round($precision, 2) . "%",
                'recall_formula' => "No Under-Predict (did not under-predict, sign only) = (" . ($n - $underPredict) . " / {$n}) × 100 = " . round($recall, 2) . "%",
                'f1_formula' => "Balanced Score (harmonic mean of the two above) = 2 × (" . round($precision, 2) . " × " . round($recall, 2) . ") / ("
                    . round($precision, 2) . " + " . round($recall, 2) . ") = " . round($f1, 2) . "%",
            ]
        ];
    }

    /**
     * Walk-forward backtest rows for one size, cached per request because it is the
     * expensive part of scoring and evaluateForSize is called repeatedly by the gauges.
     */
    private function backtestRows($eggSize) {
        if (array_key_exists($eggSize, $this->backtestCache)) {
            return $this->backtestCache[$eggSize];
        }
        if ($this->predictor === null) {
            require_once __DIR__ . '/KNNPredictor.php';
            $this->predictor = new KNNPredictor();
        }
        [$rows] = $this->predictor->backtestSeries($eggSize);
        $this->backtestCache[$eggSize] = $rows;
        return $rows;
    }

    /** Live forecast + backtest in one pass, for the page-level accuracy figures. */
    public function getEvaluationSummary() {
        $all = $this->evaluateAllMetrics();
        $scored = array_filter($all, fn($m) => ($m['sample_count'] ?? 0) > 0);
        if (empty($scored)) {
            return ['days' => 0, 'from' => null, 'to' => null, 'source' => 'walk_forward_backtest'];
        }
        $dates = [];
        foreach ($scored as $m) {
            if (!empty($m['evaluation_window'])) {
                $parts = explode(' .. ', $m['evaluation_window']);
                $dates[] = $parts[0];
                $dates[] = $parts[1];
            }
        }
        sort($dates);
        return [
            'days' => (int)array_sum(array_column($scored, 'sample_count')),
            'from' => $dates[0] ?? null,
            'to' => $dates[count($dates) - 1] ?? null,
            'source' => 'walk_forward_backtest'
        ];
    }

    private function getDemandCategory($trays) {
        if ($trays >= 6) return 'HIGH';
        if ($trays >= 3) return 'MEDIUM';
        return 'LOW';
    }

    private function getStatus($value, $threshold) {
        if ($value >= $threshold) return 'green';
        if ($value >= $threshold * 0.85) return 'yellow';
        return 'red';
    }

    private function getR2Status($r2) {
        if ($r2 >= 0.75) return 'green';
        if ($r2 >= 0.5) return 'yellow';
        return 'red';
    }

    private function emptyMetrics($eggSize) {
        return [
            'egg_size' => $eggSize,
            'sample_count' => 0,
            'accuracy' => 0,
            'accuracy_status' => 'red',
            'exact_accuracy' => 0,
            'precision' => 0,
            'precision_status' => 'red',
            'recall' => 0,
            'recall_status' => 'red',
            'f1_score' => 0,
            'f1_status' => 'red',
            'mse' => 0,
            'mae' => 0,
            'rmse' => 0,
            'mape' => 0,
            'r2' => 0,
            'r2_percent' => 0,
            'r2_status' => 'red',
            'adj_r2' => 0,
            'adj_r2_percent' => 0,
            'ss_res' => 0,
            'ss_tot' => 0,
            'confusion_matrix' => ['within_tolerance' => 0, 'exact_match' => 0, 'over_predicted' => 0, 'under_predicted' => 0, 'total' => 0],
            'message' => 'Insufficient prediction data (minimum 5 samples needed)'
        ];
    }

    public function updateActualSales($eggSize, $date, $actualSold) {
        $this->db->update(
            "UPDATE knn_predictions SET actual_trays_sold = ?, updated_at = NOW()
             WHERE prediction_date = ? AND egg_size = ?",
            [$actualSold, $date, $eggSize]
        );
    }

    /**
     * Overall score shown on the dashboard.
     *
     * Accuracy on its own is misleading: the ±1 tray tolerance is generous, so a model
     * that is systematically short can still post a high accuracy while its recall
 * * sits near 50%. The headline score is therefore the F1 average, which falls when
     * either direction is unhealthy, and the accuracy average is reported alongside.
     */
    public function getOverallAccuracyGauge() {
        $metrics = $this->evaluateAllMetrics();
        $f1s = [];
        $accs = [];
        foreach ($metrics as $m) {
            if (($m['sample_count'] ?? 0) > 0) {
                $f1s[] = (float)($m['f1_score'] ?? 0);
                $accs[] = (float)($m['accuracy'] ?? 0);
            }
        }

        if (empty($f1s)) return ['level' => 'insufficient data', 'score' => 0, 'accuracy' => 0, 'color' => 'gray'];

        $score = array_sum($f1s) / count($f1s);
        $accAvg = array_sum($accs) / count($accs);

        if ($score >= 90) return ['level' => 'excellent', 'score' => round($score), 'accuracy' => round($accAvg), 'color' => 'green'];
        if ($score >= 80) return ['level' => 'good', 'score' => round($score), 'accuracy' => round($accAvg), 'color' => 'green'];
        if ($score >= 70) return ['level' => 'fair', 'score' => round($score), 'accuracy' => round($accAvg), 'color' => 'yellow'];
        if ($score >= 60) return ['level' => 'weak', 'score' => round($score), 'accuracy' => round($accAvg), 'color' => 'yellow'];
        return ['level' => 'poor', 'score' => round($score), 'accuracy' => round($accAvg), 'color' => 'red'];
    }

    public function getOverallMetrics() {
        $metrics = $this->evaluateAllMetrics();
        if (empty($metrics)) {
            return ['accuracy' => 0, 'precision' => 0, 'recall' => 0, 'f1' => 0, 'mae' => 0, 'mse' => 0, 'rmse' => 0, 'r2' => 0, 'sample_count' => 0];
        }

        $accuracy = []; $precision = []; $recall = []; $f1 = []; $mae = []; $mse = []; $rmse = []; $r2 = []; $samples = 0;
        foreach ($metrics as $m) {
            if (($m['sample_count'] ?? 0) > 0) {
                $accuracy[] = $m['accuracy'];
                $precision[] = $m['precision'];
                $recall[] = $m['recall'];
                $f1[] = $m['f1_score'];
                $mae[] = $m['mae'];
                $mse[] = $m['mse'];
                $rmse[] = $m['rmse'];
                $r2[] = $m['r2'];
                $samples += $m['sample_count'];
            }
        }

        return [
            'accuracy' => round($accuracy ? array_sum($accuracy) / count($accuracy) : 0, 2),
            'precision' => round($precision ? array_sum($precision) / count($precision) : 0, 2),
            'recall' => round($recall ? array_sum($recall) / count($recall) : 0, 2),
            'f1' => round($f1 ? array_sum($f1) / count($f1) : 0, 2),
            'mae' => round($mae ? array_sum($mae) / count($mae) : 0, 2),
            'mse' => round($mse ? array_sum($mse) / count($mse) : 0, 4),
            'rmse' => round($rmse ? array_sum($rmse) / count($rmse) : 0, 2),
            'r2' => round($r2 ? array_sum($r2) / count($r2) : 0, 4),
            'sample_count' => $samples
        ];
    }
}
