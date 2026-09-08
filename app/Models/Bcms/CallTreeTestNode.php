<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * What happened at one node during one test.
 *
 * `downstream_blocked_count` is THE number on the broken-branch screen: the
 * difference between "one person missed the call" and "forty-three people were
 * never told". The `_snapshot` columns hold the node as it was, because a tree
 * edited after the test would otherwise rewrite who was on it.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $test_id
 * @property ?int $node_id
 * @property ?string $role_label_snapshot
 * @property ?string $contact_name_snapshot
 * @property ?int $tier_snapshot
 * @property ?\Illuminate\Support\Carbon $contacted_at
 * @property ?\Illuminate\Support\Carbon $acknowledged_at
 * @property ?int $response_minutes
 * @property ?string $channel_used
 * @property int $attempts
 * @property ?string $outcome
 * @property int $downstream_blocked_count
 * @property ?string $notes
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class CallTreeTestNode extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_call_tree_test_nodes';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'test_id', 'node_id', 'role_label_snapshot', 'contact_name_snapshot',
        'tier_snapshot', 'contacted_at', 'acknowledged_at', 'response_minutes', 'channel_used',
        'attempts', 'outcome', 'downstream_blocked_count', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'test_id' => 'integer',
            'node_id' => 'integer',
            'tier_snapshot' => 'integer',
            'contacted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'response_minutes' => 'integer',
            'attempts' => 'integer',
            'downstream_blocked_count' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<CallTreeTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(CallTreeTest::class, 'test_id');
    }

    /** @return BelongsTo<CallTreeNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(CallTreeNode::class, 'node_id');
    }
}
