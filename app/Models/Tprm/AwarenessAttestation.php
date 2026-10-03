<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\TprmAuditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A recurring CBN Cyber §2.3(ii) obligation: the third party's own security
 * awareness programme, delivered and evidenced periodically.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $third_party_id
 * @property ?int $engagement_id
 * @property string $programme_name
 * @property ?\Illuminate\Support\Carbon $delivered_at
 * @property ?int $participants
 * @property ?int $evidence_document_id
 * @property ?\Illuminate\Support\Carbon $next_due_at
 * @property ?int $created_by
 */
class AwarenessAttestation extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_awareness_attestations';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'third_party_id', 'engagement_id', 'programme_name', 'delivered_at',
        'participants', 'evidence_document_id', 'next_due_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'date',
            'participants' => 'integer',
            'next_due_at' => 'date',
        ];
    }

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    public function isOverdue(): bool
    {
        return $this->next_due_at !== null && $this->next_due_at->isPast();
    }
}
