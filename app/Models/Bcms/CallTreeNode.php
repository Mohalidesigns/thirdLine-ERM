<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person's place in a cascade.
 *
 * `is_must_reach` blocks the cascade from scoring complete if this node was
 * never reached, however many people below it answered.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $call_tree_id
 * @property ?int $parent_node_id
 * @property int $tier
 * @property ?int $user_id
 * @property ?int $contact_id
 * @property ?string $role_label
 * @property bool $is_must_reach
 * @property ?string $primary_channel
 * @property ?string $secondary_channel
 * @property ?int $deputy_user_id
 * @property ?int $deputy_contact_id
 * @property int $expected_response_minutes
 * @property int $sequence
 * @property ?string $notes
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class CallTreeNode extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_call_tree_nodes';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'call_tree_id', 'parent_node_id', 'tier', 'user_id', 'contact_id',
        'role_label', 'is_must_reach', 'primary_channel', 'secondary_channel', 'deputy_user_id',
        'deputy_contact_id', 'expected_response_minutes', 'sequence', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'call_tree_id' => 'integer',
            'parent_node_id' => 'integer',
            'tier' => 'integer',
            'user_id' => 'integer',
            'contact_id' => 'integer',
            'is_must_reach' => 'boolean',
            'deputy_user_id' => 'integer',
            'deputy_contact_id' => 'integer',
            'expected_response_minutes' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<CallTree, $this> */
    public function callTree(): BelongsTo
    {
        return $this->belongsTo(CallTree::class, 'call_tree_id');
    }

    /** @return BelongsTo<CallTreeNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CallTreeNode::class, 'parent_node_id');
    }

    /** @return HasMany<CallTreeNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(CallTreeNode::class, 'parent_node_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function deputyContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'deputy_contact_id');
    }
}
