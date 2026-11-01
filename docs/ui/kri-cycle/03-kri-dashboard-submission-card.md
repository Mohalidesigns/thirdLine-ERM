# 03. KRI Monitoring Dashboard: monthly submissions card

**Page:** `resources/js/Pages/Kri/Dashboard.jsx` (existing; one card added) · **Route:** `GET /risk/kri/dashboard` (`risk.kri.dashboard`, permission unchanged) · Read `00-common.md` first.

## 1. Purpose and the user

**Who:** a Risk Officer or the CRO who opens the dashboard each morning, and on the days after a month's request goes out wants one answer: *has every department submitted?*

**In the first ten seconds:** "Submitted X of Y departments" and "Not submitted Z" without scrolling, and one click to the list of those Z departments.

## 2. Props used (ADR §16)

Lazy prop `returnsCard: {period: PeriodOpt|null, periods: PeriodOpt[] (cycle runs only, newest first, max 12), summary: ReturnSummary, cycleOpened: bool, drillDownUrl, canRemind}` with `ReturnSummary = {denominator, submitted, not_submitted, overdue, no_champion}`. `?period={id}` selects a month. Partial reload: `only: ['returnsCard']`. The existing props (`kpis`, `statusDistribution`, `breachTrend`, `breaching`, `trafficLights`, `canCreate`) are untouched.

## 3. Layout

### 3.1 Placement
Directly under the existing six-`KpiCard` row and above "KRI Traffic Light Overview": a full-width card with `mb-6`. At 1440 by 900 it sits above the fold (header about 100 px, KPI row about 110 px, this card about 190 px). It does not push the existing content into a different order.

### 3.2 Card anatomy
`<section aria-labelledby="returns-card-title" class="bg-white rounded-xl border border-gray-200 p-5 mb-6">`

Header row (`flex flex-wrap items-center justify-between gap-3`):
- `h2#returns-card-title` (existing card-title style): **Monthly KRI returns: {period.label}**
- **Period selector:** a native `<select>` with a visible label "Month" (`options` from `periods`, each `label`; the current `period.id` selected). Native select is deliberate: lightest on a slow link, correct on mobile.

Body (desktop `grid grid-cols-1 md:grid-cols-12 gap-6 items-center`):
- **Left (`md:col-span-5`):** the headline sentence as one block: `Submitted **{submitted}** of **{denominator}** departments` (`text-2xl`, numbers `font-bold`). Under it a progress bar (8 px, `rounded`, `aria-hidden`): fill `#2D7D46` at `submitted / denominator` width, track `#E5E7EB`. The percentage is not printed; the sentence is the figure.
- **Middle (`md:col-span-4`):** three secondary figures as a `dl`: **Not submitted** (`not_submitted`, the largest, `text-xl font-bold`), **Overdue** (`overdue`, `text-red-700` with warning icon when `> 0`), **No risk champion** (`no_champion`, amber icon when `> 0`, with the help line "Nobody to email for these departments.").
- **Right (`md:col-span-3`, right aligned):** primary button **See departments not submitted** → `drillDownUrl` (a link styled `btn-primary`; carries `?period=` already). When `canRemind` is true, the button text is **Review and send reminders**, same URL. When `not_submitted = 0` the button becomes the secondary **View all departments** → `drillDownUrl` (the drill-down defaults to the not-submitted filter; the empty state there explains and links the other filters).

Invariants: `submitted + not_submitted = denominator`; `overdue <= not_submitted`; `no_champion <= denominator`. Rendered as sent, never "corrected".

### 3.3 Past month banner
When the selected `period.id` is not `periods[0].id`: a one-line `role="note"` strip inside the card above the body: "You are looking at a past month. Figures show where each return stands **now**, not where it stood at month end." (True: `kri_returns.status` is current, and a return reopened later counts as not submitted.)

## 4. States

