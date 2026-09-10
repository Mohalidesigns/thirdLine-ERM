# Market-Readiness Analysis — Atheris ERM vs the Enterprise Tier

**Date:** 19 August 2026
**Codebase state:** branch `erm-remodel/wp00-wp06`, HEAD `559b74b` (19 Aug 2026, 17:07 WAT)
**Milestone this is written against:** sales demo & pilot pipeline
**Benchmark set:** Archer, MetricStream, IBM OpenPages, ServiceNow IRM — plus what is actually sold into Nigeria

**Method.** Full static audit of the extracted tree (835 PHP files, 116 migrations, 211 Blade views, 78 test classes) across five parallel work-streams, plus ~55 web searches against primary vendor, analyst and regulator sources. Every defect below carries `file:line` evidence and was re-verified independently before it appears here. Claims I could not prove are marked *unproven* rather than smoothed over.

---

## 1. The headline

**The update was real and it was large.** Six months ago this was a Laravel app with zero tenant isolation, zero route authorization, no API, no jobs, no PDF, and fabricated AI numbers on screen. Today it has a tenancy kernel enforced by CI, 334 of 334 live routes permission-guarded, a REST API with OpenAPI and scope-checked tokens, HMAC-signed SSRF-guarded webhooks, scheduled connectors, a working MCP server, an async seeded Monte Carlo engine, a workflow engine with real parallel gateways and SLA escalation, an effective-dated measure/threshold engine, configurable scoring profiles, config bundles promotable dev→prod, and a data grid with saved views, inline edit and XLSX export.

That is more platform than most Series-A GRC products carry.

**And you cannot demo it to a bank yet.** Not because features are missing — because of what is on the screen that nothing computed.

The three things standing between here and a credible demo, in strict priority order:

| | Class of problem | Why it decides the deal |
|---|---|---|
| **1** | **Numbers on screen that nothing computed** | A missing feature is a roadmap conversation. A fabricated Capital Adequacy Ratio in front of a CFO is a credibility event you do not recover from in that room. |
| **2** | **The architecture story has no screen** | The pitch is "object graph + configure-don't-code". Business HQ and the dashboard builder were retired last commit; the admin builder surfaces are routed but linked from nowhere. There is currently **no configurable dashboard in the product at all** — all four competitors open their demo on one. |
| **3** | **The demo loop does not close** | KRI breaches notify nobody. Launching an RCSA campaign tells no one. Completing a treatment plan changes no score. These are the exact five minutes every ERM demo is built around. |

Waves 0–3 below close all three. Estimated **35–45 engineer-days**. That is the honest number to demo-ready.

---

## 2. What the last update actually delivered — verified

Stated plainly, because the delta matters and the team should get credit for it.

**Genuinely working, proven by a read path:**

- **Tenancy.** `organization_id ?? 1` went from 166 occurrences across 22 files to **4, all in one Blade view, all neutralised by the global scope**. `TenantContext::organizationId()` *throws* rather than defaulting — `app/Support/Tenancy/TenantContext.php:77-91`. 67 of 91 models carry `BelongsToOrganization`; the 24 that don't have no `organization_id` column, and `tests/Feature/TenancyIsolationTest.php:65-86` fails CI if anyone adds one without the trait.
- **Authorization.** 334 live named routes, 267 `permission:` usages, and exactly **14 unguarded routes — every one an auth-flow route** (login, logout, password reset, SSO callback, MFA setup). Enforced by `tests/Feature/RouteAuthorizationTest.php:52-95` with a meta-test at `:158-172` that fails if the allowlist widens. The Livewire update route is closed too (`AppServiceProvider.php:89-91`) — the usual hole in this pattern.
- **API.** 22 allowlisted resources, scope *and* user-permission double-checked (`EnsureResourceScope.php:25-67`), unknown write fields refused rather than ignored, OpenAPI exposed via Scramble behind `permission:api.docs`.
- **Workflow v2.** Parallel fork/join with real arity counting (`WorkflowEngine.php:601,626-651`), exclusive gateways, SLA due dates, escalation that *actually reassigns* (`:362-369`), delegation with a cross-tenant guard, return-for-rework that abandons open parallel branches, and version pinning so a running instance stays on its version. Swept hourly by `workflow:sweep-slas`.
- **Measure engine.** Effective-dated threshold versions so a historic breach reads against the limit in force at the time (`KriMeasureBridge.php:102-120`) — better than most commercial implementations. Risk scores period-stamped on approval and read back via `RiskRepository::asOf()`.
- **Assessment chain.** `AssessmentChainService::deriveResidual()` is the best code in the repository: `L·r^p × I·r^(1-p)` where preventive controls move likelihood and detective controls move impact, exactly preserving `L·I·r`. Override is distinguished from derivation and requires justification.
- **Monte Carlo.** Seeded and reproducible, seed drawn at *enqueue* time so a replay reproduces the same capital figure, `tries = 1` deliberately, cooperative cancellation, no partial writes.
- **Document output.** Real PDF (dompdf) and real XLSX (PhpSpreadsheet with frozen panes, autofilter, and a formula-injection guard). Board pack with **honest empty states** — `sections/risk_profile.blade.php:52-56` literally prints *"N active risk(s) are not on this map. The map understates the register by that much."* That is better disclosure than most commercial GRC output.

**The diagnostic pattern from the last audit has narrowed but not closed.** "The schema is ahead of the application" is still the signature failure, and it has moved up a layer: the *engines* now exist, and it is the **read surfaces and the notification edges** that are missing.

---

