<?php

namespace App\Services\Bcms\Dr;

use App\Enums\Bcms\DependencyType;
use App\Models\ApiToken;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Process;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The IT DR register, its cadence and its evidence (clause map §3 in full).
 *
 * WE GOVERN AND EVIDENCE FAILOVER; WE DO NOT EXECUTE IT (Blueprint §3.3). This
 * class writes register entries, test results and attestations — never a
 * command to Zerto, Veeam or Azure Site Recovery.
 *
 * A REAL INVOCATION IS NEVER WRITTEN HERE (clause map §3.4, ADR 0020 §4).
 * There is no method on this class that takes an incident — that is
 * structural, not a convention: the actuals for a real failover live in the
 * PIR's `quantitative_results`, and this service has no path to write them
 * into `bcms_dr_tests`.
 *
 * CADENCE IS RESOLVED IN PHP, NEVER WITH A RAW JSON FUNCTION (clause map
 * §3.3's own MariaDB warning, `CalendarService.php:410`'s documented trap).
 * `regulatory_flags` rows are fetched scoped, then checked with `in_array()`.
 */
class DrService
{
    /**
     * A "test result" past this many minutes (one year) is not a recovery
     * metric, it is a data-entry mistake — an unbounded rto/rpo would let a
     * typo or a malformed webhook payload write a number the register then
     * treats as real evidence. No config knob: the ceiling exists to catch
     * garbage, not to be tuned per tenant.
     */
    private const MAX_RTO_RPO_MINUTES = 525_600;

    /**
     * The tier-mismatch rule (clause map §3.2): the minimum approved BIA RTO
     * across processes depending on this system's application, compared
     * against the system's own target. Draft BIAs are excluded — a draft is
     * somebody's work in progress and must not silently retier production.
     *
     * @return array{process: string, bia_reference: string, required_hours: float, target_hours: ?float}|null
     */
    public function tierMismatch(DrSystem $system): ?array
    {
        if ($system->application_id === null || $system->rto_target_hours === null) {
            return null;
        }

        $assessmentIds = Dependency::query()
            ->where('dependable_type', DependencyType::Applications->value)
            ->where('dependable_id', $system->application_id)
            ->pluck('assessment_id');

        if ($assessmentIds->isEmpty()) {
            return null;
        }

        $tightest = BiaAssessment::query()
            ->whereIn('id', $assessmentIds)
            ->where('status', 'approved')
            ->whereNotNull('rto_hours')
            ->orderBy('rto_hours')
            ->with('process:id,name')
            ->first();

        if ($tightest === null) {
            return null;
        }

        $targetHours = (float) $system->rto_target_hours;

        if ($targetHours <= (float) $tightest->rto_hours) {
            return null;
        }

        return [
            'process' => $tightest->process->name ?? 'Unknown process',
            'bia_reference' => (string) ($tightest->uuid ?? $tightest->getKey()),
            'required_hours' => (float) $tightest->rto_hours,
            'target_hours' => $targetHours,
        ];
    }

    /**
     * Every system currently mismatched — the register's filter and the
     * compliance-calendar feed alike.
     *
     * Returns a plain list rather than a typed `Collection`: `Collection`'s
     * `TValue` is not covariant, and the map/filter/values chain below
     * produces a type Larastan cannot unify with any declared array-shape
     * annotation, identical or not — a documented class of false positive,
     * not a bug in the shape itself. A plain array sidesteps it without
     * widening what is actually returned.
     *
     * @return list<array{system: DrSystem, mismatch: array{process: string, bia_reference: string, required_hours: float, target_hours: float|null}}>
     */
    public function tierMismatches(): array
    {
        return DrSystem::query()->get()
            ->map(fn (DrSystem $s) => ['system' => $s, 'mismatch' => $this->tierMismatch($s)])
            ->filter(fn (array $r) => $r['mismatch'] !== null)
            ->values()
            ->all();
    }

