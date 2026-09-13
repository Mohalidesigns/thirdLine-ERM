<?php

namespace App\Enums\Bcms;

/**
 * How a cascade is run (`bcms_call_tree_tests.mode`, Blueprint §6.2).
 *
 * HYBRID IS THE DEFAULT AND THAT IS A DELIBERATE READING OF THE MARKET. A
 * Nigerian examiner asking how the MD was informed does not accept "an SMS was
 * delivered" as evidence that a human being was spoken to, and a bank that
 * automates the top of its cascade has to argue that point every audit. So the
 * top tiers are confirmed by a person and the bottom tiers are dispatched by the
 * system, which is what these institutions already do on paper.
 *
 * The scorecard reports the two halves separately. A completion rate that mixed
 * "the system sent 180 messages" with "two managers rang each other" would
 * flatter the automated half and hide the manual one.
 */
enum CascadeMode: string
{
    case Manual = 'manual';
    case Automated = 'automated';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual cascade',
            self::Automated => 'Automated cascade',
            self::Hybrid => 'Hybrid',
        };
    }

    /**
     * The tier at and below which the system dispatches by itself.
     *
     * Hybrid's boundary is tier 2: tiers 0 and 1 — the activation authority and
     * the crisis management team — are confirmed by a human, and everything
     * below is dispatched. A tree with no tier 2 therefore runs entirely
     * manually under hybrid, which is correct: a five-person executive cascade
     * has nothing to automate.
     */
    public function automatedFromTier(): ?int
    {
        return match ($this) {
            self::Manual => null,
            self::Automated => 0,
            self::Hybrid => 2,
        };
    }

    public function dispatchesTier(int $tier): bool
    {
        $from = $this->automatedFromTier();

        return $from !== null && $tier >= $from;
    }

    /** Whether a node at this tier needs a person to record the contact. */
    public function requiresHumanConfirmation(int $tier): bool
    {
        return ! $this->dispatchesTier($tier);
    }
}
