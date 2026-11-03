# 02. KRI Library: department detail

**Page:** new `resources/js/Pages/Kri/Department.jsx` · **Routes:** `GET /risk/kri/departments/{department}` (`risk.kri.departments.show`) and `GET /risk/kri/departments/unassigned` (`risk.kri.departments.unassigned`), both `permission:kri.view` · Read `00-common.md` first.

## 1. Purpose and the user

**Who:** the same people as 01, arriving from a tile. A Risk Officer tracking KRIs one department at a time before a review.

**First ten seconds:** which of this department's KRIs are red, whether its return is on time, who the champion is (or that nobody is), and whether any KRI-raised Issue is overdue.

**Can do:** work the KRI grid (filters, links, as today); open the department's return; jump to the contacts settings when the department has no champion.

## 2. Props used (ADR §16)

`{department: {id, code, name, parent: {id,name}|null}|null, summary: {kri_count, red, amber, green, unbanded}, return: ReturnRef|null, openKriIssues: [{id, reference, title, status, due_date, is_overdue, url}], contacts: {risk_champion: Contact[], team_lead: Contact[], unit_head: Contact[], group_head: Contact[]}, grid}` with `Contact = {id, name, email, is_active}`. `department: null` is the **Unassigned** variant.

## 3. Layout

`PageHeader`:
- Title: `department.name`; for Unassigned, **Unassigned KRIs**.
- Subtitle: `{code}` and, when `parent` is set, " · part of {parent.name}". Unassigned: "KRIs with no department".
- Breadcrumbs: **KRI Monitoring** (→ `risk.kri.index`), **Key Risk Indicators Library** (→ `risk.kri.index`, the departments view), then the department name (current, no link). The existing pages use "KRI Monitoring" as the first crumb pointing at the index; the extra crumb is what makes the "breadcrumb leads to the department detail" requirement real.
- Actions: **New KRI** (`kri.create`) and a text link **View all KRIs in a flat list** → `risk.kri.index?layout=flat`.

Top to bottom:

### 3.1 Summary row
A `grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4` of `KpiCard`: **KRIs** (`kri_count`), **Red**, **Amber**, **Green**, and **Not banded** (only if `unbanded > 0`). Per `KpiCard`'s rule the status goes in the `subtitle` as words ("Needs an explanation and an action") and the number stays plain. When `kri_count = 0` the whole row is replaced by the text "No KRIs are assigned to this department." (see states).

### 3.2 Two cards side by side (`lg:grid-cols-3`, return card 1 col, contacts card 2 cols; stacked below `lg`)

**Return card** (`department` set only). Heading "Monthly return". Content:
- Status badge (`return.display_status`), due date "Due Mon 9 Nov 2026", "{N} days overdue" when `days_overdue` is set.
- Link **View return** → `return.url` (`risk.kri.returns.show`, read-only).
- When `return` is null: "No return is open for this department. A return opens on the first working day of a month when the department has KRIs due." No link.

**Contacts card** (`department` set only). Heading "KRI contacts". Four rows, one per role in ladder order: Risk champions, Team leads, Unit heads, Group heads. Each row is a `dl` pair: role (`dt`), names (`dd`) as a comma list; each name carries its email in `title` and, when `is_active` is false, the words "(account inactive)" in `text-amber-800` with an icon. A role with no one: "None set" in `text-gray-600`.
- **No risk champion** (empty `risk_champion`): the row is replaced by an amber callout (`bg-amber-50 border-amber-200 text-amber-900`, warning icon, `role="note"`): "No risk champion is set. The monthly request has nobody to go to." Link **Set contacts** → `risk.kri.contacts.index`, rendered only when the user holds `kri.manage_department_contacts` (from `auth.permissions`; a link that 403s must not appear).
- A role whose names are *all* inactive is shown with the inactive marker per name, plus the line "No active person in this role."

### 3.3 Open KRI Issues card (`openKriIssues`)
Heading "Open KRI issues ({openKriIssues.length})". A list (not a table, at most a handful), each item: reference as a link (`url`, mono), title, status badge, "Due Mon 9 Nov 2026", and an **Overdue** badge (`bg-red-100 text-red-800`, with icon) when `is_overdue`. Items ordered by the server; the overdue ones are not re-sorted so the order matches the Issues module. Empty: "No open KRI issues for this department." (A true statement from an empty sent list.)

