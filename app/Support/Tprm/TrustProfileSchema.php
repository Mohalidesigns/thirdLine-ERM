<?php

namespace App\Support\Tprm;

/**
 * What a trust profile contains, and what each section is worth — FR-PRT-04.
 *
 * DECLARED IN ONE PLACE because three things read it and they must agree: the
 * vendor's editor, the completeness percentage, and the pre-fill that carries
 * answers into a new client's questionnaire. A schema implied by whichever
 * form field somebody added last is a completeness score that drifts from what
 * the vendor sees.
 *
 * `weight` IS NOT UNIFORM, and the weights are the behavioural lever this
 * phase is built around. A vendor optimising for the number will do the
 * heaviest sections first, so the heaviest sections are the ones that actually
 * shorten an onboarding: certifications and standard security answers remove
 * whole questionnaire sections, where a nicely written company description
 * removes nothing. Telling a vendor that everything matters equally is how you
 * get a hundred-percent profile that saves nobody any time.
 *
 * `unlocks` is the sentence shown against each section in the "what unlocks
 * faster onboarding" panel. It says what the vendor GETS, not what we want —
 * "removes the whole access-control section from most questionnaires" is a
 * reason to do the work; "improves your profile completeness" is not.
 */
class TrustProfileSchema
{
    /**
     * @var array<string, array{label: string, weight: int, fields: list<string>, unlocks: string}>
     */
    public const SECTIONS = [
        'company' => [
            'label' => 'Company details',
            'weight' => 10,
            'fields' => [
                'legal_name', 'registration_number', 'country_of_incorporation',
                'year_established', 'employee_band', 'website', 'primary_contact_email',
            ],
            'unlocks' => 'Fills the identification section of every questionnaire you are ever sent.',
        ],

        'certifications' => [
            'label' => 'Certifications and audit reports',
            'weight' => 25,
            'fields' => ['iso27001', 'soc2', 'pci_dss', 'iso22301', 'other_certifications'],
            'unlocks' => 'A current SOC 2 or ISO 27001 lets a client pre-answer most of the security '
                .'questionnaire and ask you for evidence once instead of every time.',
        ],

        'security' => [
            'label' => 'Standard security answers',
            'weight' => 30,
            'fields' => [
                'access_control', 'encryption_at_rest', 'encryption_in_transit', 'vulnerability_management',
                'penetration_testing', 'incident_response', 'business_continuity', 'secure_development',
                'logging_and_monitoring', 'backup_and_recovery',
            ],
            'unlocks' => 'The ten questions every client asks in different words. Answer them once and new '
                .'assessments arrive part-answered, with your own wording.',
        ],

        'data_protection' => [
            'label' => 'Data protection',
            'weight' => 15,
            'fields' => ['dpo_contact', 'lawful_basis', 'data_locations', 'cross_border_transfers', 'retention'],
            'unlocks' => 'Answers the NDPA and cross-border transfer questions a Nigerian bank must ask '
                .'before it can sign.',
        ],

        'subprocessors' => [
            'label' => 'Sub-processors',
            'weight' => 15,
            'fields' => ['subprocessors'],
            'unlocks' => 'Declaring who you rely on avoids the finding a client raises when they discover '
                .'a sub-processor in your SOC 2 that you never told them about.',
        ],

        'policies' => [
            'label' => 'Policies',
            'weight' => 5,
            'fields' => ['information_security', 'business_continuity', 'privacy', 'code_of_conduct'],
            'unlocks' => 'Saves a round trip when a client asks to see a policy you have already uploaded.',
        ],
    ];

    /** @return list<string> */
    public static function sectionKeys(): array
    {
        return array_keys(self::SECTIONS);
    }

    public static function totalWeight(): int
    {
        return array_sum(array_column(self::SECTIONS, 'weight'));
    }

    /**
     * The sections a share may be scoped to.
     *
     * Identical to the section list today. It is a separate method because the
     * two will diverge — a client should never be able to request the vendor's
     * draft, and a future section may be visible to nobody but the vendor.
     *
     * @return list<string>
     */
    public static function shareableSections(): array
    {
        return self::sectionKeys();
    }
}
