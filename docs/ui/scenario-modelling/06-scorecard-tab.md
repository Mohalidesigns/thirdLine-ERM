# 06. Scenario workspace — Scorecard tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=scorecard` · **Route:** `GET /risk/scenarios/{scenario}?tab=scorecard` (`scenario.show`) · **Write:** `PUT /risk/scenarios/{scenario}/scorecard` (`permission:scenario.design`) · **Phase:** 2

Read `01-workspace-shell.md` first: the status-colour table (§2), the permission→action table (§3), the shared states (§0.2), the accessibility baseline (§0.3), the low-bandwidth rules (§0.4) and the no-fabricated-numbers rule (§0.5) apply here unchanged and are not repeated.

**Authority:** ADR 0027 Amendment 2 — §A2.2 (`sm_scorecards` and `sm_scorecard_components`) and §A2.6 (scorecard calculation specification). Formula decoded from BIFF8 workbook `Scorecard` sheet (`PHASE0-SPIKE.md` §1).

---

## 1. Purpose and the user

**Who.** The risk analyst or workshop participant assessing the organization's control environment efficiency across the five COSO components.

**In the first ten seconds.** Whether the control environment is characterized (nature selector: manual, mixed, automated), what the overall efficiency percentage is, and how each COSO component is rated and weighted.

**Must be able to.** Select the control environment nature (mainly manual, mixed, mainly automated); rate each of the 5 COSO components (Very low 1 to Very high 5); set the weighting factor for each component (1 to 5); view the automatically calculated level score, environmental deduction, adjusted score, and weighted score; and inspect the resulting Global Efficiency Score ($0.0 \dots 1.0$ / $0\% \dots 100\%$) which feeds into the Frequency tab.

---

## 2. Props this tab expects

Sent under `scorecard` when `tab === 'scorecard'`:

```ts
scorecard: {
  id: number|null,
  control_nature: 'mainly_manual' | 'mixed' | 'mainly_automated',
  nature_options: [
    { value: 'mainly_manual', label: '1. Mainly manual controls', deduction: '-0.10' },
    { value: 'mixed', label: '2. Mix of manual and automated controls', deduction: '-0.05' },
    { value: 'mainly_automated', label: '3. Mainly automated controls', deduction: '0.00' }
  ],
  components: [
    {
      component_code: 'control_environment' | 'risk_assessment' | 'control_activities' | 'information_communication' | 'monitoring',
      component_name: string, // e.g. "I. Control environment"
      efficiency_level: number, // 0..5 (0 = unset)
      weighting_factor: number, // 0..5 (0 = unset)
      score: number, // 0..5
      level_score: string, // e.g. "0.90"
      adjusted_score: string, // e.g. "0.85"
      weighting_score: number, // 0..5
      weighted_score: string, // e.g. "4.2500"
      notes: string|null,
      sort_order: number
    }
  ],
  level_options: [
    { value: 0, label: '— Please select —' },
    { value: 1, label: '1. Very low', score: '0.20' },
    { value: 2, label: '2. Low', score: '0.40' },
    { value: 3, label: '3. Medium', score: '0.55' },
    { value: 4, label: '4. High', score: '0.70' },
    { value: 5, label: '5. Very high', score: '0.90' }
  ],
  weight_options: [
    { value: 0, label: '— Please select —' },
    { value: 1, label: '1. Very low' },
    { value: 2, label: '2. Low' },
    { value: 3, label: '3. Medium' },
    { value: 4, label: '4. High' },
    { value: 5, label: '5. Very high' }
  ],
  total_weight: number|null, // e.g. 22
  total_weighted_score: string|null, // e.g. "15.2000"
  global_score: string|null, // e.g. "0.69090909"
  global_score_formatted: string|null, // e.g. "69.09%"
  is_read_only: boolean
}
```

**Post body (`PUT /risk/scenarios/{scenario}/scorecard`):**
```json
{
  "control_nature": "mixed",
  "components": [
    {
      "component_code": "control_environment",
      "efficiency_level": 5,
      "weighting_factor": 5,
      "notes": "Annual policy review completed."
    }
  ]
}
```

---

## 3. Layout and Wireframe

Container `max-w-5xl`. Two cards:
1. **Control Environment Nature card** (`h2`): Radio card group selecting `mainly_manual`, `mixed`, or `mainly_automated`, detailing the penalty applied to level scores.
2. **COSO Components & Global Score card** (`h2`): Interactive table with 5 rows.
   - Header summary banner showing **Total Weight**, **Total Weighted Score**, and **Global Control Efficiency** (`role="status"`).
   - Columns: Component, Efficiency Level (select), Level Score (read-only), Adjusted Score (read-only), Weighting Factor (select), Weighted Score (read-only), Notes (optional expand/text input).

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Control Environment Nature                                                   │
│ Choose the nature of controls operating across this scenario.                │
│ ( ) Mainly manual (-10%)   (*) Mixed manual & automated (-5%)  ( ) Automated │
└──────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│ COSO Control Components                                                      │
│ Global Score: 69.09%  │  Total Weight: 22  │  Total Weighted Score: 15.20    │
├──────────────────────────────────────────────────────────────────────────────┤
│ Component            │ Level (1-5)  │ Adj. Score │ Weight (1-5) │ Wtd Score  │
├──────────────────────┼──────────────┼────────────┼──────────────┼────────────┤
│ I. Control Env.      │ [5. V.High ▾]│   0.85     │ [5. V.High ▾]│   4.250    │
│ II. Risk Assessment  │ [5. V.High ▾]│   0.85     │ [2. Low    ▾]│   1.700    │
│ III. Control Act.    │ [1. V.Low  ▾]│   0.15     │ [5. V.High ▾]│   0.750    │
│ IV. Info & Comm.     │ [5. V.High ▾]│   0.85     │ [5. V.High ▾]│   4.250    │
│ V. Monitoring        │ [5. V.High ▾]│   0.85     │ [5. V.High ▾]│   4.250    │
├──────────────────────────────────────────────────────────────────────────────┤
│ [ Save Scorecard ]                                                           │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Interaction, Errors & States

- **Live calculation feedback:** Changing a level or weight updates row adjusted and weighted scores, total weight, and global efficiency preview live in client state. The authoritative server response on `PUT` commits the exact decimals to database storage.
- **Empty state:** When no components have been rated yet, global score displays as "Not yet assessed", total weight "—", and scores "—".
- **Read-only state:** When scenario `is_read_only` (status `signed_off`, `archived`, or `in_validation`), form controls are disabled with banner explanation.
- **Validation:** 422 errors returned on `control_nature` or `components.{code}.efficiency_level` / `weighting_factor` (must be 1..5 when submitted).
- **Transport failures:** Wired to `useSaveFailure` / `FailureBanner`.

