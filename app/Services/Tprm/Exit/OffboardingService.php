<?php

namespace App\Services\Tprm\Exit;

use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Connection;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\OffboardingChecklist;
use App\Models\Tprm\OffboardingItem;
use App\Models\Tprm\Waiver;
use App\Services\Tprm\Access\TerminationGuard;
use App\Support\Tprm\OffboardingTemplate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The offboarding checklist — FR-EXT-03, and the second gate on `terminated`.
 *
 * IT RECONCILES RATHER THAN DUPLICATES. Two of the nine items — access
 * revocation and connection closure — are already answered by the Phase 7
 * register and already enforced by `TerminationGuard`. A checklist that let
 * somebody tick them by hand would give a bank two answers to one question and
 * let it complete its offboarding while the guard still refused the
 * transition. `refresh()` reads those two from the register on every look;
 * nothing can mark them complete directly.
 *
 * THE CHECKLIST IS GENERATED WHEN AN ENGAGEMENT ENTERS TRANSITION, not when
 * somebody presses terminate. By the time a relationship is being terminated
 * the data return should already have happened; a checklist that appeared at
 * the end would be a list of things it is too late to do.
 */
class OffboardingService
{
    public function __construct(private readonly TerminationGuard $access) {}

    /**
     * Create the checklist for an engagement, or return the one it has.
     *
     * Idempotent: entering transition twice, or a retried job, must not
     * produce two checklists whose items disagree.
     */
    public function generate(Engagement $engagement, ?int $userId = null): OffboardingChecklist
    {
        return DB::transaction(function () use ($engagement, $userId): OffboardingChecklist {
            $existing = OffboardingChecklist::query()
                ->where('engagement_id', $engagement->getKey())
                ->first();

            if ($existing !== null) {
                return $this->refresh($existing);
            }

            $checklist = OffboardingChecklist::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'created_by' => $userId,
            ]);

            foreach (OffboardingTemplate::ITEMS as $template) {
                OffboardingItem::create([
                    'organization_id' => $engagement->organization_id,
                    'checklist_id' => $checklist->getKey(),
                    'code' => $template['code'],
                    'title' => $template['title'],
                    'item_type' => $template['item_type'],
                    'is_mandatory' => $template['mandatory'],
                    'owner_id' => $engagement->relationship_owner_id,
                ]);
            }

            return $this->refresh($checklist->refresh());
        });
    }

    /**
     * Re-read the reconciled items from the registers that own them.
     */
    public function refresh(OffboardingChecklist $checklist): OffboardingChecklist
    {
        $engagementId = (int) $checklist->engagement_id;

        $openGrants = AccessGrant::query()
            ->where('engagement_id', $engagementId)->live()->count();

        $openConnections = Connection::query()
            ->where('engagement_id', $engagementId)->open()->count();

        foreach ($checklist->items as $item) {
            $satisfied = match ($item->reconcilesWith()) {
                'access_grants' => $openGrants === 0,
                'connections' => $openConnections === 0,
                default => null,
            };

            if ($satisfied === null || $item->status === OffboardingItem::STATUS_EXCEPTED) {
                continue;
            }

            $item->forceFill([
                'status' => $satisfied ? OffboardingItem::STATUS_COMPLETE : OffboardingItem::STATUS_OPEN,
                'completed_at' => $satisfied ? ($item->completed_at ?? now()) : null,
            ])->save();
        }

        return $checklist->refresh();
    }

    /**
     * Complete one item by hand, with its evidence.
     *
     * A RECONCILED ITEM CANNOT BE COMPLETED THIS WAY. See the class comment:
     * the whole point is that those two read their answer.
     *
     * @throws InvalidArgumentException
     */
    public function complete(OffboardingItem $item, ?int $documentId = null, ?int $userId = null): OffboardingItem
    {
        if ($item->isReconciled()) {
            throw new InvalidArgumentException(sprintf(
                'This item is answered by the access register, not by hand. %s',
                $item->guidance() ?? '',
            ));
        }

        $item->forceFill([
            'status' => OffboardingItem::STATUS_COMPLETE,
            'evidence_document_id' => $documentId,
            'completed_at' => now(),
        ])->save();

        return $item->refresh();
    }

    /**
     * Except an item, which needs an approved waiver.
     *
     * IT WRITES A WAIVER ROW RATHER THAN JUST A REASON. Phase 0 built one
     * override register for the whole module precisely so that a risk
     * committee reads one report rather than four; an offboarding exception
     * recorded only as free text on an item would never reach it.
     */
    public function except(
        OffboardingItem $item,
        string $reason,
        int $approverId,
        ?int $userId = null,
    ): OffboardingItem {
        return DB::transaction(function () use ($item, $reason, $approverId, $userId): OffboardingItem {
            Waiver::create([
                'organization_id' => $item->organization_id,
                'waivable_type' => Waiver::TYPE_OFFBOARDING_ITEM,
                'waivable_id' => $item->getKey(),
                'engagement_id' => $item->checklist?->engagement_id,
                'rationale' => $reason,
                'requested_by' => $userId,
                'requested_at' => now(),
                'approver_id' => $approverId,
                'approved_at' => now(),
                'status' => Waiver::STATUS_APPROVED,
            ]);

            $item->forceFill([
                'status' => OffboardingItem::STATUS_EXCEPTED,
                'exception_reason' => $reason,
                'exception_approver_id' => $approverId,
                'completed_at' => now(),
            ])->save();

            return $item->refresh();
        });
    }

    /**
     * What stands between this engagement and `terminated`.
     *
     * TWO GATES, REPORTED TOGETHER. `TerminationGuard` refuses while access is
     * open; this refuses while a mandatory item is outstanding. Reporting them
     * separately would send somebody round the loop twice — clear the access,
     * press terminate, discover the data destruction certificate is missing.
     *
     * @return array{allowed: bool, reason: string|null, blockers: list<array<string, mixed>>}
     */
    public function terminationReadiness(Engagement $engagement): array
    {
        $accessVerdict = $this->access->check($engagement);

        $checklist = OffboardingChecklist::query()
            ->where('engagement_id', $engagement->getKey())
            ->with('items')
            ->first();

        $blockers = $accessVerdict->blockers;

        if ($checklist !== null) {
            $this->refresh($checklist);

            foreach ($checklist->blockingItems() as $item) {
                // Reconciled items already appear as access blockers above;
                // listing them twice would say the same thing in two voices.
                if ($item->isReconciled()) {
                    continue;
                }

                $blockers[] = [
                    'kind' => 'offboarding_item',
                    'id' => $item->getKey(),
                    'label' => $item->title,
                    'detail' => $item->guidance(),
                    'status' => $item->status,
                    'action' => 'Complete it with evidence, or record an approved exception.',
                ];
            }
        }

        if ($blockers === []) {
            return ['allowed' => true, 'reason' => null, 'blockers' => []];
        }

        return [
            'allowed' => false,
            'reason' => sprintf(
                '%d item%s outstanding before this engagement can be terminated.',
                count($blockers),
                count($blockers) === 1 ? ' is' : 's are',
            ),
            'blockers' => $blockers,
        ];
    }
}
