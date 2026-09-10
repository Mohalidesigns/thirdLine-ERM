<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\SignalSeverity;
use App\Support\Tprm\RuleEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A declarative rule that turns signals into action — FR-MON-04.
 *
 * `cooldown_hours` IS WHAT KEEPS THE STREAM CREDIBLE. A rule that fires on
 * every occurrence of a recurring signal produces the same alert nightly, and
 * a console full of yesterday's alert is one nobody opens. The cooldown is per
 * rule per subject, so a rule watching forty vendors still fires for the
 * thirty-ninth while sitting quiet on the first.
 *
 * THE ACTIONS ARE ORDERED AND THE ORDER IS SIGNIFICANT. `suspend_engagement`
 * before `create_finding` means the vendor is stopped before the paperwork;
 * the reverse means a finding exists against an engagement still taking
 * traffic. The rule editor preserves the author's order rather than sorting
 * them, because only the author knows which matters.
 */
class AlertRule extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_alert_rules';

    public const ACTION_NOTIFY = 'notify';

    public const ACTION_CREATE_TASK = 'create_task';

    public const ACTION_CREATE_FINDING = 'create_finding';

    public const ACTION_ADJUST_RESIDUAL = 'adjust_residual';

    public const ACTION_TARGETED_ASSESSMENT = 'targeted_assessment';

    public const ACTION_ESCALATE = 'escalate';

    public const ACTION_SUSPEND_ENGAGEMENT = 'suspend_engagement';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_NOTIFY,
        self::ACTION_CREATE_TASK,
        self::ACTION_CREATE_FINDING,
        self::ACTION_ADJUST_RESIDUAL,
        self::ACTION_TARGETED_ASSESSMENT,
        self::ACTION_ESCALATE,
        self::ACTION_SUSPEND_ENGAGEMENT,
    ];

    protected $fillable = [
        'organization_id', 'name', 'signal_types', 'condition', 'scope',
        'actions', 'severity', 'is_enabled', 'cooldown_hours', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'signal_types' => 'array',
        'condition' => 'array',
        'scope' => 'array',
        'actions' => 'array',
        'severity' => SignalSeverity::class,
        'is_enabled' => 'boolean',
        'cooldown_hours' => 'integer',
    ];

    protected $attributes = [
        'is_enabled' => true,
        'cooldown_hours' => 24,
        'severity' => 'medium',
    ];

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'rule_id');
    }

    /**
     * Whether this rule watches a signal of this type.
     *
     * An empty `signal_types` watches EVERYTHING, and that is the right
     * default for a field somebody left blank — a rule that matched nothing
     * would be a rule that silently never fired, which is the failure mode
     * this whole class is written to avoid.
     */
    public function watches(string $signalType): bool
    {
        $types = (array) ($this->signal_types ?? []);

        return $types === [] || in_array($signalType, $types, true);
    }

    /**
     * Whether the rule's condition holds for a signal's facts.
     *
     * A null condition is TRUE. Same reasoning as the clause library: an
     * author who left the field blank meant "always", and a default of false
     * would produce rules that look configured and do nothing.
     *
     * @param  array<string, mixed>  $facts
     */
    public function matches(array $facts, ?RuleEvaluator $evaluator = null): bool
    {
        if (empty($this->condition)) {
            return true;
        }

        return ($evaluator ?? new RuleEvaluator)->evaluate($this->condition, $facts);
    }

    /**
     * Whether this rule applies to a given engagement's attributes.
     *
     * Scope narrows by tier or by engagement type — "only Critical vendors",
     * "only ICT services". Evaluated against the same fact context as the
     * condition, so an author writes one dialect rather than two.
     *
     * @param  array<string, mixed>  $facts
     */
    public function inScope(array $facts, ?RuleEvaluator $evaluator = null): bool
    {
        if (empty($this->scope)) {
            return true;
        }

        return ($evaluator ?? new RuleEvaluator)->evaluate($this->scope, $facts);
    }

    /** @return list<string> */
    public function actionList(): array
    {
        return array_values(array_filter(
            (array) ($this->actions ?? []),
            fn ($action) => in_array($action, self::ACTIONS, true),
        ));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
