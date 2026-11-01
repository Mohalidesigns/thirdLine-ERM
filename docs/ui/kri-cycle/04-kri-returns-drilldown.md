# 04. KRI returns drill-down: departments not submitted, reminders and history

**Page:** new `resources/js/Pages/Kri/Returns/Index.jsx` · **Routes:** `GET /risk/kri/returns` (`risk.kri.returns.index`, `kri.view`) and `POST /risk/kri/returns/remind` (`risk.kri.returns.remind`, `kri.send_reminder`, `throttle:kri-reminders`) · Read `00-common.md` first.

## 1. Purpose and the user

**Who:** a Risk Officer (`kri.send_reminder`: risk-manager, CRO) chasing departments after the due date; a read-only viewer (`kri.view` only) checking status.

**First ten seconds:** which departments have not submitted, how overdue each is, who their champion is (or that nobody is), when each was last reminded and how many times, and which ones can be reminded **today**. Then: tick, send, see what happened.

**Must be able to:** select one or many departments, see exactly who the next reminder will reach, confirm, send, read the per-department result, and inspect each department's reminder history with delivery outcomes.

## 2. Props used (ADR §16)

`{period: PeriodOpt, periods: PeriodOpt[], filter: 'not_submitted'|'submitted'|'all', summary: ReturnSummary, rows: [{return_id, department: {id, code, name}, status, display_status, due_on, days_overdue|null, kris_due, kris_completed, champions: Contact[], has_champion, reminder_count, reminded_today, next_tier (1-4), last_reminder: {sent_on, sent_by: {id,name}|null, tier, status}|null, open_kri_issues, overdue_kri_issues, notices: [{id, kind, sequence, tier, status, sent_on, queued_at, sent_by: {id,name}|null, recipients: [{name, email, contact_role, status, skip_reason, sent_at, error}]}], view_url}], canRemind, scopeNote}`.

Reminder POST body: `{return_ids: int[]}` (1 to 50, distinct). Reply is a redirect back with flash `reminderResults: [{return_id, department, outcome: 'queued'|'already_reminded_today'|'submitted'|'out_of_scope', tier|null}]`. `Contact = {id, name, email, is_active}`.

## 3. Layout

### 3.1 Header and controls
`PageHeader`: title **KRI returns: {period.label}**; subtitle "Where each department's return stands now"; breadcrumbs **KRI Monitoring** → **Dashboard** (`risk.kri.dashboard`) → **Returns**.

Under it, one row:
- **Month** `select` (from `periods`, label "Month"). Change → `router.get(url, {period, filter}, {preserveScroll, replace})`.
- **Show** filter as a `<nav aria-label="Filter returns">` of three links styled as a segmented control: **Not submitted** (default), **Submitted**, **All** (`?filter=`). `aria-current="page"` on the active one. (The query parameter name `filter` follows the prop name; the ADR does not state the request key. Assumption logged in section 10.)
- `scopeNote` as an info strip (verbatim) when set.

### 3.2 Summary strip
A compact `dl` row (not `KpiCard`s; this is a working list, not a dashboard): **Departments** `denominator` · **Submitted** `submitted` · **Not submitted** `not_submitted` · **Overdue** `overdue` · **No risk champion** `no_champion`. Same invariants as screen 03.

### 3.3 The bulk bar (only when `canRemind`)
A sticky bar (`sticky top-0 z-10 ... rounded-lg border bg-white p-3 shadow-sm`, the pattern in `Bcms/Identity/Review.jsx`):
- "Select all that can be reminded" checkbox (selects every enabled row, capped at 50).
- `aria-live="polite"` count: "{n} selected".
- **Send reminder** button (`btn-primary`), disabled with `aria-disabled` while `n = 0`. A short line beside it states why when disabled and when the selection hits the cap: "Select at least one department." / "50 is the most you can remind at once."
- **Refresh** button (`btn-secondary`): reloads `only: ['rows', 'summary']`; text "Refresh delivery status" with the time of the last load ("Updated 14:05 Lagos time").

Without `canRemind` the bar and the checkbox column do not exist, and a note sits where the bar would be: "You can see returns but not send reminders."

