# Phase 4.5 — Assessment campaigns and questionnaires

`Risk/CampaignController` (411 lines) and `Risk/QuestionnaireController` (202),
eight Blade views, onto Inertia. Two commits: the guard rail and the figures
first, the screens second.

- `0396e5b` — policies, Form Requests, `CampaignDashboardService`, the
  characterisation test. Blade unchanged, every existing test still green.
- this commit — the eight views, the React pages, the Blade deleted.

## Scope check, done before any code

The phase prompt says "views `risk/campaigns/*` (6), `risk/questionnaires/*` (5)".
There are **eight**, not eleven: `campaigns/{create,dashboard,respond,show,
submission}` and `questionnaires/{create,edit,show}`. `campaigns.index`,
`questionnaires.index` and `questionnaires.library` became Inertia grids in
Phase 2 and were already in `Ported::ROUTES`.

Two of the recurring traps from `phase-4-findings` were clear here before we
started: neither controller contains MySQL-only SQL (`FIELD(|MONTH(|
DATE_FORMAT(|YEAR(|DATEDIFF(` all clean), and neither view set used
`x-dynamic-form`, so 4.4's schema-driven-form trap does not apply. The rest of
the checklist found plenty.

## The questionnaire engine had never reached a respondent

This is the module's headline defect and it is not small.

`CampaignController::respond()` has eager-loaded
`campaign.questionnaire.sections.questions` since the day it was written.
`risk/campaigns/respond.blade.php` referenced **not one line of it** — the page
built its form from the business unit's register risks and nothing else. So a
bank could build a fraud-risk questionnaire, add its sections, add its eight
question types, publish it, attach it to a campaign, and send two hundred people
to answer it; every one of them was shown a list of register risks instead.
`questionnaire_data` was accepted by `submitResponse()` and **no control on the
page could produce it** — the only writer was the RCSA worksheet screen.

The prompt asks for `Campaigns/Respond.jsx` to render the sections dynamically,
so this phase builds the path end to end:

- `Campaigns/QuestionnaireSections.jsx` renders a published questionnaire's
  sections and questions.
- `SubmitResponseRequest` accepts `questionnaire_answers` (question id → value)
  and **enforces the required flag** the builder has always been able to set. A
  page that renders a required marker it does not enforce is another screen
  manufacturing the appearance of work.
- `App\Services\Campaigns\QuestionnaireAnswerSheet` builds the stored payload;
  `submitResponse()` writes it as **one** `CampaignResponse` row with
  `risk_id = null`.
- `App\Presenters\CampaignSubmissionPresenter::answerSheet()` reads it back,
  grouped by section, on `Campaigns/Submission.jsx`.

**The stored shape, and why.** Each answer carries the question's TEXT, SECTION
and TYPE beside its value, plus the resolved option LABEL:

```php
['questionnaire_id' => 12, 'answers' => [
    ['question_id' => 3, 'section' => 'Detection',
     'question' => 'How effective is transaction monitoring?',
     'type' => 'likert', 'value' => '4', 'label' => 'Agree'],
]]
```

Questionnaires are edited between campaigns — a question is reworded, a section
dropped, a row deleted. A read-back that resolved the text from `questions` at
display time would quietly restate somebody's answer against a question they
were never asked, and `4` on its own is not an answer; `Agree` is. What was on
the screen when they answered is what the submission screen shows.

**Deviation from the prompt, recorded with its reason.** The prompt says to
"reuse the `DynamicForm` field renderers for the question types". The classes
are reused so the two look identical, but the component is not: `DynamicForm`'s
contract is `FormSchemaPresenter`'s schema, where a field is `mapped` to a
column or lands in `configured_attributes`. A questionnaire answer is neither —
it is one of eight question types with per-question `options`, stored as JSON on
a campaign response. Pushing it through would have meant inventing a fake schema
on the server and unpicking it again on submit. Same look, own renderer.