## 3. Class 1 — Integrity: what a bank's model-validation function will find

This section is the one that decides whether you get a second meeting. Each item is verified.

### 3.1 A fabricated Capital Adequacy Ratio, on the Board Risk Report, in a bank demo

`resources/views/risk/reports/board.blade.php:51`

```blade
<x-kpi-card title="Capital Adequacy" :value="($capitalAdequacyRatio ?? 15.2) . '%'" … subtitle="Min: 10%" />
```

The controller does the right thing — `ReportController.php:213-215` sets the value to `null` when no ICAAP row exists, and `:246` writes the honest narrative *"No ICAAP assessment is on record for the current period."* Three lines later the view prints **15.2% in a green tile against a stated 10% minimum**. Both appear in the same screenshot.

Same file: `:49` `$riskProfileScore ?? '3.2/5'`, and `:44` a shipped narrative fallback inventing *"credit concentration in the oil and gas sector"*.

### 3.2 The ICAAP screens are a fabricated presentation layer over write-only storage

- **Pillar 2A is relabelled as Pillar 1.** `QuantificationController.php:508-510` reads `pillar2a_credit_kobo` into `$pillar1Credit`. The screen has no Pillar 1 figure at all. Pillar 1 is the regulatory minimum on RWA; Pillar 2A is the ICAAP add-on. These are not the same number.
- **The Pillar 2 breakdown is invented.** `:513-519` slices `pillar2a_other_kobo` by `0.3`, `0.25`, `0.25`, `0.2` and calls the results concentration, IRRBB, reputational and strategic risk. Nobody computed those splits; nothing in the schema holds them. Liquidity takes exactly half the stress buffer for no stated reason.
- **Stress results are hardcoded.** `:741-746` ships five scenarios with fixed CAR drops (`3.5`, `2.1`, `2.8`, `5.2`, `1.6`) and `:758` renders a **Pass / Marginal / Fail verdict** off them. These deltas are independent of the bank's balance sheet, RWA and portfolio mix.
- **CAR, CET1 and Tier ratios are never computed.** `cet1_capital_kobo` has **one occurrence in the entire codebase** — the `$fillable` array. Nothing divides qualifying capital by RWA.
- **The CBN minimum is hardcoded to 10% via a column that does not exist.** `:699` reads `$icaap->car_required ?? 10`; the migration defines `cbn_minimum_car`, so the `?? 10` always fires, and `:874` hardcodes the label *"CAR above CBN minimum (10%)"*.

That last one is a substantive regulatory error, not just a fabrication. **CBN's Guidelines on Regulatory Capital (September 2021) set 10.0% for national/regional banks and 15.0% for international banks and D-SIBs.** Every D-SIB in Nigeria — the exact institutions you most want in the room — would see a false "Compliant" verdict at 12.3% CAR.

### 3.3 `GAUSSIAN_COPULA` is the schema default, is exported to the customer, and does not exist

`database/migrations/2026_02_22_200031_create_simulation_runs_table.php:22`

```php
$table->string('correlation_method', 30)->default('GAUSSIAN_COPULA');
```

The engine sums scenario losses independently — `MonteCarloService.php:201` `$totalLosses[$i] += $annualLoss;`. There is no correlation matrix, no Cholesky decomposition, no copula anywhere. The seeder also stamps `gaussian_copula`, and `ExportController.php:348` writes the string **into the customer's CSV**.

Two consequences: diversification benefit is structurally unavailable (which is the entire point of aggregating operational risk categories for ICAAP), and the claim reaches the customer in a file they keep.

### 3.4 Four more fabricated or mislabelled statistics

| What the screen says | What the code does | Evidence |
|---|---|---|
| **"Expected Shortfall"** KPI on two dashboards | Returns `var_99_kobo`. The comment even says *"ES approximated as average of losses above VaR 95"* | `SimulationRun.php:122-128` |
| **"Correlation Analysis"** with 3-decimal coefficients and a Significance column | `$coeff = -1 * round(abs($score1 - $score2) / 25, 3);` under the comment `// Synthetic negative correlation` | `AnalysisController.php:688-720`, rendered `correlation.blade.php:56-57` |
| **"Tornado"** diagram | A p5→p95 range plot of scenario outputs. A tornado ranks *input parameters* by induced output swing | `TornadoResolver.php:14-15` |
| **Bow-tie consequences** on every risk | Reads `$risk->risk_consequence` — a column that does not exist — so the fallback always fires with three invented consequences | `AnalysisController.php:457,471-479` |

The bow-tie one deserves a note: the same file's docblock at `:451-455` congratulates itself on having fixed exactly this defect **on the cause side**. It survives verbatim on the consequence side.

### 3.5 Three mathematical defects in the engine itself

1. **Lognormal μ omits the −σ²/2 correction.** `QuantificationController.php:183` sets `$mu = log($meanKobo)`. For a lognormal, `E[X] = exp(μ + σ²/2)`, so every scenario simulates a mean severity **1.65×–3.08× larger** than the number the user typed. The correct relationship is used 800 lines later at `:988`.
2. **The severity tail is truncated at 4.29σ.** `MonteCarloService.php:102` clamps the Box-Muller uniform at `1e-4`, capping `z` and piling a point mass at the cap — precisely the region that sets the 99.9% quantile. This systematically understates VaR₉₉.₉ and any economic capital derived from it. The characterisation test only exercises σ=0.5, so it cannot see this.
3. **Six distributions offered, one implemented.** `:156` validates `lognormal,normal,poisson,pareto,weibull,beta`; the engine unconditionally calls `lognormalRandom()`. The shipped library includes scenarios explicitly typed `pareto` and `weibull`.

