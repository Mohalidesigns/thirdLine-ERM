<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One run of a call tree, announced or otherwise — the demo that closes deals
 * (Gate G2).
 *
 * Every scorecard number is STORED at completion. A test run in March must
 * reprint in December with March's roster and March's failures; recomputed from
 * live nodes it would silently improve as the tree is repaired.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $call_tree_id
 * @property ?int $occurrence_id
 * @property string $mode
 * @property bool $announced
 * @property ?\Illuminate\Support\Carbon $initiated_at
 * @property ?int $initiated_by
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?int $total_cascade_minutes
 * @property ?string $completion_rate
 * @property ?string $first_attempt_rate
 * @property ?string $deputy_activation_rate
 * @property int $data_quality_failures
 * @property int $nodes_total
 * @property int $nodes_reached
 * @property array<array-key, mixed> $scorecard
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class CallTreeTest extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory;

    protected $table = 'bcms_call_tree_tests';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'call_tree_id', 'occurrence_id', 'mode', 'announced', 'initiated_at',
        'initiated_by', 'completed_at', 'total_cascade_minutes', 'completion_rate',
        'first_attempt_rate', 'deputy_activation_rate', 'data_quality_failures', 'nodes_total',
        'nodes_reached', 'scorecard', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scorecard' => 'array',
            'organization_id' => 'integer',
            'call_tree_id' => 'integer',
            'occurrence_id' => 'integer',
            'announced' => 'boolean',
            'initiated_at' => 'datetime',
            'initiated_by' => 'integer',
            'completed_at' => 'datetime',
            'total_cascade_minutes' => 'integer',
            'completion_rate' => 'decimal:2',
            'first_attempt_rate' => 'decimal:2',
            'deputy_activation_rate' => 'decimal:2',
            'data_quality_failures' => 'integer',
            'nodes_total' => 'integer',
            'nodes_reached' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
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

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return HasMany<CallTreeTestNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(CallTreeTestNode::class, 'test_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
