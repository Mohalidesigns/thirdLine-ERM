<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\ExitPlanStatus;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The plan for leaving a provider.
 *
 * `last_tested_at` IS THE COLUMN THAT SEPARATES A PLAN FROM A DOCUMENT ABOUT
 * LEAVING. Almost every institution has exit plans; almost none has tested
 * one, and an untested plan's duration estimate is an assertion. The
 * concentration report shows the count of never-tested plans beside the
 * replacement estimate for exactly that reason.
 *
 * `data_extraction_tested` is the same argument one level down. A plan whose
 * extraction format has never been exercised is a plan to discover, on the day,
 * that the vendor's export is a PDF of a screen.
 */
class ExitPlan extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_exit_plans';

    protected $fillable = [
        'organization_id', 'engagement_id', 'version', 'trigger_scenarios',
        'alternative_providers', 'in_house_option', 'transition_steps',
        'estimated_duration_days', 'estimated_cost_minor', 'currency',
        'data_extraction_format', 'communication_plan',
        'residual_risk_during_transition', 'document_id', 'created_by', 'updated_by',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = [
        'status', 'approved_by', 'approved_at', 'last_tested_at', 'next_test_due',
        'data_extraction_tested',
    ];

    protected $casts = [
        'status' => ExitPlanStatus::class,
        'trigger_scenarios' => 'array',
        'alternative_providers' => 'array',
        'transition_steps' => 'array',
        'data_extraction_tested' => 'boolean',
        'approved_at' => 'datetime',
        'last_tested_at' => 'date',
        'next_test_due' => 'date',
        'version' => 'integer',
        'estimated_duration_days' => 'integer',
    ];

    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
        'data_extraction_tested' => false,
    ];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hasBeenTested(): bool
    {
        return $this->last_tested_at !== null;
    }

    public function testIsOverdue(): bool
    {
        return $this->next_test_due !== null
            && $this->next_test_due->isBefore(now()->startOfDay());
    }

    /**
     * How much of this plan is evidence rather than assertion.
     *
     * Three things: approved, tested, and the extraction actually exercised. A
     * plan with none of them is a document; with all three it is a capability.
     */
    public function credibility(): string
    {
        return match (true) {
            $this->hasBeenTested() && $this->data_extraction_tested => 'tested',
            $this->hasBeenTested() => 'partially tested',
            $this->approved_at !== null => 'approved, never tested',
            default => 'draft',
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeverTested(Builder $query): Builder
    {
        return $query->whereNull('last_tested_at');
    }
}
