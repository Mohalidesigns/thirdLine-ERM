<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\EnforcesNodeScope;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Controls\LinkControlRiskRequest;
use App\Http\Requests\Controls\StoreControlRequest;
use App\Http\Requests\Controls\UpdateControlRequest;
use App\Models\Control;
use App\Models\Risk;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Services\Controls\ControlService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The control library (migration Phase 3.4). Each action authorises through
 * ControlPolicy, hands the work to ControlService, and renders a page.
 */
class ControlController extends Controller
{
    // WP-00 node scoping. Route-model binding resolves a record through the
    // tenancy scope only, so every method that receives a bound model asks
    // EnforcesNodeScope whether the caller's subtree admits it — and gets a 404
    // rather than a 403 when it does not, so the record's existence is not
    // itself the answer. ControlPolicy checks the same reach as defence in
    // depth for callers holding the model some other way.
    use EnforcesNodeScope;

    // WP-05 TASK 2 — receives the fields a tenant added through the builder.
    // Without it, a configured field would render on the form, accept what was
    // typed, and discard it on submit.
    use PersistsConfiguredAttributes;

    public function __construct(
        private readonly ControlService $controls,
        private readonly FormSchemaPresenter $schemas,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index */
    /* ------------------------------------------------------------------ */

    /**
     * Display the control library listing. Search, filters, sorting and
     * pagination all moved into the shared data grid (WP-09) — see
     * App\Grids\Definitions\ControlsGrid.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Control::class);

        // WP-00: scoped like ControlsGrid, so the header total counts the
        // rows the grid beneath it will actually show.
        $total = Control::where('organization_id', TenantContext::organizationId())
            ->visibleTo()
            ->count();

        return Inertia::render('Controls/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('controls'), $request, $request->user()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / Store */
    /* ------------------------------------------------------------------ */

    public function create()
    {
        Gate::authorize('create', Control::class);

        return Inertia::render('Controls/Create', array_merge(
            $this->controls->formOptions(),
            ['schema' => $this->configuredOnly($this->schemas->form('Control'))],
        ));
    }

    public function store(StoreControlRequest $request)
    {
        $control = $this->controls->create(
            $request->validated(),
            TenantContext::organizationId(),
            $request->user()?->id,
        );

        $this->saveConfiguredAttributes($request, $control);

        return redirect()->route('risk.controls.show', $control)
            ->with('success', "Control {$control->control_code} has been created.");
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, Control $control)
    {
        $this->abortUnlessNodeVisible($control);
        Gate::authorize('view', $control);

        $user = $request->user();

        return Inertia::render('Controls/Show', array_merge(
            $this->controls->detail($control),
            [
                'configuredDetail' => $this->schemas->detail($control, 'Control', omit: self::DETAIL_OMIT, hideEmpty: true),
                'can' => [
                    'update' => $user->can('update', $control),
                    'delete' => $user->can('delete', $control),
                    'linkRisk' => $user->can('linkRisk', $control),
                    'createTest' => $user->can('create', \App\Models\ControlTest::class),
                ],
            ],
        ));
    }

    /**
     * Codes the detail page already draws by hand, so the "Additional
     * Information" card does not repeat them.
     *
     * @var list<string>
     */
    private const DETAIL_OMIT = [
        'control_code', 'name', 'description', 'control_type', 'control_nature',
        'frequency', 'automation_level', 'owner_id', 'business_unit_id',
        'effectiveness_rating', 'effectiveness_pct', 'status',
        'last_test_date', 'next_test_due', 'last_test_result',
        'tests_passed_count', 'tests_failed_count', 'total_tests_count',
    ];

    /* ------------------------------------------------------------------ */
    /*  Edit / Update */
    /* ------------------------------------------------------------------ */

    public function edit(Control $control)
    {
        $this->abortUnlessNodeVisible($control);
        Gate::authorize('update', $control);

        return Inertia::render('Controls/Edit', array_merge(
            $this->controls->formOptions(),
            [
                'control' => $this->controls->present($control->load(['controlOwner', 'businessUnit'])),
                'schema' => $this->configuredOnly($this->schemas->form('Control', $control)),
            ],
        ));
    }

    public function update(UpdateControlRequest $request, Control $control)
    {
        $this->abortUnlessNodeVisible($control);

        $this->controls->update($control, $request->validated(), $request->user()?->id);

        $this->saveConfiguredAttributes($request, $control);

        return redirect()->route('risk.controls.show', $control)
            ->with('success', "Control {$control->control_code} has been updated.");
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function destroy(Control $control)
    {
        $this->abortUnlessNodeVisible($control);
        Gate::authorize('delete', $control);

        $code = $control->control_code;

        if (($refusal = $this->controls->delete($control)) !== null) {
            return back()->with('error', $refusal);
        }

        return redirect()->route('risk.controls.index')
            ->with('success', "Control {$code} has been deleted.");
    }

    /* ------------------------------------------------------------------ */
    /*  Risk mappings */
    /* ------------------------------------------------------------------ */

    public function linkToRisk(LinkControlRiskRequest $request, Control $control)
    {
        $this->abortUnlessNodeVisible($control);

        ['risk' => $risk, 'linked' => $linked] = $this->controls->linkRisk(
            $control,
            $request->validated(),
            $request->user()?->id,
        );

        return back()->with(
            $linked ? 'success' : 'error',
            $linked
                ? "Control {$control->control_code} linked to risk {$risk->risk_code}."
                : "Control {$control->control_code} is already linked to risk {$risk->risk_code}.",
        );
    }

    public function unlinkFromRisk(Control $control, Risk $risk)
    {
        $this->abortUnlessNodeVisible($control);
        Gate::authorize('linkRisk', $control);

        $this->controls->unlinkRisk($control, $risk);

        return back()->with('success', "Control {$control->control_code} unlinked from risk {$risk->risk_code}.");
    }

    /* ------------------------------------------------------------------ */

    /**
     * The tenant-configured fields only. A column-backed attribute is already
     * an input on the bespoke form; rendering the schema's copy as well would
     * put two controls on one column.
     *
     * @param  array{objectType: mixed, sections: list<array{code:string,label:string,fields:list<array<string,mixed>>}>}  $schema
     * @return array{objectType: mixed, sections: list<array{code:string,label:string,fields:list<array<string,mixed>>}>}
     */
    private function configuredOnly(array $schema): array
    {
        $sections = [];

        foreach ($schema['sections'] as $section) {
            $fields = array_values(array_filter($section['fields'], fn (array $field) => ! $field['mapped']));

            if ($fields !== []) {
                $sections[] = array_merge($section, ['fields' => $fields]);
            }
        }

        return ['objectType' => $schema['objectType'], 'sections' => $sections];
    }
}
