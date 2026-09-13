# NDPA personal-data register — platform tables

**Owner:** compliance-analyst · **Opened:** TPRM Phase 11a, as a merge condition on
the 11a re-gate (ADR 0015 §10) · **Scope: unprefixed, platform-owned tables only**

This register covers personal data held in **platform-owned tables** — the
unprefixed ones, whose precedent is `risk_audit_trail` — written by code that
belongs to no single module. The module registers are separate and stay separate:
`docs/compliance/ndpa-register.md` is titled and scoped to BCMS and covers the
`bcms_*` tables.

**Why a second file rather than a section in the first.** ADR 0015 §10 ruled it,
and the reason is the one the schema already draws: unprefixed is platform,
`bcms_*` is BCMS, `tp_*` is TPRM. A platform table does not belong in a module's
register, and the BCMS register is mid-remediation under a separate gate — folding
a new table into a file two gates are reading is how a correction in one lands in
the wrong review. One product-wide register is the better end state; the way to
reach it is to let this file exist now and fold the module registers into it at a
moment when **no** module register is open under a gate.

**Why this table needed an entry at all, stated plainly.** The deferral was
defensible for as long as `llm_usage_events.user_id` had no writer: a table of
token counts and outcomes is telemetry. **The 11a cycle's own fix gave it one.**
Every model call now records a **named member of staff** against a timestamp, a
subject record, a module, a service and an endpoint, kept for 24 months. That is
a behavioural log of identifiable individuals, and the fact that its purpose is
capacity management does not change what it is. Gate 2 raised it; the architect
agreed; this is the entry.

---

## 1. `llm_usage_events` — the AI usage ledger

Created by `database/migrations/2026_09_17_120001_create_llm_usage_events_table.php`.
Written by `App\Services\Llm\UsageRecorder::record()` and nothing else.

