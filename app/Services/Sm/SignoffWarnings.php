<?php

namespace App\Services\Sm;

use App\Models\Sm\Scenario;
use App\Models\Sm\Signoff;

/**
 * The eight non-blocking sign-off warnings, as CODES (ADR 0027 addendum-1).
 *
 * CODES ONLY. The rendered WORDING comes from `config/sm.php`
 * (`signoff.warning_messages`) and is never stored — storing wording would freeze
 * text a tenant may reconfigure and duplicate §A1.8's label maps.
 *
 * WHY THEY ARE STORED ON THE ROW RATHER THAN RECOMPUTED AT RENDER — and this is
 * the load-bearing part. Spec 01 §5.5's table allows notes to be added to a
 * `signed_off` scenario ("a note is commentary, not a change"). So
 * `no_challenge_recorded`, derived live, would VANISH the moment somebody appended
 * a challenge note a month after sign-off — falsifying what was outstanding when
 * the signer signed. The sign-off row's `warnings_acknowledged` is a SNAPSHOT,
 * exactly the discipline D1/§4.3 applies to figures. A derived list there is not a
 * simplification, it is a record that quietly rewrites itself.
 *
 * This class computes the LIVE list, which is a different thing with a different
 * job: it is what the sign-off modal shows BEFORE the signer commits, and what
 * gets frozen onto the row when they do.
 *
 * SEVEN OF THE EIGHT CANNOT FIRE THROUGH THE PHASE 1 UI PATH. §A1.8's guard on
 * `in_design → designed` requires ≥1 selected cause and an allocation summing to
 * 100.00, so a scenario cannot reach `signed_off` with those unset, and
 * `no_challenge_recorded` is the sole code that genuinely fires. They are computed
 * anyway because the sign-off record must be SELF-DESCRIBING rather than
 * correct-by-coincidence, and because PHASE 5'S EXCEL IMPORT CAN PRODUCE A
 * SCENARIO THAT NEVER PASSED A GATE.
 *
 * The eighth code is `financial_risk_not_assessed`, per ADR 0027 Correction 1
 * §C1.1 — swapped in for addendum-1's `eligible_to_transfer_not_assessed`, whose
 * NULL condition a NOT NULL column makes unreachable. The two exposure scales are
 * NOT gated (§A1.8 lists four requirements and they are not among them), so
 * `reputational_not_assessed` and `legal_not_assessed` can fire on a scenario that
 * reached sign-off legitimately.
 */
class SignoffWarnings
{
    /**
     * The codes that are true of $scenario right now, in
     * `Signoff::WARNING_CODES` order.
     *
     * Order comes from the const, not from the order the checks happen to run in,
     * so the modal's list is stable across releases and a diff of two sign-off
     * rows reads as a diff of substance.
     *
     * @return list<string>
     */
    public static function for(Scenario $scenario): array
    {
        $true = [];

        // Bullet 1 — no `challenge` or `dissent` in the notes log. This is the one
        // that genuinely fires in Phase 1, and the reason it can is spec 01 §5.5:
        // a scenario may reach sign-off with nobody having argued about it.
        if (! self::hasChallenge($scenario)) {
            $true['no_challenge_recorded'] = true;
        }

        // Bullet 2
        if (CauseService::selectedCodes($scenario->causes) === []) {
            $true['no_cause_selected'] = true;
        }

        // Bullet 3 — two mutually exclusive alternatives, so exactly one fires.
        $total = AllocationService::total($scenario->allocations);

        if ($total === null) {
            $true['allocation_missing'] = true;
        } elseif (! AllocationService::isExactly100($total)) {
            $true['allocation_not_100'] = true;
        }

        // Bullet 4 — NULL is "not yet assessed", and `no_impact` / `no` are
        // answers. Only the absence warns (standard §5, spec 03 §6.1).
        if ($scenario->reputational_exposure === null) {
            $true['reputational_not_assessed'] = true;
        }

        if ($scenario->legal_compliance_exposure === null) {
            $true['legal_not_assessed'] = true;
        }

        // Bullet 5 — both nullable flags (§A1.2 rows 23–24). NULL is "not yet
        // assessed" and `false` is an answer, so only the absence warns: coercing
        // either into "No" is the exact defect the nullable columns exist to
        // prevent (standard §5, spec 03 §6.4).
        if ($scenario->insurance_risk === null) {
            $true['insurance_risk_not_assessed'] = true;
        }

        if ($scenario->financial_risk === null) {
            $true['financial_risk_not_assessed'] = true;
        }

        // NO `eligible_to_transfer` CHECK, per ADR 0027 Correction 1 §C1.1. The
        // addendum's eighth code tested `eligible_to_transfer IS NULL`, but §A1.2
        // row 25 froze that column NOT NULL default `false` so Phase 2's netting
        // formula stays two-case — the condition describes a state the schema
        // forbids. Spec 01 §5.6.5's sixth bullet (the advisory shown when the value
        // is `false`) is therefore UI-ONLY and is never recorded here: `false` is
        // the default and so the state of every scenario nobody has considered, and
        // recording it would attach the same warning to most sign-offs and teach
        // signers to skip the list.

        return array_values(array_filter(
            Signoff::WARNING_CODES,
            fn (string $code) => isset($true[$code])
        ));
    }

    /**
     * The codes with their configured wording, for the modal's `role="note"` list.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function withMessages(Scenario $scenario): array
    {
        $messages = (array) config('sm.signoff.warning_messages', []);

        $rendered = [];

        foreach (self::for($scenario) as $code) {
            $rendered[] = [
                'code' => $code,
                'message' => (string) ($messages[$code] ?? $code),
            ];
        }

        return $rendered;
    }

    /**
     * Whether the notes log carries a challenge or a dissent.
     *
     * Either kind satisfies it. A dissent is somebody disagreeing and a challenge
     * is the second line testing the design; spec 01 §5.6.5 treats them as one
     * condition, and requiring specifically a `challenge` would let a recorded
     * dissent read as "nobody argued about this".
     */
    public static function hasChallenge(Scenario $scenario): bool
    {
        return $scenario->notes()
            ->whereIn('kind', ['challenge', 'dissent'])
            ->exists();
    }
}
