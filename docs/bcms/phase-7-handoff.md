## HANDOFF

**Phase:** P7 — EMNS: Emergency Mass Notification System
**Agent:** main session (implementation only — `integrations-engineer` in spirit, not as a spawned agent)
**Status:** **certified 2026-10-16.** Both gates passed: `qa-engineer` on cycle 4, and `code-reviewer` with MERGE at its final text check (the re-review before it was REJECT for missing documents only). The merge is conditional on CI's `tests` matrix passing on both legs, `mariadb:10.4` and `mysql:8.0.46`. Criteria 1, 2, 6, 10 and 12 are carried to Gate G3 (see the gate record).

> Until 2026-10-16 this document opened with a marker saying the QA and review gates had not been run,
> because Phase 7 was built in a session that predates `.claude/agents/`. `Preflight::checkUncertifiedModulesAreOff()`
> reads this file, and while the marker was here it failed every deploy with `FEATURE_BCMS` on. The marker was
> removed because the gates below ran, not to tidy the file. `PreflightModuleGateTest` now asserts the gate record
> exists, and keeps the uncertified case alive with a fixture
> (`tests/Support/fixtures/uncertified-phase-7-handoff.md`).
>
> The implementation notes from here to "Gate record" are the original, uncertified handoff of 2026-09-07,
> kept as written. The criteria table in it is the implementer's own claim. The gate record at the end supersedes it.

---

### Delivered

**Adapters** (`app/Services/Bcms/Notification/`)
- `Channels/HttpChannel.php` — base for every real adapter: never throws for a provider failure, never retries, redacts credentials from the stored response
- `Channels/SmsGatewayChannel.php` + `Channels/FailoverSmsChannel.php` — three configured Nigerian gateways, health-based ordering, both attempts recorded on the receipt
- `Channels/SmsSegmenter.php` — GSM-7/UCS-2 segment counting and word-boundary truncation
- `Channels/WhatsAppCloudChannel.php`, `VoiceTtsChannel.php`, `SmtpEmailChannel.php`, `WebhookChannel.php` (Teams + Slack), `WebPushChannel.php`, `UssdChannel.php`
- `ProviderHealth.php` — delivery rate, latency, spend, circuit breaker; derived entirely from `bcms_notification_deliveries`
- `ChannelRegistry.php` — extended to build configured adapters and **fall back to the Phase 0 mock when a real adapter has no credentials**
- `app/Contracts/Bcms/Configurable.php` — new optional interface, added *beside* `NotificationChannel` because that one is frozen at G0

**Services** (`app/Services/Bcms/Emns/`)
`AlertService`, `AlertDispatcher`, `TemplateRenderer`, `RollCallService`, `EscalationService`, `InboundResponseHandler`, `EvidenceExport`

**Jobs** `app/Jobs/Bcms/DispatchAlertChunkJob.php`, `EscalateAlertRecipientsJob.php`

**HTTP** `AlertController`, `AlertTemplateController`, `AlertWebhookController`, `EmnsPresenter`, 18 routes in `routes/web.php`, 2 unauthenticated provider callbacks

**Screens** `resources/js/Pages/Bcms/Emns/{Index,Alert,Templates,Providers}.jsx`

**Config** `config/bcms-gateways.php` (new), `config/bcms.php` (`dual_approval` block)

**Seed** `database/seeders/Bcms/EmnsDemoSeeder.php`

**Docs** `docs/bcms/phase-7-notes.md`

**Tests** `tests/Feature/Bcms/Phase7EmnsTest.php` (25), `Phase7ScreensTest.php` (8)

### Contracts touched

- `NotificationChannel` — **implemented, not changed.** Still frozen as ADR 0004 left it.
- `ContactResolver`, `AudienceRule`/`AudienceResolver` — consumed unchanged.
- `bcms_notification_deliveries` — same table as the Phase 5 reminder path, same write-ahead discipline.
- **Schema: zero changes.** `bcms:verify-schema` reports no drift. No ADR needed.

### Assumptions made

1. **Dual-approval thresholds are deployment config, not tenant settings.** `bcms_settings` has no column for them and I did not add one. The tenant switch is the existing `require_dual_approval_for_live`.
2. **"Everyone" is not an audience.** The G0 grammar has no such leaf and `bcms_alerts.audience_rule` is `NOT NULL`; the whole-organisation case is the root org node with descendants. Fail closed.
3. **No Hausa, Yoruba or Igbo emergency copy was written** — see Known gaps.
4. **The live dashboard polls** rather than using SSE, as Phase 6 does.
5. **Digital signage / PA hook not stubbed.** An interface nobody implements is a liability.

