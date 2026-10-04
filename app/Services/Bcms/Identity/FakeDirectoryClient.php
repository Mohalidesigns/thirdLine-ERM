<?php

namespace App\Services\Bcms\Identity;

use App\Contracts\Bcms\DirectoryClient;
use App\Contracts\Bcms\ReportsDirectoryFetchStats;
use App\Models\Bcms\IdentityConnector;
use App\Support\Bcms\DirectoryUser;

/**
 * `DirectoryClient` from an in-memory fixture — what every test and
 * `IdentityDemoSeeder` run against (ADR 0018 §4, §6 criterion 1).
 *
 * NO NETWORK, EVER. There is nothing here for `Http::preventStrayRequests()`
 * to catch because nothing here calls `Http`. Building the real client
 * (`EntraGraphClient`) and the mock complete together, before any Entra tenant
 * exists to point at (standing rule 7).
 *
 * PAGING IS REAL, NOT SIMULATED. `withPageSize()` sets how many users a
 * single internal "page" yields before `lastFetchStats()['pages']`
 * increments, so a test can set it below the fixture size and assert
 * `pages_fetched > 1` exactly as ADR 0018 §6 criterion 1 asks — "paging is
 * proven by lowering $top, not by inflating the fixture".
 */
class FakeDirectoryClient implements DirectoryClient, ReportsDirectoryFetchStats
{
    /** @var list<DirectoryUser> */
    private array $users = [];

    private int $pageSize = 50;

    private int $pagesFetched = 0;

    private int $objectsRead = 0;

    /** @var list<string> objectIds a delta() call reports as changed */
    private array $deltaChangedObjectIds = [];

    private ?string $nextDeltaLink = 'fake-delta-cursor';

    private bool $throwOnNextCall = false;

    /**
     * @param  iterable<int, DirectoryUser>  $users
     */
    public function __construct(iterable $users = [])
    {
        $this->setUsers($users);
    }

    /** @param  iterable<int, DirectoryUser>  $users */
    public function setUsers(iterable $users): static
    {
        $this->users = $users instanceof \Traversable ? iterator_to_array($users, false) : (array) $users;

        return $this;
    }

    public function withPageSize(int $size): static
    {
        $this->pageSize = max(1, $size);

        return $this;
    }

    /**
     * @param  list<string>  $objectIds
     */
    public function withDeltaChanges(array $objectIds, ?string $nextDeltaLink = null): static
    {
        $this->deltaChangedObjectIds = $objectIds;
        $this->nextDeltaLink = $nextDeltaLink ?? $this->nextDeltaLink;

        return $this;
    }

    /**
     * A PARTIAL delta entry for `$objectId` — exactly what Graph's own
     * `/users/delta` sends when only the given properties changed since the
     * last delta query (gate 2 rejection #3, blocking defect 1): every
     * property this test did not name, `accountEnabled` included, is
     * reported as `null` ("not reported"), never coerced to a default.
     * Replaces any full `DirectoryUser` already present for this object id
     * in `setUsers()`, so a test's setup roster and its delta payload do not
     * have to be kept in sync by hand — the whole point of a partial entry
     * is that it is NOT the full object the roster may already hold.
     *
     * @param  array{userPrincipalName?: ?string, displayName?: ?string, mail?: ?string, mobilePhone?: ?string, businessPhone?: ?string, jobTitle?: ?string, department?: ?string, officeLocation?: ?string, employeeId?: ?string}  $changedProperties
     */
    public function withPartialDeltaEntry(string $objectId, array $changedProperties = []): static
    {
        $this->partialDeltaEntries[$objectId] = $changedProperties;

        return $this;
    }

    /**
     * A `@removed` tombstone for `$objectId` on the next `delta()` call —
     * what `EntraGraphClient::delta()` has already collapsed a real Graph
     * tombstone into by the time anything downstream of the client sees it
     * (gate 2 rejection #3, blocking defect 1c): `accountEnabled: false`,
     * every other property unreported, whichever of Graph's own `changed`
     * (soft-deleted / moved out of scope) or `deleted` (hard-deleted)
     * reasons produced it. Replaces any full `DirectoryUser` already present
     * for this object id in `setUsers()` — a tombstone by definition means
     * the object no longer has a live representation to report.
     *
     * @param  list<string>  $objectIds
     */
    public function withRemovedTombstones(array $objectIds, ?string $nextDeltaLink = null): static
    {
        $this->removedTombstoneObjectIds = $objectIds;
        $this->nextDeltaLink = $nextDeltaLink ?? $this->nextDeltaLink;

        return $this;
    }

    /** @var array<string, array<string, ?string>> objectId => changed DirectoryUser properties */
    private array $partialDeltaEntries = [];