    /**
     * The batched form of `tierMismatch()` — one dependency lookup and one
     * BIA lookup for the WHOLE set, rather than two queries per system.
     *
     * Gate 2 review #1 defect 11: `DrPresenter::register()` called
     * `tierMismatch()` once per row (2 queries each), then `tierMismatches()`
     * a second time over every system in the org to build the summary count
     * (fetching every system again and repeating the same 2 queries per
     * system), then `overdue()` a third time. For a register of any size that
     * is O(N) round trips on a screen that has to render inside a page load.
     * This method does the same computation this class's own `tierMismatch()`
     * does, one system at a time, but with the Dependency and BiaAssessment
     * fetches issued once across the whole collection instead of once per
     * system, so the caller can build both the per-row column and the
     * summary count from a single pass.
     *
     * @param  Collection<int, DrSystem>  $systems
     * @return array<int, array{process: string, bia_reference: string, required_hours: float, target_hours: ?float}|null> Keyed by DrSystem id.
     */
    public function tierMismatchesForSystems(Collection $systems): array
    {
        $applicationIds = $systems->pluck('application_id')->filter()->unique()->values();

        if ($applicationIds->isEmpty()) {
            return $systems->mapWithKeys(fn (DrSystem $s) => [$s->getKey() => null])->all();
        }

        $dependencies = Dependency::query()
            ->where('dependable_type', DependencyType::Applications->value)
            ->whereIn('dependable_id', $applicationIds)
            ->get(['dependable_id', 'assessment_id']);

        $assessmentIdsByApplication = $dependencies->groupBy('dependable_id')
            ->map(fn (Collection $rows) => $rows->pluck('assessment_id')->unique()->values());

        $allAssessmentIds = $dependencies->pluck('assessment_id')->unique()->values();

        $assessments = $allAssessmentIds->isEmpty()
            ? collect()
            : BiaAssessment::query()
                ->whereIn('id', $allAssessmentIds)
                ->where('status', 'approved')
                ->whereNotNull('rto_hours')
                ->with('process:id,name')
                ->get()
                ->keyBy('id');

        return $systems->mapWithKeys(function (DrSystem $system) use ($assessmentIdsByApplication, $assessments) {
            if ($system->application_id === null || $system->rto_target_hours === null) {
                return [$system->getKey() => null];
            }

            $assessmentIds = $assessmentIdsByApplication->get($system->application_id, collect());

            $tightest = $assessments->only($assessmentIds->all())->sortBy('rto_hours')->first();

            if ($tightest === null) {
                return [$system->getKey() => null];
            }

            $targetHours = (float) $system->rto_target_hours;

            if ($targetHours <= (float) $tightest->rto_hours) {
                return [$system->getKey() => null];
            }

            return [$system->getKey() => [
                'process' => $tightest->process->name ?? 'Unknown process',
                'bia_reference' => (string) ($tightest->uuid ?? $tightest->getKey()),
                'required_hours' => (float) $tightest->rto_hours,
                'target_hours' => $targetHours,
            ]];
        })->all();
    }

    /**
     * Every process a system's failure would affect — review #2 defect 12,
     * clause map §6 item 6: criterion 2's "flags the dependent BIA
     * assessments" (plural) is `Finding.affected_process_id` (a single FK)
     * plus `iso22301.8.2.2`, raised once PER process, the same shape
     * ADR 0019 §… settled for Phase 9. This is that traversal — dependency
     * -> approved BIA -> process — with no RTO comparison, unlike
     * `tierMismatch()`: every dependent process is a target for the finding,
     * not only the tightest one. Draft BIAs are excluded for the same
     * reason `tierMismatch()` excludes them: a draft is somebody's work in
     * progress and must not silently attach a finding to a process whose
     * assessment was never approved.
     *
     * @return Collection<int, Process>
     */
    public function dependentProcesses(DrSystem $system): Collection
    {
        if ($system->application_id === null) {
            return new Collection;
        }

        $assessmentIds = Dependency::query()
            ->where('dependable_type', DependencyType::Applications->value)
            ->where('dependable_id', $system->application_id)
            ->pluck('assessment_id');

        if ($assessmentIds->isEmpty()) {
            return new Collection;
        }

        $processIds = BiaAssessment::query()
            ->whereIn('id', $assessmentIds)
            ->where('status', 'approved')
            ->pluck('process_id')
            ->filter()
            ->unique()
            ->values();

        if ($processIds->isEmpty()) {
            return new Collection;
        }

        return Process::query()->whereIn('id', $processIds)->orderBy('name')->get();
    }

