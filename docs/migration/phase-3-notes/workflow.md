# Phase 3.7 — Approvals, my-tasks, workflow instances

| Area | Delivered |
|---|---|
| Policies | `WorkflowTaskPolicy` (task.view / task.act, tenancy, then `WorkflowEngine::canAct` — the assignee / delegate / candidate-role answer the engine already gives), `WorkflowInstancePolicy` (workflow.view/act/manage + tenancy), `WorkflowDefinitionPolicy` (workflow.view/manage + tenancy), `ApprovalRequestPolicy` (approval.view/act + tenancy). All auto-discovered; the page test asserts `Gate::getPolicyFor` for each. No inline `Gate::define` belonged to this module. |
| Form Requests | `Workflow/{ActOnTaskRequest,DelegateTaskRequest,ReturnTaskRequest,ActOnInstanceRequest,StartWorkflowRequest,StoreWorkflowDefinitionRequest}`, `Approvals/{ApproveRequest,RejectRequest}`. `delegate_to`, `delegated_to` and `definition_id` are tenant-bound `Rule::exists`; `authorize()` goes through the policies (an instance `cancel` needs workflow.manage, everything else workflow.act). |
| Services | `Workflow/TaskSubjectResolver` (the record behind a task, resolved once per list; the reference/title chain every screen printed), `Workflow/WorkflowDashboardService` (the six counters, recent instances, workload with names in one query — characterised), `Workflow/StartableSubjects` (records a workflow can start over). |
| Presenter | `Presenters/WorkflowPresenter` — task rows, steps, graph steps, actions, instance summaries, definition rows, approvals, delegate options, and a paginator → `{data, links, meta}` adapter for `Components/Pagination.jsx`. |
| Controllers | `ApprovalController` (dashboard → `Approvals/Dashboard`; approve/reject through requests), `MyTaskController` (index/show → `MyTasks/{Index,Show}`; act/delegate/return through requests), `WorkflowController` (dashboard/definitions/showInstance → `Workflows/{Dashboard,Definitions,ShowInstance}`; act/start/store through requests; `Gate::authorize` on publish/unpublish/edit). The designer (`createDefinition`, `editDefinition`) still renders `risk/workflows/designer.blade.php` — Phase 6. Route names and URIs unchanged. |
| Pages | `Pages/Approvals/Dashboard.jsx` (approve/reject in `Modal`), `Pages/MyTasks/Index.jsx` (counter tiles as links, queue, pagination), `Pages/MyTasks/Show.jsx` (decision, delegate and return forms with `useForm`), `Pages/Workflows/Dashboard.jsx` (`KpiCard`s, recent activity, workload), `Pages/Workflows/Definitions.jsx` (publish, inline start form), `Pages/Workflows/ShowInstance.jsx` (steps, history, action block incl. cancel for workflow.manage). Links to the designer are plain anchors. |
| Deleted | `risk/approvals/dashboard`, `risk/my-tasks/{index,show}`, `risk/workflows/{dashboard,definitions,show-instance}` Blade views. |
| Tests | `Characterisation/WorkflowDashboardStatsTest`, `Workflow/WorkflowPagesTest` (policies discovered, queue + counters, show for holder vs non-holder, cross-tenant delegate rejected, in-tenant delegate works, dashboard/definitions/instance props, approvals dashboard + reject validation + approve, views deleted / designer kept). |

## Deviations, with reasons

- **The instance page gained "Cancel workflow"** for users with workflow.manage — the endpoint already supported `action=cancel` but no screen offered it. Confirmed with `window.confirm` (Modal's Transition fix is in 3.6; `ConfirmDialog` can replace it later).
- **Approvals dashboard hides Approve/Reject** from users without approval.act (the Blade showed the buttons and let the route 403).
- **Delegation validates the target inside the tenant and active** in the Form Request (the controller used to `findOrFail` after a string `exists:users,id`).
- **Workload names** resolved in one query instead of `User::find` per row.
- **Definitions page**: the start form still posts to `risk.workflows.start`; the "New definition" / "Edit" links stay plain anchors to the Blade designer.

## Assertions adapted

- `WorkflowScreensTest`: four `assertSee` chains (dashboard, definitions, instance with actions, instance without actions) → `assertInertia` on the same facts (`recentInstances.0.name`, `stats.overdue`, `waiting_on.0.who`, definition row flags, `steps[].task.who`, `canAct`, `actionable`). The designer test is unchanged (still Blade).
- `MyTasksTest::the_screen_lists_a_users_tasks`: `assertSee('Review')` → `tasks.data.0.name`.
