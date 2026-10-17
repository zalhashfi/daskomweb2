<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertJawabanStandarRequest;
use App\Models\JawabanMandiri;
use App\Models\JawabanTp;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Standard answers (kunci jawaban) for TP/TM questions (SPEC 02-01 §7.4, T16).
 *
 * PUT /api-v1/soal-{tp|tm}/{idSoal}/jawaban-standar
 *
 * The kunci is written onto the item of the modul's ACTIVE version set
 * (activated_at not null) matching (soal_type, soal_id):
 *
 * - no active set / no matching item     => 404 VERSION_ITEM_NOT_FOUND
 * - the question already has answers     => 409 SOAL_HAS_ANSWERS (nothing written)
 * - bobot makes SUM(bobot) for the type
 *   exceed 100                           => 422 BOBOT_TIDAK_100 (per_type)
 *
 * On success the item goes back to `pending` with approved_by/approved_at and
 * reject_reason cleared, so a previously approved kunci must be re-approved
 * (the dual-control flow itself is T17 and lives elsewhere).
 */
class JawabanStandarController extends Controller
{
    public const BOBOT_TARGET_TOTAL = 100;

    /**
     * PUT /api-v1/soal-tp/{idSoal}/jawaban-standar
     */
    public function upsertTp(UpsertJawabanStandarRequest $request, $idSoal): JsonResponse
    {
        return $this->upsert($request, SoalVersionItem::SOAL_TP, (int) $idSoal);
    }

    /**
     * PUT /api-v1/soal-tm/{idSoal}/jawaban-standar
     */
    public function upsertTm(UpsertJawabanStandarRequest $request, $idSoal): JsonResponse
    {
        return $this->upsert($request, SoalVersionItem::SOAL_TM, (int) $idSoal);
    }

    private function upsert(UpsertJawabanStandarRequest $request, string $soalType, int $soalId): JsonResponse
    {
        $validated = $request->validated();

        $modulId = $this->modulIdFor($soalType, $soalId);

        if ($modulId === null) {
            return $this->versionItemNotFound($soalType, $soalId);
        }

        $activeSet = SoalVersionSet::query()
            ->forModul($modulId)
            ->active()
            ->orderByDesc('version_no')
            ->first();

        $item = $activeSet?->items()
            ->where('soal_type', $soalType)
            ->where('soal_id', $soalId)
            ->first();

        if (! $item) {
            return $this->versionItemNotFound($soalType, $soalId);
        }

        $answerCount = $this->answerCount($soalType, $soalId);

        if ($answerCount > 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'SOAL_HAS_ANSWERS',
                'message' => 'Soal sudah memiliki jawaban, kunci jawaban tidak dapat diubah.',
                'count' => $answerCount,
            ], 409);
        }

        $bobot = array_key_exists('bobot', $validated) && $validated['bobot'] !== null
            ? (int) $validated['bobot']
            : null;

        if ($bobot !== null) {
            // Total of the other items of this type; the current item's bobot is replaced.
            $otherTotal = (int) SoalVersionItem::query()
                ->where('version_set_id', $activeSet->id)
                ->where('soal_type', $soalType)
                ->whereKeyNot($item->id)
                ->sum('bobot');

            $newTotal = $otherTotal + $bobot;

            if ($newTotal > self::BOBOT_TARGET_TOTAL) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'BOBOT_TIDAK_100',
                    'message' => 'Total bobot per tipe soal tidak boleh melebihi 100.',
                    'per_type' => $this->bobotTotals($activeSet, $soalType, $newTotal),
                ], 422);
            }
        }

        DB::transaction(function () use ($item, $validated, $bobot) {
            $attributes = [
                'jawaban_standar' => $validated['jawaban_standar'],
                'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
                'jawaban_standar_approved_by' => null,
                'jawaban_standar_approved_at' => null,
                'reject_reason' => null,
            ];

            if ($bobot !== null) {
                $attributes['bobot'] = $bobot;
            }

            if (isset($validated['max_score'])) {
                $attributes['max_score'] = (int) $validated['max_score'];
            }

            $item->update($attributes);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Kunci jawaban berhasil disimpan dan menunggu persetujuan.',
            'data' => $item->fresh(),
        ], 200);
    }

    /**
     * Modul that owns the question, or null when the question does not exist.
     */
    private function modulIdFor(string $soalType, int $soalId): ?int
    {
        $modulId = match ($soalType) {
            SoalVersionItem::SOAL_TP => SoalTp::query()->whereKey($soalId)->value('modul_id'),
            SoalVersionItem::SOAL_TM => SoalMandiri::query()->whereKey($soalId)->value('modul_id'),
            default => null,
        };

        return $modulId === null ? null : (int) $modulId;
    }

    private function answerCount(string $soalType, int $soalId): int
    {
        return match ($soalType) {
            SoalVersionItem::SOAL_TP => JawabanTp::query()->where('soal_id', $soalId)->count(),
            SoalVersionItem::SOAL_TM => JawabanMandiri::query()->where('soal_id', $soalId)->count(),
        };
    }

    /**
     * Current SUM(bobot) per type for the set, with $soalType replaced by the
     * would-be total so the client sees what it tried to submit.
     *
     * @return array{tp: int, tm: int}
     */
    private function bobotTotals(SoalVersionSet $set, string $soalType, int $newTotalForType): array
    {
        $sums = [
            SoalVersionItem::SOAL_TP => 0,
            SoalVersionItem::SOAL_TM => 0,
        ];

        SoalVersionItem::query()
            ->where('version_set_id', $set->id)
            ->selectRaw('soal_type, SUM(bobot) as total')
            ->groupBy('soal_type')
            ->get()
            ->each(function ($row) use (&$sums) {
                $sums[(string) $row->soal_type] = (int) $row->total;
            });

        $sums[$soalType] = $newTotalForType;

        return [
            'tp' => $sums[SoalVersionItem::SOAL_TP],
            'tm' => $sums[SoalVersionItem::SOAL_TM],
        ];
    }

    private function versionItemNotFound(string $soalType, int $soalId): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'VERSION_ITEM_NOT_FOUND',
            'message' => "Item versi soal untuk soal {$soalType} #{$soalId} pada versi aktif tidak ditemukan.",
        ], 404);
    }
}
