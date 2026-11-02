<?php

namespace App\Models;

use App\Enums\KriReturnStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One department's KRI return for one reporting month (ADR 0026 section 4).
 *
 * The unique key (organisation, business unit, period) is the idempotency key
 * for the whole cycle. The stored status is draft / submitted / reopened; the
 * badge a screen shows is DERIVED by displayStatus(), never stored.
 */
class KriReturn extends Model
{
    use BelongsToOrganization;

    public const DISPLAY_SUBMITTED = 'submitted';

    public const DISPLAY_OVERDUE = 'overdue';

    public const DISPLAY_REOPENED = 'reopened';

    public const DISPLAY_IN_PROGRESS = 'in_progress';

    public const DISPLAY_NOT_STARTED = 'not_started';

    protected $table = 'kri_returns';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'period_id',
        'kri_cycle_run_id',
        'status',
        'requested_on',
        'due_on',
        'opened_at',
        'submitted_at',
        'submitted_by',
        'reopened_at',
        'reopened_by',
        'reopen_count',
        'lock_version',
    ];

    protected $casts = [
        'status' => KriReturnStatus::class,
        'requested_on' => 'date',
        'due_on' => 'date',
        'opened_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reopened_at' => 'datetime',
        'reopen_count' => 'integer',
        'lock_version' => 'integer',
    ];

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return BelongsTo<KriCycleRun, $this> */
    public function cycleRun(): BelongsTo
    {
        return $this->belongsTo(KriCycleRun::class, 'kri_cycle_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<KriReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(KriReturnLine::class);
    }

    /** @return HasMany<KriReturnNotice, $this> */
    public function notices(): HasMany
    {
        return $this->hasMany(KriReturnNotice::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === KriReturnStatus::Submitted;
    }

    /**
     * Calendar days after `due_on`, or null when it is not past due.
     *
     * A day against a day (standard section 14): `due_on` is a date cast, i.e.
     * midnight at the start of its day, so it is compared as a date string
     * against Lagos today, never with isPast().
     */
    public function daysOverdue(CarbonInterface $lagosToday): ?int
    {
        if ($this->isSubmitted() || $this->due_on === null) {
            return null;
        }

        $due = $this->due_on->toDateString();
        $today = $lagosToday->toDateString();

        if ($today <= $due) {
            return null;
        }

        // Whole calendar days between two DATE strings, both read as UTC
        // midnight, so the Lagos-vs-UTC hour offset can never truncate a day.
        return (int) \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $due, 'UTC')
            ->diffInDays(\Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC'), true);
    }

    /**
     * The badge: submitted > overdue > reopened > in_progress > not_started.
     *
     * @param  bool  $hasValue  at least one line holds a value
     */
    public function displayStatus(CarbonInterface $lagosToday, bool $hasValue): string
    {
        if ($this->isSubmitted()) {
            return self::DISPLAY_SUBMITTED;
        }

        if ($this->daysOverdue($lagosToday) !== null) {
            return self::DISPLAY_OVERDUE;
        }

        if ($this->status === KriReturnStatus::Reopened) {
            return self::DISPLAY_REOPENED;
        }

        return $hasValue ? self::DISPLAY_IN_PROGRESS : self::DISPLAY_NOT_STARTED;
    }
}
