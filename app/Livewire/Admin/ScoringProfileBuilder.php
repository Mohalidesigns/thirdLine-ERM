<?php

namespace App\Livewire\Admin;

use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Support\Scoring\ScoringProfileTemplates;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

/**
 * WP-05 TASK 3 — the screen where an organisation decides what a score means.
 *
 * Matrix size, the label and definition of every point on both axes, the
 * naira band each impact level stands for, which dimensions are scored and how
 * they collapse, the rating bands, and the residual formula.
 *
 * TWO THINGS THIS SCREEN DOES THAT A PLAIN CRUD FORM WOULD NOT.
 *
 *   It previews the re-rating before saving. Changing a band boundary re-rates
 *   every risk in the register; the operator is shown how many risks move and
 *   in which direction while the change is still abandonable.
 *
 *   It refuses to save bands with a gap. A score that falls into no band
 *   renders as an empty rating, which on a dashboard is indistinguishable from
 *   "not assessed" — a silent failure with a governance consequence.
 */
class ScoringProfileBuilder extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public ?int $deletingId = null;

    /* Form state ------------------------------------------------------- */

    public string $name = '';

    public string $code = '';

    public string $description = '';

    public int $matrix_rows = 5;

    public int $matrix_cols = 5;

    public string $impact_aggregation = 'max';

    public string $residual_formula = '';

    public bool $is_default = false;

    public ?string $effective_from = null;

    /** @var list<array<string, mixed>> */
    public array $likelihood_scale = [];

    /** @var list<array<string, mixed>> */
    public array $impact_scale = [];

    /** @var list<array<string, mixed>> */
    public array $rating_bands = [];

    /** @var list<string> */
    public array $impact_dimensions = [];

    /** @var array<string, float> */
    public array $dimension_weights = [];

    /* applies_to */
    /** @var list<int> */
    public array $applies_to_object_type_ids = [];

    public string $applies_to_risk_types = '';

    public function render()
    {
        return view('livewire.admin.scoring-profile-builder', [
            'profiles' => ScoringProfile::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'typeOptions' => ObjectType::orderBy('name')->get(['id', 'name']),
            'currency' => $this->currency(),
            'rerating' => $this->showForm ? $this->reratingPreview() : null,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Form */
    /* ------------------------------------------------------------------ */

    public function create(): void
    {
        $this->resetForm();

        $template = ScoringProfileTemplates::default($this->currency());

        $this->likelihood_scale = $template['likelihood_scale'];
        $this->impact_scale = $template['impact_scale'];
        $this->rating_bands = $template['rating_bands'];
        $this->impact_dimensions = $template['impact_dimensions'];
        $this->dimension_weights = $template['dimension_weights'];
        $this->residual_formula = $template['residual_formula'];

        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $profile = ScoringProfile::findOrFail($id);

        $this->editingId = $profile->id;
        $this->name = $profile->name;
        $this->code = $profile->code;
        $this->description = (string) $profile->description;
        $this->matrix_rows = $profile->matrix_rows;
        $this->matrix_cols = $profile->matrix_cols;
        $this->impact_aggregation = $profile->impact_aggregation;
        $this->residual_formula = (string) $profile->residual_formula;
        $this->is_default = (bool) $profile->is_default;
        $this->effective_from = $profile->effective_from?->toDateString();
        $this->likelihood_scale = $profile->likelihood_scale ?? [];
        $this->impact_scale = $profile->impact_scale ?? [];
        $this->rating_bands = $profile->rating_bands ?? [];
        $this->impact_dimensions = $profile->dimensions();
        $this->dimension_weights = $profile->weights();

        $applies = $profile->applies_to ?? [];
        $this->applies_to_object_type_ids = array_map('intval', $applies['object_type_ids'] ?? []);
        $this->applies_to_risk_types = implode(', ', $applies['risk_types'] ?? []);

        $this->showForm = true;
    }

    /**
     * Resizing rebuilds the scales and the bands, keeping whatever the tenant
     * has already written against a level that still exists.
     */
    public function updatedMatrixRows(): void
    {
        $this->matrix_rows = max(3, min(10, (int) $this->matrix_rows));
        $this->likelihood_scale = $this->mergeScale(
            $this->likelihood_scale,
            ScoringProfileTemplates::likelihoodScale($this->matrix_rows)
        );
        $this->rating_bands = ScoringProfileTemplates::ratingBandsFor($this->matrix_rows, $this->matrix_cols);
    }

    public function updatedMatrixCols(): void
    {
        $this->matrix_cols = max(3, min(10, (int) $this->matrix_cols));
        $this->impact_scale = $this->mergeScale(
            $this->impact_scale,
            ScoringProfileTemplates::impactScale($this->matrix_cols, $this->currency())
        );
        $this->rating_bands = ScoringProfileTemplates::ratingBandsFor($this->matrix_rows, $this->matrix_cols);
    }

    public function addBand(): void
    {
        $last = end($this->rating_bands) ?: ['max' => 0];
        $min = (int) ($last['max'] ?? 0) + 1;

        $this->rating_bands[] = [
            'code' => 'band_'.(count($this->rating_bands) + 1),
            'label' => 'New band',
            'color' => '#64748b',
            'min' => $min,
            'max' => max($min, $this->matrix_rows * $this->matrix_cols),
        ];
    }

    public function removeBand(int $index): void
    {
        unset($this->rating_bands[$index]);
        $this->rating_bands = array_values($this->rating_bands);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => 'required|string|max:160',
            'code' => 'required|string|max:64|regex:/^[a-z0-9][a-z0-9_\-]*$/',
            'description' => 'nullable|string|max:2000',
            'matrix_rows' => 'required|integer|min:3|max:10',
            'matrix_cols' => 'required|integer|min:3|max:10',
            'impact_aggregation' => 'required|in:max,weighted,average,worst_two',
            'residual_formula' => 'nullable|string|max:500',
            'effective_from' => 'nullable|date',
            'rating_bands' => 'required|array|min:1',
            'rating_bands.*.code' => 'required|string|max:32',
            'rating_bands.*.label' => 'required|string|max:60',
            'rating_bands.*.min' => 'required|integer|min:0',
            'rating_bands.*.max' => 'required|integer|min:0',
            'impact_dimensions' => 'required|array|min:1',
        ]);

        if (! $this->assertBandsCoverTheRange()) {
            return;
        }

        $profile = $this->editingId === null
            ? new ScoringProfile
            : ScoringProfile::findOrFail($this->editingId);

        if ($profile->exists && $profile->is_system) {
            // The seeded 5×5 is the parity baseline every existing tenant
            // resolves. Editing it in place would move scores for everybody,
            // so an edit forks it into this tenant's own profile instead.
            $profile = new ScoringProfile;
            $this->editingId = null;
        }

        $riskTypes = array_values(array_filter(array_map('trim', explode(',', $this->applies_to_risk_types))));

        $appliesTo = array_filter([
            'object_type_ids' => $this->applies_to_object_type_ids ?: null,
            'risk_types' => $riskTypes ?: null,
        ]);

        $profile->fill([
            'organization_id' => $profile->exists ? $profile->organization_id : TenantContext::organizationId(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $this->description ?: null,
            'applies_to' => $appliesTo ?: null,
            'likelihood_scale' => array_values($this->likelihood_scale),
            'impact_scale' => array_values($this->impact_scale),
            'impact_dimensions' => array_values($this->impact_dimensions),
            'impact_aggregation' => $validated['impact_aggregation'],
            'dimension_weights' => $this->dimension_weights ?: null,
            'rating_bands' => $this->normalisedBands(),
            'residual_formula' => $this->residual_formula ?: null,
            'matrix_rows' => $validated['matrix_rows'],
            'matrix_cols' => $validated['matrix_cols'],
            'is_default' => $this->is_default,
            'is_system' => false,
            'effective_from' => $this->effective_from ?: null,
            // Redefining what Critical means is a governance act, so the
            // profile records who signed it off rather than only who typed it.
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ])->save();

        if ($this->is_default) {
            // Exactly one default per tenant, or resolution picks arbitrarily.
            ScoringProfile::withoutGlobalScopes()
                ->where('organization_id', $profile->organization_id)
                ->whereKeyNot($profile->id)
                ->update(['is_default' => false]);
        }

        ScoringProfile::flushResolutionCache();

        $this->showForm = false;
        $this->resetForm();
        session()->flash('builder-status', "Saved scoring profile “{$validated['name']}”. "
            .'Risks are re-rated against its bands from now on.');
    }

    public function delete(int $id): void
    {
        $profile = ScoringProfile::findOrFail($id);

        if ($profile->is_system) {
            $this->addError('profile', 'The seeded system profile cannot be deleted — it is what an '
                .'organisation with no profile of its own scores against.');

            return;
        }

        $name = $profile->name;
        $profile->delete();

        ScoringProfile::flushResolutionCache();
        session()->flash('builder-status', "Deleted “{$name}”.");
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Bands must be contiguous from 1 to the maximum attainable score. A gap
     * renders as a blank rating, which reads as "not assessed"; an overlap
     * means the first match wins and the second band is dead configuration.
     */
    private function assertBandsCoverTheRange(): bool
    {
        $bands = $this->normalisedBands();
        $maxScore = $this->matrix_rows * $this->matrix_cols;
        $ok = true;

        if (($bands[0]['min'] ?? 0) !== 1) {
            $this->addError('rating_bands', 'The lowest band must start at 1.');
            $ok = false;
        }

        if (($bands[count($bands) - 1]['max'] ?? 0) < $maxScore) {
            $this->addError('rating_bands', "The highest band must reach {$maxScore}, the top score a "
                ."{$this->matrix_rows}×{$this->matrix_cols} matrix can produce.");
            $ok = false;
        }

        foreach ($bands as $index => $band) {
            if ($band['min'] > $band['max']) {
                $this->addError('rating_bands', "“{$band['label']}” starts above where it ends.");
                $ok = false;
            }

            if ($index > 0 && $band['min'] !== $bands[$index - 1]['max'] + 1) {
                $this->addError('rating_bands', "There is a gap or an overlap between “{$bands[$index - 1]['label']}” "
                    ."and “{$band['label']}”. Every score from 1 to {$maxScore} must fall into exactly one band.");
                $ok = false;
            }
        }

        return $ok;
    }

    /** @return list<array<string, mixed>> */
    private function normalisedBands(): array
    {
        $bands = array_map(fn (array $band) => [
            'code' => $band['code'],
            'label' => $band['label'],
            'color' => $band['color'] ?? '#64748b',
            'min' => (int) $band['min'],
            'max' => (int) $band['max'],
        ], array_values($this->rating_bands));

        usort($bands, fn ($a, $b) => $a['min'] <=> $b['min']);

        return $bands;
    }

    /**
     * How many risks move band if this profile is saved as it stands.
     *
     * @return array{moved:int,total:int,examples:list<string>}|null
     */
    private function reratingPreview(): ?array
    {
        if ($this->rating_bands === []) {
            return null;
        }

        $bands = $this->normalisedBands();
        $bandFor = function (int $score) use ($bands) {
            foreach ($bands as $band) {
                if ($score >= $band['min'] && $score <= $band['max']) {
                    return $band['label'];
                }
            }

            return null;
        };

        $risks = \App\Models\Risk::query()
            ->where('organization_id', TenantContext::organizationId())
            ->where('status', 'active')
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->get(['id', 'risk_code', 'inherent_likelihood', 'inherent_impact', 'inherent_rating']);

        $moved = [];

        foreach ($risks as $risk) {
            $score = min((int) $risk->inherent_likelihood, $this->matrix_rows)
                * min((int) $risk->inherent_impact, $this->matrix_cols);

            $new = $bandFor($score);

            if ($new !== null && $new !== $risk->inherent_rating) {
                $moved[] = "{$risk->risk_code}: {$risk->inherent_rating} → {$new}";
            }
        }

        return [
            'moved' => count($moved),
            'total' => $risks->count(),
            'examples' => array_slice($moved, 0, 5),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $template
     * @return list<array<string, mixed>>
     */
    private function mergeScale(array $existing, array $template): array
    {
        $byValue = collect($existing)->keyBy(fn ($level) => (int) ($level['value'] ?? 0));

        return array_map(function (array $level) use ($byValue) {
            $previous = $byValue->get((int) $level['value']);

            return $previous === null ? $level : array_merge($level, array_filter(
                $previous,
                fn ($value, $key) => $key !== 'value' && $value !== null && $value !== '',
                ARRAY_FILTER_USE_BOTH
            ));
        }, $template);
    }

    private function currency(): string
    {
        $organization = Organization::find(TenantContext::organizationId());

        return (string) (($organization?->settings ?? [])['reporting_currency'] ?? 'NGN');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'code', 'description', 'residual_formula', 'is_default',
            'effective_from', 'likelihood_scale', 'impact_scale', 'rating_bands',
            'impact_dimensions', 'dimension_weights', 'applies_to_object_type_ids',
            'applies_to_risk_types',
        ]);

        $this->matrix_rows = 5;
        $this->matrix_cols = 5;
        $this->impact_aggregation = 'max';
        $this->resetErrorBag();
    }
}
