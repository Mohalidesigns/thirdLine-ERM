<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Integrations\StoreApiTokenRequest;
use App\Models\ApiToken;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

/**
 * WP-07 TASK 2 — issuing and revoking API tokens.
 *
 * A user may always issue a token for themselves, because a token can never
 * exceed the permissions of the person behind it: the worst a user can do with
 * one is what they could already do by logging in. Issuing a MACHINE token is a
 * different act — it acts as nobody, so its scopes are the whole of its
 * authority — and needs api.tokens.manage.
 *
 * THE PLAINTEXT IS SHOWN ONCE. Only its hash is stored, so a lost token is
 * reissued rather than recovered. Every screen that pretends otherwise is
 * storing something it should not.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $canManage = $request->user()->can('api.tokens.manage');

        Gate::authorize('viewAny', ApiToken::class);

        return Inertia::render('Admin/ApiTokens/Index', [
            // Without api.tokens.manage a user sees only their own tokens.
            // Listing everyone's would show which integrations exist and when
            // they last ran, which is reconnaissance in itself.
            'tokens' => ApiToken::query()
                ->when(! $canManage, fn ($q) => $q->where('tokenable_id', $request->user()->id))
                ->with('creator:id,name', 'revoker:id,name')
                ->latest()
                ->get()
                ->map(fn (ApiToken $token) => array_merge($token->only([
                    'id', 'name', 'description', 'token_type', 'client_id', 'rate_limit_per_minute',
                ]), [
                    // Only the hash is stored, so there is nothing to leak here
                    // even by accident.
                    'abilities' => array_values((array) ($token->abilities ?? [])),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    // Carbon::parse rather than the cast: larastan reads this
                    // column as a string (it is declared in casts() on a
                    // Sanctum parent it cannot see through), and this is the
                    // shape AuthenticatedSessionController already uses for the
                    // same reason.
                    'revoked_at' => $token->revoked_at ? Carbon::parse($token->revoked_at)->toIso8601String() : null,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'creator' => $token->getRelationValue('creator')?->name,
                    'revoker' => $token->getRelationValue('revoker')?->name,
                    'can_revoke' => Gate::allows('revoke', $token),
                ]))->values()->all(),
            'canManage' => $canManage,
            'scopes' => Permission::orderBy('name')->pluck('name')->values(),
            'machineLifetime' => [
                'default_days' => ApiToken::MACHINE_DEFAULT_LIFETIME_DAYS,
                'max_days' => ApiToken::MACHINE_MAX_LIFETIME_DAYS,
            ],

            // THE PLAINTEXT IS SHOWN ONCE, on the one request that follows
            // issuing it, and only on this screen. Only its hash is stored, so
            // a lost token is reissued rather than recovered.
            'revealedToken' => fn () => session('revealed_token'),
            'revealedTokenFor' => fn () => session('revealed_token_for'),
        ]);
    }

    public function store(StoreApiTokenRequest $request)
    {
        $validated = $request->validated();

        $isMachine = ($validated['token_type'] ?? 'personal') === ApiToken::TYPE_CLIENT;

        if ($isMachine && ! Gate::allows('createMachine', ApiToken::class)) {
            return back()->with('error',
                'A machine token acts as no user, so its scopes are its whole authority. '
                .'Issuing one needs the api.tokens.manage permission.');
        }

        /*
         * A MACHINE TOKEN MAY NOT ASK FOR EVERYTHING.
         *
         * ApiToken::booted() enforces this for every creation path — this
         * controller, `php artisan api:token`, and anything written later — but
         * it enforces it by throwing, and a 500 on an admin form is not an
         * answer. Caught here so the operator gets told what to do instead.
         *
         * PREVIOUS BEHAVIOUR: the scope list was passed through untouched, on
         * the reasoning quoted below that "a personal token cannot grant what
         * its owner does not have". That reasoning is sound and it is ONLY about
         * personal tokens. A client_credentials token has no owner to be
         * narrowed by, so `*` on one means every permission this organization
         * has, plus every permission added to it in future, for the life of the
         * credential — and ApiToken::permits() had no second check to catch it.
         *
         * An explicit list is workable: the form on this screen renders every
         * seeded permission as a checkbox (see $scopes in index()), so an
         * integration that legitimately needs broad access can be given twenty
         * named scopes. What it cannot be given is the twenty-first, silently,
         * six months from now.
         */
        if ($isMachine) {
            try {
                ApiToken::assertMachineScopesAreExplicit($validated['scopes']);
            } catch (InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage())->withInput();
            }
        }

        /*
         * A MACHINE TOKEN MUST EXPIRE.
         *
         * PREVIOUS BEHAVIOUR: expires_in_days is optional and this method wrote
         * `null` when it was omitted, which — with config/sanctum.php setting
         * `'expiration' => null` — meant a credential with no end date at all,
         * acting as nobody, living in an integrator's configuration file for as
         * long as the integration does.
         *
         * The lifetime is resolved here so the CONFIRMATION MESSAGE can state
         * the real expiry date. ApiToken::booted() applies the same default and
         * the same ceiling regardless, but an operator who is told "expires
         * 2027-08-20" once, at the moment they copy the token, is far more
         * likely to diarise a rotation than one who has to go and look.
         */
        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays((int) $validated['expires_in_days'])
            : null;

        if ($isMachine) {
            $ceiling = now()->addDays(ApiToken::MACHINE_MAX_LIFETIME_DAYS);

            $expiresAt = match (true) {
                $expiresAt === null => now()->addDays(ApiToken::MACHINE_DEFAULT_LIFETIME_DAYS),
                $expiresAt->greaterThan($ceiling) => $ceiling,
                default => $expiresAt,
            };
        }

        // A personal token cannot grant what its owner does not have, so there
        // is no need to police the scope list here — ApiToken::permits()
        // intersects at request time. Saying so on screen is more useful than
        // silently dropping scopes the user cannot exercise.
        $plain = Str::random(48);

        $token = ApiToken::create([
            'organization_id' => TenantContext::organizationId(),
            'tokenable_type' => $isMachine ? null : $request->user()->getMorphClass(),
            'tokenable_id' => $isMachine ? null : $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'token_type' => $isMachine ? ApiToken::TYPE_CLIENT : ApiToken::TYPE_PERSONAL,
            'client_id' => $isMachine ? 'cid_'.Str::random(24) : null,
            'token' => hash('sha256', $plain),
            'abilities' => $validated['scopes'],
            'expires_at' => $expiresAt,
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 120),
            'created_by' => $request->user()->id,
        ]);

        return back()
            ->with('success', 'Token issued'
                .($token->expires_at ? ', expiring '.$token->expires_at->toDayDateTimeString() : '')
                .'. Copy it now — it is not shown again.')
            ->with('revealed_token', $token->id.'|'.$plain)
            ->with('revealed_token_for', $token->name);
    }

    public function destroy(Request $request, ApiToken $token)
    {
        // Your own, or anybody's with api.tokens.manage — ApiTokenPolicy.
        Gate::authorize('revoke', $token);

        // Revoked, not deleted. The row is the record that the token existed,
        // what it could do and when it was last used — which is the first thing
        // asked for after an incident.
        $token->revoke($request->user());

        return back()->with('success', 'Token revoked. Any request presenting it is now refused.');
    }
}
