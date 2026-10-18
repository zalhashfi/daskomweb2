<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiRegradeRequest;
use App\Services\Ai\AiRegradeService;
use Illuminate\Http\JsonResponse;

/**
 * Batch regrade of one modul's latest answers.
 *
 * POST /api-v1/ai-regrade  {"modul_id": 3, "priority": "high"|"normal"}
 *
 * Requires `regrade-ai` (SOFTWARE, KORDAS, KOORPRAK); any other assistant role
 * gets 403 from the `permission` middleware before this controller runs.
 *
 * - 422 : body invalid (`modul_id` missing/unknown, `priority` not allowed)
 * - 409 : `AI_GATE_DISABLED` (or the gate's own code) / `BUDGET_EXCEEDED`,
 *         nothing changed
 * - 202 : `{"queued": N}` — N rows re-queued, one EvaluateAnswer job each
 *
 * `done` rows are never overwritten; attachment answers (`manual_required`)
 * are never queued.
 */
class AiRegradeController extends Controller
{
    public function store(AiRegradeRequest $request, AiRegradeService $regrades): JsonResponse
    {
        return response()->json(
            $regrades->regrade($request->modulId(), $request->priority()),
            202,
        );
    }
}
