<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emerging\StoreEmergingRiskRequest;
use App\Http\Requests\Emerging\UpdateEmergingRiskRequest;
use App\Models\EmergingRisk;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Services\ReferenceCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

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
 * EmergingRiskPolicy asks for those same permissions — see its docblock.
 */
class EmergingRiskController extends Controller
{
    // WP-05 TASK 2 — receives the fields a tenant added through the
    // builder. Until Phase 4.6 this was a NO-OP for this model: EmergingRisk
    // was absent from ObjectTypeRegistry::modelTypeMap(), so the trait could
    // not resolve a type and returned 0 before validating or storing anything.
    // A builder-added field rendered, accepted what was typed and was
    // discarded, which is exactly the failure the trait exists to prevent.
    use PersistsConfiguredAttributes;

    private const OBJECT_TYPE = 'EmergingRisk';

    public function __construct(private readonly FormSchemaPresenter $schemas) {}

    /**
     * WP-09: the register is the shared data grid
     * (App\Grids\Definitions\EmergingRisksGrid), which owns the query, the
     * status/horizon/impact filters, sorting and pagination. Only the page
     * header is left, and it needs no data.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', EmergingRisk::class);

        return Inertia::render('Emerging/Index', [
            'grid' => fn () => $presenter->present(GridRegistry::resolve('emerging_risks'), $request, $request->user()),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', EmergingRisk::class);

        return Inertia::render('Emerging/Create', [
            // The whole form comes from the EmergingRisk object type, as the
            // Blade page's `<x-dynamic-form type="EmergingRisk">` did. Writing
            // the fields out here would put a second definition of "what an
            // emerging risk form contains" beside FormFieldRegistry's, and a
            // field a tenant added through the builder would render nowhere
            // while the controller went on saving it — 4.4's trap exactly.
            'schema' => $this->schemas->form(self::OBJECT_TYPE, defaults: [
                'horizon' => '6-12m',
                'velocity_score' => 3,
                'proximity_score' => 3,
                'potential_impact' => 'Medium',
                'status' => 'monitoring',
                'detected_at' => now()->toDateString(),
            ]),
        ]);
    }

    public function store(StoreEmergingRiskRequest $request)
    {
        $orgId = TenantContext::organizationId();

        // One transaction. The reference is drawn from a per-tenant sequence,
        // and a half-written entry that keeps its number is worse than none.
        $entry = DB::transaction(function () use ($request, $orgId) {
            $entry = EmergingRisk::create([
                ...$request->columns(),
                'organization_id' => $orgId,
                'created_by' => auth()->id(),
                'reference' => ReferenceCodeService::generate('emerging_risks', 'reference', 'EMR', 4, $orgId),
            ]);

            // Fields the tenant added through the builder, if any. Already
            // validated by the Form Request, so this cannot fail the save after
            // the row exists — which is what it used to do.
            $this->saveConfiguredAttributes($request, $entry);

            return $entry;
        });

        return redirect()->route('risk.emerging.index')
            ->with('success', "Emerging risk {$entry->reference} added to the register.");
    }

    public function edit(EmergingRisk $emerging)
    {
        Gate::authorize('update', $emerging);

        return Inertia::render('Emerging/Edit', [
            'entry' => [
                'id' => $emerging->id,
                'reference' => $emerging->reference,
                'title' => $emerging->title,
                'createdAt' => $emerging->created_at?->format('d M Y'),
                'createdBy' => $emerging->creator?->name,
                'radarScore' => $emerging->radar_score,
                'lastReviewedAt' => $emerging->last_reviewed_at?->format('d M Y'),
                'reviewUrl' => route('risk.emerging.review', $emerging),
            ],
            'schema' => $this->schemas->form(self::OBJECT_TYPE, $emerging),
            'canDelete' => auth()->user()->can('delete', $emerging),
        ]);
    }

    public function update(UpdateEmergingRiskRequest $request, EmergingRisk $emerging)
    {
        DB::transaction(function () use ($request, $emerging) {
            $emerging->update($request->columns());

            $this->saveConfiguredAttributes($request, $emerging);
        });

        return redirect()->route('risk.emerging.index')
            ->with('success', "Emerging risk {$emerging->reference} updated.");
    }

    public function destroy(EmergingRisk $emerging)
    {
        Gate::authorize('delete', $emerging);

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
        Gate::authorize('review', $emerging);

        $emerging->update(['last_reviewed_at' => now()->toDateString()]);

        return back()->with('success', "{$emerging->reference} marked as reviewed today.");
    }
}
