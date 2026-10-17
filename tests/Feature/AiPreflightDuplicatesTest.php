<?php

namespace Tests\Feature;

use App\Models\JawabanSubmission;
use App\Models\Praktikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the read-only `ai:preflight-duplicates` command.
 *
 * The command is driven through Artisan so the summary line format, the
 * `--fail-on-duplicate` exit code contract and the read-only guarantee
 * (row counts unchanged) are all exercised end to end.
 */
class AiPreflightDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_without_duplicates_exits_zero_with_fail_option(): void
    {
        $slot = $this->makeSlot('tp');
        $this->insertJawaban('tp', ...$slot);

        $tmSlot = $this->makeSlot('tm');
        $this->insertJawaban('tm', ...$tmSlot);

        $exitCode = $this->artisan('ai:preflight-duplicates', ['--fail-on-duplicate' => true])
            ->expectsOutputToContain('tp=0 tm=0')
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_single_duplicate_group_in_jawaban_tps_exits_one(): void
    {
        $slot = $this->makeSlot('tp');
        $this->insertJawaban('tp', ...$slot);
        $this->insertJawaban('tp', ...$slot);

        $exitCode = $this->artisan('ai:preflight-duplicates', ['--fail-on-duplicate' => true])
            ->expectsOutputToContain('tp=1 tm=0')
            ->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_duplicate_group_in_jawaban_mandiris_exits_one(): void
    {
        $slot = $this->makeSlot('tm');
        $this->insertJawaban('tm', ...$slot);
        $this->insertJawaban('tm', ...$slot);

        $exitCode = $this->artisan('ai:preflight-duplicates', ['--fail-on-duplicate' => true])
            ->expectsOutputToContain('tp=0 tm=1')
            ->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_without_fail_option_exits_zero_even_with_duplicates(): void
    {
        $slot = $this->makeSlot('tp');
        $this->insertJawaban('tp', ...$slot);
        $this->insertJawaban('tp', ...$slot);

        $exitCode = $this->artisan('ai:preflight-duplicates')
            ->expectsOutputToContain('tp=1 tm=0')
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_summary_line_is_printed_exactly_once(): void
    {
        $slot = $this->makeSlot('tp');
        $this->insertJawaban('tp', ...$slot);
        $this->insertJawaban('tp', ...$slot);

        $exitCode = Artisan::call('ai:preflight-duplicates', ['--fail-on-duplicate' => true]);

        $output = Artisan::output();
        $this->assertSame(1, substr_count($output, 'tp=1 tm=0'));

        $summaryLines = array_values(array_filter(
            preg_split('/\R/', $output) ?: [],
            fn (string $line): bool => preg_match('/\btp=\d+ tm=\d+\b/', $line) === 1
        ));

        $this->assertSame(1, $exitCode);
        $this->assertSame(['tp=1 tm=0'], $summaryLines, 'Baris ringkas harus persis `tp=<N> tm=<M>` tanpa prefix.');
    }

    public function test_command_is_read_only(): void
    {
        $tpSlot = $this->makeSlot('tp');
        $this->insertJawaban('tp', ...$tpSlot);
        $this->insertJawaban('tp', ...$tpSlot);

        $tmSlot = $this->makeSlot('tm');
        $this->insertJawaban('tm', ...$tmSlot);
        $this->insertJawaban('tm', ...$tmSlot);
        $this->insertJawaban('tm', ...$tmSlot);

        $tpBefore = DB::table('jawaban_tps')->count();
        $tmBefore = DB::table('jawaban_mandiris')->count();


        $this->artisan('ai:preflight-duplicates', ['--fail-on-duplicate' => true])->run();

        $this->assertSame($tpBefore, DB::table('jawaban_tps')->count());
        $this->assertSame($tmBefore, DB::table('jawaban_mandiris')->count());
        $this->assertSame(2, $tpBefore);
        $this->assertSame(3, $tmBefore);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function makeSlot(string $key): array
    {
        $praktikanId = Praktikan::factory()->create()->id;

        $modulId = DB::table('moduls')->insertGetId([
            'judul' => 'Modul '.$key,
            'deskripsi' => 'Deskripsi '.$key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $soalTable = $key === 'tp' ? 'soal_tps' : 'soal_mandiris';

        $soalId = DB::table($soalTable)->insertGetId([
            'modul_id' => $modulId,
            'soal' => 'Soal '.$key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$praktikanId, $modulId, $soalId];
    }

    private function insertJawaban(string $key, int $praktikanId, int $modulId, int $soalId): int
    {
        $table = $key === 'tp' ? 'jawaban_tps' : 'jawaban_mandiris';

        // jawaban_tps/jawaban_mandiris .submission_id is NOT NULL and unique
        // per (submission_id, soal_id), so every requested answer row gets its
        // own submission of the matching tipe.
        $attributes = [
            'praktikan_id' => $praktikanId,
            'modul_id' => $modulId,
            'soal_id' => $soalId,
            'jawaban' => 'Jawaban '.$key,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $attributes['submission_id'] = $this->makeSubmission($praktikanId, $modulId, $key)->id;

        return (int) DB::table($table)->insertGetId($attributes);
    }

    private function makeSubmission(int $praktikanId, int $modulId, string $key): JawabanSubmission
    {
        // (praktikan_id, modul_id, tipe, attempt_no) is unique, so every extra
        // answer in the same slot becomes the next attempt.
        $tipe = $key === 'tp' ? JawabanSubmission::TIPE_TP : JawabanSubmission::TIPE_TM;

        $nextAttempt = (int) DB::table('jawaban_submissions')
            ->where('praktikan_id', $praktikanId)
            ->where('modul_id', $modulId)
            ->where('tipe', $tipe)
            ->max('attempt_no') + 1;

        return JawabanSubmission::create([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modulId,
            'tipe' => $tipe,
            'attempt_no' => $nextAttempt,
        ])->fresh();
    }
}
