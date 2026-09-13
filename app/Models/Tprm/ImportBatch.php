<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One reversible bulk import — FR-TPR-09.
 *
 * `created_ids` is what makes it reversible, and reversibility is what makes
 * the feature usable at all: an import that cannot be undone is one nobody
 * dares run against production data, which means the migration path in TRD §18
 * — "bulk import of the existing vendor list" — never actually gets used.
 *
 * The lifecycle is deliberately four states rather than two. `validated` exists
 * so the dry run is a thing that HAPPENED and can be looked at, not a preview
 * that evaporates: a user who validates on Friday and commits on Monday should
 * be committing the file they checked.
 */
class ImportBatch extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_import_batches';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const TARGET_THIRD_PARTIES = 'third_parties';

    protected $fillable = [
        'organization_id', 'target', 'original_filename', 'file_path',
        'column_mapping', 'status', 'rows_total', 'rows_valid', 'rows_failed',
        'errors', 'created_ids', 'committed_at', 'rolled_back_at', 'created_by',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'errors' => 'array',
        'created_ids' => 'array',
        'committed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }

    public function canCommit(): bool
    {
        return $this->status === self::STATUS_VALIDATED && $this->rows_valid > 0;
    }

    /**
     * Whether the import can still be undone.
     *
     * Committed and not yet rolled back. There is no time limit — a rollback a
     * week later is still the right thing to offer, and the register knows
     * exactly which rows it created.
     */
    public function canRollBack(): bool
    {
        return $this->isCommitted() && $this->rolled_back_at === null && ! empty($this->created_ids);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
