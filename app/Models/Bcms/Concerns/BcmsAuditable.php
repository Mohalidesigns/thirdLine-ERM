<?php

namespace App\Models\Bcms\Concerns;

use App\Models\Bcms\AuditLog;
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
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
