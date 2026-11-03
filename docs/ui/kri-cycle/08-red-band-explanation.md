# 08. Red-band explanation: reusable section, the band legend, and its three placements

**Components (new, shared):** `RedBandSection` and `KriBandLegend` (proposed names), plus a hidden live region. **Placements:** `Kri/Edit.jsx` via `Kri/KriForm.jsx` Section 2 (Target Value); the Record Measurement form on `Kri/Show.jsx` (the ADR's named gap, covered here); `Kri/MyReturn.jsx` (screen 07). Read `00-common.md` first. Server rules: ADR §6.

## 1. Purpose and the user

**Who:** (a) a Risk Officer editing a KRI whose **Target Value** falls in red; (b) anyone with `kri.record_measurement` entering a red reading on the KRI page; (c) a risk champion whose value on "Complete my KRIs" is red.

**Circumstance:** they typed a number and the product has just told them it is bad. They need to understand at once that more is required, what it is, and why, without losing the number they typed or the place they were typing in.

**Rule the server enforces and the UI mirrors:** a red value cannot be saved without a root cause, an action point and a timeline. The server recomputes the band; the browser is a display mirror (`resources/js/lib/kri-band.js`, pinned to the same truth table).

## 2. Contracts used

- **Edit** (`Kri/Edit`, ADR §16): `departments`, `bandRule`, `openKriIssue: {id, reference, status, due_date, action_plan, observation, responsible_owner_id, url}|null`, `openRedBreach: {id, period_label, root_cause}|null`, `redBandDefaults: {owner_id}`; existing `users` (owner list). Posts `business_unit_id` and `red_band`.
- **Show** (`Kri/Show`): the record form gains `bandRule` (for the default date's period) and posts `red_band`; red `readings` gain `{root_cause|null, issue: {reference, status, due_date, url|null}|null}`. Existing: `kri.unit`, `kri.direction`, `kri.greenThreshold`, `kri.redThreshold`, `can.recordMeasurement`.
- **MyReturn** (screen 07): `Line.bandRule`, `Line.red_band`, `Line.open_issue`, `ownerOptions`.
- **Payload key** everywhere: `red_band: {root_cause, action_point, timeline (Y-m-d), owner_id?, timeline_change_reason?}`. 422 keys: `red_band.root_cause`, `red_band.action_point`, `red_band.timeline`, `red_band.owner_id`, `red_band.timeline_change_reason` (champion path: `lines.{i}.red_band.*`).

## 3. The behaviour, in one place

### 3.1 Band rule (mirror of the engine)
`bandFor(rule, value)` returns `'green' | 'amber' | 'red' | null`. `null` for an empty/non-numeric value or a rule with no bands (any null boundary). **On the red boundary is red in both directions; on the green boundary is amber in both directions** (ADR §6.1). `ruleFromBoundaries(direction, green, red)` builds a rule from what the user is typing (Edit form). `KriBandLegend` text and the badge are generated from the same rule, never typed twice.

### 3.2 Hidden until red
The section is **not in the DOM** while the band is not red (not `display:none`, which still reads in some screen readers when toggled). It mounts when the band becomes red and unmounts when it stops being red. The text the user typed lives in the **parent form's state**, so hiding and re-showing the section restores it. Hidden text is **not sent**: the payload omits `red_band` unless the current band is red. The only exception is server-driven reveal (3.5).

### 3.3 Reveal, announcement and focus (the sequence)
1. The user types in the value (or on Edit: changes direction, boundary or target).
2. The band is recomputed on each keystroke; the section mounts/unmounts at once so sighted users see it.
3. **Announcement is debounced.** Intermediate values flip the band while typing ("1", "12", "120"), which would chatter. The component announces only when the band's *redness* has differed from the last announced state for **700 ms of idle**, or on blur, whichever is first.
4. A single `div role="status" aria-live="polite" aria-atomic="true" class="sr-only"` is **mounted with the host (always present, empty)** so the announcement reaches assistive technology; its text is set on change. (A live region inserted together with its text is unreliable.) Strings:
   - Entered red: "{KRI code} is in the red band. Root cause, action point and timeline are now required." For a reading on MyReturn also names the KRI; on Edit: "The target value is in the red band. ..."
   - Left red: "{KRI code} is no longer in the red band. The explanation fields are hidden. What you typed is kept."
   - Server-forced reveal: "The server reads this value as red. Explanation required."
5. **Focus.** Focus is **never moved while the user is typing in the value**. It moves in two situations: (a) Tab out of the value field, which lands in the section because the section is placed immediately after the value in DOM order (on MyReturn: value → section → comment; on Show: value/notes → section); (b) the user presses **Enter** in the value field, which moves focus to the first empty required field (root cause). On the Edit form, where Enter in a number field would submit the whole form, Enter is intercepted **only when the section is shown and incomplete**: it moves focus to the section instead of submitting. A mouse user clicking elsewhere keeps focus where they click; the section is visible and announced. This departs from the brief's literal "focus moved to it" because stealing focus mid-keystroke would swallow digits; the intent (a keyboard or screen-reader user lands in the new fields without hunting) is kept by the DOM order and Enter.
6. Reduced motion: no animation; the section appears instantly.

### 3.4 Fields
| Field | Control | Rules (mirrors the server) |
|---|---|---|
| **Root cause** | `textarea`, 3 rows, `maxlength=5000`, counter appears at 4500 ("{n} characters left") | Required |
| **Action point** | `textarea`, 3 rows, `maxlength=5000`, counter | Required |
| **Timeline for resolution** | `type="date"` with `min` = today in Africa/Lagos | Required. Not in the past, **except** an unchanged date equal to the open Issue's current `due_date` (a champion may confirm an overdue action without moving it; ADR §6.2). When the typed date is in the past and differs from the open Issue's date: inline "Choose today or a later date." |
| **Action owner** | `select`, optional | Default shown per placement (3.6). Never required |
| **Reason for changing the timeline** | `input` text, `maxlength=500` | Appears **only** when an open Issue exists and the timeline differs from its `due_date`. **Required** then (ADR §6.2). Help: "Why is the date changing? This is recorded on the Issue." |

Client validation mirrors the above on submit/blur and shows errors; the server's 422 is always honoured and shown through the same slots.

### 3.5 Server-driven reveal
If a 422 arrives with `red_band.*` (or `lines.{i}.red_band.*`) for a value the client thinks is not red (rules changed, or the Show form's date moved into another period), the section mounts, shows the server errors, and announces "The server reads this value as red. Explanation required."

### 3.6 Existing open Issue
When the placement supplies an open Issue (`openKriIssue` / `line.open_issue`) the section starts with a panel (not a field): "This KRI already has an open Issue" with the reference (link when `url` is non-null; opens in a new tab), status badge, due date, an **Overdue** badge when `is_overdue`, and the current action plan and root cause in a `<details>`. Copy: "Saving updates this Issue. A second one is never opened." Two buttons:
- **Use the existing action**: copies Issue → fields (root cause ← `observation`, action point ← `action_plan`, timeline ← `due_date`). This is the "confirm it still applies" path: the champion confirms deliberately rather than the form silently pre-filling. The fields stay editable.
- No "start fresh" button is needed; typing replaces.
If the Issue is overdue the panel adds: "This Issue is overdue. Keeping the same date is allowed. It stays overdue and keeps being chased."
Owner: with an open Issue the select starts on **"Keep the current owner"** (value empty, not posted) so a reused Issue's owner changes only if the user picks someone; with no open Issue it starts on the default.

### 3.7 What gets written (shown as a sentence, from the ADR)
Under the heading, one `text-sm` sentence by placement:
- New Issue: "Saving will raise a new high-priority Issue with this action point and due date. The root cause is also recorded on the breach."
- Existing Issue: "Saving will update Issue {reference}. Its status and priority don't change."
- Edit with no open red breach: "No breach is open for this KRI, so the root cause is recorded on the Issue."
(The "high priority" wording is ADR §0: always `high`.)

## 4. Visual design

Container: `role="group" aria-labelledby="{id}-title"`, `rounded-lg border border-red-200 border-l-4 border-l-[#C53030] bg-red-50 p-4 mt-3`. Title (`h3`, `text-sm font-semibold text-red-900`, warning icon `aria-hidden`): placements' wording below. Fields sit on white (`bg-white`) inside the tinted box. Labels `text-xs font-medium text-gray-700` as in `KriForm`'s `Field`, with the red `*` and sr-only "(required)". Error text `text-red-700` with an icon. Contrast: red-900 on red-50 about 11:1; red-700 on red-50 about 5.7:1; the 4 px Error-token left border is 5.5:1 against white (non-text 3:1 met).
The red band is **not** the only cue: heading words, icon, required markers and the live announcement all carry the state.

## 5. Component contract (proposed, for `frontend-engineer`)

`<RedBandSection>` props: `idPrefix`; `kriCode`; `context` (`'target' | 'reading'`) for wording; `rule` (`{direction, bands}`) and `value` (so it can compute `isRed` itself or take `isRed` from the host); `values` (`{root_cause, action_point, timeline, owner_id, timeline_change_reason}`) and `onChange(field, value)`; `errors` (flat map for this instance, keys without the line prefix); `openIssue` (`{id, reference, status, due_date, action_plan, observation, is_overdue, url}|null`); `openBreach` (`{period_label, root_cause}|null`, Edit only); `ownerOptions` (`[{id,name}]` or `null` to omit the owner field); `ownerDefault` (id or `null`); `lagosToday` (string); `readOnly`; `serverForced` (bool, 3.5).
`<RedBandHost>` (a thin wrapper holding the live region and the debounce/last-announced logic) is used by each placement; it exposes `{ liveRegion, shouldShow, focusSection }`.
`<KriBandLegend>` props: `direction`, `green`, `red`, `unit`, `variant` (`'stacked' | 'inline'`).

## 6. `KriBandLegend` (the corrected `BandPreview`)

Extract `BandPreview` from `KriForm.jsx` into a shared component; `KriForm` and `MyReturn` both import it. Its text is **generated from the rule and corrected to the engine** (ADR §6.1):

| Direction | Green | Amber | Red |
|---|---|---|---|
| Higher is worse | "below {G}" | "{G} up to but not including {R}" | "{R} and above" |
| Lower is worse | "above {G}" | "above {R} up to and including {G}" | "{R} and below" |

(Values formatted with `withUnit(n, unit)`.) The existing note for inverted boundaries is kept. Missing boundary: "Enter both boundaries to see the bands." (Edit) / "Not set" (MyReturn). The component accepts both direction spellings (`higher_is_worse` and `higher_worse`).
The legend and the badge both come from `kri-band.js`; a frontend check compares them for the truth-table cases, including the two boundary edges.

## 7. Placement A: Edit KRI, Thresholds section (Target Value)

**File:** `Kri/KriForm.jsx` Section 2 "Thresholds" (rendered by `Kri/Edit.jsx`; the Create page shows **no** red-band section, ADR §6.3, and `Kri/Thresholds.jsx` is untouched).

- **Trigger:** `bandFor(ruleFromBoundaries(data.direction, data.green_threshold, data.red_threshold), Number(data.target_value))` is `'red'`. Recomputed when direction, either boundary, or the target changes. `bandRule` (prop) is the starting rule before any boundary edit.
- **Position:** inside Section 2, after the two-column grid (inputs left, "Resulting bands" right), as a full-width row. DOM order: Target Value → (Actively monitored checkbox when `showActive`) → red-band section → Section 3. The section title: **"The target value is in the red band: explain it"**.
- **Owner:** `select` from the Edit page's existing `users`. Default per 3.6: with an open Issue, "Keep the current owner" (the Issue's `responsible_owner_id` is shown by name from `users`); otherwise `redBandDefaults.owner_id` pre-selected.
- **Open red breach (`openRedBreach`):** under the title: "The root cause will also be saved on the open red breach for {period_label}." If it has `root_cause`, a button **Start from the current root cause** copies it into the field.
- **Open Issue:** panel per 3.6.
- **Submit behaviour:** the **Update KRI** button stays enabled. On click with the section shown and incomplete: client validation shows errors, focus goes to the first invalid field, **no request is sent**. A complete form posts `put` with `red_band` added through the existing `transform`. Server 422s appear in the same fields.
- **Important consequence:** the server recomputes on **every** update (ADR §6.3). A Risk Officer who only fixes a typo in a KRI whose target is already red is asked for the explanation again. The open-Issue panel and **Use the existing action** make this a single click when an Issue exists; with no Issue they must write one. The page says so in the section intro: "This KRI's target is in the red band, so an explanation is needed whenever it is saved." (Logged in section 14, gap 5.)
- **Department field (Section 1).** Add a **Department** `select` (label "Department", options from `departments`), first option **"No department (Unassigned)"** (empty value). Help text: "A KRI with a department is included in that department's monthly return. KRIs with no department aren't included and don't count on the submission dashboard." On Edit, add: "Moving a KRI to another department takes it out of the old department's return the next time that return is saved or submitted. A draft value for it is discarded and recorded in the audit trail." Error slot under it for `business_unit_id`. (Create gets the same field; see gap 1.)
- **Legend fix:** `BandPreview` is replaced by `KriBandLegend` (section 6) in this same Section 2.

## 8. Placement B: Record Measurement, `Kri/Show.jsx` (the ADR's known gap, covered)

**File:** the `RecordMeasurement` panel in `Kri/Show.jsx`. Without this the form would post a red reading the server rejects and the user could not fix it (standard §10), so it is required, not optional.

- **Fields on the form:** Measurement Date, Value, Notes (existing) + the red-band section.
- **Trigger:** `bandFor(bandRule, Number(data.value))`, **valid only while the Measurement Date still falls in the period `bandRule` was built for**. The ADR gives `bandRule` for the default date's period and no period bounds. Behaviour:
  - Default date: the form's date defaults to **today in Africa/Lagos** (not `new Date().toISOString()`, which is the UTC date and is yesterday for the first hour of each Lagos day). While the date equals that default, the band is live as in other placements.
  - **Date changed to anything else:** the band can't be known client-side (the KRI's period boundaries depend on its frequency and the tenant calendar). The badge reads "Band will be checked when you record this date" and the section stays hidden. The server recomputes on submit; if red and unexplained it returns `red_band.*` errors and the section mounts (3.5) with the announcement. The existing help paragraph already says a reading is judged by the limits in force for its date.
- **Position:** after the Date / Value / Notes grid, before the help paragraph and the Cancel/Record buttons. DOM order: Date → Value → Notes → section → buttons. (Tab from Value goes to Notes first; the announcement and visible section cover the gap; Enter in Value moves focus to the section when red, instead of submitting.)
- **Title:** **"This reading is in the red band: explain it"**.
- **Owner:** the page has **no owner list and no default** in its props, so the **owner field is omitted** here; the server then defaults the Issue owner to the person recording. The section intro says: "You will be the owner of the Issue." (This is a placement of a smaller form; logged as gap 2.)
- **Open Issue:** the page has no `openKriIssue` prop. The section therefore cannot show the existing Issue up front. Consequence handled: if the KRI has an open Issue and the user changes its due date, the server demands `timeline_change_reason`; the section shows that field **only after** the server returns an error on it (reveal on error), with a standing help line: "If this KRI already has an open Issue, changing its due date needs a reason." Logged as gap 2.
- **After recording:** `onSuccess` closes the panel (as now); the new reading appears in the Readings table with its explanation (below).
- **Errors:** `errors['red_band.root_cause']` etc. flat keys from Inertia; shown in the section. `preserveScroll` is already set.

### 8.1 Show page: reading the explanation (read side)
- **Readings table:** a red reading with `root_cause` or `issue` gets a second row spanning the table (`colSpan=5`): "Explanation: {root cause, 3 lines then Show more}"; "Issue {reference} ({status}) due {due_date}" with the reference as a link when `issue.url` is non-null, plain text otherwise. A red reading with neither: "No explanation recorded." (A true statement; do not guess why.) Non-red readings get no second row.
- **Breaches table** is unchanged; the Breach Register grid gains **Root cause** (truncated to about 80 characters with the full text in `title` and an accessible expander) and **Issue** (reference link) columns per ADR §16.
- **"Bands in force" chips (existing)** show `≥ min ≤ max` and would contradict the corrected legend at the green boundary. Recommended: feed them from `KriBandLegend`'s text so the page has one wording (gap 3).

## 9. Placement C: Complete my KRIs (screen 07)

- **Trigger:** `bandFor(line.bandRule, Number(value))`.
- **Position:** directly after the value field and before the comment, inside the KRI's card.
- **Title:** **"{kri.code} is in the red band: explain it"**.
- **Owner:** `ownerOptions` (`[{id,name}]`); default is the champion (`auth.user.id`) when present in `ownerOptions`, otherwise "Me" with an empty value. With `line.open_issue`: "Keep the current owner".
- **Open Issue:** `line.open_issue` per 3.6 (the brief's "update the Issue or confirm it still applies").
- **Draft vs submit:** Save draft sends `red_band` only for lines currently red and never blocks on missing fields; Submit blocks (client and server) on any missing field. Errors: `lines.{i}.red_band.*`.
- **Read-only/submitted:** the explanation is shown as text with the Issue link; no fields.

## 10. States

| State | Behaviour |
|---|---|
| **Not red** | Section absent; live region empty. |
| **Red, empty** | Section mounted, fields empty, announcement made. |
| **Red, partly filled** | Normal. On MyReturn the footer counts "n red need an explanation". |
| **Red, complete** | No errors; MyReturn counts it done. |
| **Red then fixed to not red** | Section unmounts; announcement "no longer red"; typed text kept in parent state, not sent. Going red again restores it. |
| **Null band** (no thresholds, empty value) | Not red. Badge "No band". |
| **Existing open Issue** | Panel (3.6). |
| **Existing Issue overdue** | Panel adds the overdue sentence; same-date allowed. |
| **Timeline changed on a reused Issue** | Reason field appears and is required. |
| **Validation error (client or 422)** | Under the field, `role` not alert on mount (focus goes to the first invalid field); `aria-invalid`, `aria-describedby`. |
| **Save failure / 5xx / network** | The host form's banner (Edit: top of the form; Show: inside the panel; MyReturn: 07 section 4.5). Typed text is kept. |
| **Expired session** | The host form's handling (00-common). Typed text is kept. |
| **Permission denied** | A champion can't reach Edit (403, ADR §10); the Show record form appears only with `can.recordMeasurement`. The section has no permission logic of its own. |
| **Loading** | Not applicable (local). |
| **Degraded network** | Section 12. |

## 11. Accessibility summary
- Group labelled by its title; fields labelled; errors tied by id; required via `required`/`aria-required` and the `*`.
- Live region mounted at all times; debounced announcement; no focus theft while typing; Enter and DOM order carry keyboard users in.
- Unmount-on-hide means a screen reader never meets a hidden form.
- Contrast and non-colour cues per section 4. Date input native; its `min` is Lagos today.
- Touch targets 44 px on mobile; textareas full width, `font-size` at least 16 px on mobile.
- The open-Issue link opens in a new tab with the visible text "(opens in a new tab)" so a half-written explanation isn't abandoned.

## 12. Low bandwidth
No requests are made by the component. Band and reveal are computed locally from props already on the page. The only payload cost is the `red_band` object on the host's own save (about 1 to 10 KB of text). Text is held in memory and backed up by the host (07: `sessionStorage`). On Edit and Show, text typed before a failed request stays in the form.

## 13. The numbers on the screen
| Figure | Source |
|---|---|
| Band (green/amber/red) | `bandFor(rule, value)`; rule from `bandRule` (server `KriBandRule`) or `ruleFromBoundaries` for Edit |
| Legend boundaries | The KRI's green and red boundaries (props or form state) |
| Issue reference, status, due date | `openKriIssue` / `line.open_issue` / `readings[].issue` |
| Characters left | Count of the textarea value against 5000 / 500 |
| "Today" for the date `min` | Africa/Lagos date from the browser clock; the server is authoritative |

## 14. Known gaps (also in the handoff)
1. **Create page props:** the ADR adds the `departments` prop only to `Kri/Edit`. The Department select on Create needs it too (the controller's `create` action).
2. **Show record form has no `openKriIssue`, owner list or `redBandDefaults`.** Handled by omitting the owner field and reveal-on-error for the timeline reason. Recommended: add `openKriIssue` and `ownerOptions` to `Kri/Show`.
3. **`Kri/Show` "Bands in force" chips contradict the corrected legend** at the green boundary. In scope for consistency; needs a yes from architect.
4. **`bandRule` on Show has no period bounds**, so the band can be live only for the default date. Recommended: add `bandRule.period: {label, start_date, end_date}` so any date within it stays live.
5. **Edit re-asks for the explanation on every save while the target is red** (server rule, ADR §6.3); the UX is softened but not removed. Confirm this is intended with the user.
6. **Focus rule departs from the brief's literal wording** (3.3 step 5).
