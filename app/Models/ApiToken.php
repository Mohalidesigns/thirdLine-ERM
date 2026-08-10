<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum token that knows which organization it belongs to.
 *
 * TWO KINDS
 *   personal            issued by a user, acts as that user
 *   client_credentials  issued for a system, acts as nobody
 *
 * The second exists because the alternative — a nightly connector
 * authenticating as a borrowed employee account — puts a named person's name
 * against every automated change at 3am for the next four years, and no audit
 * trail recovers from that.
 *
 * SCOPES NARROW, THEY NEVER WIDEN. A personal token's effective rights are the
 * intersection of its abilities and its owner's permissions, always. Issuing a
 * token with `risk.delete` to somebody who cannot delete risks gives them
 * nothing — which is the only safe direction for a credential a user can mint
 * for themselves.
 */
class ApiToken extends PersonalAccessToken
{
    // A token is tenant data: which tokens exist for an organization, who
    // issued them and when they were last used all belong to that organization
    // and to nobody else. The global scope means the admin token screens cannot
    // list another tenant's credentials by forgetting a where clause.
    //
    // Authentication is unaffected: findToken() runs before any tenant is
    // bound, and the scope does not filter when there is no organization set —
    // which is exactly right, because resolving the token is how the tenant
    // gets decided in the first place.
    use BelongsToOrganization;

    protected $table = 'personal_access_tokens';

    public const TYPE_PERSONAL = 'personal';

    public const TYPE_CLIENT = 'client_credentials';

    protected $fillable = [
        'organization_id', 'tokenable_type', 'tokenable_id', 'name', 'token_type',
        'client_id', 'description', 'token', 'abilities', 'expires_at',
        'created_by', 'rate_limit_per_minute',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'revoked_at' => 'datetime',
            'rate_limit_per_minute' => 'integer',
        ]);
    }

    /**
     * Resolve a presented token, ignoring tenancy.
     *
     * Deliberately outside the global scope. Resolving a credential is how the
     * tenant gets DECIDED, so it must not depend on whichever tenant happens to
     * be bound already — otherwise the same token resolves or does not
     * depending on what ran before it in the process, and a token with no
     * organization (which must be refused with a clear message) would instead
     * look like a token that does not exist.
     *
     * AuthenticateApiToken applies every check that matters immediately after:
     * revoked, expired, no organization, owner deactivated.
     */
    public static function findToken($token)
    {
        return static::withoutGlobalScopes()->getModel()->newQueryWithoutScopes()
            ->when(true, function ($query) use ($token) {
                if (! str_contains($token, '|')) {
                    return $query->where('token', hash('sha256', $token));
                }

                [$id, $plain] = explode('|', $token, 2);

                return $query->whereKey($id)->where('token', hash('sha256', $plain));
            })
            ->first();
    }

    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /* ------------------------------------------------------------------ */

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function isMachine(): bool
    {
        return $this->token_type === self::TYPE_CLIENT;
    }

    public function revoke(?User $by = null): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $by?->id ?? auth()->id(),
        ])->save();
    }

    /**
     * The user this token acts as, or null for a machine token.
     */
    public function actingUser(): ?User
    {
        return $this->tokenable instanceof User ? $this->tokenable : null;
    }

    /**
     * May this token do this thing?
     *
     * Both halves are required, and the order matters only for legibility:
     * the token's own scope, AND the permission of whoever stands behind it.
     * A machine token has nobody behind it, so its scopes are the whole answer
     * — which is why issuing one is a separate, narrower permission
     * (api.tokens.manage) than issuing a personal one.
     */
    public function permits(string $permission): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        if (! $this->can($permission) && ! $this->can('*')) {
            return false;
        }

        $user = $this->actingUser();

        return $user === null || $user->can($permission);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return array_values(array_filter((array) $this->abilities, 'is_string'));
    }
}
