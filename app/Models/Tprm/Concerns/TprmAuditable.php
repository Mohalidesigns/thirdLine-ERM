<?php

namespace App\Models\Tprm\Concerns;

use App\Models\Tprm\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Writes a before/after audit row on every create, update and delete.
 *
 * TWO RULES ABOUT WHAT REACHES THE LOG.
 *
 * `auditExcluded()` keeps secrets and noise out. A vendor portal user's
 * `password` and `mfa_secret`, a monitoring source's `credentials`: recording
 * a before/after of those would put the secret in the audit table in plain
 * text, which is worse than not auditing the change at all. `updated_at` is
 * excluded because it changes on every write and would double the size of
 * every diff without saying anything.
 *
 * ONLY WHAT ACTUALLY CHANGED is recorded on update. `getChanges()` rather than
 * `getAttributes()`, so a screen that re-saves forty unchanged fields writes
 * one meaningful row instead of forty columns of noise a reader has to
 * diff by eye.
 *
 * AUDITING NEVER FAILS THE WRITE. This mirrors `HasObjectIdentity`'s reasoning
 * for the graph index: a missing audit row is recoverable and reportable; a
 * risk acceptance the approver believes they recorded, lost because its audit
 * insert failed, is not. The failure is logged at error level so a broken
 * audit path is visible rather than silent.
 */
trait TprmAuditable
{
    public static function bootTprmAuditable(): void
    {
        static::created(function (self $model): void {
            $model->writeAuditRow('created', null, $model->auditPayload($model->getAttributes()));
        });

        static::updated(function (self $model): void {
            $changes = $model->auditPayload($model->getChanges());

            if ($changes === []) {
                return;
            }

            $before = [];
            foreach (array_keys($changes) as $key) {
                $before[$key] = $model->getOriginal($key);
            }

            $model->writeAuditRow('updated', $model->auditPayload($before), $changes);
        });

        static::deleted(function (self $model): void {
            // A soft delete is a status change, not a disappearance, and the
            // log should say which of the two happened.
            $event = self::isSoftDeleting($model) ? 'soft_deleted' : 'deleted';

            $model->writeAuditRow($event, $model->auditPayload($model->getOriginal()), null);
        });

        // Registered by NAME rather than through the `restored()` helper,
        // because that helper exists only on models using SoftDeletes and this
        // trait is applied to models that do not (a ruleset is versioned, not
        // soft-deleted). `registerModelEvent` is on every Eloquent model, so
        // one registration covers both cases and static analysis can see it.
        static::registerModelEvent('restored', function (Model $model): void {
            if ($model instanceof self) {
                $model->writeAuditRow('restored', null, $model->auditPayload($model->getAttributes()));
            }
        });
    }

    /**
     * Whether this delete is a soft delete.
     *
     * Asked of the instance rather than of the class, so that static analysis
     * narrows the type and a model without SoftDeletes is handled rather than
     * assumed away.
     */
    private static function isSoftDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();
    }

    /**
     * Attributes never written to the audit log.
     *
     * Override on a model to add to it; always merge with the parent's list
     * rather than replacing it.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return ['password', 'remember_token', 'mfa_secret', 'credentials', 'token_hash', 'updated_at'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExcluded()));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function writeAuditRow(string $event, ?array $before, ?array $after): void
    {
        try {
            $request = request();
            $actor = auth()->user();

            AuditLog::create([
                'organization_id' => $this->getAttribute('organization_id'),
                'auditable_type' => static::class,
                'auditable_id' => $this->getKey(),
                'event' => $event,
                'actor_type' => $actor !== null ? 'user' : 'system',
                'actor_id' => $actor?->getKey(),
                'actor_label' => $actor?->name,
                'before' => $before,
                'after' => $after,
                'ip' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 500) ?: null,
                'correlation_id' => $request?->header('X-Correlation-Id'),
            ]);
        } catch (\Throwable $exception) {
            Log::error('TPRM audit write failed', [
                'model' => static::class,
                'id' => $this->getKey(),
                'event' => $event,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** @return \Illuminate\Database\Eloquent\Relations\MorphMany<AuditLog, $this> */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable', 'auditable_type', 'auditable_id');
    }
}
