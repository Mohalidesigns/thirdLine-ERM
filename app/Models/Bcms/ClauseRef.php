<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Model;

/**
 * The readable half of `App\Enums\Bcms\IsoClauseRef`: title, standard,
 * citation, mandatory-record flag and export grouping.
 *
 * NOT TENANT-SCOPED AT ALL — no `organization_id` column. Clause 8.5 of ISO
 * 22301 does not vary by customer, and a per-tenant copy would let one tenant's
 * edit change what a clause means in their evidence pack.
 *
 * @property int $id
 * @property string $code
 * @property string $standard
 * @property ?string $clause
 * @property string $title
 * @property ?string $requirement
 * @property ?string $citation
 * @property bool $is_mandatory_record
 * @property array<array-key, mixed> $export_packs
 * @property int $sort_order
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ClauseRef extends Model
{
    //

    protected $table = 'bcms_clause_refs';

    /** @var list<string> */
    protected $fillable = [
        'code', 'standard', 'clause', 'title', 'requirement', 'citation', 'is_mandatory_record',
        'export_packs', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'export_packs' => 'array',
            'is_mandatory_record' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
