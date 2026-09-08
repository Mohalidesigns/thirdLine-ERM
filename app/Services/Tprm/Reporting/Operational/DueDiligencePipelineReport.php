<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Models\Tprm\DueDiligenceChecklist;
use App\Models\Tprm\DueDiligenceItem;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * What is holding each onboarding up — FR-RPT-07.
 *
 * THE BLOCKING COUNT IS THE COLUMN PEOPLE READ. A checklist at "12 of 15
 * complete" tells nobody whether the vendor can go live; three outstanding
 * mandatory items and three outstanding optional ones are the same fraction
 * and different situations.
 *
 * WAIVED MANDATORY ITEMS ARE COUNTED AND SHOWN SEPARATELY, because a
 * checklist completed by waiving its hard requirements is a checklist that did
 * not happen, and the reader is entitled to see that without opening it.
 */
class DueDiligencePipelineReport implements OperationalReport
{
    use StatesAbsence;

    public function key(): string
    {
        return 'due-diligence-pipeline';
    }

    public function title(): string
    {
        return 'Due diligence pipeline';
    }

    public function description(): string
    {
        return 'Open due diligence checklists, what is outstanding on each, and how much of the progress came '
            .'from waiving mandatory items.';
    }

    public function permission(): string
    {
        return 'tprm.view';
    }

    public function headers(): array
    {
        return [
            'Engagement', 'Provider', 'Tier at generation', 'Checklist', 'Status', 'Items',
            'Complete', 'Outstanding', 'Blocking outstanding', 'Mandatory waived',
            'Oldest outstanding item', 'Days open', 'Completed on',
        ];
    }

    public function rows(): array
    {
        $checklists = DueDiligenceChecklist::query()
            ->with(['items.owner:id,name', 'engagement.thirdParty:id,legal_name', 'completer:id,name'])
            ->orderBy('created_at')
            ->get();

        return $checklists->map(function (DueDiligenceChecklist $checklist) {
            $items = $checklist->items;
            $outstanding = $items->reject(fn (DueDiligenceItem $item) => $item->isSettled());

            $oldest = $outstanding
                ->filter(fn (DueDiligenceItem $item) => $item->due_date !== null)
                ->sortBy(fn (DueDiligenceItem $item) => $item->due_date)
                ->first();

            return [
                $this->labelOf($checklist->engagement, 'reference', 'Not linked'),
                $this->labelOf($checklist->engagement?->thirdParty, 'legal_name', 'Not recorded'),
                $checklist->tier_at_generation
                    ? ucfirst($checklist->tier_at_generation)
                    : 'Not recorded',
                $checklist->template_code ?: 'Checklist #'.$checklist->getKey(),
                ucwords(str_replace('_', ' ', (string) $checklist->status)),
                $items->count(),
                $items->where('status', DueDiligenceItem::STATUS_COMPLETE)->count(),
                $outstanding->count(),
                // What actually stops the engagement going live.
                $outstanding->where('is_mandatory', true)->count(),
                $items->where('is_mandatory', true)->where('status', DueDiligenceItem::STATUS_WAIVED)->count(),
                $oldest === null ? 'None dated' : $oldest->code.' — due '.$oldest->due_date->toDateString(),
                (int) $checklist->created_at->diffInDays(now()),
                $checklist->completed_at?->toDateString() ?? 'Open',
            ];
        })->values()->all();
    }

    public function notes(): array
    {
        return [
            'Population' => 'Every due diligence checklist, open and completed',
            'Blocking outstanding' => 'Mandatory items not complete, waived or marked not applicable',
        ];
    }
}
