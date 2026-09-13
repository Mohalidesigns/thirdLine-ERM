# P0 — foundations and the calculation engine

The schema for the whole module, the client's methodology as seeded data, and
the one service that turns likelihood, impact and control effectiveness into
everything the workbook computes.

## What landed

| Piece | Where |
|---|---|
| Feature flag | `config/features.php` → `rcsa_v2`, default off |
| Canonical methodology | `app/Support/Rcsa/RcsaMethodologyTemplate.php` |
| Schema — methodology | `2026_09_06_100001_create_rcsa_methodology_tables.php` |
| Schema — seed | `2026_09_06_100002_seed_rcsa_system_methodology.php` |
| Schema — universe | `2026_09_06_100003_create_rcsa_universe_tables.php` |
| Schema — assessment | `2026_09_06_100004_create_rcsa_assessment_tables.php` |
| Schema — import/export | `2026_09_06_100005_create_rcsa_import_export_tables.php` |
| Models | `app/Models/Rcsa/{RcsaMethodology,RcsaScaleItem,RcsaImpactCriterion,RcsaRiskBand}.php` |
| Engine | `app/Services/Rcsa/{RcsaCalculationService,RcsaResult}.php` |
| Browser mirror | `resources/js/lib/rcsa-calc.js` |
| Shared truth table | `resources/js/lib/rcsa-truth-table.json` |
| Node runner | `tests/Support/rcsa-calc-runner.mjs` |
| Tests | `tests/Unit/Rcsa/RcsaCalculationTest.php` — 29 tests, 1,018 assertions |

Sixteen tables were planned; **fifteen** were created. The sixteenth is covered
below.

## Acceptance criterion

> *A 100-case truth table (5 likelihood × 5 impact × 4 CE) passes against the
> workbook formulas, byte for byte.*

Met, and in a shape that keeps meeting it. `resources/js/lib/rcsa-truth-table.json`
holds all 100 combinations with expected outputs, and
`it_matches_the_truth_table_on_all_one_hundred_combinations` asserts every one
of eight fields per case.

**The fixture was written independently of the service**, from §3.3 of the plan,
by a throwaway generator that implements the spec on its own terms. That matters:
a fixture produced by the code under test proves only that the code is
deterministic. This one makes the test a disagreement between two readings of
the specification.

**It is not, however, extracted from the workbook.** The `.xlsx` was not
available in this session, so the numbers are a transcription of the plan's
§3.1–§3.5, which is itself a reverse-engineering of the workbook. The file is
written to be read: `_meta.formulas` states every rule in one line each.
**Diff it against the workbook before sign-off** — that is what the plan's "a
truth table I will review" asks for, and it is the one input to this phase that
has not been verified against the source.

Beyond the 100 cases, the test pins the boundaries from both sides (2/2.01,
4/4.01, 9/9.01, 16/16.01, and clamping outside 0–25), band contiguity, partial
input, out-of-range input, lenient label matching, both residual modes, the
appetite ceiling, and the seed itself.

## The browser mirror is tested, which the plan assumed was free

The plan asks for the engine mirrored in `resources/js/lib/rcsa-calc.ts` "with a
shared JSON fixture driving tests on both sides so they cannot drift". Two
things about this repository make that not quite possible as written:

1. **There is no TypeScript.** `resources/js` is `.js`/`.jsx` with a
   `jsconfig.json`; there is no `tsconfig.json` and no `.ts` file in the tree.
   The mirror is `rcsa-calc.js` with JSDoc types.
2. **There is no JavaScript test runner.** No jest, no vitest, no `npm test`
   script. "Tests on both sides" had nowhere to live.

Without a runner the mirror would be unverified — and an unverified mirror is
worse than none, because the assessor sees one residual band while typing and a
different one after the save lands, and nothing goes red.

So the PHP test runs the mirror: `the_browser_mirror_computes_what_the_server_computes`
pipes the methodology and the 100 cases into `tests/Support/rcsa-calc-runner.mjs`
under `node`, and asserts zero mismatches. It was verified to actually catch
drift by changing `modifier / 100` to `modifier / 110` in the mirror and
watching it fail.

It **skips** when `node` is absent, so a PHP-only CI image does not report a
false red — but a green run on such an image is not evidence the two agree. If
this repository ever gains a JS test runner, move the runner's assertions into
it and keep the fixture where it is.

## Deviations from the plan, and why

### 1. No `rcsa_processes` table — `business_processes` gains `parent_id`

`business_processes` is already the product's process inventory: it is in the
object graph (`ObjectSourceMap`, `ObjectTypeRegistry`, `MorphTypes`),
`risks.business_process_id` points at it, `RiskRegisterService` reads it, and
the **legacy RCSA screens read it**. A second, RCSA-only process master would
let the Process column of an RCSA export name a process the risk register has
never heard of, and the two would drift from the first day.

The one thing the table lacked is the self-reference giving Process →
Sub-Process, which is exactly what §5.2 asks for. That column was added.

Not carried across: `status` and `version` on the process. The publish gate that
matters is on the risk row — "only published universe rows are picked up when a
cycle is opened" — and a process that is live for the risk register but draft
for RCSA is not a state anyone asked for. `is_active` covers retirement.

This is the missing sixteenth table.

### 2. The methodology is its own aggregate, not a `ScoringProfile`

