<?php

namespace App\Services\Ai;

use App\Models\AiEvaluation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIFO across modules (T22d): only ONE modul may hold non-terminal
 * evaluations (`queued` | `running` | `pending` | `processing`) at a time.
 *
 * `EvaluateAnswer::handle()` calls `shouldYield()` before it touches the
 * provider. A job belonging to a modul that is not at the head of the queue
 * releases itself back onto the queue with a delay; its row keeps its
 * non-terminal status (never terminal) and no provider request is made.
 *
 * Ordering is by `oldest_queued_at` — the earliest `created_at` of the
 * modul's non-terminal rows, i.e. the created_at of the oldest non-terminal
 * evaluation in the whole queue. That row's modul owns the queue; every other
 * modul waits. A modul only ever compares itself with the head row, so two
 * questions of the same modul never block each other and the head always gets
 * through — two moduls can therefore never run at the same time.
 *
 * The modul of an evaluation comes from its answer row (`jawaban_tps` /
 * `jawaban_mandiris`, keyed by `jawaban_type`), joined in SQL so the guard
 * stays one query per answer table regardless of queue size.
 */
class ModuleQueueGuard
{
    /**
     * Statuses that still need the queue's attention (everything except
     * `AiEvaluation::TERMINAL_STATUSES`).
     *
     * @var list<string>
     */
    public const NON_TERMINAL_STATUSES = [
        'queued',
        'running',
        'pending',
        'processing',
    ];

    /**
     * Default delay for `release()` while another modul is ahead.
     */
    public const RELEASE_DELAY_SECONDS = 15;

    /**
     * `jawaban_type` => the answer table carrying `modul_id`.
     *
     * The TP table is the fallback for unknown types, matching
     * `EvaluateAnswer::answerRow()`.
     *
     * @var array<string, string>
     */
    public const ANSWER_TABLES = [
        'tp' => 'jawaban_tps',
        'tm' => 'jawaban_mandiris',
    ];

    /**
     * `true` when another modul is ahead of `$modulId` in the FIFO order.
     */
    public function shouldYield(int $modulId): bool
    {
        $headModulId = $this->activeModulId();

        if ($headModulId === null) {
            // Empty queue: nothing to wait for.
            return false;
        }

        return $headModulId !== $modulId;
    }

    /**
     * The modul allowed to run right now (head of the FIFO queue), or null
     * when no modul has a non-terminal evaluation.
     */
    public function activeModulId(): ?int
    {
        $head = $this->headEvaluation();

        if ($head === null) {
            return null;
        }

        return $this->modulIdOf($head);
    }

    /**
     * Minimum non-terminal `created_at` of one modul, normalised the same way
     * MySQL stores it so the value can be compared in SQL.
     */
    public function oldestQueuedAt(int $modulId): ?string
    {
        $oldest = null;

        foreach (self::ANSWER_TABLES as $answerTable) {
            $candidate = $this->candidateQuery($answerTable)
                ->where($answerTable.'.modul_id', $modulId)
                ->min('ai_evaluations.created_at');

            if ($candidate !== null && ($oldest === null || $candidate < $oldest)) {
                $oldest = $candidate;
            }
        }

        return $oldest === null ? null : Carbon::parse($oldest)->format('Y-m-d H:i:s');
    }

    /**
     * Oldest non-terminal evaluation in the queue (lowest id on a tie).
     */
    private function headEvaluation(): ?object
    {
        $head = null;

        foreach (self::ANSWER_TABLES as $answerTable) {
            $candidate = $this->candidateQuery($answerTable)
                ->orderBy('ai_evaluations.created_at')
                ->orderBy('ai_evaluations.id')
                ->first([
                    'ai_evaluations.id',
                    'ai_evaluations.jawaban_id',
                    'ai_evaluations.jawaban_type',
                    'ai_evaluations.created_at',
                ]);

            if ($candidate !== null
                && ($head === null
                    || ((string) $candidate->created_at < (string) $head->created_at)
                    || ((string) $candidate->created_at === (string) $head->created_at
                        && (int) $candidate->id < (int) $head->id))) {
                $head = $candidate;
            }
        }

        return $head;
    }

    /**
     * Non-terminal evaluations whose answer row lives in `$answerTable`.
     */
    private function candidateQuery(string $answerTable): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('ai_evaluations')
            ->join($answerTable, $answerTable.'.id', '=', 'ai_evaluations.jawaban_id')
            ->whereIn('ai_evaluations.status', self::NON_TERMINAL_STATUSES);

        $isMandiri = $answerTable === self::ANSWER_TABLES['tm'];

        return $isMandiri
            ? $query->where('ai_evaluations.jawaban_type', 'tm')
            : $query->where('ai_evaluations.jawaban_type', '<>', 'tm');
    }

    private function modulIdOf(object $evaluation): ?int
    {
        $answerTable = self::ANSWER_TABLES[(string) $evaluation->jawaban_type]
            ?? self::ANSWER_TABLES['tp'];

        $modulId = DB::table($answerTable)
            ->where('id', $evaluation->jawaban_id)
            ->value('modul_id');

        return $modulId === null ? null : (int) $modulId;
    }
}
