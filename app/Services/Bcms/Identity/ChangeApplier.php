<?php

namespace App\Services\Bcms\Identity;

use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\SyncChangeKind;
use App\Models\Bcms\Contact;
use App\Models\Bcms\IdentitySyncChange;

/**
 * THE ONLY WRITER TO `bcms_contacts` FROM A DIRECTORY SYNC — ADR 0018 §3.3,
 * §3.4. Every other service in this namespace stages; this one applies, one
 * approved or auto-applied change at a time, and only after a decision has
 * been recorded on the row.
 *
 * THE ALLOWLIST AND THE NEVER-WRITE LIST ARE CONSTANTS, ASSERTED BY A TEST,
 * NOT JUST OBSERVED IN THE CODE (work order §4). `Phase2cIdentitySyncTest`
 * pins both arrays so a future edit that widens either is a diff a reviewer
 * sees rather than a silent scope creep of what a directory read can touch.
 *
 * PROVENANCE WITHOUT A PROVENANCE COLUMN. `bcms_identity_sync_changes.
 * after_json` on every PREVIOUSLY APPLIED row for a contact is that contact's
 * synced baseline. A field is overwritten only when the contact's current
 * live value still matches that baseline (or is null) — meaning nobody has
 * touched it since the last sync. Otherwise the field is left alone: a human
 * edited it, and the sync must not silently discard that edit (ADR 0018
 * §3.4, criterion 5).
 *
 * THE MANAGER EDGE IS THE ONE ALLOWLISTED FIELD KEPT UNCONDITIONALLY IN SYNC
 * WITH THE DIRECTORY, rather than provenance-checked like the personal/role
 * fields — recorded here as the assumption it is (`docs/bcms/
 * phase-2c-notes.md` "assumptions"): this phase ships no screen that lets a
 * human hand-edit `manager_contact_id` (ADR 0018's own §1 point 1 — there is
 * no contact directory screen yet), so there is nothing to protect it from.
 * When a manager's own contact does not exist yet in this organisation (the
 * manager has not been synced as a contact), the edge is left as it was and
 * resolves on a later run once the manager exists — the same eventual
 * consistency ADR 0018 §3.1 already accepts for a delta run's manager-adjacent
 * changes.
 */
class ChangeApplier
{
    /**
     * Every `bcms_contacts` column a sync may ever write. Nothing outside
     * this list is ever touched, by construction — {@see applyJoiner()} and
     * {@see applyFieldChange()} both build their write set by intersecting
     * `after_json` with this array.
     */
    public const WRITE_ALLOWLIST = [
        'full_name', 'employee_id', 'title', 'business_unit_id', 'site_id', 'email', 'mobile_primary',
        'manager_contact_id', 'manager_user_id', 'is_active', 'ad_object_guid', 'ad_synced_at', 'source',
    ];

    /**
     * Never written, on any row, by any sync (ADR 0018 §3.4). Not consulted
     * by the writer itself — nothing in `WRITE_ALLOWLIST` overlaps it — kept
     * here so the guard test can assert the two constants are disjoint and
     * this exact list is the one the ADR names.
     */
    public const NEVER_WRITE = [
        'whatsapp', 'mobile_secondary', 'next_of_kin', 'channel_preferences', 'preferred_language',
        'consent_status', 'consent_captured_at', 'consent_withdrawn_at', 'verification_status',
        'last_verified_at', 'latitude', 'longitude', 'geo_last_known', 'user_id',
    ];

    /** Allowlisted fields that are provenance-checked before being overwritten. */
    private const PROVENANCE_PROTECTED = [
        'full_name', 'employee_id', 'title', 'business_unit_id', 'site_id', 'email', 'mobile_primary',
    ];

    /**
     * Apply one decided change. The caller (the controller action, or the
     * auto-apply path in `DirectorySyncService`) is responsible for the
     * decision itself — this method only ever runs for a row already marked
     * `approved` or about to be marked `auto_applied`.
     */
    public function apply(IdentitySyncChange $change): void
    {
        $contact = match ($change->kind) {
            SyncChangeKind::Joiner => $this->applyJoiner($change),
            SyncChangeKind::Leaver => $this->applyLeaver($change),
            SyncChangeKind::Mover, SyncChangeKind::ContactChange => $this->applyFieldChange($change),
        };

        $change->forceFill(['applied_at' => now()])->save();

        $contact?->recordAudit('contact.synced', [
            'sync_change_id' => $change->getKey(),
            'sync_run_id' => $change->sync_run_id,
            'kind' => $change->kind->value,
        ]);
    }

