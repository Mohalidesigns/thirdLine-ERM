<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A third party's own business-continuity or disaster-recovery test result,
 * against one engagement — CBN Cyber §2.3(vii).
 *
 * `our_participation` matters because a test we did not attend is weaker
 * evidence than one we sat in on: the column is what lets a downstream score
 * (or, from BCMS Phase 11, a continuity-currency view) say so rather than
 * treating every third-party report as equally trustworthy.
 *
 * BCMS PHASE 11 READS THIS TABLE AND WRITES TO IT THROUGH
 * `App\Services\Tprm\Continuity\BcpTestRecorder`, never directly — a BCMS
 * permission grant is not a licence to write the vendor register
 * (phase-11-spec §5, ADR 0021 §4).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $engagement_id
 * @property \Illuminate\Support\Carbon $test_date
 * @property string $test_type
 * @property ?string $scope
 * @property bool $our_participation
 * @property ?int $rto_achieved_hours
 * @property ?int $rpo_achieved_hours
 * @property ?string $outcome
 * @property array<array-key, mixed> $findings_raised
 * @property ?int $evidence_document_id
 * @property ?\Illuminate\Support\Carbon $next_due_at
 * @property ?int $created_by
 */
class BcpTest extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_bcp_tests';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'engagement_id', 'test_date', 'test_type', 'scope', 'our_participation',
        'rto_achieved_hours', 'rpo_achieved_hours', 'outcome', 'findings_raised',
        'evidence_document_id', 'next_due_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'test_date' => 'date',
            'our_participation' => 'boolean',
            'rto_achieved_hours' => 'integer',
            'rpo_achieved_hours' => 'integer',
            'findings_raised' => 'array',
            'next_due_at' => 'date',
        ];
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether this test's evidence has passed its next-due date. */
    public function isOverdue(): bool
    {
        return $this->next_due_at !== null && $this->next_due_at->isPast();
    }
}