### 3.4 The table (desktop, `lg` and up)
`<table class="data-table">`, `<caption class="sr-only">Departments and the state of their KRI returns for {period.label}</caption>`. Rows are sorted on the client: `days_overdue` descending (null last), then `due_on` ascending, then department name. (The ADR does not fix the server order; this makes the worst case first deterministically. Logged in section 10.)

| Column | Content | Source |
|---|---|---|
| (checkbox) | `canRemind` only. Label (visually hidden) "Select {department.name}". **Disabled** when the row cannot be reminded, with the reason shown as visible text in the Reminders cell (not only a tooltip): `status = submitted` → "Submitted"; `reminded_today` → "Reminded today". | `status`, `reminded_today` |
| Department | Name (link to `view_url`, the read-only return), code below in mono. | `department`, `view_url` |
| Status | Badge (00-common). Under it: "{N} days overdue" when `days_overdue` is set; else "Due {due_on}". | `display_status`, `days_overdue`, `due_on` |
| KRIs | "{kris_completed} of {kris_due} have a value" | `kris_completed`, `kris_due` |
| Risk champions | Names as a comma list; an inactive one carries "(inactive)". `has_champion = false`: amber "No risk champion" with icon. | `champions`, `has_champion` |
| Reminders | "{reminder_count} sent". Under it: last reminder "Mon 9 Nov by Ada Obi, reached team leads" or "by scheduler"/"by system" when `sent_by` is null. Words for tier from 00-common. Status in words ("Handed to mail server", "Some failed"...). `reminder_count = 0` and `last_reminder` null: "No reminder yet". Plus the disabled-reason text from the checkbox column. | `reminder_count`, `last_reminder`, `reminded_today` |
| KRI issues | "{open_kri_issues} open" and, if `overdue_kri_issues > 0`, "· {n} overdue" in red-700 with icon. | `open_kri_issues`, `overdue_kri_issues` |
| (actions) | **History** disclosure button (`aria-expanded`, `aria-controls`), labelled "Show reminder history for {department.name}". | `notices` |