### 3.6 The systemic cause — the CI guard's threat model is wrong

`tests/Feature/NoFabricatedNumbersTest.php:37` greps for `mt_rand|rand|random_int|shuffle|…`. **Every fabrication in §3.1–3.4 passes it cleanly, because none of them uses an RNG.** The class docblock claims to guard against *"a number shown to a bank that nothing computed"*; it guards against one narrow spelling of that.

This is the single most valuable fix in the document, because it prevents recurrence rather than treating instances.

### 3.7 Unproven certification claims

`auth/login.blade.php:45` — `['COSO ERM Aligned', 'CBN ORMS Compliant', 'ISO 31000', 'Basel III Ready']`
`layouts/app.blade.php:170` — `CBN ORMS Compliant · Basel III Aligned · NDPA Certified`

There is no certificate or audit reference anywhere in the repository. **"Certified" is a legally loaded word**, and NDPA compliance is the one a Nigerian bank's DPO will ask to see evidence for — Fidelity Bank was fined ₦555.8m for data-privacy breaches in August 2024, so the question is not hypothetical.

---

## 4. Class 2 — The architecture story has no screen

Your differentiation thesis is the metadata-driven object graph and configure-don't-code. Both are now largely unreachable from the UI.

### 4.1 The widget engine was retired last commit — and there is now no configurable dashboard at all

`routes/web.php:133-163` retires `/hq` and `/risk/dashboards`, with a clear-eyed rationale (*"neither earned its place, and the org-tree page in particular rendered 'No dashboard published for Enterprise' for most nodes"*). The retirement was done properly: the engine is intact, the tests were updated honestly, and `resources/js/app.js:47-70` documents exactly how to bring it back (`npm install gridstack`, restore two imports).

But the collateral is severe:

- **~6,450 lines of dashboard engine are now dead code** — 21 widget type resolvers, the widget panel, the dashboard builder, `WidgetQueryEngine`, `WidgetContextResolver`, plus the `widget_definitions` / `dashboards` tables (still seeded by `DatabaseSeeder.php:99`).
- **The only remaining dashboard is one hardcoded page.** It is genuinely good — 8 sections, 7 charts, period-aware via `RiskRepository::asOf` — but it is one page.
- Archer, MetricStream, OpenPages and ServiceNow **all open their demo on a drag-and-drop dashboard designer.** This is the first thing the room sees.
- `NetworkResolver.php` — the only visualisation of graph edges anywhere in the product — is inside the dead zone. The object-graph story has literally no picture.

This is the cheapest large capability recovery available to you. Two days plus polish, versus rebuilding.

### 4.2 The entire configuration surface is unlinked from the navigation

Routed, functional, permission-gated, and reachable only by typing the URL: object-type builder, attribute builder, relationship-type builder, lifecycle builder, scoring-profile builder, config bundle export/diff/apply/rollback, connectors, webhooks, API tokens, job runs, SSO settings.

`grep` for links to them outside `views/admin/` returns **two hits**, both deep-links between builder screens. The Administration dropdown (`sidebar.blade.php:407-441`) contains only Users and Settings — and its outer gate is `@role(['super-admin','chief-risk-officer'])` while every inner item is `@role('super-admin')`, so **a CRO opens an empty menu**.

"Configure, don't code" is the pitch, and it cannot be shown without typing URLs.

### 4.3 Configured fields are write-only on every screen that has them

`<x-dynamic-form>` is live on 5 real domain screens (controls, KRIs, issues, treatments, emerging risks) — that is real work. But **`<x-dynamic-detail>`, the read component, is used in zero views.** Add a field in the builder, fill it in on create, save, open the record: it is on no detail page, no list, no export.

The inverse hole exists on Risk: it gets the Livewire attribute editor on `show` but its create/edit forms are hand-written with no `<x-dynamic-form>`.

And a tenant-defined object type cannot be instantiated at all — `app/Livewire/DynamicForm.php:161-163` throws *"This record has no graph object to attach attributes to yet"*, because there is no route, controller or view that creates a record of a custom type.

### 4.4 The graph goes stale the moment the demo starts

`object_relationships` is populated by the migration and then frozen. `HasObjectIdentity::relate()` is called **from tests only**; live code writes the old pivots — `RiskRegisterController.php:326` `RiskControlMapping::create([`, and the same in `ControlController.php:89,248`. There is no observer.

So every control↔risk link a prospect creates during a demo exists in `risk_control_mapping` and **not** in the graph. The graph API, the MCP server and workflow relationship-routing all return stale or empty results for it.

Related: `GraphQueryService::rollUp()` and `traverse()` have **no production callers** — roll-up is still the hand-rolled `Risk::calculateRollUpScore()`, whose only caller is a test. `object_versions` has three writers and zero readers. `ObjectLifecycle::allowsTransition()` is never enforced.

### 4.5 Node-scoped authorization is dead code — and it is the thing a bank buys

`app/Models/Concerns/ScopedToGraph.php:20-27` defines `visibleTo($user)`. **The only occurrence of `visibleTo(` in `app/` is inside that trait's own docblock.** No controller, no grid, no Livewire component, no API endpoint calls it.

`RisksGrid::query()` and `RiskRegisterController::show()` apply tenancy only. So a user pinned to a branch (`users.scope_entity_id` set) sees **the entire organization's** register, controls, issues, loss events and KRIs in every index and every export, and can open any record by ID — while `tests/Feature/GraphScopeTest.php:126-141` proves the mechanism works and is simply never invoked.

