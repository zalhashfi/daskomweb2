<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\JawabanFitb;
use App\Models\JawabanJurnal;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTa;
use App\Models\JawabanTk;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalFitb;
use App\Models\SoalJurnal;
use App\Models\SoalMandiri;
use App\Models\SoalTa;
use App\Models\SoalTk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssistantAnswerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function actingAsAssistantWithNilaiPermission(): Asisten
    {
        $role = Role::create([
            'name' => 'assistant-tester',
            'guard_name' => 'asisten',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'nilai-praktikan',
            'guard_name' => 'asisten',
        ]);

        $role->givePermissionTo($permission);

        $assistant = Asisten::factory()->create([
            'role_id' => $role->id,
        ]);

        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    protected function createPraktikanWithKelas(): Praktikan
    {
        $kelas = Kelas::factory()->create();

        return Praktikan::factory()->create([
            'kelas_id' => $kelas->id,
        ]);
    }

    public function test_assistant_can_fetch_tes_awal_answers_with_options(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $modul = Modul::factory()->create(['isUnlocked' => true]);
        $praktikan = $this->createPraktikanWithKelas();

        $soal = SoalTa::factory()
            ->for($modul)
            ->create([
                'pertanyaan' => 'Apa itu gaya gravitasi?',
            ]);

        $options = $soal->options()->get();
        $selectedOption = $options->first();

        JawabanTa::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'opsi_id' => $selectedOption->id,
        ]);

        $response = $this->getJson("/api-v1/jawaban-ta/praktikan/{$praktikan->id}/modul/{$modul->id}");

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $response->assertJsonPath('jawaban_ta.0.soal_id', $soal->id);
        $response->assertJsonPath('jawaban_ta.0.pertanyaan', 'Apa itu gaya gravitasi?');
        $response->assertJsonPath('jawaban_ta.0.selected_opsi_id', $selectedOption->id);

        $optionsPayload = collect($response->json('jawaban_ta.0.options'));

        $this->assertTrue(
            $optionsPayload->contains(fn ($option) => $option['id'] === $selectedOption->id),
            'Selected option should be present in the payload.'
        );

        $correctOption = $optionsPayload->firstWhere('is_correct', true);

        $this->assertNotNull($correctOption, 'Options payload should mark the correct answer.');
        $this->assertSame($soal->opsi_benar_id, $correctOption['id']);
    }

    public function test_assistant_can_fetch_tes_keterampilan_answers_with_options(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $modul = Modul::factory()->create(['isUnlocked' => true]);
        $praktikan = $this->createPraktikanWithKelas();

        $soal = SoalTk::factory()
            ->for($modul)
            ->create([
                'pertanyaan' => 'Langkah pertama dalam praktikum apa?',
            ]);

        $options = $soal->options()->get();
        $selectedOption = $options->last();

        JawabanTk::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'opsi_id' => $selectedOption->id,
        ]);

        $response = $this->getJson("/api-v1/jawaban-tk/praktikan/{$praktikan->id}/modul/{$modul->id}");

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $response->assertJsonPath('jawaban_tk.0.soal_id', $soal->id);
        $response->assertJsonPath('jawaban_tk.0.pertanyaan', 'Langkah pertama dalam praktikum apa?');
        $response->assertJsonPath('jawaban_tk.0.selected_opsi_id', $selectedOption->id);

        $optionsPayload = collect($response->json('jawaban_tk.0.options'));

        $this->assertTrue(
            $optionsPayload->contains(fn ($option) => $option['id'] === $selectedOption->id),
            'Selected option should be present in the payload.'
        );

        $correctOption = $optionsPayload->firstWhere('is_correct', true);

        $this->assertNotNull($correctOption, 'Options payload should mark the correct answer.');
        $this->assertSame($soal->opsi_benar_id, $correctOption['id']);
    }

    public function test_assistant_can_fetch_mandiri_answers_with_question_text(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $modul = Modul::factory()->create();
        $praktikan = $this->createPraktikanWithKelas();

        $soal = SoalMandiri::factory()
            ->for($modul)
            ->create([
                'soal' => 'Jelaskan prosedur keselamatan kerja di laboratorium.',
            ]);

        // jawaban_mandiris.submission_id is NOT NULL (FK to jawaban_submissions)
        // and POST /api-v1/jawaban-tm now always creates a numbered attempt via
        // SubmissionWriter, so the fixture mirrors that exact shape.
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
            'locked_at' => null,
        ]);

        $jawaban = JawabanMandiri::create([
            'submission_id' => $submission->id,
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Menggunakan APD dan membaca SOP sebelum mulai.',
        ]);

        $submission->forceFill(['jawaban_id' => $jawaban->id])->save();

        $response = $this->getJson("/api-v1/jawaban-mandiri/praktikan/{$praktikan->id}/modul/{$modul->id}");

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $response->assertJsonPath('jawaban_mandiri.0.soal_id', $soal->id);
        $response->assertJsonPath('jawaban_mandiri.0.soal_text', $soal->soal);
        $response->assertJsonPath('jawaban_mandiri.0.jawaban', 'Menggunakan APD dan membaca SOP sebelum mulai.');
    }

    public function test_assistant_can_fetch_jurnal_answers_with_question_text(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $modul = Modul::factory()->create();
        $praktikan = $this->createPraktikanWithKelas();

        $soal = SoalJurnal::factory()
            ->for($modul)
            ->create([
                'soal' => 'Catat hasil pengamatan percobaan.',
            ]);

        JawabanJurnal::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Percobaan menunjukkan kenaikan suhu konstan.',
        ]);

        $response = $this->getJson("/api-v1/jawaban-jurnal/praktikan/{$praktikan->id}/modul/{$modul->id}");

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $response->assertJsonPath('jawaban_jurnal.0.soal_id', $soal->id);
        $response->assertJsonPath('jawaban_jurnal.0.soal_text', $soal->soal);
        $response->assertJsonPath('jawaban_jurnal.0.jawaban', 'Percobaan menunjukkan kenaikan suhu konstan.');
    }

    public function test_assistant_can_fetch_fitb_answers_with_question_text(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $modul = Modul::factory()->create();
        $praktikan = $this->createPraktikanWithKelas();

        $soal = SoalFitb::factory()
            ->for($modul)
            ->create([
                'soal' => 'Lengkapi kalimat: Tegar adalah ____ laboran.',
            ]);

        JawabanFitb::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'seorang',
        ]);

        $response = $this->getJson("/api-v1/jawaban-fitb/praktikan/{$praktikan->id}/modul/{$modul->id}");

        $response->assertOk()->assertJsonFragment(['success' => true]);
        $response->assertJsonPath('jawaban_fitb.0.soal_id', $soal->id);
        $response->assertJsonPath('jawaban_fitb.0.soal_text', $soal->soal);
        $response->assertJsonPath('jawaban_fitb.0.jawaban', 'seorang');
    }
}
