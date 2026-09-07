# P1 — the RCSA Universe screen

The governed inventory a cycle is populated from: the index, the three-step Add
Risk panel with Save & Add Another, publish/retire, duplicate and bulk edit.

## What landed

| Piece | Where |
|---|---|
| Models | `app/Models/Rcsa/{RcsaRegisterRisk,RcsaRegisterControl,RcsaSystem}.php` |
| Sub-process relation | `app/Models/BusinessProcess.php` — `parent()`, `children()`, `scopeTopLevel()` |
| Policy | `app/Policies/Rcsa/RcsaRegisterRiskPolicy.php` (discovered) |
| Service | `app/Services/Rcsa/RcsaUniverseService.php` |
| Form Requests | `app/Http/Requests/Rcsa/{Store,Update,BulkUpdate,Duplicate}RegisterRisk*.php`, `StoreUniverseProcessRequest.php` |
| Controller | `app/Http/Controllers/Rcsa/UniverseController.php` |
| Routes | `routes/web.php` — nine, under `rcsa.universe.*`, behind `feature:rcsa_v2` |
| Permissions | `app/Authorization/RiskPermissionCatalog.php` + `2026_09_06_100006_grant_rcsa_universe_permissions_to_roles.php` |
| Pages | `resources/js/Pages/RcsaUniverse/{Index,AddRiskPanel}.jsx` |
| Navigation | `NavPresenter` — RCSA → Universe, gated on the flag |
| Registry | `Ported::ROUTES` gains `rcsa.universe.index` |
| Tests | `tests/Feature/Rcsa/Universe{TestCase,PageTest,WritesTest,AuthorizationTest,FeatureFlagTest}.php` — 38 tests |

## Acceptance criteria

> *A user can enter 20 risks with controls without leaving the keyboard; only
> published rows are visible to cycles.*

The second half is pinned by
`publishing_records_who_and_when_and_only_published_rows_are_assessable`, which
asserts `RcsaRegisterRisk::assessable()` returns published rows only. That scope
is the single enforcement point for rule 1 of the process flow.

The first half was verified by driving the real screen (below). The loop is:
Business Unit → Tab → Process → step 2 → type the risk → pick a category →
Ctrl+Enter. Placement stays, the risk clears, focus returns to Potential Risk.
Escape closes. The two things that are NOT keyboard-only are the systems tag
row and the control repeater's "+ Add another control" — both reachable by Tab,
both requiring Space rather than typing.

## What driving the real screen found, that 38 passing tests did not

**Every save failed, silently.** The Add Risk panel seeds one empty control row
so there is something to type into. `controls.*.description` is `required`, so
an untouched row failed validation on every submission — and the error rendered
against a field on the *Controls* step, which the user is not looking at when
they press Save on the *Risk* step. The button appeared to do nothing at all.

This is the "screen that saves nothing" defect family from the migration
programme's pre-flight checklist, arriving from the client side rather than the
server side. Every server-side test passed throughout, because they post a
correct payload; the form in front of the endpoint was the broken part.

Fixed in three places, deliberately:

1. The panel filters blank control rows out with `form.transform()` before
   sending — the row the user is looking at stays on screen.
2. `StoreRegisterRiskRequest::prepareForValidation()` drops them server-side
   too, so a client that forgets cannot bring the form to a halt again. A row
   with a type or an owner but no description is NOT blank — that is a
   half-filled control and a genuine error.
3. The panel now renders an error summary at the top of the body and jumps to
   the step carrying the first failure. A form whose fields are spread over
   three steps needs this whatever the validation rules are.

`an_untouched_control_row_does_not_block_the_save` and
`a_half_filled_control_row_is_still_an_error` pin the server half. **Nothing in
this repository can pin the client half** — there is no JavaScript test runner.

Two more, both real:

- **`window.prompt` is not available in every browser.** It was used for inline
  process creation and for choosing a duplicate's destination; it threw
  outright in the preview browser. Both are now in-page: an inline input under
  the process select (which is what §6.2 asks for — the user does not leave the
  form), and a destination bar above the table that shows unit *names* rather
  than asking someone to type a database id.
- **`published` and `retired` rendered identically to `draft`.** The shared
  `StatusBadge` had no entry for either, so both fell through to the draft
  styling — on the screen whose entire point is that distinction. Two keys added
  to the shared component rather than forking it; the map is additive, so no
  existing consumer changes.

## Deviations from the plan

### 1. The shared components are not the three the prompt names

> *Reuse the existing PageHeader, FilterBar and DataTable components — do not
> fork them.*

`PageHeader`, `FilterBar` and `Pagination` are used as written. `DataTable` is
not, and neither does the pattern source use it: ThirdLine's own Audit Universe
writes `<table className="data-table">` directly. Two things here need markup
`DataTable` cannot express — a selection column driving the bulk-edit bar, and
an expanding detail row. It is the same shell either way: `data-table`, `card`
and the `filter-*` classes are the shared styling.

### 2. Permissions are `rcsa_universe.*`, not `rcsa.universe.*`

