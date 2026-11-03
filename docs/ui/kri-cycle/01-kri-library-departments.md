# 01. KRI Library: department landing

**Page:** `resources/js/Pages/Kri/Index.jsx` · **Route:** `GET /risk/kri` (`risk.kri.index`, `permission:kri.view`) · **Layout prop:** `layout: 'departments'` (the default) or `'flat'` · Read `00-common.md` first.

## 1. Purpose and the user

**Who:** a Risk Officer, Risk Analyst or CRO (all hold `kri.view` and `kri.all_departments`), or a first-line user with `kri.view` limited to the business units they are assigned to (ADR §10). Opened from the nav ("KRI Library") or from a bookmark that used to be the flat grid.

**In the first ten seconds** they must see: which departments have KRIs in the red, which departments' returns are overdue, and how to open one. They are scanning for trouble across 20 to 60 departments, not reading.

**What they must be able to do:** open a department; switch to the flat list that this page used to be; find a department by name.

## 2. Props used (ADR §16, `layout: 'departments'`)

`{layout, period: PeriodOpt|null, departments: [{id, code, name, parent_id, kri_count, red, amber, green, unbanded, return: ReturnRef|null, open_kri_issues, overdue_kri_issues, url}], unassigned: {kri_count, red, amber, green, unbanded, url}, scopeNote: string|null, flatUrl}`

