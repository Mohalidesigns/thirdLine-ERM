<?php

namespace App\Services\Bcms\Identity;

use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\SyncChangeKind;
use App\Exceptions\Bcms\DirectorySyncException;
use App\Models\Bcms\Contact;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Support\Bcms\DirectoryUser;
use Illuminate\Support\Facades\Log;

/**
 * Directory vs roster → joiner / leaver / mover / contact_change (ADR 0018
 * §2.4, §3.4).
 *
 * MATCH KEY: `ad_object_guid`, then `employee_id`, then `email` (ADR 0018
 * §3.4 "Source"), scoped to the connector's organisation.
 *
 * A DIFFERENCE IS STAGED ONLY WHEN IT IS ONE. A directory value identical to
 * the live one produces no row at all — the queue exists to show what
 * changed, not to re-confirm what did not.
 *
 * `manager_object_id` IS CARRIED AS DIRECTORY DATA, NOT RESOLVED HERE.
 * Resolving it to a `bcms_contacts.id` needs every contact this run touches
 * to already exist, which is only true after `ChangeApplier` has applied the
 * run's joiners — so detection stages the raw Graph object id under the
 * internal `_manager_object_id` key and the applier resolves it.
 */
class ChangeDetector
{
    /**
     * A FULL reconciliation read. `$directoryUsers` may be a live provider
     * generator that raises a {@see DirectorySyncException} partway through
     * paging — caught HERE rather than pre-drained by the caller into an
     * array first (gate 2 advisory 3), so a 5,000-user read is consumed
     * lazily, one at a time, instead of fully materialised in memory before
     * detection can even start. Everything already yielded before the
     * failure is still staged. A read that stopped early must never sweep
     * for leavers below — every directory-sourced contact not yet reached
     * would read as gone — so the sweep's own condition is "no failure was
     * caught during this call", not a flag the caller had to compute by
     * already knowing the answer up front.
     *
     * @param  iterable<int, DirectoryUser>  $directoryUsers
     * @return array{changes: list<array<string, mixed>>, unmatched_departments: list<string>, failure: ?DirectorySyncException}
     */
    public function detectFullReconciliation(IdentityConnector $connector, iterable $directoryUsers): array
    {
        $organizationId = (int) $connector->organization_id;
        $map = $connector->effectiveAttributeMap();

        // Three keyed maps, built ONCE per run rather than a business-unit,
        // site and up-to-three contact query PER directory user (gate 2
        // advisory 5/6). Nothing in this method writes to the roster it
        // reads — detection only ever stages rows, `ChangeApplier` is what
        // applies them, after detection has finished entirely — so a map
        // built once at the top stays correct for every user in this same
        // read.
        $roster = $this->preloadRoster($organizationId);

        $seenObjectIds = [];
        $unmatchedDepartments = [];
        $changes = [];
        $failure = null;

        try {
            foreach ($directoryUsers as $directoryUser) {
                $seenObjectIds[$directoryUser->objectId] = true;

                [$mapped, $unmatchedDepartment] = $this->mapDirectoryUser($roster, $directoryUser, $map);

                if ($unmatchedDepartment !== null) {
                    $unmatchedDepartments[$unmatchedDepartment] = true;
                }

                $existing = $this->findExistingContact($roster, $directoryUser);

                $changes[] = $existing === null
                    ? $this->joiner($directoryUser, $mapped, $organizationId)
                    : $this->diffAgainstExisting($existing, $directoryUser, $mapped);
            }
        } catch (DirectorySyncException $e) {
            $failure = $e;
        }

        // Leavers: every currently active, directory-sourced contact already
        // in `$roster` (gate 2 advisory 4 — no second query over a table
        // this method already read in full for the match-key maps above)
        // whose `ad_object_guid` was not seen on this full read.
        if ($failure === null) {
            foreach ($roster['contacts_by_guid'] as $guid => $contact) {
                if (! $contact->is_active || $contact->source !== ContactSource::Entra) {
                    continue;
                }

                if (isset($seenObjectIds[$guid])) {
                    continue;
                }

                $changes[] = $this->leaver($contact);
            }
        }

        $changes = array_values(array_filter($changes));

        return [
            'changes' => $changes,
            'unmatched_departments' => array_keys($unmatchedDepartments),
            'failure' => $failure,
        ];
    }

