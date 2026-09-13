<?php

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;

/**
 * SCIM 2.0 /Groups (RFC 7644).
 *
 * A SCIM group is an application role. Roles are read-only over SCIM —
 * membership can be changed, the set of roles cannot.
 *
 * That is deliberate. Roles are bound to permissions by the seeder, and a
 * directory administrator who could POST a new group would be creating an
 * authorization principal with no permissions and no review. Membership is the
 * part that genuinely belongs to the directory.
 */
class ScimGroupController extends Controller
{
    private const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    public function index(Request $request): JsonResponse
    {
        $query = Role::query();

        if ($filter = $request->query('filter')) {
            if (! preg_match('/^\s*displayName\s+eq\s+"([^"]*)"\s*$/i', (string) $filter, $m)) {
                return $this->error('Unsupported filter. Only `displayName eq "..."` is implemented.', 400, 'invalidFilter');
            }

            $query->where('name', $m[1]);
        }

        $roles = $query->orderBy('id')->get();

        return $this->scim([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $roles->count(),
            'startIndex' => 1,
            'itemsPerPage' => $roles->count(),
            'Resources' => $roles->map(fn (Role $role) => $this->represent($role))->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $role = Role::query()->find($id);

        return $role
            ? $this->scim($this->represent($role))
            : $this->error('Group not found.', 404);
    }

    /**
     * PATCH members — the operation directories use to grant and revoke roles.
     */
    public function patch(Request $request, string $id): JsonResponse
    {
        $role = Role::query()->find($id);

        if (! $role) {
            return $this->error('Group not found.', 404);
        }

        foreach ((array) $request->input('Operations', []) as $operation) {
            $op = strtolower((string) Arr::get($operation, 'op'));
            $path = strtolower((string) Arr::get($operation, 'path', ''));

            if ($path !== '' && $path !== 'members') {
                return $this->error("Only the `members` attribute is writable on a group; got \"{$path}\".", 400, 'mutability');
            }

            $memberIds = collect(Arr::wrap(Arr::get($operation, 'value', [])))
                ->map(fn ($member) => is_array($member) ? Arr::get($member, 'value') : $member)
                ->filter()
                ->all();

            // Resolved through the tenant-scoped query, so a token for one
            // organization cannot grant a role to another organization's user.
            $users = User::query()->whereIn('id', $memberIds)->get();

            match ($op) {
                'add' => $users->each(fn (User $u) => $u->assignRole($role->name)),
                'remove' => $users->each(fn (User $u) => $u->removeRole($role->name)),
                'replace' => $this->replaceMembers($role, $users->pluck('id')->all()),
                default => null,
            };

            if (! in_array($op, ['add', 'remove', 'replace'], true)) {
                return $this->error("Unsupported PATCH op \"{$op}\".", 400, 'invalidSyntax');
            }
        }

        return $this->scim($this->represent($role->fresh()));
    }

    /**
     * @param  list<int>  $userIds
     */
    private function replaceMembers(Role $role, array $userIds): void
    {
        // Only touches users this token's organization can see, so a replace
        // cannot strip the role from another tenant's members.
        User::query()->whereNotIn('id', $userIds)->get()
            ->each(fn (User $u) => $u->removeRole($role->name));

        User::query()->whereIn('id', $userIds)->get()
            ->each(fn (User $u) => $u->assignRole($role->name));
    }

    /**
     * @return array<string, mixed>
     */
    private function represent(Role $role): array
    {
        $members = User::query()
            ->whereHas('roles', fn ($q) => $q->where('id', $role->id))
            ->get(['id', 'name', 'email']);

        return [
            'schemas' => [self::GROUP_SCHEMA],
            'id' => (string) $role->id,
            'displayName' => $role->name,
            'members' => $members->map(fn (User $u) => [
                'value' => (string) $u->id,
                'display' => $u->name,
                '$ref' => url("/scim/v2/Users/{$u->id}"),
            ])->all(),
            'meta' => [
                'resourceType' => 'Group',
                'location' => url("/scim/v2/Groups/{$role->id}"),
            ],
        ];
    }

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
