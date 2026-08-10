<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScimToken extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'token_hash',
        'last_used_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Mint a token. The plaintext is returned once and never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(int $organizationId, string $name, ?int $createdBy = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $plaintext = 'scim_'.Str::random(48);

        $token = static::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'token_hash' => self::hash($plaintext),
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plaintext];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
