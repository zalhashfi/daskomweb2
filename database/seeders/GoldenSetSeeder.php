<?php

namespace Database\Seeders;

use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\AiGoldenSet;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use Illuminate\Database\Seeder;

/**
 * Seeds a minimal golden set for `ai:golden-eval --modul= --soal-type=`.
 *
 * One modul with one soal_tp, five answered TP submissions, the `done`
 * ai_evaluations rows carrying the scores under test (`ai_score`) and five
 * `ai_golden_sets` labels (`expected_score`). The AI scores deliberately
 * differ from the labels, so the harness has a real MAE and a non-zero
 * correlation instead of a perfect fit.
 *
 * Idempotent per answer: re-running the seeder reuses the "Golden Set" modul
 * and answers (matched on the sample text) and updates the evaluation scores
 * / labels instead of duplicating them. All answers belong to the first
 * praktikan, because `nilais` (the human grade) is keyed by
 * (praktikan_id, modul_id) — the label itself lives in `expected_score`.
 */
class GoldenSetSeeder extends Seeder
{
    public const MODUL_JUDUL = 'Modul Golden Set AI';

    /**
     * `ai_score` (model output under test) => `expected_score` (hand grade).
     *
     * @var array<int, array{ai_score: float, expected_score: float}>
     */
    public const SAMPLES = [
        ['ai_score' => 90, 'expected_score' => 88],
        ['ai_score' => 78, 'expected_score' => 80],
        ['ai_score' => 72, 'expected_score' => 65],
        ['ai_score' => 55, 'expected_score' => 45],
        ['ai_score' => 30, 'expected_score' => 35],
    ];

    public function run(): void
    {
        $modul = Modul::query()->firstOrCreate(
            ['judul' => self::MODUL_JUDUL],
            [
                'deskripsi' => 'Modul contoh untuk evaluasi golden set AI.',
                'isEnglish' => false,
                'isUnlocked' => true,
            ],
        );

        $soal = SoalTp::query()->firstOrCreate(
            ['modul_id' => $modul->id, 'soal' => 'Jelaskan perbedaan proses dan thread beserta contohnya.'],
        );

        $configId = (int) (AiConfig::query()->value('id') ?? 0);

        foreach (self::SAMPLES as $index => $sample) {
            $praktikanId = Praktikan::query()->orderBy('id')->value('id')
                ?? Praktikan::factory()->create()->id;

            $jawaban = JawabanTp::query()
                ->where('modul_id', $modul->id)
                ->where('soal_id', $soal->id)
                ->where('jawaban', 'Jawaban contoh golden set #'.($index + 1).'.')
                ->first();

            if ($jawaban === null) {
                $jawaban = JawabanTp::query()->create([
                    'praktikan_id' => $praktikanId,
                    'soal_id' => $soal->id,
                    'modul_id' => $modul->id,
                    'submission_id' => $this->attempt($praktikanId, $modul->id, $index + 1)->id,
                    'jawaban' => 'Jawaban contoh golden set #'.($index + 1).'.',
                ]);
            }

            AiEvaluation::query()->updateOrCreate(
                [
                    'jawaban_id' => $jawaban->id,
                    'answer_hash' => hash('sha256', 'golden-set-'.$jawaban->id),
                    'version_set_id' => 0,
                    'ai_config_id' => $configId,
                ],
                [
                    'jawaban_type' => JawabanSubmission::TIPE_TP,
                    'status' => AiEvaluation::STATUS_DONE,
                    'ai_score' => $sample['ai_score'],
                    'max_score' => 100,
                    'finished_at' => now(),
                ],
            );

            AiGoldenSet::query()->updateOrCreate(
                [
                    'jawaban_type' => JawabanSubmission::TIPE_TP,
                    'jawaban_id' => $jawaban->id,
                ],
                [
                    'modul_id' => $modul->id,
                    'soal_id' => $soal->id,
                    'expected_score' => $sample['expected_score'],
                    'catatan' => 'Contoh golden set AI.',
                ],
            );
        }

        $this->command?->info(sprintf(
            'Golden set siap: modul #%d, %d sampel.',
            $modul->id,
            count(self::SAMPLES),
        ));
    }

    /**
     * The answer's own attempt, so a re-run finds the same (praktikan, modul,
     * tipe, attempt_no) row instead of tripping the unique key.
     */
    private function attempt(int $praktikanId, int $modulId, int $attemptNo): JawabanSubmission
    {
        return JawabanSubmission::query()->firstOrCreate([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modulId,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => $attemptNo,
        ]);
    }
}
