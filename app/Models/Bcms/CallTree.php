<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\CallTreeSource;
use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\CallTreeType;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A cascade tree for a department, crisis team, site or DR function.
 *
 * `stale` is a status the SYSTEM writes, not one a user picks: a tree whose
 * review is overdue, or whose members' contact data has decayed, is stale. A
 * laminate on the wall nobody has checked in two years is the failure this
 * module exists to replace.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $business_unit_id
 * @property ?int $site_id
 * @property string $name
 * @property \App\Enums\Bcms\CallTreeType $tree_type
 * @property string $version
 * @property ?int $supersedes_call_tree_id
 * @property \App\Enums\Bcms\CallTreeStatus $status
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $last_reviewed_at
 * @property int $review_frequency_days
 * @property \App\Enums\Bcms\CallTreeSource $source
 * @property ?int $activation_authority_user_id
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class CallTree extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_call_trees';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'business_unit_id', 'site_id', 'name', 'tree_type', 'version',
        'supersedes_call_tree_id', 'status',
        'approved_by', 'approved_at', 'last_reviewed_at', 'review_frequency_days', 'source',
        'activation_authority_user_id', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'business_unit_id' => 'integer',
            'site_id' => 'integer',
            'supersedes_call_tree_id' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'review_frequency_days' => 'integer',
            'activation_authority_user_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            // Cast FIRST, before any comparison is written against these.
            // Reading an enum column as a string has produced a silently
            // disabled rule set once per phase since Phase 2.
            'tree_type' => CallTreeType::class,
            'status' => CallTreeStatus::class,
            'source' => CallTreeSource::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return HasMany<CallTreeNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(CallTreeNode::class, 'call_tree_id');
    }

    /** @return HasMany<CallTreeTest, $this> */
    public function tests(): HasMany
    {
        return $this->hasMany(CallTreeTest::class, 'call_tree_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activationAuthority(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activation_authority_user_id');
    }

    /** The version this one replaced, or null at v1 (ADR 0013). */
    /** @return BelongsTo<CallTree, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(CallTree::class, 'supersedes_call_tree_id');
    }

    /** @return HasMany<CallTree, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(CallTree::class, 'supersedes_call_tree_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
