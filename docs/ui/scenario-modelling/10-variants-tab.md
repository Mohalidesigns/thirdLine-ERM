# 10. Scenario workspace — Variants Deck tab

**Page:** `resources/js/Pages/Sm/Scenarios/Workspace.jsx`, `tab=variants` · **Route:** `GET /risk/scenarios/{scenario}?tab=variants` (`scenario.show`) · **Write:** `PUT /risk/scenarios/{scenario}/variants` (`permission:scenario.update`) · **Phase:** 3

Read `01-workspace-shell.md` first: status colors, permissions, shared states, accessibility, and no-fabricated-numbers rules apply.

**Authority:** ADR 0027 Amendment 3 — §A3.2 (`sm_scenario_variants`). Ebola reference deck slides 3–6.

---

## 1. Purpose and the user

**Who.** The operational resilience manager, risk analyst, or workshop facilitator documenting the escalating "severe but plausible" tiers of a scenario.

**In the first ten seconds.** Clear view of the 3 escalating tiers (Tier 1: Low Risk / Contained, Tier 2: Medium Risk / BCP Activation, Tier 3: High Risk / Crisis Management), their narratives, trigger thresholds, operational impacts, organizational responses, and response owners.

**Must be able to.** Edit tier names, narratives, bulleted triggers, operational impacts, response measures, and response owners; toggle cumulative response builds (`is_cumulative`); save the tier deck; click through to the executive Deck View (`scenario.deck`).

---

## 2. Props this tab expects

Sent under `variants` when `tab === 'variants'`:

```ts
variants: [
  {
    id: number|null,
    tier: 'tier_1' | 'tier_2' | 'tier_3',
    tier_label: string, // e.g. "Tier 1 (Low Risk)"
    name: string, // e.g. "Scenario 1: Contained Outbreak"
    narrative: string|null,
    is_cumulative: boolean,
    triggers: string[], // array of bulleted trigger conditions
    operational_impacts: string[], // array of bulleted impacts
    responses: string[], // array of bulleted responses
    response_owners: string[], // array of departments/roles
    sort_order: number
  }
],
canEditVariants: boolean
```

---

## 3. UI Layout & Controls

1. **Header Toolbar:**
   - Section heading: "Severe but Plausible Variant Deck"
   - Quick action link/button: "Open Executive Deck View" linking to `route('scenario.deck', scenario.id)`.
   - Save button: "Save Variant Deck" (disabled when read-only or pristine).
2. **Tier Navigation / Accordion / Grid:**
   - 3 escalating tier cards (Tier 1 Low, Tier 2 Medium, Tier 3 High).
   - Each tier card has:
     - Header: Tier badge (`Low Risk`, `Medium Risk`, `High Risk`), tier name input, cumulative toggle.
     - Narrative textarea.
     - Four multi-line item builders:
       - **Triggers:** list of trigger conditions/thresholds with "Add Trigger" button.
       - **Operational Impacts:** list of impacts with "Add Impact" button.
       - **Abbey/Firm Response:** list of responses with "Add Response" button.
       - **Response Owners:** tags / badges or list of owning departments/units.

