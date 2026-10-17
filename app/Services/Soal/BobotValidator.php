<?php

namespace App\Services\Soal;

use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;

/**
 * Validates the bobot (weight) distribution of a soal version set.
 *
 * Rule (SPEC 02-01 §5.3): for every soal_type that actually has items in the
 * set, SUM(bobot) must equal 100. A type with no items at all is not checked,
 * and an individual item is allowed to have bobot 0 as long as the type total
 * is still 100.
 */
class BobotValidator
{
    public const TARGET_TOTAL = 100;

    /**
     * @var array<int, string>
     */
    public const TYPES = [
        SoalVersionItem::SOAL_TP,
        SoalVersionItem::SOAL_TM,
    ];

    /**
     * @return array{
     *     ok: bool,
     *     per_type: array{tp: int, tm: int},
     *     item_count: array{tp: int, tm: int}
     * }
     */
    public function validate(SoalVersionSet $set): array
    {
        $sums = array_fill_keys(self::TYPES, 0);
        $counts = array_fill_keys(self::TYPES, 0);

        $rows = SoalVersionItem::query()
            ->where('version_set_id', $set->id)
            ->get(['soal_type', 'bobot']);

        foreach ($rows as $row) {
            $type = (string) $row->soal_type;

            $sums[$type] = ($sums[$type] ?? 0) + (int) $row->bobot;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $ok = true;

        foreach (self::TYPES as $type) {
            // A type without any item is not validated (it may legitimately be 0).
            if ($counts[$type] === 0) {
                continue;
            }

            if ($sums[$type] !== self::TARGET_TOTAL) {
                $ok = false;
            }
        }

        return [
            'ok' => $ok,
            'per_type' => [
                'tp' => $sums[SoalVersionItem::SOAL_TP],
                'tm' => $sums[SoalVersionItem::SOAL_TM],
            ],
            'item_count' => [
                'tp' => $counts[SoalVersionItem::SOAL_TP],
                'tm' => $counts[SoalVersionItem::SOAL_TM],
            ],
        ];
    }
}
