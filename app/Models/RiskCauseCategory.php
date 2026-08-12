<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The cause taxonomy — step 2 of the assessment chain's vocabulary.
 *
 * Rows with a NULL organization_id are system categories inherited by every
 * tenant (Basel's four operational cause classes plus third-party and
 * governance). A tenant that wants its own taxonomy adds its own rows; it does
 * not have to replace the defaults to do so.
 */
class RiskCauseCategory extends Model
{
    use BelongsToOrganization, HasFactory;

    /** NULL organization_id means "system category, visible to every tenant". */
    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function causes()
    {
        return $this->hasMany(RiskCause::class, 'cause_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * The picker list: system categories and the tenant's own, in one ordered
     * set. Tenant-authored categories sort after the system ones sharing a
     * sort_order, so a bank's additions never displace the Basel classes.
     */
    public static function options(): \Illuminate\Support\Collection
    {
        return static::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']);
    }

    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }
}
