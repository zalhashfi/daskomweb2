<?php

namespace App\Services\Ai;

use App\Models\AiEvalMetric;
use Illuminate\Support\Carbon;

/**
 * Recomputes one day of ai_eval_metrics from ai_evaluations.
 *
 * The day is defined by `ai_evaluations.created_at` (the moment the
 * evaluation entered the queue). Two kinds of row are written per requested
 * day:
 *
 *  - global (`modul_id = NULL`): every evaluation created that day;
 *  - per modul (when `$perModul`): the same measures restricted to one modul,
 *    joined through the answer row carrying `modul_id` (jawaban_tps for
 *    `tp`, jawaban_mandiris for `tm`) exactly like ModuleQueueGuard does.
 *
 * Measures:
 *  - total / done / failed : row counts by status;
 *  - avg_confidence        : mean confidence over done rows (the
 *                            `confidence` column, or the value inside
 *                            `raw_response`);
 *  - p50/p95_latency_ms    : nearest-rank percentiles of done-row latency;
 *  - mae                   : mean |ai_score - manual_score| over done rows
 *                            where both scores exist (manual_score is the
 *                            assistant's `nilais.tp`); null when none do;
 *  - korelasi              : Pearson correlation between ai_score and the
 *                            assistant's manual score; null when undefined;
 *  - agreement             : share of done rows whose verdict agrees with the
 *                            assistant's manual verdict; null when no done row
 *                            carries a manual verdict.
 *
 * The run is idempotent: rows are replaced (`updateOrInsert`), so running the
 * command twice never duplicates a day.
 */
class MetricsAggregator
{
    /**
     * `jawaban_type` => the answer table carrying `modul_id` and
     * `praktikan_id`.
     *
     * @var array<string, string>
     */
    public const ANSWER_TABLES = [
        'tp' => 'jawaban_tps',
        'tm' => 'jawaban_mandiris',
    ];

    /**
     * Aggregate `$date` and return the written rows (global first).
     *
     * @return list<AiEvalMetric>
     */
    public function aggregate(Carbon $date, bool $perModul = true): array
    {
        $day = $date->toDateString();

        $rows = $this->evaluationRows($day);

        $written = [$this->store($day, null, $this->measures($rows))];

        if ($perModul) {
            foreach ($this->groupByModul($rows) as $modulId => $modulRows) {
                $written[] = $this->store($day, $modulId, $this->measures($modulRows));
            }
        }

        return $written;
    }

    /**
     * The union of `jawaban_tps` / `jawaban_mandiris` (id + modul +
     * praktikan), tagged with its `jawaban_type`, so an evaluation can be
     * joined to its answer row in one query (`jawaban_id` is only unique per
     * type).
     */
    private function answerUnion(): \Illuminate\Database\Query\Builder
    {
        $db = AiEvalMetric::query()->getConnection();
        $union = null;

        foreach (self::ANSWER_TABLES as $jawabanType => $answerTable) {
            $query = $db->table($answerTable)
                ->select([$answerTable.'.id', $answerTable.'.modul_id', $answerTable.'.praktikan_id'])
                ->selectRaw('? as answer_type', [$jawabanType]);

            $union = $union === null ? $query : $union->unionAll($query);
        }

        return $union;
    }

    /**
     * One row per evaluation created on `$day`, with the modul of its answer
     * and the assistant's manual TP grade for that (praktikan, modul) pair —
     * `nilais.tp`. A missing answer row or grade leaves both null.
     *
     * The manual verdict is inferred from the numeric grade: >= 70 sesuai,
     * >= 40 sebagian_sesuai, otherwise tidak_sesuai.
     *
     * @return list<object>
     */
    private function evaluationRows(string $day): array
    {
        $answers = $this->answerUnion();

        return AiEvalMetric::query()->getConnection()
            ->table('ai_evaluations')
            ->leftJoinSub($answers, 'answer', function ($join): void {
                $join->on('answer.id', '=', 'ai_evaluations.jawaban_id')
                    ->on('answer.answer_type', '=', 'ai_evaluations.jawaban_type');
            })
            ->leftJoin('nilais', function ($join): void {
                $join->on('nilais.praktikan_id', '=', 'answer.praktikan_id')
                    ->on('nilais.modul_id', '=', 'answer.modul_id');
            })
            ->whereDate('ai_evaluations.created_at', $day)
            ->orderBy('ai_evaluations.id')
            ->select([
                'ai_evaluations.id',
                'ai_evaluations.status',
                'ai_evaluations.verdict',
                'ai_evaluations.confidence',
                'ai_evaluations.latency_ms',
                'ai_evaluations.ai_score',
                'ai_evaluations.raw_response',
                'answer.modul_id as modul_id',
                'nilais.tp as manual_score',
            ])
            ->get()
            ->map(function (object $row): object {
                $row->manual_verdict = $row->manual_score === null
                    ? null
                    : self::manualVerdictOf((float) $row->manual_score);

                return $row;
            })
            ->all();
    }

