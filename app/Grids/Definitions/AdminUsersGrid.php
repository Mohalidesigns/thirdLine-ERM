<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * User administration (WP-09 migration of
 * resources/views/admin/users/index.blade.php).
 *
 * Schema notes (verified against 2026_02_22_200004_add_profile_fields_to_users_table
 * and 2026_02_24_000001_add_auth_fields_to_users_table): there is no `status`
 * column — the register's Status is the `is_active` boolean — and MFA is
 * `mfa_enabled`, last sign-in `last_login_at`. All three are cast on the model.
 *
 * Tenancy: User carries BelongsToOrganization, so the global scope already
 * confines reads to the current tenant; the explicit where() is stated anyway
 * because a definition's base query is the security boundary the engine trusts
 * and it should not depend on a trait staying attached.
 *
 * Actions: activation/deactivation stays a POST on the user's own page rather
 * than becoming a bulk action — the controller refuses to let you toggle your
 * own account, which is a per-actor rule a bulk handler would have to
 * re-implement, and the row already links there.
 */
class AdminUsersGrid extends GridDefinition
{
    public function name(): string
    {
        return 'admin_users';
    }

    public function permission(): string
    {
        return 'admin.users';
    }

    public function query(): Builder
    {
        return User::query()
            ->with(['roles', 'businessUnit'])
            ->where('users.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('name', 'User')
                ->sortable()->searchable()
                ->linkTo(fn (User $u) => route('admin.users.show', $u)),

            Column::make('email', 'Email')
                ->sortable()->searchable()
                ->hiddenByDefault(),

            Column::make('staff_id', 'Staff ID')
                ->sortable()->searchable()
                ->using(fn (User $u) => $u->staff_id ?: '—'),

            // Spatie roles are a many-to-many; eager-loaded above so the list
            // costs one query for the page rather than one per row.
            Column::make('roles', 'Role')
                ->using(fn (User $u) => $u->roles->isEmpty()
                    ? 'No role'
                    : $u->roles
                        ->map(fn ($role) => ucwords(str_replace('-', ' ', $role->name)))
                        ->implode(', ')),

            Column::make('businessUnit.name', 'Business Unit'),

            Column::make('is_active', 'Status')
                ->sortable()
                ->using(fn (User $u) => $u->is_active ? 'active' : 'inactive')
                ->rag([
                    'active' => 'green',
                    'inactive' => 'neutral',
                ]),

            Column::make('mfa_enabled', 'MFA')
                ->sortable()
                ->using(fn (User $u) => $u->mfa_enabled ? '✓' : '—'),

            Column::make('last_login_at', 'Last Login')
                ->sortable()
                ->datetime('d M Y H:i'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('role', 'All Roles')
                ->options(fn () => Role::orderBy('name')
                    ->pluck('name')
                    ->mapWithKeys(fn ($name) => [$name => ucwords(str_replace('-', ' ', $name))])
                    ->all())
                // Spatie ships the scope; using it keeps guard handling and the
                // roles pivot join in one place.
                ->apply(fn (Builder $query, string $value) => $query->role($value)),

            Filter::make('status', 'All Status')
                ->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ])
                ->apply(fn (Builder $query, string $value) => $query
                    ->where('users.is_active', $value === 'active')),

            Filter::make('business_unit', 'All Units')
                ->column('users.business_unit_id')
                ->options(fn () => BusinessUnit::where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (User $u) => route('admin.users.show', $u)),
            RowAction::make('Edit', 'edit', fn (User $u) => route('admin.users.edit', $u)),
        ];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No users found.';
    }

    public function emptyIcon(): string
    {
        return 'group_off';
    }
}
