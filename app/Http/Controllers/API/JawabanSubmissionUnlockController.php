<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Jawaban\SubmissionUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Manual unlock of a jawaban submission by a kadiv.
 *
 * POST /api-v1/jawaban-submissions/{id}/unlock
 *
 * - 422 : `reason` missing or shorter than REASON_MIN_LENGTH characters
 *         (nothing is changed)
 * - 404 : no such submission
 * - 200 : lock cleared, submission_unlocks row created, audit 'submission.unlock'
 */
class JawabanSubmissionUnlockController extends Controller
{
    public const REASON_MIN_LENGTH = 15;

    public function __construct(private readonly SubmissionUnlockService $unlockService) {}

    public function unlock(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN_LENGTH],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $submission = $this->unlockService->unlock(
            $id,
            (int) $request->user('asisten')->getKey(),
            (string) $validator->validated()['reason'],
        );

        if ($submission === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submission tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'submission_id' => (int) $submission->id,
        ]);
    }
}
