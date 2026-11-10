<?php

namespace App\Services\Sm;

use App\Models\FxRate;
use App\Models\Sm\Scenario;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The scenario write path: create, and the PARTIAL update both tabs post to.
 *
 * ── THE REFERENCE IS ASSIGNED HERE, ON SAVE ONLY ──────────────────────────────
 *
 * Addendum-2: the create form shows "Assigned when you save" and posts no
 * provisional number, because `max + 1` means two designers creating at once would
 * both be shown a number one of them never gets — the screen would have displayed a
 * reference that never existed (standard §5). The Form Request refuses a posted
 * `reference`, so a stale tab cannot smuggle one in either.
 *
 * ── THE COLLISION RULE, WHICH NEITHER PRECEDENT HAS ───────────────────────────
 *
 * `Quantification\ScenarioService::nextReference()` and
 * `LossEventController`'s near-miss generator both read-then-insert with no lock and
 * no retry, so two concurrent creates get the same number and one 500s. Frozen
 * instead: the create runs in a transaction; on a `QueryException` carrying SQLSTATE
 * 23000 against `sm_scenarios_org_reference_unique`, re-read the sequence and retry,
 * AT MOST 3 ATTEMPTS; after that a FLASH MESSAGE — "We could not assign a reference.
 * Please try again." — never a 500, and never a silently different reference.
 *
 * `lockForUpdate` over the tenant's rows was refused: it serialises every create for
 * a tenant to defend a race a retry resolves in microseconds, and there is no
 * sequence row to lock. A per-tenant counter table was refused too — a sixth table,
 * and §A1.1's boundary is the point of the discipline. Dropping the unique key was
 * refused outright: the reference is what a board pack cites.
 *
 * ── `PUT {scenario}` IS A PARTIAL UPDATE ──────────────────────────────────────
 *
 * Spec 01 §6.1, and coordinator ruling 3. BOTH TABS POST TO THIS ROUTE and neither
 * loads the other's fields (§4 sends one tab's data at a time), so a whole-object
 * write from the Qualitative tab would null `background`, `rationale` and every
 * governance field — silently, on save, with a 200 response. That is standard §6's
 * write-path rule in its most dangerous form: the columns exist, the write
 * "succeeds", and the data is gone. Only the keys the Form Request validated are
 * written, and `UpdateScenarioRequest` gives every rule `sometimes` semantics.
 */
class ScenarioService
{
    /** The flash message when the reference sequence cannot be resolved. Frozen by addendum-2. */
    public const REFERENCE_UNAVAILABLE_MESSAGE = 'We could not assign a reference. Please try again.';

    /** The unique index a reference collision is reported against. */
    public const REFERENCE_INDEX = 'sm_scenarios_org_reference_unique';

    /** Addendum-2: "at most 3 attempts", then a flash and never a 500. */
    public const MAX_REFERENCE_ATTEMPTS = 3;

    public function __construct(private readonly ReferenceGenerator $references) {}