    /**
     * A 15-minute delta read. NEVER STAGES A MOVER AND NEVER RESOLVES A
     * MANAGER EDGE (ADR 0018 §3.1): delta carries attribute changes and
     * enable/disable flips only. An organisational-position change surfaces
     * here as an ordinary `contact_change` on the fields that DID come
     * through (name, title, contact details); the department and manager
     * edge wait for the next full reconciliation, which is what actually
     * keeps the hierarchy true.
     *
     * @param  iterable<int, DirectoryUser>  $directoryUsers
     * @return array{changes: list<array<string, mixed>>, unmatched_departments: list<string>}
     */
    public function detectDelta(IdentityConnector $connector, iterable $directoryUsers): array
    {
        $organizationId = (int) $connector->organization_id;
        $map = $connector->effectiveAttributeMap();
        $roster = $this->preloadRoster($organizationId);
        $changes = [];

        foreach ($directoryUsers as $directoryUser) {
            [$mapped] = $this->mapDirectoryUser($roster, $directoryUser, $map);
            unset($mapped['business_unit_id'], $mapped['site_id']);

            $existing = $this->findExistingContact($roster, $directoryUser);

            if ($existing === null) {
                // A JOINER NEEDS A FULL OBJECT (ADR 0018 §3.1; gate 2
                // rejection #3, blocking defect 1b). `accountEnabled ===
                // null` means this delta entry only reported a change to
                // SOME other property (a title, a phone number) for an
                // object this connector has never matched to a contact —
                // there is no reason to believe that object is even new,
                // only that it is unfamiliar. Staging it as a joiner writes
                // whatever few fields DID come through into a brand new
                // `bcms_contacts` row, and `full_name` is NOT NULL: a
                // partial entry with no `displayName` throws on every
                // apply, failing the whole run every time the schedule
                // ticks. Counted and left alone instead — the next full
                // reconciliation carries a complete object and stages it
                // properly if it really is a joiner.
                if ($directoryUser->accountEnabled === null) {
                    Log::info('BCMS identity delta: partial entry for an unmatched directory object was not staged', [
                        'organization_id' => $organizationId,
                        'directory_object_id' => $directoryUser->objectId,
                    ]);

                    continue;
                }

                $entry = $this->joiner($directoryUser, $mapped, $organizationId);

                if ($entry !== null) {
                    unset($entry['after_json']['_manager_object_id']);
                }

                $changes[] = $entry;

                continue;
            }

            // Explicit disable only (`=== false`), never a bare falsy check
            // (gate 2 rejection #3, blocking defect 1). `null` — unreported —
            // must fall through to `diffAgainstExisting()`, which is where an
            // inactive contact's own "still disabled, no drift worth staging"
            // guard lives; conflating "unreported" with "disabled" here would
            // just move the same silent-reactivation risk one line down.
            if ($existing->is_active && $directoryUser->accountEnabled === false) {
                $changes[] = $this->leaver($existing);

                continue;
            }

            $entry = $this->diffAgainstExisting($existing, $directoryUser, $mapped, includeOrgAndManager: false);

            if ($entry !== null) {
                $entry['kind'] = SyncChangeKind::ContactChange;
                $changes[] = $entry;
            }
        }

        return ['changes' => array_values(array_filter($changes)), 'unmatched_departments' => []];
    }

