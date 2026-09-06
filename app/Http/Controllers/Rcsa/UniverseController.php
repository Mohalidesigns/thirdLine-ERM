<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\BulkUpdateRegisterRisksRequest;
use App\Http\Requests\Rcsa\DuplicateRegisterRiskRequest;
use App\Http\Requests\Rcsa\StoreRegisterRiskRequest;
use App\Http\Requests\Rcsa\StoreUniverseProcessRequest;
use App\Http\Requests\Rcsa\UpdateRegisterRiskRequest;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Rcsa\RcsaSystem;
use App\Models\User;
use App\Services\Rcsa\RcsaUniverseService;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The RCSA Universe — the governed inventory of Business Unit → Process →
 * Sub-Process → System → Risk → Control (plan §6).
 *
 * Thin, as every ported controller in this product is: the rules live in the
 * Form Requests, the writes in RcsaUniverseService, the authority in
 * RcsaRegisterRiskPolicy. What is here is the query behind the index and the
 * shape of the page's props.
 *
 * Every route is behind the `rcsa_v2` flag, which 404s when off — the whole
 * module is invisible until a tenant is ready for it.
 */
class UniverseController extends Controller
{
    public function __construct(private readonly RcsaUniverseService $universe) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaRegisterRisk::class);

        $orgId = TenantContext::organizationId();

        $risks = RcsaRegisterRisk::query()
            ->with([
                'businessUnit:id,name,code',
                'process:id,name',
                'subProcess:id,name',
                'owner:id,name',
                'controls:id,register_risk_id,description,control_type,frequency,control_owner_id,is_key',
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));

                // risk_no as well as the statement: a champion chasing a row
                // from a board paper has the number, not the wording.
                $query->where(fn ($q) => $q
                    ->where('risk_no', 'like', "%{$search}%")
                    ->orWhere('potential_risk', 'like', "%{$search}%")
                    ->orWhere('risk_driver', 'like', "%{$search}%"));
            })
            ->when($request->filled('business_unit'), fn ($q) => $q->where('business_unit_id', $request->input('business_unit')))
            ->when($request->filled('category'), fn ($q) => $q->where('risk_category', $request->input('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('business_unit_id')
            ->orderBy('risk_no')
            ->paginate(25)
            ->withQueryString();

        $risks->through(fn (RcsaRegisterRisk $risk) => $this->toListRow($risk));

        return Inertia::render('RcsaUniverse/Index', [
            'risks' => $risks,
            'filters' => $request->only(['search', 'business_unit', 'category', 'status']),
            'options' => $this->options($orgId),
            'can' => [
                'create' => $request->user()->can('create', RcsaRegisterRisk::class),
                'publish' => $request->user()->can('rcsa_universe.publish'),
                'delete' => $request->user()->can('rcsa_universe.delete'),
                'import' => $request->user()->can('import', RcsaRegisterRisk::class),
            ],
        ]);
    }

    public function store(StoreRegisterRiskRequest $request)
    {
        $data = $request->validated();

        $risk = $this->universe->create(
            attributes: collect($data)->except('controls')->all(),
            controls: $data['controls'] ?? [],
            actor: $request->user(),
        );

        return back()->with('success', "Risk {$risk->risk_no} added to the universe.");
    }

    public function update(UpdateRegisterRiskRequest $request, RcsaRegisterRisk $risk)
    {
        $data = $request->validated();

        $this->universe->update(
            risk: $risk,
            attributes: collect($data)->except('controls')->all(),
            controls: $data['controls'] ?? null,
            actor: $request->user(),
        );

        return back()->with('success', "Risk {$risk->risk_no} updated.");
    }

    public function destroy(Request $request, RcsaRegisterRisk $risk)
    {
        Gate::authorize('delete', $risk);

        // A published row has been, or may already have been, provisioned into
        // a cycle. Retiring withdraws it from future ones and keeps the trail;
        // deleting would leave assessment lines pointing at nothing. The screen
        // offers Retire for exactly this case, so this is a guard, not a wall.
        if ($risk->isPublished()) {
            return back()->with('error', 'A published risk is retired, not deleted, so that past assessments keep their source.');
        }

        $risk->delete();

        return back()->with('success', "Risk {$risk->risk_no} deleted.");
    }

    public function duplicate(DuplicateRegisterRiskRequest $request, RcsaRegisterRisk $risk)
    {
        $copy = $this->universe->duplicate($risk, $request->validated(), $request->user());

        return back()->with('success', "Copied to {$copy->risk_no}.");
    }

    public function publish(Request $request)
    {
        $ids = $this->authorisedIds($request, 'publish');

        $count = $this->universe->publish($ids, $request->user());

        return back()->with('success', $count === 1
            ? '1 risk published to the universe.'
            : "{$count} risks published to the universe.");
    }

    public function retire(Request $request)
    {
        $ids = $this->authorisedIds($request, 'retire');

        $count = $this->universe->retire($ids, $request->user());

        return back()->with('success', $count === 1 ? '1 risk retired.' : "{$count} risks retired.");
    }

    public function bulkUpdate(BulkUpdateRegisterRisksRequest $request)
    {
        $data = $request->validated();

        // The request proved the ids are this tenant's; the policy is asked
        // about each row, because a class-level ability cannot see a selection.
        $risks = RcsaRegisterRisk::whereIn('id', $data['ids'])->get();

        foreach ($risks as $risk) {
            Gate::authorize('update', $risk);
        }

        $count = $this->universe->bulkUpdate(
            ids: $risks->modelKeys(),
            attributes: collect($data)->except('ids')->all(),
            actor: $request->user(),
        );

        return back()->with('success', "{$count} risks updated.");
    }

    /**
     * Create a process or sub-process from inside the Add Risk panel (§6.2).
     */
    public function storeProcess(StoreUniverseProcessRequest $request)
    {
        $data = $request->validated();

        $process = BusinessProcess::create([
            'organization_id' => TenantContext::organizationId(),
            'business_unit_id' => $data['business_unit_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'code' => $this->processCode($data['name']),
            'is_active' => true,
        ]);

        return back()->with('success', "Process \"{$process->name}\" created.");
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * One row of the index table.
     *
     * Every field here is read off a column that exists — checked against the
     * migration, not assumed. A list row built from guessed property names
     * renders a dash for every record on every tenant and nothing fails, which
     * is a defect this product has shipped more than once.
     *
     * @return array<string, mixed>
     */
    private function toListRow(RcsaRegisterRisk $risk): array
    {
        return [
            'id' => $risk->id,
            'risk_no' => $risk->risk_no,

            // Names for the table, ids for the edit panel. BOTH are needed and
            // it is worth saying why: the panel edits this same row object, and
            // a payload carrying only the display names would open every edit
            // with an empty Business Unit and Process — the form would look
            // like a fresh one and saving it would blank the placement. The
            // panel's reads were checked against this list, not assumed.
            'business_unit' => $risk->getRelationValue('businessUnit')?->name,
            'business_unit_id' => $risk->business_unit_id,
            'process' => $risk->getRelationValue('process')?->name,
            'process_id' => $risk->process_id,
            'sub_process' => $risk->getRelationValue('subProcess')?->name,
            'sub_process_id' => $risk->sub_process_id,
            'system_ids' => $risk->system_ids ?? [],

            'potential_risk' => $risk->potential_risk,
            'risk_driver' => $risk->risk_driver,
            'risk_category' => $risk->risk_category,
            'secondary_categories' => $risk->secondary_categories ?? [],
            'default_likelihood' => $risk->default_likelihood,
            'default_impact' => $risk->default_impact,

            'owner' => $risk->getRelationValue('owner')?->name,
            'owner_id' => $risk->owner_id,
            'status' => $risk->status,

            'controls_count' => $risk->controls->count(),
            'controls' => $risk->controls->map(fn (RcsaRegisterControl $control) => [
                'id' => $control->id,
                'description' => $control->description,
                'control_type' => $control->control_type,
                'frequency' => $control->frequency,
                'control_owner_id' => $control->control_owner_id,
                'is_key' => (bool) $control->is_key,
            ])->all(),
            'updated_at' => $risk->updated_at?->toDateString(),
        ];
    }

    /**
     * The selects the filter bar and the Add Risk panel need.
     *
     * Processes carry `parent_id` so the panel can narrow the sub-process list
     * on the client without a round trip per keystroke — the panel is meant to
     * be operable entirely from the keyboard, and a select that waits on the
     * network between Tab presses is not.
     *
     * @return array<string, mixed>
     */
    private function options(int $orgId): array
    {
        return [
            'businessUnits' => BusinessUnit::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->all(),

            'processes' => BusinessProcess::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'business_unit_id', 'parent_id'])
                ->all(),

            'systems' => RcsaSystem::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->all(),

            'owners' => User::query()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),

            'categories' => Template::RISK_CATEGORIES,
            'controlTypes' => RcsaRegisterControl::TYPES,
            'controlFrequencies' => RcsaRegisterControl::FREQUENCIES,
            'statuses' => RcsaRegisterRisk::STATUSES,
        ];
    }

    /**
     * The selected ids, each checked against the policy.
     *
     * @return list<int>
     */
    private function authorisedIds(Request $request, string $ability): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        // The global tenancy scope narrows this to the caller's organisation,
        // so an id from another tenant simply does not come back — and the
        // policy is still asked about every row that does.
        $risks = RcsaRegisterRisk::whereIn('id', $validated['ids'])->get();

        foreach ($risks as $risk) {
            Gate::authorize($ability, $risk);
        }

        return $risks->modelKeys();
    }

    /**
     * A short code for an inline-created process.
     *
     * `business_processes.code` is not nullable and every existing row has a
     * short uppercase code, so one is derived rather than asking a user
     * mid-form for a value they have no basis to choose. Uniqueness is not
     * enforced on the column, so a collision is harmless.
     */
    private function processCode(string $name): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? '');

        return substr($code !== '' ? $code : 'PROC', 0, 20);
    }
}
