<?php

namespace App\Http\Resources;

use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Models\Praktikan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Read side of the assistant answer screen (SPEC 02-01 §7.2, T23).
 *
 * Envelope, in this order:
 *
 *   success → data.provider_status → data.submission →
 *   data.jawabanData[] → data.praktikan → data.modul
 *
 * Every answer carries an `ai` block with exactly four keys —
 * `status`, `verdict`, `alasan`, `max_score`. The view deliberately cannot
 * carry the internal grading numbers: `ai_score`, `raw_response`,
 * `cited_chunk_ids`, `confidence`, the standard answer text (kunci) and the
 * prompt never leave the database here (SPEC §7.2 invariant / F6).
 *
 * `ai.status` resolution, per answer:
 *   1. no row matched in `ai_evaluations` → `no_submission`
 *   2. else the matched row's status (`queued`, `done`, `key_pending`, ...)
 *
 * Rows are matched on (jawaban_type = submission tipe, jawaban_id = answer
 * id); when several rows exist for one answer the newest wins.
 *
 * A submission without any answer row (missing, or all answers removed)
 * still answers 200, with every question carrying `ai.status =
 * no_submission`.
 */
class JawabanVerdictResource extends JsonResource
{
    /**
     * Status shown when no evaluation row exists for an answer.
     */
    public const STATUS_NO_SUBMISSION = 'no_submission';

    /**
     * @param  array{
     *     praktikan: Praktikan,
     *     modul: Modul,
     *     submission: JawabanSubmission|null,
     *     soal: Collection<int, object>,
     *     jawaban: Collection<int, object>,
     *     evaluations: Collection<int, AiEvaluation>|null,
     *     provider_status: string|null,
     * }  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * The assistant read endpoint returns this envelope directly (the
     * existing client reads `data.jawabanData` and the top-level `success`),
     * so no extra `data` wrapper is added on top of `toArray()`.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toResponse($request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->toArray($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'provider_status' => $this->resource['provider_status'] ?? null,
            'submission' => $this->submissionPayload(),
            'jawabanData' => $this->jawabanData(),
            'praktikan' => $this->resource['praktikan'],
            'modul' => $this->resource['modul'],
        ];
    }

    /**
     * @return array{id:int, attempt_no:int, submitted_at:?string, locked_at:?string}|null
     */
    private function submissionPayload(): ?array
    {
        $submission = $this->resource['submission'];

        if (! $submission instanceof JawabanSubmission) {
            return null;
        }

        return [
            'id' => (int) $submission->id,
            'attempt_no' => (int) $submission->attempt_no,
            'submitted_at' => $submission->created_at?->toIso8601String(),
            'locked_at' => $submission->locked_at?->toIso8601String(),
        ];
    }

    /**
     * One entry per soal of the modul, in soal order.
     *
     * @return list<array<string, mixed>>
     */
    private function jawabanData(): array
    {
        $jawaban = $this->resource['jawaban'];

        $rows = [];

        foreach ($this->resource['soal'] as $soal) {
            $answer = $jawaban->get((int) $soal->id);

            $rows[] = [
                'soal_id' => (int) $soal->id,
                'soal_text' => $soal->soal,
                'jawaban' => $answer === null ? '-' : (string) $answer->jawaban,
                'ai' => $this->aiBlock($answer === null ? null : (int) $answer->id),
            ];
        }

        return $rows;
    }

    /**
     * The public AI block of one answer: status, verdict, alasan, max_score.
     *
     * @return array{status:string, verdict:?string, alasan:?string, max_score:?int}
     */
    private function aiBlock(?int $answerId): array
    {
        // Collection keys may be strings after keyBy() on some drivers, so the
        // lookup falls back to an explicit scan instead of a typed get().
        $evaluation = null;

        if ($answerId !== null) {
            $evaluation = $this->evaluations()->first(
                fn (AiEvaluation $row): bool => (int) $row->jawaban_id === $answerId
            );
        }

        if (! $evaluation instanceof AiEvaluation) {
            return [
                'status' => self::STATUS_NO_SUBMISSION,
                'verdict' => null,
                'alasan' => null,
                'max_score' => null,
            ];
        }

        $maxScore = $evaluation->max_score;

        return [
            'status' => (string) $evaluation->status,
            'verdict' => $evaluation->verdict,
            'alasan' => $evaluation->alasan,
            'max_score' => $maxScore === null ? null : (int) $maxScore,
        ];
    }

    /**
     * Evaluation rows of this submission keyed by answer id, newest first.
     *
     * Loaded once by the controller with only the public columns, so the
     * heavy / withheld ones (`raw_response`, `cited_chunk_ids`) are never
     * even selected.
     *
     * @return Collection<int, AiEvaluation>
     */
    private function evaluations(): Collection
    {
        /** @var Collection<int, AiEvaluation>|null $rows */
        $rows = $this->resource['evaluations'] ?? null;

        return $rows ?? collect();
    }
}
