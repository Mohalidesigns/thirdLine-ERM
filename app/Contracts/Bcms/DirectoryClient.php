<?php

namespace App\Contracts\Bcms;

use App\Models\Bcms\IdentityConnector;
use App\Support\Bcms\DirectoryUser;

/**
 * The only surface the identity sync knows (ADR 0018 §4).
 *
 * THERE IS NO WRITE METHOD AND THERE WILL NOT BE ONE. Standing rule 3 is
 * non-negotiable, and "the reviewer confirms by inspection" is not an
 * enforcement mechanism — this interface having nothing to call is one third
 * of the enforcement (ADR 0018 §3.2); the other two are
 * `Phase2cReadOnlyGuardTest` and the `User.Read.All` scope request itself.
 *
 * NO GRAPH VOCABULARY IN THE SIGNATURE. `App\Services\Bcms\Identity\
 * EntraGraphClient` implements this over Microsoft Graph; 2D's LDAPS and HRIS
 * bridge implement the same interface without inheriting an `$odata` anywhere
 * in a method name.
 */
interface DirectoryClient
{
    /**
     * A full read of every in-scope directory object, paged internally.
     *
     * @return iterable<int, DirectoryUser>
     */
    public function users(IdentityConnector $connector, ?string $filter = null): iterable;

    /**
     * The subset of users the provider believes changed since `$deltaLink`
     * (or every user, the first time). Carries attribute changes and enable/
     * disable flips; it does NOT carry relationships — a delta run never
     * re-resolves a manager edge (ADR 0018 §3.1).
     *
     * @return array{users: iterable<int, DirectoryUser>, delta_link: ?string}
     */
    public function delta(IdentityConnector $connector, ?string $deltaLink = null): array;

    /**
     * The directory object id of this user's manager, or null.
     *
     * A "not found" answer from the provider means "no manager", not a
     * failure — the trap that would otherwise mark every executive's run as
     * partial (ADR 0018 §3.1).
     */
    public function managerObjectId(IdentityConnector $connector, string $objectId): ?string;

    /**
     * Token plus one page of one user, with no write and no staging rows.
     *
     * @return array{ok: bool, scopes: list<string>, sample_count: int, error_class: ?string, error_code: ?string}
     */
    public function testConnection(IdentityConnector $connector): array;
}
