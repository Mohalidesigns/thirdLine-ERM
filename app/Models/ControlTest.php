<?php

namespace App\Models;

use App\Enums\ControlTestStatus;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class ControlTest extends Model
{
    use BelongsToOrganization, HasObjectIdentity, SoftDeletes;

    /**
     * The vocabularies the testing screens accept, lifted out of
     * ControlTestController's inline `in:` rules (migration Phase 3.4).
     *
     * @var list<string>
     */
    public const TYPES = ['design_effectiveness', 'operating_effectiveness', 'walkthrough', 'substantive'];

    /** @var list<string> */
    public const RESULTS = ['effective', 'partially_effective', 'ineffective'];

    /**
     * The lifecycle, in the order a test moves through it. `pending_review` is
     * only reached when a reviewer was named — a test with none is complete on
     * submission, which is the rule completeTest() encodes.
     *
     * @var list<string>
     */
    public const STATUSES = ['scheduled', 'in_progress', 'pending_review', 'completed', 'rejected'];

    protected $fillable = [
        'organization_id', 'control_id', 'test_code', 'title', 'description',
        'test_type', 'tester_id', 'reviewer_id', 'scheduled_date', 'started_date',
        'completed_date', 'result', 'findings', 'recommendations', 'evidence_refs',
        'status', 'score', 'reviewer_notes', 'reviewed_at', 'created_by',
    ];

    protected $casts = [
        'evidence_refs' => 'array',
        'scheduled_date' => 'date',
        'started_date' => 'date',
        'completed_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    /**
     * The status as an enum.
     *
     * Deliberately NOT a `$casts` entry. Casting the attribute would make
     * every existing `$test->status !== 'pending_review'` comparison in the
     * controllers and views compare an enum against a string — always true —
     * silently inverting the approval and resubmit guards. Converting those
     * ~21 call sites is a separate change; this accessor lets new code work
     * with the enum in the meantime.
     */
    public function statusEnum(): ?ControlTestStatus
    {
        return ControlTestStatus::tryFrom((string) $this->status);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Control, $this> */
    public function control(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function tester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'tester_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ControlTestEvidence, $this> */
    public function evidence(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ControlTestEvidence::class);
    }

    public function isPassed(): bool
    {
        return $this->result === 'effective';
    }
}
