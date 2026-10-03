# BCMS Phase 2C (reduced) — Microsoft Entra ID identity sync

**Lead:** integrations-engineer · **Decided by:** ADR 0018 · **Branch:** `integration/bcms-remaining`

What landed, in the shape of the other phase notes: what shipped, the decisions,
the deviations from a literal reading of the work order, the defects found
while building it, and the gaps handed forward.

## 1. What shipped

**Schema — exactly ADR 0018 §2:** one migration,
`2026_09_17_120001_create_bcms_identity_tables.php`, adding
`bcms_identity_connectors`, `bcms_identity_sync_runs`,
`bcms_identity_sync_changes`, plus `bcms_contacts.manager_contact_id` and the
`unique(organization_id, ad_object_guid)` index. `client_secret` is `TEXT` with
an `encrypted` cast, never `json` — the `connectors.config` defect is not
repeated. `database/schema/bcms-manifest.php` regenerated via
`bcms:verify-schema --write` in the same change.

**The boundary (my own remit, ADR 0018 §4):**
`App\Contracts\Bcms\DirectoryClient` (four methods, no write method, ever),
`App\Services\Bcms\Identity\EntraGraphClient` (app-only client-credentials
over `Http`, paging via `@odata.nextLink`, `$expand=manager($select=id)` with
a `/users/{id}/manager` fallback where a 404 means "no manager"), and
`App\Services\Bcms\Identity\FakeDirectoryClient` (in-memory, real paging via
`withPageSize()`, a `failAfter()` hook for testing a mid-run failure without
touching HTTP). `App\Contracts\Bcms\ReportsDirectoryFetchStats` is a small
addition beyond the ADR's four-method contract, purely for `pages_fetched`/
`directory_objects_read` bookkeeping — it does not touch the frozen shape of
`DirectoryClient` itself.

**The sync:** `ChangeDetector` (directory vs roster → joiner / leaver / mover
/ contact_change, matched by `ad_object_guid` → `employee_id` → `email`),
`ImpactAssessor` (call-tree and saved-audience impact, reusing
`CallTreeService::downstreamCount()` rather than a second counter),
`ChangeApplier` (the only writer to `bcms_contacts`, holding the write
allowlist and never-write list as constants asserted by
`Phase2cReadOnlyGuardTest`), `DirectorySyncService` (orchestrates one run,
write-ahead per standing rule 8, error classification, auto-apply per
policy).

**Models, controllers, permissions, jobs, schedule, seeder, tests** as listed
in the work order — see the HANDOFF block for the full file list.

**`IdentityConnector` added to the ADR 0017 pinned organisation-level map.**
Phase 7.5 landed mid-phase and its `BcmsRecordVisibilityTest` already carried
`IdentitySyncRun`/`IdentitySyncChange` (both route-bound, so its own §6.1 scan
required them). `IdentityConnector` is never route-bound — the settings
controller resolves the tenant's one connector directly rather than through a
route parameter — so the scan would not have asked for it on its own; it is
added anyway because ADR 0018 §8 pins all three in the same map, in the same
words the ADR uses. `bcms.identity.manage`/`bcms.identity.review` and the
route middleware are what actually guard the connector today.

## 2. Decisions and deviations from a literal reading

1. **A second-pass manager-edge resolution inside one run.** ADR 0018 §3.1
   accepts eventual consistency ACROSS runs for a delta ("a delta that shows a
   manager-adjacent change marks the contact for the next full run"), but
   says nothing about resolution ORDER WITHIN a single full run. Building the
   200-user fixture surfaced the gap directly: a two-person management loop
   (criterion 7) resolves only ONE of its two edges if `ChangeApplier` applies
   joiners strictly in directory order and only ever tries once — whichever
   contact is created first cannot find a manager that does not exist yet.
   That is not a hypothetical edge case; it is exactly what the loop fixture
   is for, and it also produced a spurious "mover" on the run after the loop,
   because the unresolved edge looked like a directory change on the next
   read. `DirectorySyncService::stageAndApply()` now runs a second pass after
   every entry in the run has been staged and (where policy allows)
   auto-applied: it re-resolves the manager edge for every touched contact
   that carried a manager reference, by which point every contact the run
   touched already exists. One full reconciliation now resolves its own
   hierarchy completely, rather than "eventually, over a couple of nights" —
   a stronger guarantee than the ADR asked for, in the direction the ADR's
   own reasoning points.
2. **`manager_contact_id` is kept unconditionally in sync from the directory,
   not provenance-protected like the personal/role fields.** ADR 0018 §3.4
   lists `manager_contact_id` in the write allowlist without saying whether it
   is provenance-checked like `full_name`/`mobile_primary`/etc. This phase
   ships no contact-editing screen at all (ADR 0018 §1 point 1 — the sync is
   the first bulk writer into the roster and there is still no contact
   directory screen), so there is no path by which a human could have hand-set
   `manager_contact_id` for the provenance rule to protect. Recorded here so
   Phase 2D's contact directory screen (if it ever allows hand-editing a
   manager) revisits this assumption rather than silently inheriting it.
3. **A management-loop reactivation and department-string mismatch both
   degrade to a `contact_change` rather than a new kind.** The work order
   names four kinds; a contact reactivating (was inactive, directory now
   shows `accountEnabled: true`) and an unmatched department string are both
   real cases the fixture exercises, and neither warranted a fifth kind —
   they are folded into `contact_change`'s `before`/`after` shape. An unmatched
   department is visible on the connector screen's roster summary
   (`unmatched_departments` count) rather than as a queue row of its own.
4. **The delta path (`ChangeDetector::detectDelta()`) is implemented per ADR
   0018 §3.1's restriction — never a mover, never a manager re-resolution —
   but is not covered by one of the nine numbered acceptance criteria.** None
   of the nine explicitly exercises `sync_schedule = nightly_plus_delta`.
   It is built and Pint/PHPStan-clean; qa-engineer should add a delta-specific
   test before go-live if the gate wants one, since criterion coverage in the
   ADR does not name it.
