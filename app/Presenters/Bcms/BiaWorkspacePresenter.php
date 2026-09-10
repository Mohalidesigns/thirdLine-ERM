<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\DependencyCriticality;
use App\Enums\Bcms\DependencyRelation;
use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Models\Bcms\Application;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\DataSet;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Equipment;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Bcms\Bia\BiaAiDrafter;
use App\Services\Bcms\Bia\BiaValidator;
use App\Services\Bcms\Bia\MtpdDeriver;

/**
 * The BIA workspace (Blueprint §15, screen 9).
 *
 * THE GRID IS SENT AS A GRID. Six categories by seven horizons is forty-two
 * cells, and shipping them as forty-two independent props would make the page
 * reassemble a table the server already knows the shape of. The prop is a
 * category-keyed map of horizon-keyed cells, so the page renders rows and
 * columns without inventing an ordering of its own.
 *
 * THE DERIVED MTPD TRAVELS WITH ITS REASONING. A proposed number on a screen
 * with no explanation is one an assessor either accepts without reading or
 * distrusts entirely; "at 4h, regulatory impact reaches 4 out of 5, which this
 * organisation has set as intolerable" is a sentence they can disagree with,
 * which is the point.
 *
 * VALIDATION IS SHOWN LIVE AND SPLIT. Blocking issues and warnings are separate
 * props because they mean different things — one stops the submit button, the
 * other is a sentence somebody senior should read — and a screen that merges
 * them teaches people that neither matters.
 */
class BiaWorkspacePresenter
{
    public function __construct(
        private BiaValidator $validator,
        private MtpdDeriver $deriver,
        private BiaAiDrafter $drafter,
    ) {}

    /** @return array<string, mixed> */
    public function present(BiaAssessment $assessment, ?User $user): array
    {
        $assessment->loadMissing(['process.businessUnit:id,name', 'assessor:id,name', 'approver:id,name', 'impacts', 'dependencies']);

        $validation = $this->validator->check($assessment);
        $derived = $this->deriver->derive($assessment);

        return [
            'assessment' => [
                'id' => $assessment->getKey(),
                'uuid' => $assessment->uuid,
                'status' => $assessment->status->value,
                'status_label' => $assessment->status->label(),
                'editable' => $assessment->status->isEditable(),
                'mtpd_hours' => $this->number($assessment->mtpd_hours),
                'rto_hours' => $this->number($assessment->rto_hours),
                'rpo_minutes' => $assessment->rpo_minutes === null ? null : (int) $assessment->rpo_minutes,
                'mbco_description' => $assessment->mbco_description,
                'min_staff_required' => $assessment->min_staff_required === null ? null : (int) $assessment->min_staff_required,
                'peak_periods' => $assessment->peak_periods ?? [],
                'workaround_available' => (bool) $assessment->workaround_available,
                'workaround_max_duration_hours' => $this->number($assessment->workaround_max_duration_hours),
                'ai_generated' => (bool) $assessment->ai_generated,
                'ai_drafted_at' => $assessment->ai_drafted_at?->toDateTimeString(),
                'ai_reasoning' => $assessment->ai_reasoning ?? [],
                'submitted_at' => $assessment->submitted_at?->toDateTimeString(),
                'approved_at' => $assessment->approved_at?->toDateTimeString(),
                'approver' => $assessment->approver?->name,
                'assessor' => $assessment->assessor?->name,
                'assessor_id' => $assessment->assessor_id,
            ],
            'process' => [
                'id' => $assessment->process?->getKey(),
                'code' => $assessment->process?->code,
                'name' => $assessment->process?->name,
                'description' => $assessment->process?->description,
                'unit' => $assessment->process?->businessUnit?->name,
                'tier' => $assessment->process?->criticality_tier,
                'is_critical_service' => (bool) $assessment->process?->is_critical_service,
                'critical_service_justification' => $assessment->process?->critical_service_justification,
                'regulatory_flags' => $assessment->process === null ? [] : ($assessment->process->regulatory_flags ?? []),
            ],
            'grid' => $this->grid($assessment),
            'derived_mtpd' => $derived,
            'validation' => $validation,
            'dependencies' => $this->dependencies($assessment),
            'dependency_options' => $this->options(),
            'ai' => [
                'available' => $this->drafter->available($assessment),
                'reason' => $this->drafter->unavailableReason($assessment),
            ],
            'can' => [
                'edit' => $user?->can('bcms.bia.complete') === true,
                'approve' => $user?->can('bcms.bia.approve') === true,
                // The approver may not be the assessor. Shown on the screen as
                // well as enforced in the service, so the button is not offered
                // to somebody who will be refused.
                'approve_this' => $user?->can('bcms.bia.approve') === true
                    && (int) $user->getKey() !== (int) $assessment->assessor_id,
            ],
        ];
    }

