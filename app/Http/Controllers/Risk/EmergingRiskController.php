<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Models\EmergingRisk;
use App\Models\RiskCategory;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Services\ReferenceCodeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * CRUD for the emerging risk register.
 *
 * This is the object the Risk Radar plots. It exists because the radar
 * previously rendered a hardcoded list of eight emerging risks that were the
 * same for every tenant. WP-29 horizon scanning will later populate the same
 * table automatically; until then it is a manual register, which is an honest
 * thing to ship and a fabricated feed is not.
 *
 * Reuses the risk.* permission set rather than minting emerging.* permissions:
 * an emerging risk is a register object, and anyone trusted to maintain the
 * risk register is trusted to maintain the horizon in front of it.
 */
class EmergingRiskController extends Controller
{
    // WP-05 TASK 2 — receives the fields a tenant added through the
    // builder. Without it, a configured field would render on the form,
    // accept what was typed, and discard it on submit.
    use PersistsConfiguredAttributes;

    /**
     * WP-09: the register is the shared data grid
     * (App\Grids\Definitions\EmergingRisksGrid), which owns the query, the
     * status/horizon/impact filters, sorting and pagination. Only the page
     * header is left, and it needs no data.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        return Inertia::render('Emerging/Index', [
            'grid' => fn () => $presenter->present(GridRegistry::resolve('emerging_risks'), $request, $request->user()),
        ]);
    }

    public function create()
    {
        return view('risk.emerging.create', $this->formOptions() + [
            'entry' => new EmergingRisk([
                'horizon' => '6-12m',
                'velocity_score' => 3,
                'proximity_score' => 3,
                'potential_impact' => 'Medium',
                'status' => 'monitoring',
                'detected_at' => now()->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $orgId = TenantContext::organizationId();
        $validated = $this->validated($request);

        $validated['organization_id'] = $orgId;
        $validated['created_by'] = auth()->id();
        $validated['reference'] = ReferenceCodeService::generate(
            'emerging_risks',
            'reference',
            'EMR',
            4,
            $orgId
        );

        $entry = EmergingRisk::create($validated);

        // Fields the tenant added through the builder, if any.
        $this->saveConfiguredAttributes($request, $entry);

        return redirect()->route('risk.emerging.index')
            ->with('success', "Emerging risk {$entry->reference} added to the register.");
    }

    public function edit(EmergingRisk $emerging)
    {
        $this->assertSameTenant($emerging);

        return view('risk.emerging.edit', $this->formOptions() + ['entry' => $emerging]);
    }

    public function update(Request $request, EmergingRisk $emerging)
    {
        $this->assertSameTenant($emerging);

        $emerging->update($this->validated($request));

        $this->saveConfiguredAttributes($request, $emerging);

        return redirect()->route('risk.emerging.index')
            ->with('success', "Emerging risk {$emerging->reference} updated.");
    }

    public function destroy(EmergingRisk $emerging)
    {
        $this->assertSameTenant($emerging);

        $reference = $emerging->reference;
        $emerging->delete();

        return redirect()->route('risk.emerging.index')
            ->with('success', "Emerging risk {$reference} removed from the register.");
    }

    /**
     * Record that someone has looked at the entry and it is still current.
     * A horizon view whose entries are never revisited quietly rots, so the
     * review date is a first-class action rather than a field buried in edit.
     */
    public function review(EmergingRisk $emerging)
    {
        $this->assertSameTenant($emerging);

        $emerging->update(['last_reviewed_at' => now()->toDateString()]);

        return back()->with('success', "{$emerging->reference} marked as reviewed today.");
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Route-model binding resolves through the tenant global scope, but this
     * belt-and-braces check keeps the guarantee if that scope is ever bypassed
     * upstream.
     */
    private function assertSameTenant(EmergingRisk $emerging): void
    {
        abort_unless(
            (int) $emerging->organization_id === TenantContext::organizationId(),
            403
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'categories' => RiskCategory::where('organization_id', $orgId)->orderBy('name')->get(),
            'owners' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'statuses' => EmergingRisk::STATUSES,
            'horizons' => EmergingRisk::HORIZONS,
            'impacts' => EmergingRisk::IMPACTS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $orgId = TenantContext::organizationId();

        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            // The tenant filter on the exists rule matters: without it a user
            // could attach their emerging risk to another organisation's
            // category or user by posting a foreign id.
            'category_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('risk_categories', 'id')
                    ->where('organization_id', $orgId),
            ],
            'horizon' => 'required|in:'.implode(',', EmergingRisk::HORIZONS),
            'velocity_score' => 'required|integer|min:1|max:5',
            'proximity_score' => 'required|integer|min:1|max:5',
            'potential_impact' => 'required|in:'.implode(',', EmergingRisk::IMPACTS),
            'status' => 'required|in:'.implode(',', EmergingRisk::STATUSES),
            'source' => 'nullable|string|max:160',
            'source_reference' => 'nullable|string|max:2000',
            'detected_at' => 'nullable|date',
            'last_reviewed_at' => 'nullable|date',
            'potential_response' => 'nullable|string|max:5000',
            'owner_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('users', 'id')
                    ->where('organization_id', $orgId),
            ],
        ]);
    }
}
