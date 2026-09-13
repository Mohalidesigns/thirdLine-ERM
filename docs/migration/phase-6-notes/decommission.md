# Phase 6.8 — Decommission

`livewire/livewire` is gone from `composer.json`. What remains under
`resources/views` is `app.blade.php`, the PDF templates, one mailable and the
vendor pagination views — nothing else.

The deletion list was the easy half. Three things on it were load-bearing, and
the phase prompt's instruction to delete them would have taken working code
with them.

## The schema engine was wearing a Blade costume

`app/View/Components/{DynamicForm,DynamicDetail}.php` were on the deletion
list. `FormSchemaPresenter` — which builds the field schema for **every**
React create, edit and detail page — does not re-derive that schema. It
instantiates the two view components and reads the answers out, deliberately:

> This is a projection of the two Blade view components, not a second
> implementation of them. […] A rule enforced in the Blade renderer and
> re-derived here would be two rules.

That was the right call in Phase 2, when Blade was the other renderer. It
inverts here: the renderer is being deleted and the presenter is the only
caller left. Only `render()` was ever about Blade.

So they moved rather than died — `App\Services\Metadata\ObjectFormSchema` and
`ObjectDetailSchema`, minus `render()`. Deleting them as instructed would have
broken every dynamic form in the product.

`DynamicForm::visibleToUser()` had the same shape as a smaller problem. It is
the role/permission gate for tenant-added fields, and three Inertia classes
called it as a static on a view component: `FormSchemaPresenter` (what the form
offers), `ValidatesConfiguredAttributes` (what the validator accepts) and
`PersistsConfiguredAttributes` (what is written). It is
`ObjectAttribute::visibleToCurrentUser()` now, which is where a question about
an attribute and the current user belongs. The Livewire component turned out to
hold a **second, independent copy** of the same rule in a private method — two
implementations of one security check, which is the argument for the move
restated.

## A tenant could tick "unique" and nothing happened

`is_unique` was enforced in exactly one place: the Livewire component's private
`enforceUniqueness()`. `ObjectAttribute::validationRules()` — what every Inertia
screen has used since Phase 3.2 — has never produced a uniqueness rule of any
kind.

So the builder has offered a checkbox that did nothing on the screens people
actually use, for five phases. Fail-open, unlike the phase's other findings:
duplicates were written and nothing complained. Deleting the Livewire component
would have removed the last place the box meant anything, and the defect would
have been "fixed" by making it uniform.

It is `App\Rules\UniqueConfiguredAttribute` now, applied by both rule-builders —
the Form Request trait and the persister — because a controller can reach the
persister without a Form Request, and a rule enforced in one of two passes is
not enforced. Two details worth keeping:

- It is scoped to the type being **edited**, not to `$attribute->object_type_id`.
  An inherited attribute belongs to the parent type, and a value on an
  Opportunity does not collide with one on a Risk. Using the attribute's own
  type would have scanned the wrong records for every inherited field.
- It compares as text. What arrives from a form is a string; what is stored has
  been through `castConfiguredValue()` and may be an int. A strict comparison
  would report `"3"` and `3` as different and let the duplicate through — the
  exact failure the rule exists to prevent.

Tenancy needs no assertion here: `GraphObject` carries the global scope, so the
scan cannot see another tenant's values.

## The write path's only test was a Livewire test

`tests/Feature/Graph/DynamicFormTest.php` was 11 tests pinning WP-03 TASK 7 —
a tenant-added attribute renders, validates server-side, rejects an
out-of-enum value, persists, versions, money typed in naira and stored in kobo,
formula displayed but never accepted, role-restricted field absent, inherited
attribute, unique refuses a duplicate. On the Inertia side,
`FormSchemaPresenterTest` and `DynamicRendererTest` cover the **schema** half
only. The write half was pinned nowhere else, so `git rm app/Livewire` would
have silently deleted the coverage along with the code.

They are `tests/Feature/Metadata/ConfiguredAttributeWriteTest.php` now, against
`GET risk/register/{register}/edit` and
`PATCH risk/register/{register}/attributes`. Not a translation: the Livewire
component owned rendering, validation and persistence together, and the Inertia
replacement splits them across three classes that have to agree — so the
offered-schema half and the accepted-value half sit in one file deliberately.

Writing them corrected two of my own assumptions, both times in the code's
favour:

- **A formula attribute IS offered**, as a read-only box with its expression.
  The Blade form dropped formula fields, having nowhere to put an input that
  must not post; the React form shows them. The rule that matters —
  displayed, never accepted — is unchanged.