The same `Index` component also renders `layout: 'flat'` (today's `total`, `activeBreachCount`, `grid`), so it branches on `layout` at the top. The flat branch changes only as set out in section 5.5.

## 3. Layout

### 3.1 Above the fold (1440 by 900)
1. `PageHeader`, title **Key Risk Indicators Library** (unchanged). Subtitle: `{N} departments · {M} indicators` where N is `departments.length` and M is the sum of every `kri_count` plus `unassigned.kri_count`. Both are sums of named props. (The flat layout's "active breaches" figure is not in these props, so it is not shown here.) Actions are unchanged: **New KRI** (`kri.create`), **Manage Thresholds**.
2. `scopeNote`, when present, directly under the header: an info strip (`bg-blue-50 border-blue-100 rounded-lg px-4 py-3 text-sm text-gray-700`, `role="note"`), rendered verbatim.
3. **Toolbar** (one row at `md` and up):
   - Left: **layout switch**, a `<nav aria-label="Library layout">` holding two links styled as a segmented control: **By department** (current, `aria-current="page"`) and **All KRIs (flat list)** (`flatUrl`). Links, not buttons or tabs, because each is a navigation to a different URL.
   - Middle: **Find a department** search input (`type="search"`, label visible: "Find a department"). Filters client-side on name and code, case-insensitive, substring.
   - Right: **Sort** `<select>` (label "Sort by"): *Name (A to Z)* (default), *Most red first*, *Most overdue first*. Client-side, stable; ties break by name. "Most overdue first" sorts by `return.days_overdue` descending, with `null` last.
   - Period line under the toolbar when `period` is set: "Return status shown for **{period.label}**" with a text link to `risk.kri.dashboard`. It names the month so a tile's status is never read as the current month's.
4. **Tile grid.**

**URL state warning.** Search and sort are **component state only** (use Inertia `useRemember` if state should survive Back). They must **not** be written to the URL as `search`, `sort` or `direction`: ADR §15 treats any of those query parameters as grid state and renders the **flat** layout. Writing them would silently switch the page.

### 3.2 Tile grid
`<ul>` of `<li>` tiles. Grid: `grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4`. Departments in the chosen sort order; the **Unassigned tile is always last**, outside the sort, and **is not hidden by the search filter** (it is not a department, and hiding it would hide KRIs).

**Department tile** (`bg-white rounded-xl border border-gray-200 p-4`, hover `shadow-md`):

| Row | Content | Source |
|---|---|---|
| Title | Department name as an `h2` containing the only link (`url`). The link's `::after` is stretched over the tile so the whole tile is the click target and the tile has **one** tab stop. Focus ring on the whole tile via `focus-within`. | `name`, `url` |
| Caption | `code` (mono, `text-xs text-gray-600`). When `parent_id` matches another listed department: "Part of {parent name}". If the parent is not in the list, no caption (never show an id). | `code`, `parent_id`, `departments` |
| Return | Status badge (00-common section 2) with the due date: "Due Mon 9 Nov". If overdue: "{N} days overdue". When `return` is null: "No return for {period.label}" with an info glyph and an inline `title`/visually-hidden sentence "A return opens only for departments with KRIs due that month." | `return.display_status`, `return.due_on`, `return.days_overdue`, `period.label` |
| KRIs | "**{kri_count}** KRIs" | `kri_count` |
| Bands | Four counts as text pairs: "Red 2 · Amber 1 · Green 7". "Not banded 1" appears **only** when `unbanded > 0` (hover/help text: "No thresholds in force, or no reading yet."). A stacked bar (height 8px, `rounded`, `aria-hidden`) shows the proportions: widths are `count / kri_count`; segments use the Dashboard's `#C53030` red, `#D4AF37` amber, `#2D7D46` green and `#E2E8F0` for not banded. The bar is decoration; the text above carries the numbers. | `red`, `amber`, `green`, `unbanded` |
| Issues | "{open_kri_issues} open KRI issues" with, when `overdue_kri_issues > 0`, " · **{overdue_kri_issues} overdue**" in `text-red-700 font-semibold` with a warning icon. With `open_kri_issues = 0`: "No open KRI issues". | `open_kri_issues`, `overdue_kri_issues` |

Invariant the implementer can assert in development: `red + amber + green + unbanded = kri_count`. If a payload violates it, render the counts as sent, do not "fix" them.

A tile whose department has **`kri_count = 0`**: muted (`bg-gray-50`), bands and bar replaced by "No KRIs assigned yet." No return badge (a department with no due KRIs has no return by definition). The tile still links to its detail page (ADR §0.6: such departments appear in the Library).

**Unassigned tile.** Dashed border (`border-dashed border-gray-300`), title "Unassigned", caption "KRIs with no department". Same counts as a department tile (from `unassigned`). It never shows a return badge. A fixed line: "Not counted on the submission dashboard." With `unassigned.kri_count = 0`: "Every KRI has a department." (a true, useful statement) and the tile is still shown, muted, still linking to `unassigned.url`.

## 4. States

| State | Trigger | What the page shows |
|---|---|---|
| **Loading** | Inertia visit | Progress bar only (props are synchronous). No skeleton needed. |
| **Empty: nothing at all** | `departments` empty and `unassigned.kri_count = 0` | `EmptyState`: title "No departments or KRIs yet". Description: "Departments come from your organisation's business units. KRIs are added with New KRI and given a department on the form." Action **New KRI** if `kri.create`. |
| **Empty: no departments in scope** | `departments` empty, `unassigned.kri_count > 0` | A `role="note"` strip: "No departments are in your scope. You can still see KRIs that have no department." (+ `scopeNote` if sent). The Unassigned tile renders. |
| **Departments exist, none has KRIs** | `departments.length > 0` and every `kri_count = 0` and `unassigned.kri_count` any | Strip above the grid: "No KRI is assigned to a department yet. Open a KRI, choose **Edit**, and set its Department." |
| **No search match** | filter hides every department | `role="status"` line in place of the grid (the Unassigned tile stays): "No department matches '{text}'." + **Clear search** button. |
| **Not configured** | `period` is null | Strip: "The monthly KRI cycle hasn't opened yet. Return status will appear here after the first request goes out on the first working day of a month." Tiles render without return rows. |
| **Overdue / submitted / reopened** | `return.display_status` | The badge on the tile; nothing else changes. |
| **Partial** | `return` null for some tiles | Per-tile "No return for {period.label}". |
| **Error** | Section 4 of `00-common.md` | |
| **Permission denied** | no `kri.view` | 403 page (no nav entry). A user with `kri.view` whose scope is empty gets the "no departments in scope" state, not a 403. |
| **Validation error / save failure / expired session** | not applicable | This page has no form and no write. A 419 cannot occur. |
| **Degraded network** | slow link | Section 7. |

Colour pairs on tiles are the 00-common palette; "overdue" is also words and an icon.

## 5. Interactions

1. **Open a department:** click or Enter/Space on the tile title link → `url` (`/risk/kri/departments/{id}` or `/risk/kri/departments/unassigned`). Standard Inertia link.
2. **Search:** typing filters instantly. After 400 ms of idle, a visually hidden `role="status"` region announces "{n} of {N} departments shown". `Escape` in the field clears it.
3. **Sort:** `<select>`; change re-orders immediately and announces "Sorted by {option}".
4. **Layout switch:** navigation links. Switching to flat drops no state the flat grid owns.
5. **Nothing here is destructive or irreversible.**

### 5.5 The flat layout (`layout: 'flat'`)
Unchanged apart from three additions, so existing bookmarks keep working:
- The same **layout switch** nav above the grid, with **All KRIs (flat list)** current. **By department** links to the bare `risk.kri.index` (the departments layout, since a bare URL carries no grid state). Following it intentionally drops grid state.
- `KrisGrid` gains a **Department** column (rendered by `DataGrid` from the grid definition) and a **Department** filter whose options include "Unassigned". Both come from the grid definition (`app/Grids/Definitions/KrisGrid.php`) per ADR §15, not from this page.
- The subtitle stays `{total} indicators configured · {activeBreachCount} active breaches`.

## 6. Accessibility (WCAG 2.1 AA)

- **Landmarks and headings:** `h1` page title; each tile title is an `h2` (the list is `<ul>` so a screen reader announces "list, 24 items"). The toolbar is `<div role="search">` around the search field.
- **Tab order:** header actions → layout switch → search → sort → tiles in visual order (one stop each) → Unassigned tile.
- **Stretched link:** the link text is the department name only. Counts and status are in the tile's text, reachable in browse mode. To make them part of the link's name for tab-through users, give the link `aria-describedby` pointing at the tile's summary line id, which reads e.g. "42 KRIs, red 2, amber 1, green 7, return overdue by 3 days, 1 overdue KRI issue". That sentence is generated from the props, not authored.
- **Not colour alone:** every colour signal has words; the bar is `aria-hidden`.
- **Contrast:** secondary text `text-gray-600`/`-500` (00-common section 3). "Not banded" bar segment `#E2E8F0` against a white card is decoration, text carries the count.
- **Switch control:** `aria-current="page"` on the active link; focus ring visible.
- **Search results announcement:** polite, debounced; not announced per keystroke.

## 7. Mobile and low bandwidth

**Mobile (below `sm`, 320 to 639 px):** one column. Toolbar stacks in order switch, search, sort, each full width, 44 px tall. Tile padding `p-4`; counts wrap onto two lines ("Red 2 · Amber 1" / "Green 7"). The bar is full width. The Unassigned tile is last, as on desktop. No horizontal scroll.

**100 kbps:**
- Payload is about 0.4 KB per department, so 60 departments is about 25 KB, roughly 2 s. No deferral is needed and none is added.
- No images, no extra font, no chart. The bar is CSS.
- While navigating, the previous page stays up with the Inertia progress bar.
- The icon font can fail; icons are decorative and boxed (00-common section 5), so the layout holds.

## 8. The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| Departments, indicators in subtitle | Count and sum of `departments[].kri_count` + `unassigned.kri_count` (server-grouped counts of active, non-deleted KRIs) | Subtitle omits the figure if `departments` is empty |
| Red / Amber / Green / Not banded per tile | `key_risk_indicators.current_status`, grouped (ADR §16) | Not shown for a tile with `kri_count = 0` |
| Return status, due date, days overdue | `kri_returns` via `ReturnRef` | "No return for {period.label}" |
| Open and overdue KRI issues | `open_kri_issues`, `overdue_kri_issues` (Issues graph edge) | "No open KRI issues" only when the sent value is `0` |

## 9. Known gaps (also in the handoff)

1. **Green may include never-read KRIs.** `Kri/Show.jsx` documents that `current_status` defaults to green on create, so a KRI nobody has read counts as green on a tile. Recommended: the grouped query should place KRIs with no reading in `unbanded`. Until then, the label "Green" can overstate health. (Backend.)
2. No `activeBreachCount` in the departments props, so the subtitle drops it.
3. Parent name is only resolvable when the parent is also listed; no `parent_name` prop.