| State | Trigger | Behaviour |
|---|---|---|
| **Loading** | Lazy prop not yet arrived | The card renders immediately with its final dimensions: title, selector disabled, and grey skeleton blocks (`bg-gray-100 animate-pulse rounded`) where the sentence and figures go. `aria-busy="true"` on the section, and a visually hidden "Loading submission figures". No `0` placeholders. |
| **Slow** | no response after 15 s | Under the skeleton, `role="status"`: "Still loading. Your connection is slow. The rest of the dashboard is ready." |
| **Error** | partial reload fails (network, 5xx) | Inside the card: `role="alert"` "Couldn't load the submission figures." + **Try again** button (repeats `only: ['returnsCard']`). The remainder of the dashboard is unaffected. |
| **Not configured** | `cycleOpened = false` or `period = null` | No numbers. Message: "No monthly KRI cycle has opened yet. The first request goes out on the first working day of the month." For users holding `holiday.manage`, add the link "Check the holiday list" → `risk.holidays.index`. Selector hidden when `periods` is empty. |
| **Cycle opened, nothing to count** | `cycleOpened` true and `denominator = 0` | "There are no returns to show for {period.label}. Either no department had KRIs due, or none are in your scope." No bar, no figures, selector stays. |
| **Normal** | | Section 3.2. |
| **All submitted** | `not_submitted = 0` and `denominator > 0` | Headline plus a Forest check icon and the text "Every department has submitted." Button per 3.2. |
| **Overdue** | `overdue > 0` | The Overdue figure in `text-red-700` with warning icon and the word "Overdue". |
| **Gaps** | `no_champion > 0` | The amber No risk champion line with the help text. |
| **Permission denied** | no `kri.send_reminder` | `canRemind` false: the button says **See departments not submitted**; there is no hidden or disabled "remind" control. |
| **Validation / save failure / expired session** | not applicable | Read-only card. The period change is a GET. |

## 5. Interactions
1. **Change month:** `select` `onChange` → partial reload `router.get(currentUrl, {period: id}, {only: ['returnsCard'], preserveScroll: true, preserveState: true, replace: true})`. While in flight the body dims and shows the skeleton; the selector keeps focus and stays enabled (a second change cancels the first). The URL gains `?period=` so Back and refresh keep the choice.
2. **Open the drill-down:** link navigation, a full Inertia visit.
3. Keyboard: Tab to the selector, arrow keys to change (native). With a native select, Inertia fires on `change`, which for keyboard arrows is on each arrow press in some browsers; to avoid request storms on a slow link the handler debounces 300 ms and only fires for the last value.
4. Not destructive.

## 6. Accessibility
- **Landmark:** `section` with `aria-labelledby`. The headline is plain text, announced in reading order: "Submitted 8 of 10 departments."
- **Live announcement on month change:** after the reload resolves, a persistent visually hidden `role="status"` region (mounted with the card, so announcements work) says: "{period.label}: {submitted} of {denominator} departments submitted, {not_submitted} not submitted." Focus does not move. When the card enters the error state, the `role="alert"` message announces itself.
- **Selector** has a visible `<label for>`.
- **Not colour alone:** overdue and gap states have icon and words. The bar is `aria-hidden`; the sentence is the accessible content.
- **Contrast:** figures `text-[var(--color-text-primary)]`; red-700 text on white about 6:1; amber text uses `text-amber-800`, never Gold; Forest fill on `#E5E7EB` track is decoration with the sentence as the real content.
- Focus order: selector → drill-down button. The skeleton blocks are not focusable.

## 7. Mobile and low bandwidth
**Mobile:** the three columns stack: headline, then the `dl` as a two-column grid of three items wrapping, then a full-width 44 px button. Selector full width under the title.

**100 kbps:** the card's data is a lazy prop for exactly this reason: the existing dashboard payload (KPIs, traffic-light tiles, breaching table) must not wait for the returns query. The page renders at once; on mount a single partial reload fetches `returnsCard` (a few hundred bytes). The skeleton has the card's final height so nothing shifts. A period change fetches only that prop. Nothing polls; a **Refresh** is not offered because the figures only change when someone submits, and a stale-looking number is corrected by changing month or reloading.

## 8. The numbers on the screen

| Figure | Source | When absent |
|---|---|---|
| Submitted X / Y departments | `summary.submitted` / `summary.denominator` (the set of `kri_returns` rows for the period in the user's scope; only `status = submitted` counts) | The "not configured" or "nothing to count" message, never `0 of 0` |
| Not submitted | `summary.not_submitted` | as above |
| Overdue | `summary.overdue` (not submitted, Lagos today after `due_on`) | as above |
| No risk champion | `summary.no_champion` | as above |
| Month list | `periods` | selector hidden |

## 9. Known gaps
1. `returnsCard` has no `scopeNote`, so a scoped user cannot be told "figures cover your departments only". The "nothing to count" copy hedges; a `scopeNote` on the card is recommended.
2. How a lazy prop is requested on mount (Inertia `Deferred` vs a manual `router.reload`) depends on the Inertia version in `package.json`. The behaviour above is required either way.