### 3.4 The KRI grid
`<DataGrid grid={grid} />`, unchanged shell. The server locks the Department filter (ADR §16); the page shows that lock by rendering the active filter chip as read-only if the grid exposes it, otherwise no UI is added and the page header already names the department. Columns are `KrisGrid`'s, including the new Department column (redundant here, hidden by the grid definition when the filter is locked is acceptable; the implementer follows the grid's lead).

## 4. States

| State | Behaviour |
|---|---|
| **Loading** | Inertia progress; props are synchronous. |
| **Department with no KRIs** | Summary row replaced by `EmptyState`-style block: title "No KRIs are assigned to {name}". Description: "Assign a KRI to this department from the KRI's Edit form (Department field). Until then this department has no monthly return." The grid area is hidden; the Return card shows "No return is open…". Contacts card still shows (so the champion can be set ahead of time). |
| **Grid has rows but the filter yields none** | The grid's own empty state. |
| **No champion** | Callout in 3.2. |
| **Contact inactive** | Marker in 3.2. |
| **Return overdue / submitted / reopened** | Badge in the Return card, same vocabulary. |
| **Not configured** | `return` null *and* no cycle ever run cannot be told from this page's props; the "No return is open" sentence covers both and is deliberately neutral. |
| **Unassigned variant** (`department: null`) | No Return card, no Contacts card. In their place one full-width explanation card: "These KRIs have no department. They are not part of any monthly return and are not counted on the submission dashboard. To give one a department, open it and choose **Edit**, then set **Department**." Summary row and the Issues card still render. The Issues card may be empty. |
| **Error / session / network** | `00-common.md` section 4. No forms here, so no 419. |
| **Permission denied** | In-tenant but out of scope: the 403 page (ADR §10). The Unassigned page is visible to every `kri.view` holder. |
| **Validation error / save failure** | not applicable (read-only; the grid's own actions follow the grid). |

## 5. Interactions
- Grid filtering and sorting: as `DataGrid` does today; these are grid-state query parameters on this same URL, which is fine (the departments/flat split concerns only `risk.kri.index`).
- **View return**, **Set contacts**, issue links: plain navigation. Issue links open the Issue; nothing here edits it.
- Nothing destructive.

## 6. Accessibility
- Headings: `h1` title; cards use `h2`; grid keeps its own structure.
- The summary `KpiCard`s are a labelled group: `role="group" aria-label="KRI summary for {name}"`.
- The contacts `dl` is real `dl/dt/dd`. The inactive marker is text plus icon, not only amber colour.
- The overdue badge is text. The callout uses `role="note"`, not `alert` (it is page content, not a live event).
- Breadcrumbs: the existing `PageHeader` breadcrumb component (`nav aria-label="Breadcrumb"`, `aria-current` on the last item).
- Focus order: header actions → return link → contacts action → issue links → grid.

## 7. Mobile and low bandwidth
**Mobile:** cards stack in the order summary, return, contacts, issues, grid. `KpiCard` grid is two columns. Contacts `dl` stacks term over value. The grid follows `DataGrid`'s responsive behaviour (horizontal scroll inside its container).

**100 kbps:** the page payload is the grid's page (rows paged by the grid) plus small card data. The grid is server-paged, so payload does not grow with the register. Nothing is deferred; the order of paint is header, cards, grid. No images or charts.

## 8. The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| KRIs, Red, Amber, Green, Not banded | `summary` (grouped counts of `current_status`) | Row replaced by the "no KRIs" message when `kri_count = 0` |
| Return status, due date, days overdue | `return` (`ReturnRef`) | "No return is open…" |
| Contacts | `contacts.*` (`kri_department_contacts`) | "None set" per role |
| Open KRI issues | `openKriIssues` | "No open KRI issues…" |
| Overdue flag | `openKriIssues[].is_overdue` | |

## 9. Known gaps
1. Same "Green may include never-read KRIs" caveat as 01 (summary uses the same counts).
2. `Contact` carries no directory data; unlike screen 05, this page cannot flag a champion who has left the department. Intentional: it is a read view.
3. For the Unassigned variant the ADR does not say whether `contacts` is `null` or four empty arrays; the page must treat both as "no contacts card".
