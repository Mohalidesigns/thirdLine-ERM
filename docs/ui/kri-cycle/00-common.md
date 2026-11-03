# Departmental KRI cycle: common rules for every screen spec

**Status:** spec, not built · **Source of truth:** `docs/adr/0026-the-departmental-kri-cycle-a-draft-is-not-a-reading.md` (frozen; §15 routes, §16 page props) · **Brief:** `plans/kri/KRI-DEPARTMENTAL-CYCLE-PROMPT.md`

Ten screen specs sit beside this file (`01-` to `10-`). This file holds what they share, so it is
written once. Where a screen spec disagrees with this file, the screen spec wins for that screen.

## 0. How to read these specs

- **Page props are the ADR's, exactly.** No spec adds, renames or reshapes a page prop. Where a
  screen needs something the ADR lacks, the spec says what it does without it and logs the gap
  in the spec's last section. All gaps are collected in the handoff.
- **Component props written in these specs (for example `RedBandSection`'s) are proposals for
  `frontend-engineer`.** They are front-end internals, not contracts. Rename freely if the
  behaviour is kept.
- **No application code.** Class names quoted from existing pages (`btn-primary`, `data-table`,
  `badge`) are references to what already exists.
- Existing files read before specifying: `resources/js/Pages/Kri/{Index,Dashboard,Show,Edit,KriForm}.jsx`,
  `packages/ui/src/Components/{ConfirmDialog,Modal,EmptyState,KpiCard}.jsx`,
  `resources/js/Pages/Bcms/Identity/Review.jsx` (bulk-select precedent),
  `resources/js/Pages/Tprm/Settings/Ai.jsx` (`beforeunload` precedent), `docs/ui/thirdline-internal-audit-parity.md`.

## 1. Shells and primitives to reuse

| Need | Use | Note |
|---|---|---|
| Page frame | `AuthenticatedLayout` + `PageHeader` (title, subtitle, breadcrumbs, actions) | As `Kri/Dashboard.jsx` |
| Stat tile | `KpiCard` (status goes in `subtitle` as words, never by recolouring the number; absence is `unavailable`, never `0`) | |
| List of KRIs | `DataGrid` with `KrisGrid` | Screen 02 only |
| Confirmation | `ConfirmDialog` (single string message) or `Modal` (when the body needs a list) | Both are Headless UI `Dialog`, which traps focus and restores it |
| Empty state | `EmptyState` | Extended with the "what to do" line in every spec |
| Cards | `bg-white rounded-xl border border-gray-200`, header `px-5 py-4 border-b border-gray-100`, title `text-sm font-semibold text-[#1A365D]` | As Dashboard/Show |
| Tables | `data-table` inside `overflow-x-auto`, `<caption class="sr-only">`, `scope="col"` | As `Bcms/Identity/Review.jsx` |
| Inline errors | `InputError` from `@thirdline/ui` | See section 6 for the wiring it needs |
| Charts | None. Bars are CSS or inline SVG. Do not import `chart.js` | Standard §8 / ADR 0007 |

**Bespoke surfaces, justified once:**
1. `Kri/Returns/Index` (04) is a bespoke table, not `DataGrid`: ADR §16 gives it plain `rows` with
   nested `notices`, not a `grid` config, and it needs row checkboxes plus an expandable history.
   Precedent: `Bcms/Identity/Review.jsx`.
2. The reminder confirmation (04) is a `Modal`, not `ConfirmDialog`, because it lists several
   departments with per-row detail.
3. The user picker (05) uses Headless UI `Combobox`. The product has none; Headless UI is the
   sanctioned source of missing primitives.
4. `RedBandSection` and `KriBandLegend` (08) are new shared components because three screens need
   them and a copy would drift (the exact failure the ADR records in `BandPreview`).

## 2. Vocabulary (one wording, every screen and email)

**Return status badge** (from `ReturnRef.display_status`). Colour is never the only signal: each badge carries an icon (decorative, `aria-hidden`) and its words.

| `display_status` | Words | Classes (existing palette) |
|---|---|---|
| `submitted` | Submitted | `bg-green-100 text-green-800` |
| `overdue` | Overdue, with "N days" when `days_overdue` is set | `bg-red-100 text-red-800` |
| `reopened` | Reopened | `bg-orange-100 text-orange-800` |
| `in_progress` | In progress | `bg-blue-100 text-blue-800` |
| `not_started` | Not started | `bg-gray-100 text-gray-700` |

The ADR's precedence (`submitted` > `overdue` > `reopened` > `in_progress` > `not_started`) is the server's. The UI shows what it is sent and never re-derives it. "Not submitted" means every status except `submitted`, so a reopened return is "not submitted".

**Band badge** (green / amber / red / none). Words "Green", "Amber", "Red", and **"No band"** when the band is null. Classes follow `Kri/Show.jsx` (`bg-green-100 text-green-700`, `bg-yellow-100 text-yellow-700`, `bg-red-100 text-red-700`), upgraded to `-800` text where the badge sits on tinted backgrounds. A null band is **never** displayed as green.

**Contact roles:** `risk_champion` "Risk champion", `team_lead` "Team lead", `unit_head` "Unit head", `group_head` "Group head".

**Reminder tiers** (ADR §7, cumulative). Used by 04, 05 and 10:

| Tier | Reaches |
|---|---|
| 1 | Risk champions |
| 2 | Risk champions and team leads |
| 3 | Risk champions, team leads and unit heads |
| 4 | All four levels, including group heads |

**Delivery wording.** `sent` is shown as **"Handed to mail server"**, never "Delivered". Every place that shows it carries or links the sentence: *"Handed to mail server means our mail system accepted the message. It does not confirm it reached an inbox."* (ADR §5: bounces are not tracked.)

| Notice status | Words | Recipient status | Words |
|---|---|---|---|
| `queued` | Queued | `pending` | Waiting to send |
| `dispatching` | Sending | `sent` | Handed to mail server |
| `sent` | Handed to mail server | `failed` | Failed |
| `partially_failed` | Some failed | `skipped` + `inactive_user` | Skipped: account is inactive |
| `failed` | Failed | `skipped` + `no_email` | Skipped: no email address |
| `no_recipients` | Nobody to send to | | |

**Dates.** The props carry `Y-m-d` strings (`due_on`, `holiday_date`, `timeline`). Format them without a timezone conversion: split the string and build the display, or format with `timeZone: 'UTC'` from `Date.UTC(y, m-1, d)`. `new Date('2026-11-09')` plus local formatting can shift the day, and the cycle is date-against-date (standard §14). Display form: **"Mon 9 Nov 2026"**. Timestamps (`submitted_at`, `queued_at`) are shown in Africa/Lagos, with the word "Lagos time" in a tooltip or caption on first use per page. "Today" in any client-side check means today in `Africa/Lagos` (`Intl.DateTimeFormat` with that `timeZone`), never `new Date().toISOString()` (a UTC date, which is yesterday for the first hour of a Lagos day).

**Reporting month vs request month.** A return opened in November collects **October** (ADR §0). Every screen names the month it collects ("October 2026"), never "this month", beside the request date. Copy that says "this month's value" is banned; use "{period label} value".

## 3. Colour and contrast (the real tokens, WCAG 2.1 AA)

Approximate ratios, computed against white unless stated. Use these to choose text colours.

| Token / class | Hex | Ratio | Use |
|---|---|---|---|
| Navy `--color-primary` | `#1A365D` | about 12:1 | Headings, links, primary buttons (white text on it about 12:1) |
| Forest `--color-secondary` | `#2D7D46` | about 5.1:1 | Success text and icons: allowed for text |
| Error `--color-error` | `#C53030` | about 5.5:1 | Error text, red-band accent: allowed |
| `--color-text-primary` | `#2D3748` | about 10:1 | Body |
| `text-gray-600` | `#4B5563` | about 7.6:1 | Secondary text |
| `text-gray-500` | `#6B7280` | about 4.8:1 | Smallest allowed secondary text (also on `gray-50`: about 4.6:1) |
| `--color-text-secondary` | `#718096` | about 4.0:1 | **Fails AA for small text. Do not use for text under 18.66px bold / 24px.** |
| `text-gray-400` | `#9CA3AF` | about 2.5:1 | **Fails.** Existing pages use it for empty-state text; the new screens must not. Decorative glyphs only |
| Gold `--color-accent` | `#D4AF37` | about 2.1:1 | **Never text, never the sole indicator.** Fine as a decorative border |

Non-text contrast (1.4.11, 3:1): focus rings, input borders (`border-gray-300` is about 1.5:1, below 3:1, so inputs on the new screens keep the existing `border-gray-300` **and** show a visible 2px focus ring in Navy; field boundaries are therefore carried by the label and the ring rather than the border alone, matching what `KriForm` already ships. Flag to qa-engineer, not redesigned here), checkbox outlines (use `border-gray-500` or darker), band badges (text carries the meaning).

## 4. Shared states

Every screen spec lists its own empty/error states. These apply everywhere and are not repeated:

| State | Behaviour |
|---|---|
| **Navigating** (Inertia visit in flight) | The existing Inertia progress indicator. The previous page stays interactive until the response arrives. No full-page spinner. |
| **Network failure on a visit** | Stay on the current page. Show a `role="alert"` banner at the top of the content: "Couldn't reach the server. Check your connection and try again." with a **Try again** button that repeats the visit. Handle Inertia's network-failure event so the user never sees a raw error dialog. |
| **Non-Inertia response** (429, 502, HTML error page) | Intercept Inertia's `invalid` event and show the same banner with the matching sentence (429: "Too many requests. Wait a minute, then try again."). Never let Inertia render the raw response in its modal on these screens. |
| **Session expired (419 / 401)** | On a form screen: do not discard state. Show a modal: "Your session has expired. Sign in again in a new tab, then come back and press Retry. Your entries are kept." Retry repeats the request (Inertia reads the refreshed `XSRF-TOKEN` cookie). If the retry returns 419 again, offer a full reload as a last step. Screen 07 adds a local backup (see there). On read-only screens: a redirect to sign-in is acceptable. |
| **Permission denied (403)** | The application's 403 page. The nav never shows these entries without the permission (`NavigationPermissionGateTest`), so a 403 means a typed or stale URL. In-page, a control the user can't use is **absent**, not disabled, unless the spec says a disabled control teaches something. |
| **Not found (404)** | Out-of-tenant ids are 404 (ADR §10). The standard 404 page. |
| **Server error (500)** | The standard error page. Forms keep their values (Inertia `useForm`) and show: "Something went wrong on our side. Nothing was changed. Try again." |
| **Lifecycle refusals** | ADR §10: "submitting a submitted return" and similar answer with a flash message, not a 403. Show the flash in a `role="status"` banner. |

## 5. Accessibility baseline (every screen, in addition to its own section)

- One `h1` (the `PageHeader` title). Section titles are `h2`; card titles `h2`/`h3` in a consistent order. Nothing skips a level.
- Everything is reachable and operable by keyboard; **no keyboard trap** except modals, which trap and restore focus (Headless UI does).
- Visible focus: the existing `focus:ring-2` Navy ring on every interactive element, including the stretched-link tiles (the ring goes on the tile, not on a hidden anchor).
- Icons (`material-symbols-outlined`) are `aria-hidden="true"`. Meaning is always also in text. The icon font is a network dependency: with it blocked, the ligature name renders as text ("check_circle"), so every icon sits in a fixed-size, `overflow-hidden` box so a failed font load cannot reflow the layout.
- Status messages use `role="status"` (polite). Use `role="alert"` only for something that has just failed after a user action, never on page load.
- Form errors: `aria-invalid="true"` on the control, `aria-describedby` pointing at the error element's id **and** any help text id. `InputError` in `@thirdline/ui` renders a bare message; the implementer wraps or extends it so the message has a stable `id` the field references (the existing `Field` in `KriForm.jsx` does **not** do this today; the new and changed forms must).
- Labels: every input has a visible `<label for>`; placeholder text is never the label. Required is marked with the existing red `*` **and** `required`/`aria-required`; the `*` has `aria-hidden` and the label text carries "(required)" for screen readers via `sr-only`.
- Touch targets at least 44 by 44 CSS px for primary actions below `md` (2.5.5 is AAA; the bank's users are on phones from email, so this is a design floor).
- Reflow (1.4.10): no horizontal page scroll at 320 CSS px. Tables are the only exception, inside `overflow-x-auto`, and every table below also has a stacked-card form below `md`.
- `prefers-reduced-motion`: there is no animation beyond the Inertia progress bar.
- Language and direction: page `lang="en"`.

## 6. Low bandwidth (100 kbps is about 12 KB/s, so 100 KB takes about 8 s)

The product ships one JS bundle shared by all screens (outside this work). Per screen, the rules are:
- No new library, image, web font or chart. The only added client code is small components and `resources/js/lib/kri-band.js` (a few hundred bytes).
- Nothing polls. Anything that changes after the page loads (delivery outcomes, history) is refreshed by an explicit **Refresh** control that reloads only the prop that changed (`only: [...]`).
- Anything below the fold or conditional is not rendered until needed (expanded history, picker results).
- Each screen states its payload driver and what it shows while waiting. Skeletons reserve their final height so nothing jumps.
- Every action that posts has a visible in-flight state ("Saving…", button disabled **with** `aria-busy`) and, after 15 s, a status line: "Still working. Your connection is slow. Don't close this page." There is no client timeout that gives up on its own, because a write that might have landed must not be retried blind: after a network error on a write, the screen first reloads the affected prop to learn the truth (see 04, 07).

## 7. "Numbers on the screen" rule

Each spec ends with a table of every figure, where it comes from, and what is shown when the source is absent. A figure with no named source is cut. Derived figures (sums, differences, a percentage for a bar width) are allowed only when the spec names the inputs.

## 8. Index

| # | Spec | Route / page |
|---|---|---|
| 01 | `01-kri-library-departments.md` | `risk.kri.index` → `Kri/Index` (`layout: 'departments'`) |
| 02 | `02-kri-library-department-detail.md` | `risk.kri.departments.show`, `.unassigned` → `Kri/Department` |
| 03 | `03-kri-dashboard-submission-card.md` | `risk.kri.dashboard` → `Kri/Dashboard` (`returnsCard`) |
| 04 | `04-kri-returns-drilldown.md` | `risk.kri.returns.index` → `Kri/Returns/Index`; `risk.kri.returns.remind` |
| 05 | `05-department-kri-contacts.md` | `risk.kri.contacts.*` → `Kri/Settings/Contacts` |
| 06 | `06-organisation-holidays.md` | `risk.holidays.*` → `Settings/Holidays` |
| 07 | `07-complete-my-kris.md` | `risk.kri.my-return*`, `risk.kri.returns.show` → `Kri/MyReturn` |
| 08 | `08-red-band-explanation.md` | Component, plus `Kri/Edit`, `Kri/Show`, `Kri/MyReturn` placements |
| 09 | `09-email-monthly-request.md` | `App\Mail\KriReturnRequested` |
| 10 | `10-email-reminder-escalation.md` | `App\Mail\KriReturnReminder` |
