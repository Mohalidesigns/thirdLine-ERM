<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\AccessLevel;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One named person's access to one of our systems — FR-ACC-02.
 *
 * The grantee is a NAME AND AN EMAIL, not a user row, because these are the
 * vendor's staff and they will never have an account here. That makes
 * `grantee_name` the weakest link in the whole register — it is the field a
 * departing engineer's grant hides behind — which is why the reconciliation
 * report keys on the ENGAGEMENT's state rather than trying to work out whether
 * a person still works somewhere we cannot see.
 *
 * `isOverdue()` and the `Expired` status say different things. The first is a
 * date comparison anybody can make; the second is a claim the nightly job has
 * recorded, having also raised the Critical finding FR-ACC-05 requires. Reading
 * the date is how the report finds work to do; reading the status is how it
 * knows the work was done.
 */
class AccessGrant extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_access_grants';

    protected $fillable = [
        'organization_id', 'engagement_id', 'connection_id', 'grantee_name', 'grantee_email',
        'system_name', 'access_level', 'justification', 'valid_from', 'valid_to',
        'escort_required', 'monitoring_method', 'created_by',
    ];

    /**
     * Approval and revocation are service actions with their own guards.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'approver_id', 'approved_at', 'revoked_at', 'revoked_by',
        'revocation_evidence_document_id',
    ];

    protected $casts = [
        'access_level' => AccessLevel::class,
        'status' => AccessGrantStatus::class,
        'approved_at' => 'datetime',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'escort_required' => 'boolean',
        'revoked_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'active',
        'escort_required' => false,
    ];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function revocationEvidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'revocation_evidence_document_id');
    }

    /** Whether the credential should be assumed to still work. */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * Past its valid-to date and not yet revoked.
     *
     * A grant with no end date is not overdue. It is a worse problem — an
     * open-ended credential — which the reconciliation report reports
     * separately rather than quietly folding in here.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if (! $this->isLive() || $this->valid_to === null) {
            return false;
        }

        return $this->valid_to->isBefore(($asOf ?? Carbon::now())->startOfDay());
    }

    public function isOpenEnded(): bool
    {
        return $this->isLive() && $this->valid_to === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', '!=', AccessGrantStatus::Revoked->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query->live()
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<', ($asOf ?? Carbon::now())->toDateString());
    }
}