| | |
|---|---|
| **Purpose** | Capacity management and governance of a shared, single-GPU model: enforce a tenant's monthly token and call limit, show a usage-and-cost report by service, and let a usage spike be traced back to the document that caused it. One row per gateway call, **including refusals** — a report built only on successes cannot tell a quiet month from a broken endpoint. |
| **Data subjects** | **Employees of the tenant** who trigger an AI call: a TPRM analyst who uploads a vendor document for extraction, a BCMS user who asks for a BIA or plan draft. No customers, no third-party staff, no data subjects outside the bank. |
| **Personal-data categories** | `user_id` (a foreign key to `users`, resolved to a person's **name** on screen), `created_at` (to the second), `module`, `service`, `subject_type` + `subject_id` (which record they were working on), `endpoint_profile`, `model`, `outcome`, `attempts`, `duration_ms`, the three token counts, `usage_month`, `organization_id`. Joined together this is **who did what AI work, on which record, when, and whether it worked** — a behavioural log, and it should be read as one. |
| **Not personal data, despite the names** | `prompt_key` and `prompt_version` are **identifiers of a configured prompt**, not prompt text: `soc2` and `soc2.v2`, keys into `config/tprm_prompts.php`. BCMS writes the literal `'unversioned'`. `model` is `granite4:micro`. This row exists because "prompt_key" reads like content to anyone who has not opened the schema. |
| **Lawful basis** | NDPA s.25 — necessary for the performance of the employment contract and for the controller's legitimate interest in governing a shared, rate-limited resource it pays to run. A staff member using a bank system on bank work, logged at the granularity needed to enforce a quota, is squarely inside that. **No consent is sought and none should be:** consent from an employee to their employer for a record they cannot decline and still do the job is not freely given, and dressing this up as consent would be worse than the legitimate-interest position it replaces. |
| **Purpose limitation — the line this register draws** | This ledger is for **capacity and governance**. It is not a productivity metric, it is not an input to a performance review, and it is not a monitoring tool. Nothing in the code makes it one; nothing in the code prevents it either. That boundary lives in the tenant's acceptable-use and staff-monitoring policy, and it is named in §1.6 as something the client's DPO must state rather than something this schema can enforce. |
| **Retention** | **24 months**, `config('llm.retention_months')`, enforced daily. §1.3. |
| **Residency** | The application's own database, wherever that deployment's MariaDB runs. **Not established per deployment** — §1.4. |
| **Access control** | `tprm.admin`, three times over: `AiUsageController::index()` opens with `Gate::authorize('tprm.admin')`; `TprmAiUsageEventsGrid::permission()` returns the same string; and the shared grid routes carry `can:view-grid,grid`, which resolves that same definition permission — **including on the export route**. Tenant-scoped by `BelongsToOrganization` on the model and by an explicit `where('organization_id', …)` in the grid query. |
| **Bulk export** | **Yes, and it is easy to miss.** The shared grid stack gives every definition a CSV and XLSX download at `risk.grids.export` (`GridController::export()`), and `GridPresenter` sets `canExport` to `true` for both formats unconditionally. So a `tprm.admin` can download a file of **named staff, per AI call, with timestamps** — see §1.9, which is where this register says what it thinks of that. |
| **Mutability** | **Append-only.** `$timestamps = false`, no `updated_at` column, no writer other than `UsageRecorder`. The only code in the product that deletes from it is the retention command. |
| **Audit trail** | **None, deliberately.** `LlmUsageEvent` carries neither `TprmAuditable` nor `BcmsAuditable`: `tp_audit_logs` and `bcms_audit_logs` hold the governance events (who turned AI on, and when), this table holds the traffic. That is a defensible split and it is the reason this table is not itself regulator-facing evidence. |

### 1.1 What this table does not hold — the first question anyone asks

**It holds no prompt text, no model response, and not one character of a vendor
document.**

Checked by reading `UsageRecorder::record()` in full and enumerating every key it
writes. The array it passes to `LlmUsageEvent::create()` has twenty keys and they
are the twenty columns listed above. `LlmCall` carries `$prompt` and `$system` —
the rendered prompt, which for a TPRM extraction contains the vendor document's
text — and **`record()` reads neither.** It reads `$call->promptKey` and
`$call->promptVersion`, which are the configured prompt's name and version, and
`$call->subjectType`/`$call->subjectId`, which are a morph reference to a record
the viewer may or may not have permission to open.

Three independent reads now agree on this — gate 1's, gate 2's and this one —
and it is stated here because an "AI usage log" invites exactly the opposite
assumption. **The model's output is not here either:** a successful TPRM
extraction's parsed payload lands in `tp_document_extractions.extracted`, under
that module's own controls and its own retention; a BCMS draft lands in the BCMS
record the drafter was called for. This table records that a call happened and
what it cost, never what was said.

**What an examiner should therefore be told.** Asked *"does your AI log contain
our vendor's report, or our staff's questions?"*, the answer is no, and the
evidence is a twenty-line method with no branch in it.

### 1.2 `user_id` — who writes it, and the one place it is legitimately null

| Path | Where the actor comes from |
|---|---|
| TPRM document extraction, queued | `RunTprmDocumentExtraction` reads `$this->jobRun()?->created_by` — the uploader/requester captured at dispatch — and threads it through `ExtractionDispatcher::dispatch($document, $userId)` to `LlmClient::run()`. **Never `auth()->id()` in the job**, because a queue worker has no session. |
| BCMS drafting (`BiaAiDrafter`, `PlanAiDrafter`, `ProgrammeAdvisor`) | `BcmsLlmClient::json()` defaults `$userId ??= auth()->id()`. Every current caller runs inside a controller action on the request's own authenticated user. A future caller outside a request must pass the actor explicitly. |
| Genuinely unattended | `null` — and the usage grid renders **"Not recorded"**, never "Scheduled". This matters for the register, not just for the screen: "Scheduled" would have been an absence stated as a fact. |

**`user_id` is nullable and the foreign key is `nullOnDelete()`.** So deleting a
user's account detaches their rows rather than deleting them, leaving the usage
and cost history intact and unattributed. That is the right default for a
capacity ledger and it is **also an erasure answer**: an NDPA erasure request
satisfied by deleting the user account leaves no personal data in this table,
because a null `user_id` and a timestamp identify nobody. It is recorded here so
that it is a stated position rather than an accident of a migration.

### 1.3 Retention — 24 months, and this one is actually enforced

| | |
|---|---|
| **Figure** | 24 months, `config('llm.retention_months')` |
| **Enforced by** | `App\Console\Commands\PruneLlmUsageEvents` (`llm:prune-usage`), flat in `app/Console/Commands/` |
| **Scheduled** | `routes/console.php:153` — `Schedule::command('llm:prune-usage')->daily();` |
| **How** | `where('created_at', '<', now()->subMonthsNoOverflow($months))`, deleted in chunks of 1,000, `withoutGlobalScopes()` so the sweep crosses every tenant — the normal shape for a platform-owned retention job |
| **Why it cannot silently miss rows** | `created_at` is **NOT NULL** in the migration and `UsageRecorder` always supplies `now()`. A nullable `created_at` would never match the cutoff predicate and those rows would be retained for ever while appearing to be covered. That was caught at 11a gate 2 advisory 5 and the column is not nullable because of it. |

**This is named explicitly because of the contrast, and the contrast is the
evidence.** The BCMS register records that **no BCMS retention or purge command
of any kind exists**, so every retention figure in that file is currently a
statement of intent and the true state is indefinite retention. Here the figure,
the config key, the command and the schedule entry all exist and can be shown. An
examiner asking *"show me retention working"* gets a command name, a scheduler
line and a deletion predicate for this table, and gets an honest "not yet" for
BCMS. **Two different answers, both true, and a register that gave the same
answer to both would be worth nothing.**

24 months is a **proposal of this build, not a figure the client has set.** It is
long enough to show a year-on-year usage trend to a board and short enough that a
behavioural log does not become an archive. If the client's own retention
schedule says otherwise, it is one config value — §1.6.

### 1.4 Residency — and what is and is not crossing a border today

| | |
|---|---|
| **Where the rows live** | In the application's own database, alongside every other table in this product. There is no separate store, no analytics export, no third-party telemetry sink: `UsageRecorder` writes one Eloquent row and nothing forwards it. |
| **Which country that is** | **UNKNOWN, and deployment-specific.** This product does not pin its own database's region, and a register that wrote "af-south-1" here would be describing an intention. It **must be recorded per deployment at go-live**, naming the environment and the region, by **the deploying party**. A blank here would read as an assessment that happened. |
| **Does anything cross a border today?** | **No.** Checked rather than assumed: `config/llm.php` declares exactly one endpoint profile, `local-ollama`, whose endpoint defaults to `http://localhost:11434` and whose model is `granite4:micro`. Its `unit_cost_per_1k_tokens_minor` and `currency` are both `null`, which is this product's marker for "self-hosted, not priced". The model runs on a box the institution already owns. **No prompt, no document and no usage row leaves the deployment**, so NDPA §41 does not engage on this table or on the calls behind it. |
| **The condition attached to that fact** | §1.5. It is a fact **with a precondition on it**, and the precondition belongs in the register rather than in somebody's memory. |

### 1.5 The §41 precondition that attaches the day a priced profile is added

ADR 0015 §4 deliberately leaves a hook open: an endpoint profile **may** declare
`unit_cost_per_1k_tokens_minor` and `currency`, and `llm_usage_events.unit_cost_minor`
and `.currency` fill in only when it does. That hook is the right design — it is
what stops the product printing `0.00` for an unpriced self-hosted endpoint today
— and it is also the exact moment this register's residency answer changes.

**What actually happens on that day.** A priced profile is, in practice, a metered
third-party API. Pointing a tenant at one means:

| What changes | The obligation it triggers |
|---|---|
| **The rendered prompt leaves the bank.** For TPRM that is vendor document text — a SOC 2 or an ISO certificate, which names auditors, signatories and sometimes the vendor's own staff. For BCMS it is BIA and plan context, which names processes, sites and owners. | **NDPA §29** — a written processing agreement with the model vendor, binding it to assist with data-subject rights, implement security measures, evidence compliance and notify when it engages a further processor. §29 is **not** conditional on the vendor being foreign. |
| **That vendor is almost certainly outside Nigeria.** Every commercially metered model API in existence today is hosted in the United States or the EU. | **NDPA §41** — a transfer instrument: contractual clauses, binding corporate rules, a code of conduct or a certification mechanism, subject to the Commission's approval under **GAID 2025**. The NDPR-era Whitelist has no legal effect, so no adequacy assumption is available; the instrument has to be a real one. |
| **A new processor exists that no register lists.** | It belongs in the **TPRM register as a third party**, with an engagement, a DPA, a residency attribute and a §41 instrument attached — the same argument the BCMS register makes at its §6.3 for the notification gateways. A model vendor is a processor like any other. |
| **The model vendor may retain prompts for abuse monitoring or training.** Most do by default; most offer a zero-retention tier by contract. | A retention and purpose question **about data this register cannot see**, answerable only from the contract. It is on the selection criteria, not discovered after signature. |

**So the precondition, stated as a rule the next engineer meets before the
config edit:**

> **No endpoint profile carrying `unit_cost_per_1k_tokens_minor` may be added to
> `config/llm.php` until the vendor behind it has a §29 written processing
> agreement, a §41 transfer instrument, a recorded prompt-retention position, and
> an entry in the TPRM third-party register.** The price field is the tell: a
> profile with a price is a processor, and a profile without one is a box in the
> building.

This is a precondition on a configuration change, which is the cheapest possible
place to put it — `config/llm.php` is **not publishable**, for the same reason
`config/tprm.php` is not, so the set of profiles is controlled by whoever ships
the code rather than by whoever holds a deployment's `.env`. The day that stops
being true, so does this control.

### 1.6 What is unknown, and who must answer it

**A cell reading UNKNOWN here means nobody has established the fact, not that it
was assessed and found acceptable.**

| What is unknown | Who answers it | What it blocks |
|---|---|---|
| The database region for each deployment | **The deploying party**, recorded in §1.4 per environment | Nothing today; a residency claim at go-live |
| Whether 24 months matches the client's own retention schedule for staff activity records | **The client's DPO** | Nothing today. One config value if the answer differs |
| Whether the tenant's staff-monitoring and acceptable-use policy covers an AI activity log at per-call, per-person, per-record granularity, and whether staff have been told it exists | **The client's DPO and HR** | Nothing technically. But an activity log staff have not been told about is the finding an examiner writes up, and the schema cannot fix it |
| Whether a §29 processing agreement, a §41 instrument and a prompt-retention position exist for any future priced model vendor | **The client's DPO**, before the profile is added | Adding any priced endpoint profile — §1.5 |

### 1.7 What this ledger does not cover, stated so its numbers are read for what they are

**Three ERM callers reach the model without passing the gateway**, and therefore
write **no row here**: `app/Http/Controllers/Risk/AiToolsController.php`,
`app/Console/Commands/WarmAiCache.php` and `app/Console/Commands/WarmLlm.php`.
They are named and counted in `LlmGatewayGuardTest::ALLOWED_LLM_SERVICE_CALLERS`,
the deferral is upheld by both 11a gates, and it ends at Phase 11 item 2 as a
precondition of that item's gate (ADR 0015 §1, amendment).

The consequence for **this** register, which is different from the consequence
for the usage report: **a subject-access response assembled from this table would
be incomplete.** It would show a person's TPRM and BCMS AI activity and omit
their ERM AI activity entirely, while looking complete. That is worse than a
gap — it is a gap that presents as a whole answer. Anyone answering a DSAR from
this table before Phase 11 item 2 lands must say so in the response.

### 1.8 A note on the application log, for symmetry with the BCMS register's §5.0

Written in the same form and for the same reason: what is logged, not a count.

| Sink | What it emits |
|---|---|
| `UsageRecorder::recordSafely()`, when the row itself cannot be written | `organization_id`, `module`, `service`, `outcome`, and the exception message. A failure-isolation log, matching `TprmAuditable`'s rule that a telemetry write never fails the caller's real work |
| `LlmGateway::call()`, on an unanticipated throwable | `organization_id`, `module`, `service`, and the exception message |
| `LlmService::jsonWithUsage()` — **the path the gateway actually uses** | `'LLM HTTP {status}'` or the exception message. **No response body and no prompt.** |

**No prompt text and no document text reaches the log through the gateway path.**
The exception messages above can carry the model endpoint's own URL, which on the
only profile that exists is `http://localhost:11434` — a loopback address, not
personal data.

**One thing this section does not clear, named rather than left out.**
`LlmService::complete()` logs `['status' => …, 'body' => $res->body()]` on a
non-2xx reply. That is an **error envelope from the model host**, not a
completion and not the prompt — but `complete()` is not on the gateway path; it
is reached by the three grandfathered ERM callers in §1.7. **Nobody has assessed
what a non-Ollama backend could put in that body**, and it is recorded as
unassessed rather than as safe. Owner: whoever takes Phase 11 item 2, since that
is the item that moves those three callers.

### 1.9 One thing found while writing this, for the architect and the DPO

**The AI usage grid inherits a bulk export nobody decided to give it.** Because
`TprmAiUsageEventsGrid` is a definition on the shared grid stack, it gets
`risk.grids.export` for free: `GridController::export()` re-runs the definition's
own query, takes the viewer's selected columns, and streams a CSV or an XLSX.
Those columns include **User** — the staff member's name — alongside a timestamp
to the second and the record they were working on. One request produces a
spreadsheet of who used AI, on what, and when.

The authorisation is correct and was checked rather than assumed: the route group
carries `can:view-grid,grid`, which resolves `TprmAiUsageEventsGrid::permission()`
to `tprm.admin`, so the export is behind the same permission as the screen. **The
question is not whether it is gated; it is whether `tprm.admin` is the right
holder for a downloadable file of staff activity.** `tprm.admin` is an
administrative permission for configuring a third-party risk module. It is not,
and was never designed as, a permission to extract a behavioural record of named
employees.

This is the **same finding shape** the BCMS register records at its §6.2, where a
reporting permission (`bcms.report.export`) produces the staff mobile numbers
that §1 of that register deliberately put behind a separate, CRO-only permission.
Two modules, one pattern: a general-purpose export surface reaching personal data
that a narrower permission was designed to protect.

It is recorded as an **observation, not a defect of 11a** — the export is
generic, it predates this table, and 11a did not add it. What is owed is a
decision: the **architect** owns whether this definition should opt out of the
shared export or declare a separate export permission; the **DPO** owns whether a
downloadable staff-activity file is acceptable at all under the monitoring
position §1.6 asks them to state. Neither question is answerable by leaving it
undiscovered.

---

## 2. Clause references

**No new clause code is published by this file.** The three NDPA codes that exist
— `ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency` — are cases on
`App\Enums\Bcms\IsoClauseRef` and are seeded into `bcms_clause_refs`, which is a
**BCMS** enum and a **BCMS** table. They map this file's content correctly in
substance and have no reach over a platform table in fact.

That is a real finding and it is recorded rather than papered over with a code
that would not resolve: **the platform has no clause-reference taxonomy of its
own.** Inventing one here would produce a code that exists in a document and not
in an enum — which, as the BCMS register's §5.4 puts it, is a code that will be
claimed as satisfied by something nobody built. The decision of where a
platform-level clause-reference home should live belongs to the **architect**;
until it is taken, this file cites NDPA sections directly (§25, §29, §41, §43)
and claims no enum code.

## 3. Sufficiency — could an examiner be shown this in one click?

**Partly, and the honest split matters more than the verdict.**

| The question | The answer today |
|---|---|
| *"Show me what your AI usage log contains about our staff."* | **Yes, for TPRM.** `Tprm/Settings/AiUsage` renders the month's calls with the user's name, service, outcome, tokens, duration and subject, behind `tprm.admin`. One screen, no assembly. |
| *"Show me the same for BCMS."* | **No.** `TprmAiUsageEventsGrid` filters `module = 'tprm'`. BCMS rows are written by the same recorder and have **no screen anywhere**. They are governed, retained and pruned correctly, and they are invisible. |
| *"Export this month's AI usage."* | **Yes** — CSV or XLSX from the shared grid export, behind `tprm.admin`. TPRM rows only, one month at a time. And see §1.9: getting this for free is not the same as deciding to have it. |
| *"Export everything you hold about this named employee."* | **No.** The export that exists is scoped to a **month** and to `module = 'tprm'`, and the **User column is neither searchable nor filterable** on this definition, so there is no way to ask it for one person. There is also **no DSAR export machinery anywhere in this product** — checked: nothing under `app/` implements one. Retrieval by subject is `where('user_id', ?)`, run by a developer against the database. That is a query, not an export, and §1.7 says it would be incomplete besides. |
| *"Show me retention working."* | **Yes.** A command, a schedule line and a deletion predicate — §1.3. |

**What would make the "no" rows yes** is not a new screen per module. It is
(a) the module filter on the usage grid becoming a **selector** rather than a
constant, so a platform table gets a platform view, and (b) a DSAR export that
takes a user id and returns every row this product holds about that person across
every table in both registers. Both are **architect** decisions with a phase
attached, and neither is 11a's. Note that (b) is also the clean answer to §1.9:
the right export for a named person is a **DSAR export**, not a month of
everybody. They are recorded here so they are placed
deliberately rather than discovered during an examination — the same argument the
BCMS register makes at its §6.3.

**The verdict for the 11a re-gate:** the merge condition ADR 0015 §10 set was a
register entry, and this is it. The processing is documented, the safeguard
(retention) already exists and is scheduled, and the two sufficiency gaps above
are **recorded as gaps rather than described as features**.

## 4. Sources

- NDPA 2023, §§25, 29, 41, 42, 43 — [Nigeria Data Protection Act 2023 (NGCERT copy)](https://cert.gov.ng/ngcert/resources/Nigeria_Data_Protection_Act_2023.pdf)
- NDPC **GAID 2025**, in force 19 September 2025 — [NDPC text](https://ndpc.gov.ng/wp-content/uploads/2025/07/NDP-ACT-GAID-2025-MARCH-20TH.pdf); the Whitelist's loss of legal effect and the SCC/BCR-subject-to-approval position per [DLA Piper, *Nigeria: NDPC Issues GAID*](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/)
- `docs/adr/0015-tprm-ai-consolidation-per-tenant-settings-and-the-usage-budget.md` — §4 (the priced-profile hook), §5 (why this table exists and why `organization_id` is NOT NULL), §10 (the ruling this file answers)
- `docs/compliance/ndpa-register.md` — the BCMS module register, for the retention contrast in §1.3 and the third-party argument in §1.5

## Open questions — for the client's DPO

1. **Is 24 months right** for a staff AI-activity log under the client's own
   records-retention schedule? One config value either way (§1.3).
2. **Have staff been told this log exists**, and does the acceptable-use or
   staff-monitoring policy cover it at per-call, per-person, per-record
   granularity? The schema cannot answer this and should not pretend to (§1).
3. **Is the legitimate-interest basis accepted**, or does the client's legal
   function want a different one stated? Consent is explicitly rejected here and
   the reasoning is in §1 — but the client, not this register, owns the position.
4. **What is the database region** for each environment (§1.4)? This is for the
   deploying party, and it must be filled in before a residency claim is made to
   anyone.
5. **Who may download a staff-activity file** (§1.9)? `tprm.admin` holds it today
   by inheritance rather than by decision. The parallel question in the BCMS
   register is its §6.2, and the two should be answered together rather than
   separately, because the answer is a principle and not a permission string.

## HANDOFF

**Phase:** TPRM P11a — re-gate merge condition (ADR 0015 §10)
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** `docs/compliance/ndpa-register-platform.md` — a new platform-scoped register with `llm_usage_events` as §1: purpose, subjects, categories, lawful basis and why consent is refused, purpose limitation, access control, `user_id`'s three writers and its one legitimate null, the "no prompt, no response, no document text" statement with the method that proves it, retention with its command and schedule line, residency and the §41 precondition on a priced profile, what the ledger does not cover (the three ERM callers), the log sinks, an inherited bulk-export observation for the architect and the DPO (§1.9), the clause-reference finding, and a five-row sufficiency verdict
**Clause refs published:** **none.** The three existing NDPA codes live on `App\Enums\Bcms\IsoClauseRef` and are BCMS-scoped in fact; the platform has no clause-reference taxonomy and inventing a code in a document that does not exist in an enum is the failure mode the BCMS register §5.4 already names. Recorded as a finding for the architect, not closed by this file
**Contracts touched:** none. Documentation only. `docs/compliance/ndpa-register.md` was **not** restructured or retitled to accommodate this table, per ADR 0015 §10. No application file, test, migration or config was modified; nothing outside `docs/compliance/` was written; none of the three concurrently-edited areas (`app/Services/Llm/`, `docs/adr/`, BCMS Phase 7 code) was touched
**Assumptions made:** 24 months is this build's proposal, not the client's answer. The legitimate-interest basis under NDPA s.25 is stated as the position this build implements and is open to the client's legal function. §1.5's assertion that a priced model API is "almost certainly outside Nigeria" is a judgement about the market as it stands, not a CBN or NDPC requirement, and is written as such
**Known gaps:** the database region is **UNKNOWN per deployment** and must be recorded at go-live by the deploying party; **no DSAR export exists anywhere in this product**, so subject-access retrieval from this table is a developer's query — the export that does exist is per-month, TPRM-only and cannot be filtered to one person; a DSAR answered from this table today would be **silently incomplete** because three ERM callers bypass the gateway (§1.7); BCMS usage rows have **no screen at all**; the inherited staff-activity export at `risk.grids.export` is gated at `tprm.admin` and **nobody has decided that is the right holder** (§1.9); `LlmService::complete()`'s body logging on the ERM path is **unassessed, not cleared**
**Next agent:** `code-reviewer`, to verify this entry at the 11a re-gate — ADR 0015 §10 names that verification explicitly. Then `architect`, for the two items this file raises and cannot decide: where a platform-level clause-reference taxonomy lives (§2), and the module-selector plus DSAR-export pair that would make §3's second and third rows yes
**Verification run:** Read in full and checked field by field on 2026-09-12 on `integration/tprm-bcms`: the migration (twenty columns, `organization_id` NOT NULL cascade-delete, `user_id` nullable `nullOnDelete`, `created_at` NOT NULL, no `updated_at`, three indexes); `UsageRecorder::record()` (every key enumerated — **no `$call->prompt`, no `$call->system`, no response**); `LlmCall` (confirming the prompt is carried into the gateway and not into the row); `LlmUsageEvent`; `PruneLlmUsageEvents` and `routes/console.php:153`; `config/llm.php` (**one** profile, `local-ollama`, loopback endpoint, `unit_cost_per_1k_tokens_minor` and `currency` both null); `AiUsageController` and `TprmAiUsageEventsGrid` (`tprm.admin` on both; grid filtered to `module = 'tprm'`; the User column carries neither `->searchable()` nor a filter). **A first pass of this file asserted there was no export for this grid; that was wrong and was corrected before delivery** — `GridController::export()` at `routes/web.php:444` gives every definition CSV and XLSX behind the group's `can:view-grid,grid`, `GridPresenter:115` sets `canExport` unconditionally, and §1.9 exists because of it. The lesson is the one §5.0 of the BCMS register was rewritten for: a negative claim about a shared stack has to be checked at the stack, not at the definition. `RunTprmDocumentExtraction` and `ExtractionDispatcher::dispatch()` and `BcmsLlmClient::json()` for the actor's three sources; `App\Enums\Bcms\IsoClauseRef:122-124` for the three NDPA codes and their BCMS namespace; `rg -i dsar app/` → **no matches, product-wide**; `Log::` across `app/Services/Llm/` and `app/Services/LlmService.php` for §1.8. ADR 0015 §§4, 5, 10 read in full. No test suite run; no database touched. **Examiner walkthrough — "does your AI log hold our vendor's report or our staff's questions, and how long do you keep what it does hold?": answerable in one click for the second half (a scheduled command with a named config key and a deletion predicate) and answerable with certainty but not in one click for the first (a twenty-line method with no branch in it, which an examiner would have to be shown as code rather than as a screen).**
