<?php

namespace App\Models\Bcms\Concerns;

use App\Models\Bcms\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Writes a before/after audit row on every create, update and delete.
 *
 * Definition of Done, item 4: every state change writes an entry with actor,
 * timestamp and before/after. Blueprint §14 goes further and calls the log
 * immutable — which is a property of the table (append only, no update path)
 * rather than of this trait.
 *
 * THREE RULES, the same three `TprmAuditable` settled and for the same reasons.
 *
 * `auditExcluded()` keeps secrets and noise out. A contact's `push_token` is a
 * credential; recording a before/after of it puts it in the audit table in
 * plain text, which is worse than not auditing the change. `updated_at`
 * changes on every write and would double the size of every diff while saying
 * nothing.
 *
 * ONLY WHAT CHANGED is recorded on update — `getChanges()`, not
 * `getAttributes()` — so a screen that re-saves forty untouched fields writes
 * one meaningful row rather than forty columns a reader has to diff by eye.
 *
 * THE BOOT CLOSURES TYPE-HINT `self`, NOT `Model`. In a trait `self` resolves
 * to the using class, so static analysis can see this trait's own methods on
 * it; `Model` widens it to the base class and produces one "undefined method"
 * per model using the trait — a hundred findings that make the analyser's
 * output unreadable and therefore unread.
 *
 * AUDITING NEVER FAILS THE WRITE. A missing audit row is recoverable and
 * reportable. An exercise a facilitator believes they closed, lost because its
 * audit insert failed, is not. The failure is logged at error level so a broken
 * audit path is visible rather than silent.
 */
trait BcmsAuditable
{
    public static function bootBcmsAuditable(): void
    {
        static::created(function (self $model): void {
            $model->writeBcmsAuditRow('created', null, $model->bcmsAuditPayload($model->getAttributes()));
        });

        static::updated(function (self $model): void {
            $changes = $model->bcmsAuditPayload($model->getChanges());

            if ($changes === []) {
                return;
            }

            $before = [];

            foreach (array_keys($changes) as $key) {
                $before[$key] = $model->getOriginal($key);
            }

            $model->writeBcmsAuditRow('updated', $before, $changes);
        });

        static::deleted(function (self $model): void {
            $model->writeBcmsAuditRow('deleted', $model->bcmsAuditPayload($model->getOriginal()), null);
        });
    }

    /**
     * Attributes never written to the log. Override to add model-specific
     * secrets; the base list covers the columns every model has.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return ['updated_at', 'push_token', 'raw_response'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function bcmsAuditPayload(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExcluded()));
    }

    /**
     * Record something that happened to this row which is not a column change.
     *
     * A RESCHEDULE'S JUSTIFICATION IS THE CASE THIS EXISTS FOR. The automatic
     * diff already captures that `scheduled_date` moved from the 14th to the
     * 28th; what it cannot capture is *why*, and the why is the half a regulator
     * asks about. The same shape covers a cancellation's reason, an executive
     * waiver and a readiness override — all decisions with a sentence attached
     * that no column holds.
     *
     * It writes an `after` and no `before` deliberately: this is an event, not
     * a transition, and inventing a "before" for one would make the audit log
     * read as though something reverted.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordAudit(string $event, array $context = []): void
    {
        $this->writeBcmsAuditRow($event, null, $context);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function writeBcmsAuditRow(string $event, ?array $before, ?array $after): void
    {
        try {
            $actor = auth()->user();

            AuditLog::query()->create([
                'organization_id' => $this->getAttribute('organization_id'),
                'auditable_type' => static::class,
                'auditable_id' => $this->getKey(),
                'event' => $event,
                'before' => $before,
                'after' => $after,
                'actor_id' => $actor?->getAuthIdentifier(),
                'actor_label' => $actor?->name,
                'ip_address' => Request::hasSession() || app()->runningInConsole() === false
                    ? Request::ip()
                    : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BCMS audit row could not be written', [
                'model' => static::class,
                'id' => $this->getKey(),
                'event' => $event,
                'exception' => get_class($e),
                ...$this->bcmsAuditFailureDiagnostics($e),
            ]);

            // A log line nothing reads is indistinguishable from silence, and
            // silence here is what let a too-narrow `event` column discard
            // audit rows for the life of the module (ADR 0014). The catch is
            // still correct — a plan activation must not roll back because its
            // audit row would not write — so instead of failing the write, the
            // failure is COUNTED where BcmsWatchdog will find it and page
            // somebody.
            //
            // Wrapped again, and deliberately: if the cache is the thing that
            // is broken, the business write must still succeed. This counter
            // failing is not worth an outage.
            try {
                Cache::add(AuditLog::AUDIT_FAILURE_CACHE_KEY, 0, now()->addDays(30));
                Cache::increment(AuditLog::AUDIT_FAILURE_CACHE_KEY);
            } catch (\Throwable) {
                // Nothing further to do: the Log::error above is the fallback.
            }
        }
    }

    /**
     * Bounded, value-free context for a failed audit write — never
     * `$e->getMessage()`.
     *
     * THIS IS THE SAME DEFECT THE CHANNEL ADAPTERS ALREADY HAD FIXED TWICE
     * THIS PHASE (`HttpChannel`, `SmsGatewayChannel`), and here it is worse:
     * `$before`/`$after` on this call are a BCMS model's own attributes —
     * for a call-tree contact or an alert recipient that is a name, a
     * mobile number, an email. `Illuminate\Database\QueryException::
     * getMessage()` appends the fully bound SQL (`formatMessage()` replaces
     * every `?` with its bound value before building the message), so a
     * constraint failure while auditing one of those models would write the
     * row's own contact details into the application log — the one sink
     * with no declared retention or residency, on the exact path that
     * exists to make the write accountable.
     *
     * What reaches the log instead is the SQLSTATE and the driver-specific
     * error number, both integers/short codes from a closed vocabulary with
     * no capacity to carry a value: `$e->errorInfo[2]` (the driver's own
     * message text) is deliberately never read, because for a duplicate-key
     * violation on a unique contact column it repeats the value itself —
     * exactly the shape `getMessage()` already leaked. The pair that is
     * logged is enough to tell a duplicate key (23000/1062) from a lock
     * wait timeout (HY000/1205) or a missing column (42S22/1054) at 3am,
     * which is what actually needs diagnosing, without the value.
     *
     * A non-`QueryException` throwable (the cache counter's own catch has
     * one path, this does not re-use it) logs only its built-in `getCode()`
     * — a small, programmer-set integer on nearly every exception class,
     * not data derived from this row.
     *
     * @return array<string, mixed>
     */
    protected function bcmsAuditFailureDiagnostics(\Throwable $e): array
    {
        if (! $e instanceof QueryException) {
            return ['code' => $e->getCode() ?: null];
        }

        return [
            'sqlstate' => $e->getCode() ?: ($e->errorInfo[0] ?? null),
            'driver_error_code' => $e->errorInfo[1] ?? null,
        ];
    }
}
