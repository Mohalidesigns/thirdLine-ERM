<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A system in the IT DR register, with its recovery tier and targets.
 *
 * We GOVERN and EVIDENCE failover; we do not execute it (Blueprint §3.3). The
 * `last_test_*` columns are denormalised from the test table because the DR
 * register screen is "which systems are overdue and which missed target", and
 * computing that per row across tests is the N+1 a reviewer would reject.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $application_id
 * @property string $name
 * @property ?int $recovery_tier
 * @property ?string $rto_target_hours
 * @property ?int $rpo_target_minutes
 * @property ?string $dr_strategy
 * @property ?int $dr_site_id
 * @property ?int $failover_runbook_plan_id
 * @property ?\Illuminate\Support\Carbon $last_test_date
 * @property ?\Illuminate\Support\Carbon $next_test_due
 * @property ?int $last_test_rto_actual_minutes
 * @property ?int $last_test_rpo_actual_minutes
 * @property ?bool $last_test_met_objectives
 * @property ?string $backup_frequency
 * @property ?string $replication_type
 * @property ?\Illuminate\Support\Carbon $last_backup_verified_at
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class DrSystem extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_dr_systems';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'application_id', 'name', 'recovery_tier', 'rto_target_hours',
        'rpo_target_minutes', 'dr_strategy', 'dr_site_id', 'failover_runbook_plan_id',
        'last_test_date', 'next_test_due', 'last_test_rto_actual_minutes',
        'last_test_rpo_actual_minutes', 'last_test_met_objectives', 'backup_frequency',
        'replication_type', 'last_backup_verified_at', 'iso_clause_ref', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'application_id' => 'integer',
            'recovery_tier' => 'integer',
            'rto_target_hours' => 'decimal:2',
            'rpo_target_minutes' => 'integer',
            'dr_site_id' => 'integer',
            'failover_runbook_plan_id' => 'integer',
            'last_test_date' => 'date',
            'next_test_due' => 'date',
            'last_test_rto_actual_minutes' => 'integer',
            'last_test_rpo_actual_minutes' => 'integer',
            'last_test_met_objectives' => 'boolean',
            'last_backup_verified_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function drSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'dr_site_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function runbook(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'failover_runbook_plan_id');
    }

    /** @return HasMany<DrTest, $this> */
    public function tests(): HasMany
    {
        return $this->hasMany(DrTest::class, 'dr_system_id');
    }
}