    /**
     * @param  array{business_units: array<string, int>, sites: array<string, int>, contacts_by_guid: array<string, Contact>, contacts_by_employee_id: array<string, Contact>, contacts_by_email: array<string, Contact>}  $roster
     * @param  array<string, string>  $map
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function mapDirectoryUser(array $roster, DirectoryUser $user, array $map): array
    {
        $bySourceProperty = [
            'displayName' => $user->displayName,
            'employeeId' => $user->employeeId,
            'jobTitle' => $user->jobTitle,
            'department' => $user->department,
            'officeLocation' => $user->officeLocation,
            'mail' => $user->mail,
            'mobilePhone' => $user->mobilePhone ?? $user->businessPhone,
        ];

        $mapped = [];
        $unmatchedDepartment = null;

        foreach ($map as $sourceProperty => $contactField) {
            $value = $bySourceProperty[$sourceProperty] ?? null;

            if ($value === null) {
                continue;
            }

            if ($contactField === 'business_unit_id') {
                $unitId = $roster['business_units'][mb_strtolower($value)] ?? null;

                if ($unitId === null) {
                    $unmatchedDepartment = $value;

                    continue;
                }

                $mapped['business_unit_id'] = $unitId;

                continue;
            }

            if ($contactField === 'site_id') {
                $siteId = $roster['sites'][mb_strtolower($value)] ?? null;

                if ($siteId !== null) {
                    $mapped['site_id'] = $siteId;
                }

                continue;
            }

            $mapped[$contactField] = $value;
        }

        if ($user->userPrincipalName !== null && ! isset($mapped['email'])) {
            $mapped['email'] = $user->userPrincipalName;
        }

        return [$mapped, $unmatchedDepartment];
    }

    /**
     * Every business unit, site and existing contact this organisation
     * holds, read ONCE and shaped into the lookups `mapDirectoryUser()` and
     * `findExistingContact()` used to re-query per directory user (gate 2
     * advisory 5/6: a 200-user department read no longer runs up to 800
     * queries just to resolve units, sites and existing contacts — three
     * queries, once, resolve all of them).
     *
     * CONTACTS CARRYING AN `ad_object_guid` NEVER POPULATE THE EMPLOYEE-ID OR
     * EMAIL MAPS, matching the per-row queries this replaces exactly: those
     * two arms only ever matched a contact not yet linked to a directory
     * object.
     *
     * @return array{business_units: array<string, int>, sites: array<string, int>, contacts_by_guid: array<string, Contact>, contacts_by_employee_id: array<string, Contact>, contacts_by_email: array<string, Contact>}
     */
    private function preloadRoster(int $organizationId): array
    {
        $businessUnits = [];

        foreach (BusinessUnit::query()->where('organization_id', $organizationId)->get(['id', 'name', 'code']) as $unit) {
            $businessUnits[mb_strtolower($unit->name)] = $unit->getKey();

            if ($unit->code !== null) {
                $businessUnits[mb_strtolower($unit->code)] = $unit->getKey();
            }
        }

        $sites = [];

        foreach (Site::query()->where('organization_id', $organizationId)->get(['id', 'name', 'code']) as $site) {
            $sites[mb_strtolower($site->name)] = $site->getKey();

            if ($site->code !== null) {
                $sites[mb_strtolower($site->code)] = $site->getKey();
            }
        }

        $byGuid = [];
        $byEmployeeId = [];
        $byEmail = [];

        // Narrowed to the columns this class actually reads (gate 2 advisory
        // 3 — data minimisation): the un-narrowed `get()` this replaced held
        // every `bcms_contacts` column, including several from
        // `ChangeApplier::NEVER_WRITE` (`next_of_kin`, `geo_last_known`,
        // `push_token`, `whatsapp`...), in memory for the whole run for no
        // reason a directory sync has any business touching them.
        $contacts = Contact::query()
            ->where('organization_id', $organizationId)
            ->with('managerContact:id,ad_object_guid')
            ->get([
                'id', 'organization_id', 'ad_object_guid', 'employee_id', 'email', 'full_name',
                'business_unit_id', 'site_id', 'title', 'mobile_primary', 'is_active', 'source',
                'manager_contact_id',
            ]);

        foreach ($contacts as $contact) {
            if ($contact->ad_object_guid !== null) {
                $byGuid[$contact->ad_object_guid] = $contact;

                continue;
            }

            if ($contact->employee_id !== null && ! isset($byEmployeeId[$contact->employee_id])) {
                $byEmployeeId[$contact->employee_id] = $contact;
            }

            if ($contact->email !== null) {
                $emailKey = mb_strtolower($contact->email);

                if (! isset($byEmail[$emailKey])) {
                    $byEmail[$emailKey] = $contact;
                }
            }
        }

        return [
            'business_units' => $businessUnits,
            'sites' => $sites,
            'contacts_by_guid' => $byGuid,
            'contacts_by_employee_id' => $byEmployeeId,
            'contacts_by_email' => $byEmail,
        ];
    }

