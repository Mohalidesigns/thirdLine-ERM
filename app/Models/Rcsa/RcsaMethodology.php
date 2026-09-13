<?php

namespace App\Models\Rcsa;

use App\Models\Organization;
use App\Models\User;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * What an RCSA score MEANS, as data — the workbook's scoring engine.
 *
 * Everything that computes or renders an RCSA figure resolves one of these
 * first, and resolution never returns null: the seeded SB methodology carries
 * organization_id NULL and therefore belongs to every tenant, the same
 * convention ScoringProfile, ObjectType and WidgetDefinition use, with
 * $tenantIncludesGlobal letting the tenancy scope pass those rows through.
 *
 * A CYCLE PINS A METHODOLOGY AND THAT PIN IS LOAD-BEARING. When the bank
 * re-cuts its bands in 2027, the closed 2026 assessments must still render, and
 * still reconcile, under the scales they were assessed with. So a methodology
 * with assessments behind it is never edited: it is retired and a new version
 * activated. `is_locked` is the enforcement point, set the first time a line is
 * scored under it.
 *
 * @property-read Collection<int, RcsaScaleItem> $scaleItems
 * @property-read Collection<int, RcsaImpactCriterion> $impactCriteria
 * @property-read Collection<int, RcsaRiskBand> $bands
 */
