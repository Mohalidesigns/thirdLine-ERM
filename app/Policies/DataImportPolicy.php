<?php

namespace App\Policies;

use App\Models\DataImport;
use App\Models\User;

/**
 * Who may bring bulk data into the register (migration Phase 5.5).
 *
 * UPLOADING IS NOT PROCESSING, and the seeded set has always said so:
 * `import.create` and `import.process` are separate permissions and the routes
 * carry them separately. What was missing is anything asking the question about
 * a PARTICULAR import — `processImport()` checked the tenant by hand and
 * nothing checked the permission beyond route middleware.
 *
 * The distinction is worth keeping. Uploading a spreadsheet stages a file and
 * reads its headers; processing writes every row of it into the register,
 * irreversibly and in bulk. A fifty-thousand-row risk import is the largest
 * single write anyone can make to this product.
 *
 * Reach is the tenant. Gate::before grants super-admin every ability first.
 */
class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('import.view');
    }

    public function view(User $user, DataImport $import): bool
    {
        return $user->can('import.view') && $this->sameTenant($user, $import);
    }

    /** Stage a spreadsheet and read its headers. */
    public function create(User $user): bool
    {
        return $user->can('import.create');
    }

    /** Write its rows into the register. */
    public function process(User $user, DataImport $import): bool
    {
        return $user->can('import.process') && $this->sameTenant($user, $import);
    }

    private function sameTenant(User $user, DataImport $import): bool
    {
        return (int) $import->organization_id === (int) $user->organization_id;
    }
}