    /** @param  array{contacts_by_guid: array<string, Contact>, contacts_by_employee_id: array<string, Contact>, contacts_by_email: array<string, Contact>}  $roster */
    private function findExistingContact(array $roster, DirectoryUser $user): ?Contact
    {
        $byGuid = $roster['contacts_by_guid'][$user->objectId] ?? null;

        if ($byGuid !== null) {
            return $byGuid;
        }

        if ($user->employeeId !== null) {
            $byEmployeeId = $roster['contacts_by_employee_id'][$user->employeeId] ?? null;

            if ($byEmployeeId !== null) {
                return $byEmployeeId;
            }
        }

        if ($user->mail !== null) {
            return $roster['contacts_by_email'][mb_strtolower($user->mail)] ?? null;
        }

        return null;
    }

    /** @param  array<string, mixed>  $mapped */
    private function joiner(DirectoryUser $user, array $mapped, int $organizationId): ?array
    {
        // `!== true`, not a bare falsy check (gate 2 rejection #3, blocking
        // defect 1) — this now also excludes `null` ("not reported"), which
        // `detectDelta()`'s own guard above already keeps out of this method
        // for an UNMATCHED object on a DELTA read; this remains the guard
        // for the ordinary case, a disabled account (`=== false`) that never
        // existed as a contact. Either way there is nothing to leave.
        // Nothing is staged.
        if ($user->accountEnabled !== true) {
            // `null` specifically — not `false` — is logged (gate 2
            // rejection #4, advisory 2): a FULL read can also carry
            // `accountEnabled: null` (a guest/external or
            // partially-readable Graph object), and `detectFullReconciliation()`
            // has no pre-check to intercept it before this method the way
            // `detectDelta()` does above. An unmatched object with an
            // unknown enablement state is not evidence it is new, only that
            // it is unfamiliar — the same log line `detectDelta()` writes
            // for its own unmatched/unreported case, organization_id and
            // directory_object_id only, never a name, mail or UPN.
            if ($user->accountEnabled === null) {
                Log::info('BCMS identity sync: partial entry for an unmatched directory object was not staged', [
                    'organization_id' => $organizationId,
                    'directory_object_id' => $user->objectId,
                ]);
            }

            return null;
        }

        $after = $mapped;
        $after['_manager_object_id'] = $user->managerObjectId;

        return [
            'kind' => SyncChangeKind::Joiner,
            'contact_id' => null,
            'directory_object_id' => $user->objectId,
            'subject_name' => $user->displayName ?? $user->userPrincipalName ?? $user->objectId,
            'before_json' => null,
            'after_json' => $after,
        ];
    }