    /**
     * The impact grid, category by horizon.
     *
     * @return array<string, mixed>
     */
    private function grid(BiaAssessment $assessment): array
    {
        $cells = [];

        foreach ($assessment->impacts as $impact) {
            $category = $impact->impact_category instanceof ImpactCategory
                ? $impact->impact_category->value
                : (string) $impact->impact_category;
            $horizon = $impact->horizon instanceof ImpactHorizon
                ? $impact->horizon->value
                : (string) $impact->horizon;

            $cells[$category][$horizon] = [
                'severity' => $impact->severity_score === null ? null : (int) $impact->severity_score,
                // Minor units on the wire, formatted by the page. A naira
                // figure divided in two places drifts in one of them.
                'financial_amount_minor' => $impact->financial_amount_minor === null ? null : (int) $impact->financial_amount_minor,
                'narrative' => $impact->narrative,
            ];
        }

        return [
            'categories' => array_map(fn (ImpactCategory $c) => [
                'value' => $c->value,
                'label' => ucfirst($c->value),
                'monetary' => $c->isMonetary(),
            ], ImpactCategory::cases()),
            'horizons' => array_map(fn (ImpactHorizon $h) => [
                'value' => $h->value,
                'hours' => $h->hours(),
            ], ImpactHorizon::cases()),
            'cells' => $cells,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function dependencies(BiaAssessment $assessment): array
    {
        return $assessment->dependencies
            ->map(fn (Dependency $d) => [
                'id' => $d->getKey(),
                'type' => $d->dependable_type,
                'type_label' => $d->type()?->label(),
                'name' => $d->dependableLabel(),
                'relation' => $d->dependency_type,
                'relation_label' => DependencyRelation::tryFrom((string) $d->dependency_type)?->label(),
                'criticality' => $d->criticality,
                'single_point_of_failure' => (bool) $d->single_point_of_failure,
                'alternative_available' => (bool) $d->alternative_available,
                'recovery_notes' => $d->recovery_notes,
            ])
            ->sortByDesc(fn (array $d) => DependencyCriticality::tryFrom((string) $d['criticality'])?->rank() ?? 0)
            ->values()
            ->all();
    }

    /**
     * What can be depended on, per type.
     *
     * `vendors` comes from TPRM and `users` from the platform — BCMS holds no
     * copy of either (ADR 0001). The four seam registers are its own only
     * because nothing upstream owns them yet.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'types' => array_map(fn (DependencyType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'owned_by_bcms' => $t->isOwnedByBcms(),
            ], DependencyType::cases()),
            'relations' => array_map(fn (DependencyRelation $r) => [
                'value' => $r->value, 'label' => $r->label(), 'halts' => $r->halts(),
            ], DependencyRelation::cases()),
            'criticalities' => array_map(fn (DependencyCriticality $c) => [
                'value' => $c->value, 'label' => ucfirst($c->value),
            ], DependencyCriticality::cases()),
            'targets' => [
                DependencyType::Applications->value => Application::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                DependencyType::Vendors->value => ThirdParty::query()->orderBy('legal_name')->limit(200)->get(['id', 'legal_name as name']),
                DependencyType::Sites->value => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                DependencyType::Equipment->value => Equipment::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                DependencyType::DataSets->value => DataSet::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                DependencyType::Processes->value => Process::query()->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
                DependencyType::Users->value => User::query()->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name']),
            ],
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
