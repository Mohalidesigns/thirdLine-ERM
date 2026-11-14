# 05. Navigation entry

**Surface:** the `scenario_modelling` section of the left sidebar · **Declared in:** `App\Presenters\NavPresenter::sections()` · **Rendered by:** `packages/ui/src/Layouts/AuthenticatedLayout.jsx` from the shared `navigation` prop · **Phase:** 1

Read `01-workspace-shell.md` first for the shared rules. This is not a screen — it is the two links that get a user to one, and it is specified separately because **three guard tests fail if it is added carelessly** (§4), and because a nav entry is the only part of the module every user of the product sees whether or not they ever open it.

**Authority:** ADR 0027 Amendment 1 §A1.11 freezes this section's key, label, icon, prefix, items, permissions, feature flag and position in the list. Nothing here re-decides any of it; this file records what the frozen block renders as and what must move beside it.

---

## 1. What is declared

Verbatim from §A1.11, inserted in `NavPresenter::sections()` **after `'analysis'` and immediately before `'quantification'`**:

```php
'key' => 'scenario_modelling', 'label' => 'Scenario Modelling', 'icon' => 'timeline',
'prefixes' => ['/risk/scenarios'],
'items' => [
    ['label' => 'Scenarios', 'route' => 'scenario.index', 'permission' => 'scenario.view', 'feature' => 'scenario_modelling'],
    ['label' => 'New Scenario', 'route' => 'scenario.create', 'permission' => 'scenario.create', 'feature' => 'scenario_modelling'],
],
```

**Placement reasoning (§A1.11).** Scenario Modelling is *upstream* of Quantification (plan §2: "The new module is upstream of it, not a replacement"), so a user reading the menu top to bottom meets scenario design before capital quantification. Both sit after Risk Analysis because both are analysis-of-record rather than register entry.

**Two items because Phase 1 has two screens.** The dashboard, action tracker and library items arrive with their own phases and their own permissions (§A1.11) — they are not declared now, because `PermissionCatalogCoversRoutesTest` fails on a declared permission no role holds, and an administrator reading a role-assignment screen reads an entry as available authority.

---

## 2. How it renders

`NavPresenter::for($user)` resolves each item through `resolve()` and drops it when the route does not exist, when `config("features.{feature}")` is falsy, or when `$user->can($permission)` is false. A section whose items all drop is omitted entirely (`if ($items === []) continue;`). The emitted shape:

```
navigation.sections[] = { key, label, icon, prefixes, items: [ {key, label, icon|null, route, url, permission, inertia} ] }
```

`url` is the path only (`parse_url(route(...), PHP_URL_PATH)`), so `/risk/scenarios` and `/risk/scenarios/create`.

| State | What the sidebar shows |
|---|---|
| **Flag off** (`FEATURE_SCENARIO_MODELLING` unset or false — the default) | **No section at all.** Not greyed, not "coming soon". A disabled surface is indistinguishable from one that does not exist, which is also what the flag's `EnsureFeatureEnabled` middleware does to the routes: **404, not 403** (§A1.11) |
| **Flag on, user has `scenario.view` only** | The section with one item, **Scenarios**. "New Scenario" is absent, not disabled — `resolve()` drops it, so a user who cannot create never sees a control that would 403 |
| **Flag on, `scenario.view` + `scenario.create`** | Both items, in the declared order |
| **Flag on, neither permission** | No section (both items drop) |
| **Section active** | `prefixes` matched against the current path by `isUnder()` (`AuthenticatedLayout.jsx:112`): the section button gets `.active`, and `useEffect` force-opens it. So `/risk/scenarios`, `/risk/scenarios/create` and `/risk/scenarios/42?tab=qualitative` all highlight and expand the section — the last is why the workspace deep-link in `01` §5.1 keeps the user oriented |
| **Section inactive** | Collapsed by default, like every other section |
| **Sidebar collapsed** (icon rail) | The section renders as **one anchor to `items[0]`** with `title`/`aria-label` = the section label (`AuthenticatedLayout.jsx:118-131`). **This is why `Scenarios` must be first**: on the icon rail, clicking the Scenario Modelling icon goes to the *first* item. Were "New Scenario" declared first, every collapsed-sidebar user clicking the module icon would land in a create form |

Per §A1.10's role grants, `risk-manager` and `chief-risk-officer` see both items; `risk-analyst`, `risk-owner` and `compliance-officer` see **Scenarios only** (they hold `scenario.view` + `scenario.note`, not `scenario.create`); `board-member` sees Scenarios only; `super-admin` sees both.