Permission naming in this product is `resource.verb` in **two** segments
(migration Decision 2); `control_test.view` is the precedent for a compound
resource. The plan's three-segment `rcsa.universe.view` would have been the only
one of its shape in a catalog of 130.

### 3. The plan's seven roles are not created

§11 suggests Risk Champion, BU Head, ORM Analyst, Head of ORM, CRO, Internal
Audit, System Administrator. This product has nine roles already, and inventing
seven more is a governance change, not a screen. The grants map onto what exists:

| Plan role | Here | Gets |
|---|---|---|
| Head of ORM | `risk-manager` | everything, including publish and import |
| Risk Champion | `risk-owner` | view, create, update — **not** publish |
| ORM Analyst | `risk-analyst` | view |
| — | `compliance-officer` | view |

Whether the universe is ORM-only or BU-proposes/ORM-approves is §14 Q8 and is
still open. The grant above is the second reading, which is the safer default:
it can be widened without having to re-approve data that went live unreviewed.

### 4. Business-unit scoping is not enforced yet

§11 requires that a risk champion in Retail cannot read Treasury's rows. The
plan schedules that for **P7**, along with the rest of the permission matrix,
and that is where it stays. What P1 enforces is the permission and the tenant
boundary — `another_tenants_risks_are_not_listed`,
`another_tenants_risk_is_not_found_rather_than_forbidden`,
`bulk_edit_refuses_another_tenants_ids` and
`publishing_another_tenants_id_changes_nothing`.

`RcsaRegisterRiskPolicy::reachable()` is the single seam P7 changes; every
ability already routes through it. Note that `users.business_unit_id` exists but
holds **one** unit, and the plan's requirement is a set of assignments — reading
the single column as if it were that set would hide a risk manager's own estate
the moment anyone filled the field in.

### 5. Download Template and Bulk Upload are rendered disabled

They are the P2 deliverable. They are rendered in the order §6.1 fixes, and
disabled with a title saying why — the alternative was a header two buttons
short whose shape changes again next phase.

## Decisions worth knowing

- **A published risk is retired, not deleted.** Assessment lines point back at
  the register row; deleting would leave a closed cycle unable to say where its
  lines came from. The controller answers a delete on a published row with a
  flash explaining that, and the screen offers Retire in its place.
- **A duplicate always arrives as a draft** with a fresh number for its
  destination unit. A copy that arrived published would enter the next cycle
  without anyone approving it.
- **A row with no controls is publishable.** Blocking it would push users to
  invent a placeholder control to get past the gate; the assessment is exactly
  where that gap is meant to be found and rated Not Achieved. The index shows
  the count as an amber "None" instead.
- **Risk numbering counts from the highest used, including soft-deleted rows.**
  A unit with R1, R2, R3 that deletes R2 has two rows and a highest of 3;
  counting rows would generate R3 and collide. A soft-deleted number is not
  reissued — the unique index still holds it, and an audit entry naming R3 must
  mean one thing. Generation races retry three times before surfacing.
- **Editing a published row bumps `version`; editing a draft does not.** Only
  the fields an assessment snapshots count as material.
- **Bulk edit is an allow-list of three fields in two places** — the Form
  Request and the service. `bulk_edit_changes_only_owner_category_and_status`
  posts a `potential_risk` alongside and asserts it was ignored.
- **`row_hash` is computed over resolved IDs**, not names as §5.2 writes it.
  Renaming a process would otherwise make every risk under it look new to the
  next upload, and two units may run processes with the same name. Both sides
  still compute the same value for the same row, which is the only property the
  hash needs.

## Verification

- `php artisan test --filter=Universe` — 38 passed, 277 assertions.
- Full suite: **2,063 passed, 4 skipped** (2,017 before this phase).
- One guard test went red and was **bookkeeping**, not a regression:
  `NavigationPermissionGateTest::every_route_in_the_sections_appears_exactly_once`
  hard-codes the number of routes the navigation links, 78 → 79. Worth noting
  which assertions in that file stayed green, because they are the substantive
  ones: `every_entrys_permission_equals_its_route_guard` confirms the nav item's
  `rcsa_universe.view` matches the route's middleware, and
  `every_entrys_feature_flag_equals_its_routes_feature_middleware` confirms its
  `rcsa_v2` matches the route's. The wiring is checked; only the tally moved.
- PHPStan clean on the new paths; Pint clean.
- Migrations run on **MySQL**, not only the suite's SQLite.
- The screen was driven end to end in a browser: index renders with filters and
  pagination, row expansion shows driver/owner/controls, the panel's cascading
  process select narrows to the chosen unit, and Save & Add Another creates the
  row, keeps the placement, clears the risk and returns focus.

## What P2 needs from this

- `RcsaMethodologyTemplate::RISK_CATEGORIES` and `IMPORT_ALIASES` are the import
  vocabulary; the store request already validates against the first.
- `RcsaRegisterRisk::hashFor()` is the duplicate key. The import resolves
  business unit and process to ids first, then calls it.
- `rcsa_register_risks.source_batch_id` is written by the importer and is
  currently always null.
- The two disabled header buttons are the P2 entry points.
