# Phase 5.6 — Risk Intelligence screens

Three views onto Inertia, seven literals into config, and **no defects** — the
first module in this phase to have none, because an earlier work package had
already been through it.

## What was already done, and what these pages had to preserve

`AiIntelligenceController` used to generate user-facing figures from a random
number generator at five call sites and read seven more from a service that did
the same. The Predictive screen carried a model-accuracy panel — accuracy 87.3,
precision 84.1, recall 89.7, F1 86.8, AUC 0.912, version "2.4.1" — every number
a constant. The Radar was a hardcoded array of eight emerging risks with
invented confidence percentages, identical for every tenant, and a
`sources_scanned: 47` that scanned nothing. Benchmarking drew peer values from
`mt_rand(2, 5)` against a fourteen-bank peer group that did not exist, and was
removed outright rather than disabled.

All of that is recorded in `docs/ai-number-provenance.md`, which maps every
surviving figure to the query behind it.

**The port keeps every prop name the Blade views used**, so that document still
points at the right figures without being rewritten. That was the constraint
worth designing to, and it is the reason the pages are hand-drawn SVG rather
than a chart library:

- **A month with no assessment stays a gap.** The forecast chart splits each
  series into contiguous runs, so a null is a hole. A default line chart
  interpolates, which would turn "nobody assessed anything in March" into a
  plotted value — precisely the class of invention the work package removed.
- **The projection is a range with its basis stated, not a confidence band.**
  Drawn from the fit's standard error of prediction, with no percentage
  attached, because a percentage asserts distributional properties nobody has
  verified.
- **The radar's axes are fixed at 1-5**, not auto-fitted. Proximity and velocity
  are ordinal scores; an axis scaled to the data would imply a continuous
  measure and make two registers incomparable.

The blue explanatory bands on all three screens are carried across verbatim.
They are not decoration — they are each screen saying what it does and does not
do ("It does not scan external sources, and it shows nothing your team has not
recorded"), which is the honest counterweight to a section still called Risk
Intelligence.

## The LLM budgets: seven literals, not the prompt's two

The prompt asks for `max_tokens 1200, timeout 90` at L179 and L283 to move into
`config/services.php`. There are **seven** such pairs in `AiToolsController`:
600/60 at four call sites, 1200/90 at two, 900/90 at one.

Moving only the two named would have left five behind and made the config look
authoritative when it was not. They are all in `services.llm.budgets`, keyed by
the SHAPE of the answer being asked for — `short`, `medium`, `narrative`,
`long` — rather than by the method asking, so a tool returning truncated JSON
gets its budget raised in one place next to the others it should be compared
against.

`AiToolsController::budget()` falls back to the `short` budget and then to a
hardcoded pair, so a missing config key degrades rather than throws.

## Gating

Unchanged and verified: all three routes sit behind `feature:ai_intelligence`
**and** `permission:ai.view`, and they **404** rather than 403 when the flag is
off — a 403 would confirm the screen exists.
`RiskIntelligenceGateTest` covers both directions and passes against the React
pages without modification, which is the useful signal: the gate is on the
route, not in the view.

## Numbers

`AiIntelligenceController` 179 → 197 lines (the radar's register is presented
for the page rather than serialised whole). `AiToolsController` 672 → 690, all
of it the budget helper and its note. `resources/views/risk/ai/` is gone.

With this, `resources/views/risk/` contains only `dashboard.blade.php` — the
Command Centre, which is criterion 7's remaining work — and
`workflows/designer.blade.php`, which Phase 6 owns.
