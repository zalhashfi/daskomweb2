<?php

namespace App\Services\Soal;

use App\Models\Asisten;
use App\Models\SoalVersionItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Dual-control approval of a kunci jawaban (SPEC 02-01 §7.4, T17).
 *
 * An item whose kunci is `pending` may be approved by an assistant holding one
 * of APPROVER_ROLES, as long as that assistant is not the one who wrote the
 * kunci (jawaban_standar_created_by). The decision is returned instead of a
 * response so the controller owns the HTTP shape.
 */
class JawabanStandarApprovalService
{
    /**
     * Roles allowed to approve a kunci (guard `asisten`).
     *
     * @var array<int, string>
     */
    public const APPROVER_ROLES = ['SOFTWARE', 'DDC', 'ADMIN'];

    public const AUDIT_SUBJECT_TYPE = 'soal_version_item';

    public const AUDIT_ACTION = 'jawaban_standar.approve';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{ok: bool, code?: string, item?: SoalVersionItem}
     */
    public function approve(SoalVersionItem $item, Asisten $actor): array
    {
        if (! $this->isApprover($actor)) {
            return ['ok' => false, 'code' => 'FORBIDDEN'];
        }

        $createdBy = $item->jawaban_standar_created_by;

        if ($createdBy !== null && (int) $createdBy === (int) $actor->id) {
            return ['ok' => false, 'code' => 'SELF_APPROVAL_FORBIDDEN'];
        }

        $approvedAt = now();

        $approved = DB::transaction(function () use ($item, $actor, $approvedAt) {
            // Conditional update keeps two concurrent approvals from both winning.
            $affected = SoalVersionItem::query()
                ->whereKey($item->id)
                ->where('jawaban_standar_status', SoalVersionItem::STATUS_PENDING)
                ->update([
                    'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
                    'jawaban_standar_approved_by' => $actor->id,
                    'jawaban_standar_approved_at' => $approvedAt,
                    'updated_at' => $approvedAt,
                ]);

            if ($affected === 0) {
                return false;
            }

            $this->auditLogger->record(
                action: self::AUDIT_ACTION,
                description: "Kunci jawaban item versi #{$item->id} disetujui.",
                context: [
                    'soal_type' => $item->soal_type,
                    'soal_id' => $item->soal_id,
                    'version_set_id' => $item->version_set_id,
                    'created_by' => $item->jawaban_standar_created_by,
                ],
                subjectType: self::AUDIT_SUBJECT_TYPE,
                subjectId: (int) $item->id,
                actorType: 'asisten',
                actorId: (int) $actor->id,
            );

            return true;
        });

        if (! $approved) {
            return ['ok' => false, 'code' => 'INVALID_STATUS'];
        }

        return ['ok' => true, 'item' => $item->fresh()];
    }

    /**
     * The actor's primary role and any Spatie roles both count.
     */
    public function isApprover(Asisten $actor): bool
    {
        $roleNames = $actor->getRoleNames()->all();

        $primaryRole = $actor->role()->value('name');

        if ($primaryRole !== null) {
            $roleNames[] = $primaryRole;
        }

        return array_intersect(array_map('strtoupper', $roleNames), self::APPROVER_ROLES) !== [];
    }
}