- **Posting a formula value is DROPPED, not refused.** Formula attributes are
  filtered out before any rule is built, so there is no rule to fail. The
  `prohibited` rule in `validationRules()` is the belt to that filter's braces.
  The Livewire test asserted the same thing (`assertHasNoErrors`, then null);
  I had assumed a 422 and was wrong.

Three more tests asserted against rendered Blade partials that this phase
deletes. Each was re-pointed at the rule it was actually about rather than
weakened: label-not-code and PII-marking now assert on
`FormSchemaPresenter::detail()`, and the no-object-identity degradation case
asserts the schema resolves to nothing **and** the presenter returns no
sections.

## Branding, and why it is a class

`app.blade.php` included `layouts/partials/branding.blade.php`, so deleting
`resources/views/layouts/**` wholesale would have taken per-tenant theming off
the Inertia shell. Inlining it was the obvious fix and the wrong one: the
partial is an `@php` block, and acceptance criterion 1 forbids `@php` outside
the PDF templates and mailables — rightly, since logic in the root view is
logic no test can reach.

It is `App\Support\Branding::styleTag()`. The hex allowlist matters: the value
is tenant-controlled and it is interpolated into a `<style>` block. Escaping is
not the control, because a valid CSS colour that escapes cleanly can still
carry a payload; the allowlist is. `AssetResidencyTest` already pinned both
halves and still does.

## The CSP is strict, and unconditionally so

`script-src` is `'self'` alone — the Phase 0 TODO, closed.

`legacyScriptSources()` returned the two `unsafe-*` sources only while
`class_exists(Livewire\Livewire::class)`, so the policy tightened itself the
moment Composer dropped the package and no edit was needed. That conditional
was right while the removal was pending and wrong afterwards: it meant anything
pulling Livewire back in — a transitive dependency included — would silently
reopen `script-src` on a live deployment. A control that switches itself off
when conditions change is not a control. It is unconditional now, and
`SecurityHeadersTest::script_src_carries_no_unsafe_source` asserts the policy
directly rather than branching on what happens to be installed.

`style-src` keeps `'unsafe-inline'`: Tailwind and the branding block above.

## NoFabricatedNumbersTest covers React — as well as Blade, not instead

The prompt said this test's scope "now covers `resources/js/**` instead of
Blade". It covers both, deliberately. The PDF templates are still Blade, and
the board pack is exactly where the August 2026 audit found `?? 15.2` rendered
as a green capital-adequacy tile. Dropping the Blade scan would have retired
the rule from the one surface that has actually shipped the defect.

The new React rule found **eight** non-zero numeric fallbacks in the whole
tree, none of them a metric: six layout coordinates and two defaults in
editable inputs, which is the JSX equivalent of the Blade rule's `old(`
exclusion. They are allowlisted by line CONTENT rather than line number — as
`FINANCIAL_CONSTANT_ALLOWLIST` already is — because a number-keyed entry
silently stops matching when the file above it grows, turning an allowlist into
a hole nobody notices.

The rule was verified by planting `{data.capitalAdequacy ?? 15.2}` in the tree
and watching it fail, then removing it. A guard that has never been seen to
catch anything is a guard nobody should trust.

The Blade allowlist is empty now: both entries were the deleted designer's node
coordinates. `app/Livewire` left `SEARCH_PATHS`, and `app/Presenters` joined
it — presenters shape figures for pages, which is what the list is for.

## Two prompt instructions that were already moot

- **`npm uninstall alpinejs`** — Alpine is not in `package.json` at all. It
  arrives inside Livewire's bundle (`livewire.esm`), which is why
  `resources/js/app.js` imported `{ Livewire, Alpine }` from vendor: one Alpine,
  started by Livewire. Removing the Composer package removed it.
- **GridStack stays**, as the prompt says — `DashboardBuilder.jsx` still uses it.

`resources/js/widgets/{charts,index,builder}.js` were also on the list and had
already gone in Phase 2; what is there is `chartConfigs.js`, `renderers/` and
`theme.js`, all still used.

## Two things the full suite found that nothing else would have

**Compiled Blade outlives the package that compiled it.** Three
`DocumentOutputTest` failures reported `Class
"Livewire\Mechanisms\ExtendBlade\ExtendBlade" not found` while rendering
`reports/pdf/board-pack.blade.php` — a template that contains no Livewire and
never did. Livewire hooks the Blade compiler, so every `@foreach` compiled while
it was installed carries `ExtendBlade::isRenderingLivewireComponent()` into the
cached PHP. The cache in `storage/framework/views` was compiled before the
uninstall; the source was fine and the artefact was not.

