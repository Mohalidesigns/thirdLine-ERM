# Phase 5 criterion 7 — the Command Centre

`risk/dashboard.blade.php`, 892 lines with seven inline Chart.js constructions
— the last Blade view in `resources/views/risk/` and the product's landing
page.

## What the criterion asked, and what was built

> `risk/dashboard.blade.php` (Command Centre) ported to `Pages/Dashboard.jsx`
> composed of `Widget`s from the seeded `erm-hq` dashboard — the seven inline
> Chart.js constructions (L538-891) are deleted, not ported.

The Chart.js is gone; `grep -rn "new Chart" resources/views/risk/` returns
nothing, and `resources/views/risk/` now contains only `workflows/`, which
Phase 6 owns.

**The widget composition is partial, and that was a deliberate call put to the
user before it was made.**

`erm-hq` carries **seven** widgets: four KPI tiles (`wg-risks-active`,
`wg-risks-critical`, `wg-issues-open`, `wg-treatments-overdue`), the residual
heat map, the rating donut and the risk register. The Command Centre has
**eight sections**.

The whole widget catalogue is thirty types, and it contains **no KRI widget, no
appetite widget, no activity feed and no regulatory-review widget** — so a
literal reading deletes four sections from the landing page: KRI status and
breached indicators, appetite position, the activity feed, and the regulatory
summary (CBN-reportable losses, regulatory issues, reviews due and overdue).

Three options were put to the user: compose literally and lose those sections;
build four new widget types first and compose purely; or render `erm-hq`'s
widgets for what they cover and keep the rest. **The third was chosen.** So:

- the seeded dashboard's widgets render through the same
  `DashboardResolver` → `WidgetPayloadPresenter` → `WidgetGrid` path Business HQ
  uses, resolved server-side so the page paints with its numbers on first load;
- the sections no widget covers keep their own figures, now in
  `CommandCentreService`, and draw with the house SVG components.

`resolveFor(null, $user)` is what gets `erm-hq`: it is the type-agnostic
dashboard, the same fallback every node without one of its own lands on.
`WidgetContext::for($user)` takes a null node, which is right here — the
Command Centre is the organisation's view, not a node's.

**A tenant whose dashboard has not been published gets an empty layout**, and
the page falls back to rendering its own heat map and rating chart rather than
showing a hole. `the_widget_props_are_always_present` pins that both props are
always arrays.

## The fourth 0% CAR

The capital tile was:

```php
$carPercentage = $latestIcaap
    ? number_format((float) ($latestIcaap->car_actual ?? 0), 1)
    : null;
```

`car_actual` is **nullable** — a preparer records the balance sheet before
typing a ratio — and `(float) null` is `0.0`. An ICAAP assessment on file
without one therefore put **"0.0%"** on the landing page of a bank's risk
platform.

This is the **fourth** place this same defect has been found and fixed:

| Where | Found in |
|---|---|
| ICAAP screen | WP-08 |
| Quantification dashboard | Phase 5.2 |
| Board pack | Phase 5.4 |
| Command Centre | here |

Each was an independent copy of the same reasoning — read `car_actual`, coerce,
print. All four now go through `IcaapService::capitalRatioPercent()`, which
computes from stored capital and RWA and keeps the preparer's typed figure as a
stated fallback. **That is the durable fix**: four screens can no longer
disagree about a bank's CAR, and there is one place to change if the definition
ever does.

## Numbers

`DashboardController` 454 → 149 lines, of which the arithmetic is none: the
figures are `CommandCentreService`'s and the widgets are the engine's. The
five phpstan-baseline entries for the controller moved with the code rather
than being regenerated — all five are analyser limitations (Eloquent accessors
and `selectRaw` aliases), not defects.

`CommandCentreTest` is seven tests on a page that had none.

## The guard test the port broke, and why the fix is stricter

`AdminNavigationTest` — one of the programme's five guard tests — went red on
eight cases the moment this page flipped. It used `/risk/dashboard` as its
sidebar sample and asserted the raw HTML contained `href="..."` for each admin
link. That page is Inertia now, so its sidebar is React and the links live in
the shared `navigation` prop rather than in the server's HTML: the assertion was
reading a document that no longer contains them.

Reading the prop instead is not just a repair, it is the better test. The prop
is the actual contract between `NavPresenter` and the layout, and it cannot
pass because a link happens to appear in some unrelated markup on the page —
which is what an `assertSee('href="...")` over a whole document can do.

Worth remembering for the phases that follow: **a test that greps rendered HTML
for a link is coupled to the renderer, not to the navigation.** Phase 6 removes
the Blade sidebar entirely, and any other test written this way will fail the
same way.

**A third guard caught a real regression, not just a stale assertion.**
`PeriodSelectorTest` asserted the dashboard says *"Risk scores shown as at
&lt;period&gt;"* and *"remain current state"* when a closed period is selected.
The first port of this page dropped that banner entirely — and it is not
decoration: when a closed period is selected, the active risk count, heat map
and rating mix read as at that period while issues, treatments, loss trends and
KRI panels stay current state. Without the banner a reader compares an historic
risk count against a live KRI count with nothing telling them the two are dated
differently.

The banner is restored verbatim, including its link back to the current period.
The test now asserts the `asOfPeriod` prop — the contract between the server and
the page — rather than the rendered sentence.

A second guard caught the flip and was right to: `AuthPagesTest` asserted
`assertFalse(Ported::isPath('/risk/dashboard'))` under the note *"the dashboard
is Blade until Phase 4"*. That is the guard working — a stale claim about which
renderer serves a page cannot sit quietly in the suite. It now asserts the
opposite, and the `wire:navigate` branch is exercised against
`risk.workflows.edit-definition`, a route that genuinely is still Blade, so the
assertion bites rather than passing on any unported path.
