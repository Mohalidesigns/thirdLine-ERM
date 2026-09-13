<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A standing instruction to render a report and email it — FR-RPT-09.
 *
 * `isDueOn()` IS THE WHOLE MODEL. Everything else is bookkeeping around one
 * question the dispatcher asks each morning: should this schedule fire today?
 * Keeping it here rather than in the command means it can be tested without a
 * clock, a queue or a mailer, which is what makes the frequency rules
 * assertable at all.
 *
 * A MONTHLY SCHEDULE IS CAPPED AT DAY 28. February is the reason. A schedule
 * set for the 31st that silently skips four months a year is worse than one
 * that fires slightly early, because nobody notices the absence of an email.
 */
class ReportSchedule extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_report_schedules';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    /** @var list<string> */
    public const FREQUENCIES = [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY];

    /** @var list<string> */
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /** The highest day-of-month a schedule may be set to. See the class note. */
    public const MAX_DAY_OF_MONTH = 28;

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'organization_id', 'report_key', 'name', 'frequency', 'day_of_week', 'day_of_month',
        'send_at', 'format', 'recipients', 'owner_id', 'is_active', 'created_by', 'updated_by',
    ];

    /**
     * Run outcomes are written by the dispatcher. A form that could set
     * `last_run_status` could mark a schedule healthy without it having run.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'last_run_at', 'last_run_status', 'last_run_message', 'consecutive_failures',
    ];

    protected $casts = [
        'recipients' => 'array',
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'consecutive_failures' => 'integer',
        'last_run_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Whether this schedule should fire on the given day.
     *
     * The time of day is NOT considered. The dispatcher runs once each morning
     * and asks only about the date; making the decision depend on the minute
     * would mean a schedule silently skipped whenever the cron ran late, which
     * is the failure mode this feature can least afford.
     */
    public function isDueOn(CarbonInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Already sent today. Re-running the dispatcher — after a deploy, or
        // by hand while debugging — must not send a second copy.
        if ($this->last_run_at !== null && $this->last_run_at->isSameDay($date)) {
            return false;
        }

        return match ($this->frequency) {
            self::FREQUENCY_DAILY => true,
            self::FREQUENCY_WEEKLY => $this->day_of_week === $date->dayOfWeekIso,
            self::FREQUENCY_MONTHLY => $this->day_of_month === $date->day,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function recipientList(): array
    {
        return array_values(array_filter(array_map(
            fn ($address) => trim((string) $address),
            (array) ($this->recipients ?? []),
        )));
    }

    public function describeFrequency(): string
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => 'Every day at '.$this->send_at,
            self::FREQUENCY_WEEKLY => 'Every '.$this->weekdayName().' at '.$this->send_at,
            self::FREQUENCY_MONTHLY => 'On day '.$this->day_of_month.' of each month at '.$this->send_at,
            default => 'Not scheduled',
        };
    }

    /**
     * What the last run did, in words, including the case nobody should read
     * as success.
     */
    public function describeLastRun(): string
    {
        if ($this->last_run_at === null) {
            // Not a green tick it has not earned.
            return 'Never run';
        }

        return match ($this->last_run_status) {
            self::STATUS_SUCCEEDED => 'Sent '.$this->last_run_at->toDayDateTimeString(),
            self::STATUS_SKIPPED => 'Skipped '.$this->last_run_at->toDayDateTimeString()
                .' — '.($this->last_run_message ?: 'no reason recorded'),
            self::STATUS_FAILED => 'FAILED '.$this->last_run_at->toDayDateTimeString()
                .' — '.($this->last_run_message ?: 'no reason recorded'),
            default => 'Ran '.$this->last_run_at->toDayDateTimeString().', outcome not recorded',
        };
    }

    private function weekdayName(): string
    {
        return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$this->day_of_week] ?? 'week';
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
