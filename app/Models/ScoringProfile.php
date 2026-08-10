<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Scoring\ScoringProfileTemplates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WP-05 TASK 3 — what a score means, as data.
 *
 * Everything that computes or renders a risk score resolves one of these
 * first. There is no longer a fallback path that "just uses 5×5": a profile is
 * always found, because the seeded system profile has organization_id NULL and
 * therefore belongs to every tenant.
 *
 * organization_id NULL = system profile, the same convention object_types and
 * object_lifecycles use, with $tenantIncludesGlobal letting the tenancy scope
 * pass those rows through.
 */
class ScoringProfile extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'scoring_profiles';

    /** System profiles carry a NULL organization_id and belong to everyone. */
    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'applies_to',
        'likelihood_scale',
        'impact_scale',
        'impact_dimensions',
        'impact_aggregation',
        'dimension_weights',
        'rating_bands',
        'residual_formula',
        'matrix_rows',
        'matrix_cols',
        'is_default',
        'is_system',
        'effective_from',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'applies_to' => 'array',
        'likelihood_scale' => 'array',
        'impact_scale' => 'array',
        'impact_dimensions' => 'array',
        'dimension_weights' => 'array',
        'rating_bands' => 'array',
        'matrix_rows' => 'integer',
        'matrix_cols' => 'integer',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'effective_from' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Resolution is memoised per request: a register listing asks for the
     * profile once per row, and without this a 500-risk page issues 500
     * identical queries.
     *
     * @var array<string, self|null>
     */
    private static array $resolutionCache = [];

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    /**
     * The profile that governs a score in this context.
     *
     * Specificity wins, in this order: risk type, then object type, then node.
     * A profile that narrows on nothing is the organisation's general profile
     * and loses to any profile that narrows on something. Ties break toward
     * the tenant's own profile over the system one, then the most recently
     * effective, then is_default.
     *
     * Profiles whose effective_from is in the future are not yet in force —
     * that is the whole point of staging next year's matrix — and are excluded.
     */
    public static function resolveFor(
        ?int $organizationId = null,
        ?int $nodeId = null,
        ?int $objectTypeId = null,
        ?string $riskType = null,
    ): ?self {
        $organizationId ??= TenantContext::organizationId();

        $key = implode('|', [$organizationId ?? '-', $nodeId ?? '-', $objectTypeId ?? '-', $riskType ?? '-']);

        if (array_key_exists($key, self::$resolutionCache)) {
            return self::$resolutionCache[$key];
        }

        $candidates = static::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(function ($query) use ($organizationId) {
                $query->whereNull('organization_id');

                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()))
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $profile) {
            $score = $profile->specificityFor($nodeId, $objectTypeId, $riskType);

            if ($score < 0) {
                continue;
            }

            // Tenant's own beats the system's; later effective_from beats
            // earlier; a default beats a non-default.
            $score = $score * 1000
                + ($profile->organization_id !== null ? 500 : 0)
                + ($profile->is_default ? 1 : 0);

            if ($score > $bestScore) {
                $best = $profile;
                $bestScore = $score;
            }
        }

        return self::$resolutionCache[$key] = $best;
    }

    /**
     * How well this profile matches the context, or -1 when it excludes it.
     *
     * An applies_to key that is absent or empty does not narrow on that axis.
     * A key that lists ids and does not contain this one is a hard exclusion,
     * not a weak match — a credit-risk profile must never score an operational
     * risk merely because nothing better was configured.
     */
    public function specificityFor(?int $nodeId, ?int $objectTypeId, ?string $riskType): int
    {
        $applies = $this->applies_to ?? [];
        $score = 0;

        foreach ([
            ['risk_types', $riskType, 4],
            ['object_type_ids', $objectTypeId, 2],
            ['node_ids', $nodeId, 1],
        ] as [$key, $value, $weight]) {
            $allowed = $applies[$key] ?? null;

            if (! is_array($allowed) || $allowed === []) {
                continue;
            }

            if ($value === null) {
                return -1;
            }

            // Loose comparison: node ids arrive as ints from the graph and as
            // strings from a JSON payload the builder wrote.
            if (! in_array($value, $allowed)) {
                return -1;
            }

            $score += $weight;
        }

        return $score;
    }

    /**
     * The profile every calculation falls back to when resolution finds
     * nothing — which should be impossible once the seed migration has run,
     * but a scoring service that returns null ratings on a half-migrated
     * database is worse than one that scores conservatively.
     *
     * Not persisted. It exists so the service always has a profile object.
     */
    public static function fallback(): self
    {
        $profile = new self(ScoringProfileTemplates::default());
        $profile->exists = false;

        return $profile;
    }

    public static function flushResolutionCache(): void
    {
        self::$resolutionCache = [];
    }

    /* ------------------------------------------------------------------ */
    /*  Reading the definition */
    /* ------------------------------------------------------------------ */

    /** The highest score this matrix can produce. */
    public function maxScore(): int
    {
        return max(1, $this->matrix_rows * $this->matrix_cols);
    }

    /**
     * The band a score falls into, or null when the score is outside every
     * band — which is a configuration error, and is reported as one rather
     * than silently rounded into the nearest band.
     *
     * @return array<string, mixed>|null
     */
    public function bandFor(int|float|null $score): ?array
    {
        if ($score === null) {
            return null;
        }

        foreach ($this->rating_bands ?? [] as $band) {
            if ($score >= ($band['min'] ?? PHP_INT_MIN) && $score <= ($band['max'] ?? PHP_INT_MAX)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * The dimensions this profile scores, falling back to the platform five so
     * a profile saved with an empty list does not silently score every risk at
     * zero impact.
     *
     * @return list<string>
     */
    public function dimensions(): array
    {
        $dimensions = array_values(array_filter((array) ($this->impact_dimensions ?? [])));

        return $dimensions !== [] ? $dimensions : ScoringProfileTemplates::DEFAULT_IMPACT_DIMENSIONS;
    }

    /** @return array<string, float> */
    public function weights(): array
    {
        $weights = (array) ($this->dimension_weights ?? []);

        return array_map('floatval', $weights);
    }

    /**
     * The label configured for a point on an axis, e.g. 4 => 'Likely'.
     */
    public function labelFor(string $axis, int $value): ?string
    {
        $scale = $axis === 'likelihood' ? ($this->likelihood_scale ?? []) : ($this->impact_scale ?? []);

        foreach ($scale as $level) {
            if ((int) ($level['value'] ?? 0) === $value) {
                return $level['label'] ?? null;
            }
        }

        return null;
    }

    /** @return array<int, string> value => label, for a select box. */
    public function axisLabels(string $axis): array
    {
        $scale = $axis === 'likelihood' ? ($this->likelihood_scale ?? []) : ($this->impact_scale ?? []);
        $points = $axis === 'likelihood' ? $this->matrix_rows : $this->matrix_cols;

        $labels = [];

        for ($value = 1; $value <= $points; $value++) {
            $labels[$value] = ucfirst($axis).' '.$value;
        }

        foreach ($scale as $level) {
            $value = (int) ($level['value'] ?? 0);

            if ($value >= 1 && $value <= $points && ! empty($level['label'])) {
                $labels[$value] = $level['label'];
            }
        }

        return $labels;
    }

    /* ------------------------------------------------------------------ */
    /*  Relations */
    /* ------------------------------------------------------------------ */

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
