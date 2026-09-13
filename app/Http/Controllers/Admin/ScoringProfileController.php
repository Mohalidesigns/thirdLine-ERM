<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Scoring\PreviewBandsRequest;
use App\Http\Requests\Admin\Scoring\ValidateFormulaRequest;
use App\Http\Requests\Admin\StoreScoringProfileRequest;
use App\Http\Requests\Admin\UpdateScoringProfileRequest;
use App\Models\ObjectType;
use App\Models\Risk;
use App\Models\ScoringProfile;
use App\Services\CurrencyService;
use App\Services\FormulaEvaluator;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Scoring profiles (migration Phase 6.4, from Livewire ScoringProfileBuilder).
 *
 * TWO THINGS THIS SCREEN DOES THAT A PLAIN CRUD FORM WOULD NOT.
 *
 *   It previews the re-rating before saving. Changing a band boundary re-rates
 *   every risk in the register; the operator is shown how many risks move and
 *   in which direction while the change is still abandonable. The Livewire
 *   component recomputed this on every render; here it is an endpoint the page
 *   asks, which is the same guarantee and one query instead of a query per
 *   keystroke.
 *
 *   It refuses to save bands with a gap. A score that falls into no band
 *   renders as an empty rating, which on a dashboard is indistinguishable from
 *   "not assessed" — a silent failure with a governance consequence. That check
 *   lives in StoreScoringProfileRequest now, alongside the formula check it
 *   never had.
 */
