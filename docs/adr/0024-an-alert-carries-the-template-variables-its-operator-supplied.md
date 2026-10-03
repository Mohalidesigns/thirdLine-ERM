# ADR 0024 — An alert carries the template variables its operator supplied; everything else is derived or deferred

**Status:** Accepted · **Date:** 2026-09-24 · **Phase:** BCMS Phase 7 remediation (EMNS templates), lands **before Phase 12 and before the client demo** · **Author:** architect
**Requested by:** the coordinator. The standalone EMNS rendering fix (in the working tree, uncommitted) made `TemplateRenderer::render()` fail closed on any unfilled `{{…}}`. That exposed 18 template variables with no home, and as a result `EVACUATE` cannot be sent at all.
**Consumers:** backend-engineer **(implements, immediately)** · frontend-engineer (compose form, estimate preview) · qa-engineer · code-reviewer · compliance-analyst (NDPA note; template content) · Phase 12 12A-2 (the alert composer builds on this) · Phase 5 (reminder templates) · Phase 10 (crisis room compose)

## Amendment 1 (2026-09-25): the body corrected to match the implementation

Raised by code review (a doc-drift block). **This section governs wherever the body below disagrees
with it.** Each item was checked against the code on 2026-09-25.

1. **§4 merge order: base → stored → derived → `$extra`.** Not the base → derived → stored order
   written below. QA found that the original order let a stored key shadow a derived value: a direct
   `compose()` rendered "EVACUATE THE WRONG BUILDING". Two defences now hold, and each would be
   enough on its own:
   - **Derived values win structurally.** `TemplateRenderer::variablesFor()` merges
     `template_variables` *before* `alertDerivedVariables()`.
   - **`AlertService::compose()` refuses** any `template_variables` key that is not a declared
     `OPERATOR` or `OPERATOR_OPTIONAL` variable of the template. The same rule as the Form Request
     (§3.1), enforced in the service, so a direct caller cannot bypass it.

   `$extra` stays last. Only direct `render()` callers pass it, and every production caller passes
   `[]`.
2. **§2 `tree_name` is derived only when the audience rule targets exactly one call tree**
   (`soleLeafId()`). It does *not* join every `call_tree` leaf. With zero trees or several, it is not
   derived, and CALLTREEACT fails closed with the variable named. This is the rule `site_name`
   follows: a name is printed only when the whole positive audience is exactly one such thing. A
   message saying "the Lagos tree is activated", sent to three trees, would tell two of them
   something false.
3. **`site_name` falls back to the linked incident's or occurrence's site only when the audience
   rule mentions no site anywhere** (`audienceRuleMentionsAnySite()`). A site that the rule excludes
   through `none_of` therefore can never be named as the site of the alert.
4. **`message` is required at the HTTP layer** (`StoreBcmsAlertRequest`: `required|string|max:4000`),
   because the column is `NOT NULL`. For direct callers, `compose()` defaults a missing `message` to
   the template's name, or to `''` when there is no template.
5. **SMS and USSD wire bodies are transliterated to GSM-7 for typographic punctuation** before
   truncation and segmenting (`SmsSegmenter::transliterateForGsm7()`). The characters covered are
   the em and en dash, curly single and double quotes, the ellipsis character and the non-breaking
   space. A single em dash in a site name would otherwise force UCS-2. **Stored data and the
   author's template preview are unchanged**, because only the wire body is transliterated.
6. **§3.7 estimate `preview` shape:** `[{channel, locale, body, subject, segments, encoding}]`,
   holding **the exact wire text**: prefix applied, transliterated, and truncated as it would be
   sent. The reply token is omitted.

Nothing in this amendment changes the schema, the variable classification counts (8 operator,
8 derived, 2 per-recipient), or the fail-closed release.

## Context

The standalone fix is right, and this ADR does not revisit it. Every channel now substitutes from one
variable map (`TemplateRenderer::variablesFor()`), and an unfilled placeholder throws
`UnresolvedTemplateVariableException`. That exception is raised at three points: at estimate (422,
naming the variable), at release (`AlertService::assertRenderable()`, before any recipient row is
written), and in the dispatcher (the delivery row is marked failed). The alternative was a phone
receiving "Assemble at" with the instruction missing. The fix derives `site_name`, `location`,
`incident_reference`, `incident_title`, `exercise_name`, `scheduled_date` and `days_remaining` from
what an alert already carries: the audience rule's `site` leaves, the linked incident, and the linked
occurrence.

