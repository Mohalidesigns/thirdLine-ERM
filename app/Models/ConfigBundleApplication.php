<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WP-05 TASK 4 — the immutable record of every dry run, apply and rollback.
 *
 * The stored diff is the one the operator was shown before they confirmed, not
 * one recomputed later. Recomputing against today's data answers a different
 * question, and the question this table exists to answer is what somebody
 * agreed to at the time.
 */
class ConfigBundleApplication extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'config_bundle_applications';

    protected $fillable = [
        'organization_id',
        'config_bundle_id',
        'snapshot_bundle_id',
        'mode',
        'outcome',
        'diff',
        'added_count',
        'changed_count',
        'removed_count',
        'conflict_count',
        'error',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'diff' => 'array',
        'applied_at' => 'datetime',
        'added_count' => 'integer',
        'changed_count' => 'integer',
        'removed_count' => 'integer',
        'conflict_count' => 'integer',
    ];

    public function bundle()
    {
        return $this->belongsTo(ConfigBundle::class, 'config_bundle_id');
    }

    public function snapshot()
    {
        return $this->belongsTo(ConfigBundle::class, 'snapshot_bundle_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** Whether this application can still be undone. */
    public function isRollbackable(): bool
    {
        return $this->mode === 'apply'
            && $this->outcome === 'ok'
            && $this->snapshot_bundle_id !== null
            && $this->rolled_back_by_application_id === null;
    }

    public function summary(): string
    {
        return "{$this->added_count} added, {$this->changed_count} changed, "
            ."{$this->removed_count} removed, {$this->conflict_count} conflicting";
    }
}
