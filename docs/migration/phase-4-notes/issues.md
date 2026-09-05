# Phase 4.4 — Issues

`Risk/IssueController` (822 lines, 19 methods) and six Blade views (2,237
lines) onto Inertia. `risk.issues.index` was already an Inertia grid page from
Phase 2.

## What landed

| Piece | Where |
|---|---|
| Policy | `app/Policies/IssuePolicy.php` |
| Form Requests | `app/Http/Requests/Issues/` — seven |
| Services | `app/Services/Issues/IssueDashboardService.php`, `IssueAgeingService.php` |
| Pages | `resources/js/Pages/Issues/{Dashboard,Create,Edit,Show,Ageing,Closure}.jsx`, plus `IssueForm` and `format.js` |
| Characterisation | `Characterisation/IssueDashboardFiguresTest.php` |
| Module tests | `tests/Feature/Issues/IssuePagesTest.php` |

`Ported::ROUTES` 82 → 88.

## Raising an issue without a category was a 500

`issues.issue_category` is `string(50)` **NOT NULL with no default**. The
controller validated it as `nullable|string|max:100` and `store()` passed
`null` straight through when the field was blank.

So an issue raised without a category hit a NOT NULL violation — **a 500 error,
not a validation message** — and one with a 51-character category was rejected
by the driver rather than by the form. This is the primary create path of the
module.

It is `required|string|max:50` now, matching the column.
`raising_an_issue_without_a_category_is_a_validation_error_not_a_crash` pins it.

## An overdue issue never looked overdue

`risk/issues/show.blade.php` gated three things on `$issue->is_overdue` — a
banner, a badge and the red due-date. **`is_overdue` was neither a column nor an
accessor**, so it was `null` on every issue ever rendered and none of the three
appeared. An issue could be six weeks late and its own detail page looked
ordinary.

There is now an accessor, derived from the two facts that decide it: a settled
issue is not overdue however old, and one with no due date cannot be. It is
deliberately NOT read from `issue_status = 'OVERDUE'`, which is set by a
scheduled command and lags reality by up to a day.

Two more dead reads on the same page: `cbn_regulatory_deadline` (the column is
`cbn_response_deadline` — a typo, so the CBN deadline never showed) and
`bofia_section`, which has no column at all and no data source; that block is
dropped.

## Completing a remediation action recorded no date

`completeAction()` wrote `completed_at` and `completed_by`. Neither is a column
on `issue_remediation_actions`, and neither is in its `$fillable` — so **Eloquent
dropped them silently**. The action flipped to `completed` with no record of
when, and no error anywhere.

It writes `actual_close_date`, the real column, now. **Who completed it is still
not recorded**, because there is no column for it: `verified_by` is the only
candidate and it means something else, so it is left alone rather than filled
with a claim nobody made. Flagged here rather than guessed at.

Two related shape errors corrected while porting: `issue_progress_updates` has
no `progress_pct` column (progress is a property of the ISSUE, and
`addProgressUpdate()` correctly writes `issues.progress_percentage`), and
`issue_escalation_log` records the level REACHED (`escalation_level`), not a
from/to pair.

## `DATEDIFF()` hid two screens from the test suite

`avgDaysToClose` on the dashboard and `avgClosureTime` on the closure queue both
used `AVG(DATEDIFF(closed_at, created_at))`. MySQL-only — so **both screens threw
on the SQLite the suite runs on and neither had ever been tested**.

This is the **fourth** instance of the pattern in this migration: `FIELD()` in
3.8's RCSA dashboard and 4.1's KRI dashboard, `MONTH()` in 4.3's loss-event
dashboard, `DATEDIFF()` here. It was found by the pre-port grep, which was
widened after 4.3 to cover the whole family rather than just `FIELD(`.

Both figures are computed in PHP now, over the two columns, which costs the same
one query and is portable.

## The ageing bands overlapped

A correction, not a carried figure. The dashboard counted the first band as
`created_at >= now()-30` and the second as
`whereBetween(now()-60, now()-30)` — and `whereBetween` is **inclusive at both
ends**, so an issue created exactly 30 days ago was counted in both and the four
buckets could sum to more than the population they describe.

The bands are half-open now and every open issue falls in exactly one.
`the_ageing_bands_do_not_overlap` asserts both the per-band counts and that they
sum to the population.

## Twelve COUNT queries became three

`dashboard()` ran twelve separate `COUNT` queries plus four ageing counts and
then derived several more figures from them. `IssueDashboardService` groups the
statuses in one query and the priorities in another. `ageingReport()`'s
twelve-count trend loop is two grouped queries.

## Authorization

`IssuePolicy` gives the six route permissions six abilities. **Closing is not
editing and deleting is not closing** — `issue.close` and `issue.delete` are
separate permissions the seeder issues separately, and
`closing_editing_and_deleting_are_separate` pins that they do not imply each
other.

`destroy()` carried a stale in-method check for **`issue.close`** with a message
reading "You do not have permission to delete issues", while its route required
`issue.delete`. The policy asks `issue.delete`, matching the route; the
contradictory line is gone.

Fifteen hand-written tenancy guards became `Gate::authorize` calls, and the
`EnforcesNodeScope` trait went with them — the policy resolves node scope
through the model's own `visibleTo()`. Two compound guards kept their second
half as a **404**: an action or attachment that does not belong to the issue in
the URL is a wrong address, not a permission answer.

## The forms are schema-driven, and a first attempt got that wrong

`risk/issues/{create,edit}.blade.php` rendered their whole form from
`<x-dynamic-form type="Issue">` — the field list, labels, options and
conditional rules all come from the `Issue` object type via `FormFieldRegistry`,
and `IssueController` persists tenant-added fields with
`saveConfiguredAttributes()`.

**The first version of this port wrote the fields out by hand.** That put a
second definition of "what an issue form contains" beside the registry's, and —
worse — meant a field a tenant added through the builder would render nowhere
while the controller went on saving it. It was caught by an existing test,
`DynamicRendererTest::a_conditional_field_carries_its_condition_into_the_markup`,
which asserts that the examination reference carries its "only when the source is
regulatory" rule to the client.

## Existing assertions adapted

That test asserted on the Blade page's Alpine markup (`x-show=`). It reads the
`visibleWhen` rule off the Inertia schema now. **The question it protects is
unchanged and is the whole point of the feature** — the condition reaches the
client, so the field appears the moment the source is switched, without a round
trip. Only where the answer is read from has moved.

Both pages render `DynamicForm` from `FormSchemaPresenter::form('Issue', …)`
now, with the same `sections` and `omit` the Blade components passed.
`the_forms_are_rendered_from_the_object_type` and
`the_edit_form_omits_the_recommendation` pin it.

## Deviations

**The priority × band matrix is a table, not a stacked bar chart.** It is sixteen
numbers; the table shows all sixteen at once where the chart showed four stacks
you had to hover to read. Cell shading carries the magnitude.

**`uploadAttachment` and `completeAction` keep inline validation** — a file rule
and a single optional note respectively. Neither has an `exists:` rule, so
criterion 3 is unaffected.

## PHPStan

Relations typed with generics on `Issue` (seven), `IssueRemediationAction` and
`IssueProgressUpdate` (a `createdBy` that did not exist), plus an `@property`
block for the 200038 columns and the new accessor. Baseline gains nothing.
