# 13. Scenario Action Tracker — Enterprise Actions Index

**Page:** `resources/js/Pages/Sm/Actions/Index.jsx` · **Route:** `GET /risk/scenarios/actions` (`scenario.actions.index`) · **Permission:** `scenario.view` · **Phase:** 3

**Authority:** ADR 0027 Amendment 3 — §A3.5. Plan §4.5 screen 5.

---

## 1. Purpose and the user

**Who.** Operational Risk Managers, Head of ERM, and Internal Audit tracking scenario remediation actions across all scenarios in the organization.

**In the first ten seconds.** Executive KPI cards (Total Actions, Open, In Progress, Completed, Overdue, Promoted to Issues), searchable DataGrid with multi-facet filters (Kind, Status, Owner, Scenario, Time Horizon, Due Date).

**Must be able to.** Filter and search actions; view linked scenario; update status directly; link to enterprise issue; export to CSV.

---

## 2. Props this screen expects

```ts
stats: {
  total: number,
  open: number,
  in_progress: number,
  completed: number,
  overdue: number,
  promoted_issues: number
},
actions: {
  data: [
    {
      id: number,
      scenario_id: number,
      scenario_reference: string,
      scenario_title: string,
      kind: 'gap_remediation' | 'immediate',
      time_horizon: string|null,
      risk_area: string,
      action_plan: string,
      owner_name: string|null,
      department: string|null,
      due_date: string|null,
      status: string,
      issue_id: number|null,
      issue_reference: string|null
    }
  ],
  links: [],
  meta: {}
},
filters: {
  search: string,
  kind: string,
  status: string,
  owner_id: string,
  horizon: string
},
owners: [ { id: number, name: string } ],
scenarios: [ { id: number, reference: string, title: string } ],
canManageActions: boolean
```

