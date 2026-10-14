<?php

namespace App\Models;

use App\Services\Configuration\ConfigurationExporter;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 *
 * @property array<string, mixed>|null $payload sealed in the column, see payload()
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
        'version' => 'integer',
        'exported_at' => 'datetime',
        'is_snapshot' => 'boolean',
    ];

    /**
     * THE PAYLOAD IS STORED SEALED (ADR 0026): the `json` column holds one JSON
     * STRING whose content is `CanonicalJson::encode()` of the bundle. Do not
     * "simplify" this back to an `array` cast.
     *
     * MySQL 8.0.46's JSON parser reads some doubles back as a neighbouring value
     * (`478/53` returns as `9.0188679245283`), so a checksum computed over the
     * PHP value before the insert fails over the value read back, and the
     * stored bundle reads as altered. A JSON string scalar never passes through
     * the number parser, so both engines return its contents byte for byte and
     * the doubles come back as the same PHP doubles that were sealed. Types are
     * kept exactly, which is why it is not done by quoting floats: a bundle is
     * applied as configuration, and a quoted weight would arrive as a string.
     *
     * A row written before ADR 0026 holds the payload as a JSON object. It is
     * read as it is and verified under the v1 checksum recipe; it is never
     * converted and never re-checksummed.
     *
     * No `shouldCache()`: Laravel would cache the caller's array on set, and an
     * in-memory model must return the same value as a fresh read.
     */
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn (?string $raw) => self::unsealPayload($raw),
            set: fn (array $value) => self::sealPayload($value),
        );
    }

    /** The column value for a payload: a JSON string holding the canonical text. */
    public static function sealPayload(array $payload): string
    {
        return json_encode(
            CanonicalJson::encode($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * The payload for a column value: sealed (a JSON string) or legacy (a JSON object).
     *
     * @return array<string, mixed>|null
     *
     * @throws \UnexpectedValueException when the column holds neither
     */
    public static function unsealPayload(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        }

        if (! is_array($decoded)) {
            throw new \UnexpectedValueException('A configuration bundle payload must be a JSON object, sealed or not.');
        }

        return $decoded;
    }

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
        try {
            return $this->checksum === ConfigurationExporter::checksum($this->payload ?? []);
        } catch (\InvalidArgumentException) {
            // A format this release does not read: not intact, never guessed at.
            return false;
        }
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