    /**
     * The cadence-inheritance rule (clause map §3.3): process ->
     * dependency -> application -> system. Resolved in PHP over a scoped
     * fetch — never `JSON_CONTAINS`.
     *
     * AN OPEN-BANKING SYSTEM OWES TWO CADENCES — quarterly failover, six-
     * monthly full DR test — and this one denormalised `next_test_due`
     * column has to pick one. It takes the TIGHTER of the two (quarterly),
     * because that is the cadence criterion 5's own example is written
     * against ("four months past a quarterly failover is overdue") and the
     * one that makes the system overdue soonest — the actionable date, not
     * the loosest one that would hide a missed failover behind six months
     * of headroom.
     */
    public function deriveNextTestDue(DrSystem $system, ?Carbon $from = null): ?Carbon
    {
        $from ??= $system->last_test_date ?? now();
        $isOpenBanking = $this->inheritsOpenBankingScope($system);

        $days = $isOpenBanking
            ? (int) config('bcms.dr.open_banking_failover_cadence_days', 91)
            : (int) config('bcms.dr.default_test_cadence_days', 365);

        return $from->copy()->addDays($days);
    }

    /** Whether this system's failover cadence is the Open Banking quarterly one. */
    public function inheritsOpenBankingScope(DrSystem $system): bool
    {
        if ($system->application_id === null) {
            return false;
        }

        $assessmentIds = Dependency::query()
            ->where('dependable_type', DependencyType::Applications->value)
            ->where('dependable_id', $system->application_id)
            ->pluck('assessment_id');

        if ($assessmentIds->isEmpty()) {
            return false;
        }

        $processIds = BiaAssessment::query()->whereIn('id', $assessmentIds)->pluck('process_id')->unique();

        // Fetched scoped to these specific processes, then checked in PHP —
        // the clause map's own instruction, not `whereJsonContains` and
        // never a raw `JSON_CONTAINS`. The flag spellings are Phase 2's own
        // (`BiaValidator::OPEN_BANKING_FLAGS`) — reused rather than a second,
        // possibly-diverging list.
        return Process::query()
            ->whereIn('id', $processIds)
            ->get(['id', 'regulatory_flags'])
            ->contains(fn (Process $p) => array_intersect(
                (array) ($p->regulatory_flags ?? []),
                \App\Services\Bcms\Bia\BiaValidator::OPEN_BANKING_FLAGS,
            ) !== []);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException if the data does not describe a
     *                                  plausible test result — see `validateTestInput()`.
     */
    public function recordTest(DrSystem $system, array $data, User $by): DrTest
    {
        $this->validateTestInput($data);

        $metObjectives = $data['met_objectives'] ?? $this->computeMetObjectives($system, $data);

        return DB::transaction(function () use ($system, $data, $by, $metObjectives) {
            $test = DrTest::query()->create([
                'organization_id' => $system->organization_id,
                'dr_system_id' => $system->getKey(),
                'occurrence_id' => $data['occurrence_id'] ?? null,
                'test_type' => $data['test_type'],
                'test_date' => $data['test_date'] ?? now()->toDateString(),
                'rto_actual_minutes' => $data['rto_actual_minutes'] ?? null,
                'rpo_actual_minutes' => $data['rpo_actual_minutes'] ?? null,
                'met_objectives' => $metObjectives,
                'rollback_required' => $this->normalizeBoolean($data['rollback_required'] ?? null, false),
                'issues' => $data['issues'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'notes' => $data['notes'] ?? null,
                'iso_clause_ref' => ($data['occurrence_id'] ?? null) !== null
                    ? \App\Enums\Bcms\IsoClauseRef::Iso22301_8_5_exercise->value
                    : \App\Enums\Bcms\IsoClauseRef::Iso22301_8_4_5->value,
                'created_by' => $by->getKey(),
            ]);

            $this->applyTestToSystem($system, $test, $by);

            return $test;
        });
    }

    /**
     * Denormalise a recorded test onto its system's `last_test_*` and
     * `next_test_due` columns — shared by `recordTest()` and `ingest()`.
     *
     * FORWARD ONLY (review #1 defect 2). An older test recorded late — a
     * vendor's report that arrives weeks after the fact, or a manual entry
     * backfilled from a paper log — must never move `last_test_date` or
     * `next_test_due` BACKWARDS. Before this guard, recording (or ingesting)
     * any test unconditionally overwrote both columns from that test's own
     * `test_date`, so a test dated earlier than the system's current
     * `last_test_date` would regress the register's overdue clock — the
     * exact defect that lets a stale backfill make an overdue system look
     * current again, or an already-current system look freshly tested from a
     * date in the past.
     *
     * `$tokenActorLabel`, WHEN GIVEN, replaces `BcmsAuditable`'s automatic
     * (null-actor) audit row with an explicit one naming the machine token
     * (review #1 defect 11) — see `machineTokenActorLabel()`. The "before"
     * snapshot has to be read BEFORE `save()`, not after: by the time an
     * `update()` call returns, Eloquent has already synced `$original` to
     * the new values, so a `getOriginal()` read afterward would report the
     * new value as its own "before" — the reason this method fills `$before`
     * off `$system`'s current attributes first rather than reusing
     * `getChanges()`/`getOriginal()` post-save the way `BcmsAuditable` does
     * from inside its own `updated` listener, which runs before that sync.
     */
    private function applyTestToSystem(DrSystem $system, DrTest $test, ?User $by, ?string $tokenActorLabel = null): void
    {
        $testDate = $test->test_date instanceof Carbon ? $test->test_date : Carbon::parse($test->test_date);

        if ($system->last_test_date !== null && $testDate->lt($system->last_test_date)) {
            return;
        }

        $attributes = [
            'last_test_date' => $test->test_date,
            'last_test_rto_actual_minutes' => $test->rto_actual_minutes,
            'last_test_rpo_actual_minutes' => $test->rpo_actual_minutes,
            'last_test_met_objectives' => $test->met_objectives,
            'next_test_due' => $this->deriveNextTestDue($system, $testDate),
            'updated_by' => $by?->getKey(),
        ];

        if ($tokenActorLabel === null) {
            $system->update($attributes);

            return;
        }

        $system->fill($attributes);
        $dirty = $system->getDirty();

        if ($dirty === []) {
            return;
        }

        $before = [];

        foreach (array_keys($dirty) as $key) {
            $before[$key] = $system->getOriginal($key);
        }

        DrSystem::withoutEvents(fn () => $system->save());

        $this->writeExplicitAuditRow($system, 'updated', $before, $dirty, $tokenActorLabel);
    }

    /**
     * The minimum plausibility check every recorded or ingested test result
     * has to pass, whichever path it arrives by (review #1 defect 2). The
     * webhook route has no Form Request in front of it — a provider payload
     * reaches this service directly — so the check lives here rather than
     * only in `StoreBcmsDrTestRequest`, which covers the manual path alone.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException naming exactly which field failed.
     */
    private function validateTestInput(array $data): void
    {
        if (isset($data['test_type'])) {
            // Checked BEFORE the string cast below, not after (review #2
            // advisory A4). `(string) $data['test_type']` on an array is a
            // PHP warning that evaluates to the literal string "Array" —
            // which `DrTestType::tryFrom()` would then, correctly, refuse —
            // but a provider payload is exactly the place a malformed value
            // should be refused with a named message, not by falling through
            // a cast whose behaviour on a non-scalar is a footnote rather
            // than a contract.
            if (! is_string($data['test_type']) && ! is_int($data['test_type'])) {
                throw new InvalidArgumentException('test_type must be a string.');
            }

            if (\App\Enums\Bcms\DrTestType::tryFrom((string) $data['test_type']) === null) {
                throw new InvalidArgumentException('test_type is not a recognised DR test type.');
            }
        }

        if (isset($data['test_date'])) {
            try {
                $testDate = Carbon::parse($data['test_date']);
            } catch (\Throwable) {
                throw new InvalidArgumentException('test_date is not a valid date.');
            }

            if ($testDate->startOfDay()->gt(now()->startOfDay())) {
                throw new InvalidArgumentException('test_date cannot be in the future.');
            }
        }

        foreach (['rto_actual_minutes', 'rpo_actual_minutes'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $value = $data[$field];

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw new InvalidArgumentException($field.' must be a non-negative integer.');
            }

            $intValue = (int) $value;

            if ($intValue < 0 || $intValue > self::MAX_RTO_RPO_MINUTES) {
                throw new InvalidArgumentException(
                    $field.' must be between 0 and '.self::MAX_RTO_RPO_MINUTES.' minutes.'
                );
            }
        }

        if (
            array_key_exists('rollback_required', $data)
            && $data['rollback_required'] !== null
            && ! is_bool($data['rollback_required'])
            && ! in_array($data['rollback_required'], [0, 1, '0', '1', 'true', 'false'], true)
        ) {
            throw new InvalidArgumentException('rollback_required must be a boolean.');
        }

        if (array_key_exists('notes', $data) && $data['notes'] !== null) {
            if (! is_string($data['notes']) || mb_strlen($data['notes']) > 5000) {
                throw new InvalidArgumentException('notes must be a string of at most 5000 characters.');
            }
        }
    }

    /**
     * A validated `rollback_required` into a real PHP `bool`, never the raw
     * payload value (review #2 advisory A4).
     *
     * `validateTestInput()` accepts the loose forms `IsoClauseRef`-style
     * inbound payloads use — `"true"`, `"false"`, `"0"`, `"1"`, `0`, `1` — but
     * accepting them is not the same as being safe to persist them. PHP's
     * `(bool)` cast treats the STRING `"false"` as truthy (a non-empty string
     * casts true), so passing that string straight through — as the code did
     * before this method existed — silently stored the opposite of what a
     * provider sent rather than raising an error or storing the right value.
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` is the one PHP primitive
     * that reads `"false"`/`"0"` as false and everything else truthy as
     * true, so every caller of `create()` below passes through here first.
     */
    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Target vs actual, at the moment of recording — computed, never a
     * typeable field (mirrors the AAR builder's `threshold_breached` rule).
     *
     * @param  array<string, mixed>  $data
     */
    private function computeMetObjectives(DrSystem $system, array $data): ?bool
    {
        $rtoActual = $data['rto_actual_minutes'] ?? null;
        $rpoActual = $data['rpo_actual_minutes'] ?? null;

        if ($rtoActual === null && $rpoActual === null) {
            return null;
        }

        $rtoTargetMinutes = $system->rto_target_hours === null ? null : ((float) $system->rto_target_hours * 60);
        $rpoTarget = $system->rpo_target_minutes;

        $rtoOk = $rtoTargetMinutes === null || $rtoActual === null || $rtoActual <= $rtoTargetMinutes;
        $rpoOk = $rpoTarget === null || $rpoActual === null || $rpoActual <= $rpoTarget;

        return $rtoOk && $rpoOk;
    }

    /**
     * An ingested provider result never carries our own judgement of
     * `met_objectives` (clause map §3.6) — it lands `null` until a human
     * confirms.
     *
     * IDEMPOTENT ON `(provider, external_test_id)`, and now (review #2
     * advisory A3) safe against two REDELIVERIES of the same webhook
     * arriving close enough together to race. There is no unique index to
     * lean on — ADR 0020 §4 put this pair inside `evidence` (json), not a
     * table, deliberately — so the whole method runs inside one transaction
     * that takes `lockForUpdate()` on the SYSTEM row before it even looks
     * for an existing test. A second delivery arriving while the first is
     * still inside this method blocks on that lock rather than running its
     * own "not found" check against data the first request has not
     * committed yet; once the first commits and releases the lock, the
     * second's lookup finds the row the first just wrote and returns it
     * instead of inserting a duplicate. The lookup itself no longer scans
     * every test for this system in PHP — `whereJsonContains()` on
     * `evidence->provider` and `evidence->external_test_id` is Laravel's
     * portable spelling (ADR 0020 Amendment 3; `NotificationService::
     * reportabilityStatus()` already uses the same construct), never a raw
     * `JSON_CONTAINS`.
     *
     * `$token`, WHEN GIVEN, is the machine `ApiToken` that authenticated this
     * request (review #1 defect 11). It is recorded twice: `evidence`
     * carries the token's id and name on the row itself, and — because
     * `AuthenticateApiToken` never binds an acting user for a
     * `client_credentials` token, so `auth()->user()` is null and
     * `BcmsAuditable`'s automatic audit row would carry a null actor forever
     * — the audit rows this ingest produces are written explicitly with the
     * token named in `actor_label`, so a leaked token's rows can be found
     * (search the audit log for that label) and the token revoked.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException if the payload does not describe a
     *                                  plausible test result — see `validateTestInput()`. Caught by
     *                                  `DrIngestionWebhookController` and turned into a 422, so a malformed
     *                                  provider payload is refused rather than silently written.
     */
    public function ingest(
        DrSystem $system,
        string $provider,
        string $externalTestId,
        array $payload,
        ?ApiToken $token = null,
    ): DrTest {
        return DB::transaction(function () use ($system, $provider, $externalTestId, $payload, $token) {
            // Locked FIRST, before the dedupe check — see the method
            // docblock. Every ingest for this system, redelivery or not,
            // serialises through this one row.
            DrSystem::query()->whereKey($system->getKey())->lockForUpdate()->first();

            $existing = DrTest::query()
                ->where('dr_system_id', $system->getKey())
                ->whereJsonContains('evidence->provider', $provider)
                ->whereJsonContains('evidence->external_test_id', $externalTestId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->validateTestInput($payload);

            $tokenActor = $this->machineTokenActorLabel($token);

            $create = fn () => DrTest::query()->create([
                'organization_id' => $system->organization_id,
                'dr_system_id' => $system->getKey(),
                'test_type' => $payload['test_type'] ?? \App\Enums\Bcms\DrTestType::Failover->value,
                'test_date' => $payload['test_date'] ?? now()->toDateString(),
                'rto_actual_minutes' => $payload['rto_actual_minutes'] ?? null,
                'rpo_actual_minutes' => $payload['rpo_actual_minutes'] ?? null,
                // Never accepted from the provider — awaits human confirmation.
                'met_objectives' => null,
                'rollback_required' => $this->normalizeBoolean($payload['rollback_required'] ?? null, false),
                'notes' => $payload['notes'] ?? null,
                'evidence' => [
                    'provider' => $provider,
                    'external_test_id' => $externalTestId,
                    'received_at' => now()->toIso8601String(),
                    'raw_payload' => $payload,
                    'content_hash' => hash('sha256', json_encode($payload)),
                    'ingested_by_token_id' => $token?->getKey(),
                    'ingested_by_token_name' => $token?->name,
                ],
                'iso_clause_ref' => \App\Enums\Bcms\IsoClauseRef::Iso22301_8_4_5->value,
            ]);

            if ($tokenActor === null) {
                $test = $create();
            } else {
                $test = DrTest::withoutEvents($create);
                $this->writeExplicitAuditRow($test, 'created', null, $this->auditableAttributes($test), $tokenActor);
            }

            $this->applyTestToSystem($system, $test, null, $tokenActor);

            return $test;
        });
    }

    /**
     * The one write action on an otherwise read-only ingested record.
     *
     * BOTH DIRECTIONS, AND ONLY WHEN THIS TEST IS STILL THE SYSTEM'S LATEST
     * (review #2 defect 10). Before this guard, confirming `met = true` never
     * touched `last_test_met_objectives` at all — an ingested test that
     * turned out to have met its target showed unconfirmed on the register
     * forever, because only the `false` branch existed — and confirming
     * EITHER value on an OLDER test (one superseded by a later test already
     * recorded on this system) overwrote the newer test's own result with
     * the older one's, silently. `isSystemsLatestTest()` uses the same
     * "latest by test_date, ties broken by id" ordering `applyTestToSystem()`
     * already applies when denormalising, so the two never disagree about
     * which test the system's columns should reflect.
     */
    public function confirmObjectives(DrTest $test, bool $met, User $by): DrTest
    {
        if ($test->met_objectives !== null) {
            throw new InvalidArgumentException(
                'This test\'s objective has already been confirmed. A wrong confirmation is corrected by a '
                .'new finding, not by flipping the flag back.'
            );
        }

        $test->update(['met_objectives' => $met, 'updated_by' => $by->getKey()]);

        if ($this->isSystemsLatestTest($test)) {
            $test->drSystem?->update(['last_test_met_objectives' => $met, 'updated_by' => $by->getKey()]);
        }

        return $test->refresh();
    }

    /**
     * Whether `$test` is the most recent test recorded for its system — the
     * same ordering `applyTestToSystem()` uses to decide "forward only", so
     * a confirmation and a new test's denormalisation never disagree about
     * which row the system's `last_test_*` columns should describe.
     */
    private function isSystemsLatestTest(DrTest $test): bool
    {
        $latest = DrTest::query()
            ->where('dr_system_id', $test->dr_system_id)
            ->orderByDesc('test_date')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->is($test);
    }

    /**
     * The audit actor's display name for a machine token — null when there
     * is a real authenticated user to name instead, or no token at all
     * (review #1 defect 11).
     */
    private function machineTokenActorLabel(?ApiToken $token): ?string
    {
        if ($token === null || Auth::user() !== null) {
            return null;
        }

        return sprintf('API token #%d (%s)', $token->getKey(), $token->name);
    }

    /**
     * The attributes `BcmsAuditable`'s own `created` listener would have
     * written, replicated here because `withoutEvents()` suppressed that
     * listener for this one write. `auditExcluded()` is the model's own
     * public method — the same one the trait calls — so this never drifts
     * from what the automatic row would have excluded.
     *
     * @return array<string, mixed>
     */
    private function auditableAttributes(DrTest $test): array
    {
        return array_diff_key($test->getAttributes(), array_flip($test->auditExcluded()));
    }

    /**
     * Writes one `bcms_audit_logs` row directly, bypassing `BcmsAuditable`'s
     * automatic hook, for the one case that hook cannot serve: a write made
     * by a machine token, which `auth()->user()` cannot name.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function writeExplicitAuditRow(Model $model, string $event, ?array $before, array $after, string $actorLabel): void
    {
        AuditLog::query()->create([
            'organization_id' => $model->getAttribute('organization_id'),
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'before' => $before,
            'after' => $after,
            'actor_id' => null,
            'actor_label' => $actorLabel,
            'ip_address' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Backup attestation via an audited event, no new table (clause map §3.5,
     * ADR 0020 §4): actor, time and the statement text in `after` is the
     * minimum an attestation means.
     */
    public function backupAttestation(DrSystem $system, User $by, string $statement): DrSystem
    {
        if (trim($statement) === '') {
            throw new InvalidArgumentException('A backup attestation must state what is being confirmed.');
        }

        $system->update(['last_backup_verified_at' => now(), 'updated_by' => $by->getKey()]);

        $system->recordAudit('backup.attested', [
            'actor' => $by->name,
            'statement' => $statement,
        ]);

        return $system->refresh();
    }

    /** Systems overdue for their next test — the compliance calendar's feed. */
    public function overdue(): Collection
    {
        return DrSystem::query()
            ->whereNotNull('next_test_due')
            ->where('next_test_due', '<', now()->toDateString())
            ->get();
    }
}
