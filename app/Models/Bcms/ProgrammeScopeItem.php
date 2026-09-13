<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One org node or process, in or out of the programme's scope (clause 4.3).
 *
 * `in_scope = false` IS A ROW, NOT AN ABSENCE. Clause 4.3 requires an exclusion
 * to be justified, so "the insurance brokerage is out of scope, because it
 * maintains its own arrangements" is recorded. Silence would be
 * indistinguishable from nobody having considered it.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $programme_id
 * @property string $scopable_type
 * @property int $scopable_id
 * @property bool $in_scope
 * @property ?string $rationale
 * @property ?int $created_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ProgrammeScopeItem extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_programme_scope';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'scopable_type', 'scopable_id', 'in_scope', 'rationale',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'scopable_id' => 'integer',
            'in_scope' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    /**
     * The business unit or process this row scopes in or out.
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function scopable(): MorphTo
    {
        return $this->morphTo();
    }
}