Note what that produces, and it is intended: a `risk-analyst` — the role the catalog describes as "challenges every line, decides nothing" — can open a scenario and add a dissent (`scenario.note`) but cannot create or edit one. The nav is the first place that separation is visible.

---

## 3. Accessibility

The section is existing `Section` markup and needs no new work, but the module inherits its behaviour and these are the parts a reviewer will check:

- The section header is a `<button>` with `aria-expanded` and `aria-controls="nav-sec-scenario_modelling"`. The panel id derives from `key`, so **`key` must stay `scenario_modelling`** — changing it changes the id and orphans the `aria-controls`.
- The `timeline` icon is decorative (`Icon`, `aria-hidden` via the component); the section label is real text and is truncated with `truncate`, so a long label does not reflow the rail.
- On the collapsed rail the anchor carries `aria-label={section.label}` and an `sr-only` label, so it announces "Scenario Modelling" rather than nothing.
- Focus ring is `RING_DARK` on the navy sidebar; contrast is white-on-Navy (`#1A365D`), ≈12:1.
- Keyboard: the section is one Tab stop, Enter/Space toggles it, then each item is a Tab stop. Nothing new is introduced.

---

## 4. The three guards that must move with it

`tests/Feature/Inertia/NavigationPermissionGateTest.php` asserts the menu mirrors the router. Adding this section fails three assertions until they are updated, and each is load-bearing:

| Test | What breaks | What must change |
|---|---|---|
| `the_presenter_declares_exactly_the_24_sections()` | `assertSame(self::SECTIONS, array_column(NavPresenter::sections(), 'key'))` — **order-sensitive** | Add `'scenario_modelling'` to `SECTIONS` at the index matching its position between `'analysis'` and `'quantification'`; rename the method and its docblock to 25 |
| `every_route_in_the_sections_appears_exactly_once()` | `assertCount(136, $counts, 'The 24 sections link 136 distinct routes…')` | Becomes **138** (two new routes), and the message's "24 sections" becomes 25. Neither new route may appear in any other section |
| `every_entrys_permission_equals_its_routes_permission_middleware()` / `…feature_flag…` | The nav item's `permission` and `feature` must **exactly equal** the route's `permission:` and `feature:` middleware arrays | `scenario.index` must carry exactly `permission:scenario.view` + `feature:scenario_modelling`; `scenario.create` exactly `permission:scenario.create` + the same flag. A route group that adds a second `permission:` guard, or an item that omits `feature`, fails here |

Also: `every_entry_names_a_route_that_exists()` means the two routes must be registered before the nav entry is, or the suite fails — which is the failure mode `NavPresenter.php:172-175` warns about for BCMS ("a menu item pointing at a route nobody registered — a 500 on a customer's screen, found by a customer").

**Two stale counts to correct while in the file.** `NavPresenter::sections()`' own docblock says "The **22** module sections" while the test says 24 — it is already out of date, and this change makes it 25. Fixing it is one word; leaving it means the next person to add a section trusts a number that was wrong twice.

**`a_feature_flagged_section_is_withheld_while_the_flag_is_off`** already covers this section without modification: it toggles `config(['features.…'])` and asserts presence, and §2's first row is that behaviour. Recommend `qa-engineer` extend it (or add a sibling) to assert `scenario_modelling` specifically, since a default-off flag on a new module is exactly what a future refactor silently breaks.

---

## 5. Known gaps

1. **No `ModuleSections`-style single source.** BCMS builds its item list from `App\Support\Bcms\ModuleSections` because that class also registers its routes, so the menu and the router cannot diverge. Phase 1's two hand-typed items are fine and `every_entry_names_a_route_that_exists` covers them, but when Phase 2/3/5 add the dashboard, action tracker, library and admin items, consider the same pattern — four hand-typed entries across three releases is where drift starts.
2. **`prefixes` has one entry.** If the Phase-5 library or admin screens live outside `/risk/scenarios` (an `admin/…` path, say), the section will not highlight on them and `prefixes` needs a second element, as `kri_monitoring` carries `['/risk/kri', '/risk/settings/holidays']`.
3. **The section label is "Scenario Modelling" and the page `h1` on `00` is "Scenario Modelling & Analysis."** Deliberate — the sidebar has ~25 sections in a 16 rem rail and the short form is what fits — but a user searching the nav for the words on the page header will not find an exact match. Flagged in case the coordinator wants them identical; the index's `h1` is the easier of the two to shorten.