What the fix left open, verified against `database/seeders/Bcms/Reference/AlertTemplates.php` on
2026-09-24: shipped templates declare 18 variables that nothing supplies. `bcms_alerts` has no column
that could hold an operator's words, and eleven of those variables cannot be derived from any
relation an alert has (`template_id`, `incident_id`, `occurrence_id`, `audience_rule`). So
`EVACUATE`, `ALLCLEAR`, `CRISISCONVENE`, `ITOUTAGE`, `LESSONSBULLETIN` and others are refused at
release, and an operator facing a fire cannot use the evacuation template. The refusal is safe. It is
not acceptable.

## Decision

### 1. One column: `bcms_alerts.template_variables`, nullable `json`

| | |
|---|---|
| **Column** | `bcms_alerts.template_variables`, `json NULL`. Model cast is `array`. Added with `->after('response_options')` |
| **Holds** | **Only the operator-entered variables** (§2), as `{name: string}`, captured at compose. **Never derived values** (they are recomputed at render, which is what the fix does now). **Never per-recipient values.** **Never ciphertext** |
| **What breaks without it** | A life-safety template cannot be sent. The only no-column alternative, flattening the template into a free-hand `message` at compose, throws away the template's SMS and voice renderings and its locale rows. That means the tuned short SMS and the Pidgin evacuation text are lost in exactly the alert that needs them |
| **Portability** | Handled PHP-side only. **No `JSON_*` function touches this column in SQL**: it is never filtered, sorted or searched in a query (CLAUDE.md, MariaDB 10.4). Plain text values only. MariaDB's `json_valid()` CHECK rejects an encrypted envelope, which is the `connectors.config` defect, so nothing encrypted ever goes here |
| **Immutability** | Written at `store` and never updated. There is no alert-update route today, and this ADR adds none. An operator who mistyped cancels and recomposes. **This is deliberate:** a value changed after a first approval would make the second approver sign text the first never saw |
| **Migration** | `database/migrations/2026_09_24_120001_add_template_variables_to_bcms_alerts.php`. Schema builder only (SQLite reference migration), no timestamp (ADR 0022), `down()` drops the column. `bcms:verify-schema --write` regenerates the manifest in the same commit. ADR 0023's Phase 12 migration moves to `2026_09_25_120001` so the two never reorder |

**Structural count: one nullable column on one table.** It is an EMNS (Phase 7) remediation, not
Phase 12 work.

### 2. The 18 variables, classified

The classification is **a single constant map in code**,
`App\Support\Bcms\AlertTemplateVariables` (`DERIVED`, `OPERATOR`, `OPERATOR_OPTIONAL`,
`PER_RECIPIENT`), so the validator, the presenter and the guard test all read one list. The
variables the standalone fix already derives are added to `DERIVED` for completeness:
`site_name`, `location`, `incident_reference`, `incident_title`, `exercise_name`, `scheduled_date`,
`days_remaining`, `alert_title`, `severity` and `organisation`.

**Operator-entered at compose (8):**

| Variable | Templates | Required? | Max length |
|---|---|---|---|
| `assembly_point` | EVACUATE (en, pcm) | yes | 80 |
| `additional_instructions` | ALLCLEAR | **optional**: an empty string is stored explicitly and renders as nothing | 160 |
| `bridge` | CRISISCONVENE | yes | 120 |
| `convene_by` | CRISISCONVENE | yes | 40 |
| `service_name` | ITOUTAGE | yes | 80 |
| `workaround` | ITOUTAGE | yes | 160 |
| `next_update` | ITOUTAGE | yes | 40 |
| `lessons_summary` | LESSONSBULLETIN | yes | 300 |

