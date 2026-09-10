<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * WP-05 TASK 4 — a configuration, captured.
 *
 * Two kinds of row live here. A bundle somebody exported deliberately, and a
 * snapshot taken automatically before an apply. Snapshots carry is_snapshot
 * and are hidden from the bundle list: they exist to be rolled back to, not to
 * be browsed.
 */
class ConfigBundle extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'config_bundles';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'version',
        'description',
        'payload',
        'checksum',
        'exported_at',
        'exported_by',
        'source_environment',
        'is_snapshot',
    ];

    protected $casts = [
        'payload' => 'array',
        'version' => 'integer',
        'exported_at' => 'datetime',
        'is_snapshot' => 'boolean',
    ];

    public function exporter()
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function applications()
    {
        return $this->hasMany(ConfigBundleApplication::class, 'config_bundle_id');
    }

    /** Bundles a human made, as opposed to pre-apply snapshots. */
    public function scopeExported($query)
    {
        return $query->where('is_snapshot', false);
    }

    /** Whether the payload still matches the checksum recorded at export. */
    public function isIntact(): bool
    {
        return $this->checksum === \App\Services\Configuration\ConfigurationExporter::checksum($this->payload ?? []);
    }

    /** @return array<string, int> section name => row count */
    public function sectionCounts(): array
    {
        $counts = [];

        foreach ($this->payload['sections'] ?? [] as $section => $rows) {
            $counts[$section] = count($rows);
        }

        return $counts;
    }

    public function totalRows(): int
    {
        return array_sum($this->sectionCounts());
    }

    public function label(): string
    {
        return "{$this->name} v{$this->version}";
    }
}