**`matrix` and `file_upload` are stated, not dropped.** Neither has ever had an
answer control anywhere in this product. The builder no longer offers them
(`QuestionnaireController::BUILDABLE_QUESTION_TYPES` — the same six the Blade
select offered), and a question of either type that already exists renders on
the respond page as a stated gap rather than vanishing. A section that silently
omitted two of its questions would produce a submission everyone believes is
complete. `AddQuestionRequest` still accepts the full enum so pre-existing rows
keep working.

## Four cross-tenant holes, each confirmed against HEAD first

A throwaway probe hit each one before a line was written. All four succeeded and
wrote their row. They are regression tests in `Campaigns/CampaignPoliciesTest`,
HTTP-level, because none of them was ever a service bug.

| Route | What resolved | What it did |
|---|---|---|
| `questionnaires.add-question` | any `{section}` id | posted a question into another bank's questionnaire |
| `questionnaires.remove-question` | any `{question}` id | deleted another bank's question outright |
| `campaigns.add-assignment` | string `exists:` on unit + user | assigned another bank's unit, answered by another bank's user |
| `campaigns.store` | **nothing** — unvalidated | mass-assigned another bank's `questionnaire_id` and `reviewer_id` |

`questionnaire_sections` and `questions` carry no `organization_id`, so neither
model is tenant-scoped and route model binding resolves any id in the table. The
fix is the same walk `CampaignController::tenantCampaignFor()` used for
assignments: go up to the owning `Questionnaire`, which IS scoped, so a foreign
parent reads back as `null` — both the tenancy check and the lookup, and it
404s.

## Policies

`AssessmentCampaignPolicy` (campaign.view/create/manage/respond/review) and
`QuestionnairePolicy` (questionnaire.view/create/edit/publish), both discovered.