    /** @var list<string> objectIds reported as `@removed` tombstones on the next delta() call */
    private array $removedTombstoneObjectIds = [];

    /**
     * Make the very next `users()`/`delta()` iteration throw partway through
     * — the fixture equivalent of a Graph 503 mid-page, for a test that
     * exercises `partial` without touching HTTP at all.
     */
    public function failAfter(int $objectCount, string $errorClass, ?string $errorCode): static
    {
        $this->failAfterCount = $objectCount;
        $this->failErrorClass = $errorClass;
        $this->failErrorCode = $errorCode;
        $this->throwOnNextCall = true;

        return $this;
    }

    private int $failAfterCount = 0;

    private string $failErrorClass = 'graph_http_503';

    private ?string $failErrorCode = '503';

    public function users(IdentityConnector $connector, ?string $filter = null): iterable
    {
        $this->pagesFetched = 0;
        $this->objectsRead = 0;

        $chunks = array_chunk($this->users, $this->pageSize);

        foreach ($chunks as $chunk) {
            $this->pagesFetched++;

            foreach ($chunk as $user) {
                $this->objectsRead++;

                if ($this->throwOnNextCall && $this->objectsRead > $this->failAfterCount) {
                    $this->throwOnNextCall = false;

                    throw new \App\Exceptions\Bcms\DirectorySyncException(
                        $this->failErrorClass,
                        $this->failErrorCode,
                        $this->objectsRead - 1,
                    );
                }

                yield $user;
            }
        }
    }

    /**
     * @return array{users: iterable<int, DirectoryUser>, delta_link: ?string}
     */
    public function delta(IdentityConnector $connector, ?string $deltaLink = null): array
    {
        $this->pagesFetched = 1;

        $changed = $this->deltaChangedObjectIds === []
            ? $this->users
            : array_values(array_filter($this->users, fn (DirectoryUser $u) => in_array($u->objectId, $this->deltaChangedObjectIds, true)));

        foreach ($this->partialDeltaEntries as $objectId => $changedProperties) {
            $changed = $this->replaceOrAppend($changed, $objectId, $this->partialDeltaUser($objectId, $changedProperties));
        }

        foreach ($this->removedTombstoneObjectIds as $objectId) {
            $changed = $this->replaceOrAppend($changed, $objectId, $this->tombstoneUser($objectId));
        }

        $this->objectsRead = count($changed);

        return ['users' => $changed, 'delta_link' => $this->nextDeltaLink];
    }

    /**
     * @param  list<DirectoryUser>  $users
     * @return list<DirectoryUser>
     */
    private function replaceOrAppend(array $users, string $objectId, DirectoryUser $replacement): array
    {
        $users = array_values(array_filter($users, fn (DirectoryUser $u) => $u->objectId !== $objectId));
        $users[] = $replacement;

        return $users;
    }

    /** @param  array<string, ?string>  $changedProperties */
    private function partialDeltaUser(string $objectId, array $changedProperties): DirectoryUser
    {
        return new DirectoryUser(
            objectId: $objectId,
            userPrincipalName: $changedProperties['userPrincipalName'] ?? null,
            displayName: $changedProperties['displayName'] ?? null,
            mail: $changedProperties['mail'] ?? null,
            mobilePhone: $changedProperties['mobilePhone'] ?? null,
            businessPhone: $changedProperties['businessPhone'] ?? null,
            jobTitle: $changedProperties['jobTitle'] ?? null,
            department: $changedProperties['department'] ?? null,
            officeLocation: $changedProperties['officeLocation'] ?? null,
            employeeId: $changedProperties['employeeId'] ?? null,
            // Unreported, by definition of "partial" — the one property this
            // helper never lets a caller override, because a test that needs
            // an explicit `true`/`false` is testing an ordinary delta entry,
            // not a partial one.
            accountEnabled: null,
            managerObjectId: null,
        );
    }

    private function tombstoneUser(string $objectId): DirectoryUser
    {
        return new DirectoryUser($objectId, null, null, null, null, null, null, null, null, null, false, null);
    }

    public function managerObjectId(IdentityConnector $connector, string $objectId): ?string
    {
        foreach ($this->users as $user) {
            if ($user->objectId === $objectId) {
                return $user->managerObjectId;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, scopes: list<string>, sample_count: int, error_class: ?string, error_code: ?string}
     */
    public function testConnection(IdentityConnector $connector): array
    {
        return [
            'ok' => true,
            'scopes' => ['User.Read.All'],
            'sample_count' => $this->users === [] ? 0 : 1,
            'error_class' => null,
            'error_code' => null,
        ];
    }

    /** @return array{pages: int, objects: int} */
    public function lastFetchStats(): array
    {
        return ['pages' => $this->pagesFetched, 'objects' => $this->objectsRead];
    }
}
