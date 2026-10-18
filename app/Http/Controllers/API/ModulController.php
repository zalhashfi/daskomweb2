<?php

namespace App\Http\Controllers\API;

use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Models\Resource;
use App\Models\Tugaspendahuluan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ModulController extends BaseController
{
    public function index()
    {
        try {
            $moduls = Modul::leftJoin('resources', 'resources.modul_id', '=', 'moduls.id')
                ->select(
                    'judul',
                    'deskripsi',
                    'isEnglish',
                    'isUnlocked',
                    'unlock_config',
                    'moduls.id as idM',
                    'resources.id as idR',
                    'resources.modul_link',
                    'resources.ppt_link',
                    'resources.video_link'
                )
                ->selectRaw('(SELECT COUNT(*) FROM soal_tps WHERE soal_tps.modul_id = moduls.id) AS soal_tp_count')
                ->selectRaw('(SELECT COUNT(*) FROM soal_tas WHERE soal_tas.modul_id = moduls.id) AS soal_ta_count')
                ->selectRaw('(SELECT COUNT(*) FROM soal_fitbs WHERE soal_fitbs.modul_id = moduls.id) AS soal_fitb_count')
                ->selectRaw('(SELECT COUNT(*) FROM soal_jurnals WHERE soal_jurnals.modul_id = moduls.id) AS soal_jurnal_count')
                ->selectRaw('(SELECT COUNT(*) FROM soal_mandiris WHERE soal_mandiris.modul_id = moduls.id) AS soal_tm_count')
                ->selectRaw('(SELECT COUNT(*) FROM soal_tks WHERE soal_tks.modul_id = moduls.id) AS soal_tk_count')
                ->get()
                ->map(function ($modul) {
                    $modul->unlock_config = $this->decodeUnlockConfig($modul->unlock_config);

                    return $modul;
                });

            return response()->json([
                'data' => $moduls,
                'message' => 'Moduls retrieved successfully.',
                'success' => true,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving moduls.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            // Mengambil modul dengan eager loading relasi 'resource'
            $modul = Modul::with('resource')
                ->select('judul', 'deskripsi', 'isEnglish', 'isUnlocked', 'unlock_config', 'moduls.id as idM')
                ->where('moduls.id', $id)
                ->first();
            if (! $modul) {
                return response()->json([
                    'message' => 'Modul tidak ditemukan.',
                ], 404);
            }
            $modul->resource_id = $modul->resource->id ?? null;
            $modul->modul_link = $modul->resource->modul_link ?? null;
            $modul->ppt_link = $modul->resource->ppt_link ?? null;
            $modul->video_link = $modul->resource->video_link ?? null;

            return response()->json([
                'success' => true,
                'data' => $modul,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengambil modul.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        // Validasi input dari request
        $request->validate([
            'judul' => 'required|unique:moduls|string',
            'deskripsi' => 'required|string',
            'isEnglish' => 'required|boolean',
            'isUnlocked' => 'required|boolean',
            'unlock_config' => 'nullable|array',
            'unlock_config.*' => 'boolean',
            'modul_link' => 'nullable|string',
            'ppt_link' => 'nullable|string',
            'video_link' => 'nullable|string',
        ]);
        try {
            $judul = $request->judul;
            $deskripsi = $request->deskripsi;
            $isEnglish = $request->boolean('isEnglish');

            if ($this->titleIndicatesEnglish($judul)) {
                $isEnglish = true;
            }

            $modul = Modul::create([
                'judul' => $judul,
                'deskripsi' => $deskripsi,
                'isEnglish' => $isEnglish ? 1 : 0,
                'isUnlocked' => $request->boolean('isUnlocked') ? 1 : 0,
                'unlock_config' => Modul::normalizeUnlockConfig(
                    $request->input('unlock_config'),
                    (bool) $request->isUnlocked
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Resource::create([
                'modul_id' => $modul->id,
                'modul_link' => $request->modul_link ?? '',
                'ppt_link' => $request->ppt_link ?? '',
                'video_link' => $request->video_link ?? '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Tugaspendahuluan::create([
                'modul_id' => $modul->id,
                'pembahasan' => 'empty',
                'isActive' => 0,
            ]);

            // Inertia posts this form through `router.visit`, which only runs
            // `onSuccess` when the response carries an Inertia header. Answer
            // with a real redirect so the browser confirms and refreshes.
            if ($request->header('X-Inertia')) {
                return redirect()->back()->with('success', 'Modul added successfully.');
            }

            // Plain XHR/API clients keep the JSON contract.
            return response()->json([
                'success' => true,
                'message' => 'Modul added successfully.',
                'data' => $modul,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat menambahkan modul.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'judul' => 'required|string',
            'deskripsi' => 'required|string',
            'isEnglish' => 'required|boolean',
            'isUnlocked' => 'required|boolean',
            'unlock_config' => 'nullable|array',
            'unlock_config.*' => 'boolean',
            'modul_link' => 'nullable|string',
            'ppt_link' => 'nullable|string',
            'video_link' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $modul = Modul::findOrFail($id);

            $judulTelahDigunakan = Modul::where('judul', $request->judul)
                ->where('id', '!=', $modul->id)
                ->exists();

            if ($judulTelahDigunakan) {
                return response()->json([
                    'message' => 'Judul "'.$request->judul.'" sudah terdaftar.',
                    'errors' => [
                        'judul' => ['Judul "'.$request->judul.'" sudah terdaftar.'],
                    ],
                ], 422);
            }

            $resource = Resource::firstOrNew(['modul_id' => $modul->id]);

            $judul = $request->judul;
            $isEnglish = $request->boolean('isEnglish');

            if ($this->titleIndicatesEnglish($judul)) {
                $isEnglish = true;
            }

            $modul->judul = $judul;
            $modul->deskripsi = $request->deskripsi;
            $modul->isEnglish = $isEnglish ? 1 : 0;
            $modul->isUnlocked = $request->boolean('isUnlocked') ? 1 : 0;

            if ($request->has('unlock_config')) {
                $modul->unlock_config = Modul::normalizeUnlockConfig(
                    $request->input('unlock_config', []),
                    (bool) $modul->isUnlocked
                );
            }

            $modul->save();

            $resource->modul_link = $request->modul_link ?? '';
            $resource->ppt_link = $request->ppt_link ?? '';
            $resource->video_link = $request->video_link ?? '';
            $resource->save();

            return response()->json([
                'message' => 'Modul berhasil diperbarui.',
                'data' => [
                    'idM' => $modul->id,
                    'judul' => $modul->judul,
                    'deskripsi' => $modul->deskripsi,
                    'isEnglish' => (int) $modul->isEnglish,
                    'isUnlocked' => (int) $modul->isUnlocked,
                    'unlock_config' => $modul->unlock_config,
                    'modul_link' => $resource->modul_link,
                    'ppt_link' => $resource->ppt_link,
                    'video_link' => $resource->video_link,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengupdate modul.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        if (! Modul::where('id', $id)->exists()) {
            return response()->json(['message' => 'Modul tidak ditemukan'], 404);
        }
        $modul = Modul::findOrFail($id);
        $modul->delete();

        return response()->json([
            'message' => 'modul berhasil dihapus',

        ], 200);
    }

    //  =====================Template for resetAll()=====================
    //    di disable but still can be used
    //
    //
    //    public function resetAll()
    //    {
    //        try {
    //            // This deletes all records but respects foreign keys
    //            Modul::query()->delete();
    //
    //            return response()->json([
    //                'message' => 'Semua data modul berhasil dihapus'
    //            ], 200);
    //        } catch (\Exception $e) {
    //            return response()->json([
    //                'message' => 'Gagal menghapus data modul: ' . $e->getMessage()
    //            ], 500);
    //        }
    //    }

    /**
     * Reset the submission/evaluation data of one or more moduls.
     *
     * Only the moduls listed in the request are touched; every other modul
     * keeps its data. The deletion is ordered child-first so the real foreign
     * keys (no SET FOREIGN_KEY_CHECKS=0, no TRUNCATE) are always satisfied:
     *
     *   1. ai_evaluation_attempts  (belongs to the modul's evaluations)
     *   2. ai_evaluations          (of the answers of those moduls)
     *   3. jawaban_tps / jawaban_mandiris
     *   4. jawaban_submissions
     *   5. submission_unlocks      (of the deleted submissions)
     *
     * Everything runs inside one DB::transaction, so a failure halfway
     * through rolls every table back to its previous row count.
     *
     * Query parameters: `modul_id` (alias `id`) or `modul_ids[]`; a `{id}`
     * route parameter is accepted as well. Without any target the request is
     * rejected with 422, so a typo can never wipe every modul.
     */
    public function reset(Request $request, $id = null)
    {
        $modulIds = $this->resetTargetModulIds($request, $id);

        if ($modulIds === []) {
            return response()->json([
                'message' => 'Parameter modul_id wajib diisi.',
            ], 422);
        }

        try {
            $deleted = DB::transaction(function () use ($modulIds): array {
                return $this->deleteModulSubmissionData($modulIds);
            });

            return response()->json([
                'message' => 'Modul and its submission data reset successfully.',
                'modul_ids' => $modulIds,
                'deleted' => $deleted,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'An error occurred during the reset process.',
                'error' => $e->getMessage(),
            ], 500); // Server error
        }
    }

    /**
     * Ordered, FK-safe deletion for the given moduls. Runs inside the
     * caller's transaction and returns the number of rows removed per table.
     *
     * @param  list<int>  $modulIds
     * @return array<string, int>
     */
    private function deleteModulSubmissionData(array $modulIds): array
    {
        $deleted = [
            'ai_evaluation_attempts' => 0,
            'ai_evaluations' => 0,
            'jawaban_tps' => 0,
            'jawaban_mandiris' => 0,
            'jawaban_submissions' => 0,
            'submission_unlocks' => 0,
        ];

        // Answer rows of the target moduls, per `jawaban_type` (tp / tm).
        $answersByType = [
            JawabanSubmission::TIPE_TP => [
                'table' => 'jawaban_tps',
                'ids' => $this->chunkedColumn('jawaban_tps', 'modul_id', $modulIds),
            ],
            JawabanSubmission::TIPE_TM => [
                'table' => 'jawaban_mandiris',
                'ids' => $this->chunkedColumn('jawaban_mandiris', 'modul_id', $modulIds),
            ],
        ];

        // 1 + 2. Evaluations owned by those answers (matched by the
        // (jawaban_type, jawaban_id) pair, so an id shared between the two
        // answer tables can never pull in another modul's evaluation), and
        // the attempts belonging to them.
        $evaluationIds = [];

        foreach ($answersByType as $tipe => $answers) {
            if ($answers['ids'] === []) {
                continue;
            }

            foreach (array_chunk($answers['ids'], 500) as $idChunk) {
                $chunkEvaluationIds = DB::table('ai_evaluations')
                    ->where('jawaban_type', $tipe)
                    ->whereIn('jawaban_id', $idChunk)
                    ->pluck('id')
                    ->map(fn ($value): int => (int) $value)
                    ->all();

                $evaluationIds = array_merge($evaluationIds, $chunkEvaluationIds);
            }
        }

        $evaluationIds = array_values(array_unique($evaluationIds));

        if ($evaluationIds !== []) {
            $deleted['ai_evaluation_attempts'] = $this->deleteByChunks(
                'ai_evaluation_attempts',
                'ai_evaluation_id',
                $evaluationIds
            );

            $deleted['ai_evaluations'] = $this->deleteByChunks(
                'ai_evaluations',
                'id',
                $evaluationIds
            );
        }

        // 3. Answer rows of the target moduls.
        foreach ($answersByType as $answers) {
            if ($answers['ids'] !== []) {
                $deleted[$answers['table']] = $this->deleteByChunks($answers['table'], 'id', $answers['ids']);
            }
        }

        // 4. Submissions of the target moduls.
        $submissionIds = $this->chunkedColumn('jawaban_submissions', 'modul_id', $modulIds);

        $deleted['jawaban_submissions'] = $submissionIds === []
            ? 0
            : $this->deleteByChunks('jawaban_submissions', 'id', $submissionIds);

        // 5. Unlock audit rows pointing at those submissions.
        $deleted['submission_unlocks'] = $submissionIds === []
            ? 0
            : $this->deleteByChunks('submission_unlocks', 'submission_id', $submissionIds);

        return $deleted;
    }

    /**
     * All values of $column in $table whose $filterColumn is in $filterValues,
     * fetched in chunks to keep the IN() list bounded.
     *
     * @param  list<int>  $filterValues
     * @return list<int>
     */
    private function chunkedColumn(string $table, string $filterColumn, array $filterValues): array
    {
        $ids = [];

        foreach (array_chunk($filterValues, 500) as $chunk) {
            foreach (DB::table($table)->whereIn($filterColumn, $chunk)->pluck('id') as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Delete every row of $table whose $column is in $values, in chunks.
     *
     * @param  list<int>  $values
     */
    private function deleteByChunks(string $table, string $column, array $values): int
    {
        $deleted = 0;

        foreach (array_chunk($values, 500) as $chunk) {
            $deleted += DB::table($table)->whereIn($column, $chunk)->delete();
        }

        return $deleted;
    }

    /**
     * Resolve the modul ids the reset applies to from the request body/query
     * and the optional route parameter.
     *
     * @return list<int>
     */
    private function resetTargetModulIds(Request $request, mixed $id): array
    {
        $candidates = [];

        foreach ([$id, $request->input('modul_id'), $request->input('id')] as $single) {
            if (is_scalar($single) && $single !== '' && $single !== null) {
                $candidates[] = $single;
            }
        }

        $many = $request->input('modul_ids', $request->input('modulIds', []));

        if (is_array($many)) {
            $candidates = array_merge($candidates, $many);
        } elseif (is_scalar($many) && $many !== '') {
            $candidates[] = $many;
        }

        $ids = [];

        foreach ($candidates as $candidate) {
            if (! is_numeric($candidate) || (int) $candidate <= 0) {
                continue;
            }

            $ids[] = (int) $candidate;
        }

        return array_values(array_unique($ids));
    }

    public function updateStatus(Request $request)
    {
        $data = $request->all();

        if (empty($data)) {
            return response()->json([
                'message' => 'No data provided.',
            ], 400);
        }

        try {
            // Validasi input
            $validatedData = $request->validate([
                '*.id' => 'required|exists:moduls,id',
                '*.isUnlocked' => 'required|boolean',
            ]);

            // Ambil semua ID yang diberikan dalam request
            $ids = collect($validatedData)->pluck('id');

            // Ambil semua modul yang sesuai dengan ID yang diberikan
            $moduls = Modul::whereIn('id', $ids)->get();

            // Update status modul
            foreach ($moduls as $modul) {
                $item = collect($validatedData)->firstWhere('id', $modul->id);
                $modul->update([
                    'isUnlocked' => $item['isUnlocked'],
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Status modul berhasil diupdate.',
                'data' => $validatedData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengupdate status: '.$e->getMessage(),
            ], 500);
        }
    }

    public function bulkUpdate(Request $request)
    {
        $data = [
            'payload' => $request->input('payload'),
        ];

        if ($data['payload'] === null && array_is_list($request->all())) {
            $data['payload'] = $request->all();
        }

        $validator = Validator::make($data, [
            'payload' => 'required|array',
            'payload.*.id' => 'required|integer|exists:moduls,id',
            'payload.*.judul' => 'nullable|string',
            'payload.*.deskripsi' => 'nullable|string',
            'payload.*.isUnlocked' => 'nullable|boolean',
            'payload.*.isEnglish' => 'nullable|boolean',
            'payload.*.modul_link' => 'nullable|string',
            'payload.*.ppt_link' => 'nullable|string',
            'payload.*.video_link' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payloadData = $validator->validated()['payload'] ?? [];

        if (empty($payloadData)) {
            return response()->json([
                'message' => 'Tidak ada data yang dikirim.',
            ], 422);
        }

        $ids = collect($payloadData)->pluck('id')->all();
        $modules = Modul::whereIn('id', $ids)->get()->keyBy('id');

        $updatedCount = 0;

        foreach ($payloadData as $entry) {
            $module = $modules->get($entry['id']);

            if (! $module) {
                continue;
            }

            $changes = [];

            if (array_key_exists('isUnlocked', $entry)) {
                $changes['isUnlocked'] = (bool) $entry['isUnlocked'];
            }

            if (array_key_exists('isEnglish', $entry)) {
                $changes['isEnglish'] = (bool) $entry['isEnglish'];
            }

            // Only update the fields we intend to change, ignore the other fields
            // that were sent for validation purposes
            if (! empty($changes)) {
                $module->update($changes);
                $updatedCount++;
            }
        }

        return response()->json([
            'message' => 'Bulk update berhasil',
            'updated' => $updatedCount,
        ]);
    }

    private function decodeUnlockConfig(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function titleIndicatesEnglish(string $title): bool
    {
        return str_contains(Str::upper($title), 'ENG');
    }
}
