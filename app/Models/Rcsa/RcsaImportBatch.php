<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One upload of the RCSA template.
 *
 * A batch is a STAGING AREA WITH A GATE ON THE END, not a record of an import
 * that already happened. The process-flow specification requires the system to
 * "validate uploaded records and flag any errors, duplicates or incomplete
 * information BEFORE the data is published", so a batch reaches `validated` and
 * stops there until a person looks at the preview and confirms. Only
 * `published` means anything was written to the universe.
 *
 * The counts are stored rather than derived from the rows, because the preview
 * screen shows them as tiles above a paginated grid and counting five statuses
 * across a 1,000-row batch on every page change is four queries nobody needs.
 * `RcsaImportProcessor::recount()` is the single writer.
 *
 * @property-read Collection<int, RcsaImportRow> $rows
 */
class RcsaImportBatch extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const QUEUED = 'queued';

    public const PARSING = 'parsing';

    public const VALIDATED = 'validated';

    public const FAILED = 'failed';

    public const PUBLISHED = 'published';

    public const DISCARDED = 'discarded';

    protected $table = 'rcsa_import_batches';

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'assessment_id',
        'file_path',
        'file_purged_at',
        'original_name',
        'template_version',
        'status',
        'total_rows',
        'valid_rows',
        'error_rows',
        'warning_rows',
        'duplicate_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'failure_reason',
        'error_report_path',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'file_purged_at' => 'datetime',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'warning_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<RcsaImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(RcsaImportRow::class, 'batch_id')->orderBy('row_number');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the batch may still be published.
     *
     * A published batch is final: republishing would create the rows twice,
     * because the staging rows are not consumed by publishing — they are kept
     * as the record of what the file contained.
     */
    public function isPublishable(): bool
    {
        return $this->status === self::VALIDATED;
    }

    public function hasErrors(): bool
    {
        return $this->error_rows > 0;
    }
}
