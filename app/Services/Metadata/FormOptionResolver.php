<?php

namespace App\Services\Metadata;

use App\Models\ObjectAttribute;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * WP-05 TASK 2 — turns a field definition into the options a select renders.
 *
 * An allowlist, not a query builder. `options_source` names one of a fixed set
 * of lookups; anything else returns nothing. The alternative — letting a field
 * definition carry a table and a column — would mean a configuration row could
 * name `users`, `password` and have the form render it, which is a metadata
 * system that also happens to be an arbitrary read primitive.
 *
 * EVERY LOOKUP IS TENANT-SCOPED at the query, not by convention. These feed a
 * select whose selected value is then validated by the controller with an
 * `exists` rule; several of those rules are not themselves tenant-scoped, so
 * an unscoped option list here would be a genuine cross-tenant leak rather
 * than a cosmetic one.
 */
class FormOptionResolver
{
    /**
     * @return array<int|string, string> value => label
     */
    public function optionsFor(ObjectAttribute $attribute): array
    {
        $source = data_get($attribute->validation, 'options_source');

        if ($source === null) {
            return $this->enumOptions($attribute);
        }

        $organizationId = TenantContext::organizationId();

        return match ($source) {
            'users' => $this->lookup('users', 'name', $organizationId, ['is_active' => true]),
            'business_units' => $this->lookup('business_units', 'name', $organizationId),
            'risks' => $this->risks($organizationId),
            'risk_categories' => $this->lookup('risk_categories', 'name', $organizationId),
            'controls' => $this->lookup('controls', 'name', $organizationId),
            'scoring_scale' => $this->scoringScale($attribute),
            default => [],
        };
    }

    /**
     * Whether this field renders as a select at all.
     */
    public function isChoice(ObjectAttribute $attribute): bool
    {
        return in_array($attribute->data_type, ['enum', 'multi_enum'], true)
            || data_get($attribute->validation, 'options_source') !== null;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Enum options, using the human labels stored beside them where the field
     * definition supplied any.
     *
     * @return array<string, string>
     */
    private function enumOptions(ObjectAttribute $attribute): array
    {
        $labels = (array) data_get($attribute->validation, 'option_labels', []);
        $options = [];

        foreach ($attribute->enum_options ?? [] as $option) {
            $options[$option] = $labels[$option] ?? ucfirst(str_replace('_', ' ', (string) $option));
        }

        return $options;
    }

    /**
     * The points on the organisation's likelihood or impact axis.
     *
     * Read from the scoring profile rather than the hardcoded five, so a
     * tenant on a 4×4 matrix is offered four options on a treatment plan's
     * expected-residual fields and cannot enter a 5 the matrix cannot hold.
     *
     * @return array<int, string>
     */
    private function scoringScale(ObjectAttribute $attribute): array
    {
        $axis = (string) data_get($attribute->validation, 'axis', 'impact');
        $profile = app(RiskScoringService::class)->profileFor();

        $options = [];

        foreach ($profile->axisLabels($axis) as $value => $label) {
            $options[$value] = "{$value} — {$label}";
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function risks(?int $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        return DB::table('risks')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('risk_code')
            ->limit(500)
            ->get(['id', 'risk_code', 'title'])
            ->mapWithKeys(fn ($risk) => [$risk->id => trim("{$risk->risk_code} — {$risk->title}", ' —')])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array<int, string>
     */
    private function lookup(string $table, string $labelColumn, ?int $organizationId, array $where = []): array
    {
        if ($organizationId === null || ! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return [];
        }

        $query = DB::table($table)->where('organization_id', $organizationId);

        foreach ($where as $column => $value) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                $query->where($column, $value);
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->orderBy($labelColumn)
            ->limit(500)
            ->pluck($labelColumn, 'id')
            ->all();
    }
}