5. **The saved-audience "would empty a dynamic group" check is a
   single-snapshot approximation**, not a full re-simulation of the group's
   rule with the proposed change applied: it flags a dynamic group whose
   CURRENT resolved count is exactly one and includes the contact being
   moved or removed. A group that is currently at two, both of whom leave in
   the same run, is not caught by this — a known gap, not a defect found and
   left; the ADR's own text ("a change to their `business_unit_id`/
   `site_id`/`is_active` takes a dynamic group's resolved count to zero") did
   not specify the simulation mechanics and this phase chose the cheaper,
   honest-about-its-limits version over a full what-if resolver.

## 3. Defects found while building this

- **`IdentityConnector::auditExcluded()` cannot call `parent::auditExcluded()`.**
  `BcmsAuditable` is a trait, not a base class; a model using the trait that
  overrides one of its methods has no parent implementation to call. The fix
  (repeat the base exclusion list rather than extend it) is recorded in the
  model's own docblock so the next model to add a secret-bearing override
  does not repeat the mistake.
- **Both real-HTTP call sites that used the cached token array as if it were
  the token string.** `EntraGraphClient::users()`, `::delta()` and
  `::managerObjectId()` each passed the `array{token, scopes}` return of
  `token()` straight into a method typed `string $token`. Caught by PHPStan
  and confirmed by `criterion_8`'s manager-404 and mid-run-failure tests
  before any of this reached a review.
- **A JSON-column identifier over 64 characters.** MariaDB rejects an index
  name longer than 64 bytes; the naïve `$table->index([...])` auto-name on
  `bcms_identity_sync_runs` (three-column composite) was 79 characters.
  Named explicitly (`bcms_isr_org_connector_started_idx`,
  `bcms_isr_org_status_idx`) rather than left to Laravel's default.
