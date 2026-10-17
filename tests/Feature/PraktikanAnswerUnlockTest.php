<?php

namespace Tests\Feature;

use App\Models\JawabanSubmission;
use App\Models\JawabanTa;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTa;
use App\Models\SoalTp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PraktikanAnswerUnlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    protected function actingAsPraktikan(): Praktikan
    {
        $praktikan = Praktikan::factory()->create();
        $this->actingAs($praktikan, 'praktikan');

        return $praktikan;
    }

    public function test_praktikan_cannot_view_ta_answers_when_type_locked(): void
    {
        $praktikan = $this->actingAsPraktikan();

        $modul = Modul::factory()->create([
            'isUnlocked' => true,
            'unlock_config' => [
                'ta' => false,
            ],
        ]);

        $response = $this->getJson("/api-v1/jawaban-ta/{$modul->id}");

        $response
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Jawaban masih terkunci.',
            ]);
    }

    public function test_praktikan_can_view_ta_answers_when_type_enabled(): void
    {
        $praktikan = $this->actingAsPraktikan();

        $modul = Modul::factory()->create([
            'isUnlocked' => false,
            'unlock_config' => [
                'ta' => true,
            ],
        ]);

        $soal = SoalTa::factory()->for($modul)->create();
        $option = $soal->options()->first();

        JawabanTa::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'opsi_id' => $option->id,
        ]);

        $response = $this->getJson("/api-v1/jawaban-ta/{$modul->id}");

        $response
            ->assertOk()
            ->assertJsonFragment([
                'status' => 'success',
            ])
            ->assertJsonPath('jawaban_ta.0.soal_id', $soal->id);
    }

    public function test_praktikan_can_always_view_tp_answers(): void
    {
        $praktikan = $this->actingAsPraktikan();

        $modul = Modul::factory()->create([
            'isUnlocked' => false,
            'unlock_config' => [
                'tp' => false,
            ],
        ]);

        $soal = SoalTp::factory()->for($modul)->create([
            'soal' => 'Apa tujuan percobaan?',
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);

        JawabanTp::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Mengamati data',
        ]);

        $response = $this->getJson("/api-v1/jawaban-tp/{$modul->id}");

        $response
            ->assertOk()
            ->assertJsonFragment([
                'status' => 'success',
            ])
            ->assertJsonPath('jawaban_tp.0.soal_id', $soal->id);
    }
}
