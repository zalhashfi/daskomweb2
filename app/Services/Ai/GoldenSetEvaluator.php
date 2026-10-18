<?php

namespace App\Services\Ai;

use App\Models\AiEvaluation;
use App\Models\AiGoldenSet;
use App\Models\Modul;
use App\Support\Ai\ScoreStatistics;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the golden set and pairs every labelled answer with the AI score
 * stored in `ai_evaluations`.
 *
 * Read-only: two SELECTs, nothing is written. A label only enters the sample
 * when
 *  - it matches the requested modul / soal type,
 *  - its answer has exactly one `done` evaluation, and
 *  - that row carries a non-null `ai_score`.
 *
 * The evaluation is joined on (jawaban_type, jawaban_id) without its
 * `version_set_id` / `ai_config_id` unique-key columns on purpose: a golden
 * answer that was re-evaluated under a newer config must still contribute
 * the label, and taking the MAX over the idempotency-key columns collapses
 * those candidates to the single row the `done` filter expects.
 */
class GoldenSetEvaluator
{
    public const SOAL_TYPES = Modul::QUESTION_TYPES;

    /**
     * @return array{
     *     pairs: list<array{jawaban_id: int, jawaban_type: string, ai_score: float, expected_score: float}>,
     *     mae: float,
     *     korelasi: float|null,
     *     n: int
     * }
     */
    public function evaluate(?int $modulId, ?string $soalType): array
    {
        $evaluationConnection = AiGoldenSet::query()->getConnection();

        $evaluations = $evaluationConnection
            ->table('ai_evaluations')
            ->where('status', AiEvaluation::STATUS_DONE)
            ->whereNotNull('ai_score')
            ->groupBy('jawaban_type', 'jawaban_id')
            ->select(['jawaban_type', 'jawaban_id'])
            ->selectRaw('max(version_set_id) as version_set_id')
            ->selectRaw('max(ai_config_id) as ai_config_id')
            ->selectRaw('max(ai_score) as ai_score');

        $query = AiGoldenSet::query()
            ->joinSub($evaluations, 'eval', function ($join): void {
                $join->on('eval.jawaban_id', '=', 'ai_golden_sets.jawaban_id')
                    ->on('eval.jawaban_type', '=', 'ai_golden_sets.jawaban_type');
            })
            ->when($modulId !== null, fn (Builder $scope): Builder => $scope->where('ai_golden_sets.modul_id', $modulId))
            ->when($soalType !== null, fn (Builder $scope): Builder => $scope->where('ai_golden_sets.jawaban_type', $soalType))
            ->orderBy('ai_golden_sets.id')
            ->select([
                'ai_golden_sets.jawaban_id',
                'ai_golden_sets.jawaban_type',
                'ai_golden_sets.expected_score',
                'eval.ai_score',
            ]);

        $pairs = $query->get()
            ->map(static fn (object $row): array => [
                'jawaban_id' => (int) $row->jawaban_id,
                'jawaban_type' => (string) $row->jawaban_type,
                'ai_score' => (float) $row->ai_score,
                'expected_score' => (float) $row->expected_score,
            ])
            ->all();

        $statistics = ScoreStatistics::measure(
            array_column($pairs, 'ai_score'),
            array_column($pairs, 'expected_score'),
        );

        return ['pairs' => $pairs] + $statistics;
    }
}