**`respond` and `review` take the CAMPAIGN, not the assignment they act on.**
Laravel resolves a policy from the subject's class, so `can('respond',
$assignment)` would look for a `CampaignAssignmentPolicy`, find none, and deny
everyone silently — the bug 4.1 shipped against `MeasureBreach`.
`campaign_assignments` carries no `organization_id` either, so the campaign is
where both the tenancy check and the permission belong. The row-level rules —
which statuses may be responded to, whether a submission is waiting on review —
stay in the controller, answered with a flash message rather than a 403, as
everywhere else in this codebase.

Manage, respond and review never collapse into one another: a respondent who
could approve their own submission would not be assurance.

**Not changed, and worth knowing:** any user holding `campaign.respond` may
respond to any assignment in their organisation, not only their own. That is
long-standing behaviour and there is a real workflow behind it (the risk team
filing for a unit that will not), so it is documented rather than tightened.

## Validation gaps closed

- **`questionnaire_type`** was `required` with no `in:` against a six-value ENUM
  column — anything the form did not send was a driver error rather than a
  message. The question library's `question_type` had the same gap against a
  `string(30)`, where it was stored quietly instead: a library question typed
  `Likert Scale` renders no input at all when it reaches a questionnaire.
- **`control_effectiveness`** was `nullable|string` and the respond form offered
  **`not_applicable`** — a fifth value that exists nowhere else in this product.
  `SubmitRcsaWorksheetRequest` validates four; `RcsaService`'s effectiveness
  bands are the same four; the submission screen's label map had no entry for
  the fifth. A respondent who marked a risk N/A had the answer stored and
  displayed back as an em dash. There is one vocabulary now,
  `CampaignResponse::EFFECTIVENESS`, and the RCSA request points at it. The
  React `format.js` keeps a label for `not_applicable` — rows stored before this
  phase should read as what the respondent chose, not as a blank — but nothing
  offers it as a new choice.
- **A campaign can no longer be opened on an unpublished questionnaire.** The
  create screen has only ever listed published ones; the validator now agrees,
  so a campaign's questions cannot be rewritten underneath its respondents
  mid-flight. This is a rule the old code did not have; it is recorded here
  because it is a behaviour change, not a port.

## Dead reads, three more

- **`$library` on the questionnaire builder.** `edit()` grouped the entire
  question library by category and handed it to the view on every load; the
  template never referenced it. The library has its own screen and there has
  never been a "copy from library" control here to feed.
- **`$controls` on the respond page.** `respond()` loaded every control of the
  business unit and the Blade page referenced none. `campaign_responses.
  control_id` exists and nothing has ever set it from this screen.
- **`$response->control?->control_title`** on the submission screen. There is no
  `control_title` column on `controls` and never was — the column is `name` —
  and the read sat behind a `?? '—'`, so a line filed against a control printed
  a dash for its name on every tenant, forever, and nothing failed. Same family
  as 3.8's seven RCSA columns and 4.1's four KRI properties. **PHPStan found
  this one** the moment the module's relations were typed, which is a cheaper
  technique than the greps and worth reaching for first next time.

## The figure that disagrees with itself

`CampaignDashboardService::stats()` carries the four tiles across unchanged,
including one inconsistency, pinned so that resolving it is a deliberate act:

> The **Pending Review** tile counts assignments whose status is `submitted`
> only. The progress bar directly beneath it is drawn from
> `AssessmentCampaign::AWAITING_REVIEW_STATUSES`, which is `['submitted',
> 'under_review']`. On a campaign holding both, the bar says two are awaiting
> review and the tile counts one of them.

Nothing in the product ever writes `under_review` to `campaign_assignments` —
the enum permits it, no code path sets it — so the disagreement is dormant
rather than live, and which of the two is right is a product call. Same
treatment as 4.3's CBN threshold: documented, not silently aligned.

## Two other screens that rendered no control for what the code supports

- **The Reject button posted no `reviewer_notes`.** The controller has always
  accepted the field and the rejection notification has always quoted it back to
  the respondent — but the campaign page's reject form sent nothing, so the only
  screen most reviewers used could not attach a reason. Both ported pages offer
  the field. Whether a rejection *without* a reason should be refused outright
  is a policy call for the product and is left open.
- **The Blade `show` template queried `BusinessUnit` and `User` inside itself** —
  `\App\Models\BusinessUnit::where(...)->get()` in the middle of the markup.
  A query the controller could not see, scope or test. Both are props now.

## One bug this port introduced, and caught

Both review panels first read the pressed button out of form state:
`setData('action', 'reject')` and then `post(...)` in the same handler.
`setData` is asynchronous, so the post carried the *previous* value — and the
initial value is `'approve'`. **Return for rework would have approved the
submission it was meant to return.** Passing `{ data: {...} }` in the post
options does not rescue it either: Inertia's `router.post(url, data, options)`
assigns `data` after spreading `options`, so an `options.data` is discarded.

The intent lives in a `useRef` now and reaches the payload through
`transform()`, which is exactly what `Rcsa/Worksheet.jsx` does with its Save
Draft / Submit pair, and for the same reason.

**Nothing in the suite could have caught this**: the server-side reject path is
tested (`BreachAndCampaignAlertsTest`), and it was never wrong — the bug was
entirely in what the browser would have sent, and there is no JS test runner in
this repo. Worth remembering for any ported screen with two submit buttons: the
Blade original used two `<form>`s with a hidden input, which cannot race, and
that safety is lost the moment the pair becomes one React form.

## Tests re-pointed

`RcsaWorksheetSubmissionTest` asserted on the submission and campaign screens'
BLADE markup (`assertSee` for the respondent's own answers; `assertSee('awaiting
review')`). Both now assert the Inertia props that render those words — same
question, same values — as 3.8 and 4.4 did for theirs.

## Numbers

`Ported::ROUTES` 88 → **96**. Suite green. PHPStan back to the six errors that
are red at HEAD; typing the seven models' relations made eight baseline entries
stale, all removed, nothing added.
