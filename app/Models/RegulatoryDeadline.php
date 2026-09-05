<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegulatoryDeadline extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /**
     * The states a deadline moves through, as the enum column defines them.
     *
     * Kept here so the filter on the deadlines screen and the validator that
     * accepts a status are derived from one list rather than hand-written
     * beside each other — 4.6's lesson, where a form offered options no column
     * would take.
     *
     * @var list<string>
     */
    public const STATUSES = ['upcoming', 'in_progress', 'submitted', 'overdue', 'not_applicable'];

    /** A deadline in these states is not chased, whatever its date. */
    public const NOT_CHASED = ['submitted', 'not_applicable'];

    protected $fillable = [
        'organization_id', 'regulator', 'report_type', 'title', 'description',
        'deadline_date', 'frequency', 'status', 'responsible_id', 'notes',
    ];

    protected $casts = ['deadline_date' => 'date'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /** @return HasMany<RegulatoryFiling, $this> */
    public function filings(): HasMany
    {
        return $this->hasMany(RegulatoryFiling::class, 'deadline_id');
    }

    public function isOverdue(): bool
    {
        return $this->deadline_date->isPast() && ! in_array($this->status, ['submitted', 'not_applicable']);
    }
}
