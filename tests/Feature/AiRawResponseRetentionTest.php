<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\RawResponsePruner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T29: 90 day retention for `ai_evaluations.raw_response`.
 *
 *  - `php artisan ai:prune-raw-responses` NULLs `raw_response` for
 *    evaluations whose `finished_at` is more than 90 days old;
 *  - the grading columns (verdict, ai_score, answer_hash, status) survive;
 *  - recent rows and rows without `finished_at` are untouched;
 *  - running the command twice is safe (idempotent);
 *  - the command is scheduled to run daily.
 */
class AiRawResponseRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Modul $modul;

    private AiConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-18 02:30:00');

        $this->modul = Modul::factory()->create();

        $this->config = AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => 'https://gateway.test',
            'chat_model' => 'google/gemini-flash',
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 500000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_evaluation_finished_100_days_ago_has_raw_response_pruned_and_verdict_kept(): void
    {
        $evaluation = $this->evaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'sesuai',
            'ai_score' => 90,
            'finished_at' => now()->subDays(100),
            'raw_response' => '{"verdict":"sesuai","score":90}',
        ]);

        $this->artisan('ai:prune-raw-responses')->assertExitCode(0);

        $fresh = $evaluation->fresh();

        $this->assertNull($fresh->raw_response);
        $this->assertSame('sesuai', $fresh->verdict);
        $this->assertSame('90.00', (string) $fresh->ai_score);
        $this->assertSame($evaluation->answer_hash, $fresh->answer_hash);
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_evaluation_finished_10_days_ago_is_not_touched(): void
    {
        $evaluation = $this->evaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'tidak_sesuai',
            'ai_score' => 40,
            'finished_at' => now()->subDays(10),
            'raw_response' => '{"verdict":"tidak_sesuai","score":40}',
        ]);

        $this->artisan('ai:prune-raw-responses')->assertExitCode(0);

        $fresh = $evaluation->fresh();

        $this->assertSame('{"verdict":"tidak_sesuai","score":40}', $fresh->raw_response);
        $this->assertSame('tidak_sesuai', $fresh->verdict);
        $this->assertSame('40.00', (string) $fresh->ai_score);
    }

    public function test_evaluation_without_finished_at_is_not_touched(): void
    {
        $evaluation = $this->evaluation([
            'status' => AiEvaluation::STATUS_QUEUED,
            'finished_at' => null,
            'raw_response' => '{"still":"running"}',
        ]);
        $evaluation->forceFill(['created_at' => now()->subDays(365)])->save();

        $this->artisan('ai:prune-raw-responses')->assertExitCode(0);

        $fresh = $evaluation->fresh();

        $this->assertSame('{"still":"running"}', $fresh->raw_response);
        $this->assertNull($fresh->finished_at);
        $this->assertSame(AiEvaluation::STATUS_QUEUED, $fresh->status);
    }

    public function test_command_is_idempotent(): void
    {
        $expired = $this->evaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'sebagian_sesuai',
            'ai_score' => 70,
            'finished_at' => now()->subDays(100),
            'raw_response' => '{"verdict":"sebagian_sesuai"}',
        ]);
        $recent = $this->evaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'sesuai',
            'ai_score' => 95,
            'finished_at' => now()->subDays(5),
            'raw_response' => '{"verdict":"sesuai"}',
        ]);

        $this->artisan('ai:prune-raw-responses')->assertExitCode(0);
        $this->artisan('ai:prune-raw-responses')->assertExitCode(0);

        $this->assertNull($expired->fresh()->raw_response);
        $this->assertSame('sebagian_sesuai', $expired->fresh()->verdict);
        $this->assertSame('{"verdict":"sesuai"}', $recent->fresh()->raw_response);
        $this->assertSame(1, AiEvaluation::query()->whereNotNull('raw_response')->count());
    }

    public function test_only_rows_older_than_the_90_day_cutoff_are_pruned(): void
    {
        $justInside = $this->evaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'finished_at' => now()->subDays(90)->addHour(),
            'raw_response' => '{"id":"inside"}',
        ]);
        $justOutside = $this->evaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'finished_at' => now()->subDays(90)->subHour(),
            'raw_response' => '{"id":"outside"}',
        ]);

        $this->artisan('ai:prune-raw-responses')->assertExitCode(0);

        $this->assertSame('{"id":"inside"}', $justInside->fresh()->raw_response);
        $this->assertNull($justOutside->fresh()->raw_response);
    }

    public function test_prune_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'ai:prune-raw-responses'));

        $this->assertCount(1, $events, 'ai:prune-raw-responses harus terjadwal di routes/console.php.');
        $this->assertSame('30 2 * * *', $events->first()->expression);
    }

    public function test_retention_window_is_90_days(): void
    {
        $this->assertSame(90, RawResponsePruner::RETENTION_DAYS);
    }

    /**
     * A done/queued evaluation with its answer row and all grading columns.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function evaluation(array $attributes = []): AiEvaluation
    {
        $praktikan = Praktikan::factory()->create();
        $soal = SoalTp::factory()->create(['modul_id' => $this->modul->id]);
        $versionSet = SoalVersionSet::query()->firstOrCreate(
            ['modul_id' => $this->modul->id, 'version_no' => 1],
            [
                'status' => SoalVersionSet::STATUS_APPROVED,
                'bobot_total' => 100,
                'activated_at' => now(),
            ],
        );

        SoalVersionItem::create([
            'version_set_id' => $versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'teks_snapshot' => $soal->soal,
            'jawaban_standar' => 'Kunci jawaban.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $this->modul->id,
            'tipe' => 'tp',
            'attempt_no' => 1,
        ]);

        $jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $this->modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban untuk soal '.$soal->id.'.',
        ]);

        return AiEvaluation::query()->create(array_merge([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'retention-'.$jawaban->id),
            'version_set_id' => $versionSet->id,
            'ai_config_id' => $this->config->id,
            'status' => AiEvaluation::STATUS_DONE,
            'max_score' => 100,
            'driver' => 'openai_compatible',
        ], $attributes));
    }
}
