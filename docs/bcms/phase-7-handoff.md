## HANDOFF

**Phase:** P7 — EMNS: Emergency Mass Notification System
**Agent:** main session (implementation only — `integrations-engineer` in spirit, not as a spawned agent)
**Status:** **partial**

> **THE QA GATE AND THE REVIEW GATE HAVE NOT BEEN RUN.** This session started
> 2026-09-07 22:54, before `.claude/agents/` existed, so `qa-engineer` and
> `code-reviewer` cannot be spawned from it. Under the working agreement added
> in `e64a436`, a green suite is an *input* to the gate, not the gate. Nothing
> below is certified.

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
- **Two defects were found and fixed in this phase; both are worth a regression test from qa-engineer** — the `'3'`-inside-the-token misclassification (a reply of "SAFE" recorded as NOT ON SITE) and the tenancy leak from running `DispatchAlertChunkJob` inline.

### Two contradictions in the new agent definitions — please fix the definitions, not the code

1. **"There is no SQLite" is false on this branch.** `qa-engineer.md:68`, `backend-engineer.md:104` and `code-reviewer.md:68` all say MariaDB 10.4 everywhere. But `riskerm-wt/bcms/phpunit.xml:41-42` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and **all 3,422 tests in this branch's suite run on SQLite.** The MariaDB switch landed on another branch and has not reached `feature/bcms-module`. This matters beyond tidiness: Phase 4 shipped a `JSON_CONTAINS` predicate that passed every SQLite test and would have failed on the only database a customer runs. Until `phpunit.xml` is changed here, that class of defect is still invisible. **Either merge the MariaDB test config into this branch, or the three agents are telling a fresh session something the suite contradicts.**
2. **"Chart.js for charts" is true of the product and wrong for BCMS.** `chart.js@^4.5.1` is in `package.json`, so `ui-designer.md:57` and `frontend-engineer.md:55` are not false — but **no BCMS screen uses it.** Every BCMS chart is inline SVG, and the reason is written in the file: *"Inline SVG and no chart dependency, which is the house convention (`Components/Quantification/SeriesChart`)"* — `CostRtoScatter.jsx`, `YearHeatGrid.jsx`, and the tier bars in `CallTrees/Results.jsx`. A fresh `frontend-engineer` told to reach for Chart.js would contradict six phases of shipped precedent. Suggest: "inline SVG is the BCMS convention; Chart.js exists in the product and is available where a full chart library genuinely earns its place."

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
5. Settle the SQLite contradiction above before certifying anything about database behaviour.
