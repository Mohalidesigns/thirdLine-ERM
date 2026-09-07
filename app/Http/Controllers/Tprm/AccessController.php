<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\AccessLevel;
use App\Enums\Tprm\ConnectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\StoreAccessGrantRequest;
use App\Http\Requests\Tprm\StoreConnectionRequest;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Connection;
use App\Models\Tprm\Engagement;
use App\Services\Tprm\Access\AccessService;
use App\Services\Tprm\Access\TerminationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Connections, access grants and the reconciliation report — FR-ACC.
 *
 * THE RECONCILIATION REPORT IS THE LANDING PAGE, not a tab behind the
 * register. A list of every connection ever recorded is a filing cabinet; the
 * list of access that outlived its engagement is the control, and it is the
 * artefact an examiner asks for by name. Opening on the cabinet would put the
 * four rows that matter three clicks from anybody.
 */
class AccessController extends Controller
{
    public function __construct(
        private readonly AccessService $access,
        private readonly TerminationGuard $guard,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.access.view');

        $reconciliation = $this->access->reconciliation();

        return Inertia::render('Tprm/Access/Index', [
            'reconciliation' => [
                'discontinued' => $this->access->sortByExposure($reconciliation['discontinued']),
                'expired_contract' => $this->access->sortByExposure($reconciliation['expired_contract']),
                'overdue' => $this->access->sortByExposure($reconciliation['overdue']),
                'open_ended' => $this->access->sortByExposure($reconciliation['open_ended']),
                'open_connections' => $reconciliation['open_connections'],
                'generated_at' => $reconciliation['generated_at'],
            ],
            'can' => [
                'manage' => $request->user()->can('tprm.access.manage'),
            ],
        ]);
    }

    /** The access panel on one engagement — FR-ACC-01, FR-ACC-02. */
    public function show(Request $request, Engagement $engagement)
    {
        Gate::authorize('tprm.access.view');

        $engagement->load(['thirdParty:id,uuid,legal_name']);

        return Inertia::render('Tprm/Access/Engagement', [
            'engagement' => [
                'uuid' => $engagement->uuid,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'status_label' => $engagement->status->label(),
                'third_party' => $engagement->thirdParty?->legal_name,
            ],
            'connections' => $engagement->connections()
                ->with(['owner:id,name', 'approver:id,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Connection $c) => [
                    'id' => $c->getKey(),
                    'name' => $c->name,
                    'type' => $c->type->value,
                    'type_label' => $c->type->label(),
                    'endpoint' => $c->endpoint,
                    'direction' => $c->direction,
                    'encryption' => $c->encryption,
                    'authentication_method' => $c->authentication_method,
                    'firewall_rule_ref' => $c->firewall_rule_ref,
                    'status' => $c->status->value,
                    'status_label' => $c->status->label(),
                    'status_color' => $c->status->color(),
                    'owner' => $c->owner?->name,
                    'approved_by' => $c->approver?->name,
                    'closed_at' => $c->closed_at?->toDateString(),
                    'has_closure_evidence' => $c->closure_evidence_document_id !== null,
                    'closure_hint' => $c->type->closureEvidenceHint(),
                ])->values(),
            'grants' => $engagement->accessGrants()
                ->with(['approver:id,name', 'revoker:id,name'])
                ->orderBy('grantee_name')
                ->get()
                ->map(fn (AccessGrant $g) => [
                    'id' => $g->getKey(),
                    'grantee_name' => $g->grantee_name,
                    'grantee_email' => $g->grantee_email,
                    'system_name' => $g->system_name,
                    'access_level' => $g->access_level->value,
                    'access_level_label' => $g->access_level->label(),
                    'is_privileged' => $g->access_level->isPrivileged(),
                    'justification' => $g->justification,
                    'approved_by' => $g->approver?->name,
                    'approved_at' => $g->approved_at?->toDateString(),
                    'valid_from' => $g->valid_from?->toDateString(),
                    'valid_to' => $g->valid_to?->toDateString(),
                    'escort_required' => $g->escort_required,
                    'monitoring_method' => $g->monitoring_method,
                    'status' => $g->status->value,
                    'status_label' => $g->status->label(),
                    'status_color' => $g->status->color(),
                    'is_overdue' => $g->isOverdue(),
                    'is_open_ended' => $g->isOpenEnded(),
                    'revoked_at' => $g->revoked_at?->toDateString(),
                ])->values(),
            'terminationVerdict' => $this->guard->check($engagement)->toArray(),
            'connectionTypes' => collect(ConnectionType::cases())->map(fn (ConnectionType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'closure_hint' => $t->closureEvidenceHint(),
            ])->values(),
            'accessLevels' => collect(AccessLevel::cases())->map(fn (AccessLevel $l) => [
                'value' => $l->value,
                'label' => $l->label(),
                'privileged' => $l->isPrivileged(),
            ])->values(),
            'can' => [
                'manage' => $request->user()->can('tprm.access.manage'),
            ],
        ]);
    }

    public function storeConnection(StoreConnectionRequest $request, Engagement $engagement)
    {
        $this->access->recordConnection($engagement, $request->validated(), $request->user()->id);

        return back()->with('success', 'Connection recorded.');
    }

    public function closeConnection(Request $request, Connection $connection)
    {
        Gate::authorize('tprm.access.manage');

        $validated = $request->validate([
            'closure_evidence_document_id' => [
                'nullable',
                'integer',
                Rule::exists('tp_documents', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->access->close(
                $connection,
                $validated['closure_evidence_document_id'] ?? null,
                $validated['reason'] ?? null,
                $request->user()->id,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf('%s closed.', $connection->name));
    }

    public function storeGrant(StoreAccessGrantRequest $request, Engagement $engagement)
    {
        $this->access->grantAccess($engagement, $request->validated(), $request->user()->id);

        return back()->with('success', 'Access grant recorded.');
    }

    public function approveGrant(Request $request, AccessGrant $grant)
    {
        Gate::authorize('tprm.access.manage');

        $this->access->approveGrant($grant, $request->user()->id);

        return back()->with('success', sprintf('Access for %s approved.', $grant->grantee_name));
    }

    public function revokeGrant(Request $request, AccessGrant $grant)
    {
        Gate::authorize('tprm.access.manage');

        $validated = $request->validate([
            'revocation_evidence_document_id' => [
                'required',
                'integer',
                Rule::exists('tp_documents', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
        ]);

        $this->access->revokeGrant(
            $grant,
            (int) $validated['revocation_evidence_document_id'],
            $request->user()->id,
        );

        return back()->with('success', sprintf('Access for %s revoked.', $grant->grantee_name));
    }
}