Delegated branch visibility is the reason a multi-subsidiary Nigerian banking group buys a platform instead of a spreadsheet. It is built and not wired.

---

## 5. Class 3 — The demo loop does not close

These are the five minutes every ERM demo is built around: *breach → alert → assess → treat → the number moves.* Currently each link is broken.

| # | Break | Evidence |
|---|---|---|
| 1 | **A KRI breach produces no notification of any kind.** The single most-demoed CBN control is silent. | `CheckKriBreaches.php:234` dispatches the event → `SendNotification.php:89` `$actorId = auth()->id();` is `null` in console → `:92` logs a warning and discards |
| 2 | **All domain-event notifications are dropped on a real queue.** The listener is `ShouldQueue`, so `auth()->id()` is always null on a worker. Your `.env` runs `QUEUE_CONNECTION=redis`. | `SendNotification.php:8,89-96` |
| 3 | **Launching an RCSA campaign notifies nobody**, and campaign assignments appear on no queue — `MyResponsibilitiesService` has no campaign source. A campaign launched on stage sits invisible. | `CampaignController.php:125-136`; `MyResponsibilitiesService.php:63` |
| 4 | **Completing a treatment plan changes no score.** The listener guards on `expected_risk_reduction_percentage` — a column that does not exist. The real column is the JSON `expected_risk_reduction`. The guard is always falsy. | `TriggerRiskReassessment.php:20` |
| 5 | **`treatments:check-overdue` crashes every night** on its first overdue plan — writes `$treatment->responsible_user_id` (the column is `owner_id`) into a `NOT NULL` FK. Scheduled daily. | `CheckOverdueTreatments.php:42` |
| 6 | **The issue escalation ladder dies at level 1, silently.** Candidates are `OPEN/IN_PROGRESS/OVERDUE`; escalation sets status to `ESCALATED`, so the issue leaves the candidate set forever. No notification is sent at all. | `IssueEscalationService.php:20` vs `:62` |
| 7 | **Editing one control silently overwrites board-approved residual scores** with a different number computed a different way (scalar, no axis split), contradicting the approved assessment. Demo script "update a control → watch the risk move" produces a number that doesn't match. | `ControlEffectivenessService.php:58-62` vs `RiskAssessmentBinding.php:90-95` |
| 8 | **The Custom Report Builder ignores every option the user picks.** Four report types and a section checklist are offered; `sections` is validated and discarded, and the payload is hardcoded to `risk_register`. All four produce the identical file. | `ReportController.php:486,509` vs `custom.blade.php:37-41,91` |
| 9 | **A gold "AI Risk Statement Builder · Local LLM · Granite" panel errors on click, on four create screens.** `LLM_ENABLED` defaults false and the endpoint is `localhost:11434`. | `register/create.blade.php:38-70` + 3 others; `config/services.php:38` |
| 10 | **The sidebar has zero permission gating** — all 22 sections and ~90 links render for every user, every target route is guarded, and there are **no error views**, so one click gives an unstyled Symfony "Forbidden". | `sidebar.blade.php:367`; no `resources/views/errors/` |
| 11 | **Two approval queues in the nav, one permanently empty.** The migration supersedes every pending legacy approval into the engine; the sidebar still links to the legacy queue. | `sidebar.blade.php:118`; `…120004_migrate_open_approvals…:154` |
| 12 | **The "Risk Intelligence" nav section can never render** — built into `$sections[]`, iterated from `$navSections`. | `sidebar.blade.php:306` vs `:367` |
| 13 | **`on_timeout: notify` reminds hourly, forever** — no dedupe, unlike the escalate path. The CRO (168h SLA) and the Board (720h) are on that path. Two days into a demo tenant the board approver has ~50 identical notifications. | `WorkflowEngine.php:892` vs `:941` |

### And the security items a bank's review will fail on

MFA is comprehensively broken, and it is the first thing an infosec reviewer tests:

- **`verifyMfa()` never logs the user in.** `login()` calls `Auth::logout()` at `:98`; `verifyMfa()` validates the code, sets `session(['mfa_verified' => true])` at `:413`, and redirects. `Auth::login` appears **nowhere** in the file. Any user with `mfa_enabled = true` cannot complete sign-in.
- **The TOTP counter is 4 bytes, not 8.** `:480` `pack('N', $time)` — RFC 6238 requires an 8-byte big-endian counter. **No authenticator app will ever produce a matching code.**
- **The MFA seed is sent to a third party.** `:466` builds `https://api.qrserver.com/…?data=otpauth://totp/…secret=$secret&issuer=…` and `mfa-setup.blade.php:62` renders it as an `<img>`. The user's browser hands the second-factor seed and their email to an external service. `AssetResidencyTest` exists to prevent exactly this and its forbidden-hosts list omits this domain.
- `mfa_secret` is plaintext, mass-assignable and not in `$hidden` — while `WebhookSubscription.secret` and `Connector.credentials` are correctly `encrypted`.
- MFA is **off by default for every tenant** (`mfa_required_roles` is set by no seeder, migration or config), SSO bypasses a personally enrolled second factor, and SSO+MFA is an infinite redirect loop.
- **No rate limiting on any auth route** — zero `throttle` middleware in `routes/web.php`. `verifyMfa` has no attempt counter, and the 5-attempt lockout is a targeted-DoS primitive against any known email.

