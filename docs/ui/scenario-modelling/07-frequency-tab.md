# 07. Scenario workspace — Frequency tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=frequency` · **Route:** `GET /risk/scenarios/{scenario}?tab=frequency` (`scenario.show`) · **Write:** `PUT /risk/scenarios/{scenario}/frequency` (`permission:scenario.design`) · **Phase:** 2

Read `01-workspace-shell.md` first.

**Authority:** ADR 0027 Amendment 2 — §A2.3 (`sm_frequency_drivers`) and §A2.6 (frequency formulas). Decoded from BIFF8 workbook `LS` sheet rows 72–77 (`PHASE0-SPIKE.md` §2).

---

## 1. Purpose and the user

**Who.** The risk practitioner estimating the event occurrence rate per year based on workforce size, control mitigation, and external probabilities.

**In the first ten seconds.** What the **Expected Annual Frequency ($\lambda$)** is (e.g. `16.15 events/year`), what that means as an interval (e.g. `1 event every 0.062 years` / `~23 days`), and where the drivers come from (HR records, control scorecard, expert judgement).

**Must be able to.** Edit the driver rows (count, scorecard link, probability); view whether the scorecard link is active; inspect the provenance data sources; add or adjust driver values; and save to store the calibrated frequency and interval.

---

## 2. Props this tab expects

Sent under `frequency` when `tab === 'frequency'`:

```ts
frequency: {
  drivers: [
    {
      id: number|null,
      driver_type: string, // 'exposed_staff_count' | 'retention_plan_efficiency' | 'competition_pressure_prob' | 'custom'
      criterion: string, // e.g. "Exposed Staff count"
      computation_role: 'count' | 'control_score_complement' | 'probability',
      value: string, // decimal string e.g. "55.00000000" or "0.95000000"
      formatted_value: string, // e.g. "55", "69.09%", "95.0%"
      data_source: string, // e.g. "HR information", "Scorecard", "Expert judgement"
      sm_scorecard_id: number|null,
      is_linked_to_scorecard: boolean,
      sort_order: number
    }
  ],
  scorecard_global_score: string|null, // e.g. "0.69090909" (null if no scorecard assessed)
  total_frequency: string|null, // e.g. "16.15000000"
  interval_years: string|null, // e.g. "0.06191950"
  interval_display: string|null, // e.g. "1 event in 0.062 year(s) (~23 days)"
  is_read_only: boolean
}
```

**Post body (`PUT /risk/scenarios/{scenario}/frequency`):**
```json
{
  "drivers": [
    {
      "driver_type": "exposed_staff_count",
      "criterion": "Exposed Staff count",
      "computation_role": "count",
      "value": "55",
      "data_source": "HR information"
    },
    {
      "driver_type": "retention_plan_efficiency",
      "criterion": "Retention plan efficiency",
      "computation_role": "control_score_complement",
      "use_scorecard": true,
      "data_source": "Scorecard"
    },
    {
      "driver_type": "competition_pressure_prob",
      "criterion": "Competition pressure probability",
      "computation_role": "probability",
      "value": "0.95",
      "data_source": "Expert judgement"
    }
  ]
}
```

---

## 3. Layout and Wireframe

Container `max-w-5xl`. Two cards:
1. **Frequency Summary Banner (`h2`)**: Displays the prominent primary metric:
   - **Expected Frequency ($\lambda$):** `16.15 events / year`
   - **Frequency Interval ($1/\lambda$):** `1 event in 0.062 year(s)` ($\approx 23$ calendar days)
2. **Frequency Drivers Table (`h2`)**: Table with driver parameters, computation roles, data source badges, and interactive value inputs.

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Expected Annual Frequency (λ)                                                │
│ 16.15 events / year  │  Interval: 1 event every 0.062 years (~23 days)       │
└──────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│ Frequency Drivers                                                            │
│ The expected annual frequency is calculated as Count × (1 - Scorecard) × P   │
├──────────────────────────┬────────────────┬─────────────┬────────────────────┤
│ Driver / Criterion       │ Role           │ Value       │ Data Source        │
├──────────────────────────┼────────────────┼─────────────┼────────────────────┤
│ Exposed Staff count      │ Count          │ [ 55      ] │ [HR information  ] │
│ Retention efficiency     │ Control Comp.  │ (Scorecard) │ Linked: 69.09%     │
│ Competition pressure     │ Probability    │ [ 0.95    ] │ [Expert judgement] │
├──────────────────────────┴────────────────┴─────────────┴────────────────────┤
│ [ Save Frequency Drivers ]                                                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Interaction, Errors & States

- **Scorecard integration:** When linked to the scorecard, the control efficiency driver pulls live from the scorecard's `global_score`. If no scorecard exists, the user can type a manual efficiency percentage or click "Create Scorecard" which links to the Scorecard tab.
- **Empty state:** When no frequency drivers exist, prompts with "Add default frequency drivers" to seed the standard 3-driver structure.
- **Validation:** 422 errors returned on `drivers.{i}.value` (must be numeric $>0$; probabilities must be $\le 1.0$).
- **Read-only state:** Disabled inputs with read-only badges when scenario is frozen/validated.
