# BCMS Phase 2 — the BIA engine

**Track:** A · **Weeks 3–6** · **Lead:** backend-engineer · **Depends on:** Phase 1's process catalogue

Built in the `riskerm-wt/bcms` worktree on `feature/bcms-module`, because a second
session was building TPRM Phase 8 in the main checkout and one working tree can
only be in one testable state.

---

## 1. What landed

| Layer | Files |
|---|---|
| ADRs | `0009` — eight columns, no tables · `0010` — **BCMS AI runs on the product's own LLM, not Prism** |
| Schema | `2026_09_11_120001_add_bcms_bia_engine_columns.php`; manifest regenerated in the same commit |
| Enums | `BiaAssessmentStatus`, `DependencyRelation`, `DependencyCriticality` |
| Domain | `Bia\BiaValidator`, `Bia\MtpdDeriver`, `Bia\DependencyService`, `Bia\BiaCampaignService`, `Bia\BiaAssessmentService`, `Bia\BiaAiDrafter`, `Ai\BcmsLlmClient` |
| HTTP | `BiaController`, `BiaCampaignController`, `DependencyController`, `BiaReportController`, `BiaWorkspacePresenter`, 2 form requests, 21 routes |
| Front end | `Bia/Index`, `Bia/Workspace`, `Bia/Campaigns`, `Bia/Dependencies`, `Bia/ReverseImpact`, `Bia/Report` |
| Console | `bcms:chase-bia`, daily at 07:00 |
| Seeds | 25 applications, owners on all 50 processes, a distributed campaign, 12 assessed processes (11 approved), 35 dependencies, **4 SPOFs and 5 shared dependencies** |
| Tests | `Phase2BiaEngineTest` (31), `Phase2ScreensTest` (15) |

## 2. The seven acceptance criteria

| # | Criterion | Status |
|---|---|---|
| 1 | A campaign distributes to 20 owners, collects, and produces a ranked report plus a dependency graph | **Pass** — and re-distributing is idempotent |
| 2 | Blocks RTO > MTPD; warns on open banking over 30 minutes, citing the CBN | **Pass** — plus two blocking rules the prompt did not ask for: the tenant's critical-service ceiling, and a child outlasting its approved parent |
| 3 | A dependency on an application resolves to the live record; no duplicated application row | **Pass, with the deviation stated** — see below |
| 4 | Reverse view lists every dependent process with aggregate RTO exposure | **Pass** |
| 5 | AI draft proposes with reasoning, lands in draft, cannot be approved without a human | **Pass** — and is off by default on a fresh install |
| 6 | SPOF register with downstream process counts | **Pass** — ordered by consequence, not by code |
| 7 | A non-responding owner triggers manager escalation on schedule | **Pass** — once, recorded, with the no-manager case reported |

### Criterion 3, honestly

The prompt asks that a dependency "resolve to the live **EA** record — no
duplicated application row exists anywhere in `bcms_*`". **This product has no
Enterprise Architecture module** (ADR 0001), so `bcms_applications` *is* the
application register — a named seam, not a duplicate. What the criterion is
actually protecting against is testable and is tested: exactly **one** application
register exists, the morph resolves to the live row, and renaming the application
changes what the dependency displays. Nothing shadows it.

## 3. The decisions worth not re-litigating

**Blocking and warning are different, and which is which is the whole design.**
A rule that blocks says "this cannot be true"; one that warns says "this is true
and somebody senior should know". Getting it backwards in either direction is
worse than no rule: block too much and assessors record fiction to get past the
form; warn too much and nobody reads warnings. The CBN 30-minute threshold
**warns and cites** rather than blocking, because whether those guidelines bind
an institution depends on its licence — and a system that refused to record a
bank's actual RTO would stop holding the truth.

**The system suggests, the human decides — in two columns.** `derived_mtpd_hours`
is what the impact grid proposed; `mtpd_hours` is what the assessor recorded.
One column cannot hold both, and overwriting the proposal with the answer erases
the disagreement, which is the interesting part of a review. Where they differ,
the validator raises it as a warning rather than a fault.

**The MTPD is the first horizon at which ANY category becomes intolerable.** Any,
not all. A process reputationally survivable for a week but regulatorily
intolerable at four hours has a four-hour MTPD; averaging the categories would
hide exactly the one that matters. The threshold is the tenant's, because one
bank's 4-out-of-5 is another's 3.

