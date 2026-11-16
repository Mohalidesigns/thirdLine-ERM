<?php

namespace App\Models;

use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class IncidentNotification extends Model
{
    use BelongsToOrganization, HasFactory;

    public const STATUSES = ['Pending', 'Notified', 'Not Required', 'Waived'];

    protected $fillable = [
        'organization_id', 'incident_id', 'obligation_id', 'obligation_title',
        'obligation_ref', 'legal_reference', 'regulator_id', 'regulator_name',
        'regulator_code', 'due_at', 'status', 'is_required', 'notified_at',
        'notification_reference', 'notified_by', 'notes', 'verification_status',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'notified_at' => 'datetime',
        'is_required' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function notifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('is_required', true)->where('status', 'Pending');
    }

    public function auditAction(string $action, ?array $before = null, ?array $after = null): void
    {
        AuditTrailService::record(
            $this->incident,
            "notification_{$action}",
            "notification:{$this->id}",
            $before ? json_encode($before) : null,
            $after ? json_encode($after) : null,
        );
    }
}
