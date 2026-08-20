<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum capital adequacy ratio
    |--------------------------------------------------------------------------
    |
    | The CBN Guidelines on Regulatory Capital (September 2021) set the minimum
    | Capital Adequacy Ratio at 10.0% of total risk-weighted assets for banks
    | holding a national or regional authorisation, and 15.0% for banks holding
    | an international authorisation and for Domestic Systemically Important
    | Banks (D-SIBs).
    |
    | 10.0% is the LOWEST of the two, so it is the safe default for an
    | organisation that has not yet configured its own figure: it can only ever
    | understate the requirement for a bank that never told us it was
    | international or a D-SIB, and that understatement is visible on screen
    | rather than silent. An institution on international authorisation, or one
    | designated a D-SIB, MUST set 15.0 on its ICAAP assessment or in its
    | Quantification Settings. Nothing in this codebase infers authorisation
    | class or D-SIB designation, and nothing here will silently switch a bank
    | to 15.0% on its behalf.
    |
    | Resolution order (see QuantificationController::resolveMinimumCar):
    |   1. icaap_assessments.cbn_minimum_car  — the figure the assessment was
    |      prepared against, which is what a validator reconciles to;
    |   2. quantification_settings.cbn_minimum_car — the org-wide standing
    |      figure. Until WP-08 this column was read by nothing at all;
    |   3. this value.
    |
    */

    'default_minimum_car' => 10.0,

    /*
    | Reference only — displayed as guidance next to the resolved minimum so a
    | preparer on international authorisation can see what they should have
    | configured. Never applied automatically.
    */

    'international_or_dsib_minimum_car' => 15.0,

    /*
    |--------------------------------------------------------------------------
    | Capital conservation buffer
    |--------------------------------------------------------------------------
    |
    | CBN requires a capital conservation buffer of 1.0% of total risk-weighted
    | assets, met with CET1 — not the 2.5% of the Basel III text.
    |
    | The `conservation_buffer` column on icaap_assessments carries a database
    | default of 2.5 from the original 2026-02 migration, which was the Basel
    | figure rather than the Nigerian one. That stored default is NOT rewritten
    | here — an assessment keeps the number it was prepared against, and
    | changing stored capital inputs from a config file is exactly the kind of
    | silent restatement a model validator would (rightly) fail us for. This
    | value is used only where the code previously fell back to a hardcoded
    | 2.5, i.e. when an assessment carries no buffer at all.
    |
    */

    'default_conservation_buffer' => 1.0,

    /*
    |--------------------------------------------------------------------------
    | CAR reconciliation tolerance (percentage points)
    |--------------------------------------------------------------------------
    |
    | The ICAAP screen recomputes CAR from stored capital and RWA and compares
    | it with the `car_actual` figure the preparer typed in. Anything above this
    | tolerance is surfaced as an explicit variance rather than quietly
    | preferring one number over the other. 0.05pp is below the rounding noise
    | of a CAR quoted to two decimal places.
    |
    */

    'car_reconciliation_tolerance' => 0.05,

    /*
    |--------------------------------------------------------------------------
    | Headline stress confidence level
    |--------------------------------------------------------------------------
    |
    | Stress impact is reported at every confidence level the simulation engine
    | actually stored (90 / 95 / 99 / 99.9). This is the one used for the
    | single-line headline verdict, and it is stated on screen next to the
    | number so no reader has to guess which tail the figure came from.
    |
    */

    'headline_stress_confidence' => 99.0,

];
