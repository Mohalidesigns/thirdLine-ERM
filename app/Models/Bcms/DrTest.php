<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One DR test result and its actual RTO and RPO.
 *
 * `occurrence_id` is nullable: a test recorded from a vendor's own report has
 * no occurrence, and it still counts as evidence. Refusing to record it is how
 * customers keep the spreadsheet this module is meant to replace.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $dr_system_id
 * @property ?int $occurrence_id
 * @property string $test_type
 * @property \Illuminate\Support\Carbon $test_date
 * @property ?int $rto_actual_minutes
 * @property ?int $rpo_actual_minutes
 * @property ?bool $met_objectives
 * @property bool $rollback_required
 * @property array<array-key, mixed> $issues
 * @property array<array-key, mixed> $evidence
 * @property ?string $notes
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class DrTest extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_dr_tests';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'dr_system_id', 'occurrence_id', 'test_type', 'test_date',
        'rto_actual_minutes', 'rpo_actual_minutes', 'met_objectives', 'rollback_required',
        'issues', 'evidence', 'notes', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issues' => 'array',
            'evidence' => 'array',
            'organization_id' => 'integer',
            'dr_system_id' => 'integer',
            'occurrence_id' => 'integer',
            'test_date' => 'date',
            'rto_actual_minutes' => 'integer',
            'rpo_actual_minutes' => 'integer',
            'met_objectives' => 'boolean',
            'rollback_required' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<DrSystem, $this> */
    public function drSystem(): BelongsTo
    {
        return $this->belongsTo(DrSystem::class, 'dr_system_id');
    }

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }
}
