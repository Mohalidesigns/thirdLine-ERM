# Workflow engine v2 (WP-06)

One engine replaces three approval mechanisms.

| Retired | Was | Now |
| --- | --- | --- |
| `approval_requests` | generic maker-checker, one reviewer, no stages | open rows migrated to `workflow_instances` + `workflow_tasks`; decided rows kept as history; **no new writes** |
| per-module `approved_by` / `status` columns | four modules each with their own approve/reject branch in a controller | still written, by the engine through a `SubjectBinding`, for one release |
| `workflow_instances` v1 | an integer cursor into a JSON `stages` array | a set of active node codes in a graph |

## Tables

| Table | Purpose |
| --- | --- |
| `workflow_definitions` | a process at one version. `definition` holds `{nodes[], edges[]}`. Publishing writes a **new row** at version + 1 and unpublishes its predecessor. |
| `workflow_instances` | one run over one subject. `current_nodes` is an **array** — a parallel gateway puts the instance in several places at once. `definition_id` pins the version. |
| `workflow_tasks` | a decision somebody owes. The row the v1 engine did not have, and therefore the reason delegate/escalate/return could only be logged. |
| `workflow_actions` | the immutable history. `actor_id` is nullable: an SLA expiry is an action with no actor. |

Legacy `stages` and `current_stage` are retained and still maintained. The
follow-up release drops them, along with the `approval_requests` write path.

## Node types

`start · task · approval · exclusive_gateway · parallel_gateway · join · timer ·
service_task · sub_process · escalation · notification · end`

Only `task` and `approval` wait for a human. Everything else executes the moment
control reaches it, which is why `advance()` loops rather than stepping once.

## Assignee rules

| Rule | Resolves to |
| --- | --- |
| `user` | a named person |
| `role` / `group` | an offer to one or more roles, or a shortlist; first to act claims it |
| `owner` | the subject's owner, per its binding |
| `delegate` | the subject's assigned reviewer, falling back to its owner |
| `manager` | the owner of the node **above** the subject's node in the org graph. There is no `users.manager_id`, and inventing one would be a fiction nobody maintains. |
| `relationship_traversal` | the owner of whatever a named `object_relationships` edge lands on |
| `expression` | an expression returning a user id or a role name |

Rules that resolve from the record (`owner`, `delegate`, `manager`,
`relationship_traversal`) **must** name `fallback_roles`; the validator refuses
to publish without them, because a record with no owner set otherwise puts the
task on nobody's list.

## Conditions

Edges carry `when`, evaluated by `ConditionEvaluator` over
symfony/expression-language — **no `eval()`**. A workflow condition is
user-authored content on a multi-tenant platform.

    outcome == 'approve'
    get(subject, 'cost_estimate_ngn') > 50000000
    in_list(get(subject, 'residual_rating'), ['High', 'Critical'])

`subject` is frozen at start, so routing cannot change because somebody later
edited the amount. A condition that throws evaluates **false** — failing closed,
because a malformed condition that evaluated true would advance an approval
nobody granted.

An edge with no `when` is unconditional. An exclusive gateway takes the **first**
satisfied edge in document order, so the default branch belongs last; the
designer's ↑/↓ controls exist for exactly this.

## Triggers

Every value does something. None of the ten shipped processes uses anything but
`manual` (and `on_event` for threshold re-baselining), so installing WP-06
changes no existing behaviour.

| Trigger | Started by |
| --- | --- |
| `manual` | `ModuleApprovals::submit()` from a module screen |
| `on_create` | `WorkflowTriggerObserver` |
| `on_transition` | the same observer, on a change to `status` / `issue_status` / `current_status` / `lifecycle_state`; `trigger_config` names `from` / `to` |
| `on_event` | a caller naming the code |
| `on_schedule` | `workflow:run-scheduled --cadence=…` |

`scope_filter` narrows what a trigger fires over. Without it, "start on create"
means every record.

## SLA

`workflow:sweep-slas` runs **hourly** — an SLA measured in hours cannot be
enforced by a nightly job, and a loss event's level-1 decision sits inside the
CBN seven-day reporting window.

Per node, `on_timeout` is `escalate | notify | auto_approve | auto_reject`.
Absent, the definition's `escalation_rules` are consulted — the first code in the
platform that reads that column. A task is escalated **once** per level.

## Shipped definitions

`risk_assessment_approval`, `treatment_plan_approval`, `control_test_review`,
`loss_event_approval`, `issue_closure_approval`, `risk_appetite_approval`,
`risk_acceptance_approval`, `threshold_rebaselining_approval`, `policy_approval`,
`icaap_signoff`.

Installed per organization (`workflow:provision`), never as shared system rows: a
workflow is the first thing a customer edits, and a shared definition would mean
one bank's change to its escalation matrix landing in another's.

## Authorization

A decision needs **both**:

1. the task is yours — assigned by name, or offered to a role/shortlist you hold;
2. the subject's existing Gate lets you (`approve-risk-assessment`,
   `review-control-test`, `approve-loss-event`, …).

Super-admin passes every Gate but is still **not** offered a task it was not
assigned. An administrator silently recording a decision as though they were the
CRO is exactly what a board approval must never contain; the documented route is
to delegate the task first, which is recorded.

## Follow-up (next release)

- Drop `workflow_definitions.stages` and `workflow_instances.current_stage`.
- Remove the `approval_requests` fallback in `ThresholdRebaselineService::raise()`
  and the `decideDirectly()` paths in the module controllers, once every tenant
  has published the definitions.
- Remove the per-module `approved_by` / `approved_at` mirrors once the exports,
  reports and board pack read the engine instead.
