# P7 — RBAC, audit and scoping

Section 11. The phase that fills in the seam every phase before it left open.

## What landed

| Piece | Where |
|---|---|
| The scope | `app/Support/Rcsa/RcsaScope.php` |
| Assignments | `business_unit_user` + backfill, `2026_09_07_100006` |
| Audit recorder | `app/Services/Rcsa/RcsaAuditRecorder.php` |
| Read-only audit view | `Rcsa\AuditController`, `RcsaAudit/Show.jsx` |
| Cycle due dates | `app/Console/Commands/CheckRcsaCycleDeadlines.php` (08:40 daily) |
| Morph aliases | eight RCSA models added to `App\Support\MorphTypes` |
| Permissions | `rcsa_scope.all_units`, `rcsa_scope.assign` |
| Tests | `BusinessUnitScopeTest` (14), `AuditTrailTest` (10), `CycleDeadlineTest` (8) — 32 |

## Scoping (§11)

> A risk champion in Retail Operations must not be able to read Treasury's
> assessment, or export it.

**One class answers the question, and the whole module asks it.** P1–P6 each left
a seam — `reachable()` on three policies, `reachableUnitIds()` on the export
service, an unscoped `lines()` on the dashboard — and every one now calls
`RcsaScope::unitIdsFor()`. That is what deferring it bought: scoping implemented
five times is scoping that disagrees with itself five ways, and the
disagreements are invisible until somebody exports what they cannot see on
screen.

**`users.business_unit_id` could not do it.** That column says where somebody
works, which is the right shape for one fact about a person and the wrong shape
for authority: an ORM analyst covers six units, a regional head owns a subtree,
and a champion covering two branches over a holiday is an ordinary Tuesday. §11
says "assignments", plural. `business_unit_user` is the pivot; the old column
stays exactly what it was and is what the migration backfills from.

**A general table, wired only into RCSA.** Nothing about "this user covers this
unit" is specific to self-assessment, so the table is not prefixed `rcsa_`. The
enforcement is, and lives in one class.

**Null means "every unit" and only a permission produces it.** `rcsa_scope
.all_units` is the Head of ORM, the CRO and Internal Audit — §11's "read-only,
full estate". A user with no assignments and no permission gets an EMPTY LIST,
not null. Those two are the same query shape with opposite meanings, and
conflating them is how an RBAC system fails open on exactly the accounts nobody
configured. `a_user_with_no_assignments_sees_nothing_rather_than_everything`
pins it.

**Which is why the migration must not blind a live tenant**: every user's home
unit is copied in as their first assignment, and the second-line and executive
roles are granted `all_units` — the authority they already exercised, now
written down. A deployment narrows from there deliberately rather than
discovering on Monday that the bank sees nothing.

**An empty screen has to say why.** `describe()` returns a sentence, and four
screens render it. An empty table because you are assigned to nothing is
indistinguishable from an empty table because the bank has no risks, and the
second reading gets filed as a bug every time.

**Descendants are expanded on read, not stored.** An assignment says "Retail,
and everything under it"; what is under Retail changes whenever somebody adds a
branch. A stored expansion would go stale silently, which on an authorisation
boundary means a new branch is either invisible to its own head or visible to
everybody. The walk is guarded against a cycle in the tree.

### The acceptance criterion

> *Write a test suite that attempts cross-business-unit access on every route as
> each role and asserts a 403.*

`every_route_that_takes_an_assessment_refuses_another_unit` walks the actual
route table rather than a hand-written list, so a route added in P8 that forgets
to authorise fails here rather than in production. It asserts a floor on the
number of routes it matched, because a filter that stops matching would
otherwise pass by checking nothing.

The other half matters as much: **the lists must not show what the policies
refuse.** A row somebody can see but not open is still a disclosure — the risk
statement, the unit and the residual level are all on the index screen.
Assessments, the universe, the cycle tracker, the review queue, the action-plan
register, the dashboards and the export are each asserted separately.

## Audit (§11)

**`spatie/laravel-activitylog` was not installed, and P7 did not install it.**
The plan names it; the house already has something strictly better.
`risk_audit_trail` is hash-chained (each row commits to its predecessor),
append-only at the model layer *and* enforced by database triggers a
query-builder update cannot bypass, and verified by an existing `audit:verify`
command. Activitylog is a plain table with none of that.

Adding it anyway would give an auditor two trails to reconcile, two retention
policies and one immutability guarantee where they would reasonably assume two.
§11's actual requirement — *"audit views are read-only and non-deletable"* — is
met by the table this writes to and would **not** be met by the one the plan
names. `an_rcsa_audit_row_cannot_be_edited_or_deleted` proves the difference,
and it had to drop the database trigger to write the tampering test at all,
which is the guarantee demonstrating itself.

**Two writes on purpose.** `rcsa_line_revisions` and
`rcsa_assessment_transitions` answer "what happened to this line"; the trail
answers "what did this person do on Tuesday", correlated by request id with
every other module. Collapsing them would mean losing the line's own history or
putting RCSA-shaped JSON in a column every other module reads as a scalar.

**Columns U, V and W were recorded nowhere before P7.** `rcsa_line_revisions` is
keyed by LINE, and action plans are a child table, so a plan's owner or date
changing left no trace. An implementation date moving is the single most
audit-worthy event in a remediation register — it is how a programme comes to
report nothing overdue without anything having been delivered.

**Q and S joined `MATERIAL_FIELDS`.** §11 names them and P3 omitted them because
they are calculated. In the common case the J/K/O change that caused the
movement sits beside them; they earn their place in the uncommon one, where a
residual moves because the methodology was re-versioned and no answer the
assessor gave changed at all.

**The audit screen shows the seal.** A trail displayed without saying whether it
still verifies presents tampered rows as fact.

## What P7 found

**Validation ran before authorisation on the review decisions.** A reviewer with
no authority over a unit was answered with a 302 and "you forgot the reason"
rather than a 403 — refused either way, but told the wrong thing, and the route
walk could not tell the two apart. `ReviewDecisionRequest::authorize()` now does
the real check. Authority first, then the payload.

**A parent cycle in `business_units` takes the application down.**
`BusinessUnit` uses `HasObjectIdentity`, which projects into the object graph
along its `parent` edge on save; a cycle makes that recurse until memory is
exhausted. Found because a scoping test tried to create one. **This is a real
pre-existing defect and P7 did not fix it** — it is in the graph projection, not
in scoping. The test writes its malformed tree through the query builder to get
past it, and says so.

**The RCSA models were not in the morph map**, so any audit row written against
one would have been unreadable by the relationship that fetches it — the exact
bug that made `Risk::auditTrail()` return nothing for years.

## Carried into P8

- **`rcsa_scope.assign` has no screen yet.** The permission exists and the table
  exists; assignments are made by migration backfill or by hand. An admin UI for
  it is small and belongs wherever user administration lives, not in RCSA.
- **The dashboards and the export are scoped; the METHODOLOGY screens are not**,
  because a methodology has no business unit. That is correct, not an omission.
- **§14 Q8 is now answerable.** `assigned_to` on `rcsa_assessments` is still
  null, but `business_unit_user` is the model an assignment screen would read.
- **The PHPStan baseline is stale by 19 errors at P5's commit** and 20 with
  P6/P7 — all of them `getOrganizationIdColumn()` on `BelongsToOrganization`,
  one occurrence per model using the trait. Every new model adds one. Fixing the
  trait's annotation would clear all twenty at once and is worth doing before
  the baseline is driven to zero.