### Acceptance criteria — my own assessment, for qa-engineer to check rather than trust

| # | Criterion | My claim | Evidence |
|---|---|---|---|
| 1 | 1,000 recipients / 3 channels / <60s + ack + escalation + export | **PARTIAL** | Functional half tested at 60 recipients × 3 channels. **Timing untested.** |
| 2 | 10,000 queued <30s, first SMS <60s | **NOT VERIFIED** | Chunked-queue shape tested at 1,200 against `bcms.nfr.dispatch_queue_seconds`. No load rig. |
| 3 | SMS gateway failover, both attempts recorded, health reflects | **SATISFIED** | 3 tests incl. all-gateways-refuse and connection failure |
| 4 | No dispatch above threshold without a second authoriser; attempt logged | **SATISFIED** | Incl. same-person-twice refusal and `alert.dispatch_refused` audit |
| 5 | Exercise alert defaults to simulation, prefix on every channel, dispatches nothing | **SATISFIED** | Asserts no adapter is called and every stored body carries the prefix |
| 6 | Roll-call 1,000 mixed; unresponsive escalate to managers; dashboard reconciles | **PARTIAL** | Escalation, one-message-per-manager, simulation suppression and silent-vs-unreachable all tested. **Not at 1,000** — demo estate exercises 364. |
| 7 | Two-way from SMS, WhatsApp and USSD onto the same recipient | **SATISFIED** *(USSD end-to-end `[verify at integration]` at W14, per the prompt)* | |
| 8 | Life-safety bypasses quiet hours; routine does not | **SATISFIED** | |
| 9 | Cost estimate within 5% of actual | **SATISFIED** | Estimate walks the dispatch path; measured drift asserted ≤5% |
| 10 | Five languages, SMS limits, no mid-word truncation | **PARTIAL** | Mechanism, limits and truncation satisfied. **Only `en` (+ Phase 0's `pcm`) authored.** |
| 11 | Immutable per-recipient evidence export | **SATISFIED** | Incl. a row for people nothing was sent to |
| 12 | Life-safety queue drains while `bcms-sync` is backed up | **PARTIAL** | Separate pools and supervisors asserted structurally. **Not load-tested.** |
| 13 | MFA enforced on dispatch | **SATISFIED** | Asserted on the route, and asserted *absent* on compose |
| 14 | Phase 5's dispatcher runs unchanged | **SATISFIED** | No Phase 5 file touched; its suite passes |

**Four of fourteen are not fully met: 1, 2, 6 and 12 on scale/timing, and 10 on translation.** All four scale items belong to Gate G3 (Phase 12) and should be carried there rather than closed here.

### Known gaps

- **No channel is live.** No credentials exist, so every adapter falls back to the Phase 0 mock. Correct state; every screen says so. Nothing can be verified end-to-end until the Nigerian paperwork lands — a commercial lead time, not a code gap.
- **ha / yo / ig emergency copy is not authored**, deliberately. `compliance-analyst` should own this before P11: the coverage grid, the recorded `locale_fell_back` flag and the inactive-on-creation rule are built and waiting.
- **Provider webhook signature verification is scaffolded only** — HMAC when a secret is configured, accepted when not, because some aggregators do not sign.
### Two regression tests the gate must not pass without

Both are defects found and fixed inside this phase. Neither had a test before it
bit, and both belong to a family rather than being one-offs — which is why they
are named here as gate conditions rather than left in the notes.

1. **Substring match on a numeric token.** `not_on_site` matched the bare string
   `'3'` with `str_contains`, and the acknowledgement token is sixteen hex
   characters — roughly two in three contain a 3. A person replying **"SAFE
   8a3f…" was recorded as NOT ON SITE**: a false negative in a roll-call, which
   is the direction that leaves somebody in a building. The test must use a
   **real token**, not a tidy fixture, or it proves nothing.
2. **Tenancy bypass from running a queue job inline.**
   `DispatchAlertChunkJob::handle()` clears `TenantContext` in its `finally` —
   correct for a worker, and it silently untenants everything after it when a
   seeder or a test calls the job directly. It cost a failed maturity assessment
   before it was found. Same family as the Phase 4 ICS feed and the Phase 6
   cascade acknowledgement route: **`OrganizationScope` is inert with no tenant
   resolved.**

### Two contradictions in the new agent definitions — please fix the definitions, not the code

1. **"There is no SQLite" is false on this branch — and the gap is wider than a
   wording fix.** *(Definitions corrected in `ff69cc0`; the configuration gap
   below is still open and is a gate blocker.)* `qa-engineer.md:68`, `backend-engineer.md:104` and `code-reviewer.md:68` all say MariaDB 10.4 everywhere. But `riskerm-wt/bcms/phpunit.xml:41-42` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and **all 3,422 tests in this branch's suite run on SQLite.** The MariaDB switch landed on another branch and has not reached `feature/bcms-module`. This matters beyond tidiness: Phase 4 shipped a `JSON_CONTAINS` predicate that passed every SQLite test and would have failed on the only database a customer runs. Until `phpunit.xml` is changed here, that class of defect is still invisible. **Verified across every branch, and it is a four-way mismatch:**

   | Where | Database |
   |---|---|
   | Production | **MariaDB 10.4** |
   | `migration/phase-7-shared-packages`, `fix/parent-cycle-guard` | `mysql` |
   | **`feature/bcms-module`, `feature/tprm-module`** | **`sqlite` / `:memory:`** |
   | **`ci.yml` matrix on both feature branches** | **`sqlite` + `mysql:8.0`** |

   The CI arm is the part worth pausing on. **`mysql:8.0` is not MariaDB 10.4.**
   MySQL 8 has CTEs, window functions and the full JSON function set that MariaDB
   10.4 largely does not — so that arm goes green on SQL production cannot run,
   while looking like real database coverage. TPRM's 3,098 tests are in the same
   position.

   **This is a decision for whoever holds the branch, not for a documentation
   commit — neither I nor the peer changed it.** My recommendation: switch
   `phpunit.xml` here to MariaDB **before** the P7 gates run, and expect fallout.
   Fixing CI the first time found a feature that had never worked on a real
   database; there is no reason to assume two feature branches are cleaner. What
   to hunt: raw JSON functions, CTEs, window functions, `information_schema`
   reads, `ONLY_FULL_GROUP_BY`, strict-mode inserts.
   `app/Services/Bcms/Exercises/CalendarService.php:410` is the good outcome.

   **Until that happens, no gate verdict about database behaviour means
   anything.**
2. **"Chart.js for charts" is true of the product and wrong for BCMS.**
   *(Corrected in `ff69cc0`. The peer went further and confirmed nothing in
   `resources/` imports `chart.js` on any branch — it is unused product-wide, and
   the claim came from development standard §8 Decision 4, which is aspirational
   rather than descriptive.)* `chart.js@^4.5.1` is in `package.json`, so `ui-designer.md:57` and `frontend-engineer.md:55` are not false — but **no BCMS screen uses it.** Every BCMS chart is inline SVG, and the reason is written in the file: *"Inline SVG and no chart dependency, which is the house convention (`Components/Quantification/SeriesChart`)"* — `CostRtoScatter.jsx`, `YearHeatGrid.jsx`, and the tier bars in `CallTrees/Results.jsx`. A fresh `frontend-engineer` told to reach for Chart.js would contradict six phases of shipped precedent. Suggest: "inline SVG is the BCMS convention; Chart.js exists in the product and is available where a full chart library genuinely earns its place."

### Verification run

- `php artisan test` → **3,422 passed, 4 skipped, 0 failed** (839s)
- `./vendor/bin/phpstan analyse` → 7 findings, all pre-existing and unchanged since Phase 2
- `./vendor/bin/pint --dirty` → clean
- `php artisan bcms:verify-schema` → no drift, 60 tables
- `npm run build` → clean
- `php artisan migrate:fresh --seed` → demo estate seeds; the drill reports 364 recipients, 1,010 deliveries, 32 unaccounted for (22 silent + 10 never reached), 11-minute headcount

**None of the above is a gate result.** It is the input a gate would read.

### Next agent

**`qa-engineer`** — in a **new session started in `riskerm-wt/bcms`**, because the agent definitions post-date this one.

First things I would put in front of them:
1. Re-run the full suite independently; do not trust the numbers above.
2. Decide criteria 1, 2, 6 and 12 — I claim partial and would not argue with a fail.
3. Check the two adapter rules by reading, not by testing: no adapter throws for a provider failure, and none retries internally. Those are the frozen interface's rules and the whole failover design hangs off them.
4. Confirm the credential fallback both ways: enabled-without-credentials must report `awaiting_credentials` and still dispatch through the mock.
5. **Settle the database configuration before certifying anything about database behaviour** — see the table above. This one is a blocker, not a caveat.
6. Require the two regression tests named above. The gate should not pass without them.

---

## Gate record — formal certification, 2026-10-16

Run in a worktree cut from `main` @ `463e0b3`, which is what production serves, so that `FEATURE_BCMS` could be switched on in production. The agents ran as spawned subagents, as the working agreement requires. The main session ran the full suite and handed its numbers to the gates. The agents that wrote the fixes did not certify them.

| Cycle | Agent | Verdict | What it found |
|---|---|---|---|
| Gate 1, cycle 1 | `qa-engineer` | **FAIL** | D1 a HELP reply dropped after any earlier reply (life-safety); D2 criterion 13 (MFA on dispatch) not enforced with `FEATURE_MFA_TOTP` off, the default; D3 a double dispatch gives a 500; D5 a chunk retry re-sends channels already sent. It also found three vacuous or structural-only tests, `training-link-exercises` with no test that ran it, and no test asserting any of the eleven `bcms:*` schedule entries. |
| Fix | `backend-engineer` | — | D1, D2, D3 and D5, against the owner's rulings below |
| Gate 1, cycle 2 | `qa-engineer` | **FAIL** | D6: the in-app respond route needed only `bcms.alert.view`, so any viewer could clear `needs_help` |
| Fix | `backend-engineer` | — | D6: the respond route requires `bcms.alert.dispatch` |
| Gate 1, cycle 3 | `qa-engineer` | PASS (conditional on the full suite) | — |
| Gate 2 | `code-reviewer` | **REJECT** | BLOCKER: `RollCallService::record()` not race-safe, so a concurrent SAFE could clear HELP; also `AlertDispatcher` wrote `Sent` from a stale model. HIGH: an operator clear wiped the person's own words; a bare-number HELP matching two alerts was dropped without trace; the evidence export of personal data was not audited. MEDIUM: an unsigned status callback could mark deliveries Failed and stored gateway prose verbatim; no UI reaches the respond route. Plus 12 follow-ups. |
| Fix | `backend-engineer` | — | the BLOCKER, the three HIGHs and the status callback, plus two docblocks naming `bcms:preflight` instead of `app:preflight` |
| Gate 1, cycle 4 | `qa-engineer` | **PASS** | — (one LOW observation: a bare-number HELP beyond 50 matches is dropped untraced) |
| Gate 2, re-review | `code-reviewer` | **REJECT (documents only)** | all code defects closed. It required an NDPA register entry for the cross-tenant HELP fan-out (added as `docs/compliance/ndpa-register.md` §12 by `compliance-analyst`) and this record, including the accepted gap below. |
| Gate 1, final check | `qa-engineer` | **PASS** | re-checked the certification edits; tightened the uncertified-fixture test, which a mutation showed was passing on the MFA check rather than the marker check |
| Gate 2, final text check | `code-reviewer` | **MERGE**, provided CI passes on both legs (`mariadb:10.4`, `mysql:8.0.46`) | the NDPA §12 entry, this record, `DEPLOYMENT.md` and the Preflight tests |

### Owner rulings (2026-10-16)

1. **HELP always wins.** A later `needs_help` supersedes `safe`, `not_on_site` or a status-less acknowledgement, and is audited with the previous value (`superseded: true`). A plain "received" no longer closes the recipient. No inbound reply can clear `needs_help`. Only a person holding `bcms.alert.dispatch` can, through `bcms.alerts.respond`. Decided on a `lockForUpdate` re-read of the row.
2. **Preflight blocks BCMS without MFA.** `Preflight::checkBcmsDispatchHasASecondFactor()` FAILS a deploy with `FEATURE_BCMS` on and `FEATURE_MFA_TOTP` off. With MFA on, the dispatch route's `mfa:strict` requires a verified second factor from everyone, whatever `mfa_required_roles` says. **`FEATURE_MFA_TOTP` is app-wide: every enrolled user is challenged at sign-in, and every dispatcher must enrol before go-live.**
3. **A bare-number HELP marks every open match** (capped at 50, inside the 48-hour response window). Each match is written in its own organisation's tenant context through the same locked path. Non-help ambiguous bare replies stay unmatched, not guessed. NDPA basis: vital interests (register §12; open question 17 is for the DPO).
4. **No screen control to clear `needs_help` is an accepted known gap** for go-live. `bcms.alerts.respond` is reachable only by direct POST. Help flags stay visible on the board and cannot be cleared wrongly. They cannot yet be cleared from the UI.

### Also changed

- An unsigned status callback can confirm a delivery but never move it to Failed. A signed failure stores a closed category, never the gateway's text.
- `Preflight::checkBcmsGatewaysHaveWebhookSecrets()` WARNS when BCMS is on and an enabled real gateway has no webhook secret.
- `Preflight::handle()` resets its results between runs.
- A double dispatch is refused under `lockForUpdate` and audited as `alert.dispatch_refused`.
- A chunk retry skips channels already sent.
- `alert.evidence_exported` is audited, with no personal data in the audit row.

### Tests

- **New:** `tests/Feature/Bcms/Phase7GateCertificationTest.php`, `tests/Feature/Console/BcmsScheduleCertificationTest.php` and `tests/Feature/PreflightBcmsMfaTest.php`.
- `PreflightModuleGateTest` was re-pointed at a fixture for the uncertified case.
- `Phase7EmnsTest`'s MFA assertion now expects `mfa:strict`.

Both mandatory regression tests from this handoff are present:

- **The real-token `not_on_site` substring case:** 60 minted tokens, including ids and tags containing "3", and end to end over the signed route.
- **The inline-job tenancy case:** `Phase7EmnsTest` at the `DispatchAlertChunkJob` and escalation tests.

Every scheduled `bcms:*` command now has a test that runs it, and the schedule table itself is asserted.

### Verification

- **Full suite, final tree, MariaDB 10.4.28** (a private instance with production's `sql_mode`): **5,074 tests, 0 failures, 1 skipped** (31 min, 6 workers).
  - An earlier full run, on the tree before gate 2's fixes, had 6 failures. Two were the `GraphQueryPerformanceTest` wall-clock budgets under contention. Four were `AssetResidencyTest` failing on a worktree with no `npm run build`. All 6 passed when re-run on their own.
- **MySQL 8.0.46:** not run locally; CI's `mysql:8.0.46` leg is the verdict. The new SQL is Eloquent builders and `lockForUpdate` only: no raw JSON functions, CTEs or window functions.
- **Static checks and build:** `pint` clean; `phpstan` clean on the changed files; `npm run build` clean.

### Carried to Gate G3, not closed here

| # | Criterion | Why |
|---|---|---|
| 1 | 1,000 recipients across 3 channels in under 60 s | timing is not load-tested; the functional half passes at 60 recipients |
| 2 | 10,000 queued in under 30 s, first SMS in under 60 s | no load rig; the per-severity queue routing is now proven by behaviour |
| 6 | roll-call of 1,000 mixed replies | not run at scale |
| 10 | five languages | ha, yo and ig emergency copy is not authored (owner: `compliance-analyst`, before P11); the mechanism, limits and fallback are tested |
| 12 | the life-safety queue drains while `bcms-sync` is backed up | asserted structurally only, not load-tested |

Also `[verify at integration]`:

- USSD end to end (W14).
- Every channel is still on the Phase 0 mock until gateway credentials exist.
- There is no SMTP transport.

### Follow-ups booked from gate 2 (not blocking)

- **Bare-number HELP cap:** the cap of 50 has no ordering, counts rows already at `needs_help`, and drops the 51st untraced.
- **Tenant context after a bare-number HELP:** it is left set to the last candidate's organisation.
- **`alreadySent()` is N+1**, querying once per recipient per channel.
- **`recipientForNumber` scans with a leading-wildcard `LIKE`.**
- **Webhook rate limits are still 600/600.** ADR 0016 §4's trigger for raising them to 2,000/10,000 has been met.
- **Cascade fan-out sends synchronously, with no lock.** This was accepted at Phase 6.
- **The `CascadeAckController` note** stores the unsigned `from` number.
- **Inline `$request->validate()`** remains in three controllers.
- **The criterion-2 test in `Phase7EmnsTest` is vacuous.**
- **The WhatsApp/Meta signature has no timestamp,** so those callbacks fail safe with 403.
- **`mfa_verified` has no recency check.**
- **Alerts never leave `dispatching`.**
- **`bcms:cascade-tick` is not `onOneServer()`.**
- **A misattributed `response_text` cannot be struck,** which matters for NDPA accuracy and erasure.

### Go-live prerequisites (production)

- `FEATURE_MFA_TOTP=true`, with dispatchers enrolled.
- A `BCMS_WEBHOOK_SECRET_*` for every gateway that goes live.
- The BCMS reference seeding.
- The `docs/compliance/ndpa-register.md` open questions for the client's DPO.