**A grid that never crosses the threshold proposes nothing.** Null, not the
longest horizon. "Every category tolerable for two weeks" means the grid does not
answer the question, not that the MTPD is two weeks.

**Aggregate exposure is the SHORTEST RTO among the processes that stop.** Not the
sum — that is arithmetic on unrelated clocks — and not the longest, which would
report the most relaxed process as the constraint. It is the time before the
first commitment is breached.

**Nothing about the graph is materialised.** The SPOF register, the reverse view
and the shared-dependency list are queries. A cached graph would be a cache with
no invalidation story over an estate of thousands of edges, not millions.

**A dependency cannot be both a single point of failure and have an alternative.**
Refused at the form. It is the commonest way a SPOF register under-counts:
somebody ticks both because each sounds true on its own.

## 4. Three defects found by the tests

**A constrained eager load omitted the column the code then read.**
`BiaCampaignService::overdue()` loaded `businessUnit:id,name` and `managerFor()`
read `head_id` from it — always null, so escalation silently never happened and
nothing failed. Exactly the "check the read path against the table" class in
development standard §6.

**`BcmsSettings` memoised per instance and was not a singleton.** Two services
holding two instances could disagree about a tenant's settings inside one
request: the AI kill switch flipped on and the client still refusing, the RTO
ceiling raised and the validator still blocking. Both silent. Now bound as a
singleton in `AppServiceProvider`.

**Twenty-six PHPStan findings from one cause.** Defensive `instanceof` /
`?->` guards written before `BiaAssessment::$status` had its enum cast, left in
place afterwards as dead code. Removing them made the code shorter and the
analyser quiet; PHPStan is back to 7 findings, all pre-existing and none in BCMS.

## 5. Known gaps handed forward

1. **The AI drafter has never been run against a live model.** Its unavailable
   path is tested and its apply-logic is unit-testable, but no test asserts what
   a real model returns, because a fresh install has none. Phase 12 owns AI
   hardening and should exercise it against a running Ollama.
2. **The dependency graph is drawn as chips, not a force-directed canvas.** The
   server sends nodes and edges; the page renders them as a filterable list of
   dependency pills with dependent counts. A real canvas is a Phase 12 polish
   item and would not change what the data says.
3. **No PDF export.** CSV only. `ThirdLine\Reporting\DocumentRenderer` exists and
   the board-pack rendering is Phase 11's.
4. **Impact narratives are drafted by AI; severities never are.** A severity is
   the assessor's judgement of their own business and is what the MTPD derives
   from — a model filling it in would be a model setting the recovery objectives
   through the back door. Deliberate, and worth keeping.
5. **`peak_periods` is free text.** Month-end, salary week and the festive period
   are seeded as strings. Phase 4's calendar generator needs them structured to
   avoid scheduling an exercise into one; that is a conversation for Phase 4, and
   possibly an ADR.

## 6. HANDOFF

**Phase:** P2 — BIA engine
**Agent:** backend-engineer (lead), architect (ADRs 0009, 0010), frontend-engineer
**Status:** complete
**Delivered:** as §1
**Contracts touched:** none of Orchestration §5's. `BiaAssessmentService::approve()` is now the ONE path that writes `bcms_processes.criticality_tier`, which Phase 1's model docblock already reserved for it.
**Assumptions made:** `bcms_applications` is the application register (ADR 0001); AI runs on the product's own LLM (ADR 0010); the CBN threshold warns rather than blocks
**Known gaps:** the five in §5
**Next agent:** Track A Phase 3 — strategies address the gaps this phase found, and `bcms_strategies.gap_vs_required_hours` is measured against these approved RTOs. **Broadcast to Track D:** DR tiers derive from the RTO/RPO recorded here. **Broadcast to Track B:** criticality tiers are now written by BIA approval rather than by hand; the Phase 4 exercise advisor ships against Phase 1's tiers and is **[verify at integration]** re-tuned at the W8 window.
**Verification run:** `tests/Feature/Bcms` — 153 passed; PHPStan **7 findings, all pre-existing, none in BCMS**; `pint --test` clean; `bcms:verify-schema` exit 0; a fresh `migrate --seed` green on MySQL 8; `npm run build` green