`scoring_profiles` was the first thing checked, and the overlap is real: it is
already versioned and per-tenant, with a likelihood scale, an impact scale,
rating bands, and a residual formula that is character-for-character
`inherent * (1 - effectiveness / 100)`.

Four things have nowhere to live on it:

1. A control-effectiveness scale with a **numeric modifier per rating**. A
   scoring profile has two scales; effectiveness reaches `RiskScoringService` as
   a bare percentage computed elsewhere.
2. Impact criteria as a **matrix** — a descriptor per (level × dimension) pair.
   `impact_dimensions` is a flat list of names; the workbook defines all thirty
   descriptors.
3. A **treatment and an appetite sentence per band**. Those are RCSA outputs
   (columns S and T), not scoring inputs.
4. The bands are a different shape: five, cut at 2/4/9/16, against the
   platform's four cut at 4/11/19. One row cannot mean both.

The overlap is documented at length in `RcsaMethodologyTemplate`'s class
docblock rather than hidden. If the two ever need to agree, the direction is to
derive a `ScoringProfile` **from** a methodology, never the reverse: the
workbook is the contract and the profile is not.

### 3. `rcsa_systems` created — there is no application register to reuse

The plan says to reuse the existing application/asset register "if present".
There is none. `entities` is the organisational hierarchy (Group → Region →
Branch) and `business_units` is the org chart. `rcsa_systems` is deliberately
minimal so that it is cheap to migrate into a real application inventory later.

### 4. The appetite ceiling is authoritative, not the band's sentence

Two editable fields say the same thing: each band carries an appetite statement
for column T, and `appetite_ceiling_level` names the highest band still inside
appetite. If a tenant raises the ceiling without rewriting five sentences, the
**obligation follows the ceiling** and the sentence stays what the user reads.
`the_seeded_ceiling_agrees_with_every_bands_appetite_sentence` pins that they do
not disagree on the seeded methodology — a screen saying "Within risk appetite"
beside a mandatory action-plan block is the failure that test exists to prevent.

## Decisions taken inside the engine

- **Rejects out-of-range ratings; does not clamp them.** This is the opposite of
  `RiskScoringService`, deliberately. That service re-rates a register whose
  rows were scored under an older matrix, so clamping preserves a rating that
  already exists. Here the input is a dropdown selection or a cell in an
  uploaded workbook: a 7 on a five-point scale is a data error, and scoring it
  as a 5 would put a number nobody chose into a regulatory return.
- **Computes what it can from partial input.** A grid autosaves a cell at a
  time. A line with a likelihood and nothing else returns nulls and
  `isComplete: false` — never a zero, because 0 bands as VERY LOW and an
  unassessed risk displaying as VERY LOW is the failure the module exists to
  prevent.
- **One rounding, after the formula and before banding**, to two decimal places,
  matching `decimal(5,2)` on the column — so the value banded is the value
  stored. Defect D3 is otherwise honoured in full: no rounding before banding.
- **The residual floor never applies to an assessed residual.** The floor guards
  against the formula producing an implausible zero; an assessor who rated the
  residual themselves has made a judgement, and raising it silently would be the
  module overruling them without saying so.
- **The floor never exceeds the inherent score.** A floor of 5 on an inherent 3
  would report a control making the risk worse.
- **`calculated` mode ignores an assessed pair entirely**, rather than honouring
  it if present, so a stale value left by a methodology change cannot quietly
  become the residual score.

## Defects from §3.6

| # | Status after P0 |
|---|---|
| D1 | `Moderate → Medium` is in `RcsaMethodologyTemplate::IMPORT_ALIASES`; the generated template is P2 |
| D2 | Pinned, not fixed. `a_fully_achieved_control_drives_residual_to_zero_by_default` asserts the workbook behaviour; `residual_floor` is the configured answer when the bank takes the decision (§14 Q3) |
| D3 | `decimal(5,2)`, banded on the raw value; boundary tests at 2.25, 4.5 and 12.5 |
| D4 | `unique(business_unit_id, risk_no)` on `rcsa_register_risks`. Generation of `{BU_CODE}-R{seq}` is P1 |
| D5 | `rcsa_action_plans` is a child table of the line. Flattening on export is P6 |
| D6 | `assessor_id`, `assessed_at`, `assessment_rationale`, `evidence_attachments` on the line; `submitted_by`, `reviewed_by` on the assessment |

## Verification

- `php artisan test --filter=RcsaCalculationTest` — 29 passed, 1,018 assertions.
- Full suite: **2,017 passed, 4 skipped** (baseline before this phase was 1,988;
  the 29 are these tests).
- `vendor/bin/phpstan analyse app/Support/Rcsa app/Models/Rcsa app/Services/Rcsa`
  — no errors, nothing added to the baseline.
- `vendor/bin/pint` — clean on every new path.

## What P1 needs from this

- `RcsaCalculationService::methodology()` resolves and eager-loads; pass the
  result down rather than resolving per line.
- `RcsaMethodology::toCalculatorPayload()` is the shape `rcsa-calc.js` expects.
  Any page that renders a live-calculating grid sends it once as a page prop.
- The register tables exist but have **no models, policies, requests or routes
  yet**. Nothing is reachable over HTTP in this phase — the flag gates nothing
  because there is nothing to gate.