### 3.5 Expanded history (below the row, `colSpan` full width)
Rendered **only when expanded** (the DOM for a closed row's notices does not exist). Contents:
- A short legend sentence (once): "Handed to mail server means our mail system accepted the message. It does not confirm it reached an inbox."
- One block per notice, newest first (`notices` order is the server's; sort by `queued_at` descending client-side): **"Monthly request"** (`kind = request`) or **"Reminder {sequence}"**, date `sent_on`, "by {sent_by.name}" or "by the scheduler" when null, reached "{tier label}" (00-common tier table), status in words.
- Under it a small table of `recipients`: Name · Email · Role · Status · When. Status per 00-common. A `failed` row shows the `error` text inside a `<details>` ("Why it failed"), never inline, because it can carry transport internals. `skipped` rows show the plain-language reason. `sent_at` shown in Lagos time.
- `notices` empty: "No request or reminder has been sent for this return." A `request` notice with status `no_recipients`: "The monthly request went to nobody because no risk champion was set at the time."

### 3.6 Mobile layout (below `lg`)
Each row becomes a card (`<li>`): checkbox top-left (44 px hit area), department name as link, then the labelled fields as a `dl` in the table's order, the History button full width. Bulk bar is `sticky bottom-0` instead of top (thumb reach), with safe-area padding.

## 4. The reminder confirmation (`Modal`, `maxWidth="2xl"`)

Opened by **Send reminder**. Not `ConfirmDialog`, because it lists departments (justified in `00-common.md`).

- **Title:** "Send a reminder to {n} department{s}?"
- **Body**, in order:
  1. A table (or on mobile a list): **Department** · **Reminder** · **Will reach**. For each selected row:
     - Reminder: "Reminder {reminder_count + 1}".
     - Will reach: the words of `next_tier` from the 00-common tier table, e.g. "Risk champions and team leads". If `next_tier = 4` and `reminder_count + 1 > 4`, append " (the highest level; every further reminder goes to the same four levels)".
     - If `has_champion = false`: an amber line under it "No risk champion is set, so risk champions will not receive this."
  2. One paragraph: "Each department can be reminded once a day. Emails are queued and go out shortly. Who receives each one is worked out at the moment it is sent, so contact changes you make now still apply."
  3. If any selected row has `has_champion = false`: a second paragraph, once: "A reminder to a department with no risk champion still counts as that department's next reminder and uses its one reminder for today."
- **Buttons:** **Cancel** (`btn-secondary`) and **Send {n} reminder{s}** (primary Navy). Initial focus is on **Cancel**, so a stray Enter cannot send an email that cannot be recalled. The confirm button shows "Sending…" and both buttons disable while the request is in flight.
- Escape and backdrop click cancel. Not available while sending.

The confirmation names the tier the *next* reminder reaches (ADR §0.4 / brief 2.4). The recipients' names are not in the props for tiers above champions, so the dialog names **levels**, not people. (Logged in section 10.)

## 5. After sending: the results panel

On success the page reloads with fresh `rows` and the flash `reminderResults`. Above the table, a results panel (`role="status"`, `tabindex="-1"`, focus moved to its heading **"Reminders: {q} queued, {s} skipped"** once, then it's inert):
- One line per `reminderResults` item:
  - `queued`: "{department}: queued, reaches {tier words}."
  - `already_reminded_today`: "{department}: skipped, it was already reminded today."
  - `submitted`: "{department}: skipped, its return has been submitted."
  - `out_of_scope`: "{department}: skipped, it is outside your departments."
- A footer line: "Queued means the email will go out shortly. Open a department's history to see each person's outcome, then use Refresh."
- **Dismiss** button. Selection is cleared. The panel stays until dismissed or the next send.

## 6. States

| State | Behaviour |
|---|---|
| **Loading** | Inertia visit progress. `Refresh` shows "Refreshing…" with `aria-busy`. |
| **Empty: all submitted** | `filter = not_submitted`, `summary.not_submitted = 0`, `denominator > 0`: `EmptyState` "Every department has submitted for {period.label}" with link **Show all departments** (`?filter=all`). |
| **Empty: no returns** | `denominator = 0`: "There are no returns for {period.label}. Either no department had KRIs due, or none are in your scope." |
| **Empty: submitted filter** | "No department has submitted yet for {period.label}." |
| **Not configured / cycle never opened** | The ADR types `period` as non-null here, so a month with no cycle cannot reach this page; the dashboard card (03) is where "no cycle yet" is shown. If the server redirects here with no data, the empty state "no returns" above applies. |
| **No champion** | Row marker and confirmation warning (sections 3.4, 4). |
| **Overdue** | Row badge and days. |
| **Reopened** | Badge "Reopened"; the row is selectable (not submitted). |
| **Submitted** | Row (visible under *Submitted*/*All*) has a disabled checkbox with "Submitted". |
| **Reminded today** | Disabled checkbox with "Reminded today". |
| **Over the 50 cap** | Select-all selects the first 50 enabled rows (in table order) and says "50 selected, the most you can send at once." Individual boxes beyond 50 stay enabled only after unselecting another. |
| **Send failure: network** | The modal closes, the page shows `role="alert"`: "We couldn't confirm whether the reminders were sent." with **Check now**, which reloads `only: ['rows']`. Selection is kept. The user decides from the refreshed `reminder_count`/`reminded_today` (the once-a-day rule makes a repeated send harmless: a duplicate is answered `already_reminded_today`). Never auto-retry. |
| **Send failure: throttle (429)** | Intercept: "Too many requests. Wait a minute, then try again." Selection kept. |
| **Send failure: validation (422)** | e.g. more than 50 or an id outside the tenant: "Something in your selection isn't valid. Refresh and select again." |
| **Expired session (419)** | Common modal; **Retry** re-posts the same selection. |
| **Permission denied** | No `canRemind`: no controls. No `kri.view`: 403 page. |
| **Delivery partly failed** | History shows "Some failed" with per-person outcomes. No separate page-level alert. |
| **Degraded network** | Section 8. |

## 7. Accessibility

- **Selection:** native checkboxes with labels; the live count is `aria-live="polite"`. Select-all has an accurate label and is `indeterminate` when partially selected.
- **Disabled checkboxes** keep their visible reason text associated via `aria-describedby`, so a screen reader hears *why* the row can't be selected.
- **Table semantics:** `caption`, `scope="col"`, department cell as `<th scope="row">`. The expand button is inside the last cell with `aria-expanded` and `aria-controls` the id of the history row; the history row has `role="region"` and `aria-label="Reminder history for {department.name}"`.
- **Modal:** Headless UI `Dialog` gives focus trap, `aria-modal`, focus return to the **Send reminder** button on close; the title is the dialog's accessible name; the department table inside has its own `caption`.
- **Results panel:** focus moves to its heading once so the outcome is read; it's `role="status"`, not `alert`, because the user caused it and focus already lands there.
- **Not colour alone:** every badge/marker has words and icons; "(inactive)" and "No risk champion" are text.
- **Contrast:** amber text is `text-amber-800` on `bg-amber-50` (about 7:1); red text is `text-red-700` or `-800` on white; gray secondary text `-600`.
- **Keyboard:** Tab through filters → bulk bar → checkboxes/links/buttons in row order. Space toggles a checkbox. `Shift`-range selection is **not** specified (not needed for this size); do not add it without a keyboard equivalent.
- **Focus after Refresh:** stays on the Refresh button; the "Updated hh:mm" text updates in a `role="status"` region.

## 8. Low bandwidth

- **The payload is the risk on this page.** `rows[].notices[].recipients[]` is nested history for every department on one response. A department reminded 6 times with 8 recipients is about 10 KB; 60 departments can reach hundreds of KB, which at 100 kbps is tens of seconds. The ADR's shape is followed as given; this spec does what it can on the client:
  - History DOM is built only on expand; collapsed rows cost nothing to render.
  - **Refresh** reloads `only: ['rows', 'summary']`, never the whole page.
  - Nothing polls.
  - Skeleton rows (`bg-gray-100 animate-pulse`, the final row height) show while an Inertia visit loads the table.
  - The recommended backend change (log under gaps): send only the latest few notices per row, or load the full history of one row on expand through a small endpoint.
- Sending is one small POST (up to 50 ids). While it's in flight the button reads "Sending…"; after 15 s the status line "Still working. Your connection is slow. Don't close this page." appears.
- No images, no chart, no polling.

## 9. The numbers on the screen

| Figure | Source | Absent |
|---|---|---|
| Summary strip | `summary` (grouped counts of `kri_returns` for the period) | Strip omitted if `denominator = 0`; message instead |
| Days overdue | `rows[].days_overdue` (calendar days after `due_on`, Lagos) | "Due {date}" |
| KRIs completed of due | `kris_completed`, `kris_due` (return lines) | "-" if `kris_due = 0` |
| Reminder count / next reminder number | `reminder_count`, and "next" = `reminder_count + 1` (a derived figure from that prop) | "No reminder yet" |
| Tier reached | `next_tier`, `last_reminder.tier`, `notices[].tier` mapped by the 00-common table | |
| Delivery outcome | `notices[].recipients[].status` | |
| Selected count | Count of ticked rows (client) | |

## 10. Known gaps and assumptions

1. **Request key for the filter** is assumed to be `?filter=`; the ADR gives the prop name only.
2. **Server order of `rows` is unspecified**; the client sorts as in 3.4.
3. **Names for tiers above champion are not in the props** (`champions` only). The confirmation can name levels but cannot warn "no unit head is set". Recommended addition: per-row counts of contacts by role (for example `contact_counts: {team_lead, unit_head, group_head}`), so the dialog can say "No unit head is set".
4. **Payload growth** from nested `notices` (section 8).
5. **Flash shape:** `reminderResults` is assumed to arrive as a flash/shared prop readable with `usePage().props.flash`; confirm with backend.
6. **Reminder to a department with no champion** is described as consuming that department's reminder number and its daily slot, which follows from ADR §5 (the notice row and its unique keys are inserted before recipients are resolved). Verify with backend before the copy ships.
7. **Reminder links for non-champions:** see screen 10 (they may land on a 403 if the email's link is `my-return`).