    /**
     * Create a scenario and give it its reference.
     *
     * @param  array<string, mixed>  $attributes  validated request data; `reference` is never read from it
     *
     * @throws ScenarioRefused when the sequence cannot be resolved in three attempts
     */
    public function create(array $attributes, User $actor): Scenario
    {
        $organizationId = (int) ($attributes['organization_id'] ?? TenantContext::organizationId());

        // A posted `reference` is dropped, not honoured: the sequence is read at
        // write time, so any number a client sends is a guess about a row that does
        // not exist yet (addendum-2, spec 04 §4).
        unset($attributes['reference'], $attributes['organization_id']);

        $lastCollision = null;

        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $actor, $organizationId) {
                    $scenario = Scenario::create(array_merge($attributes, [
                        'organization_id' => $organizationId,
                        'reference' => $this->references->next($organizationId),
                        'status' => Scenario::STATUSES[0],
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]));

                    AuditTrailService::record($scenario, 'create', 'reference', null, $scenario->reference);

                    return $scenario;
                });
            } catch (QueryException $e) {
                if (! self::isReferenceCollision($e)) {
                    throw $e;
                }

                // Re-read the sequence on the next pass: the row that beat us is
                // committed, so `nextNumber()` now sees it.
                $lastCollision = $e;
            }
        }

        throw new ScenarioRefused(self::REFERENCE_UNAVAILABLE_MESSAGE);
    }

    /**
     * Write only the keys present, and refuse a read-only scenario.
     *
     * PARTIAL BY CONSTRUCTION (spec 01 §6.1): both tabs post to `PUT {scenario}` and
     * neither loads the other's fields, so writing anything not in `$attributes` would
     * null the other tab's columns with a 200 response. `UpdateScenarioRequest` only
     * validates keys that were actually posted, and this method writes exactly that array.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ScenarioRefused
     */
    public function update(Scenario $scenario, array $attributes, User $actor): Scenario
    {
        $this->refuseIfReadOnly($scenario);

        // Never reachable through a Form Request, and refused here anyway: the
        // reference is what a board pack cites and there is no rename (spec 02 §5.1).
        unset($attributes['reference'], $attributes['organization_id'], $attributes['status']);

        // `validated_on` is stamped by the lifecycle on the `validated` transition
        // and nobody edits it (spec 02 §4): a designer who can type a validation date
        // can type a date nobody validated anything on.
        unset($attributes['validated_on']);

        // `fx_rate` and `fx_rate_date` are a SERVER-WRITTEN SNAPSHOT, never posted.
        if (array_key_exists('fx_rate_id', $attributes)) {
            $attributes = $this->withFxSnapshot($scenario, $attributes);
        }

        if ($attributes === []) {
            return $scenario;
        }

        $original = $scenario->getOriginal();

        $scenario->fill($attributes);
        $scenario->updated_by = $actor->id;
        $scenario->save();

        AuditTrailService::recordChanges($scenario, $original);

        return $scenario;
    }

    /**
     * Copy the chosen `fx_rates` row's rate and date onto the scenario.
     *
     * §A1.2 stores THREE FX columns, not one: `fx_rate_id` is PROVENANCE (which row was
     * chosen) and `fx_rate` + `fx_rate_date` are the SNAPSHOT that D1/§4.3 require,
     * because `fx_rates` rows are per-tenant and a platform rate can be superseded — a
     * signed-off figure must not move when the rate table does. Spec 02 §5.6 states the
     * user-facing half: "Picking a rate on this screen writes all three."
     *
     * Taking the values from the ROW rather than from the request is also what stops a
     * client inventing a rate: there is no `fx_rate` rule in `UpdateScenarioRequest`, so
     * a posted one could not reach `validated()` even if the field existed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withFxSnapshot(Scenario $scenario, array $attributes): array
    {
        $rateId = $attributes['fx_rate_id'];

        if ($rateId === null || $rateId === '') {
            // Clearing the provenance must clear the snapshot too, or the scenario is left
            // asserting a rate nothing points at — the drift §A1.2's three columns exist
            // to prevent, in the opposite direction.
            $attributes['fx_rate'] = null;
            $attributes['fx_rate_date'] = null;

            return $attributes;
        }

        // Tenant-bound read: `FxRate` carries BelongsToOrganization, so a rate belonging
        // to another tenant resolves to null here exactly as the Form Request's
        // tenant-bound Rule::exists refused it moments earlier. Two checks, one rule.
        $rate = FxRate::query()
            ->where('organization_id', $scenario->organization_id)
            ->find((int) $rateId);

        if ($rate === null) {
            $attributes['fx_rate'] = null;
            $attributes['fx_rate_date'] = null;

            return $attributes;
        }

        $attributes['fx_rate'] = (string) $rate->rate;
        $attributes['fx_rate_date'] = $rate->rate_date?->toDateString();

        return $attributes;
    }

    /**
     * Standard §3 / spec 01 §5.5: from `in_validation` onward the scenario is
     * read-only to everyone except through a lifecycle transition, because "a
     * validator reading a record the designer can still change is validating
     * nothing". A flash, not a 403 — the user is allowed to try.
     *
     * NOTE THERE IS NO SOFT-DELETE SERVICE METHOD IN PHASE 1. §A1.11's frozen route
     * table registers no DELETE route, and the workspace's "Archive" button is the
     * `archived` STATUS transition, not a deletion. `ScenarioPolicy::delete()` exists
     * because §A1.10 named it as the home soft delete would use under
     * `scenario.update` — but nothing calls it yet, and inventing a route the freeze
     * does not list is exactly the scope breach §A1.13 warns about.
     *
     * @throws ScenarioRefused
     */
    public function refuseIfReadOnly(Scenario $scenario): void
    {
        if (! $scenario->isReadOnly()) {
            return;
        }

        throw new ScenarioRefused(match ($scenario->status) {
            'archived' => 'This scenario is archived. It stays on the audit trail and appears in no live list.',
            'signed_off' => 'This scenario is signed off and frozen. To change it, reopen it for revalidation.',
            'validated' => 'This scenario is validated and read-only. Return it for rework to change it.',
            default => "This scenario is in validation. You can't change it until it is validated or returned for rework.",
        });
    }

    /**
     * Is this a collision on OUR unique index, rather than some other integrity
     * failure?
     *
     * Narrow on purpose. Swallowing every 23000 would turn a genuine foreign-key
     * violation into three pointless retries and then a message about references
     * that says nothing true — and retrying a duplicate on some OTHER unique index
     * three times is just as wrong. Both the SQLSTATE and the index name are
     * matched: MariaDB words the duplicate-key message as
     * `… for key 'sm_scenarios_org_reference_unique'` and MySQL 8 as
     * `… for key 'sm_scenarios.sm_scenarios_org_reference_unique'`, so a substring
     * test on the index name is the portable spelling.
     *
     * `UniqueConstraintViolationException` extends `QueryException`, so catching the
     * parent in `create()` covers both without a second branch.
     */
    public static function isReferenceCollision(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            && str_contains($exception->getMessage(), self::REFERENCE_INDEX);
    }
}
