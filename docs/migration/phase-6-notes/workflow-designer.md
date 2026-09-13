# Phase 6.5 — The workflow designer

`WorkflowDesigner`, 419 lines of Livewire, plus its view, its inspector partial
and a Blade shell. **The last Livewire component in the product.**

The port itself was mechanical. What it turned up was not: the round-trip test
the phase prompt asks for found **four separate places where the new Form
Request's vocabulary disagreed with the engine's** — every one of which would
have refused to save a workflow the platform itself ships.

## Four vocabularies, checked against the engine rather than against a guess

| Field | First draft | Reality | What it would have done |
|---|---|---|---|
| `assignee_rule` | `role, user, manager, owner, creator` | `user, role, group, owner, delegate, manager, relationship_traversal, expression` | `delegate` is used by three shipped processes; `creator` is not implemented by `AssigneeResolver` at all |
| `on_timeout` | `escalate, auto_approve, auto_reject, nothing` | `escalate, notify, auto_approve, auto_reject` | `notify` is used by two shipped processes; `nothing` would have escalated, so the label was a lie |
| `trigger` | `manual, on_create, on_transition, scheduled` | `manual, on_create, on_transition, on_event, on_schedule` | nothing has ever written or read `scheduled`; the command queries `on_schedule` |
| `escalation_rules.*.node` | `required` | nullable — a rule with no node applies to **every** step | would have refused every shipped process |

Each was found by `DesignerRoundTripTest`, and each is now taken from the code
that reads it: `AssigneeResolver::resolve()`'s match, `WorkflowEngine::sweep()`'s
match, `WorkflowTriggerService::fire()`'s callers and
`RunScheduledWorkflows`, and `WorkflowEngine::timeoutAction()`.

## The graph is taken whole, not from `validated()`

The worse one. `validated()` returns only the keys the rules name, and a node
carries more than this request knows about — `instructions` and a node-level
`escalate_to` among them, both of which the shipped library uses. Building the
payload from `validated()` dropped them silently, so **opening a seeded process
in the designer and pressing save would have quietly deleted parts of it.**

The rules still constrain everything they name. What passes through untouched is
the rest of the tenant's own document. The round-trip test is what catches the
next key somebody adds to a node without telling the request class.

## What the Livewire component never validated

`WorkflowDesigner::save()` validated three fields — code, name and entityType —
and posted everything else straight into the JSON columns:

- `object_type_id` had no rule at all, on a strictly tenant-scoped model.
- `entity_type` was a free string against a form offering
  `SubjectRegistry::boundTypes()`. An unbound type means the workflow can never
  start, and nothing said so until somebody tried.
- `trigger` had no rule. An unknown value simply never fires.
- `escalation_rules` were untouched end to end.
- `code` had no uniqueness check.

## Validation is on publish, not on save — and stays that way

This is the designer's central rule and the port keeps it exactly. A half-drawn
process is a normal thing to have saved. What must never happen is a half-drawn
process being the one new instances start on, so `WorkflowDefinitionValidator`
runs at publish and reports every error at once rather than one per attempt.

`SaveWorkflowDesignRequest` therefore refuses only what could not be a workflow
at all — an edge naming a step that is not on the canvas, a node of a type the
engine has never heard of. Everything else is `liveErrors`, shown on the page
while you work, so publishing is never the first time you hear about a problem.

The one place the request is stricter than the old screen is the edge-target
check, and it earns it: renaming a step without rewiring leaves edges pointing
at a step that no longer exists, and that failure surfaces as **a workflow that
silently stops**. The designer rewires on rename, as the Livewire component did;
the request is the backstop, and it names the exact edge so the page can point
at it.

## The canvas

Plain SVG, no diagram library — the prompt's preference and the house rule
every other chart here follows. Nodes carry their own x/y, so there is no layout
algorithm to disagree with: a process looks the same to the next person who
opens it, which is the whole reason position is stored at all. Dragging snaps to
the same 20px grid the Livewire handler used.

Edges are anchored on the facing sides of the boxes rather than their centres,
so a line does not run underneath the box it starts from, and each carries a
wide transparent hit-line so selecting one does not demand pixel accuracy.

## Test changes

Three Livewire-driven tests moved onto the routes. One of them changed what it
asserts, and for the better: `renaming_a_step_rewires_its_connections` checked a
component's public property, and is now
`an_edge_pointing_at_a_step_that_is_not_on_the_canvas_is_refused` — the same
guarantee, enforced by the server, with the field path in the error.

`DesignerRoundTripTest` is new and is Phase 6 acceptance criterion 4. It asserts
that a definition saved from the designer equals what `WorkflowLibrary` ships,
after normalisation — and it is precise about what normalisation forgives (node
position, key order, absent optional keys) and what it does not (every code,
type, name, assignee rule, SLA, timeout, outcome, edge, condition and **edge
order**, because an exclusive gateway takes the first satisfied branch).

It checks all ten shipped processes, not just the named one: if any of them uses
a shape the designer refuses, a customer discovers that only when they try to
save.

## After this phase

`app/Livewire/` holds one file: `DynamicForm.php`, which is a view component
rather than a builder screen and belongs to the 6.8 decommission.