Plus two file-storage blockers: control-test evidence and data imports are stored on the **public disk** and are reachable at `/storage/…` with no session (`ControlTestController.php:266`, `DataImportController.php:42`) — audit evidence on a webroot fails a bank review on sight. And the loss-event upload endpoint still accepts **any MIME type up to 20 MB** with `file_type` taken from the client (`LossEventController.php:552,564`), while every other upload path in the codebase was hardened.

---

## 6. Where you stand against the enterprise tier

### 6.1 The analyst picture is more helpful than it looks

- **Gartner retired the IRM Magic Quadrant.** The successor is the *MQ for GRC Tools, Assurance Leaders* (27 Oct 2025). Leaders: Archer, AuditBoard/Optro, Diligent, IBM, LogicGate. MetricStream and ServiceNow are **Challengers**. The Visionaries quadrant is **empty — reportedly for the first time in nearly two decades.** Critically, Gartner narrowed scope to *assurance leaders* (internal audit, compliance, ethics), not the CRO. **If your buyer is a CRO, that MQ is weakly aligned to your use case — and to your competitors' Leader claims.** Say so in the room.
- **Forrester Wave: GRC Platforms, Q2 2026** (~27 May 2026, 12 vendors). Leaders: Diligent, Optro, Vanta. MetricStream: Strong Performer. Archer scored 5/5 on Compliance Management but did not claim Leader status. *I could not verify whether IBM or ServiceNow were even included.*
- **No 2026 GRC MQ and no current Critical Capabilities exist.** Do not let anyone build a shortlist on a document that does not exist.

**Forrester's four market judgements are the most commercially useful content published this year, and three of them are openings for you:**

1. *"Much of the current AI functionality boosts current capabilities rather than the promised transformational change"* — customers cite high costs and functional limits. **Gen-AI summarisation is now table stakes with zero differentiating weight.**
2. **Continuous controls monitoring is the single weakest criterion across the entire evaluation.** Vendors collect audit evidence instead of monitoring control *performance* with automated remediation triggers.
3. **AI pricing is a mess** — "everything from no additional charges to fixed-price package additions to consumption-based pricing," confusing customers about value. ServiceNow's Now Assist reportedly adds per-user charges or burns token blocks at an estimated $50–100+/user/month, with first-year deployment typically 3–5× the annual licence.
4. Platforms must move "from a system of record to a system of action."

### 6.2 Scorecard against the 2026 ERM RFP checklist

0 = absent · 1 = schema only / services required · 2 = partial · 3 = OOTB complete

| Capability | You | Enterprise tier | Note |
|---|---|---|---|
| Risk register, hierarchy, versioning, audit trail | **2** | 3 | Register is solid; roll-up is hand-rolled and its only caller is a test |
| Multi-dimensional taxonomy, Basel L1–L3 OOTB | **1** | 3 | **L2 is a literal copy of L1; L3 never written; CBN ORMS event type and product line have no capture form** |
| RCSA campaigns, delegation, challenge, reminders | **1** | 3 | Submission works; no challenge state, no reminders, no notification on launch, questionnaire never answered |
| KRI thresholds, breach workflow, automated ingestion | **2** | 3 | Threshold engine is genuinely strong; **breach notifies nobody**; connector-ingested readings are invisible on the KRI list |
| Loss / operational risk event database | **2** | 3 | Excellent schema. 4 of 6 monetary columns never written; insurance "lifecycle" is one naira field; **no external loss consortium import (ORX/ORIC)** |
| Quantification (MC, LEC, VaR, FAIR, correlation) | **1** | 3 | Seeding and async are excellent; the mathematics is one hardcoded distribution, no copula, no CVaR, no FAIR, no backtesting |
| Controls library, design vs operating, CCM | **2** | 2 | **Nobody is good at CCM — Forrester's weakest criterion market-wide. Open goal.** |
| Issues, findings, actions | **2** | 3 | One issue object across modules ✓; escalation ladder dies at level 1, silently |
| Policy management | **0** | 3 | Absent |
| Third-party risk | **0** | 3 | Absent. Gartner's first TPRM MQ (Apr 2026) crowned *specialists*, not platforms |
| BCM / operational resilience, IBS, impact tolerance | **0** | 3 | Absent |
| Regulatory obligations, horizon scanning | **1** | 3 | 5 hardcoded thresholds, no clause-level register — see §7.2 |
| Internal audit, combined assurance | **0** | 3 | Absent |
| Reporting, dashboards, board packs | **2** | 3 | PDF/XLSX real, board pack substantive; **no configurable dashboard, no scheduled distribution, no charts in any PDF, regulator returns are bare CSV** |
| Workflow, no-code config, upgrade-safe promotion | **3** | 2–3 | **You beat MetricStream here** — its reviewers report upgrades "require re-implementing customizations"; your config bundles version, diff, apply, roll back and promote |
| Integrations, REST API, OAuth, SCIM, webhooks, MCP | **3** | 3 | At parity. MCP puts you level with OpenPages 9.1.3 |
| AI | **1** | 2 | Deliberately honest, deliberately last. Correct sequencing — but the four dead LLM panels must be gated |
| Mobile / offline capture | **0** | 0–1 | **No vendor of the four publishes genuine offline capture with conflict-resolving sync.** Archer's own reviewers call its mobile "very elementary" |
| Security, certifications, residency | **1** | 3 | MFA broken; no SOC 2 / ISO 27001; **but see §7.1 — residency inverts this** |
| Deployment flexibility | **3** | 1–3 | ServiceNow SaaS-only; Archer Evolv SaaS-only; IBM is the only one with real on-prem breadth |

