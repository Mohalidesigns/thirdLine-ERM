<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class LossEventReturn extends Model
{
    use BelongsToOrganization;

    protected $table = 'loss_event_returns';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'period_code',
        'status',
        'has_loss',
        'comment',
        'submitted_by',
        'submitted_at',
        'loss_events_count',
        'reminded_at',
    ];

    protected $casts = [
        'has_loss' => 'boolean',
        'submitted_at' => 'datetime',
        'reminded_at' => 'datetime',
        'loss_events_count' => 'integer',
    ];

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function scopeForPeriod(Builder $query, string $periodCode): Builder
    {
        return $query->where('period_code', $periodCode);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }
}
