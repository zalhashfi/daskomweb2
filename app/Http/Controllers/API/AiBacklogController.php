<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiBacklog;
use Illuminate\Http\JsonResponse;

/**
 * GET /api-v1/ai-backlog — queue snapshot per modul.
 *
 * Requires `view-ai-metrics` (SOFTWARE, DDC, KORDAS, KOORPRAK); any other
 * assistant role gets 403 from the `permission` middleware before this
 * controller runs.
 *
 * Response:
 *
 *   {
 *     "status": "success",
 *     "data": [
 *       {"modul_id": 3, "queued": 2, "running": 0, "failed": 1, "oldest_age_s": 125}
 *     ]
 *   }
 *
 * Modules are ordered by `oldest_age_s` descending (FIFO head first).
 */
class AiBacklogController extends Controller
{
    public function index(AiBacklog $backlog): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $backlog->snapshot(),
        ]);
    }
}
