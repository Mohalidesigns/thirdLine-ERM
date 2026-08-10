<?php

namespace App\Models;

use App\Enums\ControlTestStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ControlTest extends Model
{
    use BelongsToOrganization, HasObjectIdentity, SoftDeletes;

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

    public function control()
    {
        return $this->belongsTo(Control::class);
    }

    public function tester()
    {
        return $this->belongsTo(User::class, 'tester_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evidence()
    {
        return $this->hasMany(ControlTestEvidence::class);
    }

    public function isPassed(): bool
    {
        return $this->result === 'effective';
    }
}
