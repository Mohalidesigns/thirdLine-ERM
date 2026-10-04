<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\NotificationKind;
use App\Enums\Bcms\NotificationRegulator;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One submission to one regulator (ADR 0020 §2). The row IS the classification
 * — creating one for `regulator = ndpc` is the act of deciding this incident
 * owes the NDPC's 72-hour clock, from `awareness_at`, not from today.
 *
 * `due_at` IS STORED AND NEVER RECOMPUTED ON READ. A deadline is a fact fixed
 * at the moment of awareness: if `config('bcms.incident.notification_windows')`
 * is ever corrected, every historical deadline stays exactly where it was.
 *
 * NO uuid — a child of the incident, addressed nested, referenced by numeric
 * id only inside a server-built action URL (ADR 0020 §2's own schema note).
 *
 * `BcmsAuditable` (Gate 2 review #1 defect 3): this row's every write — the
 * classification that opens a regulatory obligation, a recorded submission,
 * a withdrawal — is exactly the kind of change an examiner asks "when did
 * you know, and what changed" about. `content_snapshot` is excluded from the
 * audit row: it can hold personal data by the compliance-analyst's own rule
 * ("describe categories, never paste records"), and this trait's diff is not
 * the second, un-vetted place that data should end up.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $incident_id
 * @property \App\Enums\Bcms\NotificationRegulator $regulator
 * @property string $basis_clause_ref
 * @property \App\Enums\Bcms\NotificationKind $kind
 * @property int $sequence
 * @property \Illuminate\Support\Carbon $awareness_at
 * @property ?\Illuminate\Support\Carbon $due_at
 * @property ?\Illuminate\Support\Carbon $submitted_at
 * @property ?int $submitted_by
 * @property ?string $reference
 * @property ?array<array-key, mixed> $content_snapshot
 * @property ?\Illuminate\Support\Carbon $withdrawn_at
 * @property ?int $withdrawn_by
 * @property ?int $withdrawal_entry_id
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class IncidentNotification extends Model
{
    use BcmsAuditable, BelongsToOrganization, BindsToVisibleRecord, HasFactory;

    protected $table = 'bcms_incident_notifications';

    /**
     * Derived (ADR 0017 §2): a notification has no unit column of its own
     * and takes the shortest path to its anchor — the incident it is against.
     */
    public function orgAnchorPath(): string
    {
        return 'incident';
    }

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'incident_id', 'regulator', 'basis_clause_ref', 'kind', 'sequence',
        'awareness_at', 'due_at', 'submitted_at', 'submitted_by', 'reference', 'content_snapshot',
        'withdrawn_at', 'withdrawn_by', 'withdrawal_entry_id', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content_snapshot' => 'array',
            'organization_id' => 'integer',
            'incident_id' => 'integer',
            'sequence' => 'integer',
            'awareness_at' => 'datetime',
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'submitted_by' => 'integer',
            'withdrawn_at' => 'datetime',
            'withdrawn_by' => 'integer',
            'withdrawal_entry_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'regulator' => NotificationRegulator::class,
            'kind' => NotificationKind::class,
        ];
    }

    /**
     * ADR 0020 Amendment 2: once a row's submission is recorded, its
     * submission fields are immutable — the same protection a withdrawn row
     * gets, so neither a re-recorded "initial" nor a re-opened withdrawal can
     * quietly rewrite what a regulator was told or why an obligation was
     * dropped. `NotificationService::recordSubmission()`/`withdraw()` are the
     * one write path each; this is the belt to their braces (a service, a
     * seeder and a tinker session alike).
     */
    protected static function booted(): void
    {
        static::updating(function (self $notification): void {
            $wasSubmitted = $notification->getOriginal('submitted_at') !== null;
            $wasWithdrawn = $notification->getOriginal('withdrawn_at') !== null;

            if (! $wasSubmitted && ! $wasWithdrawn) {
                return;
            }

            foreach (['submitted_at', 'submitted_by', 'reference', 'content_snapshot'] as $field) {
                if ($notification->isDirty($field)) {
                    throw new LogicException(
                        'A submitted or withdrawn notification\'s submission fields are immutable — '
                        .'record a supplementary submission instead of editing this row.'
                    );
                }
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /**
     * The decision-log entry carrying the withdrawal's rationale (ADR 0020
     * Amendment 2).
     *
     * @return BelongsTo<IncidentLogEntry, $this>
     */
    public function withdrawalEntry(): BelongsTo
    {
        return $this->belongsTo(IncidentLogEntry::class, 'withdrawal_entry_id');
    }

    /**
     * Whether this obligation is still open — the fact the watchdog scans for.
     *
     * ADR 0020 Amendment 2: a withdrawn row is not open either — it is a
     * closed obligation, just closed by withdrawal rather than by
     * submission. Every reader of "open" (the countdown tiles, the
     * notification log's per-obligation flag, the stand-down gate via
     * `NotificationService::overdueOrOpenCount()`) goes through this one
     * method, so withdrawal only had to be taught here once.
     */
    public function isOpen(): bool
    {
        return $this->submitted_at === null && $this->withdrawn_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    /** Whether this row's submission fields have been recorded, ever. */
    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /** @return list<string> */
    public function auditExcluded(): array
    {
        return ['updated_at', 'push_token', 'raw_response', 'content_snapshot'];
    }
}
