<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Which shipped obligation applies to THIS institution, who owns it, and what
 * cadence it drives.
 *
 * `bcms_clause_refs` is the library — what exists in the world. This is the
 * tenant's applicability decision. A bank with no open-banking licence marks
 * those three rows not-applicable with a reason, and the evidence pack stops
 * asking for quarterly failover evidence it will never have.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $programme_id
 * @property string $clause_ref
 * @property bool $applies
 * @property ?string $applicability_note
 * @property ?int $owner_id
 * @property ?string $cadence
 * @property ?int $cadence_per_year
 * @property ?string $how_satisfied
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ProgrammeObligation extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_programme_obligations';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'clause_ref', 'applies', 'applicability_note',
        'owner_id', 'cadence', 'cadence_per_year', 'how_satisfied', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'applies' => 'boolean',
            'owner_id' => 'integer',
            'cadence_per_year' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
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

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