Why `assembly_point` is operator-entered and not derived: the site register deliberately holds no
assembly points. They are free text inside a plan section (`SourceResolver.php`: *"it does not hold
assembly points, and this section will not invent one"*). Deriving one from plan prose would be a
guess, on the one variable where a guess sends people to the wrong car park.

**Derived at render (8)**, added to `TemplateRenderer::computeAlertDerivedVariables()`. The rule for
each source: if the source is absent, the variable **stays unset**, the existing fail-closed path
refuses, and the error names it. Nothing is defaulted.

| Variable | Derived from |
|---|---|
| `tree_name` | ~~Names of the call trees in the audience rule's `call_tree` leaves, joined~~ **Only when the audience rule targets exactly one call tree (`soleLeafId()`). Otherwise it is not derived, and the template fails closed (Amendment 1, item 2).** An alert that does not target exactly one tree cannot send CALLTREEACT, which is correct |
| `open_task_count` | The linked occurrence's readiness tasks with status `open`, `in_progress` or `overdue`, counted. Zero is a true count |
| `blocking_task_count` | The linked occurrence's stored `blocking_tasks_open` (maintained by `ReadinessService`) |
| `blocking_note` | Computed from `blocking_tasks_open`: `""` when 0, otherwise "N blocking task(s) must be closed before the exercise can start." **This is an English sentence, so only `en` template rows may declare `blocking_note`.** The guard test asserts it |
| `plan_link`, `training_link`, `profile_link` | Absolute URL of the recipient's own **My Resilience** page (`route('bcms.myresilience.index')`, verified with `route:list`: `risk/bcms/me/resilience`), with fragments `#plans`, `#training` and `#profile`. **The URL is identical for every recipient.** The person signs in and sees their own plans, training and profile, so none of these is per-recipient |
| `pir_link` | The linked incident's post-incident review (`bcms_aars.incident_id`) **only when it is final**, as the `incidents.review.show` URL. A draft PIR leaves the variable unset, so the bulletin cannot go out before the review it announces is finished |

**Per-recipient: deferred (2):** `your_task_count` (EXBLOCKED) and `last_verified` (CONTACTVERIFY).
They go to the booked EMNS per-recipient-variables ADR, the one Phase 9's T-0 code distribution is
also waiting on. **EXBLOCKED and CONTACTVERIFY therefore stay unsendable**, and §3.3 makes that
visible at compose rather than only at release.

**A point where this ADR departs from the brief.** The request listed `profile_link` as
per-recipient. For CONTACTVERIFY it would be, if it were a signed, sign-in-free verification link,
and that template stays blocked anyway by `last_verified`. As a link to the signed-in profile page it
is the same URL for everyone, and deriving it unblocks NEWJOINERBC. If a sign-in-free verification
link is wanted later, it becomes a different variable name in the per-recipient ADR, not a change of
meaning for this one.

**Resulting template status after this ADR:**

| Sendable | Code |
|---|---|
| **Yes** (given the context the table above names) | EVACUATE (en, and pcm once activated), ROLLCALL, ALLCLEAR, CRISISCONVENE (needs a linked incident), CALLTREEACT (needs a call-tree audience), ITOUTAGE, EXREMINDER (needs a linked occurrence), BCAWAREWEEK, LESSONSBULLETIN (needs a linked incident with a final PIR), NEWJOINERBC |
| **No, deferred** | EXBLOCKED, CONTACTVERIFY (per-recipient) |

### 3. Compose, validation and the form

1. **`StoreBcmsAlertRequest`** gains `template_variables` (`nullable|array`). With a template, the
   rules are built from that template row's own `variables` list:
   - each declared `OPERATOR` variable is `required|string|max:N`, and each `OPERATOR_OPTIONAL`
     variable is `present|nullable|string|max:N`, normalised to `""`;
   - **any key that is not a declared operator variable is prohibited.** An operator cannot override
     `incident_reference`, `site_name` or a link;
   - **no value may contain `{{` or `}}`.** A value that did would be substituted and then either
     trip the fail-closed check or, worse, pick up a later variable's value.
   - While this request is open, `template_id` and `occurrence_id` become tenant-bound
     `Rule::exists(...)->where('organization_id', …)`. They are bare integers today, which breaks
     DEVELOPMENT_STANDARD §4, and the new rules have to load the template anyway.
2. **Every active locale row of one template code must declare the same variable set.** The seeding
   test asserts this, because compose validates against the `en` row the picker offers while render
   uses whichever locale row a recipient gets.
3. **A template that declares any `PER_RECIPIENT` variable is refused at compose**, with the message
   "This template needs per-person details that cannot be sent yet". In the picker (`EmnsPresenter`)
   it is marked unavailable with the same reason. The operator must not first discover this at
   release, in an emergency.
4. **A template variable that appears in no list of the map is a failed test, not a runtime
   surprise.** `EmnsTemplateSeedingTest` asserts that every variable any seeded template declares is
   classified.
5. **The presenter** ships, per template, `operator_variables: [{name, label, required, max}]`,
   drawing its labels from the same map, and `unavailable_reason` where applicable.
6. **The form (frontend)** shows one labelled input per operator variable the selected template
   declares. Each input has a required marker, a character counter against `max`, and a server
   error bound to `template_variables.<name>`. It follows the ThirdLine field components (FormField)
   and WCAG 2.1 AA, with a label per input. No input is shown for derived variables. Instead the
   form lists them read-only: "Filled in automatically: site name (from the audience), incident
   reference (from the incident)". **Verified in a browser**, because no JavaScript runs in the
   suite.
7. **Estimate and preview.** `AlertService::estimate()` gains `preview`, a list of
   `{channel, locale, body, subject, segments, encoding}`, holding the exact wire text (Amendment 1, item 6) of the **first real rendering per
   (channel, locale)** it already produces while pricing. It is built through the same
   `TemplateRenderer`, with the simulation prefix applied, so the preview is the text that would be
   sent. The token is omitted, which the screen notes. The existing 422 naming the unresolved
   variable stays exactly as it is. The console shows the preview before approve and dispatch.

### 4. Release stays fail-closed, unchanged

`AlertService::assertRenderable()` and the dispatcher's defensive catch are **not relaxed** in any
way. This ADR adds sources of values. It removes no refusal. `TemplateRenderer::variablesFor()`
merges in the order ~~*base → derived → stored operator variables → caller `$extra`*~~ **base → stored →
derived → `$extra` (Amendment 1, item 1)**. Derived values win the merge, and `compose()` refuses any
undeclared key, as well as the Form Request (§3.1).

### 5. NDPA

Operator-entered values are free text typed during an event, for example "Musa is on floor 3". They
are held on `bcms_alerts` under that table's existing entry, with its lawful basis and retention, and
appear in the evidence export as part of what was sent. `compliance-analyst` adds one sentence to the
EMNS entry of `docs/compliance/ndpa-register.md`. No new sink and no new category.

## Tests the gates should hold (qa-engineer)

- `EmnsTemplateRenderingTest`: EVACUATE with `assembly_point` renders in sms, voice and email with no
  braces. Without it, compose returns a 422 naming it. With it removed from the stored map (the test
  tampers with the row), release refuses. Also: CALLTREEACT with and without a call-tree audience,
  and LESSONSBULLETIN with a draft PIR (refused) and a final PIR (sent).
- `StoreBcmsAlertRequest`: a prohibited key, a `{{` inside a value, a value over max, an optional
  variable left empty, and another tenant's `template_id` or `occurrence_id` rejected.
- `EmnsTemplateSeedingTest`: every declared variable is classified; locale rows of one code agree;
  `blocking_note` appears only in `en`.
- Estimate: `preview` present, prefixed for a simulation, and identical to what the mock channel
  records at dispatch for the same recipient.
- One end-to-end test: compose EVACUATE, approve where required, dispatch (simulation). The mock SMS
  delivery row's body contains the operator's assembly point.

## What this ADR deliberately does not do

- **It does not build per-recipient variables.** They belong to their own ADR.
- **It does not snapshot derived values.** If a site is renamed after dispatch, a re-render shows
  the new name. What was actually sent is whatever the delivery evidence records today. A snapshot
  column is a separate evidence question, not needed for the demo.
- **It does not add an alert-edit route** (§1, immutability).
- **It does not derive `assembly_point` from plan text**, or `service_name` from DR systems. Both
  would be guesses on the path where a guess does harm.
- **It does not change which templates exist, or their wording.** Content belongs to
  compliance-analyst. `pcm` rows stay inactive until a native speaker has reviewed them (owner
  decision, separate fix).
- **It does not relax fail-closed rendering anywhere.**

## Alternatives rejected

| Alternative | Why not |
|---|---|
| Flatten the template into `message` at compose (no column) | Loses the SMS and voice renderings and the locale rows, in the alert that most needs them |
| Put variables inside `audience_rule` or `response_options` json | Overloads a frozen contract's shape (ADR 0003). Every reader of those columns would have to learn to ignore a stranger's keys |
| A `bcms_alert_variables` child table | A row per variable for a small, write-once map that is never queried by value |
| Store derived values too | Two sources for one value that can disagree, and more to validate |
| Derive `assembly_point` from the plan's assembly-points section | Free prose, possibly several sites. A wrong guess sends people to the wrong place |
| Let an operator override derived values | Lets a typo replace the incident reference the regulator will cross-check |
| Relax fail-closed for "optional-looking" variables | This is exactly how "Assemble at" happened |
