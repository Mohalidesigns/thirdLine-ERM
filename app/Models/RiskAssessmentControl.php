<?php

namespace App\Models;

use App\Support\RiskCalculationSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Steps 6 and 7 of the assessment chain: Existing Controls → Control
 * Effectiveness, as rated at a point in time.
 *
 * This is a snapshot, not a view over the control library. Reading
 * `controls.effectiveness_pct` live at render time would mean that re-testing a
 * control next month silently rewrites the residual score on an assessment the
 * board already approved. An assessment has to stay reproducible, so the rating
 * that fed its residual score is stored with it.
 */
class RiskAssessmentControl extends Model
{
    use BelongsToOrganization, HasFactory;

    /**
     * The shared effectiveness vocabulary — the same keys as
     * `controls.effectiveness_rating` and config/risk.php's
     * `control_effectiveness` map, so one organization-level configuration
     * governs both the library and the assessment.
     */
    public const RATINGS = [
        'effective' => 'Effective',
        'mostly_effective' => 'Mostly Effective',
        'partially_effective' => 'Partially Effective',
        'ineffective' => 'Ineffective',
        'not_operating' => 'Not Operating',
    ];

    protected $fillable = [
        'organization_id',
        'risk_assessment_id',
        'control_id',
        'control_code',
        'control_name',
        'design_effectiveness',
        'operating_effectiveness',
        'effectiveness_pct',
        'control_weight',
        'is_key_control',
        'notes',
        'evidence_ref',
    ];

    protected $casts = [
        'effectiveness_pct' => 'decimal:2',
        'control_weight' => 'decimal:2',
        'is_key_control' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function assessment()
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Control, $this> */
    public function control(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Effectiveness resolution */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve one control's effectiveness from its design and operating
     * ratings.
     *
     * The weaker of the two wins. A control that is well designed but is not
     * being performed delivers the assurance of a control that is not being
     * performed — taking the average, or taking design alone, would let paper
     * controls inflate the residual score. Where only one of the two is rated,
     * that one stands.
     */
    public static function resolveEffectiveness(
        ?string $design,
        ?string $operating,
        ?int $organizationId = null,
    ): ?float {
        $map = RiskCalculationSettings::effectivenessMap($organizationId);

        $values = collect([$design, $operating])
            ->map(fn (?string $rating) => $rating === null ? null : strtolower(trim($rating)))
            ->filter(fn (?string $rating) => $rating !== null && $rating !== '' && array_key_exists($rating, $map))
            ->map(fn (string $rating) => (float) $map[$rating]);

        return $values->isEmpty() ? null : $values->min();
    }

    /**
     * Fill this row's effectiveness from its two ratings.
     */
    public function applyEffectiveness(): static
    {
        $this->effectiveness_pct = self::resolveEffectiveness(
            $this->design_effectiveness,
            $this->operating_effectiveness,
            $this->organization_id,
        );

        return $this;
    }

    /**
     * The weighted aggregate that residual risk derives from — step 7's single
     * output.
     *
     * Weights come from `risk_control_mapping.control_weight`, snapshotted at
     * assessment time. Controls left unrated are excluded rather than counted
     * as zero: a half-finished assessment must not report a risk as
     * uncontrolled. Returns null when nothing has been rated at all, which is
     * what tells the caller residual cannot yet be derived.
     */
    public static function aggregateEffectiveness(iterable $rows): ?float
    {
        $weightSum = 0.0;
        $weighted = 0.0;

        foreach ($rows as $row) {
            $pct = $row->effectiveness_pct;

            if ($pct === null) {
                continue;
            }

            $weight = (float) ($row->control_weight ?? 1.0);

            if ($weight <= 0) {
                continue;
            }

            $weightSum += $weight;
            $weighted += $weight * (float) $pct;
        }

        return $weightSum > 0 ? round($weighted / $weightSum, 2) : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    public function getDesignLabelAttribute(): string
    {
        return self::RATINGS[$this->design_effectiveness] ?? 'Not Rated';
    }

    public function getOperatingLabelAttribute(): string
    {
        return self::RATINGS[$this->operating_effectiveness] ?? 'Not Rated';
    }

    /**
     * The finding a design/operating pair represents. A control rated well on
     * design and badly on operation is a performance problem; the reverse is a
     * design problem. Naming which one is the point of separating the columns.
     */
    public function getFindingAttribute(): ?string
    {
        $map = RiskCalculationSettings::effectivenessMap($this->organization_id);
        $design = $map[$this->design_effectiveness] ?? null;
        $operating = $map[$this->operating_effectiveness] ?? null;

        if ($design === null || $operating === null) {
            return null;
        }

        return match (true) {
            $design - $operating >= 20 => 'Operating gap — designed well, not performed as designed',
            $operating - $design >= 20 => 'Design gap — performed diligently against a weak design',
            $design < 60 && $operating < 60 => 'Weak on both design and operation',
            default => null,
        };
    }
}
