# Screen spec — `Tprm/Settings/AiUsage`

**Route:** `GET tprm/settings/ai/usage` → `tprm.settings.ai.usage`, `Tprm\AiUsageController@index`,
`middleware('permission:tprm.admin')`. Same gate as `Tprm/Settings/Ai`; no policy, no
`TprmServiceProvider::POLICIES` entry (ADR 0015 §8).

**Reads contract:** phase-11a-ai-contract.md §7.3. This spec adds one field not enumerated
there — `tenant_ai_currently_enabled` — flagged explicitly in §7 below as an addition, needed to
tell an empty month apart from a broken one; it requires no schema change, only that
`AiUsageController` reads the same `tp_settings`/config facts `AiSettingsController` already
reads.

**Sibling to read before building:** `Tprm/Settings/Ai` (this phase's companion spec) for the
banner-tone vocabulary, and `Documents/Index.jsx` for the tile-grid pattern this screen's cap
summary reuses.

---

## 1. Purpose and the user

The same `tprm.admin` person, arriving for one of three reasons: routine governance review
("who used this and how much"), investigating a support complaint ("extraction stopped
working"), or following the "View usage report" link from the settings screen after noticing a
cap is near. A model-risk or audit function may also review this periodically, but it is an
**operational report**, not the regulatory register class of screen (CBN/DORA/PCI) — it is judged
on whether the reader can tell "quiet month" from "broken endpoint" at a glance, per ADR 0015 §4's
own framing, not on presenting a document format an examiner expects.

In the first ten seconds: this month's consumption against its limit (or "no limit set"), and
whether calls are succeeding or being refused.

## 2. Layout

`PageHeader` title "AI usage report", subtitle: "Calls to the on-premises model, by service and
by outcome, for {selected month}. There is no cost figure on this report — the model runs on this
institution's own hardware." `PageHeader` action: `btn-secondary` link back to `tprm.settings.ai`
("AI settings").

**Month selector**, immediately below the header: a native `<select>` populated with every month
still inside the retention window (`config('llm.retention_months')`, 24 by default, fewer if the
tenant is newer than that), most recent first, defaulting to the current `usage_month`. Changing
it performs a standard Inertia `GET` navigation to the same route with a `month` query parameter
— not a client-side re-fetch — consistent with the rest of the module's list screens. While the
navigation is in flight, the select is disabled and a small "Loading {month}…" label appears
beside it; the panels below keep showing the previous month's data, dimmed, rather than going
blank, so a slow link never presents as a broken page.

**Cap-status tiles** (`grid grid-cols-2 lg:grid-cols-4`, same shape as `Documents/Index.jsx`'s
tiles):

| Tile | Content |
|---|---|
| Tokens used this month | `month.total_tokens`, or "Not reported" (text, not a numeral) when null |
| Calls this month | `month.call_count` — always a true integer, including 0 |
| Token limit | `month.token_cap`, or "No limit set" when null |
| Call limit | `month.call_cap`, or "No limit set" when null |

Tone (`critical`/`warn`/neutral, same red-700/amber-700/gray-900 text convention as the sibling
list screens) is computed **server-side** from `remaining` when a cap exists: `critical` when
`remaining <= 0`, `warn` when consumed ≥ 90% of the cap, neutral otherwise. A tile with no cap set
carries no tone — "no limit" is not a warning state. A tile whose token count is "Not reported"
carries no tone either — an unmeasurable thing cannot be flagged as high or low.

**By-service panel** — a table, one row per `service` value present in the month (services with
zero calls in the selected month are omitted from this table, not shown as zero rows — the
by-service panel answers "what happened," and nothing happening for a given service is the
absence of a row, not a `0` row):

| Service | Calls | Succeeded | Refused/blocked | Tokens | Avg. duration | Cost |
|---|---|---|---|---|---|---|
| Evidence extraction | 41 | 38 | 3 | 812,004 | 34.2s | Not priced — self-hosted endpoint |

- "Tokens" is the `SUM(total_tokens)` for that service's rows that reported a count; if **every**
  call for that service in the month reported no tokens, the cell reads "not reported by this
  backend" and the call count stands alone as the only true figure for that row.
  If **some** calls reported tokens and others did not, the cell shows the summed figure for the
  calls that did, with a footnote marker: "{n} tokens · {m} of {calls} calls did not report a
  count" — the report never silently treats a missing count as zero inside a sum.
- "Cost" is **always** rendered, and for every profile shipped in this deployment always reads
  *"Not priced — self-hosted endpoint"* — never a blank cell (blank could be misread as "unknown"
  rather than "known to be free of charge") and never `0`/`0.00`/`₦0`.
- A thin inline SVG horizontal bar sits behind or beside the "Calls" figure in each row, scaled to
  the row with the most calls in the table, purely as a supplementary at-a-glance comparison. It
  is `aria-hidden="true"` — the numbers in the cells are the information; the bar repeats it
  visually and carries nothing a screen reader needs, consistent with the product's inline-SVG
  chart convention (`SeriesChart.jsx`, BCMS's `CostRtoScatter.jsx`) rather than a chart library.

**By-outcome panel** — the same table shape, one row per `Outcome` enum value **present in the
month**, plain-language labels (`Succeeded`, `Refused`, `Circuit open`, `Monthly limit reached`,
`Endpoint unreachable`, `Timed out`, `Server error`, `Response unreadable` for the eight enum
values), count, and share of the month's total calls. Explicitly includes refusals, breaker trips
and cap hits — this is the panel that answers "is the endpoint healthy," and a report that only
counted successes could not distinguish a quiet month from a dead box. Same inline-SVG
supplementary bar convention as above.

**Recent calls** — `DataGrid` fed by a `GridPresenter`-shaped grid (same convention as
`TprmThirdPartiesGrid` and the other TPRM list screens), scoped server-side to this
`organization_id` and the selected month via the `(organization_id, created_at)` index, with its
own filters/search/pagination supplied by the grid definition rather than a bespoke filter row.
Columns: When, Service, Outcome (badge, same colour convention as the settings screen's Effective
badges), Model, Endpoint profile, Tokens (figure or "not reported"), Duration, Subject (a link to
the underlying record when the morph resolves — e.g. "Document #123" — plain text "—" when it does
not, never a raw class name or numeric-only reference), User ("Scheduled" when `user_id` is null).

## 3. Every state

- **Normal month with activity.** All panels populated as above.
- **No AI calls at all in the selected month, and AI is currently off for this tenant.** The
  by-service and by-outcome panels do not render as empty tables with headers and no rows;
  instead a single message replaces both: "No AI calls were recorded for {month}. AI is currently
  off for this tenant — see AI settings to turn it on." with a link to `tprm.settings.ai`. This is
  the benign case and is worded as such.
- **No AI calls at all in the selected month, and AI is currently on for this tenant.** Same
  empty-table replacement, different message: "No AI calls were recorded for {month}, though AI
  is currently on for this tenant. If activity was expected, check the breaker status and
  endpoint on the AI settings screen." — because an enabled-but-silent month is exactly the
  "quiet month vs. broken endpoint" ambiguity ADR 0015 §4 names, and ambiguity is the thing this
  screen exists to resolve. This is the one place this screen needs a fact
  (`tenant_ai_currently_enabled`) beyond the query shapes contract §7.3 names outright — it is
  cheap to add (`AiUsageController` already has access to the same settings `AiSettingsController`
  reads) and is called out here as a deliberate, minimal addition, not a reinterpretation of the
  frozen contract.
- **Selected month has activity but every row's tokens are unreported.** Both the tile and every
  affected by-service row read "not reported by this backend"; the call counts stand and are not
  suppressed — a count is real information even when a token figure is not.
- **Cap exceeded during the month.** The relevant tile shows `critical` tone; the by-outcome
  panel shows a non-zero "Monthly limit reached" row; no additional banner is needed because the
  tile and the outcome row already say the same thing from two angles, and a third banner
  repeating it would be exactly the alert-fatigue failure mode this product elsewhere designs
  against.
- **Selected month is outside the retention window** (not reachable through the `<select>`, which
  lists only retained months, but reachable by a hand-edited URL). **Amended 2026-09-11 (frontend
  deviation 3)** — as first written this state could not occur, because `AiUsageController`
  substitutes the current month and says nothing. The controller is right to substitute:
  `TprmAiUsageEventsGrid` reads `month` from the request independently, so an un-normalised value
  would have the grid and the panels showing different months. It is the silence that is the
  defect. The response therefore carries `requested_month` (the out-of-range string, or `null`),
  and the page renders the **current month's report** under a neutral banner: "Usage for
  {requested_month} is not available — records older than {retention_months} months are not
  retained (`llm:prune-usage`). Showing {usage_month} instead." Not a blank "no data" page, which
  would hide a perfectly good current month behind a typo'd URL; and never a silent swap, which
  presents one month's numbers under another month's name.
- **Loading.** No skeleton needed for the initial page load (server-rendered). The month-change
  loading state is specified above (§2).
- **Error (500 / dropped connection on month change).** Standard product error handling; the
  previously-loaded month's data remains visible underneath until a working navigation succeeds
  or the user gives up — the page does not clear itself in anticipation of data that may not
  arrive.
- **Permission-denied.** Same as `Tprm/Settings/Ai`: nav entry hidden for non-`tprm.admin` users;
  direct navigation returns the standard 403 page.
- **Degraded network.** See §6.

## 4. Interactions

- **Change month.** Navigation as described in §2. No confirmation.
- **Follow a Subject link** in the recent-calls grid. Plain navigation to the underlying record
  (document, engagement, etc.), subject to that record's own permission check — a usage row does
  not grant visibility into a record the viewer could not otherwise open; if the morph resolves to
  a record the current user cannot view, the link renders as plain text instead of an `<a>`, not
  as a link that then 403s.
- **No destructive action on this screen.** It is read-only; no export/delete/edit control exists
  here. (An export of this report is not in 11a's scope — see the contract §9 "out" list; nothing
  on this screen claims one.)

## 5. Accessibility (WCAG 2.1 AA)

- **Month `<select>` is a native control**, fully keyboard-operable, with a visible `<label>`
  ("Month") rather than a placeholder-only affordance.
- **Every table** (by-service, by-outcome, recent calls) has a `<caption>` and proper
  `<th scope="col">` headers. The inline SVG bars are `aria-hidden="true"` and duplicate no
  information a screen reader needs that the adjacent cell text does not already carry — this is
  the accessible-equivalent requirement for the product's inline-SVG chart convention, satisfied
  by never letting an SVG be the sole carrier of a number.
- **Outcome badges** carry their state as text (the plain-language label itself), not colour
  alone; the same tone vocabulary as the settings screen is reused rather than a new palette
  invented for this screen.
- **Cap tiles' tone** is conveyed via both colour and an explicit word already in the tile's own
  hint text ("at limit" / "approaching limit"), not via colour of the numeral alone.
- **Keyboard path:** month select → "AI settings" link → by-service table (readable, no
  interactive cells) → by-outcome table → recent-calls `DataGrid` (its own documented keyboard
  behaviour — sort, filter, paginate, row links — inherited unchanged from every other TPRM list
  screen using the same component).
- **Loading-in-flight state** ("Loading {month}…") is inside an `aria-live="polite"` region so a
  screen-reader user is told a navigation is happening rather than encountering stale data with no
  explanation for the delay.

## 6. Low bandwidth (100 kbps)

- Each month change is one full-page Inertia `GET`, not a client-side aggregation over
  previously-fetched raw rows — the aggregation happens once, server-side, per month, which is
  both the portable-SQL requirement (contract §3.1, §8.12) and the lighter payload for a slow
  link (a few summary rows and a page of the grid, not the full `llm_usage_events` history).
  keeping the previous month's rendered content on screen during the transition (§2) means the
  screen does not need to redraw twice on a slow link — grey overlay, no layout shift.
- No chart library, no client-side computation of any aggregate — everything numeric on this page
  is server-computed and arrives ready to print.
- The recent-calls `DataGrid` paginates server-side, per the shared component's existing
  behaviour, so a slow connection never has to download a whole month's raw call log to see the
  first page of it.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| `month.usage_month` | selected/default month, `char(7)` |
| By-service `Calls`, `Succeeded`, `Refused/blocked` | `SELECT service, COUNT(*), SUM(outcome = 'succeeded'), … FROM llm_usage_events WHERE organization_id = ? AND usage_month = ? GROUP BY service` — plain `GROUP BY` on the `(organization_id, usage_month, service)` index; no CTE, no window function |
| By-service `Tokens` | `SUM(total_tokens)` over the same group, for rows where `total_tokens IS NOT NULL`; the "N calls did not report a count" footnote is `COUNT(*) - COUNT(total_tokens)` in the same query |
| By-service `Avg. duration` | `AVG(duration_ms)` over the same group — `duration_ms` is never null |
| By-service `Cost` | not queried at all in this deployment; the fixed phrase is rendered because every shipped profile declares no `unit_cost_per_1k_tokens_minor` |
| By-outcome counts | `GROUP BY outcome` over the same filtered set, including refusal/breaker/cap-exceeded rows, which the gateway writes per contract §5 |
| Cap tiles (`token_cap`, `call_cap`, `remaining`, `tone`) | `tp_settings.ai_monthly_token_cap` / `.ai_monthly_call_cap`; `remaining = cap - used` and the tone threshold are **both delivered by the controller** — ruled 2026-09-11 (frontend deviation 2). The 90 % threshold is a judgement and lives in one place, not one per screen. |
| By-outcome `share` | `UsageReporter::byOutcome()`, server-side, so an export and this screen cannot disagree. **Null when `call_count` is 0** — never `0`, and never a division the browser has to guard |
| `requested_month` | the out-of-range `?month=` the URL asked for, or `null`; see §5's retention state |
| `tenant_ai_currently_enabled` (spec addition, §3) | the same resolution `AiSettingsController` already computes for the settings screen's `effective.enabled` |
| Recent-calls grid rows | `(organization_id, created_at)` index, one `llm_usage_events` row per call including refusals |
| Retention window bound | `config('llm.retention_months')` |

## 8. What this screen must refuse to show

- No cost, spend or currency figure anywhere on this report — not per row, not summed, not as a
  projection. The word "spend" does not appear against an unpriced profile (ADR 0015 §4;
  contract §7.3).
- No `0`/`0.00` standing in for "not priced." The fixed phrase renders in every Cost cell in this
  deployment.
- No summed token figure that silently treats a non-reporting call as zero tokens. Where any call
  in a group did not report a count, the report says so, either wholesale ("not reported by this
  backend") or partially (the footnote), never by omission.
- No empty by-service/by-outcome tables rendered with headers and no rows for a month with zero
  calls — that state is replaced by the explanatory message in §3, which also says whether the
  silence is expected (AI currently off) or not (AI on, no activity).
- No client-side aggregation of raw usage rows — every summary figure is computed once,
  server-side, in portable SQL (no CTE, no window function, no raw JSON function, no
  `DATE_FORMAT` in a `WHERE`), per contract §8.12.
- No cross-tenant row in the recent-calls grid or any aggregate — every query here filters by the
  current `organization_id` (contract §8.13).
