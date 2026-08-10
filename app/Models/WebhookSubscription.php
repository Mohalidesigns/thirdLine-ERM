<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An endpoint that wants to be told when something happens.
 *
 * The `events` array may name events exactly (`risk.created`) or by prefix
 * wildcard (`loss_event.*`, or `*` for everything). Prefix matching is enough:
 * an integrator subscribing to "anything about loss events" should not have to
 * list the events one by one and then miss the one added next quarter.
 */
class WebhookSubscription extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** Failures in a row before the subscription switches itself off. */
    public const FAILURE_LIMIT = 20;

    protected $fillable = [
        'organization_id', 'name', 'description', 'url', 'secret', 'events',
        'filters', 'is_active', 'created_by',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'filters' => 'array',
        'is_active' => 'boolean',
        // Encrypted at rest: it authenticates every payload this platform sends
        // to that endpoint, so a database dump must not hand over the ability
        // to forge them.
        'secret' => 'encrypted',
        'last_delivered_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
            // Generated, never supplied. A secret a user chose is a secret
            // somebody reused from another system.
            $model->secret ??= Str::random(48);
        });
    }

    /* ------------------------------------------------------------------ */

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id')->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('disabled_at');
    }

    /* ------------------------------------------------------------------ */

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null || ! $this->is_active;
    }

    /**
     * Does this subscription want this event?
     */
    public function wants(string $event): bool
    {
        foreach ((array) $this->events as $pattern) {
            if ($pattern === '*' || $pattern === $event) {
                return true;
            }

            if (str_ends_with($pattern, '.*') && str_starts_with($event, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the payload pass this subscription's filters?
     *
     * Without them, "tell me about loss events" means every loss event — which
     * for an integration that only cares about CBN-reportable ones is a great
     * deal of traffic it has to discard itself.
     *
     * @param  array<string, mixed>  $payload
     */
    public function matches(array $payload): bool
    {
        foreach ((array) $this->filters as $path => $expected) {
            $actual = data_get($payload, $path);

            if (is_array($expected)) {
                if (! in_array($actual, $expected)) {
                    return false;
                }

                continue;
            }

            if ($actual != $expected) {
                return false;
            }
        }

        return true;
    }

    public function recordSuccess(): void
    {
        $this->forceFill([
            'last_delivered_at' => now(),
            'consecutive_failures' => 0,
        ])->save();
    }

    /**
     * Record a failure, and switch the subscription off once it is clear the
     * endpoint is gone rather than briefly unwell.
     */
    public function recordFailure(string $reason): void
    {
        $failures = $this->consecutive_failures + 1;

        $this->forceFill([
            'last_failed_at' => now(),
            'consecutive_failures' => $failures,
            'disabled_at' => $failures >= self::FAILURE_LIMIT ? now() : $this->disabled_at,
            'disabled_reason' => $failures >= self::FAILURE_LIMIT
                ? "Disabled automatically after {$failures} consecutive failures. Last error: {$reason}"
                : $this->disabled_reason,
        ])->save();
    }
}
