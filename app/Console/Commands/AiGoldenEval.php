<?php

namespace App\Console\Commands;

use App\Models\Modul;
use App\Services\Ai\GoldenSetEvaluator;
use Illuminate\Console\Command;

/**
 * Golden-set harness: how close the AI score is to the hand grade.
 *
 *   php artisan ai:golden-eval [--modul=<id|judul>] [--soal-type=tp]
 *
 * Pairs every `ai_golden_sets` label with the answer's `done`
 * `ai_evaluations.ai_score`, computes the MAE and the Pearson correlation
 * against `expected_score`, prints `mae=... korelasi=... n=...` and writes
 * the run (summary + per-answer pairs) to storage/logs/golden-eval.json.
 * The database is never written.
 *
 * Exit codes:
 *  - 0 : MAE <= 15 and korelasi >= 0.7;
 *  - 1 : either threshold is missed, fewer than 5 samples, or the filter is
 *        invalid.
 */
class AiGoldenEval extends Command
{
    public const LOG_FILE = 'logs/golden-eval.json';

    public const MIN_SAMPLES = 5;

    public const MAX_MAE = 15.0;

    public const MIN_KORELASI = 0.7;

    /**
     * @var string
     */
    protected $signature = 'ai:golden-eval
                            {--modul= : Limit to one modul id or judul}
                            {--soal-type= : Limit to one soal type (ta, tp, tk, jurnal, fitb, tm)}';

    /**
     * @var string
     */
    protected $description = 'Compute MAE and correlation of ai_score against the golden-set ground truth';

    public function handle(GoldenSetEvaluator $evaluator): int
    {
        $modulId = $this->resolveModul();

        if ($modulId === false) {
            $this->error('Modul tidak ditemukan untuk --modul='.$this->option('modul').'.');

            return self::FAILURE;
        }

        $soalType = $this->resolveSoalType();

        if ($soalType === false) {
            $this->error('--soal-type harus salah satu dari: '.implode(', ', Modul::QUESTION_TYPES).'.');

            return self::FAILURE;
        }

        $result = $evaluator->evaluate($modulId, $soalType);
        $passed = $this->passes($result);
        $logPath = $this->writeLog($modulId, $soalType, $result, $passed);

        if ($result['n'] < self::MIN_SAMPLES) {
            $this->error(sprintf(
                'data tidak cukup: n=%d (minimal %d sampel golden set berlabel dengan ai_score).',
                $result['n'],
                self::MIN_SAMPLES,
            ));

            return self::FAILURE;
        }

        $this->line(sprintf(
            'mae=%.2f korelasi=%s n=%d',
            $result['mae'],
            $result['korelasi'] === null ? 'undefined' : number_format($result['korelasi'], 4, '.', ''),
            $result['n'],
        ));
        $this->line('log='.$logPath);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Both gates must hold: a low MAE with random scores fails the
     * correlation gate, a high correlation with a constant offset fails the
     * MAE gate.
     *
     * @param  array{mae: float, korelasi: float|null, n: int}  $result
     */
    private function passes(array $result): bool
    {
        return $result['n'] >= self::MIN_SAMPLES
            && $result['mae'] <= self::MAX_MAE
            && $result['korelasi'] !== null
            && $result['korelasi'] >= self::MIN_KORELASI;
    }

    /**
     * The `--modul` option as an id: null when absent, false when it matches
     * no modul.
     */
    private function resolveModul(): int|null|false
    {
        $option = $this->option('modul');

        if ($option === null || $option === '') {
            return null;
        }

        $option = trim((string) $option);

        if (ctype_digit($option)) {
            $exists = Modul::query()->whereKey((int) $option)->exists();

            return $exists ? (int) $option : false;
        }

        $ids = Modul::query()->where('judul', $option)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : false;
    }

    /**
     * The `--soal-type` option lower-cased (aliases `mandiri` => `tm`):
     * null when absent, false when unknown.
     */
    private function resolveSoalType(): string|null|false
    {
        $option = $this->option('soal-type');

        if ($option === null || $option === '') {
            return null;
        }

        $soalType = strtolower(trim((string) $option));
        $soalType = $soalType === 'mandiri' ? 'tm' : $soalType;

        return in_array($soalType, Modul::QUESTION_TYPES, true) ? $soalType : false;
    }

    /**
     * Write the run to storage/logs (never storage/app, never the DB) and
     * return the file path.
     *
     * @param  array{pairs: list<array<string, mixed>>, mae: float, korelasi: float|null, n: int}  $result
     */
    private function writeLog(?int $modulId, ?string $soalType, array $result, bool $passed): string
    {
        $path = storage_path(self::LOG_FILE);
        $logDirectory = dirname($path);

        if (! is_dir($logDirectory)) {
            mkdir($logDirectory, 0775, true);
        }

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'modul' => $modulId,
            'soal_type' => $soalType,
            'mae' => round($result['mae'], 4),
            'korelasi' => $result['korelasi'] === null ? null : round($result['korelasi'], 4),
            'n' => $result['n'],
            'passed' => $passed,
            'samples' => $result['pairs'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
