<?php

namespace Tests\Feature;

use App\Console\Commands\AiGoldenEval;
use App\Models\AiEvaluation;
use App\Models\AiGoldenSet;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use Database\Seeders\GoldenSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan ai:golden-eval --modul= --soal-type=` — the golden-set harness.
 *
 *  - prints `mae=... korelasi=... n=...`, writes storage/logs/golden-eval.json
 *    and never touches the DB;
 *  - exit 0 only when n >= 5, MAE <= 15 and korelasi >= 0.7;
 *  - exit 1 when the MAE, the correlation or the sample size gate fails.
 */
class AiGoldenEvalTest extends TestCase
{
    use RefreshDatabase;

    private Modul $modul;

    private SoalTp $soal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modul = Modul::factory()->create();
        $this->soal = SoalTp::factory()->create(['modul_id' => $this->modul->id]);

        if (is_file(storage_path(AiGoldenEval::LOG_FILE))) {
            unlink(storage_path(AiGoldenEval::LOG_FILE));
        }
    }

    protected function tearDown(): void
    {
        if (is_file(storage_path(AiGoldenEval::LOG_FILE))) {
            unlink(storage_path(AiGoldenEval::LOG_FILE));
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------

    public function test_prediction_equal_to_ground_truth_passes_with_zero_mae(): void
    {
        foreach ([90, 80, 70, 60, 50] as $score) {
            $this->seedPair($score, $score);
        }

        $exitCode = Artisan::call('ai:golden-eval', ['--modul' => (string) $this->modul->id, '--soal-type' => 'tp']);

        $out = Artisan::output();

        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('mae=0.00', $out);
        $this->assertStringContainsString('korelasi=1.0000', $out);
        $this->assertStringContainsString('n=5', $out);

        $log = $this->readLog();

        $this->assertSame(0.0, (float) $log['mae']);
        $this->assertSame(1.0, (float) $log['korelasi']);
        $this->assertSame(5, $log['n']);
        $this->assertTrue($log['passed']);
        $this->assertCount(5, $log['samples']);
        $this->assertSame($this->modul->id, $log['modul']);
        $this->assertSame('tp', $log['soal_type']);
    }

    public function test_output_contains_every_metric_and_the_run_is_written_to_storage_logs_not_the_db(): void
    {
        foreach ([90, 80, 70, 60, 50] as $score) {
            $this->seedPair($score, $score - 0.5);
        }

        $tables = ['ai_evaluations', 'ai_golden_sets', 'jawaban_tps', 'moduls', 'soal_tps'];
        $before = [];
        $after = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $exitCode = Artisan::call('ai:golden-eval');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('mae=0.50', $output);
        $this->assertStringContainsString('korelasi=1.0000', $output);
        $this->assertStringContainsString('n=5', $output);

        $path = storage_path(AiGoldenEval::LOG_FILE);
        $this->assertFileExists($path);
        $this->assertSame(storage_path('logs'), dirname(str_replace('/', DIRECTORY_SEPARATOR, $path)));
        $this->assertStringContainsString($path, $output);

        $log = $this->readLog();
        $this->assertSame(0.5, (float) $log['mae']);
        $this->assertSame(5, $log['n']);
        $this->assertNull($log['modul']);
        $this->assertNull($log['soal_type']);

        foreach ($tables as $table) {
            $after[$table] = DB::table($table)->count();
        }

        $this->assertSame($before, $after, 'Harness tidak boleh menulis ke DB.');
    }

    public function test_far_off_predictions_fail_on_mae(): void
    {
        foreach ([10, 20, 30, 40, 50] as $prediction) {
            $this->seedPair($prediction, 90);
        }

        $exitCode = Artisan::call('ai:golden-eval', ['--modul' => $this->modul->judul, '--soal-type' => 'tp']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mae=', $output);
        $this->assertStringContainsString('n=5', $output);

        $log = $this->readLog();
        $this->assertGreaterThan(AiGoldenEval::MAX_MAE, (float) $log['mae']);
        $this->assertFalse($log['passed']);
    }

    public function test_random_predictions_pass_mae_but_fail_on_correlation(): void
    {
        foreach ([55, 58, 61, 64, 67, 70] as $prediction) {
            $this->seedPair($prediction, 60.5);
        }

        $exitCode = Artisan::call('ai:golden-eval', ['--soal-type' => 'tp']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('korelasi=undefined', $output);
        $this->assertStringContainsString('n=6', $output);

        $log = $this->readLog();
        $this->assertLessThanOrEqual(AiGoldenEval::MAX_MAE, (float) $log['mae']);
        $this->assertNull($log['korelasi']);
        $this->assertFalse($log['passed']);
    }

    public function test_inverted_predictions_fail_on_negative_correlation(): void
    {
        foreach ([90 => 10, 80 => 20, 70 => 30, 60 => 40, 50 => 50] as $groundTruth => $prediction) {
            $this->seedPair($prediction, $groundTruth);
        }

        $exitCode = Artisan::call('ai:golden-eval', ['--soal-type' => 'tp']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('korelasi=-1.0000', $output);
        $this->assertStringContainsString('n=5', $output);
    }

    public function test_fewer_than_five_samples_exits_with_data_tidak_cukup(): void
    {
        foreach ([90, 80, 70, 60] as $score) {
            $this->seedPair($score, $score);
        }

        $exitCode = Artisan::call('ai:golden-eval', ['--soal-type' => 'tp']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('data tidak cukup', $output);
        $this->assertStringContainsString('n=4', $output);
    }

    public function test_answers_without_a_done_evaluation_or_label_are_not_counted(): void
    {
        foreach ([90, 80, 70, 60, 50] as $score) {
            $this->seedPair($score, $score);
        }

        $this->seedPair(95, 95, evaluationStatus: AiEvaluation::STATUS_FAILED);
        $this->seedPairWithoutLabel(88);

        $exitCode = Artisan::call('ai:golden-eval', ['--soal-type' => 'tp']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('n=5', $output);
    }

    public function test_modul_and_soal_type_filters_scope_the_sample(): void
    {
        foreach ([90, 80, 70, 60, 50] as $score) {
            $this->seedPair($score, $score);
        }

        $otherModul = Modul::factory()->create();
        $otherSoal = SoalTp::factory()->create(['modul_id' => $otherModul->id]);

        foreach ([10, 20, 30, 40, 50] as $score) {
            $this->seedPair($score, 90, modul: $otherModul, soal: $otherSoal);
        }

        $exitCode = Artisan::call('ai:golden-eval', ['--modul' => (string) $this->modul->id, '--soal-type' => 'tp']);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('mae=0.00 korelasi=1.0000 n=5', $output);

        $exitCode = Artisan::call('ai:golden-eval', ['--modul' => (string) $this->modul->id, '--soal-type' => 'tm']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('data tidak cukup', $output);
        $this->assertStringContainsString('n=0', $output);
    }

    public function test_unknown_modul_or_soal_type_is_rejected(): void
    {
        $this->assertSame(1, Artisan::call('ai:golden-eval', ['--modul' => '999999']));
        $output = Artisan::output();

        $this->assertStringContainsString('Modul tidak ditemukan', $output);

        $this->assertSame(1, Artisan::call('ai:golden-eval', ['--soal-type' => 'essay']));
        $output = Artisan::output();

        $this->assertStringContainsString('--soal-type', $output);
    }

    // ---------------------------------------------------------------------
    // GoldenSetSeeder
    // ---------------------------------------------------------------------

    public function test_golden_set_seeder_provides_a_usable_sample(): void
    {
        $this->seed(GoldenSetSeeder::class);

        $this->assertSame(count(GoldenSetSeeder::SAMPLES), AiGoldenSet::query()->count());

        $exitCode = Artisan::call('ai:golden-eval', [
            '--modul' => GoldenSetSeeder::MODUL_JUDUL,
            '--soal-type' => 'tp',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('n='.count(GoldenSetSeeder::SAMPLES), $output);

        $this->seed(GoldenSetSeeder::class);

        $this->assertSame(
            count(GoldenSetSeeder::SAMPLES),
            AiGoldenSet::query()->count(),
            'Seeder harus idempoten.',
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function seedPair(
        float $aiScore,
        float $expectedScore,
        ?Modul $modul = null,
        ?SoalTp $soal = null,
        string $evaluationStatus = AiEvaluation::STATUS_DONE,
    ): JawabanTp {
        $modul ??= $this->modul;
        $soal ??= $this->soal;

        $jawaban = $this->answer($modul, $soal, 'Jawaban '.fake()->sentence());

        AiEvaluation::query()->create([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'golden-'.$jawaban->id),
            'status' => $evaluationStatus,
            'ai_score' => $aiScore,
            'max_score' => 100,
            'finished_at' => now(),
        ]);

        AiGoldenSet::query()->create([
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'expected_score' => $expectedScore,
        ]);

        return $jawaban;
    }

    private function seedPairWithoutLabel(float $aiScore): JawabanTp
    {
        $jawaban = $this->answer($this->modul, $this->soal, 'Jawaban tanpa label.');

        AiEvaluation::query()->create([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'golden-'.$jawaban->id),
            'status' => AiEvaluation::STATUS_DONE,
            'ai_score' => $aiScore,
            'max_score' => 100,
            'finished_at' => now(),
        ]);

        return $jawaban;
    }

    /**
     * One answer row with its own submission (jawaban_tps.submission_id is
     * NOT NULL).
     */
    private function answer(Modul $modul, SoalTp $soal, string $text): JawabanTp
    {
        $praktikanId = Praktikan::factory()->create()->id;

        $submission = JawabanSubmission::query()->create([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ]);

        return JawabanTp::query()->create([
            'praktikan_id' => $praktikanId,
            'soal_id' => $soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => $text,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLog(): array
    {
        $path = storage_path(AiGoldenEval::LOG_FILE);

        $this->assertFileExists($path, 'Hasil harus ditulis ke storage/logs.');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
