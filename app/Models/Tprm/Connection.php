<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\ConnectionStatus;
use App\Enums\Tprm\ConnectionType;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A technical path between us and a third party — FR-ACC-01.
 *
 * This table exists because of what happens at the END of a relationship. Most
 * of a vendor file is about deciding whether to let somebody in; the connection
 * register is the only record of what was actually opened, and therefore the
 * only thing that can be reconciled when the contract ends. A bank that cannot
 * answer "what is still plugged in" cannot answer the examiner's question about
 * discontinued providers either.
 *
 * `closure_evidence_document_id` IS DELIBERATELY NOT A FOREIGN KEY WITH A
 * CASCADE. It points at a Document, and a document deleted years later must not
 * silently reopen a closed connection or take the closure record with it.
 */
class Connection extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_connections';

    protected $fillable = [
        'organization_id', 'engagement_id', 'type', 'name', 'endpoint', 'direction',
        'data_flows', 'encryption', 'authentication_method', 'firewall_rule_ref',
        'owner_id', 'created_by',
    ];

    /**
     * Set by the service, never by a form.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'approved_by', 'approved_at', 'closed_at', 'closure_evidence_document_id',
    ];

    protected $casts = [
        'type' => ConnectionType::class,
        'status' => ConnectionStatus::class,
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'requested',
        'direction' => 'bidirectional',
    ];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function closureEvidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'closure_evidence_document_id');
    }

    /** @return HasMany<AccessGrant, $this> */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class, 'connection_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', ConnectionStatus::Closed->value);
    }
}
