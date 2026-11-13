# 09. Scenario workspace — Insurance tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=insurance` · **Route:** `GET /risk/scenarios/{scenario}?tab=insurance` (`scenario.show`) · **Write:** `PUT /risk/scenarios/{scenario}/insurance` (`permission:scenario.design`) · **Phase:** 2

Read `01-workspace-shell.md` first.

**Authority:** ADR 0027 Amendment 2 — §A2.5 (`sm_insurance_coverages`) and §A2.6 (insurance netting specification). Decoded from BIFF8 workbook `LS` sheet rows 95–106 (`PHASE0-SPIKE.md` §4).

---

## 1. Purpose and the user

**Who.** The risk transfer specialist or CRO evaluating the mitigation provided by commercial insurance and reinsurance structures against operational catastrophe losses.

**In the first ten seconds.** What the **Net Impact** is compared to Gross Impact across Typical, Serious, and Extreme severities; how much loss is transferable; and the effect of policy limits, deductibles, and insurer credit risk haircuts.

**Must be able to.** Configure local and group policy deductibles and limits; set claim recovery rate and dispute probability; set insurer default haircut; view the calculated tiered residual and haircut impact; and save to store the net-of-insurance loss figures.

---

## 2. Props this tab expects

Sent under `insurance` when `tab === 'insurance'`:

```ts
insurance: {
  currency: string, // e.g. "NGN"
  severities: ['typical', 'serious', 'extreme'],
  coverages: {
    typical: {
      transferable_amount: string, // e.g. "0.00"
      deductible_local: string,
      limit_local: string,
      deductible_group: string,
      limit_group: string,
      recovery_rate: string, // e.g. "1.0000" (100%)
      probability_of_cover: string, // e.g. "1.0000" (100%)
      insurer_default_haircut: string, // e.g. "0.0000" (0%)
      residual_amount: string,
      total_haircut: string,
      haircut_impact: string,
      gross_impact: string,
      net_impact: string
    },
    serious: { /* same shape */ },
    extreme: { /* same shape */ }
  },
  is_uninsured: boolean, // true if transferable_amount is 0 across all severities
  is_read_only: boolean
}
```

**Post body (`PUT /risk/scenarios/{scenario}/insurance`):**
```json
{
  "coverages": {
    "typical": {
      "deductible_local": "1000000",
      "limit_local": "50000000",
      "deductible_group": "50000000",
      "limit_group": "500000000",
      "recovery_rate": "0.90",
      "probability_of_cover": "0.95",
      "insurer_default_haircut": "0.02"
    },
    "serious": {
      "deductible_local": "1000000",
      "limit_local": "50000000",
      "deductible_group": "50000000",
      "limit_group": "500000000",
      "recovery_rate": "0.90",
      "probability_of_cover": "0.95",
      "insurer_default_haircut": "0.02"
    },
    "extreme": {
      "deductible_local": "1000000",
      "limit_local": "50000000",
      "deductible_group": "50000000",
      "limit_group": "500000000",
      "recovery_rate": "0.90",
      "probability_of_cover": "0.95",
      "insurer_default_haircut": "0.02"
    }
  }
}
```

---

## 3. Layout and Wireframe

Container `max-w-6xl`.
1. **Net vs Gross Impact Header (`h2`)**: Comparison cards showing Gross Impact, Insurance Recovery Benefit, and Net Retained Loss per severity.
2. **Insurance Policy Terms & Netting Matrix (`h2`)**: Table comparing the 3 severities side-by-side across insurance policy variables.

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Net Impact After Insurance Transfer                                          │
│ Typical: NGN 15.12M (Gross) → NGN 15.12M (Net)                               │
│ Serious: NGN 22.86M (Gross) → NGN 22.86M (Net)                               │
│ Extreme: NGN 45.37M (Gross) → NGN 45.37M (Net)                               │
└──────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│ Insurance Coverages & Deductibles                                            │
├──────────────────────────────┬──────────────┬──────────────┬─────────────────┤
│ Parameter                    │ Typical      │ Serious      │ Extreme         │
├──────────────────────────────┼──────────────┼──────────────┼─────────────────┤
│ Transferable Losses (from UI)│ NGN 0.00     │ NGN 0.00     │ NGN 0.00        │
│ Local Deductible             │ [ 0        ] │ [ 0        ] │ [ 0           ] │
│ Local Policy Limit           │ [ 0        ] │ [ 0        ] │ [ 0           ] │
│ Group Deductible             │ [ 0        ] │ [ 0        ] │ [ 0           ] │
│ Group Policy Limit           │ [ 0        ] │ [ 0        ] │ [ 0           ] │
│ Recovery Rate (%)            │ [ 100%     ] │ [ 100%     ] │ [ 100%        ] │
│ Probability of Cover (%)     │ [ 100%     ] │ [ 100%     ] │ [ 100%        ] │
│ Insurer Default Haircut (%)  │ [ 0%       ] │ [ 0%       ] │ [ 0%          ] │
├──────────────────────────────┼──────────────┼──────────────┼─────────────────┤
│ Tiered Residual (Retained)   │ NGN 0.00     │ NGN 0.00     │ NGN 0.00        │
│ Haircut Impact               │ NGN 0.00     │ NGN 0.00     │ NGN 0.00        │
│ Final Net Impact             │ NGN 15.12M   │ NGN 22.86M   │ NGN 45.37M      │
├──────────────────────────────┴──────────────┴──────────────┴─────────────────┤
│ [ Save Insurance Terms ]                                                     │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Interaction, Errors & States

- **Zero-transferable state:** When no impact categories are marked `eligible_to_transfer` in the Impact tab, a non-blocking informational banner explains that all losses are uninsured and Net Impact equals Gross Impact.
- **Tiered calculation explanation:** Tooltips explain the 5-branch local and group policy attachment formula decoded from the workbook.
- **Validation:** 422 errors returned on negative limits, deductibles exceeding limits, or percentages $> 100\%$.
- **Read-only state:** Disabled inputs with read-only badge when scenario is locked.

