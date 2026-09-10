<?php

namespace App\Services\Bcms\Plans;

use App\Models\Bcms\Plan;
use App\Models\Organization;
use Illuminate\Support\Str;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The printed plan.
 *
 * REUSES THE PRODUCT'S RENDERER RATHER THAN INTRODUCING ONE. `ThirdLine\
 * Reporting\DocumentRenderer` already runs dompdf with `isRemoteEnabled` off, a
 * chroot, and DejaVu Sans — the only bundled font that carries ₦, which on a
 * Nigerian bank's continuity plan is not a cosmetic detail. A second PDF stack
 * would be a second set of those decisions to get right.
 *
 * A PLAN IS PRINTED FROM `document()`, NOT FROM `render()`. An approved version
 * prints the snapshot frozen at approval, for ever; a draft prints live. That
 * is what makes "v1 remains retrievable and printable" true in the sense an
 * examiner means it.
 */
class PlanDocumentRenderer
{
    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly PlanAssembler $assembler,
    ) {}

    /** The PDF bytes. */
    public function pdf(Plan $plan, ?string $generatedBy = null): string
    {
        return $this->renderer->pdf('reports.pdf.bcms-plan', $this->data($plan, $generatedBy));
    }

    /** A filename a person can recognise in a downloads folder six months later. */
    public function filename(Plan $plan): string
    {
        return Str::slug($plan->title.' v'.$plan->version).'.pdf';
    }

    /** @return array<string, mixed> */
    public function data(Plan $plan, ?string $generatedBy = null): array
    {
        $sections = $this->assembler->document($plan);
        $drifted = $plan->sections()->where('needs_review', true)->pluck('title')->all();

        $plan->loadMissing(['owner:id,name', 'approver:id,name', 'supersedes:id,title,version', 'organization']);

        return [
            'organization' => $plan->organization ?? Organization::query()->find($plan->organization_id),
            'title' => $plan->title,
            'subtitle' => $plan->plan_type->label().' · version '.$plan->version,
            'version' => $plan->version,
            'generatedBy' => $generatedBy,
            'sections' => array_map(fn (array $s, int $i) => $s + ['anchor' => 'sec-'.$s['key']], $sections, array_keys($sections)),
            'plan' => [
                'title' => $plan->title,
                'plan_type_label' => $plan->plan_type->label(),
                'version' => $plan->version,
                'status' => $plan->status,
                'owner' => $plan->owner?->name,
                'approver' => $plan->approver?->name,
                'approved_at' => $plan->approved_at?->format('d F Y'),
                'effective_from' => $plan->effective_from?->format('d F Y'),
                'next_review_date' => $plan->next_review_date?->format('d F Y'),
                'is_stale' => $plan->next_review_date !== null && $plan->next_review_date->isPast(),
                'is_superseded' => $plan->status === 'archived',
                'supersedes' => $plan->supersedes === null
                    ? null
                    : $plan->supersedes->title.' v'.$plan->supersedes->version,
                'iso_clause_ref' => $plan->iso_clause_ref,
                'drifted_sections' => $drifted,
            ],
        ];
    }
}
