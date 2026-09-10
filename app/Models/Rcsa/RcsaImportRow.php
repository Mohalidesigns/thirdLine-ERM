<?php

namespace App\Models\Rcsa;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staged row of an upload.
 *
 * `raw` is exactly what the cells contained; `normalised` is what the row means
 * after trimming, alias resolution, date parsing and name-to-id lookup. BOTH
 * are kept: the annotated error workbook has to give the user their own file
 * back with their own spelling in it, and telling somebody "row 42 is wrong"
 * while showing them a value they never typed is how a bulk upload loses
 * whatever trust it had.
 *
 * NOT TENANT-SCOPED, and it has no organization_id: a row is meaningless apart
 * from its batch and is only ever reached through one, so the boundary is
 * enforced once on RcsaImportBatch.
 */
class RcsaImportRow extends Model
{
    use HasFactory;

    public const VALID = 'valid';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const DUPLICATE = 'duplicate';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    protected $table = 'rcsa_import_rows';

    protected $fillable = [
        'batch_id',
        'row_number',
        'raw',
        'normalised',
        'status',
        'errors',
        'target_id',
        'action',
        'row_hash',
    ];

    protected $casts = [
        'raw' => 'array',
        'normalised' => 'array',
        'errors' => 'array',
        'row_number' => 'integer',
        'target_id' => 'integer',
    ];

    /** @return BelongsTo<RcsaImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(RcsaImportBatch::class, 'batch_id');
    }

    /**
     * Whether this row would write something if the batch were published.
     *
     * A warning still writes — that is what separates it from an error. A
     * duplicate defaults to `skip` and writes nothing unless the user switched
     * the batch to update mode.
     */
    public function willWrite(): bool
    {
        return $this->action !== self::SKIP && $this->status !== self::ERROR;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_values(array_map(
            fn (array $error) => (string) ($error['message'] ?? ''),
            $this->errors ?? []
        ));
    }
}
