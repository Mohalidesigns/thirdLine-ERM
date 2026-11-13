# 12. Scenario Deck View — Executive Presentation Deck

**Page:** `resources/js/Pages/Sm/Scenarios/DeckView.jsx` · **Route:** `GET /risk/scenarios/{scenario}/deck` (`scenario.deck`) · **Permission:** `scenario.view` · **Phase:** 3

**Authority:** ADR 0027 Amendment 3 — §A3.5. Ebola reference deck (`SCENARIO ANALYSIS IRO EBOLA.pptx`).

---

## 1. Purpose and the user

**Who.** Chief Risk Officer, Board Risk Committee, BCM coordinators, and Senior Leadership reviewing the scenario escalation story, variant tiers, triggers, responses, and immediate action plan.

**In the first ten seconds.** Polished, presentation-grade executive deck layout (slide navigation, dark/light theme support, print/PDF button).

**Must be able to.** Navigate through presentation slides:
1. **Title Slide:** Scenario title, reference, category, date, review cadence, status.
2. **Context & Scope:** Scenario narrative, qualitative factors, internal/external causes, and business line allocations.
3. **Escalating Tiers (S1, S2, S3):** Side-by-side or step-through comparison of Low, Medium, and High risk tiers with their triggers, impacts, responses, and response owners.
4. **Possible Risk & Resolution Plan:** Matrix of risk areas, current positions, identified gaps, and action points.
5. **Immediate Action Roadmap:** Phased action timeline (Immediate, 7 Days, 30 Days) with owners and proof of closure status.
6. **Quantitative Recap:** $\lambda$ frequency, interval, gross impact, and net uninsured/insured impact.

---

## 2. Props this screen expects

```ts
scenario: {
  id: number,
  reference: string,
  title: string,
  status: string,
  category: string,
  narrative: string,
  variants: [ ... ], // tier 1, 2, 3 details
  gap_actions: [ ... ],
  immediate_actions: [ ... ],
  quantitative_summary: {
    frequency_lambda: string|null,
    recurrence_interval_years: string|null,
    gross_typical: string|null,
    gross_serious: string|null,
    gross_extreme: string|null,
    net_typical: string|null,
    net_serious: string|null,
    net_extreme: string|null
  }
}
```

