# RCSA v2 — administrator's guide

For whoever configures the module, opens the cycles and answers the questions a
risk champion cannot. The champion's own guide is [one page](user-guide.md); this
is everything behind it.

---

## Turning it on

`FEATURE_RCSA_V2=true`, then `php artisan config:clear`.

**Per environment.** It says the module exists in this deployment, not that any
particular bank has adopted it. Cutting a tenant off the legacy module is a
separate, per-tenant act — see the [cutover runbook](cutover-runbook.md).

Default is off. A fresh install, including production, never exposes the surface
by accident.

---

## Who can do what

Permissions are `resource.verb` in two segments. The ones that decide the shape
of the process, rather than merely who sees a screen:

| Permission | Why it is separate |
|---|---|
| `rcsa_cycle.open` | Opening a cycle copies the whole published universe into an assessment for every unit and **cannot be undone**. Split from `manage` so a coordinator can draft the quarter while the Head of ORM decides when it starts. |
| `rcsa_assessment.submit` | Submission locks every line and hands the work to the second line. |
| `rcsa_assessment.review` / `.validate` / `.return` | Three authorities, not one. An analyst may challenge every line without being the person who accepts the assessment or sends it back. |
| `rcsa_actionplan.close` vs `.verify` | The owner claims a control is in place; the second line accepts that it is. One person doing both is how a remediation register comes to be 100% complete and empty of controls. |
| `rcsa_export.bulk` | A completed RCSA is the bank's operational risk profile in one file. |
| `rcsa_audit.view` | Everybody's export history and the tamper-evident trail — an administrator's control, not a reporting one. |
| `rcsa_scope.all_units` | See every business unit rather than only your own. |

**The module enforces two-person rules that permissions alone cannot.** Whoever
submitted an assessment cannot review it; whoever approves cannot be the
submitter; whoever claims an action plan complete cannot verify it. Granting one
person every permission does not defeat these.

### Business-unit scoping

Assignments live in `business_unit_user`. A user sees the units they are assigned
to, plus everything beneath them in the hierarchy.

**A user with no assignments sees nothing** — not everything. That is deliberate:
the opposite default fails open on exactly the accounts nobody configured. The
screens say so rather than showing an empty table.

`rcsa_scope.all_units` is the escape hatch, and it is what the Head of ORM, the
CRO and Internal Audit hold.

> On upgrade, every user was assigned their `users.business_unit_id` and the
> second-line roles were granted `all_units`. Narrow from there deliberately.

---

## The methodology

Seeded from the `SB_RCSA Template 2026` workbook: five likelihood levels, five
impact levels with a 30-cell criteria matrix, four control-effectiveness bands
and five risk bands with a treatment and an appetite sentence each.

**A methodology locks the moment a cycle opens against it.** That is what makes a
closed 2026 assessment reproducible in 2027 — its lines are scored against the
2026 bands whatever is active later.

Three settings are configuration rather than code, and the bank should confirm
each before the first real cycle:

| Column | Seeded | What it decides |
|---|---|---|
| `residual_mode` | `calculated` | Whether the assessor may state a residual themselves, or it is always derived. |
| `residual_floor` | `0` | Whether a Fully Achieved control can take a residual to zero. **It can, as seeded** — that is template parity, and it means a 25-score risk can read VERY LOW. |
| `appetite_ceiling_level` | `low` | The highest band still inside appetite. It, not the band's sentence, is what the submission gate enforces. |

---

## Per-tenant settings

In `organizations.settings['rcsa']`:

- **`bu_approval_required`** — inserts the business-unit head between the
  champion and the ORM. Off by default. With it on, a submission lands in
  `bu_approval` and the unit's `head_id` is notified; a unit with no head set
  waits visibly rather than being forwarded as though approved.
- **`cutover_at`** — set by `rcsa:cutover`. See the runbook.

---

## Scheduled work

| Command | When | What |
|---|---|---|
| `rcsa:check-cycle-deadlines` | 08:40 daily | Reminds unfinished units at T-14, T-7, T-0, then weekly once overdue. |
| `rcsa:check-action-plans` | 08:45 daily | Marks overdue plans and reminds owners at T-14, T-7, T-0. |

Both take `--dry-run`. Both are safe to run twice: reminders fire on exact
milestones, so a second run in a day duplicates a message rather than producing
a wrong one.

**Neither will do anything if the scheduler is not running.** `php artisan
schedule:work`, or a cron entry calling `schedule:run` every minute. An overdue
register nobody is told about is the failure mode this module was built to
prevent.

---

## Deployment notes

**Turn on gzip.** A 2,000-line workspace is 3.2 MB uncompressed and 70 KB
gzipped — a 45× difference on exactly the branch connections the offline
round-trip exists to work around. Compression is the single highest-value thing
in this section.

**Large exports need a queue worker.** Over 2,000 rows an export becomes a
queued job delivering a signed link that lasts 48 hours. Without a worker
draining the queue the user is told their file is coming and it never arrives.

**Storage.** Snapshots, exports and uploads all go to the private disk under
`storage/app/private/rcsa/`. Nothing is web-served. Migration reports land in
`rcsa/migration/<org>/` and are worth keeping — they are the evidence of what
moved at cutover.

---

## When somebody says it is broken

| Symptom | Look here first |
|---|---|
| "The screen is empty" | Are they assigned to a business unit? The screen should say. |
| "Unauthorized action" on a link they can see | Nav and route guards disagreeing — this is what `NavigationPermissionGateTest` pins. |
| "I can't edit rows on a returned assessment" | Correct, unless the ORM flagged them. Only flagged rows reopen. |
| "The export is empty" | Their filters, then their scope. An export is scoped to the units they may see. |
| "My submission vanished" | RCSA → My Assessments; the status says who holds it. |
| A 419 on the login page | An expired CSRF token — see [login-419.md](login-419.md). |
| A super-admin refused a new screen | See [super-admin-403.md](super-admin-403.md). Every module adding permissions must resync that role. |

---

## Where the numbers come from

Nothing on a dashboard is estimated. Every figure is a count or an average over
`rcsa_assessment_lines`, computed once in `RcsaCalculationService` and stored —
the client recomputes the same figures locally only so a badge repaints in the
same frame, and what it sends is discarded.

**One caveat worth knowing before a Board pack quotes it.** The residual heat map
has no likelihood/impact pair of its own in calculated mode, so the residual view
keeps the likelihood axis and moves the impact axis to what the residual score
implies. It is stated on the screen and in `RcsaDashboardService`.
