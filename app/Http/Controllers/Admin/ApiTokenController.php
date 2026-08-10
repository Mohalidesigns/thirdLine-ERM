<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        return view('admin.api-tokens.index', [
            // Without api.tokens.manage a user sees only their own tokens.
            // Listing everyone's would show which integrations exist and when
            // they last ran, which is reconnaissance in itself.
            'tokens' => ApiToken::query()
                ->when(! $canManage, fn ($q) => $q->where('tokenable_id', $request->user()->id))
                ->with('creator', 'revoker')
                ->latest()
                ->get(),
            'canManage' => $canManage,
            'scopes' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|max:100',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
            'token_type' => 'nullable|in:personal,client_credentials',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:10000',
        ]);

        $isMachine = ($validated['token_type'] ?? 'personal') === ApiToken::TYPE_CLIENT;

        if ($isMachine && ! $request->user()->can('api.tokens.manage')) {
            return back()->with('error',
                'A machine token acts as no user, so its scopes are its whole authority. '
                .'Issuing one needs the api.tokens.manage permission.');
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
            'expires_at' => isset($validated['expires_in_days'])
                ? now()->addDays((int) $validated['expires_in_days'])
                : null,
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 120),
            'created_by' => $request->user()->id,
        ]);

        return back()
            ->with('success', 'Token issued. Copy it now — it is not shown again.')
            ->with('revealed_token', $token->id.'|'.$plain)
            ->with('revealed_token_for', $token->name);
    }

    public function destroy(Request $request, ApiToken $token)
    {
        abort_unless($token->organization_id === TenantContext::organizationId(), 403);

        // Your own, or anybody's with api.tokens.manage.
        abort_unless(
            $token->tokenable_id === $request->user()->id || $request->user()->can('api.tokens.manage'),
            403,
            'That token belongs to somebody else.',
        );

        // Revoked, not deleted. The row is the record that the token existed,
        // what it could do and when it was last used — which is the first thing
        // asked for after an incident.
        $token->revoke($request->user());

        return back()->with('success', 'Token revoked. Any request presenting it is now refused.');
    }
}
