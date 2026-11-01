# 07. Complete my KRIs (the risk champion's return)

**Page:** new `resources/js/Pages/Kri/MyReturn.jsx` · **Routes:** `GET /risk/kri/my-return/{department?}/{period?}` (`risk.kri.my-return`, `kri.submit_return`); `PUT .../my-return/{return}/draft`; `POST .../submit`; `POST .../reopen`; and `GET /risk/kri/returns/{return}` (`risk.kri.returns.show`, `kri.view`) which renders the same page with `readOnly: true` · Read `00-common.md` and `08-red-band-explanation.md` first.

## 1. Purpose and the user

**Who:** a risk champion: a first-line person in a department (typically `risk-owner`), who may not hold `kri.view`, so **this screen is their only KRI surface**. They arrive from the monthly email, often **on a phone**, sometimes on a poor connection, usually once a month for 15 to 40 minutes. A Risk Officer may open the read-only version to see what the champion sees.

**First ten seconds:** which department and which month, when it is due, how many KRIs are done, and the first KRI to fill in. For a returning champion: whether the work they left is intact.

**Must be able to:** enter this month's value for each KRI and see its band at once; explain any red; save a draft and come back; submit once everything is complete; reopen a submitted return to correct it (until the period closes).

**Design stance:** this is long-form data entry with save-and-resume. The partial state (some values, some missing, one red half-explained) is the normal state and gets as much design as the complete one. A draft is not a reading: the screen says so wherever a draft value appears.

## 2. Props used (ADR §16, `Kri/MyReturn`)

`{readOnly, state: 'ok'|'not_configured'|'not_open'|'empty', departments: [{id, name, url}], department: {id, code, name}|null, period: {id, label, start_date, end_date}|null, return: {id, status, display_status, requested_on, due_on, days_overdue|null, submitted_at, submitted_by: {id,name}|null, reopened_at, period_closed, can_save, can_submit, can_reopen}|null, progress: {completed, total}, lines: [Line], ownerOptions: [{id, name}], openKriIssues: [...], urls: {draft, submit, reopen}}`

`Line = {line_id, kri: {id, code, name, description, unit, frequency, direction, green_boundary, red_boundary, target_value, url|null}, bandRule, value, comment, band, previous: {period_label, value, band}|null, recorded_value|null, red_band: {root_cause, action_point, timeline, owner_id, timeline_change_reason}, open_issue: {id, reference, status, due_date, action_plan, observation, owner: {id,name}|null, is_overdue, url|null}|null}`

Draft and submit body: `{lines: [{line_id, value: number|null, comment: string|null, red_band?: {...}}]}` in the order of the props. Errors: `lines.{i}.value`, `lines.{i}.red_band.*`.

