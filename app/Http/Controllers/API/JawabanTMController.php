<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class JawabanTMController extends Controller
{
    public function __construct(private readonly SubmissionWriter $submissionWriter) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $entries = $this->validatedEntries($request);

        if ($entries->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak ada jawaban yang dikirimkan.',
            ], 422);
        }

        if ($entries->pluck('praktikan_id')->unique()->count() > 1 || $entries->pluck('modul_id')->unique()->count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Setiap permintaan hanya boleh memuat satu praktikan dan satu modul.',
            ], 422);
        }

        $first = $entries->first();

        $submission = $this->submissionWriter->write(
            JawabanSubmission::TIPE_TM,
            (int) $first['praktikan_id'],
            (int) $first['modul_id'],
            $entries->map(fn (array $entry): array => [
                'soal_id' => $entry['soal_id'],
                'jawaban' => $entry['jawaban'],
            ])->all(),
        );

        return response()->json([
            'status' => 'success',
            'submission_id' => $submission->id,
            'attempt_no' => $submission->attempt_no,
        ], 201);
    }

    /**
     * @return Collection<int, array{praktikan_id:int, modul_id:int, soal_id:int, jawaban:string}>
     */
    private function validatedEntries(Request $request): Collection
    {
        $payload = $request->all();

        $entries = array_is_list($payload) ? $payload : [$payload];

        return collect($entries)->map(function ($entry, int $index) {
            $validator = Validator::make($entry, [
                'praktikan_id' => ['required', 'integer'],
                'modul_id' => ['required', 'integer'],
                'soal_id' => ['required', 'integer'],
                'jawaban' => ['nullable', 'string'],
            ], [], [
                'praktikan_id' => "praktikan_id.{$index}",
                'modul_id' => "modul_id.{$index}",
                'soal_id' => "soal_id.{$index}",
                'jawaban' => "jawaban.{$index}",
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $normalizedAnswer = trim((string) ($entry['jawaban'] ?? ''));

            return [
                'praktikan_id' => (int) $entry['praktikan_id'],
                'modul_id' => (int) $entry['modul_id'],
                'soal_id' => (int) $entry['soal_id'],
                'jawaban' => $normalizedAnswer === '' ? '-' : $normalizedAnswer,
            ];
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(int $idModul): JsonResponse
    {
        $praktikan = auth('praktikan')->user();

        if (! $praktikan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized.',
            ], 401);
        }

        $modul = Modul::findOrFail($idModul);

        if (! $modul->isQuestionTypeUnlocked('tm')) {
            return response()->json([
                'status' => 'success',
                'message' => 'Jawaban masih terkunci.',
            ], 403);
        }

        $jawaban = JawabanMandiri::where('praktikan_id', $praktikan->id)
            ->where('modul_id', $idModul)
            ->get();
        if ($jawaban->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jawaban tidak ditemukan untuk modul ini.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'jawaban_mandiri' => $jawaban,
        ], 200);
    }

    public function showAsisten(int $praktikanId, int $modulId): JsonResponse
    {
        $jawaban = JawabanMandiri::with('soal_mandiri')
            ->where('praktikan_id', $praktikanId)
            ->where('modul_id', $modulId)
            ->get()
            ->map(function (JawabanMandiri $item) {
                return [
                    'soal_id' => $item->soal_id,
                    'soal_text' => $item->soal_mandiri?->soal,
                    'jawaban' => $item->jawaban,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'jawaban_mandiri' => $jawaban,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