- **The manifest regeneration also caught pre-existing drift unrelated to
  this phase.** `bcms:verify-schema --write` picked up `bcms_evidence` and
  several other tables/columns from migrations already on this branch
  (dated after this phase's own, from concurrent Phase 9/10/11 work) whose
  manifest had never been regenerated. The tool does exactly what it is
  built to do — diff against the live schema — and the wide diff in the
  manifest commit is that pre-existing gap being closed incidentally, not
  scope creep from this phase. Flagged here so code-reviewer is not surprised
  by the diff's width.

## 4. Assumptions (development standard, where the ADR was silent)

- The 12 Kano Heritage departments and their headcounts
  (`IdentityDemoSeeder::DEPARTMENTS`) are the same twelve business units
  `CallTreeDemoSeeder` names, with different headcounts summing to ~200
  rather than ~350 — the work order asks for "~200 users, not 5,000" and
  names the same 12 departments.
- A department's manager chain is built in fixed layers (2 → 4 → 8 → rest)
  rather than a randomly widening span, specifically so the chain depth is
  bounded at exactly five levels (a head plus four) for any department large
  enough to reach it, and so the SAME shape reproduces deterministically
  across two directory reads a test or the seeder takes a "generation" apart.
- Every seeded mobile number is in the reserved `+2348000` range, following
  `CallTreeDemoSeeder`'s existing rule for the same reason: a seeder that
  writes a plausible Nigerian mobile into a table an EMNS dispatcher reads is
  one misconfiguration away from ringing a stranger.

## 4a. Gate 1 remediation (reliability-engineer's review, closed by integrations-engineer)

reliability-engineer's runtime hardening pass (`tests/Feature/Bcms/
Phase2cRuntimeTest.php`, the `exclusively()`/`abort()` split in
`DirectorySyncService`, the Graph retry ladder in `EntraGraphClient`, the
overlap-aware `SyncBcmsDirectory`, the two new `BcmsWatchdog` checks, and
`config/horizon.php`) left two gaps for this phase to close before gate 1:

1. **"Sync now" no longer runs inline.** `IdentityController::sync()`
   dispatched `DirectorySyncService::runFull()` synchronously inside the HTTP
   request — correct for the ~200-user fixture, wrong at a real bank's
   headcount, where the read alone can outlive nginx/FPM and a stalled admin
   simply clicks again. It now dispatches `SyncBcmsIdentityJob` — the same job
   the nightly sweep and the 15-minute delta use, on the same `bcms-sync`
   queue, carrying the same `ShouldBeUnique` key — and redirects to the
   connector screen with a "queued" flash. `SyncBcmsIdentityJob` gained a
   fourth, optional constructor argument, `?int $triggeredByUserId`: when set,
   the run is recorded with `SyncTrigger::Manual` (and `triggered_by`) rather
   than `scheduled_full`, so an admin's click is distinguishable from the
   scheduler in the run history and in `ConnectorHealth::
   fullReconciliationStale()`'s trigger filter — both already treat `Manual`
   as a full reconciliation. The dispatch-time `ShouldBeUnique` lock drops a
   genuine double-click silently, which answers "did the work happen twice"
   but not "what does the admin see" — so the controller ALSO checks for a
   `running` `IdentitySyncRun` row before dispatching, and when one exists it
   redirects straight to that run with an explicit "already running" flash
   instead of queuing (and silently dropping) a second job. This is a DB read,
   not a second lock: the actual serialisation is still `ShouldBeUnique` at
   dispatch and `DirectorySyncService::exclusively()` at execution, both
   untouched by this fix.
2. **`ConnectorHealth::isStale()` cannot see a stale hierarchy.** It counts
   any successful run — full or delta — so a tenant on `nightly_plus_delta`
   whose nightly full has failed all week reads green on the connector screen
   while `bcms:watchdog`'s `hierarchyStale()` alerts on the same connector for
   the same reason (ADR 0018 §3.1: a delta never re-resolves a manager edge).
   `ConnectorHealth` gained `fullReconciliationStale()` and a private
   `lastFullSuccess()` (trigger in `scheduled_full`/`manual`, same 48-hour
   window `isStale()` uses), and `summarize()` now returns
   `full_reconciliation_stale` and `last_full_reconciliation_at` alongside
   `is_stale`. **No change was needed in `IdentityPresenter`**: `health` is
   built as `$this->health->summarize($connector) + [...]`, so the two new
   keys reach `connector.health` on the screen without a presenter edit —
   confirmed by re-reading the file, which by this point also carried
   frontend-engineer's and reliability-engineer's own additions
   (`runs_status_url`, `latestRunStatus()`, `trigger_label`/`status_label`,
   `decidedByUser`), none of which this phase touched.

The two watchdog-side checks this gap exposed (`hierarchyStale()` in
`BcmsWatchdog`, the identical window logic now duplicated in
`ConnectorHealth::fullReconciliationStale()`) are deliberately NOT unified
into one shared method in this pass — `BcmsWatchdog.php` was under concurrent
edit from the Phase 10 track for an unrelated check (overdue regulatory
notifications) at the time, and refactoring a file mid-edit by another agent
is a worse risk than the small duplication. Left as a named, cross-referenced
gap: **a future pass should have `BcmsWatchdog::hierarchyStale()` call
`ConnectorHealth::fullReconciliationStale()` instead of re-deriving the same
query**, so the two can never drift against each other by one a later
edit to only one of them.

Tests added/changed for this remediation: `Phase2cScreensTest::
only_identity_manage_may_run_a_sync` now asserts `Queue::fake()` + a pushed
`SyncBcmsIdentityJob` rather than a synchronously-created run row;
`a_sync_already_in_flight_is_named_rather_than_queued_again` is new;
`the_connector_screen_flags_a_stale_full_reconciliation_even_when_a_delta_recently_succeeded`
and `the_connector_screen_does_not_flag_a_recent_full_reconciliation` are new.
`Phase2cScreensTest::connector()` gained the `$overrides` parameter
`Phase2cIdentitySyncTest::connector()` already had — PHPStan caught the two
call sites silently passing an array to a zero-parameter method (PHP does not
error on an extra argument; it is simply discarded) before this landed.

## 4b. Two defects from frontend-engineer's browser verification

1. **`bcms_identity_sync_runs.started_at` silently moved forward on every
   completed run.** It was a NOT-NULL `timestamp` column with no explicit
   default. This server runs with `explicit_defaults_for_timestamp` OFF
   (confirmed: `SHOW VARIABLES LIKE 'explicit_defaults_for_timestamp'` →
   `OFF`) — MariaDB's legacy compatibility mode, under which a NOT-NULL
   `TIMESTAMP` column with no explicit default silently gets
   `DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()` attached by
   the server itself, confirmed directly against a live row with `SHOW
   CREATE TABLE`. `finish()`/`abort()` both `UPDATE` the row (setting
   `status`, `finished_at`, the tally) well after `started_at` was written,
   and MariaDB's own implicit clause was rewriting `started_at` to the
   moment of THAT update — in the server's `SYSTEM` time zone (`WAT`, UTC+1
   on this box), not PHP's UTC `now()`. Every completed run therefore showed
   `finished_at` roughly an hour BEFORE `started_at`. `finished_at` itself
   was never affected (it is `->nullable()`, and MariaDB only auto-defaults
   a NOT-NULL timestamp with no explicit default). Fixed by changing both
   columns from `timestamp` to `dateTime` in the migration — MariaDB never
   attaches an implicit default or auto-update to `DATETIME`. Verified
   empirically via `php artisan tinker` before writing the fix (see the
   handoff's verification notes), then via
   `a_completed_run_never_finishes_before_it_started`, which reads the RAW
   column (bypassing Eloquent's cast) specifically so a future regression
   that only shows up after a cast reinterprets the value cannot pass this
   test for the wrong reason.
2. **`IdentityDemoSeeder::run()` resolved `DirectorySyncService` before
   swapping in `FakeDirectoryClient`.** `app(DirectorySyncService::class)`
   was called first, baking in whatever `DirectoryClient` the container was
   bound to at that moment (the real `EntraGraphClient`, per the
   `AppServiceProvider` binding) — the later `app()->instance(DirectoryClient
   ::class, $fake)` had no effect on the already-constructed service. Run as
   shipped, the seeder placed a real HTTP call to Entra using its fake demo
   credentials. Fixed by reordering the two lines. `the_demo_seeder_never_
   reaches_the_network` runs the seeder for real under
   `Http::preventStrayRequests()` so a regression fails loudly with a
   `StrayRequestException` rather than "worked on a laptop that happened not
   to resolve the fake hostnames".

**Ruling corrected — this DID need a code change.** The review screen's
`requires_ack` rows are indeed never bulk-*selectable* (`Review.jsx` disables
the row's checkbox unconditionally), and that is the stricter, correct
reading of ADR 0018 §3.2/§5 over the work order's looser "excluded unless
explicitly selected" phrasing. But the disabled checkbox is a client-side
control only: `BulkDecideIdentitySyncChangesRequest` validated an id against
`organization_id`/`sync_run_id`/`decision = pending` and nothing else, so a
`change_ids` array built outside the JSX (curl, an API client, a compromised
or simply differently-written front end) could carry a requires_ack id
straight through `IdentitySyncController::bulkDecide()` into
`DirectorySyncService::decide()` and `ChangeApplier`, deactivating a Tier-2
call-tree node's contact without the acknowledgement ADR 0018 calls
unconditional. qa-engineer's gate 1 retest caught this with
`Phase2cIdentitySyncTest::a_requires_ack_row_cannot_be_applied_through_bulk_decide`.
Fixed in `IdentitySyncController::bulkDecide()`, not in the form request: a
submitted batch can legitimately mix an ordinary pending id with a
requires_ack id (exactly the case a reviewer clearing a queue produces), and
a hard validation failure over the whole `change_ids` array would refuse the
ordinary ids sharing that request too, which ADR 0018 never asks for — the
acceptance test itself expects the plain id to still apply in the same call.
The controller now partitions the fetched (tenant/run/pending-scoped)
candidates by `requires_ack`, applies `decide()` only to the ones that are
not, and for any that are: never calls `decide()` on them, records the
refused attempt via `IdentitySyncRun::recordAudit()`
(`identity.sync.bulk_decide_refused`, carrying the offending ids and the
decision that was attempted) so a non-UI bypass attempt is not silent, and
returns an `error` flash naming the offending ids and the count that DID
apply — never a bare success as if nothing had been withheld. Per-row
`decide()` remains the only route that may approve a requires_ack change.
Dedicated tests were added for the two adjacent gaps this defect sat next to:
a 403 for a reviewer-less `bcms.identity.manage` user on both
`bcms.identity.changes.decide` and `.bulk-decide`
(`Phase2cScreensTest::the_single_decide_action_requires_identity_review_not_identity_manage`,
`::the_bulk_decide_action_requires_identity_review_not_identity_manage`), and
a cross-tenant 404 probe on bulk-decide that changes nothing
(`::a_bulk_decide_from_another_tenant_404s_and_changes_nothing`).

## 4c. Gate 2 remediation (code-reviewer's rejection, closed by integrations-engineer)

**BLOCKING — a full reconciliation never deactivated a contact whose Entra
account was disabled.** ADR 0018 §3.4 names two ways a leaver reaches the
queue: absence from a full read, or `accountEnabled = false`. `detectDelta()`
always had the second arm (:131); `detectFullReconciliation()`'s single-contact
path, `diffAgainstExisting()`, did not — a disabled-but-still-present account
fell through to an ordinary field diff (or, with nothing else changed, no row
at all) instead of a `leaver`. Fixed by adding the same check as the first
thing `diffAgainstExisting()` does, returning `leaver($existing)` immediately
so it wins over any other field diff, exactly the priority the absence arm
already has. `IdentityDemoSeeder::buildDirectory()` gained a `$disableObjectIds`
parameter (parallel to the existing `$removeObjectIds`/`$moveCount`) so the
demo's twelve leavers are now ten absences plus one disabled-in-place account,
exercising both arms rather than only one; `Phase2cIdentitySyncTest::
criterion_6b_an_entra_account_disabled_in_place_deactivates_the_contact_and_reactivates_on_re_enable`
covers deactivation (never deletion), `leaver_count`, `requires_ack` on a
call-tree node, and reactivation once the account is re-enabled.

**Advisories fixed:**
- **4 — `DirectoryAttributeMap::isValid()`'s key check was dead code.** The
  computed `$knownKeys` was never read; the numeric-index `+` union of
  `array_keys(defaults())` and `['userPrincipalName', 'businessPhones']` threw
  both operands away as soon as any numeric keys collided, and the function
  validated values only. Fixed to actually check `in_array($key, $knownKeys,
  true)` — and `$knownKeys` is `array_keys(defaults())` alone, not defaults()
  plus those two extra names: `userPrincipalName` is applied unconditionally
  as the email fallback and `businessPhones` is folded into `mobilePhone`
  before the map is consulted, so a saved key naming either would validate and
  then never be looked up, which is the exact defect this validator exists to
  catch for every other key. `Phase2cIdentitySyncTest::
  directory_attribute_map_rejects_a_key_outside_the_recognised_source_properties`
  pins both the acceptance and the two specific rejections.
- **7 — a Graph `error.code` over 40 characters would have failed the write to
  `bcms_identity_sync_runs.error_code` (`varchar(40)`).** Capped with
  `mb_substr($graphCode, 0, 40)` in `EntraGraphClient::classifiedFailure()`,
  matching `DirectorySyncService::abort()`'s existing cap on `error_class` for
  the same reason. `Phase2cRuntimeTest::
  a_sixty_character_graph_error_code_is_capped_to_the_column_width` pins it.
- **8 — `DirectorySyncService::decide()` was read-then-write, not
  compare-and-swap.** Two requests racing the same pending row (a double
  click, two open tabs) could both pass the controller's own pending check and
  both call `ChangeApplier::apply()`. The pending→decided transition is now a
  single conditional `UPDATE ... WHERE decision = 'pending'`; zero affected
  rows means somebody else already decided it, and the call returns without a
  second apply. `Phase2cIdentitySyncTest::two_sequential_decides_on_one_change_apply_once`
  loads the same row into two separate model instances (standing in for two
  concurrent requests) and asserts exactly one `contact.deactivated` audit row.
- **10 — `SyncBcmsDirectory::handle()` used `Organization::query()->get()`.**
  Changed to `cursor()` — the per-tenant loop only ever needs one row in memory
  at a time.
- **5 — up to three queries per directory user in `ChangeDetector`.**
  `resolveBusinessUnit()`/`resolveSite()`/`findExistingContact()` each queried
  per user, so a 200-user read ran up to several hundred queries to resolve
  business units, sites and existing contacts. `ChangeDetector::preloadRoster()`
  now reads all three ONCE per `detectFullReconciliation()`/`detectDelta()`
  call into keyed maps (business units and sites by lower-cased name/code;
  contacts by `ad_object_guid`, then by `employee_id`, then by lower-cased
  `email` — the same three-arm priority and the same "never via employee_id/
  email once a guid is set" rule the per-row queries enforced). Safe because
  nothing in `ChangeDetector` writes to the roster it reads: detection only
  ever stages rows in memory, and `ChangeApplier` is the one thing that writes
  contacts, strictly after detection has finished for the whole read.

**Recorded rather than fixed:**
- **6 — `ImpactAssessor::savedGroupImpacts()` re-resolves every dynamic
  group's full rule for every leaver/mover in a run.** Unlike 5, this one is
  NOT a safe blanket cache: `DirectorySyncService::stageAndApply()` auto-applies
  a `safe_only` leaver INSIDE the same loop that assesses the next one, so a
  dynamic group's membership can genuinely change partway through a run (an
  earlier leaver in the same run already deactivated changes who a later
  contact's group-emptying check should see). Caching a group's resolved ids
  for the whole run would make that check stale exactly when it matters most —
  a cascading departure inside one run. This is the same tradeoff already
  named in §5 "Gaps handed forward" as "a single-snapshot approximation";
  fixing the redundant resolution properly wants a per-run cache that
  invalidates on auto-apply, not a blanket one, which is a larger change than
  this gate's remediation pass should carry. Tracked here as advisory 6,
  unresolved, for the same future pass §5 already names for the snapshot
  approximation itself.
- **9 — `stageAndApply()`'s supersession `UPDATE` writes no audit row.** A
  still-pending change from an earlier run, superseded by this run's re-read
  of the same object/kind, is a mass `IdentitySyncChange::query()->...
  ->update(['decision' => 'superseded'])` with no `recordAudit()` call.
  Decision: acceptable as shipped — ADR 0018 §3.4 names no audit event for
  supersession (only `identity.change.approved`/`.rejected`/`.auto_applied`),
  and the superseded row itself remains visible and queryable (`decision =
  'superseded'`) on the run it belongs to, which is what an examiner would
  actually look at. Recorded here rather than left silent, per gate 2 advisory
  9, in case a future audit requirement wants an explicit event for it.

**Doc correction (advisory 11):** `docs/bcms/screens/identity-change-review.md`
§4's bulk-approve row, and its own HANDOFF block, still described the
pre-implementation proposal — a reviewer manually selecting `requires_ack`
rows into a bulk batch, with the button label naming the count. The shipped
screen disables that checkbox unconditionally (see "Ruling corrected" above,
§4b) and the controller refuses and audits any `requires_ack` id that reaches
it anyway. Both passages corrected to describe the shipped behaviour, with the
original proposal kept in the HANDOFF block as a superseded note rather than
deleted outright.

## 4d. Gate 2 remediation, second round (code-reviewer's rejection, closed by integrations-engineer)

**BLOCKING — the connector's own outbound URLs were never checked against
anything.** `token_base_url`/`graph_base_url` were validated as `url` and
nothing else, and `EntraGraphClient` fetched both server-side with no further
check — the `@odata.nextLink` a page returns and the `delta_link` this
application stores and replays on every subsequent run were followed as
absolute URLs too. Consequence: a `bcms.identity.manage` holder — a role that
may WRITE `client_secret` but never READ it back (ADR 0018 §5) — could point
`token_base_url` at their own host and recover the plaintext secret the moment
"Test connection" POSTs the client-credentials form to it, or point
`graph_base_url` (or a stored `delta_link`) anywhere to make the server fetch
on their behalf. The product already owns a general SSRF guard
(`App\Support\Http\OutboundUrlGuard::assertSafe()`, used by the webhook and
generic-connector paths) but it is not sufficient here on its own: an
attacker's own server is a perfectly public https host, which is exactly what
that general check exists to let through. Fixed with a second, closed check
underneath it: `App\Support\Bcms\DirectoryHostGuard::assertAllowed()` calls
`OutboundUrlGuard::assertSafe()` first, then requires the host to be one of
six FQDNs named in `config('bcms.identity.allowed_hosts')` — Microsoft's
identity platform and Graph across the commercial, US Government and China
clouds (ADR 0018 §3.1's scope) — deliberately NOT tenant-editable, so widening
it is a code change, not a form field. Wired in at three points: `Update
IdentityConnectorRequest::withValidator()` (a field error at save time, not a
500 the first time a sync runs), and `EntraGraphClient::token()` / `::send()`
(the runtime check that actually matters — a value that was allowed when
typed is not re-validated on every subsequent read). Checking inside `send()`
specifically, rather than only at the top of `users()`/`delta()`, is what
covers every `@odata.nextLink` page and a stored `delta_link` alike: all four
public methods (`users()`, `delta()`, `managerObjectId()`, `testConnection()`)
funnel their GET through that one method, so a link that has drifted off the
allowlist since it was issued is caught before it is fetched, not only on a
run's first request. A disallowed host raises a bounded
`token_host_not_allowed`/`graph_host_not_allowed` `DirectorySyncException`
carrying the refused host itself (capped to 40 chars) as `error_code` — safe
to carry unredacted because it is data the admin put in the connector's own
config, not a provider's response (ADR 0018 §2.3 is about never trusting the
latter). Tests: `Phase2cScreensTest::
a_private_address_graph_base_url_is_refused_at_save_not_at_the_first_sync`,
`::an_http_token_base_url_is_refused_at_save`, and `Phase2cRuntimeTest::
a_delta_link_that_has_drifted_off_the_allowed_host_fails_the_run_and_reaches_it_never`
— the last one stores a `delta_link` pointing at a rogue host, runs a real
delta, and asserts `Http::assertNothingSent()`: not even the token request
happens, because the guard is checked before `send()` ever calls `token()`.

**Advisories fixed:**
- **2 — the 429 branch's `Retry-After` header went into `error_code`
  uncapped.** "A bounded integer" describes Graph's OWN contract, not a
  guarantee this class can rely on for a raw `varchar(40)` write. Capped with
  `mb_substr(..., 0, 40)`, matching `classifiedFailure()`'s existing cap on
  Graph's `error.code`. `Phase2cRuntimeTest::
  a_sixty_character_retry_after_header_is_capped_to_the_column_width` pins it.
- **3 — `DirectorySyncService::runFull()` drained the whole directory read
  into an array before detection could start, defeating the point of
  `EntraGraphClient::users()` being a generator; `ChangeDetector::
  preloadRoster()`'s contact query selected every column, including several
  from `ChangeApplier::NEVER_WRITE` (`next_of_kin`, `geo_last_known`,
  `push_token`...), holding data a directory sync has no business touching in
  memory for the whole run.** Fixed together, because the two were coupled:
  `runFull()` buffered into an array specifically so it could check "did the
  read finish" (to decide whether the leaver sweep may run) BEFORE calling the
  detector — a decision that can only be known by having fully consumed the
  read, which is exactly what buffering did. The fix moves that decision
  inside `ChangeDetector::detectFullReconciliation()` itself: it now accepts
  the provider's generator directly, catches a `DirectorySyncException` raised
  mid-iteration itself, stages everything already yielded before the failure,
  skips the leaver sweep only when a failure was actually caught THIS call,
  and returns the failure (if any) as a third array key rather than requiring
  the caller to have precomputed it. `runFull()` no longer holds an array of
  `DirectoryUser` objects at all. `preloadRoster()`'s contact `get()` is
  narrowed to the twelve columns the class actually reads. No behaviour
  changed for any caller — `criterion_8_a_mid_run_failure_produces_a_partial_
  status_and_a_bounded_error` (a `FakeDirectoryClient::failAfter()` fixture)
  already proves the failure-then-no-sweep contract and passes unchanged.
- **4 — the leaver sweep ran a second, chunked query over a table
  `preloadRoster()` had already read in full for the match-key maps.** Now
  iterates `$roster['contacts_by_guid']` directly (filtering `is_active` and
  `source` inline, the same two conditions the old query's `WHERE` clause
  carried) instead of a fresh `chunkById()` — one read of `bcms_contacts` per
  run, not two.
- **5 — an already-deactivated contact whose Entra object was still present
  and still disabled staged a `contact_change`/`mover` on ordinary attribute
  drift** (a title or department correction Graph still reports for someone
  who has already left and stayed disabled). `diffAgainstExisting()` now
  returns `null` immediately when `! $existing->is_active && ! $user->
  accountEnabled`, right after the existing leaver check — the reactivation
  branch further down is unaffected, since it only ever fires when
  `accountEnabled` is true, which this guard's condition excludes.
  `Phase2cIdentitySyncTest::
  an_already_inactive_and_still_disabled_account_stages_no_attribute_drift`
  pins it: a drifted title AND department together stage nothing at all.
- **6 — `IdentityController::update()` could 500 rather than refuse cleanly**
  when `TenantContext::organizationIdOrNull()` returned null on a CREATE (the
  `organization_id` column is NOT NULL). `ResolveTenant` middleware already
  makes this practically unreachable from a real web request, but the
  controller now checks explicitly and flashes an error rather than relying
  on that being the only thing standing between here and a `QueryException`.

## 4e. Gate 2 remediation, third round (code-reviewer's rejection #3, closed by integrations-engineer)

**BLOCKING defect 1 — the delta path treated a partial Graph payload as a
complete user, silently reactivating leavers, crashing on an unmatched
partial object, and mishandling `@removed` tombstones.**
`/users/delta` returns only the properties that changed since the last delta
query, plus `@removed` tombstones for deleted or out-of-scope objects — and
`DirectoryUser::$accountEnabled` was a plain `bool` whose only source,
`fromGraphAttributes()`, defaulted a missing key to `true`. Three
consequences, all from the same root cause: (a) a delta entry for an
already-inactive contact carrying nothing but a changed job title read as
"still enabled", forcing `is_active` false→true with no human decision, then
flipped back by the next nightly full — a departed member of staff briefly
back on the live emergency roster; (b) a delta entry for an object this
connector had never matched to a contact was staged as a joiner from whatever
few fields DID come through, and `bcms_contacts.full_name` is `NOT NULL` — a
partial entry with no `displayName` threw on every apply, failing the run
every fifteen minutes; (c) an `@removed` tombstone (`id` and `@removed` only,
nothing else) was handed straight to `fromGraphAttributes()`, read as an
ordinary unchanged user, and the leaver was silently never staged at all.
Fixed in three places that each close one hand-off in the chain:
`DirectoryUser::$accountEnabled` is now `?bool` — `null` means "not reported
by this read", documented as load-bearing tri-state rather than a default,
and `fromGraphAttributes()` uses `array_key_exists()` rather than `??`, so
absence becomes `null`, never `true`. A full read is unaffected in practice —
`DirectoryAttributeMap::selectFields()` names `accountEnabled` in every
`$select` this phase sends, so `EntraGraphClient::users()` and
`FakeDirectoryClient::users()` never construct one with `null`; if a future
change makes a full read's `$select` narrower, that is the moment to revisit
every caller that currently trusts it unconditionally.
`EntraGraphClient::delta()` now recognises `@removed` explicitly and forces
`accountEnabled = false` onto the raw payload before it reaches
`DirectoryUser` — Graph's own `changed` (soft-deleted / moved out of scope)
and `deleted` (hard-deleted) reasons are collapsed to the same signal, since
both mean "gone from the live directory" for this module's purposes; nothing
downstream needs to know a tombstone was involved, it is staged as the
ordinary "account disabled" leaver `ChangeDetector` already knew how to
handle. `ChangeDetector` itself now compares the tri-state property
explicitly — `=== true` / `=== false` / `!== true`, never a bare
truthy/falsy check — in `joiner()`, `detectDelta()`'s existing-contact
leaver guard, `diffAgainstExisting()`'s two disabled-contact guards, and the
`$reactivating` computation, and `detectDelta()`'s unmatched-object branch now
refuses to stage a joiner when `accountEnabled === null` (logging and
counting it instead) rather than treating "unfamiliar" as "new". Tests:
`Phase2cIdentitySyncTest::
a_partial_delta_entry_for_an_already_inactive_contact_does_not_reactivate_it`,
`::a_partial_delta_entry_for_an_unmatched_directory_object_stages_no_joiner`,
`::a_removed_tombstone_stages_a_leaver_with_the_usual_impact_treatment` (the
last one routed through the real `EntraGraphClient` + `Http::fake()` rather
than `FakeDirectoryClient`, because the defect this one guards lives in the
client's own raw-JSON translation — a `FakeDirectoryClient` fixture built
with `accountEnabled: false` would pass whether or not the client had ever
been fixed), and `::
entra_graph_client_delta_reports_unreported_enablement_as_null_and_a_removed_tombstone_as_disabled`
proving the translation directly. `FakeDirectoryClient` gained
`withPartialDeltaEntry()` and `withRemovedTombstones()` so both shapes are
expressible by name rather than by hand-building a `DirectoryUser` with the
right nulls scattered through its twelve positional constructor arguments.
All three new `ChangeDetector`-facing tests were confirmed to fail on the
pre-fix code before being left green — (i)/(ii) via a `TypeError` from
`DirectoryUser`'s previously non-nullable `$accountEnabled` (a valid failure
mode: those two scenarios are only expressible once the property accepts
`null` at all, and the reactivation/joiner guards were already
null-safe-by-accident of PHP's falsy coercion once the type allowed it, so
the explicit `=== true`/`=== false` rewrite is a code-quality improvement
layered on top rather than independently load-bearing for those two cases);
(iii) was verified the honest way — temporarily reverting both the
`DirectoryUser` default and `EntraGraphClient::delta()`'s tombstone handling
and re-running it, which failed with `leaver_count` `0` instead of `1`, then
restored.

**BLOCKING defect 2 — `TreeProposalService::chainCoverage()` counted only
`manager_user_id`, while `arrange()` (the tree generator itself) already
reads `manager_contact_id` first.** A directory-sourced contact — the Phase
2C output this screen exists to describe — carries `manager_contact_id`,
never `manager_user_id`; a guard, a cleaner or a contractor has no
`bcms_users` row to link at all. A fully-linked, directory-sourced department
reported 0% coverage on the exact figure that decides whether "generate a
call tree" is worth pressing. Fixed by counting either edge:
`whereNotNull('manager_contact_id')->orWhereNotNull('manager_user_id')`.
`Phase6CallTreeTest::chain_coverage_counts_a_directory_sourced_manager_contact_link`
pins it — confirmed to fail pre-fix (expected 1/50.0%, actual 0/0%) by
temporarily reverting the query and re-running.

**BLOCKING defect 3 — `IdentityDemoSeeder` created its demo connector
`is_active: true` on `nightly_plus_delta`, pointed at the real Microsoft
hosts with fake credentials.** After seeding, `bcms:sync-directory --delta`
queued this connector every fifteen minutes, each run POSTing junk
credentials to `login.microsoftonline.com` and waking `BcmsWatchdog` on every
failure — and it contradicted ADR 0018 §2.2 point 5 (a connector is created
disabled until a human runs "Test connection"). Fixed by seeding
`is_active: false`; the seeded run history (200 contacts, two runs) is
unaffected, since the seeder's own `DirectorySyncService::runFull()` calls
never read the flag — only the scheduled command loop does.
`Phase2cRuntimeTest::the_demo_seeders_connector_is_inactive_and_the_scheduled_delta_skips_it`
asserts both the flag and that `bcms:sync-directory --delta` pushes nothing
for it.

**Advisory 4 fixed — the config comment above `bcms.identity.allowed_hosts`
claimed a redirect "fails the run", which was not true against Guzzle's own
defaults (up to five redirects followed, and a 307/308 preserves the method
AND body).** The token request carries `client_secret` in its body, so a
redirect followed there would have POSTed the secret to wherever the
`Location` header pointed, before `DirectoryHostGuard` ever got a look at
that second host — the guard only runs on the URL this class chose to fetch,
not on one Guzzle followed on its own. Fixed with `->withoutRedirecting()` on
both the token POST (`EntraGraphClient::token()`) and every Graph GET
(`EntraGraphClient::request()`), and the config comment corrected to say so.
`Phase2cRuntimeTest::a_307_from_the_token_endpoint_is_never_followed_to_another_host`
fakes a 307 from the token host to an off-list host with no fake registered
for the target, asserts the run ends `token_http_307`/`Failed` with exactly
one request recorded, and was confirmed to fail pre-fix — without
`->withoutRedirecting()`, Guzzle's real redirect middleware (present in the
handler stack `Http::fake()` builds too) actually follows the 307, and
`Http::preventStrayRequests()` catches the second request with a
`StrayRequestException` rather than the test's own bounded assertion.

### Booked follow-ups from reviews #3–#4

Advisories 5–10 of rejection #3 are deliberately not done in this cycle:

- **5 — the change-applying stager runs an N+1 pattern and a long
  transaction** over `stageAndApply()`'s per-entry queries and the single
  `DB::transaction()` wrapping every entry in a run.
- **6 — no maximum on the `change_ids` array** a bulk decide request accepts.
- **7 — a duplicate `contact.synced` audit row** — `ChangeApplier::apply()`
  and `applyJoiner()`/`applyFieldChange()` each record one.
- **8 — `EscalationService` N+1** unrelated to this fix cycle's scope but
  flagged in the same review pass.
- **9 — `DirectoryHostGuard` does not require `https` explicitly**, relying
  on `OutboundUrlGuard::assertSafe()`'s general scheme check rather than
  asserting it itself.
- **10 — `$select` is not derived from the connector's saved attribute map**,
  so a tenant that narrows the map still pays for every field
  `DirectoryAttributeMap::selectFields()` names.

Rejection #4's advisories 3–5 (§4f) are also deferred, out of scope for that
cycle's one blocking defect:

- **3 — a reactivation (`$reactivating`, `ChangeDetector::diffAgainstExisting()`
  :484) is classified `contact_change`, and `ImpactAssessor::assess()`:46
  skips that kind** — so a restore auto-applies with no impact assessment,
  unlike a leaver or mover.
- **4 — only `ChangeApplier::applyJoiner()`:105 writes `ad_object_guid`**, so
  a pre-existing, hand-created contact matched by `employee_id`/`email`
  never enters `preloadRoster()`'s guid map and can only leave via the
  disabled-in-place arm; `resolveManagerEdge()`:192 never resolves it either.
- **5 — the demo tenant shows the "not active" banner above two successful
  seeded runs** — one line in the demo script, no code defect.

## 4f. Gate 2 remediation, fourth round (code-reviewer's rejection #4, closed by backend-engineer)

**BLOCKING defect 1 — a PRESENT-BUT-NULL `accountEnabled` was coerced to
`false`, not left `null`.** Rejection #3's fix (`§4e`) made
`DirectoryUser::$accountEnabled` tri-state for an ABSENT key
(`array_key_exists('accountEnabled', $attributes)`), but Graph's
`accountEnabled` is itself a nullable `Edm.Boolean`: some guest/external and
partially-readable objects report the key WITH a JSON `null` value, not
merely omit it. `fromGraphAttributes()`'s `array_key_exists(...) ? (bool)
$attributes['accountEnabled'] : null` still cast a present `null` to `(bool)
null === false` — an explicit, wrong "disabled" reading of exactly the value
Graph itself reported as unknown. Consequence, all from the one line:
`ChangeDetector::diffAgainstExisting()`:432 (the leaver branch) staged a `leaver` for a matched,
still-active contact; `ImpactAssessor` found no call-tree/saved-group impact
(the contact was never actually a leaver, so it had none of the usual
signals); `AutoApplyPolicy::SafeOnly` (no impact, no `requires_ack`) applied
it with no human decision; `ChangeApplier::applyLeaver()` deactivated the
person. Fixed by checking `$attributes['accountEnabled'] !== null` alongside
`array_key_exists()` — both "absent" and "present but null" now produce
`null`, never `false`. `EntraGraphClient::delta()`'s tombstone handling
(:197, forcing `accountEnabled = false` onto an `@removed` entry before it
reaches `DirectoryUser`) is unaffected: it writes a real `false`, not a
`null`, so a genuine tombstone still stages a leaver exactly as before —
confirmed by re-running
`entra_graph_client_delta_reports_unreported_enablement_as_null_and_a_removed_tombstone_as_disabled`
and `a_removed_tombstone_stages_a_leaver_with_the_usual_impact_treatment`
unchanged, both still green.

Tests, both on the FULL-run path and routed through the REAL
`EntraGraphClient` (`Http::fake()`, not `FakeDirectoryClient` — the defect
lives in the raw-JSON translation, and a `FakeDirectoryClient` fixture built
with `accountEnabled: false` by hand would pass whether or not the fix
existed):
- `Phase2cIdentitySyncTest::a_full_read_reporting_accountenabled_as_an_explicit_null_does_not_leaver_a_matched_active_contact`
  — a matched, active contact stays active and `leaver_count` is `0` when
  Graph reports `accountEnabled: null` explicitly for it; confirmed to fail
  pre-fix (`leaver_count` `1`, contact deactivated) by reverting the fix and
  re-running.
- `Phase2cIdentitySyncTest::a_full_read_reporting_accountenabled_as_an_explicit_null_for_an_unmatched_object_stages_no_joiner_and_is_logged`
  — an unmatched object with `accountEnabled: null` stages no joiner and is
  logged (advisory 2 below); confirmed to fail pre-fix (`$captured` stayed
  `null` — nothing was logged) by reverting and re-running.

**Advisory 2 fixed — `ChangeDetector::joiner()` returned `null` for both
"explicitly disabled, never a contact" (correctly silent) and "enablement
unknown", with no way to tell the two apart from the logs.** `joiner()` now
takes the run's `organization_id` and, for the `null` case only, logs the
same context `detectDelta()`'s own unmatched-object guard already logs —
`organization_id` and `directory_object_id` only, never a name, mail or UPN —
under a path-specific prefix (`BCMS identity sync:` for the full run at
`ChangeDetector.php:391`, `BCMS identity delta:` at `:157`), so grep both
when counting occurrences in production.
This matters specifically for a FULL reconciliation:
`detectFullReconciliation()` has no pre-check ahead of `joiner()` the way
`detectDelta()` does (the delta path already intercepts and logs the `null`
case before ever calling `joiner()`, so this method's own new log branch is
reached only from the full-reconciliation caller). Pinned by the second test
above.

Advisories 3–5 are booked forward under "Booked follow-ups from reviews
#3–#4" rather than fixed in this cycle — each is either a larger design
question (3, 4) or a one-line demo-script observation with no code defect
(5).

## 5. Gaps handed forward

- **The two screens (`docs/bcms/screens/identity-connector.md`,
  `identity-change-review.md`) and the React pages
  (`resources/js/Pages/Bcms/Settings/Identity.jsx`,
  `resources/js/Pages/Bcms/Identity/Review.jsx`) are frontend-engineer's and
  ui-designer's, deliberately not touched here** — the props shapes both
  controllers and the presenter ship are the contract they build against
  (`IdentityPresenter::connectorScreen()`, `::runShow()`).
- ~~**NDPA register entry for `before_json`/`after_json`** (lawful basis,
  retention, residency, DSAR position) is compliance-analyst's, per ADR 0018
  §10, and is not written here.~~ **CLOSED 2026-09-17** —
  `docs/compliance/ndpa-register.md` **§7** is the Phase 2C entry (ADR 0018
  §10's five items, plus §10 of that file, which specifies the purge command
  none of the retention figures has). Three things in it are handed to this
  phase's implementers rather than to the gate: **§7.5.1** —
  `IdentitySyncChange` inherits the base `BcmsAuditable::auditExcluded()`, so
  `created` copies `before_json`/`after_json` into the append-only
  `bcms_audit_logs`, which has no purge path; the one-method fix is an
  `auditExcluded()` override. **Done before commit:** `IdentitySyncChange.php:75`
  excludes `before_json`, `after_json` and `impact_json`, pinned by
  `Phase2cIdentitySyncTest` (review #5 confirmed). **§7.9** — the DSAR export Phase 0
  promised at 2C was not built, and when it is it must key on
  `directory_object_id` as well as `contact_id` or it misses every rejected
  joiner (`contact_id` stays null for those, permanently). **§7.4** — the review
  queue renders `before`/`after` raw, so a `mobile_primary` change shows both
  numbers to any holder of `bcms.identity.review`; correct for the feature,
  and an observation for the architect on the permission boundary.
- ~~**`docs/adr/0017-*`'s pinned map entries** for the three new organisation-
  level models are owed once ADR 0017's remediation lands.~~ **Closed:** Phase 7.5
  (`2618781`) landed first and its `BcmsRecordVisibilityTest` pins all three
  (`IdentityConnector` at the organisation level, `IdentitySyncRun` and
  `IdentitySyncChange` route-bound); see the deviation note at the top of this
  file. Nothing is owed by the merge order.
- ~~**The delta path has no acceptance test** — see deviation 4 above.~~ **Closed
  2026-09-22:** gate 1 added the complete-object delta test and the review-#3/#4
  cycles added the partial-payload, `@removed` and explicit-null cases (§4e, §4f).
- **The dynamic-saved-group emptying check is a single-snapshot
  approximation** — see deviation 5 above. A full what-if resolver is a
  reasonable Phase 2D or hardening-phase improvement, not a blocker for this
  reduced phase's own criteria. **Gate 2 advisory 6** (§4c) is the same gap
  from the performance side: a per-run cache of a dynamic group's resolved
  ids would need to invalidate itself on every same-run auto-apply to stay
  correct, which is the what-if resolver in miniature — the same future pass
  should carry both.
- **Reliability review is done** (§4a) — `Phase2cRuntimeTest.php`, the
  `ShouldBeUnique`/`exclusively()` overlap guard, the Graph retry ladder and
  four `BcmsWatchdog` checks (stuck run, stale connector, stale hierarchy,
  expiring credential) are all in place and green. The one thing left from
  that pass is named just above: unify `BcmsWatchdog::hierarchyStale()` and
  `ConnectorHealth::fullReconciliationStale()` so the window logic lives once.
