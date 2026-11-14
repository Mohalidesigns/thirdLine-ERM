# 08. Scenario workspace — Impact tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=impact` · **Route:** `GET /risk/scenarios/{scenario}?tab=impact` (`scenario.show`) · **Write:** `PUT /risk/scenarios/{scenario}/impact` (`permission:scenario.design`) · **Phase:** 2

Read `01-workspace-shell.md` first.

**Authority:** ADR 0027 Amendment 2 — §A2.4 (`sm_impact_types` & `sm_impact_criteria`) and §A2.6 (impact calculation specifications). Decoded from BIFF8 workbook `LS` sheet rows 82–91 (`PHASE0-SPIKE.md` §3).

---

## 1. Purpose and the user

**Who.** The operational risk analyst decomposing gross financial losses across multiple loss categories under three calibrated severities: **Typical**, **Serious**, and **Extreme**.

**In the first ten seconds.** What the **Total Gross Impact** is across Typical, Serious, and Extreme severities; which impact categories contribute the most; and whether loss categories are flagged as eligible for insurance transfer.

**Must be able to.** Add, configure, and remove impact types; choose the calculation template (`staff_turnover_interval` or `litigation_probability`); toggle insurance transferability; edit individual criteria parameters across Typical, Serious, and Extreme columns; and save to persist computed category totals and aggregate gross losses.

---

## 2. Props this tab expects

Sent under `impact` when `tab === 'impact'`:

```ts
impact: {
  currency: string, // e.g. "NGN"
  frequency_interval: string|null, // from frequency engine: e.g. "0.06191950" (needed for interval-based templates)
  templates: [
    {
      value: 'staff_turnover_interval',
      label: 'Staff Turnover (Count × Salary × Months × Frequency Interval)',
      description: 'Multiplies monthly cost by duration and the annual frequency interval factor.'
    },
    {
      value: 'litigation_probability',
      label: 'Litigation / Legal ((Count × Unit Amount + Fees) × Probability)',
      description: 'Sums variable and flat costs, scaled by the probability of legal action per severity.'
    }
  ],
  types: [
    {
      id: number|null,
      name: string, // e.g. "Cost of hiring and training new staff"
      aggregation_template: 'staff_turnover_interval' | 'litigation_probability',
      eligible_to_transfer: boolean,
      sort_order: number,
      totals: {
        typical: string, // e.g. "14775676.78"
        serious: string, // e.g. "22163515.17"
        extreme: string  // e.g. "44327030.34"
      },
      criteria: [
        {
          id: number|null,
          label: string, // e.g. "Monthly gross salary"
          unit: string|null, // e.g. "NGN"
          computation_role: 'count' | 'unit_amount' | 'duration' | 'probability' | 'flat_fee',
          data_source: string|null,
          typical_value: string,
          serious_value: string,
          extreme_value: string,
          sort_order: number
        }
      ]
    }
  ],
  gross_impact: {
    typical: string|null, // e.g. "15123960.76"
    serious: string|null, // e.g. "22860083.12"
    extreme: string|null  // e.g. "45371882.27"
  },
  is_read_only: boolean
}
```

**Post body (`PUT /risk/scenarios/{scenario}/impact`):**
```json
{
  "types": [
    {
      "name": "Cost of hiring and training new staff",
      "aggregation_template": "staff_turnover_interval",
      "eligible_to_transfer": false,
      "criteria": [
        {
          "label": "ID Count",
          "unit": "people",
          "computation_role": "count",
          "data_source": "HR information",
          "typical_value": "55",
          "serious_value": "55",
          "extreme_value": "55"
        },
        {
          "label": "Monthly gross salary",
          "unit": "NGN",
          "computation_role": "unit_amount",
          "data_source": "HR information",
          "typical_value": "1084669",
          "serious_value": "1084669",
          "extreme_value": "1084669"
        },
        {
          "label": "Months to hire & train",
          "unit": "months",
          "computation_role": "duration",
          "data_source": "HR information",
          "typical_value": "4",
          "serious_value": "6",
          "extreme_value": "12"
        }
      ]
    }
  ]
}
```

---

## 3. Layout and Wireframe

Container `max-w-6xl`.
1. **Gross Impact Summary Header (`h2`)**: Displays 3 severity KPI cards:
   - **Typical Gross Impact:** `NGN 15,123,960.76`
   - **Serious Gross Impact:** `NGN 22,860,083.12`
   - **Extreme Gross Impact:** `NGN 45,371,882.27`
2. **Impact Categories (`h2`)**: Collapsible cards per impact type.
   - Category header with name, template tag, transferability switch, and subtotal per severity.
   - Criteria matrix: columns for Criterion, Unit, Role, Source, Typical, Serious, Extreme.

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Gross Financial Impact                                                       │
│ Typical: NGN 15,123,960.76 │ Serious: NGN 22,860,083.12 │ Extreme: NGN 45,371,882.27│
└──────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│ [▼] 1. Cost of hiring and training new staff                                 │
│ Template: Staff Turnover │ Transferable: [No]                                │
│ Subtotals: Typical NGN 14.78M │ Serious NGN 22.16M │ Extreme NGN 44.33M      │
├───────────────────┬────────┬──────────────┬──────────┬──────────┬────────────┤
│ Criterion         │ Unit   │ Source       │ Typical  │ Serious  │ Extreme    │
├───────────────────┼────────┼──────────────┼──────────┼──────────┼────────────┤
│ ID Count          │ people │ HR info      │ 55       │ 55       │ 55         │
│ Monthly salary    │ NGN    │ HR info      │ 1,084,669│ 1,084,669│ 1,084,669  │
│ Months to train   │ months │ HR info      │ 4        │ 6        │ 12         │
└───────────────────┴────────┴──────────────┴──────────┴──────────┴────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│ [▼] 2. Cost of litigation                                                    │
│ Template: Litigation Probability │ Transferable: [No]                        │
│ Subtotals: Typical NGN 348.28K │ Serious NGN 696.57K │ Extreme NGN 1.04M     │
├──────────────────────────────────────────────────────────────────────────────┤
│ [ + Add Impact Category ]                       [ Save Impact Assessment ]   │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Interaction, Errors & States

- **Template switching:** Changing template updates required criteria roles and formula behavior.
- **Empty state:** Option to "Load standard impact templates" (Hiring/training & Litigation) matching workbook.
- **Validation:** 422 errors returned on missing criteria or non-numeric values.
- **Read-only state:** Disabled inputs and badges when scenario is locked.