The UI pre-fills an empty `value` from `recorded_value`. `Line.band` is the band at the last save and is display-only per the ADR; this screen **does not render it**, because a stored band next to a live-computed badge would give two answers. The live badge is `bandFor(line.bandRule, value)` from `resources/js/lib/kri-band.js` (the browser mirror of the engine's rule).

## 3. Layout (state `ok`)

Reading order and DOM order, top to bottom. Container `max-w-4xl`.

### 3.1 Header
- `PageHeader`: title **Complete my KRIs**; subtitle "{department.name} · {period.label}"; breadcrumbs **KRI Monitoring** → **Complete my KRIs**.
- **Department switcher**, only when `departments.length > 1`: a `<nav aria-label="Your departments">` of links (current `aria-current="page"`) that go to each `url`. Navigating away from unsaved changes asks first (4.7).
- **Read-only banner** when `readOnly`: `role="note"`, `bg-blue-50`: "You are viewing this return read-only, as the risk champions see it. Values you see may be drafts that have not been submitted."

### 3.2 Status block
A card with: status badge from `return.display_status`; "**Collects {period.label}**"; "Requested {requested_on}"; "**Due {due_on}**" (with the weekday). The wording "Collects October 2026" is mandatory (the return opened in November collects October; 00-common section 2).

Banners inside/under the card by state (all `role="status"`, none `alert` on load):
- **Overdue:** red-700 text on `bg-red-50` with warning icon: "This return is {days_overdue} day{s} overdue. It was due {due_on}."
- **Reopened:** `bg-orange-50`: "This return was reopened on {reopened_at}. It counts as not submitted until you submit it again."
- **Submitted:** `bg-green-50`, Forest check icon: "Submitted on {submitted_at} by {submitted_by.name}." and, if `can_reopen`, the **Reopen to update** button (4.6). If `period_closed`: "This reporting period is closed, so the return can't be reopened."

**Progress:** "**{n} of {total}** KRIs have a value" with a bar. `total` is `progress.total`. `n` is **live**: the count of lines whose current form value is non-empty (it starts from the props and moves as the champion types); when the form has unsaved changes the line adds "(includes entries not saved yet)". `progress.completed` seeds the first render and is not shown separately, so one screen never carries two different "completed" figures. In read-only/submitted mode the count is `progress.completed` of `progress.total`.

### 3.3 "What needs attention" jump list
Shown when not read-only and not submitted. One line of links: "{a} need a value · {b} red need an explanation" where each count is a link that moves focus to the first such KRI's field. Counts are computed from the form state. When both are 0: "Everything is filled in. You can submit." A **Go to Save and Submit** skip link sits before it. This is the fast path on long returns and on a phone.

### 3.4 Open KRI issues (if `openKriIssues.length > 0`)
A collapsed disclosure (`<details>`): summary "Open KRI issues for this department ({n})" with "{m} overdue" in red-700 when any. Content: the same list as screen 02 (reference link, title, status, due date, Overdue badge). Reference links open in a new tab (`target="_blank" rel="noopener"`, text "(opens in a new tab)") so an unsaved return is not abandoned; the link is rendered as plain text if `url` is null (the champion may lack `issue.view`; `open_issue.url` is nullable for that reason).

### 3.5 The KRI lines
`<ol aria-label="KRIs due for {period.label}">`, one `<li>` per `Line`, in prop order. Each line is a card (`bg-white rounded-xl border border-gray-200 p-5`) with `id="kri-{line_id}"` and `scroll-margin-top` equal to the sticky footer/header offset. Within a line, top to bottom:

1. **Heading** `h2`: "{kri.code} {kri.name}" (code in mono). Beside it, small text: unit ("in %"), and a frequency chip when `kri.frequency` is not monthly: "Quarterly KRI" (so a champion knows why a quarterly KRI is here).
2. **Description** disclosure (`<details>`, "About this KRI"): `kri.description` (`whitespace-pre-line`); omitted when empty. If `kri.url` is set: link "Open the KRI page (opens in a new tab)".
3. **Last period** line: `previous` set → "{previous.period_label}: {value with unit} {band badge from previous.band}". `previous` null → "No earlier reading." (Neither is a blank.)
4. **Thresholds strip**, read-only. A tinted block (`rounded-lg bg-gray-50 p-4`) titled "Thresholds":
   - Direction as the same sentence `KriForm` uses ("Higher is worse: the value rising is bad").
   - "Green boundary {g} · Red boundary {r} · Target {t}" using `withUnit`; a missing one reads "Not set".
   - The **band legend**: the shared `KriBandLegend` component (08, section 6), fed with this line's `direction`, `green_boundary`, `red_boundary`, `unit`. It is the same component the Edit form draws, so the two surfaces can't drift. Compact (inline) variant on desktop, stacked on mobile.
   - When `bandRule.bands` is empty (no threshold in force): the legend is replaced by "No thresholds are in force for {period.label}. This KRI can't be banded; your value will be recorded without a band." (A value is still required.)
5. **Value** field: label "{period.label} value ({unit})" with "(required)" in `sr-only`; `type="number" step="any"` (decimal and negative values; no spinner arrows via CSS), `onWheel` blurs the field so scrolling doesn't change a value; up to 6 decimals (server `decimal(28,6)`): more than 6 gives an inline "Use at most 6 decimal places." Pre-filled from `recorded_value` when `value` is empty; then the help line "Pre-filled from the reading already recorded for this period ({recorded_value}). Submitting records your value as this period's reading."
6. **Live band badge** immediately right of/below the value (same row on desktop, below on mobile): `<span>` "Green" / "Amber" / "Red" / "No band" with icon; with an empty value: "Enter a value to see the band". It never reads green for an empty or non-numeric value. A sentence-form helper beside it (for example "12 % is in the red band: 10 % and above") is generated from `bandRule` and the legend wording, shown only for red and amber.
7. **Red-band section** (08), directly after the value field **before** the comment, so Tab from the value lands in it when it is revealed. Visible only when the band is red (or when the server's errors say so).
8. **Comment** (`textarea`, 2 rows, label "Comment (optional)"): becomes the reading's notes.
9. **Line status:** a small `text-xs` text: "Edited, not saved" when the line differs from the last saved/loaded state; nothing otherwise. (The props carry no per-line saved time.)
10. **Line error summary** (only on server errors for that line): `role="alert"`-free; the errors are attached to their fields (4.4).

Draft marker: in every state where a value exists but the return is not submitted (and in read-only), the value is followed by a small grey tag **"Draft"** with `title`/sr-only "Not yet submitted. A draft is not a reading." After submission the tag becomes "Submitted".

### 3.6 Footer (sticky, `sticky bottom-0`, white, top border, safe-area padding)
Hidden when `readOnly`. Contents:
- Left: a `role="status"` summary: "{a} still need a value · {b} red need an explanation" or "Ready to submit" and, when dirty, "· unsaved changes". The last-saved time ("Draft saved 14:05") appears here after a save.
- **Save draft** (`btn-secondary`), shown when `return.can_save`.
- **Submit return** (`btn-primary`), shown when `return.can_submit`.
- Submitted state: footer shows only **Reopen to update** (when `return.can_reopen`).

**Submit button rule.** The brief says Submit is enabled only when every KRI has a value and every red KRI is explained. To keep a screen-reader and keyboard user from facing a dead control with no reason, the button uses `aria-disabled="true"` (still focusable) rather than `disabled`, with `aria-describedby` pointing at the summary text. Activating it while incomplete does not submit: it moves focus to the first incomplete field (value missing, then red explanation missing) and announces why. When complete it is a normal button.

## 4. Behaviour

### 4.1 Typing a value and the band
- The band is recomputed on every keystroke locally (`bandFor(bandRule, Number(value))`); no request is made.
- The red-band section reveals/hides per 08 section 3 (debounced announcement; focus on Enter).
- Pressing **Enter** in a value field does not submit anything. It moves focus to the next logical field: the red-band section's first empty field if it is shown, otherwise the next KRI's value field (or the footer's Save draft after the last). This is the fast path for 12 values. Shift+Enter is not special.
- Clearing the value removes the band ("Enter a value to see the band") and hides the red section; any half-typed explanation text is kept in memory but is not sent (08).

### 4.2 Save draft
`PUT urls.draft` with every line, in order; empty value → `null`. A line's `red_band` is included **only** when its current band is red (the server also stores it only when red). A draft never 422s for a missing explanation (ADR §6.4). Success: redirect back with `preserveScroll`/`preserveState`; the footer shows "Draft saved {hh:mm}"; the dirty flags reset; focus stays on **Save draft**; a `role="status"` line announces "Draft saved. Nothing has been submitted yet."

The line set can change under the champion (a KRI became due or was removed; ADR §4). After every save the page compares `line_id` sets before and after: if they differ, a `role="status"` banner above the list says "The list of KRIs changed: {a} added, {r} removed." and any removed line that had unsaved input is shown in a "Removed" notice with its typed value, so nothing is lost silently.

### 4.3 Submit
1. Footer **Submit return**, only when complete. Opens a `Modal` (`maxWidth="lg"`) "Submit {department.name}'s return for {period.label}?" with:
   - "{N} readings will be recorded." (N = lines.)
   - Per band counts: "{g} green, {a} amber, {r} red, {u} without a band" (from the live bands).
   - For red lines: "{x} red KRIs: {y} will raise a new Issue, {z} will update the existing open Issue." (x = red lines; z = red lines with `open_issue`; y = x − z.) Each is a computed count of props/live state. An Issue is raised at high priority; the copy says "high priority".
   - "Submitting locks the return. You can reopen it until {period} closes." (when `return.period_closed` is false.)
   - "Nothing is recorded until you press Submit."
2. Buttons **Cancel** (focused first) and **Submit return**. In flight: both disabled, "Submitting…".
3. Success: redirect back, page now `submitted`; focus moves to the status banner heading; `role="status"` "Return submitted. {n} readings recorded."
4. Failure: 4.4.

### 4.4 Validation errors (422) and where the page scrolls
Errors arrive as `lines.{i}.value` and `lines.{i}.red_band.*` (index in prop order).
- The Modal closes. A **summary** appears at the top of the lines region (`h2` "Fix {n} item{s} before submitting", `tabindex="-1"`, programmatic focus and scroll into view): a list, one entry per error, "{kri.code}: {message}" as a link to the offending field (`href="#field-id"`, handler focuses it).
- Each error is also under its field, red-700 with icon, with `aria-invalid="true"` and `aria-describedby` pointing at the error id. The first invalid line scrolls into view with the sticky offset applied. For an error inside a red-band section the section is revealed if hidden (server says red).
- A server-red line the client didn't think was red (the threshold changed after the page loaded): the section is revealed with a note "The server reads this value as red: {reason}". The live badge is corrected via the next page load; the message "Thresholds changed since you opened this page. Reload to see the current bands." appears with a **Reload** that preserves unsaved entries (4.7).
- "Nothing was submitted" banner above the summary: "Nothing was submitted. Your entries are still here." (Submit is one transaction; ADR §6.4.)

### 4.5 Save failure, session expiry, network loss
- **Network error / 5xx on Save draft or Submit:** `role="alert"` banner at the top: "Couldn't {save your draft | submit}. Check your connection and try again. Your entries are still on this page." With **Try again**. For **Submit**, before any retry, the page reloads `only: ['return', 'lines', 'progress']` to learn whether the first request actually landed; if `return.status` is now `submitted`, show the success state instead of retrying. (A lost response after a committed write is the realistic failure on a degraded link.)
- **419 / session expired:** the common modal, plus the page keeps entries (4.7). **Retry** re-sends.
- **Lifecycle refusal** (for example submitting an already submitted return, or reopening when closed): the flash message in a `role="status"` banner; the page rerenders from the new props.
- **403 on save:** the champion was removed as contact meanwhile: banner "You are no longer a risk champion for this department, so this return can't be changed." Entries are kept and offered via **Copy my entries** (4.7).

### 4.6 Reopen
**Reopen to update** (visible when `return.can_reopen`) opens a `ConfirmDialog variant="warning"`: title "Reopen this return?"; message "{department.name}'s return for {period.label} will count as **not submitted** until you submit it again. Changes you make are recorded with the old and new values." **Cancel** is focused. After reopen the page is editable; focus moves to the status banner; `role="status"` "Return reopened. It counts as not submitted until you submit it."
When `return.period_closed`: no button; the banner says why (3.2).

### 4.7 Unsaved work is never lost silently
- **Dirty tracking** compares form state to the last loaded/saved values.
- **Leaving the page** (any link, including the department switcher; the browser Back/refresh/close): `beforeunload` guard (precedent `Tprm/Settings/Ai.jsx`) and an Inertia `before` listener opening a `ConfirmDialog` "You have changes that aren't saved. Leave without saving?" with **Stay** (focused) and **Leave**. The only navigation that bypasses it is the post-save redirect.
- **Local backup:** on every change (debounced 1 s) the form state is written to `sessionStorage` under `kri-return-{return.id}`, cleared on successful save/submit. On load, if a backup exists that differs from the props, a `role="status"` strip offers "We kept entries you hadn't saved from {time}. **Restore them** · **Discard**". `sessionStorage` (not `localStorage`) so entries don't outlive the tab on a shared computer. The backup stores values, comments and explanations only; no tokens.
- **Copy my entries:** shown inside the session-expired and 403 states: a button that copies a plain-text summary ("KRI-012 Late KYC reviews: 14.2 %") to the clipboard, as the last resort when the work can't be saved.

## 5. Other states (page-level)

| `state` | Page shows |
|---|---|
| `not_configured` | `EmptyState`: title "You aren't set up as a risk champion". Description: "Ask the Risk Office to add you under Department KRI Contacts. You also need the Submit KRI return permission." Links by permission: **Department KRI contacts** (`kri.manage_department_contacts`), and, for Risk Officers who landed here, "To see what a champion sees, open a department's return from **KRI returns**" → `risk.kri.returns.index` (`kri.view`). A user with no `kri.submit_return` never reaches this page (403). |
| `not_open` | `EmptyState`: "This month's return hasn't opened yet". "KRI returns open on the first working day of each month. You'll get an email when it does." The department switcher still shows. When `period` is set the page names it: "Waiting for {period.label}". |
| `empty` | `EmptyState`: "No KRIs are due for {department.name} in {period.label}". "A return only includes KRIs that are active, entered by hand (not published by a system) and due that month, so quarterly KRIs appear only in a quarter-end month." Link "See this department's KRIs" → `risk.kri.departments.show` for `department.id` **only if** the user holds `kri.view`. |
| `ok`, status `not_started` | Full form, empty values, progress "0 of N". |
| `ok`, `in_progress` | Full form with draft values; "Draft" tags. |
| `ok`, `overdue` | Banner (3.2); form fully usable. Overdue never blocks entry. |
| `ok`, `reopened` | Banner; editable. |
| `ok`, `submitted` | Read-only presentation (4.8). |
| `readOnly` | 4.8. |
| Loading | Inertia progress; props synchronous. |

### 4.8 Read-only presentation (submitted, or `readOnly`)
Each line shows its values as text: "{period.label} value: 14.2 % {band badge}" and the comment; the thresholds strip stays; red-band content is shown as read-only text ("Root cause", "Action point", "Timeline", "Owner") with the Issue panel. Inputs are not rendered (no disabled inputs, which read poorly). Band badges are computed from `bandRule`. The tag reads "Submitted" (submitted) or "Draft" (read-only view of an unsubmitted return).

## 6. Accessibility (WCAG 2.1 AA)

- **Headings:** `h1` Complete my KRIs; `h2` per KRI; `h3` for "Thresholds" and "Explain this red reading" within a line; the error summary is an `h2`.
- **Landmarks:** `<main>` from the layout; the footer is `role="region" aria-label="Save and submit"`; the jump list is `<nav aria-label="What needs attention">`.
- **Labels and errors:** every value, comment and red-band field has a visible label; errors are linked by `aria-describedby`, with `aria-invalid`; the error summary links to fields.
- **Live band:** the badge is **not** a live region (it would chatter on each keystroke). The only live announcements are the red-band reveal/hide (08), status after save/submit/reopen, and the footer summary when its counts change after a blur (not each keystroke).
- **Focus management:** after submit/reopen/save, focus goes to a deliberate target (4.2, 4.3, 4.6); after an error summary appears, focus goes to its heading; the modals restore focus to the button that opened them. Focus is never lost to `body`.
- **Order:** header → switcher → status → jump list → issues → lines (value → red section → comment → next line) → footer.
- **Keyboard:** the full return can be completed with Tab, Enter (4.1), Space, arrows in selects/radios, Escape in dialogs. No mouse-only action. The `aria-disabled` Submit is focusable.
- **Contrast:** secondary text `text-gray-600`; red text `text-red-700`/`-800`; amber `text-amber-800`; band badges per 00-common with words; inputs get the Navy 2px focus ring.
- **Reflow and zoom:** at 320 px and 400% zoom nothing scrolls horizontally; the sticky footer takes at most 25% of the viewport height (two-line summary max) and is released from sticky when the viewport is under 400 px tall (landscape phone) so it can't cover the field being typed in.
- **Inputs:** value input has a unit in the label (not a placeholder); `inputmode` is left to `type=number`; font size at least 16 px on mobile to stop iOS zoom.

## 7. Mobile (champions open this from email)

- Single column at all widths below `md`. Lines are full-width cards. The thresholds strip stacks (legend one band per line).
- Footer: two buttons side by side, 44 px tall, each `flex-1`; the summary text sits above them on its own line.
- The department switcher becomes a full-width `<select>`-style link list (a short vertical list).
- Description and thresholds details are collapsed behind "About this KRI" and "Thresholds" disclosures on `sm` and below, with the **live band badge and legend one tap away**: the legend is open by default for the line with focus (progressive disclosure keeps a 40-KRI return scannable).
- After a field gets focus on a phone, the browser scrolls it into view; `scroll-margin` accounts for the sticky footer so the field is not hidden behind it.
- Large numeric keyboard comes from `type=number`.

## 8. Low bandwidth (100 kbps)

- **One payload, once.** The page loads all lines (about 1 KB each with `bandRule` and issue data; 40 KRIs is about 40 KB, about 3 s). Everything after that is local: bands compute in the browser, so typing works with no network at all.
- **Writes are explicit and small.** There is no autosave and no per-keystroke request (autosave on a flaky link produces conflicts and half-saved states). **Save draft** posts the lines (about 40 KB worst case, 3 s); **Submit** is one request.
- **Offline-tolerant entry:** with the connection gone the champion can keep typing; entries go to the `sessionStorage` backup; the Save/Submit controls report failure instead of hanging. When the connection returns, **Try again** re-sends.
- **In flight:** buttons show "Saving…" / "Submitting…", `aria-busy`; after 15 s: "Still working. Your connection is slow. Don't close this page."
- **Not loaded here:** no charts, no trend, no images; the previous-period context is text from the same payload.
- **Resume cost:** coming back later costs one page load; the page opens at the top with drafts restored from the server.

## 9. The numbers on the screen

| Figure | Source | Absent |
|---|---|---|
| Period label, "Collects {month}" | `period.label` | Page state `not_open`/`empty` |
| Requested / Due dates, days overdue | `return.requested_on`, `return.due_on`, `return.days_overdue` | Overdue banner not shown |
| "n of total KRIs have a value" | `progress.total` (total) and live count of non-empty form values (seeded from the props) | In read-only: `progress.completed` of `progress.total` |
| Last period value and band | `line.previous` | "No earlier reading." |
| Thresholds (direction, green, red, target) | `line.kri.*` (the same boundaries `bandRule` is built from) | "Not set"; legend replaced when `bandRule.bands` is empty |
| Live band | `bandFor(line.bandRule, value)` (display mirror of the engine; the server recomputes) | "Enter a value to see the band" / "No band" |
| Submit-modal counts | Live bands and `line.open_issue` presence | |
| Open Issues | `openKriIssues`, `line.open_issue` | "None" lines omitted |
| Submitted at/by, reopened at | `return.submitted_at`, `return.submitted_by`, `return.reopened_at` | Banner omitted |

## 10. Known gaps and assumptions

1. **Direction vocabulary:** `KriForm` uses `higher_is_worse`/`lower_is_worse`; `bandRule.direction` and `Kri/Show` use `higher_worse`/`lower_worse`. The ADR does not say which `Line.kri.direction` uses. The legend and sentence must accept both; flag to backend to pick one.
2. **No per-line saved time or version** in the props, so concurrent drafts by two champions of one department (allowed: many champions per department) cannot be detected; last save wins silently. Recommended: `return.updated_at` or a lines version echoed on save.
3. **`readOnly` and draft visibility:** the ADR doesn't forbid Risk Officers seeing a champion's unsaved-for-submission draft values; the screen labels them "Draft". If drafts should be private until submitted, backend must send `value: null` in read-only mode.
4. **Reopen has no reason field** (`ReopenKriReturnRequest`'s body is unspecified); the dialog asks for none. A reason would help the audit trail.
5. **`not_open` carries no "opens on" date** in the props, so the page can't name the day. The copy speaks of the rule, not a date.
6. **Reminder link for non-champions** (10) targets this route and would 403 for them.
