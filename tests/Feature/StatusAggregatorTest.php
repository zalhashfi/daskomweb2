<?php

namespace Tests\Feature;

use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Services\Jawaban\StatusAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers StatusAggregator: finalize() stamps finalized_at only when every
 * evaluation of the submission is terminal, and summary() buckets the
 * evaluations into done / failed / tidak_menjawab / pending.
 *
 * `tidak_menjawab` is a VERDICT of a `done` evaluation — it must never be
 * counted as an ordinary `done`, nor as a status of its own.
 */
class StatusAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private StatusAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->aggregator = new StatusAggregator;
    }

    // ---------------------------------------------------------------------
    // finalize()
    // ---------------------------------------------------------------------

    public function test_two_done_and_one_failed_with_a_pending_sibling_is_not_finalized(): void
    {
        $submission = $this->submissionWithEvaluations([
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_FAILED,
            AiEvaluation::STATUS_PENDING,
        ]);

        $this->assertFalse($this->aggregator->finalize($submission));
        $this->assertNull($submission->fresh()->finalized_at);
    }

    public function test_two_done_and_one_failed_are_all_terminal_and_finalized(): void
    {
        // done and failed are both terminal: nothing is still waiting.
        $submission = $this->submissionWithEvaluations([
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_FAILED,
        ]);

        $this->assertTrue($this->aggregator->finalize($submission));
        $this->assertNotNull($submission->fresh()->finalized_at);
    }

    public function test_pending_or_processing_evaluations_block_finalization(): void
    {
        foreach ([AiEvaluation::STATUS_PENDING, AiEvaluation::STATUS_PROCESSING] as $status) {
            $submission = $this->submissionWithEvaluations([
                AiEvaluation::STATUS_DONE,
                $status,
            ]);

            $this->aggregator->finalize($submission);

            $this->assertNull(
                $submission->fresh()->finalized_at,
                "Status {$status} belum terminal, finalized_at harus null."
            );
        }
    }

    public function test_all_terminal_evaluations_are_finalized(): void
    {
        $submission = $this->submissionWithEvaluations([
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_FAILED,
            AiEvaluation::STATUS_MANUAL_REQUIRED,
            AiEvaluation::STATUS_KEY_PENDING,
        ]);

        $this->assertTrue($this->aggregator->finalize($submission));

        $submission->refresh();
        $this->assertNotNull($submission->finalized_at);
        $this->assertTrue($submission->finalized_at->isPast() || $submission->finalized_at->isNow());
    }

    public function test_submission_without_evaluations_is_not_final(): void
    {
        $submission = $this->makeSubmission();

        $this->assertFalse($this->aggregator->finalize($submission));
        $this->assertNull($submission->fresh()->finalized_at);

        $this->assertSame(
            ['done' => 0, 'failed' => 0, 'tidak_menjawab' => 0, 'pending' => 0],
            $this->aggregator->summary($submission)
        );
    }

    public function test_repeated_finalize_keeps_the_first_timestamp(): void
    {
        $submission = $this->submissionWithEvaluations([AiEvaluation::STATUS_DONE]);

        $this->assertTrue($this->aggregator->finalize($submission));
        $first = $submission->fresh()->finalized_at;

        $this->travel(10)->minutes();
        $this->assertTrue($this->aggregator->finalize($submission->fresh()));

        $this->assertEquals($first, $submission->fresh()->finalized_at);
    }

    // ---------------------------------------------------------------------
    // summary()
    // ---------------------------------------------------------------------

    public function test_tidak_menjawab_is_a_verdict_and_not_a_plain_done(): void
    {
        $submission = $this->submissionWithEvaluations([
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_DONE,
        ], [
            'sesuai',
            'berbeda',
            StatusAggregator::VERDICT_TIDAK_MENJAWAB,
        ]);

        $summary = $this->aggregator->summary($submission);

        $this->assertSame(2, $summary['done'], 'Hanya done non-tidak_menjawab yang dihitung done.');
        $this->assertSame(1, $summary['tidak_menjawab']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['pending']);
    }

    public function test_summary_counts_all_four_categories_on_a_mixture(): void
    {
        $submission = $this->submissionWithEvaluations([
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_FAILED,
            AiEvaluation::STATUS_MANUAL_REQUIRED,
            AiEvaluation::STATUS_KEY_PENDING,
            AiEvaluation::STATUS_PENDING,
            AiEvaluation::STATUS_PROCESSING,
        ], [
            'sesuai',
            'perlu_cek_manual',
            StatusAggregator::VERDICT_TIDAK_MENJAWAB,
            null,
            null,
            null,
            null,
            null,
        ]);

        $this->assertSame(
            ['done' => 2, 'failed' => 3, 'tidak_menjawab' => 1, 'pending' => 2],
            $this->aggregator->summary($submission)
        );
    }

    public function test_summary_ignores_evaluations_of_other_submissions(): void
    {
        $submission = $this->submissionWithEvaluations([AiEvaluation::STATUS_DONE]);

        // Another answer row, not tied to this submission, belongs to someone else.
        $other = $this->makeSubmission();
        $this->createEvaluation($submission, $other->jawaban_id, 'tp', AiEvaluation::STATUS_FAILED);

        // Same jawaban_id but a different jawaban_type is not this submission either.
        $this->createEvaluation($submission, $submission->jawaban_id, 'tm', AiEvaluation::STATUS_PENDING, null, 9);

        $this->assertSame(
            ['done' => 1, 'failed' => 0, 'tidak_menjawab' => 0, 'pending' => 0],
            $this->aggregator->summary($submission)
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeSubmission(): JawabanSubmission
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $submission = JawabanSubmission::query()->create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ]);

        $jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban uji.',
        ]);

        $submission->forceFill(['jawaban_id' => $jawaban->id])->save();

        return $submission;
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string|null>|null  $verdicts
     */
    private function submissionWithEvaluations(array $statuses, ?array $verdicts = null): JawabanSubmission
    {
        $submission = $this->makeSubmission();

        foreach ($statuses as $index => $status) {
            $this->createEvaluation($submission, $submission->jawaban_id, 'tp', $status, $verdicts[$index] ?? null, $index);
        }

        return $submission->fresh();
    }

    private function createEvaluation(
        JawabanSubmission $submission,
        int $jawabanId,
        string $jawabanType,
        string $status,
        ?string $verdict = null,
        int $index = 0,
    ): AiEvaluation {
        return AiEvaluation::query()->create([
            'jawaban_id' => $jawabanId,
            'jawaban_type' => $jawabanType,
            'answer_hash' => hash('sha256', $submission->id.':'.$index),
            'version_set_id' => 0,
            'ai_config_id' => 0,
            'status' => $status,
            'verdict' => $verdict,
            'finished_at' => in_array($status, AiEvaluation::TERMINAL_STATUSES, true) ? now() : null,
        ]);
    }
}
