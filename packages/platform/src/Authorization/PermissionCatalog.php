<?php

namespace ThirdLine\Platform\Authorization;

/**
 * One declaration of what a product's permissions are and what they mean.
 *
 * THE FAILURE THIS PREVENTS. A permission is currently declared in as many
 * places as there are ways to create one: a seeder that runs on a fresh
 * install, and a grant migration for every deployed tenant the seeder can no
 * longer reach. Nothing checks that those lists agree, so a permission added to
 * one and forgotten in the other is granted on new installs and missing on
 * existing ones — which presents as "the screen works on staging and 403s in
 * production", months later, for one customer.
 *
 * ThirdLine has the role-shaped version of the same bug: an `Audit Supervisor`
 * role referenced by the application and created by nothing.
 *
 * So the catalog is the single source, and both the seeder and any grant
 * migration read it through SeedsPermissions rather than repeating it.
 *
 * DESCRIPTIONS ARE NOT DECORATION. `admin.configuration` and `admin.metadata`
 * both look like "administration" to whoever assigns a role, and one of them
 * rewrites the tenant's entire definition set. A catalogue of 121 bare strings
 * is a catalogue nobody can grant safely, so the description is required
 * alongside the name rather than left to a comment beside the declaration —
 * where, being a comment, it reaches no screen and no test.
 */
abstract class PermissionCatalog
{
    /**
     * Permissions grouped by the module that owns them.
     *
     * @return array<string, array<string, string>> module => [permission => description]
     */
    abstract public function modules(): array;

    /**
     * Roles and the permissions they hold.
     *
     * The value `['*']` means every permission in the catalog — for the
     * super-admin, which must not be a list that drifts behind the catalog.
     *
     * @return array<string, list<string>> role => permissions
     */
    abstract public function roles(): array;

    /**
     * Permissions every authenticated role holds.
     *
     * Granting these per-role rather than exempting their routes keeps the
     * "no route without a permission" invariant intact.
     *
     * @return list<string>
     */
    public function baseline(): array
    {
        return [];
    }

    /**
     * @return array<string, string> permission => description
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->modules() as $permissions) {
            $all += $permissions;
        }

        return $all;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function has(string $permission): bool
    {
        return array_key_exists($permission, $this->all());
    }

    public function describe(string $permission): ?string
    {
        return $this->all()[$permission] ?? null;
    }

    public function moduleOf(string $permission): ?string
    {
        foreach ($this->modules() as $module => $permissions) {
            if (array_key_exists($permission, $permissions)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_keys($this->roles());
    }

    /**
     * The permissions a role holds, baseline included and `*` expanded.
     *
     * @return list<string>
     */
    public function permissionsFor(string $role): array
    {
        $declared = $this->roles()[$role] ?? [];

        if (in_array('*', $declared, true)) {
            return $this->names();
        }

        return array_values(array_unique([...$this->baseline(), ...$declared]));
    }

    /**
     * Permissions declared on a role that the catalog does not define.
     *
     * A role granting a permission that does not exist is a silent hole: Spatie
     * creates nothing, the grant is dropped, and the role is quietly narrower
     * than it reads.
     *
     * @return list<string>
     */
    public function undefinedRoleGrants(): array
    {
        $known = $this->all();
        $unknown = [];

        foreach ($this->roles() as $permissions) {
            foreach ($permissions as $permission) {
                if ($permission !== '*' && ! array_key_exists($permission, $known)) {
                    $unknown[] = $permission;
                }
            }
        }

        foreach ($this->baseline() as $permission) {
            if (! array_key_exists($permission, $known)) {
                $unknown[] = $permission;
            }
        }

        return array_values(array_unique($unknown));
    }
}
