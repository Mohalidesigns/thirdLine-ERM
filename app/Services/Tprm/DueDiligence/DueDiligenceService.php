<?php

namespace App\Services\Tprm\DueDiligence;

use App\Models\Tprm\DueDiligenceChecklist;
use App\Models\Tprm\DueDiligenceItem;
use App\Models\Tprm\Engagement;
use App\Support\Tprm\DueDiligenceTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generating and closing due diligence — FR-DDL-02, FR-DDL-08, FR-DDL-09.
 *
 * TWO RULES, AND BETWEEN THEM THEY ARE THE WHOLE CONTROL.
 *
 *   AN ITEM CLOSES WITH EVIDENCE OR A WAIVER, never on an assertion. A
 *   checklist that can be ticked through is a checklist that gets ticked
 *   through, and the resulting record says due diligence was performed when
 *   what happened was that somebody clicked seventeen boxes.
 *
 *   DUE DILIGENCE CANNOT COMPLETE WHILE A MANDATORY ITEM IS OPEN. The refusal
 *   names the items, because "cannot complete" with no list teaches a user to
 *   look for whoever can override it.
 *
 * A WAIVER NEEDS AN APPROVER, A REASON AND AN EXPIRY — the same three the
 * clause waiver and the risk acceptance need, for the same reason. A waiver
 * with no expiry is a skip with paperwork attached.
 */
class DueDiligenceService
{
    /**
     * Generate the checklist for an engagement at its current tier.
     *
     * Returns the existing one if there is an open checklist: regenerating
     * would discard the evidence already gathered, and a re-tiered engagement
     * needs the NEW items adding rather than the old ones replacing.
     */
    public function generate(Engagement $engagement, ?int $userId = null): DueDiligenceChecklist
    {
        $existing = DueDiligenceChecklist::query()
            ->where('engagement_id', $engagement->getKey())
            ->open()
            ->first();

        $tier = $engagement->effectiveTier();
        $template = DueDiligenceTemplates::forTier($tier);

        if ($existing !== null) {
            $this->addMissingItems($existing, $template, $userId);

            return $existing->refresh();
        }

        return DB::transaction(function () use ($engagement, $tier, $template, $userId) {
            $checklist = DueDiligenceChecklist::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'template_code' => 'interagency-2023',
                // Recorded and never updated: "we did the due diligence the
                // tier required" is a claim about the tier in force at the
                // time.
                'tier_at_generation' => $tier?->value,
                'created_by' => $userId,
            ]);

            foreach ($template as $item) {
                DueDiligenceItem::create([
                    'organization_id' => $engagement->organization_id,
                    'checklist_id' => $checklist->getKey(),
                    'code' => $item['code'],
                    'title' => $item['title'],
                    'category' => $item['category'],
                    'item_type' => $item['item_type'],
                    'is_mandatory' => $item['is_mandatory'],
                    'owner_id' => $engagement->relationship_owner_id,
                    'created_by' => $userId,
                ]);
            }

            return $checklist->refresh();
        });
    }

    /**
     * Complete an item with its evidence.
     *
     * @return array{completed: bool, reason: string|null}
     */
    public function complete(DueDiligenceItem $item, ?int $documentId, ?int $userId = null): array
    {
        // A screening item is evidenced by the screening record rather than by
        // an uploaded document, and a site visit by the visit record. Demanding
        // a document for those would push people towards uploading a
        // screenshot of a screen the system already holds.
        $needsDocument = ! in_array($item->item_type, ['screening', 'site_visit', 'assessment', 'record'], true);

        if ($needsDocument && $documentId === null && $item->evidence_document_id === null) {
            return [
                'completed' => false,
                'reason' => 'This item closes with evidence attached. A checklist that can be ticked through '
                    .'is one that gets ticked through, and the record then says due diligence was performed '
                    .'when what happened was that somebody clicked a box.',
            ];
        }

        $item->forceFill([
            'status' => DueDiligenceItem::STATUS_COMPLETE,
            'evidence_document_id' => $documentId ?? $item->evidence_document_id,
            'completed_at' => now(),
            'updated_by' => $userId,
        ])->save();

        return ['completed' => true, 'reason' => null];
    }

    /**
     * Waive an item — FR-DDL-08.
     *
     * @return array{waived: bool, reason: string|null}
     */
    public function waive(
        DueDiligenceItem $item,
        string $reason,
        int $approverId,
        Carbon $expiresAt,
        ?int $userId = null,
    ): array {
        if (strlen(trim($reason)) < 20) {
            return [
                'waived' => false,
                'reason' => 'A waiver needs a reason somebody can review. "Not applicable" is not one — say '
                    .'why it does not apply to this engagement.',
            ];
        }

        if ($expiresAt->isBefore(now()->addDay())) {
            return [
                'waived' => false,
                'reason' => 'A waiver needs an expiry in the future. Without one it is a skip with paperwork '
                    .'attached: nobody revisits it, and the item never comes back.',
            ];
        }

        $item->forceFill([
            'status' => DueDiligenceItem::STATUS_WAIVED,
            'waiver_reason' => $reason,
            'waiver_approver_id' => $approverId,
            'waiver_expires_at' => $expiresAt->toDateString(),
            'updated_by' => $userId,
        ])->save();

        return ['waived' => true, 'reason' => null];
    }

    /**
     * Close the checklist — FR-DDL-09.
     *
     * @return array{completed: bool, reason: string|null, blockers: list<array<string, mixed>>}
     */
    public function completeChecklist(DueDiligenceChecklist $checklist, ?int $userId = null): array
    {
        $blockers = $checklist->blockers();

        if ($blockers->isNotEmpty()) {
            return [
                'completed' => false,
                // The items are NAMED. "Cannot complete" with no list teaches
                // a user to look for whoever can override it.
                'reason' => sprintf(
                    'Due diligence cannot be completed while %d mandatory item(s) are open at this '
                    ."engagement's tier:\n\n%s\n\nEach can be closed with evidence, or waived by an approver "
                    .'with a reason and an expiry.',
                    $blockers->count(),
                    $blockers->map(fn (DueDiligenceItem $item) => '· '.$item->code.' — '.$item->title)
                        ->implode("\n"),
                ),
                'blockers' => $blockers->map(fn (DueDiligenceItem $item) => [
                    'id' => $item->getKey(),
                    'code' => $item->code,
                    'title' => $item->title,
                    'status' => $item->status,
                    'lapsed_waiver' => $item->waiverHasLapsed(),
                ])->values()->all(),
            ];
        }

        $checklist->forceFill([
            'status' => DueDiligenceChecklist::STATUS_COMPLETE,
            'completed_at' => now(),
            'completed_by' => $userId,
        ])->save();

        return ['completed' => true, 'reason' => null, 'blockers' => []];
    }

    /**
     * Add items a re-tier introduced, leaving the existing ones alone.
     *
     * @param  list<array{code: string, title: string, category: string, item_type: string, is_mandatory: bool}>  $template
     */
    private function addMissingItems(DueDiligenceChecklist $checklist, array $template, ?int $userId): void
    {
        $existing = $checklist->items()->pluck('code')->flip();

        foreach ($template as $item) {
            if ($existing->has($item['code'])) {
                continue;
            }

            DueDiligenceItem::create([
                'organization_id' => $checklist->organization_id,
                'checklist_id' => $checklist->getKey(),
                'code' => $item['code'],
                'title' => $item['title'],
                'category' => $item['category'],
                'item_type' => $item['item_type'],
                'is_mandatory' => $item['is_mandatory'],
                'created_by' => $userId,
            ]);
        }
    }
}
