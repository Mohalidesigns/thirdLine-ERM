# 06. Organisation holidays (settings)

**Page:** new `resources/js/Pages/Settings/Holidays.jsx` · **Routes:** `GET /risk/settings/holidays?year=` (`risk.holidays.index`), `POST` (`risk.holidays.store`), `DELETE /risk/settings/holidays/{holiday}` (`risk.holidays.destroy`); all `permission:holiday.manage` · Read `00-common.md` first.

## 1. Purpose and the user

**Who:** a Risk Officer or CRO (`holiday.manage`) who keeps the bank's list of non-working days. Visits a few times a year, usually in January and when a movable holiday (Eid) is announced.

**First ten seconds:** is the year's list complete? and *what does the list do to the cycle*: the first working day of each month, which is the day the request goes out.

**Must be able to:** add a holiday, remove one, switch year, and see the effect on the twelve first-working-days.

## 2. Props used (ADR §16)

`{year, years: int[], holidays: [{id, date, name, weekday}], firstWorkingDays: [{month, date}]}`

Add body: the ADR names `StoreOrganizationHolidayRequest` but not its fields; this spec assumes `{holiday_date (Y-m-d), name (max 150)}` per the table. Errors are expected under those keys.

## 3. Layout

`PageHeader`: title **Organisation Holidays**; subtitle "Days that are not working days for KRI returns"; breadcrumbs **KRI Monitoring** → **Holidays**. (Nav placement: see section 10.)

`max-w-6xl`, `grid grid-cols-1 lg:grid-cols-3 gap-6`.

### 3.1 Left, two columns: the list
**Rules strip** (`role="note"`, `bg-blue-50`): "Saturdays and Sundays are never working days, so you don't need to add them. Add public holidays and any other days the bank is closed. Dates move each year (Eid, for example), so enter each year's dates when they're announced."

**Year switcher.** A `<nav aria-label="Year">` of links, one per `years` entry (current `aria-current="page"`), plus the heading "Holidays in {year}". Links go to `?year=`.

**Add form** (a card above the table):
- **Date** (`type="date"`, required; label "Date"). Native control; on browsers without date support it degrades to a text box with the hint "YYYY-MM-DD".
- **Name** (text, required, `maxlength=150`; label "Name"; example placeholder "Eid al-Fitr", help "Shown in this list only").
- **Add holiday** button (`btn-primary`).
- Client-side non-blocking notes under the date: if the chosen date is a Saturday or Sunday (computed from the date string with `Date.UTC`): "That's a weekend. Adding it changes nothing." If the date's year differs from the year being viewed: "This falls in {year}; it will appear under that year." (After add, the page stays on the viewed year; the date is saved under its own year and a `role="status"` line confirms "Added {name}, {date}, under {year}.")

**Table** `data-table`, caption "Holidays in {year}": **Date** ("Mon 4 May 2026") · **Day** (`weekday` prop) · **Name** · **Remove** (text button "Remove", accessible name "Remove {name}, {date}"). Ordered by date ascending (server order; client sorts by `date` ascending to be safe).

