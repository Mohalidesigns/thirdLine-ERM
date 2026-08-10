<?php

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 /Users (RFC 7644).
 *
 * Supports the operations directories actually use for lifecycle management:
 * list with a simple userName/externalId filter, create, read, replace, patch
 * (which is how Entra ID and Okta deactivate) and delete.
 *
 * DELETE deactivates rather than destroys. A user is referenced by risks they
 * own, assessments they signed and audit rows naming them as the actor;
 * removing the row would either break those references or silently rewrite
 * history.
 */
class ScimUserController extends Controller
{
    private const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Minimal filter support: `userName eq "x"` and `externalId eq "x"`
        // are what directories send when reconciling. Anything more elaborate
        // is refused rather than silently ignored — a filter that is dropped
        // instead of applied would hand the directory the whole tenant.
        if ($filter = $request->query('filter')) {
            if (! preg_match('/^\s*(userName|externalId|emails\.value)\s+eq\s+"([^"]*)"\s*$/i', (string) $filter, $m)) {
                return $this->error('Unsupported filter. Only `userName eq "..."` is implemented.', 400, 'invalidFilter');
            }

            $query->where('email', strtolower($m[2]));
        }

        $perPage = min((int) $request->query('count', 100), 200);
        $startIndex = max((int) $request->query('startIndex', 1), 1);

        $total = (clone $query)->count();
        $users = $query->orderBy('id')->skip($startIndex - 1)->take($perPage)->get();

        return $this->scim([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $users->count(),
            'Resources' => $users->map(fn (User $u) => $this->represent($u))->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $user = User::query()->find($id);

        return $user
            ? $this->scim($this->represent($user))
            : $this->error('User not found.', 404);
    }

    public function store(Request $request): JsonResponse
    {
        $email = strtolower((string) ($request->input('userName') ?? Arr::get($request->input('emails', []), '0.value', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('userName must be a valid email address.', 400, 'invalidValue');
        }

        // Uniqueness is global, not per tenant: email is the login identifier.
        // Check across tenants, but never reveal another organization's user —
        // report the conflict without echoing anything about it.
        $exists = User::query()->withoutGlobalScopes()->where('email', $email)->exists();

        if ($exists) {
            return $this->error('A user with this userName already exists.', 409, 'uniqueness');
        }

        $user = User::create([
            'organization_id' => TenantContext::organizationId(),
            'name' => $this->displayName($request, $email),
            'email' => $email,
            // SCIM never carries a usable password: these accounts sign in
            // through the IdP, or through an explicit local reset.
            'password' => Hash::make(Str::random(64)),
            'is_active' => $request->boolean('active', true),
            'job_title' => $request->input('title'),
        ]);

        $this->syncRolesFromGroups($user, $request->input('groups', []));

        return $this->scim($this->represent($user), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user) {
            return $this->error('User not found.', 404);
        }

        $user->fill([
            'name' => $this->displayName($request, $user->email),
            'is_active' => $request->boolean('active', $user->is_active),
            'job_title' => $request->input('title', $user->job_title),
        ])->save();

        if ($request->has('groups')) {
            $this->syncRolesFromGroups($user, $request->input('groups', []));
        }

        return $this->scim($this->represent($user));
    }

    /**
     * PATCH with a PatchOp body — how Entra ID and Okta deactivate a user.
     */
    public function patch(Request $request, string $id): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user) {
            return $this->error('User not found.', 404);
        }

        foreach ((array) $request->input('Operations', []) as $operation) {
            $op = strtolower((string) Arr::get($operation, 'op'));
            $path = (string) Arr::get($operation, 'path', '');
            $value = Arr::get($operation, 'value');

            if (! in_array($op, ['add', 'replace', 'remove'], true)) {
                return $this->error("Unsupported PATCH op \"{$op}\".", 400, 'invalidSyntax');
            }

            // Directories send either {path: "active", value: false} or a
            // pathless {value: {active: false}}. Accept both.
            $attributes = $path !== ''
                ? [$path => $op === 'remove' ? null : $value]
                : (array) $value;

            foreach ($attributes as $attribute => $attributeValue) {
                match (strtolower($attribute)) {
                    'active' => $user->is_active = filter_var($attributeValue, FILTER_VALIDATE_BOOLEAN),
                    'displayname', 'name.formatted' => $user->name = (string) $attributeValue,
                    'title' => $user->job_title = $attributeValue === null ? null : (string) $attributeValue,
                    default => null,
                };
            }
        }

        $user->save();

        return $this->scim($this->represent($user));
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user) {
            return $this->error('User not found.', 404);
        }

        // Deactivate, do not destroy — see the class docblock.
        $user->forceFill(['is_active' => false])->save();

        return response()->json(null, 204);
    }

    /* ------------------------------------------------------------------ */
    /*  Representation */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function represent(User $user): array
    {
        return [
            'schemas' => [self::USER_SCHEMA],
            'id' => (string) $user->id,
            'userName' => $user->email,
            'displayName' => $user->name,
            'title' => $user->job_title,
            'active' => (bool) $user->is_active,
            'emails' => [[
                'value' => $user->email,
                'primary' => true,
                'type' => 'work',
            ]],
            'groups' => $user->roles->map(fn ($role) => [
                'value' => (string) $role->id,
                'display' => $role->name,
            ])->all(),
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => url("/scim/v2/Users/{$user->id}"),
            ],
        ];
    }

    private function displayName(Request $request, string $fallbackEmail): string
    {
        return (string) (
            $request->input('displayName')
            ?: trim((string) Arr::get($request->input('name', []), 'givenName', '').' '.Arr::get($request->input('name', []), 'familyName', ''))
            ?: Arr::get($request->input('name', []), 'formatted')
            ?: Str::before($fallbackEmail, '@')
        );
    }

    /**
     * Groups in a SCIM payload are role names. They are matched against roles
     * that already exist — SCIM may assign a role, never invent one.
     *
     * @param  array<int, mixed>  $groups
     */
    private function syncRolesFromGroups(User $user, array $groups): void
    {
        $names = collect($groups)
            ->map(fn ($group) => is_array($group) ? ($group['display'] ?? $group['value'] ?? null) : $group)
            ->filter()
            ->map(fn ($name) => (string) $name);

        $roles = \Spatie\Permission\Models\Role::query()
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        $user->syncRoles($roles);
    }

    /* ------------------------------------------------------------------ */
    /*  Responses */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scim(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Content-Type' => 'application/scim+json']);
    }

    private function error(string $detail, int $status, ?string $scimType = null): JsonResponse
    {
        return response()->json(array_filter([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'status' => (string) $status,
            'scimType' => $scimType,
        ]), $status, ['Content-Type' => 'application/scim+json']);
    }
}
