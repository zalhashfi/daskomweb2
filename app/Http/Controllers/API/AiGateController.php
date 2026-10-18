<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleAiGateRequest;
use App\Http\Requests\UpdateAiBudgetRequest;
use App\Services\Ai\AiGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator control of the AI gate (T31).
 *
 *  - GET  /api-v1/ai-gate         `view-ai-metrics`
 *  - POST /api-v1/ai-gate/toggle  `manage-ai-gate`
 *  - POST /api-v1/ai-gate/budget  `manage-ai-budget`
 *
 * Any assistant without the matching permission gets 403 from the
 * `permission` middleware before this controller runs (KORDAS, for example,
 * may read the snapshot but can never toggle the gate).
 *
 * Toggle semantics:
 *
 *  - OFF is always accepted (200) — queued rows stay `queued`, no provider
 *    request is undone or made;
 *  - ON is refused with `409 CONFIG_CHANGE_MID_MODULE` while any modul still
 *    holds non-terminal evaluations;
 *  - the audit row keeps the old value, the new value and the reason.
 */
class AiGateController extends Controller
{
    public function show(AiGateService $gate): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $gate->snapshot(),
        ]);
    }

    public function toggle(ToggleAiGateRequest $request, AiGateService $gate): JsonResponse
    {
        $result = $gate->toggle(
            $request->boolean('ai_enabled'),
            (string) $request->input('reason'),
            $this->actorId($request),
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function budget(UpdateAiBudgetRequest $request, AiGateService $gate): JsonResponse
    {
        $result = $gate->updateBudget(
            (int) $request->input('budget_tokens_per_modul'),
            (string) $request->input('reason'),
            $this->actorId($request),
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user('asisten')?->getKey();

        return $id === null ? null : (int) $id;
    }
}
