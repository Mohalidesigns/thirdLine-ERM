<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\SyncRunStatus;
use App\Enums\Bcms\SyncTrigger;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One directory read — ADR 0018 §2.3. A record of an organisation-wide fact,
 * so it is organisation-level (ADR 0018 §8) and routed by uuid: `{run}` binds
 * to it directly, `{change}` nests under it with `->scopeBindings()`.
 *
 * `status` IS STORED HERE, COMPUTED NOWHERE ELSE. A run's outcome is a fact
 * about a past event, unlike call-tree staleness (ADR 0013), which is a fact
 * about the present and is refused a column for exactly that reason.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $identity_connector_id
 * @property \App\Enums\Bcms\SyncTrigger $trigger
 * @property \Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $finished_at
 * @property \App\Enums\Bcms\SyncRunStatus $status
 * @property int $directory_objects_read
 * @property int $pages_fetched
 * @property int $joiner_count
 * @property int $leaver_count
 * @property int $mover_count
 * @property int $contact_change_count
 * @property int $auto_applied_count
 * @property int $pending_count
 * @property ?string $error_class
 * @property ?string $error_code
 * @property ?int $triggered_by
 */
class IdentitySyncRun extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory;

    protected $table = 'bcms_identity_sync_runs';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'identity_connector_id', 'trigger', 'started_at', 'finished_at', 'status',
        'directory_objects_read', 'pages_fetched', 'joiner_count', 'leaver_count', 'mover_count',
        'contact_change_count', 'auto_applied_count', 'pending_count', 'error_class', 'error_code',
        'triggered_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'identity_connector_id' => 'integer',
            'trigger' => SyncTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => SyncRunStatus::class,
            'directory_objects_read' => 'integer',
            'pages_fetched' => 'integer',
            'joiner_count' => 'integer',
            'leaver_count' => 'integer',
            'mover_count' => 'integer',
            'contact_change_count' => 'integer',
            'auto_applied_count' => 'integer',
            'pending_count' => 'integer',
            'triggered_by' => 'integer',
        ];
    }

    /** @return BelongsTo<IdentityConnector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IdentityConnector::class, 'identity_connector_id');
    }

    /**
     * `{change}` nests under `{run}` through this relation
     * (`->scopeBindings()`, ADR 0017 §5) — the run's own child rows, never
     * addressed across another run.
     *
     * @return HasMany<IdentitySyncChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(IdentitySyncChange::class, 'sync_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
