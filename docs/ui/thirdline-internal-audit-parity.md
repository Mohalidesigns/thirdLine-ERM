# ThirdLine Internal Audit — UI parity spec

**Reference:** https://thirdline.atherislimited.com (production, walked read-only on 2026-10-16,
desktop viewport 1440×900, user "System Admin"). Source for the same build:
`../thridLine/internalaudit` @ `7aeb3d9` — its `AuthenticatedLayout.jsx` carries the
`/thirdline-logo-white.svg` bar and `--sidebar-width` shell that production serves, so the
source and the live app agree.

**Rule:** where the source and the browser disagree, **the browser wins**. The one case is the
font: Internal Audit's `app.css` imports Inter, but every element computes to **Figtree**
(Tailwind 3's `fontFamily.sans` on `html`), and only Figtree 400/500/600 is loaded. We match
what renders.

**Builds on, does not redo:** commit `99ea51c` (fields, dropdowns, `filter-bar`,
`.data-table`, date pickers, `FormSection/FormField/FormActions`, `tools/ui-audit.mjs`) and
`dfc12a8` (row headers). Those already measure identical to the reference and are not
touched here except where noted.

---

## 1. Measured tokens

### 1.1 Typography

| Token | Internal Audit (computed) | riskerm before | Action |
|---|---|---|---|
| Font family | `Figtree, ui-sans-serif, system-ui, sans-serif, …emoji` | `Inter, …` | **Change** — self-host Figtree **400/500/600 only**, as the reference loads (its 700s resolve to the 600 face, so loading 700 would make our bold heavier than theirs); under `public/fonts`, no CDN, same rule as Inter |
| Mono | Roboto Mono (`.font-mono`, IDs like `AUE-2026-0021`) | Roboto Mono | same |
| Body | 16px / 24px / 400, `#2D3748` | same | — |
| Page title `.page-title` (h2) | 24px / 32px / 700, `#2D3748` | same class | — |
| Page subtitle | 14px `text-gray-500`, `mt-1` | same | — |
| Card title (h3) | 14px / 20px / 600, `text-gray-800` (`#1F2937`) | widget titles vary | Align widgets |
| Sidebar item | 14px / 20px / 500 | 13px / 400 | **Change** |
| Sidebar child | 12.5px, padding 6px 10px | 12px, padding 6px 12px | **Change** |
| Table head | 12px / 16px / 600, uppercase, `letter-spacing .05em`, `#6B7280` on `#F9FAFB` | same (`.data-table`) | — |
| Table cell | 14px / 20px, padding 12px 16px, bottom `1px #F3F4F6` | same | — |
| Filter label | 10px / 600, uppercase, `.05em`, `#9CA3AF` | same | — |

### 1.2 Colour

Already shared (riskerm's `@theme` is Internal Audit's token set): `--color-primary #1A365D`,
`-light #2A4A7F`, `-dark #0F2340`, `--color-secondary #2D7D46`, `--color-accent #D4AF37`,
`--color-bg #F7FAFC`, `--color-text-primary #2D3748`, `--color-text-secondary #718096`,
`--color-error #C53030`, `--color-warning #DD6B20`, `--color-info #319795`.

Additional measured values:

| Use | Value |
|---|---|
| Card / stat-card border | `1px #F3F4F6` (gray-100) |
| Header bottom border | `1px #E5E7EB` (gray-200) |
| Page-header band bottom border | `1px #F3F4F6` |
| Sidebar divider | `rgba(255,255,255,.10)` |
| Sidebar item active | bg `rgba(255,255,255,.10)`, text `#FFF`, **left border 3px `#D4AF37`** |
| Sidebar item idle / hover | text `rgba(255,255,255,.70)` / bg `rgba(255,255,255,.05)` + `#FFF` |
| Bell badge | bg `--color-error #C53030`, `#FFF`, 10px / 700 |
| Rating dots / chart (critical, high, medium, low) | `#DC2626`, `#EA580C`, `#D97706`, `#16A34A` (Tailwind 600s) |
| Progress track / fill | `#E5E7EB` / `--color-secondary` |
| Ring chart track | `#E2E8F0`, stroke 6 |
| Stat-card icon tiles | `bg-blue-50 text-blue-600`, `bg-red-50 text-red-600`, `bg-yellow-50 text-yellow-600`, `bg-purple-50 text-purple-600`, `bg-green-50 text-green-600` |

### 1.3 Radius, shadow, spacing

| Element | Radius | Shadow | Padding / size |
|---|---|---|---|
| `.card`, `.stat-card` | 12px | `0 1px 2px rgba(0,0,0,.05)` (shadow-sm); stat-card hover shadow-md | stat 20px; card-header 16px 24px; card-body 16px 24px |
| Buttons (`.btn-primary/.btn-secondary`) | 8px | — | 8px 16px, 14px/600, 36px tall, icon gap 8px |
| Sidebar item | 8px | — | 10px 16px, gap 12px, 40px tall |
| Filter inputs | 8px | ring-1 gray-200 + shadow-sm | 10px 12px, 40px tall |
| Header search | 8px | — | `w-80` (320px), `pl-10 pr-4 py-2`, bg gray-50, border gray-200 |
| Modal panel | 8px (`rounded-lg`) | shadow-xl | overlay `gray-500/75`; widths sm…4xl |
| Pagination link | 6px | — | 6px 12px, 14px; current = primary bg, white, 600 |
| Badge | full | — | 2px 10px, 12px/600 |

### 1.4 Layout

| Region | Value |
|---|---|
| Sidebar | fixed, 260px (`--sidebar-width`), collapsed 72px, bg `--color-primary` |
| Sidebar logo bar | 64px tall (`h-16`), `px-4`, bottom divider; logo 44px tall (36px collapsed) |
| Sidebar nav | `py-4 px-3`, items `space-y-1`, scroll with `.sidebar-scroll` thumb `white/20` |
| Sidebar footer | top divider, `py-4 px-3`, secondary items then **Collapse** (double-chevron, rotates 180° when collapsed) |
| Top bar | sticky, **64px** (`h-16`), white, bottom border gray-200, `px-4 sm:px-6`; search **left**, bell + user **right** (`gap-3`) |
| Page-header band | white, bottom border gray-100, `px-4 sm:px-6 py-4`, holds `.page-header` (title, subtitle, actions) — **the page title sits in this band, not on the grey canvas** |
| Main | `p-4 sm:p-6` on `--color-bg` |
| Dashboard grid | 4 stat cards in a row (`gap-4`/`gap-6`), then 3 cards, then 2 tables |

### 1.5 Icons

Heroicons v2 **outline**, 24-grid, `stroke-width 1.5`, `stroke=currentColor`; 20px (`w-5 h-5`)
in the sidebar and stat tiles, 16px (`w-4 h-4`) in buttons and row actions (row actions use
stroke 1.5, buttons stroke 2). No icon font is loaded.

riskerm used Material Symbols Outlined (self-hosted font) — 39 distinct names from
`NavPresenter`, ~330 uses across ~140 page files. The icon-sweep follow-up moved every page
to `<Icon>`: button icons are `w-4 h-4` with `strokeWidth={2}`, row actions and inline icons
stay at stroke 1.5 and keep their original size where it was smaller than 16px (`w-3`/`w-3.5`
trend arrows, chips), tiles and headings `w-5 h-5`, empty states `w-8`–`w-9 block mx-auto`.
An icon on a padded tile puts the padding on a wrapping `<span>`: preflight's
`box-sizing: border-box` applies to `<svg>`, so padding on the Icon itself eats the glyph.
The Material font still loads, only for names `Icon` does not map. Heroicons has no
"off"/"problem" variants, so `money_off`, `credit_card_off`, `sync_problem` and the like map
to the base glyph.

### 1.6 Charts

No `<canvas>` on the reference dashboard: the ring and donut are hand-built SVG
(`ProgressRing`, `DonutChart` — byte-identical in `packages/ui`). Donut: thick ring, legend
to the right with 12px round swatches, label `text-sm text-gray-600`, value `font-semibold`,
centre total `text-2xl font-bold` over `text-xs text-gray-500` "Total".

riskerm's widget dashboards use Chart.js (`packages/ui/src/widgets`). They stay Chart.js
(the builder depends on it) and take the reference's styling: Figtree, gray-100 grid,
gray-500 12px ticks, round 12px legend swatches, donut `cutout 62%`, the rating palette
above for rating-semantic series.

### 1.7 Notifications

The reference has **no notification dropdown**: the bell is a link to `/notifications`
(badge `9+` cap, ring animation when the polled count rises). The page: unread banner
(`bg-blue-50 border-blue-100 rounded-lg`, navy count disc), `filter-bar` with type select and
an "Unread Only" toggle, then a `space-y-3` list of `.card` rows — 36px tinted icon tile,
title `text-sm font-medium text-gray-700`, body `text-sm text-gray-500 line-clamp-2`,
timestamp `text-xs text-gray-400`, type pill, unread rows with a left primary border and a
navy dot. riskerm keeps the link-not-dropdown behaviour (it already does) and ports the page.

### 1.8 Modals

`Modal.jsx` already matches (same overlay, panel, radius, shadow, widths). riskerm drops the
Headless UI `<Transition>` on purpose (Headless UI 2.2 + Tailwind 4 never reach the end
state — see the component's comment); that is a motion difference only and stays.
`ConfirmDialog` is identical. No change.

---

## 2. Gap list → build items (shared layer only)

| # | Item | Where | Picks up |
|---|---|---|---|
| G1 | Figtree, self-hosted; `--font-sans` and `body` switch; Chart.js `fontFamily` | `resources/css/fonts.css`, `app.css`, `widgets/theme.js`, `public/fonts/` | every screen |
| G2 | Sidebar to the reference: logo bar, `.sidebar-nav-item` items, chevron sections, child links, group labels, footer + collapse; collapsed = icons only | `packages/ui/src/Layouts/AuthenticatedLayout.jsx` | every module |
| G3 | Top bar: 64px, search left (restyle `SearchBox` to the reference input), bell + user right, breadcrumb removed; tenant chip and period selector kept (riskerm-only) in the reference's control style | layout, `resources/js/Components/SearchBox.jsx`, `PeriodSelector.jsx` | every module |
| G4 | Page-header band: `PageHeader` renders into the layout's white band (portal) when it is the page's header; `header` prop still wins | `PageHeader.jsx`, layout | 254 pages |
| G5 | Icons: `Icon` component — heroicons outline for the 39 nav names plus the common action names; Material Symbols fallback for anything unmapped; shell and shared components switch to it | `packages/ui/src/Components/Icon.jsx` | shell + shared components |
| G6 | KPI cards: `KpiCard` and Blade `.kpi-card` to the stat-card layout (label, value, sub, tinted 40px icon tile right) | `KpiCard.jsx`, `app.css` | dashboards in all modules |
| G7 | Widget chrome: header to `card-header` (14px/600 gray-800 title, xs gray-500 meta), Chart.js defaults per §1.6 | `Widget.jsx`, `widgets/*` | dashboards |
| G8 | Notifications page per §1.7 | `resources/js/Pages/Notifications/Index.jsx` | — |

### Out of scope for this PR (booked follow-ups)

- **Page-level icon sweep** — replacing the ~330 `material-symbols-outlined` spans inside module
  pages with `<Icon>`. Mechanical, touches ~140 files that open PRs #14/#15/#18 also touch; goes
  in its own PR after those merge.
- **Brand mark** — the reference shows the ThirdLine logo; riskerm keeps the "Atheris ERM"
  wordmark in the reference's 64px logo bar until the product owner supplies a white logo SVG.
- **User role under the name** — the reference shows the audit role; `auth.user` is
  id/name/email by package contract, so riskerm shows the email (the reference's own fallback).

## 3. Acceptance

1. At 1440×900, dashboard, a register table, a form, a modal and the notifications page are
   visually matched against the reference (side-by-side screenshots in the PR).
2. Computed-style spot checks equal §1 for: body font, sidebar item (active/idle), header
   height, search input, bell badge, page title band, stat card, card header, table head/cell.
3. `node tools/ui-audit.mjs <changed files> packages/ui/src` → 0 findings; `npm run ui:audit:test`
   passes; `npx vite build` clean.
4. Keyboard: every sidebar section toggle is a `<button aria-expanded>`, the collapsed sidebar
   exposes each item's name (`title` + `aria-label`), decorative SVGs are `aria-hidden`, the bell
   link is named "Notifications (N unread)".
5. PHP suite green on both CI legs (no PHP is expected to change).

---

## 4. Build spec

Reference paths: `IA` = `../thridLine/internalaudit`; `IA/Layout` = `IA/resources/js/Layouts/AuthenticatedLayout.jsx`;
`IA/css` = `IA/resources/css/app.css`. Line numbers are from `7aeb3d9`.
riskerm paths: `L` = `packages/ui/src/Layouts/AuthenticatedLayout.jsx`.

**Cross-cutting rules (apply to every item below)**

| Rule | Spec |
|---|---|
| Focus ring | Never copy `.btn-primary`'s `focus:outline-none focus:ring-2` pairing (booked finding: the ring shows on mouse click and `outline-none` leaves forced-colors users with nothing). New interactive shell elements use `focus-visible:` only and never remove the outline without a replacement. Sidebar (navy): `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white`. Top bar / page (white): `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2`. Do not edit `.btn-*` in this PR. |
| Reduced motion | Every `transition-*`/`duration-*` added carries `motion-reduce:transition-none`; `animate-bell-ring` and `animate-spin` carry `motion-reduce:animate-none`. Add once to `app.css`: `@media (prefers-reduced-motion: reduce){ .animate-bell-ring{animation:none} }`. Sidebar width change on collapse is instant under reduced motion. |
| Contrast (AA, measured on `#1A365D`) | `text-white/70` ≈ 7:1 pass; `text-white/60` ≈ 5.6:1 pass; **`text-white/50` ≈ 4.2:1 FAILS for 12px text and `white/30–40` fail**. Reference uses `/40` (group labels) and `/30`-ish footer text; do **not** copy those — floor is `text-white/60` for any text, `text-white/70` for links. Gold `#D4AF37` border on navy ≈ 5.9:1 pass (non-text 3:1). Bell badge white on `#C53030` ≈ 5.6:1 pass. |
| Decorative icons | Every `<Icon>` is `aria-hidden="true"` and `focusable="false"` unless given `title`/`label` (see G5). The accessible name always lives on the parent control. |
| New dependency | `@heroicons/react@^2` is **not** in `package.json` (verified) — add it to the root `package.json` (the `packages/ui` build resolves it from the root). Import per-icon from `@heroicons/react/24/outline` only (tree-shaken; no deep `/outline/IconName` paths). No `/solid`, `/20/*`. |

### G1 — Figtree, self-hosted

| Item | Spec |
|---|---|
| Files | `public/fonts/Figtree-400.woff2`, `-500.woff2`, `-600.woff2` — **latin subset, upright only**, from the Google Fonts static files for Figtree (OFL; add `public/fonts/Figtree-OFL.txt`). Target ≤ 18 KB each. Remove `Inter-1..7.woff2` and their `@font-face` blocks from `resources/css/fonts.css` only after `grep -r "'Inter'"` is clean (also `app.blade.php:11` comment, `widgets/theme.js:69`). `Roboto Mono` is not in `public/fonts` today (system mono fallback) — leave as is; the stack is already `ui-monospace`. |
| `@font-face` | Three blocks, `font-family:'Figtree'; font-style:normal; font-weight:400|500|600; font-display:swap; src:url(/fonts/Figtree-N.woff2) format('woff2'); unicode-range:` latin ranges (U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD). Characters outside latin (e.g. Yoruba tone marks in names) fall through to `system-ui` — acceptable, state it in the PR. |
| Tokens | `app.css:58` `--font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji';` and `app.css:86` body `font-family: var(--font-sans)`. `widgets/theme.js:69` `fontFamily: "'Figtree', ui-sans-serif, system-ui, sans-serif"`. |
| Weights | Only 400/500/600 exist. `font-bold` (700) resolves to the 600 face — that **is** the reference behaviour (§1.1). Do not add a 700 file; do not set `font-synthesis: none` (browser may not synthesise, but must not be disabled by us either way — leave default). |
| Low bandwidth | At 100 kbps (~12 KB/s) 3×~18 KB = ~4.5 s total, so: `font-display: swap` (text visible immediately in `system-ui`); `<link rel="preload" as="font" type="font/woff2" href="/fonts/Figtree-400.woff2" crossorigin>` for **400 and 500 only** in `app.blade.php` (body + sidebar/labels), 600 loads on demand. Preload tags need `crossorigin` even same-origin or the file downloads twice. Size-adjust fallback: add `@font-face{font-family:'Figtree Fallback';src:local('Arial');size-adjust:100%;ascent-override:95%;descent-override:25%;line-gap-override:0%}` and put `'Figtree Fallback'` right after `'Figtree'` in `--font-sans`, so the swap moves text by <1px (no layout shift in the 64px bar / 40px nav rows). Material Symbols stays `font-display: block` (unchanged) — while it loads the icon names would show as text, which is the reason G5 moves the shell off it. |
| States | No JS state. Verify with DevTools "Slow 3G": nav labels render in fallback within first paint, no icon-name text flashes in the sidebar (G5 closes this for the shell). |

### G2 — Sidebar

Reproduce `IA/Layout` lines 599–636 (aside, logo bar, nav, footer) and 398–477 (`NavItem`), 352–396 (`SubGroupItem`). CSS classes `.sidebar-nav-item(.active)` already exist at `app.css:101–111` and match `IA/css:40–50`; **do not redefine them** — use them.

**Markup (outer → inner)**

| Element | Classes / attrs | Source |
|---|---|---|
| `<aside data-sidebar aria-label="Primary">` | `fixed top-0 left-0 z-50 h-full bg-[var(--color-primary)] text-white flex flex-col transition-all duration-300 motion-reduce:transition-none w-[var(--sidebar-width)]` + `lg:w-[var(--sidebar-collapsed-width)]` when collapsed + `translate-x-0` (drawer open) / `-translate-x-full` (closed) + `lg:translate-x-0` | IA/Layout:600–603. Keep `data-sidebar` (print CSS at `app.css:944` selects it). Width classes use `lg:` for the collapsed case so a persisted/default collapsed state can never shrink the mobile drawer. |
| Logo bar | `<div class="flex items-center px-4 h-16 border-b border-white/10 flex-shrink-0">` | IA/Layout:605 |
| Brand (no logo asset yet — see out-of-scope) | `<span class="h-9 w-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0"><Icon name="shield" class="w-5 h-5 text-[var(--color-accent)]"/></span>` then, when expanded, `<span class="ml-3 min-w-0"><span class="block text-sm font-bold leading-tight truncate">Atheris ERM</span><span class="block text-[11px] text-white/60 leading-tight">GRC Suite</span></span>`. Collapsed: tile only (36px, matching IA's `h-9` collapsed logo). `GRC Suite` raised from 10px/50% to 11px/60% for AA. | replaces img at :606 |
| `<nav aria-label="Main" data-testid="sidebar-nav">` | `flex-1 overflow-y-auto sidebar-scroll py-4 px-3 space-y-1` | IA/Layout:610 (`px-3 py-3 space-y-0.5` in riskerm today — change) |
| Primary link (`nav.primary[]`) | `<NavAnchor class="sidebar-nav-item {active?'active':''}" aria-current={active?'page':undefined}>` `<Icon name={item.icon} class="w-5 h-5 flex-shrink-0"/> <span class="truncate">{label}</span>` | IA/Layout:467–475 |
| Divider after primary | `<div role="separator" class="h-px bg-white/10 mx-1 my-2"/>` (only if `primary.length>0` and sections exist); before admin `my-3` | existing |
| Section toggle (`nav.sections[]`, `nav.admin`) | `<button type="button" class="sidebar-nav-item w-full {sectionActive?'active':''} focus-visible…" aria-expanded={open} aria-controls={`nav-sec-${key}`}>` containing `<Icon w-5 h-5/>`, `<span class="truncate flex-1 text-left">`, `<Icon name="chevron_right" class="w-4 h-4 flex-shrink-0 transition-transform motion-reduce:transition-none {open?'rotate-90':''}" strokeWidth={2}/>` | IA/Layout:419–432 |
| Section children container | `<div id="nav-sec-{key}" class="ml-4 pl-3 border-l border-white/10 space-y-0.5 mt-0.5 mb-1">` — **unmounted when closed** (not `hidden`): keeps tab order clean and matches IA. | IA/Layout:434 |
| Child link (`section.items[]`, `group.items[]`) | `<NavAnchor class="sidebar-nav-item {active?'active':''}" style padding 6px 10px, font-size 12.5px>` → Tailwind: `!py-1.5 !px-2.5 !text-[12.5px]` (the `!` is needed because `.sidebar-nav-item` sets px/py/text-sm in `@layer components` **and** utilities beat components in v4 — verify; drop `!` if the computed padding is already 6px 10px); `<span class="truncate">{label}</span>`; `title={label}`; `aria-current="page"` when active. | IA/Layout:451–458 |
| Admin group label (`nav.admin.groups[].label`) | `<div role="group" aria-labelledby=…>`; label `<div id class="px-2.5 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-white/60">`, first group no top border, subsequent `mt-1 border-t border-white/10` | IA/Layout:441–447 (colour raised from `/40` for AA) |
| Footer | `<div class="border-t border-white/10 py-4 px-3 space-y-1 flex-shrink-0">` | IA/Layout:617 |
| Collapse button | `<button type="button" class="sidebar-nav-item w-full text-white/70 hover:text-white hidden lg:flex" aria-label={collapsed?'Expand sidebar':'Collapse sidebar'} aria-pressed={collapsed}>` `<Icon name="keyboard_double_arrow_left" class="w-5 h-5 transition-transform {collapsed?'rotate-180':''}"/>` `{!collapsed && <span>Collapse</span>}` — single icon rotated, **not** two icons (IA:627–632). | IA/Layout:623–634 |
| "Designed for CBN ORMS reporting" | Keep (riskerm-only), expanded only, `px-3 pb-1 text-[11px] text-white/60 flex items-center gap-2` with `<Icon name="architecture"?>` → unmapped, so use `InformationCircleIcon` (`info`) `w-4 h-4`. | existing, contrast fixed |

**Data shape → rendering (NavPresenter, `Presenters/NavPresenter.php:470–514`)**

| Prop | Shape | Rendering rule |
|---|---|---|
| `navigation.primary[]` | `{key,label,icon,route,url,permission,inertia}` | `PrimaryLink`. Active = exact path match, plus prefix match for `hq` and `dashboards` (keep `L:73`). |
| `navigation.sections[]` | `{key,label,icon,prefixes[],items[]}` | Section toggle; open state initialised from `prefixes` (`isUnder`) and re-opened by effect when it becomes active (keep `L:102–108`). Items = child links; active = `isExact` on `item.url`. |
| `navigation.admin` | `{key:'administration',label,icon,prefixes:['/admin'],groups:[{label,items[]}]}` or `null` | Same toggle; children rendered per group with the group label. `null` → no divider, no section. |
| `item.inertia` | bool | `NavAnchor` branch kept (`Link` vs `<a>`). |

**States**

| State | Spec |
|---|---|
| Idle | text `white/70` (from `.sidebar-nav-item:not(.active)`), 14px/500, 40px tall |
| Hover | `bg-white/5 text-white` (from CSS) |
| Active | `bg-white/10 text-white` + 3px gold left border (CSS). Section toggle gets `.active` when any descendant is active **and** stays active-styled while collapsed-open. Exactly one child link carries `aria-current="page"`; the toggle does not. |
| Focus-visible | white inset ring (cross-cutting rule); must be visible on the `.active` row too (inset ring is drawn inside the gold border). |
| Section open/closed | `aria-expanded` mirrors state; chevron rotates 90°; closed → children unmounted. A route change into a section force-opens it but never force-closes another. |
| Collapsed (lg+, 72px) | Labels use `lg:sr-only` (not `hidden`) so the name stays in the a11y tree; every link/toggle gets `title={label}` **and** `aria-label={label}`; section toggles become a single link to the section's first item (existing behaviour `L:110–121`) with `aria-label="{section.label}"`; no chevrons, no child lists, no group labels; dividers keep; footer text hidden; Collapse button shows icon only with `aria-label`. Active section shows the gold left border. |
| Mobile drawer (< lg) | Hamburger (G3) opens it; scrim `fixed inset-0 z-40 bg-black/50 lg:hidden` is a `<button aria-label="Close navigation">` (keyboard reachable) rather than a bare div; the drawer is always expanded (no collapsed rules apply, courtesy of the `lg:` width classes). Add an `X` close button (`XMarkIcon`, `aria-label="Close navigation"`) at the right of the logo bar, `lg:hidden`. Escape closes; focus moves to the first nav link on open and returns to the hamburger on close; any Inertia navigation closes it (`router.on('navigate')`). `inert`/`aria-hidden` on `<aside>` while it is off-canvas and < lg so closed-drawer links are not tabbable. |
| Long labels | `truncate` on every label span, `min-w-0` on flex parents, `title={label}` on every link so the full text is available on hover; no wrapping, rows stay 40px / 33px. Verify with "Issues & Findings" and the longest TPRM/BCMS child at 260px. |
| Empty / no permission | NavPresenter already omits unpermitted items and sections whose items are empty. The layout additionally skips any item with no `url` (missing route) and any section left with zero items; `icon:null` renders an empty `w-5 h-5` spacer so labels stay aligned. If `navigation` is absent the `<nav>` renders empty (no crash). |
| Scroll | `.sidebar-scroll` thumb is `white/20` (`app.css:820`); keep. Active link is scrolled into view on mount (`scrollIntoView({block:'nearest'})`, instant under reduced motion). |

**Accessibility**: landmark `<aside aria-label="Primary">` with an inner `<nav aria-label="Main">`; toggles are `<button aria-expanded aria-controls>`; links `aria-current="page"`; separators `role="separator"`; Tab order = logo (not focusable) → primary → sections (toggle, then its links when open) → admin → Collapse; arrow-key roving is **not** required (flat tab sequence like the reference).

**Low bandwidth**: no image in the bar (wordmark is text + inline SVG) → zero extra requests; icons are inline SVG (G5) so the sidebar paints complete with the first JS chunk, independent of the Material Symbols font. Sidebar transitions are CSS only.

### G3 — Top bar

Reproduce `IA/Layout:643–716`. Replace `L:297–391`.

| Region | Spec |
|---|---|
| `<header>` | `sticky top-0 z-30 bg-white border-b border-gray-200 h-16` → inner `flex items-center justify-between h-full px-4 sm:px-6 gap-3` (IA:644–645) |
| Left cluster | `flex items-center gap-4 min-w-0`: (a) hamburger `lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100`, `aria-label="Open navigation"`, `aria-expanded={mobileMenuOpen}`, `aria-controls` = aside id, `<Icon name="menu" class="w-6 h-6"/>`; (b) search slot. |
| Breadcrumb | **Removed** (`L:309–318`). The `title` prop stays accepted (existing pages pass it) but is no longer rendered; document-title is the page's `<Head>`. |
| Search slot | Wrapper `hidden sm:block` (IA `hidden sm:flex`), rendered only when `search && permissions.includes('search.view')` (keep `L:323`). Below `sm`: replace with an icon-only link to `tryRoute('search.index')` (`sm:hidden`, `MagnifyingGlassIcon` w-5 h-5, `aria-label="Search"`, same hover as the bell) — only when the route resolves. |
| Right cluster | `flex items-center gap-3` (IA:672), order: tenant chip → period selector → bell → user. |

**`SearchBox.jsx` restyle** (keep behaviour: 300 ms debounce, `search.suggest`, Enter → `search.index`, Escape closes, outside-click closes):

| Part | Spec |
|---|---|
| Wrapper | `relative` (IA:659) |
| Icon | `<Icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"/>` |
| Input | `type="search"` `w-56 xl:w-80 pl-10 pr-4 py-2 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20` (IA:666). 320px at ≥ xl (1280), 224px below so the cluster fits at lg (see width budget). `[&::-webkit-search-cancel-button]` left default. Placeholder stays product-specific: "Search risks, controls, units…". `aria-label="Search"` kept. The **border colour change to navy is the focus indicator** (≥ 3:1); the 20% ring is decoration — do not drop the border change. |
| Results panel | Anchored `left-0` (was `right-0`; the box is now left-aligned), `top-full mt-1 w-96 max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white shadow-xl z-50`; rows `px-3 py-2 hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-primary)]`; icon via `<Icon name={result.icon||'topic'}>` (unmapped names fall back, see G5); name `text-sm font-medium text-gray-800 truncate`; meta `text-xs text-gray-500` (was 10px/gray-400 — fails AA). Input gets `aria-expanded`, `aria-controls` = panel id; panel has a visually-hidden `role="status"` line "N results" / "No results for …" (the empty case must render "No results for “q”" — today it renders nothing). Search failure: panel shows "Search is unavailable. Press Enter for full results." |
| States | idle / typing (<2 chars: panel closed) / loading (panel closed, input `aria-busy`) / results / no results / error (above). Degraded network: debounced request failure never throws; Enter still navigates. |

**Tenant chip** (riskerm-only, kept): same node and `data-testid="tenant-chip"`; restyle to the reference control size: `hidden xl:inline-flex items-center gap-1.5 h-9 rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm font-medium text-gray-700`; icon `BuildingOffice2Icon` `w-4 h-4 text-gray-400`; label `truncate max-w-[180px]`; `title={tenant.name}`. It is non-interactive, so it is **not** focusable; its text is read in document order (`aria-label` not needed). Hidden below xl (the period/tenant are re-surfaced in the user menu: add a non-interactive `Dropdown` header row "Signed in to {tenant.name}" at < xl — one line, `px-4 py-2 text-xs text-gray-500`).

**Period selector** (slot, kept; condition `period` truthy at `L:342`): restyle only — container `h-9 flex items-center gap-1 pl-2 pr-1 rounded-lg border border-gray-200 bg-gray-50`, label button `px-2 text-sm font-semibold text-[var(--color-primary)] min-w-[120px] hover:bg-white rounded focus-visible:…`, arrows `p-1.5 rounded text-gray-500 hover:bg-white hover:text-[var(--color-primary)]` with `ChevronLeftIcon/ChevronRightIcon w-4 h-4`, lock `LockClosedIcon w-3.5 h-3.5` with `<span class="sr-only">Closed, values locked</span>` (today only `title`). Popover `rounded-lg shadow-xl border-gray-200 w-64`, text sizes ≥ 12px (`text-xs` for the granularity chips, was 11px; headings `text-[10px]`→`text-xs`). Keep `aria-haspopup` → change to `aria-haspopup="true"`/`aria-expanded` as is; add `aria-label="Reporting period: {period.name}"` on the label button; Escape returns focus to it. Wrapper `hidden sm:block`.

**Bell** (IA:674–686 + riskerm `unreadNotifications`, polling kept `L:180–203`):

| Part | Spec |
|---|---|
| Link | `<Link href={notificationsUrl} class="relative p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors motion-reduce:transition-none focus-visible:…" aria-label={liveUnread>0?`Notifications (${liveUnread} unread)`:'Notifications'}>` — the `9+` cap is **visual only**; the label carries the true count. Render only if `notificationsUrl` resolves (keep). |
| Icon | `<Icon name="notifications" class="w-5 h-5 {bellRing?'animate-bell-ring motion-reduce:animate-none':''}"/>` |
| Badge | `absolute -top-0.5 -right-0.5 w-5 h-5 bg-[var(--color-error)] text-white text-[10px] font-bold rounded-full flex items-center justify-center` `aria-hidden="true"`; text `liveUnread>9?'9+':liveUnread`; **not rendered when 0** (no “0” badge). `{bellRing ? 'animate-pulse motion-reduce:animate-none'}` per IA:682. Count ≥ 100 still shows `9+`. |
| Live region | Single `<span class="sr-only" role="status" aria-live="polite">` inside the header, text set **only when the polled count rises** ("You have N unread notifications"); never on initial render or when it falls (alert-fatigue rule). |
| Degraded network | Poll failures are swallowed (keep); the badge keeps the last good count; no error UI. |

**User menu** (IA:689–713): trigger `flex items-center gap-3 p-1.5 rounded-lg hover:bg-gray-100 focus-visible:…`, `aria-label="Account menu for {user.name}"`; avatar `w-8 h-8 bg-[var(--color-primary)] rounded-full text-white text-sm font-semibold` (`aria-hidden`); text block `hidden lg:block text-left`: name `text-sm font-medium text-gray-700`, second line `text-xs text-gray-500 truncate max-w-[180px]` = `user.email` (riskerm; IA shows role); chevron `ChevronDownIcon w-4 h-4 text-gray-400 hidden lg:block`. Menu items unchanged (`Profile`, `2FA Setup` if `features.mfa_totp`, `My Responsibilities`, `Log Out`), each only when its route resolves.

**Width budget (verify at 1024 / 1280 / 1440, sidebar expanded)**: main width = viewport − 260. At 1024: 764 − 48 padding = 716 → search 224 + gaps 12 + period ~190 + bell 36 + user avatar+chevron ~72 ≈ 535 ✓ (chip hidden, user text shown from lg: ~190 → 650 ✓). At 1280+: search 320, chip ≤ 130, period 190, bell 36, user 190 + 4×12 gaps ≈ 914 of 972 ✓. If any breakpoint overflows, the first thing to drop is the user text block, never the bell.

**Low bandwidth**: bar is 64px fixed so nothing shifts when fonts/results load; search suggestions are lazily fetched on typing only; the 30 s poll skips while `document.hidden` (add the check) so a degraded link is not spent on a background tab.

### G4 — Page-header band (portal)

**Goal:** the white band under the top bar (`IA/Layout:718–725`, `bg-white border-b border-gray-100` → `px-4 sm:px-6 py-4`) holds the page's `PageHeader`, so 254 pages move into the band with **no page edits**.

**Mechanism (all in `packages/ui`)**

| Piece | Spec |
|---|---|
| Context | New `packages/ui/src/Layouts/PageHeaderSlot.jsx` exports `PageHeaderSlotContext` (value: `null` or `{ target: HTMLElement\|null, claim(id): boolean, release(id): void }`) and `PageHeaderSlotBoundary` (`Provider value={null}`). |
| Layout | Holds `const [target, setTarget] = useState(null)`, `claimedRef = useRef(null)` and `const [claimed, setClaimed] = useState(false)`. `claim(id)` returns true and records `id` iff nothing holds it (or it already holds it); `release(id)` clears it and `setClaimed(false)`. Renders the band always, with the portal target as an inner div bound through a callback ref (`ref={setTarget}`). |
| Band markup | `<div class="bg-white border-b border-gray-100 {header||claimed?'':'hidden'}" data-testid="page-header-band"><div class="px-4 sm:px-6 py-4" ref={setTarget}>{header}</div></div>`. When the `header` prop is given it is rendered **inside the target div directly** (IA:719–724 parity) and the context value passed down is `null` (header **wins**; nested `PageHeader`s render inline, see below). |
| `PageHeader` | `const slot = useContext(PageHeaderSlotContext); const id = useId();` Eligibility = `slot?.target` truthy. In `useLayoutEffect`: if eligible, `setPortal(slot.claim(id))` and on cleanup `slot.release(id)`; if not eligible, `setPortal(false)`. While the decision is pending (first render) render `null` for eligible instances — `useLayoutEffect` resolves before paint, so there is no visible blank flash. Result: `portal ? createPortal(<Inner/>, slot.target) : <Inner/>` where `Inner` is the **unchanged** current markup (`div.page-header` with title, subtitle, actions; breadcrumbs unchanged). `claimed` is set by `claim` through the layout so the band un-hides in the same commit. |
| Where `slot` is `null` | (1) no layout above (storybook/tests, pages using another layout), (2) the page passed `header`, (3) inside `Modal`, `ConfirmDialog` and any slide-over: **`Modal.jsx` and `ConfirmDialog.jsx` wrap their children in `<PageHeaderSlotBoundary>`** (Headless UI `Dialog` renders through a React portal, but React context still flows through it — without the boundary a `PageHeader` inside a modal would hijack the band). Add the same boundary to any future drawer. |

**Cases**

| Case | Behaviour |
|---|---|
| One `PageHeader` under the layout | Portals into the band. Band visible. Page body starts directly with content (no gap duplication; the header's own `mb-6` now lives inside the band). |
| Two `PageHeader`s under the layout | First to claim (mount order = document order, because layout effects run child-first, source-order siblings) takes the band; the second gets `claim() === false` and renders **inline in `<main>`** exactly as today. No warning in production; `console.warn` in dev only. If the first unmounts (conditional render, tab switch) it releases and the band un-claims; the second is **not** re-promoted (avoids content jumping between band and body) — it stays inline until it remounts. |
| `PageHeader` in a Modal/dialog | Inline inside the dialog (boundary → `slot === null`). Band untouched. |
| Page passes `header` | `header` renders in the band; any `PageHeader` in `children` renders inline. This keeps pages not yet migrated and every page that passes `header={<PageHeader …/>}` (count not verified here; `grep -rln "header={" resources/js/Pages`) identical: for those the element is *inside* `header`, i.e. inside the band's target, so it gets `slot === null` (the provider sits on the `children` subtree only, and `header` is rendered outside it) and renders inline *within the band*. |
| Page with no `PageHeader` and no `header` | Band has `hidden` (display none) → no empty white strip, no border line. `claimed=false` and `header` falsy. |
| Inertia navigation | The layout persists only if the app uses persistent layouts; otherwise the layout remounts. Either way the claim is released on unmount; on a persistent layout, `release` must run before the next page's `claim` (guaranteed — unmount cleanup precedes the new mount's layout effect in the same commit). |
| Print | Band stays in the print stylesheet (`app.css:928+` strips sidebar/topbar only) so printed pages keep their title; add `[data-testid=page-header-band]` to the "keep" side, nothing else. |
| SSR / first paint | The target div exists from the first render, but `setTarget` triggers a second render; an eligible `PageHeader` renders `null` until it has the target, in a layout effect. No SSR in this app (Inertia client render) — if SSR is ever enabled, PageHeader must render inline during SSR (`typeof document === 'undefined'`). |

**Dimensions**: band = `py-4` (16+16) + `.page-header` content + its `mb-6` (24). With title 32px (`.page-title` 24/32) and a one-line subtitle (4 + 20) the band measures 32 + 56 + 24 = **112–113 px** — equal to the reference. Acceptance: `getBoundingClientRect().height` of `[data-testid=page-header-band]` on Notifications = 113 ±1 at 1440. A page with title only: 32+32+24 = 88. **Do not remove or override `.page-header`'s `mb-6`** (`app.css:283`) — it is the band's lower padding. `.page-header` stays `flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4`; actions `flex gap-2 flex-shrink-0`; on `<sm` actions wrap under the title.

**Accessibility**: the `h2.page-title` is the page heading; keep it an `h2` (reference) but ensure the document has an `h1`: render the product name in the logo bar as `<span>` (not a heading), and mark the portaled `h2` unchanged — the top-level heading structure is therefore h2-first, as today. The band is **not** a landmark (it is page content); do not add `role="banner"`. Because the title is now rendered outside `<main>`, add `aria-labelledby` on `<main>` pointing at the title's id (`PageHeader` generates `id={`${id}-title`}` on the `h2` and registers it with the layout via `claim`) so the main landmark keeps its name; if not portaled, `<main>` has no label (as today). Skip-link: add `<a href="#main-content" class="sr-only focus:not-sr-only …">Skip to content</a>` as the first child of the layout and `id="main-content" tabIndex={-1}` on `<main>`.

**Low bandwidth / states**: the band is part of the layout shell, so on a slow chunk load the page shows top bar + hidden band, then the title appears (no layout shift: band un-hides at once, the main content is below it either way). Loading states of pages are unchanged.

### G5 — `Icon` component

**File:** `packages/ui/src/Components/Icon.jsx`, exported from the package index and re-exported for the app as `@thirdline/ui/Components/Icon`. Replaces the private `Icon` in `L:20–22`.

**API**

| Prop | Type / default | Meaning |
|---|---|---|
| `name` | string (Material Symbol name, snake_case) | Looked up in the map below. |
| `className` | string, default `'w-5 h-5'` | Applied to the SVG (size + colour via `currentColor`). **Callers that pass a Material-era size such as `text-[18px]` get that ignored for SVG sizing** — only `w-*/h-*/size-*` size SVGs; the component therefore translates a lone `text-[Npx]` into `style={{width:N,height:N}}` when no `w-`/`h-`/`size-` class is present (shim for the sweep PR; sidebar uses explicit classes). |
| `strokeWidth` | number, default `1.5` | Passed to the heroicon (`strokeWidth` prop). Buttons use `2`; row actions `1.5`. |
| `label` | string, optional | If present: `role="img"` `aria-label={label}` and no `aria-hidden`. If absent: `aria-hidden="true"` `focusable="false"`. |
| other props | spread | `...rest` to the SVG (e.g. `style`, `data-*`). |

**Behaviour**: `const Cmp = MAP[name]; return Cmp ? <Cmp className strokeWidth aria-hidden … /> : <span className={`material-symbols-outlined leading-none ${className}`} aria-hidden="true">{name}</span>;` — **unmapped names fall back to the Material Symbols glyph** so the ~330 page-level uses and any server-supplied name (`result.icon` in search, widget icons, module-supplied nav icons) keep rendering; the fallback span still needs the `text-*` sizing, so when falling back the `w-N h-N` classes are ignored and `text-[Npx]` is derived from them (`w-5`→20px, `w-4`→16px, `w-6`→24px). In dev, `console.debug` once per unmapped name (never in production). The map is a plain object of **named imports** (no `import *`), so Vite tree-shakes to ~60 icons (~0.4 KB gzip each).

**Mapping — NavPresenter names (39)**

| Material name | Heroicon (24/outline) | Material name | Heroicon (24/outline) |
|---|---|---|---|
| `report_problem` | `ExclamationTriangleIcon` | `grid_on` | `TableCellsIcon` |
| `bug_report` | `BugAntIcon` | `gavel` | `ScaleIcon` |
| `account_tree` | `ShareIcon` | `folder_open` | `FolderOpenIcon` |
| `fact_check` | `ClipboardDocumentCheckIcon` | `device_hub` | `ArrowPathRoundedSquareIcon` |
| `view_list` | `QueueListIcon` | `dashboard` | `Squares2X2Icon` |
| `verified_user` | `ShieldCheckIcon` | `corporate_fare` | `BuildingOffice2Icon` |
| `verified` | `CheckBadgeIcon` | `checklist` | `ClipboardDocumentListIcon` |
| `upload_file` | `ArrowUpTrayIcon` | `category` | `RectangleStackIcon` |
| `update` | `ArrowPathIcon` | `campaign` | `MegaphoneIcon` |
| `tune` | `AdjustmentsHorizontalIcon` | `calendar_month` | `CalendarDaysIcon` |
| `trending_up` | `ArrowTrendingUpIcon` | `calculate` | `CalculatorIcon` |
| `task_alt` | `CheckCircleIcon` | `assessment` | `ChartBarIcon` |
| `summarize` | `DocumentTextIcon` | `approval` | `HandThumbUpIcon` |
| `speed` | `ChartBarSquareIcon` | `analytics` | `PresentationChartLineIcon` |
| `shield_moon` | `ShieldExclamationIcon` | `admin_panel_settings` | `Cog6ToothIcon` |
| `shield` | `ShieldCheckIcon` | `rate_review` | `ChatBubbleLeftRightIcon` |
| `radar` | `SignalIcon` | `psychology` | `SparklesIcon` |
| `linear_scale` | `AdjustmentsVerticalIcon` | `home` | `HomeIcon` |
| `health_and_safety` | `LifebuoyIcon` | `healing` | `WrenchScrewdriverIcon` |
| `handshake` | `UserGroupIcon` | | |

(Duplicates by design: `verified_user`/`shield` share `ShieldCheckIcon`; `task_alt`/`check_circle` share `CheckCircleIcon`. Two *sidebar* siblings never share a glyph: `Risk Register`=ChartBar vs `KRI Monitoring`=ChartBarSquare, `Control Library`=ShieldCheck vs `Business Continuity`=Lifebuoy, `Workflows`=ArrowPathRoundedSquare vs `Scoping`=Share — checked against `NavPresenter.php:73–368`.)

**Mapping — common action names**

| Material | Heroicon | Material | Heroicon |
|---|---|---|---|
| `add` | `PlusIcon` | `check_circle` | `CheckCircleIcon` |
| `save` | `ArrowDownOnSquareIcon` | `check` | `CheckIcon` |
| `edit` | `PencilSquareIcon` | `arrow_back` | `ArrowLeftIcon` |
| `add_circle` | `PlusCircleIcon` | `more_vert` | `EllipsisVerticalIcon` |
| `download` | `ArrowDownTrayIcon` | `lock` | `LockClosedIcon` |
| `delete` | `TrashIcon` | `list` | `ListBulletIcon` |
| `close` | `XMarkIcon` | `expand_more` | `ChevronDownIcon` |
| `history` | `ClockIcon` | `menu` | `Bars3Icon` |
| `play_arrow` | `PlayIcon` | `notifications` | `BellIcon` |
| `info` | `InformationCircleIcon` | `keyboard_double_arrow_left` | `ChevronDoubleLeftIcon` |
| `send` | `PaperAirplaneIcon` | `logout` | `ArrowRightOnRectangleIcon` |
| `search` | `MagnifyingGlassIcon` | `person` | `UserIcon` |
| `link` | `LinkIcon` | `warning` | `ExclamationTriangleIcon` |
| `visibility` | `EyeIcon` | `print` | `PrinterIcon` |
| `chevron_right` | `ChevronRightIcon` | | |

**Additional names the shell/G6–G8 need (add to the map)**: `chevron_left`→`ChevronLeftIcon`, `keyboard_double_arrow_right`→`ChevronDoubleRightIcon`, `cancel`→`XCircleIcon`, `done_all`→`CheckCircleIcon`, `notifications_off`→`BellSlashIcon`, `refresh`→`ArrowPathIcon`, `error_outline`→`ExclamationCircleIcon`, `zoom_in`→`MagnifyingGlassPlusIcon`, `topic`→`FolderIcon`, `expand_less`→`ChevronUpIcon`, `progress_activity`→`ArrowPathIcon` (spinning; mapped in the icon-sweep follow-up). Anything not in the three tables stays Material.

**States / a11y**: decorative by default; `label` for the rare icon-only graphic with no wrapping control (prefer naming the control). Forced-colors: icons use `currentColor`, so they follow `CanvasText`. RTL: no mirroring needed (no directional icon is used for meaning besides chevrons, which the shell does not flip).

**Switch-over scope for this PR**: `L` (sidebar, bar, user menu), `SearchBox`, `PeriodSelector`, `PageHeader` breadcrumb chevron, `KpiCard`, `Widget` (menu/lock/error glyphs; keep the `progress_activity` spinner), Notifications page, and `packages/ui` shared components that render a Material span (`grep -rn "material-symbols-outlined" packages/ui/src`). Module pages are the booked follow-up.

**Low bandwidth**: SVG paths ship inside the JS bundle (≈ 60 icons ≈ 25 KB gzipped worst case, measure with `vite build --report`; if > 30 KB gzipped, split the map into a lazy chunk **except** the 39 nav names + shell icons, which must be in the entry chunk so the sidebar never paints without icons).

### G6 / G7 — `KpiCard` and Widget chrome

**`KpiCard` (G6)** — API unchanged: `{ title, value, icon, color='primary', subtitle, unavailable=false, unavailableLabel='Not assessed' }`. Reference: `IA/resources/js/Components/StatCard.jsx:11–32` and `.stat-card` (`IA/css:52–54`; riskerm `app.css:113`).

Markup:

```
<div class="stat-card">                                   // white, rounded-xl, border-gray-100, shadow-sm, p-5, hover:shadow-md
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <p class="text-sm font-medium text-gray-500">{title}</p>
      {unavailable
        ? <p class="text-lg font-semibold italic text-gray-500 mt-1">{unavailableLabel}</p>
        : <p class="text-2xl font-bold mt-1 text-[var(--color-text-primary)]">{value}</p>}
      {subtitle && <p class="text-xs text-gray-500 mt-1">{subtitle}</p>}
    </div>
    {icon && <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 {tile}"><Icon name={icon} class="w-5 h-5"/></div>}
  </div>
</div>
```

| Decision | Spec |
|---|---|
| Value colour | The reference value is always `--color-text-primary`; riskerm's per-colour value tint (`VALUE_COLORS`) is **dropped**. Semantic colour now lives only in the icon tile. Where the value itself must carry a status (breach/overdue), pass it in `subtitle`, not by recolouring the number — and never by colour alone (status wording present). |
| Unavailable | `unavailable` renders the label (not `—`, not `0`) in gray-500 italic; tile switches to `bg-gray-50 text-gray-400`. Contrast gray-500 on white ≈ 4.8:1 ✓ (old `text-gray-400` ≈ 2.5:1 failed). `unavailableLabel` default unchanged. A zero **value** still renders `0`; absence never renders as `0`. |
| `icon` prop | Still a Material name string (back-compat); resolved through `<Icon>` so mapped names become heroicons. If a caller passes a React node (reference style) render it as-is inside the tile. No `icon` → no tile (title block takes full width). |
| Long text | `title` `truncate`; `value` `truncate` with `title={String(value)}`; `subtitle` clamps at 2 lines (`line-clamp-2`). |
| a11y | Card is a non-interactive `<div>`; the tile is `aria-hidden`; reading order title → value → subtitle matches visual. Wrap in `role="group" aria-label="{title}"` only if the grid lacks other context (not by default). |

**Colour → tile mapping** (existing keys keep working; reference keys added):

| `color` | Tile classes | Notes |
|---|---|---|
| `primary` *(default)* | `bg-blue-50 text-blue-600` | old default `primary` ⇒ the reference's `blue` |
| `info` | `bg-blue-50 text-blue-600` | |
| `blue` | `bg-blue-50 text-blue-600` | reference |
| `danger` | `bg-red-50 text-red-600` | |
| `red` | `bg-red-50 text-red-600` | reference |
| `warning` | `bg-yellow-50 text-yellow-600` | §1.2 lists yellow-50/600 for the warning tile; reference's `amber` kept as an alias → `bg-amber-50 text-amber-600` |
| `amber` | `bg-amber-50 text-amber-600` | reference |
| `success` | `bg-green-50 text-green-600` | |
| `green` | `bg-green-50 text-green-600` | reference |
| `purple` | `bg-purple-50 text-purple-600` | reference |
| `teal` | `bg-teal-50 text-teal-600` | reference |
| unknown | falls back to the `primary` row | |

Tile text/icon contrast: `-600` on `-50` ≥ 4.5:1 for all rows except yellow (`#CA8A04` on `#FEFCE8` ≈ 2.9:1) — it is a **non-text graphic** (needs 3:1): bump the yellow row to `text-yellow-700` (≈ 4.6:1) and note it as the one deliberate deviation from §1.2.

**Blade `.kpi-card`** (`app.css:857`, used by `resources/views/components/kpi-card.blade.php`): restyle in CSS only to the same box — `border: 1px solid #F3F4F6; border-radius: 12px; box-shadow: 0 1px 2px rgba(0,0,0,.05); padding: 20px; transition: box-shadow .2s` + `:hover { box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -2px rgba(0,0,0,.1) }`; and update the Blade component to the same title/value/tile order only if it takes ≤ a dozen lines (otherwise CSS-only and a follow-up). The `.stat-card` rule is already correct; delete the "React equivalent is .stat-card" comment once `KpiCard` uses `.stat-card`.

**Grids**: no layout change to callers; document the reference grid for new code: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6` (IA Dashboard:40).

**Low bandwidth / loading**: the card has fixed tile size (40px) and `min-h` is not set, so a loading dashboard shows a skeleton of the same box: add optional `loading` prop? **No** (API frozen) — loading skeleton remains the caller's responsibility; document that `value` must never be a placeholder `0` while loading.

**Widget chrome (G7)** — `Widget.jsx`:

| Part | Change |
|---|---|
| Panel | `widget-panel flex h-full flex-col rounded-xl border border-gray-100 bg-white shadow-sm` (was `rounded-lg border-gray-200`) = `.card` |
| Header | `flex items-center justify-between gap-2 border-b border-gray-100 px-6 py-4` (= `.card-header`, IA/css:131) |
| Title | `h3 truncate text-sm font-semibold text-gray-800` (already) |
| Meta | `text-xs text-gray-500` (was `text-gray-400`, fails AA); "as at" chip stays `bg-amber-50 text-amber-700`, text raised to `text-xs` |
| Body | `p-4` → `px-6 py-4` (= `.card-body`) for text/table renderers; chart renderers keep `p-3` internal canvas padding to avoid clipping — decide per renderer, default `px-6 py-4` |
| Menu button | `p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus-visible:…`, `aria-label="Widget menu: {title}"`, `aria-haspopup="menu"`, `<Icon name="more_vert" class="w-5 h-5"/>`; loading → `<Icon name="progress_activity">` (a spinning `ArrowPathIcon`) with `motion-reduce:animate-none` and `aria-busy` on the panel |
| States | `forbidden`: `LockClosedIcon` w-8 h-8 + "You do not have permission to view this data." in `text-sm text-gray-500`; `error`/missing renderer: `ExclamationCircleIcon` + "This widget could not be rendered." + a **Retry** text button (`load()`), never a blank panel; empty data is the renderer's job and must say what is absent ("No loss events in this period"), never a bare `0` |
| Chart.js defaults (§1.6) | In `widgets/theme.js`: `font.family` per G1, tick colour `#6B7280` 12px, grid `#F3F4F6`, legend `usePointStyle:true, pointStyle:'circle', boxWidth:12`, doughnut `cutout:'62%'`, rating series `#DC2626 #EA580C #D97706 #16A34A`; set `Chart.defaults.animation = false` when `matchMedia('(prefers-reduced-motion: reduce)').matches`. Every chart keeps (or gets) a `aria-label` summary on its `<canvas role="img">` and a data table alternative where one exists. |

### G8 — Notifications page

Reference: `IA/resources/js/Pages/Notifications/Index.jsx:92–226`. Keep **riskerm's data contract exactly** (`resources/js/Pages/Notifications/Index.jsx`):

| Prop | Shape | Use |
|---|---|---|
| `notifications` | paginator `{data[], links[], from, to, total}`; row = `{id, type, subject, body, priority, is_unread, created_at, metadata?{entity_type,entity_id}}` | list + `Pagination` (with `meta` from/to/total as today) |
| `unreadCount` | int | banner, button, tab count |
| `filter` | `'all'` \| `'unread'` | active filter |
| routes | `notifications.index` (GET, `filter`), `notifications.read-all` (POST), `notifications.read` (GET link, marks read then redirects to the action URL) | unchanged |

**No new filters, no type select** (the reference's type select needs `filters.type`, which riskerm's controller does not provide). The reference's "Unread Only" toggle maps to riskerm's existing `filter` param.

| Region | Markup |
|---|---|
| Layout | Use `AppLayout`, drop the title prop usage; page content **not** wrapped in `max-w-4xl mx-auto` (reference is full width) |
| Header | `<PageHeader title="Notifications" subtitle="Approvals, decisions, and system alerts directed to you." actions={unreadCount>0 && <button class="btn-secondary inline-flex items-center gap-2 text-sm" onClick={markAllRead}><Icon name="done_all" class="w-4 h-4" strokeWidth={2}/>Mark all read</button>} />` → portals to the band (G4). The hand-built `h1` block is removed. |
| Unread banner | when `unreadCount>0`: `<div class="mb-6 flex items-center gap-3 px-4 py-3 bg-blue-50 border border-blue-100 rounded-lg" role="status"><div class="w-8 h-8 rounded-full bg-[var(--color-primary)] flex items-center justify-center" aria-hidden="true"><span class="text-white text-sm font-bold">{unreadCount}</span></div><p class="text-sm text-[var(--color-primary)] font-medium">You have {unreadCount} unread notification{s}</p></div>` (count disc is `aria-hidden`; the sentence carries it). |
| Filter bar | `<div class="filter-bar"><div class="filter-bar-inner">` one `filter-group` with `filter-label` "Show" and **two toggle links** styled as the reference's “Unread Only” button: `px-4 py-2.5 text-sm rounded-lg border transition-colors`; active = `bg-[var(--color-primary)] text-white border-[var(--color-primary)]`, idle = `bg-white text-gray-600 border-gray-200 hover:bg-gray-50`; they are `<Link aria-current={active?'true':undefined}>` for `All` and `Unread{ (N)}`. Replaces the underline-tabs row. No Reset button (two states only). |
| List | `<div class="space-y-3">` of rows; **each row is an `<a href={route('notifications.read', id)}>`** (plain anchor — server marks read and redirects, often off-SPA) wrapping `.card transition-all hover:shadow-md` (so `<a class="card block …">`): `{n.is_unread ? 'border-l-[3px] border-l-[var(--color-primary)]' : ''}`; high priority unread: `border-l-red-600` instead of primary (carries riskerm's priority semantics). Inner: `px-4 py-3 flex items-start gap-3`. |
| Icon tile | `w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5 {bg} {text}`, `<Icon class="w-[18px] h-[18px]"/>`, `aria-hidden`. Type map (existing keys): `approval_request` → `rate_review`, `bg-blue-50 text-blue-600`; `approval_approved` → `check_circle`, `bg-green-50 text-green-600`; `approval_rejected` → `cancel`, `bg-red-50 text-red-600`; default → `notifications`, `bg-gray-50 text-gray-600`. Type pill label (new, from reference): `Approval request` / `Approved` / `Rejected` / `Notification` in `inline-flex text-xs font-medium px-2 py-0.5 rounded-full {same bg/text}`. |
| Text | Title `text-sm {unread?'font-semibold text-gray-900':'font-medium text-gray-700'}` = `n.subject`; body `text-sm text-gray-500 mt-0.5 line-clamp-2` = `n.body` (**drop** `whitespace-pre-line`; the full body is on the destination); timestamp `text-xs text-gray-500 whitespace-nowrap` (reference's gray-400 ≈ 2.5:1 fails AA → gray-500); relative text from the existing `timeAgo()` with absolute `formatDateTime()` in `title` and `<time dateTime>`. Unread dot `w-2 h-2 rounded-full bg-[var(--color-primary)]` plus `<span class="sr-only">Unread</span>`. "Priority" chip kept for `priority==='high'` (`bg-red-100 text-red-700 text-xs`). `metadata.entity_type #id` line kept as `text-xs text-gray-500` after the pill. |
| Empty | Inside `.card`: `text-center py-12 text-gray-500`, `BellSlashIcon w-12 h-12 mx-auto mb-3 opacity-50` (`strokeWidth` 1), title "No notifications" (`text-sm font-medium`), sub "You're all caught up" / when `filter==='unread'`: "No unread notifications — you're all caught up" + link "Show all". Never a zero-looking blank. |
| Pagination | Unchanged: `mt-6` → `<div class="card"><Pagination …/></div>` when `links.length>3`. |
| Error / permission | Controller-level (403/500 pages) — no in-page error state; if `notifications` is missing render the empty state, don't throw. Mark-all-read failure surfaces through the existing flash (`FlashNotification`). |
| Mark all read | Destructive? No — non-destructive, no confirm; button disables (`disabled`, `aria-busy`) while the Inertia visit is in flight to prevent double-post. |
| Keyboard / SR | Rows are links in DOM order; focus ring `focus-visible:ring-2 ring-[var(--color-primary)] ring-offset-2` on the `<a>` (card radius); accessible name composes from content: ensure subject first, then "Unread" sr-only, then body, then time. Bell link (G3) lands here; focus moves to the page title region on navigation (Inertia default). |
| Low bandwidth | Server-paginated list, no images, text only; row height is content-driven with `line-clamp-2` so no layout shift; no polling on this page beyond the bell's 30 s count. |

### Implementation order and verification (for frontend-engineer)

1. G1 (fonts) → G5 (`Icon`, dependency) → G2/G3 (layout) → G4 (portal + boundary in Modal/ConfirmDialog) → G6/G7 → G8.
2. Unit/RTL tests to add: PageHeader portal cases in the table above (single, double, in-Modal, `header` prop, none → band hidden); `Icon` mapped / unmapped / `label`; `KpiCard` colour map and `unavailable`; bell label and badge cap; sidebar `aria-expanded`/`aria-current`, collapsed `aria-label`s.
3. Run `node tools/ui-audit.mjs <changed files> packages/ui/src`, `npm run ui:audit:test`, `npx vite build`; capture 1440×900 screenshots of dashboard, a register, a form, a modal, notifications; also 1024 and 390 widths for the width budget and drawer; keyboard-only pass through sidebar, bar and notifications; throttle to Slow 3G and confirm text paints in fallback font with icons present.

---

## 5. Gate record (2026-10-16)

| Gate | Cycle | Verdict | Defects |
|---|---|---|---|
| qa-engineer | 1 | FAIL | D1 Inter left in `widgets.css`; D4 redundant link titles; D5 heroicons not a `packages/ui` peer |
| qa-engineer | 2 | PASS | — |
| code-reviewer | 1 | REJECT | B1 `AssetResidencyTest` still asserted `Inter-1.woff2`; B2 KRI Thresholds Save stopped submitting (button portalled out of its `<form>`); process: PHP tests in a worktree with a symlinked `vendor` read the main checkout |
| qa-engineer | 3 | PASS | 272 PHP tests green against the worktree base path |
| code-reviewer | 2 | REJECT | the B2 guard was outside CI's audit scope and had three false negatives |
| qa-engineer | 4 | PASS | — |
| code-reviewer | 3 | **MERGE** | — |

Guard added: `ui-audit --only=pageheader-submit` over all of `resources/js` and `packages/ui/src`
in CI's `guards` job — a `<PageHeader>` whose `actions` hold a submit-capable button without
`form=` fails the build (the portal moves the header out of any enclosing `<form>`).

Booked follow-ups (advisory, no live defect): page-level Material→`<Icon>` sweep (~140 files);
brand-mark SVG; role line under the user name; `--only` with an unknown tag should exit 2 (A9);
PageHeader scan should report "not fully audited" when `tagEnd` fails (A10); reduced-motion via a
global `Chart.defaults` set once (A3); KpiCard callers that signal breach by `color` should say it
in `subtitle` (A7); `tools/ui-audit-selftest.mjs` fails from a path containing `’`
(`URL.pathname` percent-encodes it).
