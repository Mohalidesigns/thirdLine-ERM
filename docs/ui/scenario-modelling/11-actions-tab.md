# 11. Scenario workspace — Actions & Gaps tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=actions` · **Route:** `GET /risk/scenarios/{scenario}?tab=actions` (`scenario.show`) · **Write:** `POST /risk/scenarios/{scenario}/actions`, `PUT /risk/scenarios/{scenario}/actions/{action}`, `DELETE /risk/scenarios/{scenario}/actions/{action}` (`permission:scenario.manage_actions`) · **Phase:** 3

Read `01-workspace-shell.md` first.

**Authority:** ADR 0027 Amendment 3 — §A3.3 (`sm_scenario_actions`) and §A3.4 (enterprise Issue promotion). Ebola reference deck slides 7–8 (Possible Risk & Resolution Plan) and slides 10–11 (Immediate Plan).

---

## 1. Purpose and the user

**Who.** Risk managers, BCM coordinators, and department heads responsible for closing vulnerabilities identified during scenario stress-testing.

**In the first ten seconds.** Clear split between:
1. **Risk & Resolution Plan (Gap Remediation Actions):** Identified organizational gaps, current control baseline ("where we are"), and target action points ("where we ought to be").
2. **Immediate Action Roadmap:** Time-horizon actions ("Today", "Within 7 days", "Within 30 days") with designated owners and proof of closure.
3. Summary stats: Total Actions, Completed, Overdue, In Progress, Promoted to Enterprise Issues.

**Must be able to.** Add, edit, and delete actions; filter by kind or horizon; update action statuses (`not_started`, `in_progress`, `completed`); record proof of closure; promote key gap remediation actions to enterprise `Issue` tracking with a single click.

---

## 2. Props this tab expects

Sent under `actions` when `tab === 'actions'`:

```ts
actions: {
  summary: {
    total: number,
    completed: number,
    in_progress: number,
    not_started: number,
    overdue: number,
    promoted_issues: number
  },
  items: [
    {
      id: number,
      kind: 'gap_remediation' | 'immediate',
      time_horizon: 'today' | 'within_7_days' | 'within_30_days' | 'custom' | null,
      time_horizon_label: string|null,
      risk_area: string,
      current_position: string|null,
      identified_gap: string|null,
      action_plan: string,
      business_unit_id: number|null,
      business_unit_name: string|null,
      department: string|null,
      owner_id: number|null,
      owner_name: string|null,
      due_date: string|null, // 'YYYY-MM-DD'
      status: 'not_started' | 'in_progress' | 'completed' | 'overdue',
      status_label: string,
      completed_at: string|null,
      proof_of_closure: string|null,
      issue_id: number|null,
      issue_reference: string|null,
      issue_url: string|null,
      sort_order: number
    }
  ],
  owners: [ { id: number, name: string } ],
  businessUnits: [ { id: number, name: string } ],
  canManageActions: boolean
}
```

---

## 3. Key Interactions

- **Add Action Modal / Inline Form:** Add either a Gap Remediation action (specifying Risk Area, Current Position, Identified Gap, Action Plan, Target Date, Owner) or an Immediate Action (specifying Horizon: Today / 7 Days / 30 Days, Description, Owner, Proof of Closure).
- **Status Change:** Quick dropdown or button to change status. Marking `completed` records `completed_at` and requires or suggests proof of closure.
- **Enterprise Issue Promotion:** Button "Promote to Enterprise Issue" (active only when `issue_id === null` and user has `scenario.manage_actions`). Opens confirmation dialog, creates issue, and renders clickable issue reference tag.

