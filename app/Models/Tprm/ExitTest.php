<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One exercise of an exit plan — AC-11.
 *
 * THE TEST IS WHAT SEPARATES A PLAN FROM A DOCUMENT ABOUT PLANNING, which is
 * the whole reason this table exists rather than a `last_tested_at` column
 * standing alone. A date says somebody ticked a box; a row with a method, a
 * scenario, participants, an outcome and the gaps it found says what was
 * actually exercised — and `gaps_identified` is the field a supervisor reads
 * first, because a test that found nothing usually tested nothing.
 *
 * `outcome` MAY BE A FAILURE AND THAT STILL COUNTS AS A TEST. A desktop walk
 * that discovered the data could not be extracted in the agreed format is the
 * most valuable exercise a bank can run, and a model that only recognised
 * successful tests would push people to record failures as nothing at all.
 */
class ExitTest extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_exit_tests';

    public const TYPE_DESKTOP = 'desktop';

    public const TYPE_PARTIAL = 'partial';

    public const TYPE_FULL = 'full';

    public const OUTCOME_SUCCESSFUL = 'successful';

    public const OUTCOME_PARTIAL = 'partial';

    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'organization_id', 'exit_plan_id', 'test_date', 'test_type', 'participants',
        'scenario', 'outcome', 'gaps_identified', 'evidence_document_id', 'created_by',
    ];

    protected $casts = [
        'test_date' => 'date',
        'participants' => 'array',
        'gaps_identified' => 'array',
    ];

    /** @return BelongsTo<ExitPlan, $this> */
    public function exitPlan(): BelongsTo
    {
        return $this->belongsTo(ExitPlan::class, 'exit_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether this exercise demonstrated the capability.
     *
     * A FAILED TEST STILL RESETS THE CLOCK. The interval is about how recently
     * the plan was exercised, not about how well it went — the gaps become
     * findings in their own right, and a bank that had to re-test immediately
     * after every failure would simply stop recording failures.
     */
    public function wasSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESSFUL;
    }
}
