<?php

namespace App\Http\Controllers\API;

use App\Exceptions\JawabanSubmissionLockedException;
use App\Http\Controllers\Controller;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp as JawabanTps;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp as SoalTps;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class JawabanTPController extends Controller
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
        try {
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
                JawabanSubmission::TIPE_TP,
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
        } catch (JawabanSubmissionLockedException $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'SUBMISSION_LOCKED',
                'submission_id' => $e->submissionId,
                'locked_at' => $e->lockedAt->toIso8601String(),
            ], 409);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menyimpan jawaban.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
                'jawaban' => ['nullable', 'string'],
                'soal_id' => ['required', 'integer'],
                'praktikan_id' => ['required', 'integer'],
                'modul_id' => ['required', 'integer'],
            ], [], [
                'jawaban' => "jawaban.{$index}",
                'soal_id' => "soal_id.{$index}",
                'praktikan_id' => "praktikan_id.{$index}",
                'modul_id' => "modul_id.{$index}",
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $normalizedAnswer = trim((string) ($entry['jawaban'] ?? ''));

            return [
                'jawaban' => $normalizedAnswer === '' ? '-' : $normalizedAnswer,
                'soal_id' => (int) $entry['soal_id'],
                'praktikan_id' => (int) $entry['praktikan_id'],
                'modul_id' => (int) $entry['modul_id'],
            ];
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(int $idModul): JsonResponse
    {
        try {
            $praktikan = auth('praktikan')->user();

            if (! $praktikan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized.',
                ], 401);
            }

            $modul = Modul::findOrFail($idModul);

            if (! $modul->isQuestionTypeUnlocked('tp')) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Jawaban masih terkunci.',
                ], 403);
            }

            $jawaban = JawabanTps::where('praktikan_id', $praktikan->id)
                ->where('modul_id', $idModul)
                ->get();

            if ($jawaban->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tidak ada jawaban',
                ]);
            }

            return response()->json([
                'status' => 'success',
                'jawaban_tp' => $jawaban,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil data jawaban.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getJawabanTP($nim, $modulId)
    {
        try {
            // Existing logic from showJawabanTP
            $praktikan = Praktikan::where('nim', $nim)->first();
            if (! $praktikan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Praktikan tidak ditemukan',
                ], 404);
            }

            $modul = Modul::find($modulId);
            if (! $modul) {
                return response()->json([
                    'success' => false,
                    'message' => 'Modul tidak ditemukan',
                ], 404);
            }

            $soalList = SoalTps::where('modul_id', $modulId)->get();
            if ($soalList->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada soal untuk modul ini',
                ], 404);
            }

            $jawabanList = JawabanTps::where('praktikan_id', $praktikan->id)
                ->where('modul_id', $modulId)
                ->get()
                ->keyBy('soal_id');

            $jawabanData = [];

            foreach ($soalList as $soal) {
                $jawaban = isset($jawabanList[$soal->id]) ? $jawabanList[$soal->id]->jawaban : '-';

                $jawabanData[] = [
                    'soal_id' => $soal->id,
                    'soal_text' => $soal->soal,
                    'jawaban' => $jawaban,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'jawabanData' => $jawabanData,
                    'praktikan' => $praktikan,
                    'modul' => $modul,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: '.$e->getMessage(),
            ], 500);
        }
    }

    // Show jawaban for asisten

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
