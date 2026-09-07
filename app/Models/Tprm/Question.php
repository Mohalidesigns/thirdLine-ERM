<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\FindingSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question — FR-ASM-02.
 *
 * `weight` and `risk_weight` are different numbers and the distinction is
 * load-bearing. `weight` is the question's share of its section's structure;
 * `risk_weight` is w_q in the AC and EC formulae AND the input to the proposed
 * severity of a finding raised from a non-compliant answer (FR-ASM-09). A
 * question can be structurally central and minor in risk terms, or the reverse.
 *
 * `is_critical` is the one flag that changes the arithmetic non-linearly: a
 * critical question answered non-compliant caps the whole assessment's AC at
 * 0.5 (TRD §7.4). Authors should use it sparingly and the builder says so.
 */
class Question extends Model
{
    protected $table = 'tp_questions';

    /**
     * FR-ASM-02's types. `control_attestation` is the one that matters — a
     * question bound to a control ID with a required evidence class, which is
     * what lets a SOC 2 pre-answer it.
     *
     * @var list<string>
     */
    public const TYPES = [
        'single_choice', 'multiple_choice', 'yes_no_na', 'numeric', 'date',
        'free_text', 'file_upload', 'matrix', 'control_attestation',
    ];

    protected $fillable = [
        'section_id', 'code', 'text', 'help_text', 'type', 'options',
        'is_required', 'is_critical', 'weight', 'risk_weight',
        'evidence_required', 'evidence_types', 'min_assurance_level',
        'visibility_rule', 'scoring_map', 'auto_answer_rule', 'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'evidence_types' => 'array',
        'visibility_rule' => 'array',
        'scoring_map' => 'array',
        'auto_answer_rule' => 'array',
        'is_required' => 'boolean',
        'is_critical' => 'boolean',
        'evidence_required' => 'boolean',
        'weight' => 'decimal:3',
        'risk_weight' => 'decimal:3',
        'min_assurance_level' => AssuranceLevel::class,
    ];

    protected $attributes = [
        'type' => 'yes_no_na',
        'is_required' => true,
        'is_critical' => false,
        'weight' => 1,
        'risk_weight' => 1,
        'evidence_required' => false,
    ];

    /** @return BelongsTo<QuestionnaireSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireSection::class, 'section_id');
    }

    /** @return HasMany<QuestionControlMap, $this> */
    public function controlMaps(): HasMany
    {
        return $this->hasMany(QuestionControlMap::class, 'question_id');
    }

    /**
     * The severity a finding raised from a non-compliant answer starts at
     * (FR-ASM-09) — proposed, for a reviewer to confirm or adjust.
     *
     * A critical question failing is Critical whatever its weight, because
     * that is what `is_critical` means. Otherwise the risk weight decides.
     */
    public function proposedFindingSeverity(): FindingSeverity
    {
        if ($this->is_critical) {
            return FindingSeverity::Critical;
        }

        $weight = (float) $this->risk_weight;

        return match (true) {
            $weight >= 5 => FindingSeverity::High,
            $weight >= 2 => FindingSeverity::Medium,
            default => FindingSeverity::Low,
        };
    }
}
