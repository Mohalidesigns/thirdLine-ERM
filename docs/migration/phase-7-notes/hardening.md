# Phase 7.3 — Hardening backfill

Acceptance criterion 3 is met: `grep -rn "validate(\[" app/Http/Controllers`
returns nothing. 38 inline validations across 21 controllers became 34 Form
Requests.

## Two of them found something

**Four AI endpoints validated `risk_id` as a bare `exists:risks,id`** — the
defect family Phase 6.3 cleared out of the Form Requests, still living in an
inline validation nobody had converted.

It was **not** a cross-tenant leak. The controller's lookup is scoped —
`Risk::where('organization_id', TenantContext::organizationId())->find(...)` —
so another organisation's id resolves to null and the posted text is used
instead. What it was is an **existence oracle**: validation passed for a risk
that exists in some other tenant and failed for one that exists nowhere, which
answers a question the caller is not entitled to ask, one id at a time. The
rule is `Rule::exists(...)->where('organization_id', ...)` now, matching every
other Form Request in the product.

**The issue attachment endpoint hand-writes its file rules** while the loss
event endpoint beside it uses a `FileUploadService` profile. That service exists
so upload points cannot drift apart — one profile per kind, holding the
extension allowlist, the MIME check and the size cap — and there is no profile
for issue attachments, so this endpoint alone accepts `zip`, `msg` and `eml` at
10 MB.

Reproduced **verbatim** in `UploadIssueAttachmentRequest`. Moving it onto a
profile changes what the endpoint accepts, and a behaviour change belongs in its
own commit with its own test rather than folded into a mechanical extraction.
Left as a finding, not fixed.

## Two mistakes I made, both caught by the suite

**A private helper shared by two endpoints.** `WidgetController::render()` held
the `validate()` call for both `payload` (JSON, for the dashboard) and `export`
(CSV, same data). Converting only `export` left `render()` calling
`$request->validated()` on a plain `Request` — 13 failures, all
`Method Illuminate\Http\Request::validated does not exist`. Both endpoints now
share one `WidgetContextRequest`, which is the right answer anyway: a rule that
applied to one and not the other would mean the spreadsheet could be asked for
something the screen could not.

**An `authorize()` naming a policy that does not exist.** I wrote
`can('update', $dashboard)` in six dashboard Form Requests. There is no
`DashboardPolicy`; the route group is guarded by `permission:dashboard.manage`.
Laravel's answer to an ability no policy defines is to deny, so every builder
endpoint returned 403 — ten failures.

This is the mirror of what 6.7 recorded: *"a policy that quietly demands more
than its route makes the route's middleware a lie."* There it was a policy
demanding more than its route; here it was a Form Request demanding a policy
that was never written. **An `authorize()` must assert what actually guards the
route** — check `ls app/Policies` before naming an ability, because the failure
is silent unless a test happens to exercise the endpoint.

## Repository hygiene

`/risk` (a 916K SQLite database), `/plans/` and `/erm-update/` were **already in
`.gitignore` and still tracked**. `.gitignore` does not untrack what is already
in the index, which is exactly why they survived every previous attempt;
`git rm --cached` is what removed them. With them went a 3.4M competitor
marketing PDF, eleven product screenshots, a spreadsheet, a `.docx`, a stray
`.xlsx.py` and a LibreOffice lock file for a spreadsheet nobody has open —
roughly 10 MB in every clone.

Everything stays on disk. The **prose** moved to `docs/history/` and is tracked
there: 20 planning documents from `plans/` and the ERM remodel master plan and
WP-00..WP-08 execution pack from `erm-update/`. Those are why the code is shaped
the way it is, and deleting them would lose the reasoning behind the schema this
migration has been porting for seven phases.

## One deployment procedure

`build-deploy.sh` packaged a cPanel zip. Nothing invoked it — no workflow, no
documentation, only its own usage comment — while
`.github/workflows/deploy.yml` had been calling `scripts/deploy.sh` all along.
Deleted, and `docs/DEPLOYMENT.md` documents what actually runs.

Two divergent procedures where only one runs is worse than one: the unused one
drifts, and it is the one somebody reads when the real deploy breaks.

## .env.example

31 product-specific keys added — `MEASURE_*`, `CBN_RATE_*`, `WEBHOOKS_*`,
`WORKFLOW_*`, `CONNECTORS_*`, the twelve missing `SSO_*` endpoints and four
remaining `LICENSE_*` tunables — each with the reasoning that decides its value
rather than a bare name.

**115 keys were "missing" and 84 of them deliberately stay missing.** They are
Laravel's own — `DB_HOST`, `REDIS_*`, `MAIL_*`, `SESSION_*`, `AWS_*`,
`BEANSTALKD_*` — which ship with sensible defaults in stock config files. Adding
them would bury the thirty-one that carry a decision among a hundred that carry
none, and `.env.example` is read by somebody configuring a deployment, not by a
completeness checker.

The one that matters most is `WEBHOOKS_ALLOW_PRIVATE_HOSTS`: true lets a
subscription point at `169.254.169.254` or an internal address, which turns the
delivery worker into a request-forgery tool holding the platform's own network
position. It is documented as false with that reason next to it.