**Read across the bottom four rows.** You are behind on module breadth in a way that cannot be closed before a demo — and you should not try. You are at or ahead on workflow, config promotion, integrations, deployment flexibility and residency. That is the ground to fight on.

---

## 7. The Nigerian market — what actually changes the pitch

### 7.1 Residency is your single strongest structural advantage, and it has a clock

**CBN Circular PSS/DIR/PUB/CIR/001/004, issued 15 June 2026** — verified independently through two Nigerian law firms. All payment transaction data generated in Nigeria must be stored and managed in Nigeria by **1 January 2027**. Cloud is permitted only if the core transaction database, **backups, logs and disaster recovery** all reside in Nigeria. Covered: DMBs, MFBs, MMOs, switching and processing companies, PTSPs, PSSPs, super agents.

**AWS, Azure and Google Cloud have no Nigerian region.** That is the decisive fact.

Consequence: **ServiceNow (SaaS only) and Archer Evolv (SaaS only) cannot satisfy this for covered entities.** IBM OpenPages survives via Cloud Pak for Data or on-prem; Archer Suite survives on-prem. Your hardened on-prem / Nigeria-resident tier is not a nice-to-have — it is a qualification gate that removes two of the four benchmark vendors from part of the market.

Nigeria has ~26–28 data-centre facilities (~50–56 MW live, 124 MW with expansion), concentrated in Lagos — OADC, Rack Centre, Kasi Cloud, Galaxy Backbone, Equinix. **The hosting-partner conversation should be open now.** There are ~4.5 months left and no engineering dependency blocking it.

Compounding this: the NDPC's GAID (effective 19 September 2025) has published **no adequacy country list**, so cross-border transfers need Commission-approved BCRs or SCCs. A foreign-hosted ERM tool holding personal data faces *two* separate residency constraints.

### 7.2 Your claimed regulatory moat is not built yet

This is the uncomfortable finding. The differentiator that justifies the whole positioning is currently:

- **5 hardcoded citations in one service**, of which **2 constants are dead** (never referenced by the evaluator).
- **One cites a statute that does not exist** — `'AML/CFT Act 2022'`. The instrument is the **Money Laundering (Prevention and Prohibition) Act 2022**.
- The NDIC entry conflates *deposit insurance cover* with a *loss-event reporting threshold* — a category error, and the cover has since been raised to ₦5m for DMBs.
- One alert is self-contradictory: type `EFCC_REPORTING_RECOMMENDED`, message *"EFCC reporting is mandatory"*.
- **BOFIA is cited nowhere.** `loss_events.bofia_reportable` and `issues.bofia_reportable` exist as columns and nothing writes them — the BOFIA leg of "CBN/NFIU/BOFIA/NDIC/EFCC" is a boolean that is always false.
- **Thresholds are not tenant-configurable.** They are PHP `const`s; the `QuantificationSetting` columns that should hold them are read by nothing. A circular changes → you cut a release. And the characterisation test *pins the constants as correct* rather than testing configurability.
- **No obligation register.** `compliance_pct` is a number a user types, and it drives the headline compliance metric. Zero circulars are seeded — the customer fills in the table themselves.
- **Filing produces no artefact** — a row with a free-text `document_ref` and a status flip. No file, no generated return, no receipt, no checksum.

None of the international vendors will ever build Nigerian regulatory content. **That is exactly why building it properly is the highest-value differentiating investment available to you** — but today it is claimed and not delivered, and a CRO who probes it will find that out in the demo.

**Corrections to make in the pitch before it embarrasses you:**

- **IFRS S1/S2 is voluntary in Nigeria through 31 December 2027.** The FRC's *amended* Roadmap (February 2026) makes it mandatory for PIEs only for periods beginning **on or after 1 January 2028**, SMEs 2030. Any claim of a 2026 mandate is wrong. The genuine commercial window is that PIEs need data collection running in 2026–27 to report FY2028.
- **A NAICOM ORSA requirement could not be confirmed to exist.** It is not in NIIRA 2025 and was not found in NAICOM regulations. Do not assert Nigerian ORSA obligations.
- The claimed CBN cybersecurity RCSA filing deadline of 28 February, and a "January 2026 CBN 30-minute fraud response directive", trace to **vendor blogs only**. Do not repeat them.

### 7.3 Three dated, concrete regulatory hooks nobody is serving

1. **NFIU goAML upgrade — live since 1 February 2026.** Automated rejection of CTRs filed for cross-border transactions, FTRs for domestic-only, and STRs lacking a predicate offence or indicator. 21 predicate-offence categories with uniquely coded indicators; a new PEP Transaction Report. **Entities using XML or B2B reporting must remap.** This is a dated, technical, painful integration problem with a named format — the most specific product hook in the entire research set.
2. **PenCom: monthly report on any risk rated high or very high, due within 7 days of month end** (RR/P&R/09/01). Plus a documented risk register and P×I matrix. That is a recurring, dated, Excel-hostile workflow across ~20 PFAs — all of which also face a **₦20bn recapitalisation by 31 December 2026**.
3. **CBN Corporate Governance Guidelines (effective 1 August 2023).** Board-approved ERM framework, reviewed for effectiveness annually and comprehensively every three years; Board Risk Committee meeting at least quarterly; internal audit effectiveness assessment filed with the Director of Banking Supervision **by 31 May**; external auditor management letters **by 31 March**. Hard-dated filing calendars are a board-pack and evidence-pack product.

### 7.4 Who you are actually competing against

