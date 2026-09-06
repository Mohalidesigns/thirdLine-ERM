<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

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

    /**
     * How long a machine token lives when nobody chose a lifetime.
     *
     * PREVIOUS BEHAVIOUR: `expires_at` was nullable and left null by every
     * creation path that did not pass `--expires` / `expires_in_days`, and
     * config/sanctum.php sets `'expiration' => null`, so there was no ceiling
     * anywhere. A client_credentials token minted once for a nightly feed was a
     * credential with unrestricted lifetime, acting as nobody, held in whatever
     * scheduler configuration file the integrator put it in. Those outlive the
     * integration, the integrator and usually the contract.
     *
     * A YEAR IS THE DEFAULT, NOT THE RECOMMENDATION. It is chosen to be the
     * longest interval that still forces the credential across an annual review
     * — short enough that a leaked token has a stated end, long enough that the
     * default does not take a bank's nightly KRI feed down at 3am and teach
     * everyone to set the maximum. Issue a shorter one wherever the integration
     * can rotate.
     */
    public const MACHINE_DEFAULT_LIFETIME_DAYS = 365;

    /**
     * The longest lifetime a machine token may be given, whatever was asked for.
     *
     * Two years, so that an operator who wants "effectively forever" gets a date
     * instead. The admin form accepts up to 3,650 days (ten years), which for a
     * credential that acts as nobody and answers to no user's permissions is
     * indistinguishable from never expiring.
     */
    public const MACHINE_MAX_LIFETIME_DAYS = 730;

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
     * The two rules a MACHINE token cannot be created without.
     *
     * Enforced here, on the model, rather than in the admin controller, because
     * there are three creation paths — ApiTokenController::store(), the
     * `api:token` console command, and direct ApiToken::create() in tests and
     * future code — and a rule that lives in one of them is a rule that the
     * other two do not have. `creating` is the single point every path goes
     * through.
     *
     * WHY MACHINE TOKENS ARE TREATED DIFFERENTLY AT ALL. A personal token is
     * bounded by the person behind it: permits() intersects the token's
     * abilities with its owner's permissions, so the worst it can do is what its
     * owner could already do by logging in, and revoking or deactivating that
     * user disarms it. A client_credentials token has NOBODY behind it. Its
     * abilities are the whole of its authority, there is no second check to
     * catch an over-broad grant, and no leaver process that ever touches it.
     *
     * RULE 1 — IT MUST EXPIRE. Defaulted rather than rejected, because a
     * required field is something an integrator works around by picking the
     * biggest number in the dropdown; a default they did not have to think about
     * is one that actually holds. Capped at MACHINE_MAX_LIFETIME_DAYS so the
     * dropdown's ten-year option cannot be used to opt out.
     *
     * RULE 2 — IT MAY NOT HOLD `*`. See assertMachineScopesAreExplicit().
     */
    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            if ($token->token_type !== self::TYPE_CLIENT) {
                return;
            }

            self::assertMachineScopesAreExplicit((array) $token->abilities);

            $ceiling = now()->addDays(self::MACHINE_MAX_LIFETIME_DAYS);

            if ($token->expires_at === null) {
                $token->expires_at = now()->addDays(self::MACHINE_DEFAULT_LIFETIME_DAYS);
            } elseif ($token->expires_at->greaterThan($ceiling)) {
                $token->expires_at = $ceiling;
            }
        });
    }

    /**
     * A machine token names its permissions; it does not ask for all of them.
     *
     * THE DECISION, AND WHY IT WENT THIS WAY. The choice was between refusing
     * `*` outright and capping it — silently expanding `*` into the full
     * permission list. Capping was rejected: it grants exactly the same
     * authority, so it buys auditability and nothing else, and it does it by
     * making a decision on the operator's behalf that they never see. Refusing
     * is the control. An explicit list IS workable here — the permission set is
     * finite, seeded and enumerable, the admin screen already renders every one
     * of them as a checkbox, and `api:token --scopes=` takes as many as you
     * like. An integration that genuinely needs broad access can be issued a
     * token naming twenty scopes; what it cannot do is be issued a token that
     * silently acquires the twenty-first when the next migration seeds one.
     *
     * The abilities column is also the answer to "what could this credential
     * do", asked after an incident. `["*"]` is not an answer.
     *
     * NOTE FOR UPGRADES: permits() below stops honouring `*` on machine tokens
     * that already hold it, so a token issued before this rule will start being
     * refused rather than quietly continuing. That is deliberate — the whole
     * finding is that those tokens have unrestricted access to their tenant —
     * but it means an upgrade should list them first:
     *
     *     select id, name, organization_id from personal_access_tokens
     *      where token_type = 'client_credentials'
     *        and revoked_at is null
     *        and abilities like '%"*"%';
     *
     * and re-issue each one with the scopes it actually uses. `last_used_at`
     * tells you which are still live.
     *
     * @param  array<int, mixed>  $abilities
     *
     * @throws InvalidArgumentException with a message meant for whoever is
     *                                  issuing the token
     */
    public static function assertMachineScopesAreExplicit(array $abilities): void
    {
        if (! in_array('*', $abilities, true)) {
            return;
        }

        throw new InvalidArgumentException(
            'A machine token may not be issued with the * scope. It acts as no user, so nothing '
            .'narrows * down afterwards — it would hold every permission this organization has now '
            .'and every one added later. Name the scopes the integration actually uses instead.'
        );
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
     *
     * `*` IS HONOURED ONLY FOR A PERSONAL TOKEN, and that asymmetry is the whole
     * point. On a personal token `*` means "everything I can do" — the
     * intersection with the owner's permissions below is what bounds it, so a
     * `*` token issued to a risk-analyst still cannot delete a risk. On a
     * machine token there is no owner to intersect with, so `*` means everything
     * this ORGANIZATION can do, for as long as the token lives, with no second
     * check anywhere.
     *
     * PREVIOUS BEHAVIOUR: `! $this->can($permission) && ! $this->can('*')`
     * treated the two identically, so a machine token minted with `['*']` — the
     * default of `php artisan api:token --machine` when no --scopes were given —
     * had unrestricted access to its tenant's data through the REST API and the
     * MCP server.
     *
     * booted() stops new machine tokens holding `*` at all; this check is what
     * covers the ones already issued. See the upgrade note on
     * assertMachineScopesAreExplicit() — an affected integration starts getting
     * 403s and must be re-issued with named scopes.
     */
    public function permits(string $permission): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        // NOT $this->can(): Sanctum's implementation is
        // `in_array('*', $abilities) || in_array($ability, $abilities)`, so it
        // answers true for ANY permission the moment `*` is present. For a
        // machine token the scope list has to be read literally.
        $hasScope = $this->isMachine()
            ? in_array($permission, $this->scopes(), true)
            : $this->can($permission);

        if (! $hasScope) {
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