`php artisan view:clear` fixes it, and `scripts/deploy.sh` is already safe —
`view:cache` calls `view:clear` before compiling. The exposure is a **local
checkout crossing this commit**, which will render PDFs against a stale cache
until it is cleared. Worth knowing because the error names a class in a file
that has nothing to do with the problem.

**A preflight allowlist entry outlived its route.** `Preflight.php` allowlisted
`livewire/upload-file` and `livewire/preview-file/{filename}` as routes that
carry no permission because something else authorizes them. Both are now
unregistered, and an allowlist entry naming a route that does not exist is worse
than useless: it is a name sitting ready to silently excuse whatever later claims
that URI. Both entries are gone.

The test that pinned them (`the_livewire_upload_endpoint_requires_a_session`)
was the third kind of red guard — subject retired, bug now impossible rather
than merely absent. It asserts the stronger fact instead of being deleted: no
`livewire/*` route is registered, the endpoint 404s, and preflight allowlists
nothing on its behalf. Deleting it would have left the allowlist entries
unexamined, which is exactly how they would have survived.

## `Ported` has done its job — retire it in Phase 7

`App\Support\Migration\Ported` exists because two renderers could not link to
each other: an Inertia `<Link>` to a Blade page showed the HTML in an error
modal, and a Blade `wire:navigate` to an Inertia page swapped in a document
whose React bundle never booted. Its own docblock said it "shrinks to nothing
worth keeping when the Blade side is gone".

That is now. `NavPresenter`'s `inertia` flag is true for every navigable route,
so the layout's `<Link>`-or-`<a>` branch has one live arm.

It is **not** retired here, deliberately. Doing it means deleting the prop, the
layout branch and the assertions in ten test files in one go — including
`AuthPagesTest::every_ported_route_exists_and_the_nav_marks_exactly_those_as_inertia`,
which is a real guard while the list still means something. That is a coherent
change and a bad thing to bury in the commit that removes Livewire. Both
docblocks now state the current truth rather than the old "until Phase 6" one,
so nothing misinforms in the meantime.

## Criterion 3's stragglers, for Phase 7

Every controller in `app/Http/Controllers/Admin/` has a Policy and Form
Requests. The repo-wide half of the criterion — `grep -rn "validate(\["
app/Http/Controllers` returns nothing — is not met, and the prompt says Phase 7
backfills the stragglers and this note lists them. 38 call sites in 21 files:

| Count | File |
|---|---|
| 6 | `Risk/DashboardBuilderController.php` |
| 6 | `Risk/AiToolsController.php` |
| 3 | `Risk/GridController.php` |
| 2 | `Risk/RiskAssessmentController.php` |
| 2 | `Risk/ReportController.php` |
| 2 | `Risk/IssueController.php` |
| 2 | `LicenseController.php` |
| 2 | `Admin/ScoringProfileController.php` |
| 1 each | `Risk/{Widget,RegulatoryCompliance,Period,LossEvent,DataImport}Controller.php`, `Auth/{Sso,PasswordResetLink,Password,NewPassword,MfaVerify,MfaSetup}Controller.php`, `Api/V1/{MeasureSeries,Graph}Controller.php` |

Two observations for whoever picks this up. The `Auth/*` six are single-field
validations on unauthenticated routes (`email`, `code`, `password`) where a
Form Request buys little beyond uniformity. The two in
`Admin/ScoringProfileController` are the exception worth doing first: they are
in an admin controller that otherwise has Form Requests, so they are
inconsistency rather than backlog — 6.4 left them because they validate the
formula-validation endpoint's ad-hoc payload rather than a persisted record.

## Numbers

- Full suite **1974 passed / 4 skipped** (14601 assertions), PHPStan at the six pre-existing.
- `composer show livewire/livewire` exits non-zero.
- `grep -rn "wire:\|@livewire\|<livewire\|x-data\|@php" resources/views` matches
  only under `reports/pdf/**` and `emails/**`.
- `npm run build`: one entry, `resources/js/app.jsx`. **gzip 126.7 kB**, against
  Phase 0's `100 kB (app.js) + 136 kB (app.jsx)` — the Livewire/Alpine/Chart.js
  entry is gone outright and the React entry is 9 kB smaller.
- `schema:audit-deprecated` exits 0. `app:preflight` exits 1 on `APP_ENV=local`
  alone, which is a property of a development checkout and not of this phase;
  asset residency, route authorization and the audit trail all PASS.
