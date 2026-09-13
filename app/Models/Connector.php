<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A configured connection to a system this platform reads from.
 *
 * config and credentials are separate so that showing a connector's settings on
 * screen never requires decrypting its password. Both are encrypted at rest;
 * only config is ever rendered.
 */
class Connector extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const FAILURE_LIMIT = 10;

    protected $fillable = [
        'organization_id', 'type', 'name', 'description', 'config', 'credentials',
        'field_map', 'schedule', 'is_active', 'created_by',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        // Encrypted even though it holds no secrets: it holds host names, paths
        // and query strings for a customer's internal systems, which is a map
        // of their estate.
        'config' => 'encrypted:array',
        'credentials' => 'encrypted:array',
        'field_map' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    /* ------------------------------------------------------------------ */

    public function runs()
    {
        return $this->hasMany(ConnectorRun::class)->latest();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDue(Builder $query, string $schedule): Builder
    {
        return $query->active()->where('schedule', $schedule);
    }

    /* ------------------------------------------------------------------ */

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    public function recordRun(string $status, ?string $error = null): void
    {
        $this->forceFill([
            'last_run_at' => now(),
            'last_status' => $status,
            'last_error' => $error,
            'consecutive_failures' => $status === 'failed' ? $this->consecutive_failures + 1 : 0,
            // A source that has refused ten runs in a row is misconfigured or
            // gone. Retrying it on every schedule tick makes the run log
            // useless and hides the connectors that are genuinely working.
            'is_active' => $status === 'failed' && $this->consecutive_failures + 1 >= self::FAILURE_LIMIT
                ? false
                : $this->is_active,
        ])->save();
    }

    /** Human summary for a list screen. */
    public function healthLabel(): string
    {
        return match (true) {
            ! $this->is_active => 'Disabled',
            $this->last_status === null => 'Never run',
            $this->last_status === 'failed' => 'Failing since '.optional($this->last_run_at)->diffForHumans(),
            default => 'Last ran '.optional($this->last_run_at)->diffForHumans(),
        };
    }
}