    /** @param  array<string, mixed>  $mapped */
    private function diffAgainstExisting(Contact $existing, DirectoryUser $user, array $mapped, bool $includeOrgAndManager = true): ?array
    {
        // ADR 0018 §3.4: a full reconciliation deactivates a contact whose
        // Entra account has been disabled, exactly as it does one whose
        // `ad_object_guid` is absent from the read entirely — the two are
        // the same "leaver" fact reaching the queue by two different Graph
        // signals. `detectDelta()` already has this branch at line 182; this is
        // its full-reconciliation twin, and it must win over any other field
        // diff below (a disabled account whose title also changed is still,
        // first and foremost, a leaver).
        //
        // `=== false`, NOT a bare falsy check (gate 2 rejection #3, blocking
        // defect 1) — a full reconciliation always carries a real bool here
        // (`$select` asks for `accountEnabled` on every read), so this is
        // unaffected in practice; written explicitly anyway, because a
        // caller reading this method's own code for how to treat the
        // tri-state property should not have to reason about implicit null
        // coercion to get the right answer.
        if ($existing->is_active && $user->accountEnabled === false) {
            return $this->leaver($existing);
        }

        // Already gone, and EITHER still disabled in Entra OR the delta that
        // reached us never reported enablement at all: no attribute drift on
        // a departed person's record is worth staging (gate 2 advisory 5) —
        // a title or department correction on an account nobody can act on
        // is queue noise, not a change anyone needs to review. `!== true`
        // (gate 2 rejection #3, blocking defect 1a) is the load-bearing part:
        // an inactive contact whose delta entry carries no `accountEnabled`
        // key (a changed job title, nothing else) must NOT be treated as
        // "reported enabled" merely because it is not reported disabled —
        // that gap is exactly how an already-departed leaver got silently
        // reactivated by an unrelated attribute drift. The reactivation
        // branch below is the one case that DOES matter for an inactive
        // contact, and it is unaffected: it only fires when `accountEnabled`
        // is POSITIVELY `true`, which this guard's own condition excludes.
        if (! $existing->is_active && $user->accountEnabled !== true) {
            return null;
        }

        $currentManagerGuid = $existing->managerContact?->ad_object_guid;
        $managerChanged = $includeOrgAndManager && $currentManagerGuid !== $user->managerObjectId;

        $orgFields = ['business_unit_id', 'site_id'];
        $personalFields = ['full_name', 'employee_id', 'title', 'email', 'mobile_primary'];

        $before = [];
        $after = [];

        foreach (array_merge($orgFields, $personalFields) as $field) {
            if (! array_key_exists($field, $mapped)) {
                continue;
            }

            $directoryValue = $mapped[$field];
            $liveValue = $existing->getAttribute($field);

            if ((string) $liveValue !== (string) $directoryValue) {
                $before[$field] = $liveValue;
                $after[$field] = $directoryValue;
            }
        }

        // `=== true`, not a bare truthy check (gate 2 rejection #3, blocking
        // defect 1a) — by the time execution reaches here, the guard above
        // has already returned for `! $existing->is_active && accountEnabled
        // !== true`, so this is provably always `true` when it is even
        // reached with `$existing->is_active` false; written explicitly
        // anyway so this line reads correctly in isolation, without a reader
        // needing to reconstruct that proof from the guard above it.
        $reactivating = ! $existing->is_active && $user->accountEnabled === true;

        if ($reactivating) {
            $before['is_active'] = false;
            $after['is_active'] = true;
        }

        $isMover = $managerChanged || array_intersect_key($after, array_flip($orgFields)) !== [];

        if ($managerChanged) {
            $after['_manager_object_id'] = $user->managerObjectId;
        }

        if ($before === [] && $after === []) {
            return null;
        }

        return [
            'kind' => $isMover ? SyncChangeKind::Mover : SyncChangeKind::ContactChange,
            'contact_id' => $existing->getKey(),
            'directory_object_id' => $user->objectId,
            'subject_name' => $user->displayName ?? $existing->full_name,
            'before_json' => $before === [] ? null : $before,
            'after_json' => $after === [] ? null : $after,
        ];
    }

    private function leaver(Contact $contact): array
    {
        return [
            'kind' => SyncChangeKind::Leaver,
            'contact_id' => $contact->getKey(),
            'directory_object_id' => (string) $contact->ad_object_guid,
            'subject_name' => $contact->full_name,
            'before_json' => [
                'full_name' => $contact->full_name,
                'business_unit_id' => $contact->business_unit_id,
                'is_active' => true,
            ],
            'after_json' => ['is_active' => false],
        ];
    }
}
