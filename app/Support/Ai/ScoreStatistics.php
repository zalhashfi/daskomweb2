<?php

namespace App\Support\Ai;

/**
 * Error statistics between the AI score and a ground-truth grade.
 *
 * Both measures are over paired samples only (a row missing either score is
 * skipped). The correlation is Pearson's r and is null when undefined —
 * fewer than two pairs or zero variance on either series, where it would
 * divide by zero.
 */
final class ScoreStatistics
{
    /**
     * @param  list<float>  $aiScores
     * @param  list<float>  $groundTruth
     * @return array{mae: float, korelasi: float|null, n: int}
     */
    public static function measure(array $aiScores, array $groundTruth): array
    {
        $n = min(count($aiScores), count($groundTruth));

        if ($n === 0) {
            return ['mae' => 0.0, 'korelasi' => null, 'n' => 0];
        }

        $aiScores = array_slice(array_values($aiScores), 0, $n);
        $groundTruth = array_slice(array_values($groundTruth), 0, $n);

        $absoluteErrors = [];
        $covariance = 0.0;
        $varianceAi = 0.0;
        $varianceGroundTruth = 0.0;
        $meanAi = array_sum($aiScores) / $n;
        $meanGroundTruth = array_sum($groundTruth) / $n;

        foreach ($aiScores as $index => $aiScore) {
            $truth = $groundTruth[$index];

            $absoluteErrors[] = abs($aiScore - $truth);
            $covariance += ($aiScore - $meanAi) * ($truth - $meanGroundTruth);
            $varianceAi += ($aiScore - $meanAi) ** 2;
            $varianceGroundTruth += ($truth - $meanGroundTruth) ** 2;
        }

        $korelasi = ($n > 1 && $varianceAi > 0.0 && $varianceGroundTruth > 0.0)
            ? $covariance / sqrt($varianceAi * $varianceGroundTruth)
            : null;

        return [
            'mae' => array_sum($absoluteErrors) / $n,
            'korelasi' => $korelasi === null ? null : max(-1.0, min(1.0, $korelasi)),
            'n' => $n,
        ];
    }
}
