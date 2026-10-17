<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Modul;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Soal\BobotValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Soal version sets (SPEC 02-01 §7.4, task T15).
 *
 * - approve()       : activate a set only when SUM(bobot) per soal_type is 100,
 *                     deactivating the previously active set of that modul.
 * - reject()        : record a rejection reason (min 10 chars).
 * - createVersion() : fork a new draft version from the latest active set (or
 *                     from the modul's live questions when no set exists yet).
 *
 * Kunci ATC (T16) and dual-control (T17) are intentionally out of scope; only
 * the existing columns/statuses are used here.
 */
class SoalVersionSetController extends Controller
{
    public const REASON_MIN_LENGTH = 10;

    public function __construct(private readonly BobotValidator $bobotValidator) {}

    /**
     * POST /api-v1/soal-version-sets/{id}/approve
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $set = SoalVersionSet::query()->find($id);

        if (! $set) {
            return $this->notFound($id);
        }

        $result = $this->bobotValidator->validate($set);

        if (! $result['ok']) {
            return response()->json([
                'status' => 'error',
                'code' => 'BOBOT_TIDAK_100',
                'message' => 'Total bobot per tipe soal harus 100.',
                'per_type' => $result['per_type'],
            ], 422);
        }

        $approvedBy = auth('asisten')->id();
        $activatedAt = now();

        DB::transaction(function () use ($set, $approvedBy, $activatedAt) {
            // Only one active set per modul: deactivate the previous one(s).
            SoalVersionSet::query()
                ->forModul($set->modul_id)
                ->whereKeyNot($set->id)
                ->whereNotNull('activated_at')
                ->update(['activated_at' => null]);

            $set->update([
                'status' => SoalVersionSet::STATUS_APPROVED,
                'approved_by_type' => 'asisten',
                'approved_by_id' => $approvedBy,
                'activated_at' => $activatedAt,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Versi soal berhasil disetujui dan diaktifkan.',
            'data' => $set->fresh(),
        ], 200);
    }

    /**
     * POST /api-v1/soal-version-sets/{id}/reject
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $set = SoalVersionSet::query()->find($id);

        if (! $set) {
            return $this->notFound($id);
        }

        $reason = $request->input('reason');
        $reason = is_string($reason) ? trim($reason) : '';

        if (mb_strlen($reason) < self::REASON_MIN_LENGTH) {
            return response()->json([
                'status' => 'error',
                'code' => 'REASON_TOO_SHORT',
                'message' => 'Alasan penolakan minimal '.self::REASON_MIN_LENGTH.' karakter.',
            ], 422);
        }

        $set->update([
            'status' => SoalVersionSet::STATUS_REJECTED,
            'reject_reason' => $reason,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Versi soal ditolak.',
            'data' => $set->fresh(),
        ], 200);
    }

    /**
     * POST /api-v1/soal-version-sets/{id}/versions
     * POST /api-v1/modul/{id}/soal-version-sets
     *
     * $id may be an existing version set id or a modul id.
     */
    public function createVersion(Request $request, $id): JsonResponse
    {
        // Accept either a version set id or a modul id.
        $source = SoalVersionSet::query()->find($id);
        $modulId = $source ? $source->modul_id : (int) $id;

        if (! $source && ! Modul::query()->whereKey($modulId)->exists()) {
            return $this->notFound($id);
        }

        $latestActive = SoalVersionSet::query()
            ->forModul($modulId)
            ->active()
            ->orderByDesc('version_no')
            ->first();

        $creator = auth('asisten')->id();

        $newSet = DB::transaction(function () use ($modulId, $latestActive, $creator) {
            $maxVersion = (int) SoalVersionSet::query()->forModul($modulId)->max('version_no');

            $set = SoalVersionSet::create([
                'modul_id' => $modulId,
                'version_no' => $maxVersion + 1,
                'status' => SoalVersionSet::STATUS_DRAFT,
                'created_by_asisten_id' => $creator,
                'approved_by_type' => null,
                'approved_by_id' => null,
                'activated_at' => null,
            ]);

            $items = $latestActive
                ? $this->itemsFromSet($latestActive)
                : $this->itemsFromModul($modulId);

            foreach ($items as $item) {
                SoalVersionItem::create(['version_set_id' => $set->id] + $item);
            }

            return $set;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Versi soal baru berhasil dibuat.',
            'data' => $newSet->fresh()->load('items'),
        ], 200);
    }

    /**
     * Copy every item of an existing set. The standard answer (kunci) is kept
     * as text but must be re-approved, so its status resets to pending.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsFromSet(SoalVersionSet $source): array
    {
        return $source->items()
            ->orderBy('id')
            ->get()
            ->map(fn (SoalVersionItem $item): array => [
                'soal_type' => $item->soal_type,
                'soal_id' => $item->soal_id,
                'bobot' => (int) $item->bobot,
                'max_score' => (int) $item->max_score,
                'teks_snapshot' => $item->teks_snapshot,
                'jawaban_standar' => $item->jawaban_standar,
                'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
                'jawaban_standar_approved_by' => null,
                'jawaban_standar_approved_at' => null,
            ])
            ->all();
    }

    /**
     * Bootstrap from the modul's live questions (no active set yet). Bobot is 0
     * until an assistant sets it.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsFromModul(int $modulId): array
    {
        $items = [];

        $tpList = SoalTp::query()->where('modul_id', $modulId)->orderBy('id')->get();
        foreach ($tpList as $soal) {
            $items[] = $this->newItem(SoalVersionItem::SOAL_TP, $soal->id, $soal->soal);
        }

        $tmList = SoalMandiri::query()->where('modul_id', $modulId)->orderBy('id')->get();
        foreach ($tmList as $soal) {
            $items[] = $this->newItem(SoalVersionItem::SOAL_TM, $soal->id, $soal->soal);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function newItem(string $soalType, int $soalId, ?string $teks): array
    {
        return [
            'soal_type' => $soalType,
            'soal_id' => $soalId,
            'bobot' => 0,
            'max_score' => 100,
            'teks_snapshot' => $teks,
            'jawaban_standar' => null,
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            'jawaban_standar_approved_by' => null,
            'jawaban_standar_approved_at' => null,
        ];
    }

    private function notFound(int|string $id): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'NOT_FOUND',
            'message' => "Versi soal dengan ID {$id} tidak ditemukan.",
        ], 404);
    }
}