    private function applyJoiner(IdentitySyncChange $change): Contact
    {
        $after = (array) $change->after_json;

        $attrs = array_intersect_key($after, array_flip(self::WRITE_ALLOWLIST));
        unset($attrs['manager_contact_id'], $attrs['manager_user_id'], $attrs['is_active'],
            $attrs['ad_object_guid'], $attrs['ad_synced_at'], $attrs['source']);

        $contact = new Contact($attrs);
        $contact->organization_id = $change->organization_id;
        $contact->source = ContactSource::Entra;
        $contact->ad_object_guid = $change->directory_object_id;
        $contact->ad_synced_at = now();
        $contact->is_active = true;
        $contact->save();

        $this->resolveManagerEdge($contact, is_string($after['_manager_object_id'] ?? null) ? $after['_manager_object_id'] : null);
        $contact->save();

        $change->contact_id = $contact->getKey();

        $contact->recordAudit('contact.synced', ['reason' => 'joiner', 'sync_change_id' => $change->getKey()]);

        return $contact;
    }

    private function applyLeaver(IdentitySyncChange $change): ?Contact
    {
        $contact = $change->contact;

        if ($contact === null) {
            return null;
        }

        // Deactivated, NEVER DELETED (ADR 0018 §3.4). The row is referenced
        // by call-tree nodes, cascade test nodes, alert recipients and
        // delivery evidence.
        $contact->forceFill(['is_active' => false])->save();
        $contact->recordAudit('contact.deactivated', ['sync_change_id' => $change->getKey()]);

        return $contact;
    }

    private function applyFieldChange(IdentitySyncChange $change): ?Contact
    {
        $contact = $change->contact;

        if ($contact === null) {
            return null;
        }

        $after = (array) $change->after_json;
        $baseline = $this->baselineFor($contact);

        foreach (self::PROVENANCE_PROTECTED as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            $directoryValue = $after[$field];
            $liveValue = $contact->getAttribute($field);
            $lastSynced = $baseline[$field] ?? null;

            if ($liveValue === null || $this->valuesEqual($liveValue, $lastSynced)) {
                $contact->setAttribute($field, $directoryValue);
            }

            // Otherwise: a human has touched this field since the last sync.
            // The row stayed staged for review (that already happened at
            // approval); the value itself is left alone here.
        }

        if (array_key_exists('is_active', $after)) {
            $contact->is_active = (bool) $after['is_active'];
        }

        if (array_key_exists('_manager_object_id', $after)) {
            $this->resolveManagerEdge($contact, is_string($after['_manager_object_id']) ? $after['_manager_object_id'] : null);
        }

        $contact->source = ContactSource::Entra;
        $contact->ad_synced_at = now();
        $contact->save();

        return $contact;
    }

    private function resolveManagerEdge(Contact $contact, ?string $managerObjectId): void
    {
        if ($managerObjectId === null) {
            $contact->manager_contact_id = null;
            $contact->manager_user_id = null;

            return;
        }

        $manager = Contact::query()
            ->where('organization_id', $contact->organization_id)
            ->where('ad_object_guid', $managerObjectId)
            ->first();

        if ($manager === null) {
            // Not yet synced as a contact — resolves on a later run once they
            // are (ADR 0018 §3.1's eventual-consistency reasoning, applied
            // here). The existing edge, if any, is left as it was.
            return;
        }

        $contact->manager_contact_id = $manager->getKey();
        // Derived, never set independently (ADR 0018 §2.1): null when the
        // manager has no login, exactly like the manager contact itself.
        $contact->manager_user_id = $manager->user_id;
    }

    /**
     * Re-resolve one contact's manager edge after the rest of a run has
     * applied — `DirectorySyncService`'s second pass.
     *
     * WHY A SECOND PASS EXISTS AT ALL. Within a single full reconciliation,
     * a joiner who manages another joiner may be created after their report
     * — directory order gives no guarantee either way, and a two-person
     * management LOOP has no order that resolves both edges in one pass by
     * construction. `apply()`'s own inline resolution already catches the
     * common case (manager processed first); this catches everyone else,
     * so a single run — not "eventually, over several nights" — is what
     * actually keeps the hierarchy true whenever the run had every fact it
     * needed in the first place.
     */
    public function finalizeManagerEdge(int $contactId, ?string $managerObjectId): void
    {
        $contact = Contact::query()->find($contactId);

        if ($contact === null) {
            return;
        }

        $this->resolveManagerEdge($contact, $managerObjectId);

        if ($contact->isDirty()) {
            $contact->save();
        }
    }

    /**
     * The value each provenance-protected field was last set to by an applied
     * sync — reconstructed from `after_json` on this contact's own applied
     * change history rather than a dedicated column, which is what lets a
     * self-supplied value survive every subsequent sync without one (ADR
     * 0018 §2.4).
     *
     * @return array<string, mixed>
     */
    private function baselineFor(Contact $contact): array
    {
        return IdentitySyncChange::query()
            ->where('contact_id', $contact->getKey())
            ->whereNotNull('applied_at')
            ->orderBy('applied_at')
            ->get(['after_json'])
            ->reduce(
                fn (array $carry, IdentitySyncChange $c) => array_merge(
                    $carry,
                    array_intersect_key((array) $c->after_json, array_flip(self::PROVENANCE_PROTECTED)),
                ),
                [],
            );
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }
}