**I could not find a single publicly documented ERM/GRC deployment at a named Nigerian bank, insurer or PFA** for Archer, MetricStream, IBM OpenPages, ServiceNow, IsoMetrix, CURA, Protecht, Camms, Ideagen, BarnOwl, SAP GRC or Oracle GRC. MetricStream's partner page lists **no African partner at all**. BarnOwl's ecosystem is scoped to South Africa.

Nigerian procurement is private, so treat that as low information rather than proof of no market. But two things follow:

- **Do not let a competitor assert Nigerian logos unchallenged** — those claims are not publicly verifiable.
- **The thing in the room is more likely Excel, a Big-4 build, or Oracle adjacency** than Archer. Oracle has the deepest incumbent adjacency via FLEXCUBE and runs Nigeria-localised risk pages. SAS is the one confirmed named Nigerian financial-services deployment (Zenith Bank, Fraud Framework, 14-month rollout) — fraud analytics, not ERM, but it establishes the credibility bar.

### 7.5 The commercial constraints

- **FX pass-through is the dominant objection, and it is documented.** Six major banks spent ₦268.7bn on IT in 2024 (+74.5% YoY in naira), with an analyst stating plainly that dollar-priced banking software cost *"nearly doubled due to the naira devaluation."* Tier-1 banks reportedly pay "at least $10 million annually" for core banking licences. **Naira-denominated, flat or entity-tiered pricing is a weapon, not a nicety** — and it lands squarely on Forrester's finding that AI consumption pricing is confusing buyers market-wide.
- **Budget and bandwidth are absorbed.** The 2024–26 IT surge went to core banking replacement (GTBank→Finacle, Sterling→SeaBaaS) alongside a ₦4.65trn recapitalisation. You are competing for residual budget and, more scarcely, project bandwidth.
- **Integrate with Finacle and FLEXCUBE first** — roughly 58% of Nigerian core banking installations sit on those two.
- **Addressable base:** ~33–36 licensed banks + 7 FHCs, 43 insurers/reinsurers (→ ~51), ~20–21 PFAs, 138 NGX-listed companies. Realistically **40–60 entities** have both the budget and the regulatory pressure to buy a platform rather than run spreadsheets. Design the commercial model for a market of 50 accounts, not 500.
- **What Nigerian CROs complain about is genuinely unevidenced.** No survey, examination summary or published buyer testimony could be located. This gap is fillable only by primary research — 8–12 interviews. Do not proxy it with global data, and do not let a deck assert it.

---

## 8. The pitch that survives contact

Given all of the above, the defensible positioning for a demo is:

> **"We are not trying to out-feature Archer. We are the risk platform that can legally hold a Nigerian bank's data after 1 January 2027, that prices in naira with no AI consumption meter, that ships CBN, NFIU, NDIC and PenCom obligations as versioned content rather than a blank table you fill in, and that shows you the arithmetic behind every number on the screen."**

Four claims. Each one is either already true, or is on the Wave list below. None of them requires a module you do not have.

The two you must earn before you say them: **regulatory content** (Wave 4A) and **showing the arithmetic** (Wave 0). The other two are commercial and hosting decisions you can make this month.

---

## 9. The build list — for approval

Effort is engineer-days. Ordering is strict: Wave 0 gates everything, because none of the rest matters if a fabricated number reaches a prospect.

### Wave 0 — Integrity. Nothing ships to a customer before this. **~11 days**

