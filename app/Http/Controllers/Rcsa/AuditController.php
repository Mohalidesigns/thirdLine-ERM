<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaLineRevision;
use App\Models\RiskAuditTrail;
use App\Services\Rcsa\RcsaWorkflowService;
use App\Support\MorphTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * §11's read-only audit view: everything that happened to one assessment.
 *
 * READ-ONLY IS NOT A UI DECISION HERE. There is no write endpoint on this
 * controller and there is nowhere for one to go: `rcsa_assessment_transitions`
 * and `rcsa_line_revisions` are append-only by construction (no `updated_at`,
 * no soft delete) and `risk_audit_trail` is append-only by database trigger.
 * The screen is read-only because the data is.
 *
 * THREE SOURCES, ONE TIMELINE. The workflow log says who moved the assessment,
 * the revisions say what changed on which line, and the estate-wide trail is
 * where an auditor correlates both with every other module by request id. They
 * are shown together because "what happened here" is one question, and made
 * distinguishable because they answer it at different grains.
 *
 * IT IS SCOPED LIKE EVERYTHING ELSE. `Gate::authorize('view', ...)` routes
 * through `RcsaAssessmentPolicy::reachable()`, so a champion cannot read
 * Treasury's history any more than they can read its assessment.
 */
class AuditController extends Controller
{
    public function __construct(private readonly RcsaWorkflowService $workflow) {}

    public function show(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('view', $assessment);

        // The audit trail is a control, so seeing SOMEBODY ELSE'S actions on
        // your own assessment is the ordinary case; what `rcsa_audit.view`
        // gates is the estate-wide trail beside it, which carries IP addresses.
        $seesEstateTrail = $request->user()->can('rcsa_audit.view');

        $assessment->load(['cycle:id,name', 'businessUnit:id,name']);

        $lineIds = $assessment->lines()->pluck('id');

        $revisions = RcsaLineRevision::query()
            ->whereIn('line_id', $lineIds)
            ->with(['user:id,name'])
            ->latest('created_at')
            ->limit(500)
            ->get();

        // One query for the risk numbers rather than a relation per revision:
        // five hundred rows over a two-hundred-line assessment is otherwise
        // five hundred queries on a screen nobody is waiting on.
        $riskNumbers = RcsaAssessmentLine::query()
            ->whereIn('id', $lineIds)
            ->pluck('risk_no', 'id');

        return Inertia::render('RcsaAudit/Show', [
            'assessment' => [
                'id' => $assessment->id,
                'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
                'cycle' => $assessment->getRelationValue('cycle')?->name,
                'status' => $assessment->status,
            ],
            'transitions' => $this->workflow->history($assessment),
            'revisions' => $revisions->map(fn (RcsaLineRevision $revision) => [
                'id' => $revision->id,
                'risk_no' => $riskNumbers[$revision->line_id] ?? null,
                'line_id' => $revision->line_id,
                'field' => $revision->field,
                'old' => $this->scalar($revision->old_value),
                'new' => $this->scalar($revision->new_value),
                'reason' => $revision->reason,
                'by' => $revision->getRelationValue('user')?->name,
                'at' => $revision->created_at?->toDateTimeString(),
                'request_id' => $revision->request_id,
            ])->all(),
            'estate' => $seesEstateTrail ? $this->estateTrail($assessment, $lineIds) : null,
            'can' => ['see_estate_trail' => $seesEstateTrail],
        ]);
    }

    /**
     * The same events as the tamper-evident trail records them.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $lineIds
     * @return list<array<string, mixed>>
     */
    private function estateTrail(RcsaAssessment $assessment, $lineIds): array
    {
        return RiskAuditTrail::query()
            ->with('changedByUser:id,name')
            ->where(function ($query) use ($assessment, $lineIds) {
                $query
                    ->where(fn ($q) => $q
                        ->whereIn('entity_type', MorphTypes::spellingsFor('rcsa_assessment'))
                        ->where('entity_id', $assessment->id))
                    ->orWhere(fn ($q) => $q
                        ->whereIn('entity_type', MorphTypes::spellingsFor('rcsa_assessment_line'))
                        ->whereIn('entity_id', $lineIds));
            })
            ->latest('changed_at')
            ->limit(500)
            ->get()
            ->map(fn (RiskAuditTrail $row) => [
                'id' => $row->id,
                'entity' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'action' => $row->action_type,
                'field' => $row->field_changed,
                'old' => $row->old_value,
                'new' => $row->new_value,
                'reason' => $row->change_reason,
                'by' => $row->getRelationValue('changedByUser')?->name,
                'at' => $row->changed_at?->toDateTimeString(),
                'ip' => $row->ip_address,
                // Whether this row still matches its own seal. A screen that
                // showed the trail without saying whether it verifies is a
                // screen that presents tampered rows as fact.
                'sealed' => $row->hash === $row->expectedHash(),
            ])
            ->all();
    }

    /**
     * Revision values are JSON columns holding scalars; print them as scalars.
     */
    private function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_array($value) => json_encode($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