    public static function manualVerdictOf(float $score): string
    {
        return match (true) {
            $score >= 70 => PromptBuilder::VERDICTS[0],
            $score >= 40 => PromptBuilder::VERDICTS[1],
            default => PromptBuilder::VERDICTS[2],
        };
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, list<object>>
     */
    private function groupByModul(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            if ($row->modul_id === null) {
                continue;
            }

            $grouped[(int) $row->modul_id][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<object>  $rows  one day (or one modul's day) of rows
     * @return array<string, mixed>
     */
    private function measures(array $rows): array
    {
        $done = array_values(array_filter(
            $rows,
            static fn (object $row): bool => $row->status === 'done',
        ));

        $latencies = array_values(array_filter(
            array_map(static fn (object $row): ?int => $row->latency_ms === null ? null : (int) $row->latency_ms, $done),
            static fn (?int $latency): bool => $latency !== null,
        ));
        sort($latencies);

        $confidences = array_values(array_filter(
            array_map(static fn (object $row): ?float => self::confidenceOf($row), $done),
            static fn (?float $confidence): bool => $confidence !== null,
        ));

        [$mae, $korelasi] = $this->scoreErrors($done);
        [$agreement, $agreementBase] = $this->agreement($done);

        return [
            'total' => count($rows),
            'done' => count($done),
            'failed' => count(array_filter($rows, static fn (object $row): bool => $row->status === 'failed')),
            'avg_confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 4),
            'p50_latency_ms' => $this->percentile($latencies, 50),
            'p95_latency_ms' => $this->percentile($latencies, 95),
            'mae' => $mae,
            'korelasi' => $korelasi,
            'agreement' => $agreementBase === 0 ? null : round($agreement / $agreementBase, 4),
        ];
    }

    /**
     * The row's `confidence`; when the column is empty (the job records it in
     * `raw_response` only) the model's self-reported confidence is read from
     * that JSON, and null is returned when neither is present.
     */
    private static function confidenceOf(object $row): ?float
    {
        if ($row->confidence !== null) {
            return (float) $row->confidence;
        }

        $payload = is_string($row->raw_response) ? json_decode($row->raw_response, true) : null;

        if (! is_array($payload) || ! is_numeric($payload['confidence'] ?? null)) {
            return null;
        }

        $confidence = (float) $payload['confidence'];

        return $confidence >= 0.0 && $confidence <= 1.0 ? $confidence : null;
    }

    /**
     * Mean absolute error and Pearson correlation of ai_score against the
     * manual grade, over done rows holding both. Both are null when the
     * sample is empty; the correlation is also null when either series has
     * zero variance (it would divide by zero).
     *
     * @param  list<object>  $done
     * @return array{0: float|null, 1: float|null}
     */
    private function scoreErrors(array $done): array
    {
        $pairs = [];

        foreach ($done as $row) {
            if ($row->ai_score === null || $row->manual_score === null) {
                continue;
            }

            $pairs[] = [(float) $row->ai_score, (float) $row->manual_score];
        }

        if ($pairs === []) {
            return [null, null];
        }

        $absoluteErrors = array_map(static fn (array $pair): float => abs($pair[0] - $pair[1]), $pairs);
        $mae = round(array_sum($absoluteErrors) / count($absoluteErrors), 4);

        $n = count($pairs);
        $meanAi = array_sum(array_column($pairs, 0)) / $n;
        $meanManual = array_sum(array_column($pairs, 1)) / $n;

        $covariance = 0.0;
        $varianceAi = 0.0;
        $varianceManual = 0.0;

        foreach ($pairs as [$aiScore, $manualScore]) {
            $covariance += ($aiScore - $meanAi) * ($manualScore - $meanManual);
            $varianceAi += ($aiScore - $meanAi) ** 2;
            $varianceManual += ($manualScore - $meanManual) ** 2;
        }

        $korelasi = ($varianceAi > 0.0 && $varianceManual > 0.0)
            ? round($covariance / sqrt($varianceAi * $varianceManual), 4)
            : null;

        return [$mae, $korelasi];
    }

    /**
     * Count of done rows whose verdict equals the manual verdict, and the
     * number of rows that could be compared.
     *
     * @param  list<object>  $done
     * @return array{0: int, 1: int}
     */
    private function columnsOf(array $pairs): array
    {
        $aiScores = [];
        $manualScores = [];

        foreach ($pairs as [$aiScore, $manualScore]) {
            $aiScores[] = $aiScore;
            $manualScores[] = $manualScore;
        }

        return [$aiScores, $manualScores];
    }

    private function agreement(array $done): array
    {
        $agree = 0;
        $comparable = 0;

        foreach ($done as $row) {
            if ($row->verdict === null || $row->manual_verdict === null) {
                continue;
            }

            $comparable++;

            if ($row->verdict === $row->manual_verdict) {
                $agree++;
            }
        }

        return [$agree, $comparable];
    }

    /**
     * Nearest-rank percentile over an ascending list.
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, int $percentile): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $rank = (int) ceil($percentile / 100 * $count);

        return $sorted[max(0, min($count, $rank) - 1)];
    }

    /**
     * Upsert one (stat_date, modul_id) row. The global row is looked up with
     * an explicit `modul_id IS NULL`, because MySQL's unique index does not
     * dedupe NULLs.
     *
     * @param  array<string, mixed>  $measures
     */
    private function store(string $day, ?int $modulId, array $measures): AiEvalMetric
    {
        $scope = AiEvalMetric::query()->whereDate('stat_date', $day);

        $scope = $modulId === null
            ? $scope->whereNull('modul_id')
            : $scope->where('modul_id', $modulId);

        /** @var AiEvalMetric $metric */
        $metric = $scope->first() ?? new AiEvalMetric(['stat_date' => $day, 'modul_id' => $modulId]);

        $metric->fill($measures);
        $metric->stat_date = $day;
        $metric->modul_id = $modulId;
        $metric->save();

        return $metric;
    }
}
