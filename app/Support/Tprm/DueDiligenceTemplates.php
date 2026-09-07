<?php

namespace App\Support\Tprm;

use App\Enums\Tprm\RiskTier;

/**
 * The due diligence checklists, by tier — FR-DDL-02.
 *
 * THE FACTOR LIST IS THE INTERAGENCY 2023 GUIDANCE'S. The US Interagency
 * Guidance on Third-Party Relationships enumerates what due diligence should
 * cover, and it is the most complete public statement of that available — the
 * CBN's own framework says less about the mechanics and does not contradict
 * it. The wording below is ours; what is borrowed is the coverage.
 *
 * MANDATORY IS SET BY TIER AND IT IS THE ONLY THING THAT GATES. FR-DDL-09
 * blocks completion while a mandatory item is open, so marking everything
 * mandatory would block every onboarding and marking nothing mandatory would
 * make the gate decorative. A Low-tier stationery supplier needs the entity to
 * exist and the contract to be signed; a Critical ICT provider needs all
 * fourteen.
 *
 * THESE ARE A STARTING POINT, NOT A STANDARD. A tenant's own programme is what
 * governs, and every item is editable — the same position the seeded business
 * functions and tier policies take. What is not negotiable is that a mandatory
 * item cannot be skipped silently: closure needs evidence or a waiver with an
 * approver and an expiry (FR-DDL-08).
 */
class DueDiligenceTemplates
{
    /**
     * code => [title, category, type, and the tiers it is mandatory for].
     *
     * @return list<array{code: string, title: string, category: string, item_type: string, mandatory_from: string|null}>
     */
    public static function items(): array
    {
        return [
            /* --- Existence and standing ------------------------------- */
            [
                'code' => 'DD-ENT-01',
                'title' => 'Corporate registration confirmed against the registry (CAC certificate or equivalent)',
                'category' => 'entity',
                'item_type' => 'document',
                'mandatory_from' => 'low',
            ],
            [
                'code' => 'DD-ENT-02',
                'title' => 'Ownership and control structure recorded, including ultimate beneficial owners',
                'category' => 'entity',
                'item_type' => 'record',
                'mandatory_from' => 'moderate',
            ],
            [
                'code' => 'DD-ENT-03',
                'title' => 'Sanctions, PEP and adverse-media screening completed on the entity and its directors',
                'category' => 'entity',
                'item_type' => 'screening',
                'mandatory_from' => 'low',
            ],

            /* --- Financial condition ---------------------------------- */
            [
                'code' => 'DD-FIN-01',
                'title' => 'Financial statements obtained and reviewed for the most recent period',
                'category' => 'financial',
                'item_type' => 'document',
                'mandatory_from' => 'moderate',
            ],
            [
                'code' => 'DD-FIN-02',
                'title' => 'Viability assessed — going concern, liquidity and dependence on this contract',
                'category' => 'financial',
                'item_type' => 'assessment',
                'mandatory_from' => 'high',
            ],
            [
                'code' => 'DD-FIN-03',
                'title' => 'Insurance cover confirmed as adequate for the exposure and naming the contracting entity',
                'category' => 'financial',
                'item_type' => 'document',
                'mandatory_from' => 'high',
            ],

            /* --- Business experience and reputation -------------------- */
            [
                'code' => 'DD-REP-01',
                'title' => 'Business experience, references and track record in this service verified',
                'category' => 'reputation',
                'item_type' => 'record',
                'mandatory_from' => 'moderate',
            ],
            [
                'code' => 'DD-REP-02',
                'title' => 'Legal and regulatory actions against the provider searched and recorded',
                'category' => 'reputation',
                'item_type' => 'record',
                'mandatory_from' => 'high',
            ],

            /* --- Operational capability -------------------------------- */
            [
                'code' => 'DD-OPS-01',
                'title' => 'Operational capacity to deliver at the volumes required, evidenced rather than asserted',
                'category' => 'operational',
                'item_type' => 'assessment',
                'mandatory_from' => 'high',
            ],
            [
                'code' => 'DD-OPS-02',
                'title' => 'Sub-contracting and sub-processor arrangements identified down to the parties that matter',
                'category' => 'operational',
                'item_type' => 'record',
                'mandatory_from' => 'high',
            ],
            [
                'code' => 'DD-OPS-03',
                'title' => 'Business continuity and disaster recovery arrangements reviewed and tested with us',
                'category' => 'resilience',
                'item_type' => 'document',
                'mandatory_from' => 'high',
            ],

            /* --- Information security and data ------------------------- */
            [
                'code' => 'DD-SEC-01',
                'title' => 'Information security programme assessed against our own control objectives',
                'category' => 'security',
                'item_type' => 'assessment',
                'mandatory_from' => 'moderate',
            ],
            [
                'code' => 'DD-SEC-02',
                'title' => 'Independent assurance obtained — SOC 2, ISO 27001 or equivalent covering this service',
                'category' => 'assurance',
                'item_type' => 'document',
                'mandatory_from' => 'high',
            ],
            [
                'code' => 'DD-SEC-03',
                'title' => 'Data protection arrangements confirmed, including a processor agreement where personal data is involved',
                'category' => 'data_protection',
                'item_type' => 'document',
                'mandatory_from' => 'moderate',
            ],

            /* --- Physical inspection ----------------------------------- */
            [
                'code' => 'DD-VIS-01',
                'title' => 'Site or data-centre inspection carried out and recorded (CBN Cyber App. II §1.4)',
                'category' => 'inspection',
                'item_type' => 'site_visit',
                // Critical only. A site visit is expensive, and requiring one
                // of every vendor is how a programme teaches its people to
                // waive the requirement rather than meet it.
                'mandatory_from' => 'critical',
            ],

            /* --- Contract and exit ------------------------------------- */
            [
                'code' => 'DD-CTR-01',
                'title' => 'Contract reviewed against the mandatory clause set for this engagement',
                'category' => 'contract',
                'item_type' => 'assessment',
                'mandatory_from' => 'low',
            ],
            [
                'code' => 'DD-CTR-02',
                'title' => 'Exit and transition arrangements agreed and documented',
                'category' => 'exit',
                'item_type' => 'document',
                'mandatory_from' => 'high',
            ],
        ];
    }

    /**
     * The checklist for a tier: every item, with mandatory resolved.
     *
     * `mandatory_from` names the LOWEST tier at which an item becomes
     * mandatory, so a Critical checklist inherits everything a High one
     * requires. Listing each tier's mandatory set separately would let the two
     * drift, and the drift would show up as a Critical vendor onboarded
     * without something a High vendor needed.
     *
     * @return list<array{code: string, title: string, category: string, item_type: string, is_mandatory: bool}>
     */
    public static function forTier(?RiskTier $tier): array
    {
        $order = ['low' => 0, 'moderate' => 1, 'high' => 2, 'critical' => 3];
        $rank = $order[$tier->value ?? 'low'] ?? 0;

        return array_map(function (array $item) use ($order, $rank) {
            $threshold = $item['mandatory_from'];

            return [
                'code' => $item['code'],
                'title' => $item['title'],
                'category' => $item['category'],
                'item_type' => $item['item_type'],
                'is_mandatory' => $threshold !== null && $rank >= ($order[$threshold] ?? 99),
            ];
        }, self::items());
    }
}
