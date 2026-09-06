<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * WP-08 TASK 1 — one widget primitive, N contexts.
 *
 * A widget definition is a QUESTION, not an answer: "open risks by rating
 * band, within ⟨wherever I am placed⟩, as at ⟨whatever period is selected⟩".
 * The two brackets are context_binding and period_binding, resolved at render
 * time by WidgetContextResolver from the page's node and the global period
 * selector. The same row placed on the Group HQ page and on a business-unit
 * page returns two different, both correct, results.
 *
 * organization_id NULL = system widget, visible to every tenant — the same
 * convention as object_types and scoring_profiles, hence
 * $tenantIncludesGlobal. A tenant "editing" a system widget clones it into
 * its own row; system rows are never mutated in place.
 */
class WidgetDefinition extends Model
{
    use BelongsToOrganization;

    /** System widgets (organization_id NULL) are visible to every tenant. */
    protected $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'widget_type',
        'object_type_id',
        'query',
        'measure_id',
        'period_binding',
        'period_config',
        'context_binding',
        'visualisation',
        'drilldown',
        'min_w',
        'min_h',
        'is_system',
    ];

    protected $casts = [
        'query' => 'array',
        'period_config' => 'array',
        'visualisation' => 'array',
        'drilldown' => 'array',
        'min_w' => 'integer',
        'min_h' => 'integer',
        'is_system' => 'boolean',
    ];

    public function objectType()
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    public function measure()
    {
        return $this->belongsTo(Measure::class, 'measure_id');
    }

    public function scopeOfType(Builder $query, string $widgetType): Builder
    {
        return $query->where('widget_type', $widgetType);
    }

    /** A query-JSON key with a default, so resolvers never reach into raw JSON. */
    public function queryConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->query, $key, $default);
    }

    public function visualisationConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->visualisation, $key, $default);
    }

    public function periodConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->period_config, $key, $default);
    }
}