### 3.2 Right, one column: effect on the cycle
Card titled **First working day of each month** with sentence "These are the days the monthly KRI request opens, given the holidays listed." A 12-row list from `firstWorkingDays`: month name · date with weekday ("Mon 2 Nov 2026") · a small tag "moved by a holiday" is **not** shown (the props don't say which were moved, and inferring it would need a second calculation). The list is read straight from `firstWorkingDays`.
Footer note: "Changing the list never moves a return that is already open. Each return's due date was fixed when its request went out."

(This footer follows ADR §4: `due_on` is stored on the return at open time.)

## 4. States

| State | Behaviour |
|---|---|
| **Loading** | Inertia progress. |
| **Empty list for the year** | In the table area: `EmptyState` "No holidays recorded for {year}". Description: "Every weekday is being treated as a working day. Add the bank's holidays so the monthly request opens on the right day." This is shown for any year with an empty `holidays`, whatever the year. |
| **Empty and current/future year** | Same state, plus an amber `role="note"` above the cycle card: "With no holidays listed, the first working day of each month is simply the first weekday." |
| **Normal** | list and cycle card. |
| **Add: validation (422)** | Errors under the field (`holiday_date` duplicate: "That date is already in the list."; name required/too long). `aria-invalid`, ids, focus moves to the first invalid field. The form keeps its values. |
| **Add: save failure (5xx / network)** | `role="alert"` above the form: "Couldn't save. Nothing was added. Try again." Values kept. After a network error the page first reloads `only: ['holidays', 'firstWorkingDays']` and, if the holiday is now present, shows the success status instead (the save had landed). |
| **Expired session (419)** | Common modal; the typed values stay in the form; Retry re-posts. |
| **Remove: confirm** | `ConfirmDialog variant="warning"`: title "Remove {name}?"; message "{date} becomes a working day again for future first-working-day checks and due dates. Returns that are already open keep their due date. The removal is recorded in the audit trail." Buttons **Cancel** (focused) / **Remove**. |
| **Remove: failure** | Page-level `role="alert"` with Try again. A 404 (already removed): "That holiday was already removed." + reload. |
| **Permission denied** | 403 page. |
| **Year with no entry in `years`** | The server decides `years`; the page shows exactly those. |
| **Not configured / overdue / submitted / reopened** | not applicable. |

## 5. Interactions
1. **Switch year:** link; preserves nothing else.
2. **Add:** submit via button or Enter in either field; `preserveScroll`; on success the form clears, focus returns to the Date field, and the status line announces. The new row appears in date order.
3. **Remove:** confirm then delete (hard delete). Irreversible except by re-adding; stated in the dialog. After removal, focus moves to the Add form's Date field (the row that held focus is gone) and a status line announces "Removed {name}".
4. Unsaved-state warning: if either field has content and the user follows a year link, `window.confirm`-style prompt is **not** used; use the `ConfirmDialog` "Discard the holiday you were adding?" (precedent `RcsaUniverse/AddRiskPanel.jsx` uses `confirm`; the dialog is preferred for consistency with this product's modals).

## 6. Accessibility
- Form: labels bound with `for`; the weekend/year notes are `aria-describedby` of the date input; errors have ids referenced from the input.
- Table: `caption`, `scope="col"`, first cell `th scope="row"` with the date.
- The cycle list is a `<table>` with caption "First working day of each month in {year}" (two columns: Month, Date), not a visual-only list.
- Year `nav` has a label and `aria-current`.
- Status messages: one persistent `role="status"` region at the top of the main column; `role="alert"` for failures.
- Contrast: all text in 00-common palette; amber note `text-amber-900` on `bg-amber-50`.
- Keyboard: date input is native, so its own keyboard and picker apply; the remove button for each row has a unique accessible name.

## 7. Mobile
Single column: rules strip, year switcher (wraps), add form (fields stacked, button full width 44 px), list as cards (date and day on the first line, name, Remove button full width), cycle card last. The native date picker opens the OS control.

## 8. Low bandwidth
Tiny page (at most about 40 holidays and 12 dates, a few KB). No date-picker library; native `type="date"`. The add/remove requests are small; each has the common "Saving…" and 15-second slow-link line. The page never reloads the whole list after an add except through Inertia's redirect response (which returns the same small props).

## 9. The numbers on the screen

| Figure | Source | Absent |
|---|---|---|
| Holidays list | `holidays` (`organization_holidays` for the year) | The "No holidays recorded" state, not a zero |
| Weekday | `holidays[].weekday` | |
| First working day per month | `firstWorkingDays` (`KriWorkingCalendar::firstWorkingDayOf` for each month) | Cycle card hidden if the array is empty |
| Weekend note | Weekday of the typed date, computed client-side from the date string | Not shown for a blank date |

## 10. Known gaps and assumptions
1. **Add field names** assumed `holiday_date` and `name` (section 2).
2. **Navigation:** ADR §15 puts "Holidays" in the "KRI Monitoring" group, but the route is `/risk/settings/holidays`, which `NavPresenter`'s `'prefixes' => ['/risk/kri']` for that group does not match, so the group would not open or highlight on this page. Backend/nav owner either adds the prefix or moves the entry. Not a spec decision.
3. `firstWorkingDays` carries no "moved by a holiday" indicator, so the cycle card can't point out which months a holiday changed. Recommended addition: a boolean per month.
4. Whether a holiday on a weekend is rejected by the server is unspecified; the page warns but doesn't block.