class ScoringProfileController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', ScoringProfile::class);

        $currency = app(CurrencyService::class)->reportingCurrency();

        return Inertia::render('Admin/ScoringProfiles/Index', [
            'profiles' => ScoringProfile::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (ScoringProfile $profile) => $this->present($profile))
                ->values()
                ->all(),
            'options' => $this->formOptions($currency)['options'],
        ]);
    }

    /**
     * The lists both the index and the editor need.
     *
     * The template is what "New profile" starts from, built by the same
     * ScoringProfileTemplates the provisioner uses, so a hand-made profile and
     * a provisioned one begin identical.
     *
     * @return array<string, mixed>
     */
    private function formOptions(string $currency): array
    {
        return [
            'options' => [
                'types' => ObjectType::query()->orderBy('name')->get(['id', 'name'])->values(),
                'aggregations' => ['max', 'weighted', 'average', 'worst_two'],
                'dimensions' => ScoringProfileTemplates::DEFAULT_IMPACT_DIMENSIONS,
                'currency' => $currency,
                'defaultFormula' => ScoringProfileTemplates::DEFAULT_RESIDUAL_FORMULA,
            ],
        ];
    }

    /**
     * The editor. A profile is thirty-odd fields across two axes, five
     * dimensions and a set of bands — too much for a dialog over the list, so
     * it gets its own page, and the list stays readable.
     */
    public function create()
    {
        Gate::authorize('create', ScoringProfile::class);

        $currency = app(CurrencyService::class)->reportingCurrency();

        return Inertia::render('Admin/ScoringProfiles/Edit', array_merge($this->formOptions($currency), [
            'profile' => null,
            'template' => ScoringProfileTemplates::default($currency),
        ]));
    }

    public function edit(ScoringProfile $scoringProfile)
    {
        Gate::authorize('update', $scoringProfile);

        $currency = app(CurrencyService::class)->reportingCurrency();

        return Inertia::render('Admin/ScoringProfiles/Edit', array_merge($this->formOptions($currency), [
            'profile' => $this->present($scoringProfile),
            'template' => ScoringProfileTemplates::default($currency),
        ]));
    }

    public function store(StoreScoringProfileRequest $request)
    {
        $profile = new ScoringProfile;

        $profile->fill(array_merge($request->payload(), [
            'organization_id' => TenantContext::organizationId(),
        ]))->save();

        $this->settleDefault($profile);

        return redirect()->route('admin.builder.scoring-profiles')->with('success', "Saved scoring profile “{$profile->name}”. "
            .'Risks are re-rated against its bands from now on.');
    }

    public function update(UpdateScoringProfileRequest $request, ScoringProfile $scoringProfile)
    {
        if ($scoringProfile->is_system) {
            // The seeded 5×5 is the parity baseline every tenant without a
            // profile of their own resolves. Editing it in place would move
            // scores for everybody, so an edit forks it instead.
            $scoringProfile = new ScoringProfile;
        }

        $scoringProfile->fill(array_merge($request->payload(), [
            'organization_id' => $scoringProfile->exists
                ? $scoringProfile->organization_id
                : TenantContext::organizationId(),
        ]))->save();

        $this->settleDefault($scoringProfile);

        return redirect()->route('admin.builder.scoring-profiles')->with('success', "Saved scoring profile “{$scoringProfile->name}”. "
            .'Risks are re-rated against its bands from now on.');
    }

    public function destroy(ScoringProfile $scoringProfile)
    {
        Gate::authorize('delete', $scoringProfile);

        // Not a policy rule, deliberately: Gate::before answers every ability
        // true for a super-admin, so "nobody may ever do this" cannot be
        // expressed as an ability. It is a data invariant, the same shape as
        // MetadataGuard::assertTypeDeletable(), and it holds whoever asks.
        if ($scoringProfile->is_system) {
            throw ValidationException::withMessages([
                'profile' => 'The seeded system profile cannot be deleted — it is what an organisation '
                    .'with no profile of its own scores against.',
            ]);
        }

        $name = $scoringProfile->name;
        $scoringProfile->delete();

        ScoringProfile::flushResolutionCache();

        return back()->with('success', "Deleted “{$name}”.");
    }

    /**
     * Whether a residual formula can be evaluated, asked while the operator is
     * still typing it.
     */
    public function validateFormula(ValidateFormulaRequest $request)
    {
        $validated = $request->validated();

        $maxScore = (int) ($validated['matrix_rows'] ?? 5) * (int) ($validated['matrix_cols'] ?? 5);

        try {
            $result = app(FormulaEvaluator::class)->evaluate(
                $validated['formula'],
                ['organization_id' => TenantContext::organizationId()],
                ['inherent' => 10, 'effectiveness' => 50.0, 'max_score' => $maxScore],
            );
        } catch (\Throwable $error) {
            return response()->json(['valid' => false, 'message' => $error->getMessage()]);
        }

        // A worked example rather than a tick: "valid" and "does what you meant"
        // are different claims, and only one of them is checkable here.
        return response()->json([
            'valid' => true,
            'message' => "With an inherent score of 10 and controls 50% effective, this gives {$result}.",
        ]);
    }

    /**
     * How many risks move band if these bands are saved as they stand.
     */
    public function preview(PreviewBandsRequest $request)
    {
        $validated = $request->validated();

        $bands = collect($validated['rating_bands'])->sortBy('min')->values()->all();

        $bandFor = function (int $score) use ($bands): ?string {
            foreach ($bands as $band) {
                if ($score >= $band['min'] && $score <= $band['max']) {
                    return $band['label'];
                }
            }

            return null;
        };

        $risks = Risk::query()
            ->where('organization_id', TenantContext::organizationId())
            ->where('status', 'active')
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->get(['id', 'risk_code', 'inherent_likelihood', 'inherent_impact', 'inherent_rating']);

        $moved = [];

        foreach ($risks as $risk) {
            $score = min((int) $risk->inherent_likelihood, (int) $validated['matrix_rows'])
                * min((int) $risk->inherent_impact, (int) $validated['matrix_cols']);

            $new = $bandFor($score);

            if ($new !== null && $new !== $risk->inherent_rating) {
                $moved[] = "{$risk->risk_code}: {$risk->inherent_rating} → {$new}";
            }
        }

        return response()->json([
            'moved' => count($moved),
            'total' => $risks->count(),
            'examples' => array_slice($moved, 0, 5),
        ]);
    }

    /**
     * Exactly one default per tenant, or resolution picks arbitrarily.
     */
    private function settleDefault(ScoringProfile $profile): void
    {
        if ($profile->is_default) {
            ScoringProfile::withoutGlobalScopes()
                ->where('organization_id', $profile->organization_id)
                ->whereKeyNot($profile->id)
                ->update(['is_default' => false]);
        }

        ScoringProfile::flushResolutionCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ScoringProfile $profile): array
    {
        $applies = $profile->applies_to ?? [];

        return array_merge($profile->only([
            'id', 'code', 'name', 'description', 'matrix_rows', 'matrix_cols',
            'impact_aggregation', 'residual_formula', 'is_default', 'is_system',
        ]), [
            'effective_from' => $profile->effective_from?->toDateString(),
            'likelihood_scale' => array_values($profile->likelihood_scale ?? []),
            'impact_scale' => array_values($profile->impact_scale ?? []),
            'rating_bands' => array_values($profile->rating_bands ?? []),
            'impact_dimensions' => $profile->dimensions(),
            'dimension_weights' => $profile->weights(),
            'applies_to_object_type_ids' => array_map('intval', $applies['object_type_ids'] ?? []),
            'applies_to_risk_types' => array_values($applies['risk_types'] ?? []),
            'max_score' => $profile->maxScore(),
            'can_delete' => Gate::allows('delete', $profile),
        ]);
    }
}
