<?php

namespace App\Http\Controllers\API;

use App\Exceptions\JawabanSubmissionLockedException;
use App\Http\Controllers\Controller;
use App\Models\JawabanSubmission;
use App\Services\Ai\AiEvaluationRetryService;
use Illuminate\Http\JsonResponse;

/**
 * Retry of the failed AI evaluations of one submission.
 *
 * POST /api-v1/ai-evaluations/{submission}/retry
 *
 * - 404 : no such submission
 * - 409 : submission locked (`SUBMISSION_LOCKED`, nothing changed)
 * - 422 : AI gate / payload policy rejects the run (`{status, code}`)
 * - 202 : `{"retried": N, "skipped_done": M}`
 *
 * No row is ever duplicated: the retry reuses the canonical `ai_evaluations`
 * rows of the submission (App\Services\Ai\EvaluationChokepoint) and only
 * flips `failed` → `queued`; `done` rows are counted, never touched.
 */
class AiEvaluationRetryController extends Controller
{
    public function retry(int $submission, AiEvaluationRetryService $retries): JsonResponse
    {
        $submissionModel = JawabanSubmission::query()->find($submission);

        if ($submissionModel === null) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if ($submissionModel->isLocked()) {
            throw JawabanSubmissionLockedException::fromSubmission($submissionModel);
        }

        $result = $retries->retry($submissionModel);

        return response()->json($result, 202);
    }
}
