# Phase 6.4 — Scoring profiles

`ScoringProfileBuilder`, 436 lines of Livewire, one Blade shell. The screen
where an organisation decides what a score means: matrix size, the label and
definition of every point on both axes, which dimensions are scored and how
they collapse, the rating bands, and the residual formula.

Four defects, all of one shape — a field the screen accepted and the platform
could not use.

## The residual formula was never checked

```php
'residual_formula' => 'nullable|string|max:500',
```

Any string at all. `RiskScoringService::calculateResidualScore()` evaluates it
through `FormulaEvaluator` and, when it throws:

```php
} catch (\Throwable $error) {
    logger()->warning('Scoring profile residual formula failed; using the platform default.', [...]);
}
$residual ??= $inherentScore * (1 - ($controlEffectivenessPct / 100));
```

That fallback is right — a broken expression in one tenant's configuration must
not blank out their residual column — and it is also why nobody would notice. A
typo did not break anything visibly. It silently reverted **every residual score
in the register** to the platform default, and the only trace was a line in a
log.

`FormulaEvaluator::canEvaluate()` already existed for the re-baselining job. The
request now uses it, against the same three variables the scoring service puts
in scope (`inherent`, `effectiveness`, `max_score`), and
`POST admin/builder/scoring-profiles/validate-formula` answers the same question
while the operator is still typing — with a worked example rather than a tick,
because "valid" and "does what you meant" are different claims and only one of
them is checkable.

## `applies_to_object_type_ids` had no rule at all

Not `exists:`, not anything — it was read straight off the component into the
`applies_to` blob. Another institution's object type could scope a profile: a
foreign id in the tenant's configuration, and a profile that resolves for
nothing. It uses `MetadataRules::objectType()` now, the rule Phase 6.3 wrote.

## Two profiles could share a code

`'code' => 'required|string|max:64|regex:...'` — no uniqueness check.
`ScoringProfile::resolveFor()` picks from the profiles that apply, so two with
the same code is configuration nobody can reason about. Unique per organisation
now.

## `impact_dimensions` accepted anything

`required|array|min:1` with no element rule. A dimension the platform does not
score contributes nothing and is invisible: the aggregation simply skips a
dimension it has no value for. Checked against
`ScoringProfileTemplates::DEFAULT_IMPACT_DIMENSIONS` now.

## Where the "seeded profile is undeletable" rule went

The Livewire component enforced it inline with `addError`. The first draft of
this port put it in `ScoringProfilePolicy::delete()` as
`&& ! $profile->is_system`, and a test caught that immediately:
**`Gate::before` answers every ability true for a super-admin**, so a policy
cannot express "nobody, ever". It is a data invariant now, in the controller,
the same shape as `MetadataGuard::assertTypeDeletable()` — and it holds whoever
asks.

Worth recording because the same trap applies to every `is_system` check in
this programme. The three metadata deletions Phase 6.3 ported were already safe:
MetadataGuard refuses a system type, a system relationship type and a system
lifecycle regardless of the caller. Scoring profiles were the one with no guard.

## Parity kept

The two things this screen does that a plain CRUD form would not, both intact:

**It previews the re-rating before saving.** Changing a band boundary re-rates
every risk in the register, and the operator is shown how many move and in
which direction while the change is still abandonable. The Livewire component
recomputed that on every render; the page now asks
`POST admin/builder/scoring-profiles/preview` when the bands settle — the same
guarantee, one query instead of one per keystroke.

**It refuses bands with a gap.** A score that falls into no band renders as an
empty rating, which on a dashboard is indistinguishable from "not assessed" — a
silent failure with a governance consequence. The check moved verbatim into
`StoreScoringProfileRequest`, messages and all.

Saving over the seeded profile still forks it rather than editing in place, and
still records `approved_by` — redefining what Critical means is a governance
act, so the profile records who signed it off rather than only who typed it.

## Still Livewire after this phase

`WorkflowDesigner` (6.5), and nothing else.