| # | Item | Days |
|---|---|---|
| 0.1 | Purge every fabricated user-facing number; replace with explicit "Not assessed" empty states (`board.blade.php:44,49,51`; `AnalysisController.php:471-479`; `ReportController.php:205`) | 2 |
| 0.2 | Rewrite ICAAP: compute CAR = qualifying capital ÷ RWA, CET1/T1/T2 ratios, read the minimum from the tenant's `cbn_minimum_car` (default **15%** for international/D-SIB), stop relabelling Pillar 2A as Pillar 1, render stress only from a bound simulation | 3 |
| 0.3 | Migrate `correlation_method` default to `'independent'`; drop it from customer exports until real | 0.5 |
| 0.4 | Compute real ES/CVaR before the sorted loss vector is discarded; reconcile the VaR/percentile dual estimator | 1 |
| 0.5 | Fix lognormal μ (`−σ²/2`) with a backfill migration; restrict the distribution enum to `lognormal` until the others exist | 1.5 |
| 0.6 | Fix Box-Muller tail truncation (redraw, don't clamp); add a σ=2.0 characterisation test | 0.5 |
| 0.7 | Delete synthetic "correlation"; relabel the panel *Shared-control overlap* (which is what it measures, and is genuinely useful). Rename the "tornado" widget to *scenario range* | 1 |
| 0.8 | **Extend the anti-fabrication CI guard to catch hardcoded constants** — ban `?? <numeric literal>` in Blade on view-bound metrics, and flag magic multipliers in controller output arrays | 1 |
| 0.9 | Rewrite certification claims to defensible wording ("Designed for CBN ORMS reporting · Basel III-aligned taxonomy") | 0.5 |

### Wave 1 — Security blockers a bank's review will fail. **~7 days**

| # | Item | Days |
|---|---|---|
| 1.1 | MFA rebuild: render the QR locally, 8-byte TOTP counter (or adopt `pragmarx/google2fa`), `Auth::login()` on verify, encrypt `mfa_secret` and remove from `$fillable`, seed `mfa_required_roles`, fix the SSO bypass and the SSO+MFA redirect loop | 3 |
| 1.2 | Move control-test evidence and data imports off the public disk; migrate existing files | 0.5 |
| 1.3 | MIME + size restriction on the loss-event upload; derive `file_type` server-side | 0.5 |
| 1.4 | `throttle` on login, MFA verify, password reset, SSO discover, SCIM | 0.5 |
| 1.5 | **Wire node-scoped authorization** — `->visibleTo(auth()->user())` in every grid `query()` and every `show()`; extend the tenancy CI test to cover it | 2 |
| 1.6 | Publish `config/cors.php`; force Secure + encrypted sessions outside local; cap `client_credentials` token lifetime | 0.5 |

### Wave 2 — Give the architecture story a screen. **~11 days**

| # | Item | Days |
|---|---|---|
| 2.1 | **Restore Business HQ + dashboard builder** — `npm install gridstack`, restore 3 routes, 2 JS imports, 1 CSS import and the sidebar block. Recovers ~6,450 lines and 21 widget resolvers, including the only graph-edge visualisation in the product | 2 |
| 2.2 | Administration nav block exposing all 8 admin/builder surfaces, gated on their own permissions (fixes the empty CRO menu) | 1 |
| 2.3 | Permission-gate every sidebar entry; add branded 403/404/500 views | 1 |
| 2.4 | `<x-dynamic-detail>` on the 5 show pages; `<x-dynamic-form>` on the risk create/edit forms | 1.5 |
| 2.5 | Generic `/objects/{type}` index + create so a tenant-defined object type is actually usable | 3 |
| 2.6 | Model observer on `RiskControlMapping` and siblings so `object_relationships` stays live during a demo | 1 |
| 2.7 | Render register/RCSA likelihood-impact selects and the dashboard heat map from `ScoringProfile` instead of hardcoded 5×5 | 1.5 |

### Wave 3 — Close the demo loop. **~10 days**

| # | Item | Days |
|---|---|---|
| 3.1 | **Notifications rebuild** — pass explicit recipients into each event, stop deriving from `auth()`; notify on KRI breach; notify RCSA assignees on launch and add campaigns as a source in My Responsibilities; notify on issue escalation | 3 |
| 3.2 | Fix the issue escalation ladder (include `ESCALATED` in candidates) | 0.5 |
| 3.3 | Fix `TriggerRiskReassessment` so completing a treatment plan actually moves the score | 1 |
| 3.4 | Fix the nightly `treatments:check-overdue` crash | 0.5 |
| 3.5 | Stop `ControlEffectivenessService` overwriting board-approved residual; have it write effectiveness and flag for re-assessment | 1 |
| 3.6 | Custom Report Builder honours `report_type` and `sections` | 1.5 |
| 3.7 | Dedupe `on_timeout: notify` reminders | 0.5 |
| 3.8 | Gate the 4 LLM panels on `config('services.llm.enabled')`; retire the duplicate Approvals queue; fix `$sections`/`$navSections` | 1 |
| 3.9 | KRI index and 12-month trend read from `measure_values`/`measure_breaches` so connector-fed readings are visible | 1 |

### Wave 4 — One differentiator that lands. Choose. **10–15 days each**

**4A — Regulatory obligation register** *(recommended second)*
Versioned, effective-dated, clause-level obligations: regulator, instrument, clause, obligation text, threshold, deadline rule, citation, review date. Per-org configurable thresholds replacing the PHP constants. Seeded with real CBN, NFIU, NDIC, EFCC, BOFIA, PenCom and NAICOM clauses. **This converts the claimed moat into a demonstrable one, and no international vendor will ever build it.** ~15 days including content.

**4B — Loss data → capital engine**
Fit λ from event counts per Basel L1 and (μ,σ) by MLE above the loss-data-collection threshold; genuine Basel L1/L2/L3 cascading capture; CBN ORMS event type and product line on the form. Makes the operational-risk story real and directly serves ICAAP. ~12 days.

**4C — Regulator-ready output** *(recommended first — cheapest, highest demo yield)*
Route the six regulator exports (CBN ORMS, Basel, NFIU) through `DocumentRenderer` to XLSX/PDF instead of bare CSV — the headers and rows are already built, so this is close to a one-line change each. Add scheduled board-pack distribution with a recipient list. Add server-rendered charts to the PDF board pack (currently every "chart" in it is an HTML table). ~8 days. **Every demo should end with "and here is the return you file."**

### Deliberately NOT in scope for this milestone

Policy management, third-party risk, BCM/resilience, internal audit, FAIR, backtesting, Gaussian copula, ESG/IFRS S1-S2, offline PWA. All are real gaps against the enterprise tier. **None of them wins a first demo, and chasing them is how you arrive at the demo with breadth and no credibility.** Revisit after the first pilot signs.

### Non-engineering, start this month

- **Open the Nigerian hosting-partner conversation.** ~4.5 months to 1 Jan 2027, no engineering dependency, and it disqualifies two of the four benchmark vendors from part of the market. This is the highest-leverage thing on the entire list and it is not code.
- **Fix the deck**: IFRS S1/S2 is voluntary in Nigeria until FY2028; do not assert a NAICOM ORSA requirement; drop the two unverified CBN claims sourced from vendor blogs.
- **Commit to naira, flat or entity-tiered pricing, with AI included and not metered** — and say so explicitly, because Forrester has documented that the enterprise tier cannot.
- **Run 8–12 CRO interviews.** What Nigerian risk officers complain about is currently unevidenced in any public source. It is the cheapest research you will ever buy and it will rewrite the demo script.

---

**Totals — Waves 0–3: ~39 engineer-days.** Add Wave 4C and you are at ~47 days to a demo that survives a hostile CRO and a bank infosec reviewer in the same week.