class RcsaMethodology extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** The system methodology carries a NULL organization_id and belongs to everyone. */
    protected bool $tenantIncludesGlobal = true;

    protected $table = 'rcsa_methodologies';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'version',
        'description',
        'status',
        'effective_from',
        'effective_to',
        'residual_mode',
        'residual_floor',
        'appetite_ceiling_level',
        'is_system',
        'is_locked',
        'locked_at',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'residual_floor' => 'float',
        'is_system' => 'boolean',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<RcsaScaleItem, $this> */
    public function scaleItems(): HasMany
    {
        return $this->hasMany(RcsaScaleItem::class, 'methodology_id')->orderBy('sort_order');
    }

    /** @return HasMany<RcsaImpactCriterion, $this> */
    public function impactCriteria(): HasMany
    {
        return $this->hasMany(RcsaImpactCriterion::class, 'methodology_id')
            ->orderBy('impact_value')
            ->orderBy('sort_order');
    }

    /**
     * The bands, ALWAYS ascending by score.
     *
     * The order is part of the contract, not a presentation choice: the
     * calculation service walks them in order to band a score and to rank one
     * level against the appetite ceiling. A band list returned in insertion
     * order would still work today and would break the first time a tenant
     * inserted a band between two existing ones.
     *
     * @return HasMany<RcsaRiskBand, $this>
     */
    public function bands(): HasMany
    {
        return $this->hasMany(RcsaRiskBand::class, 'methodology_id')->orderBy('min_score');
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    /**
     * The methodology a new cycle should be scored against.
     *
     * Prefers the tenant's own active methodology; falls back to the system one
     * seeded from the client workbook. Never returns null on an installation
     * whose migrations have run — and an installation whose migrations have NOT
     * run has no tables to query, so there is nothing to guard against here
     * that a missing-table error does not say more clearly.
     */
    public static function active(?int $organizationId = null): ?self
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        return static::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'))
            // A tenant's own methodology outranks the system one. Ordering on
            // the nullability of organization_id rather than on is_system,
            // because is_system is a label and the NULL is the actual rule.
            ->orderByRaw('organization_id is null')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Scales */
    /* ------------------------------------------------------------------ */

    /**
     * The scale items of one type, keyed by their `value`.
     *
     * Reads the loaded relation when it is loaded rather than querying, so that
     * scoring a 500-line grid is one query for the methodology and not 1,500
     * for its scales.
     *
     * @return array<int, RcsaScaleItem>
     */
    public function scale(string $type): array
    {
        return $this->scaleItems
            ->where('type', $type)
            ->sortBy('sort_order')
            ->keyBy('value')
            ->all();
    }

    /**
     * The control-effectiveness rating matching $label, case- and
     * whitespace-insensitively.
     *
     * Lenient on the way IN because the label arrives from an uploaded
     * spreadsheet as often as from a select, and "fully achieved" typed in
     * lower case is not a different rating. Strict on the way OUT: the stored
     * label is always the canonical one.
     */
    public function controlEffectiveness(?string $label): ?RcsaScaleItem
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        $needle = Str::lower(preg_replace('/\s+/', ' ', trim($label)) ?? '');

        foreach ($this->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS) as $item) {
            if (Str::lower($item->label) === $needle) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The band containing $score.
     *
     * CLAMPS RATHER THAN RETURNING NULL. A score above the top band's maximum
     * or below the bottom band's minimum bands at that end instead of rendering
     * as a blank rating on every screen — the same decision RiskScoringService
     * documents for out-of-range inputs, and for the same reason: a
     * methodology whose bands do not span the whole range is a configuration
     * error to surface in the admin screen, not a reason for an assessment to
     * show nothing.
     */
    public function bandFor(float $score): ?RcsaRiskBand
    {
        $bands = $this->bands->sortBy('min_score')->values();

        if ($bands->isEmpty()) {
            return null;
        }

        foreach ($bands as $band) {
            if ($band->contains($score)) {
                return $band;
            }
        }

        return $score < (float) $bands->first()->min_score
            ? $bands->first()
            : $bands->last();
    }

    /**
     * How high a band sits, 0-based from the bottom. Used to compare a residual
     * level against the appetite ceiling.
     */
    public function bandRank(?string $level): ?int
    {
        if ($level === null) {
            return null;
        }

        foreach ($this->bands->sortBy('min_score')->values() as $index => $band) {
            if ($band->level === $level) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether a residual band sits above the methodology's appetite ceiling.
     *
     * THE CEILING IS AUTHORITATIVE, NOT THE BAND'S SENTENCE. Each band carries
     * an appetite statement for column T, and `appetite_ceiling_level` says
     * which band is the highest still inside appetite. They agree on the seeded
     * methodology — RcsaMethodologySeedTest pins that — but they are two
     * editable fields, and if a tenant raises the ceiling without rewriting
     * five sentences, the OBLIGATION must follow the ceiling they configured.
     * The sentence is what the user reads; the ceiling is what the submission
     * gate enforces.
     */
    public function isAboveAppetite(?string $residualLevel): bool
    {
        $rank = $this->bandRank($residualLevel);
        $ceiling = $this->bandRank($this->appetite_ceiling_level);

        if ($rank === null || $ceiling === null) {
            return false;
        }

        return $rank > $ceiling;
    }

    /* ------------------------------------------------------------------ */
    /*  Client payload */
    /* ------------------------------------------------------------------ */

    /**
     * The methodology as resources/js/lib/rcsa-calc.js expects it.
     *
     * The client mirror needs the bands, the modifiers and the two appetite
     * settings, and it must receive them rather than hold its own copy — a
     * hardcoded band table in JavaScript is precisely the drift this module is
     * built to avoid. camelCase, because it crosses into JavaScript.
     *
     * @return array<string, mixed>
     */
    public function toCalculatorPayload(): array
    {
        $scaleRows = fn (string $type) => array_values(array_map(
            fn (RcsaScaleItem $item) => [
                'value' => $item->value,
                'label' => $item->label,
                'description' => $item->description,
                'percentBand' => $item->percent_band,
            ],
            $this->scale($type)
        ));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'residualMode' => $this->residual_mode,
            'residualFloor' => (float) $this->residual_floor,
            'appetiteCeilingLevel' => $this->appetite_ceiling_level,
            'likelihood' => $scaleRows(RcsaScaleItem::TYPE_LIKELIHOOD),
            'impact' => $scaleRows(RcsaScaleItem::TYPE_IMPACT),
            'controlEffectiveness' => array_values(array_map(
                fn (RcsaScaleItem $item) => [
                    'value' => $item->value,
                    'label' => $item->label,
                    'modifier' => $item->modifier,
                    'percentBand' => $item->percent_band,
                    'description' => $item->description,
                ],
                $this->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS)
            )),
            'bands' => $this->bands->sortBy('min_score')->values()->map(fn (RcsaRiskBand $band) => [
                'level' => $band->level,
                'label' => $band->label,
                'minScore' => (float) $band->min_score,
                'maxScore' => (float) $band->max_score,
                'colour' => $band->colour,
                'treatment' => $band->treatment,
                'appetiteStatus' => $band->appetite_status,
            ])->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Guards */
    /* ------------------------------------------------------------------ */

    /** Whether the scales, bands and criteria under this row may still be edited. */
    public function isEditable(): bool
    {
        return ! $this->is_locked && $this->status !== 'retired';
    }

    public function isCalculatedResidual(): bool
    {
        return $this->residual_mode === Template::RESIDUAL_CALCULATED;
    }

    public function isAssessedResidual(): bool
    {
        return $this->residual_mode === Template::RESIDUAL_ASSESSED;
    }
}
