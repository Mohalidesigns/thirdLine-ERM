<?php

namespace App\Support\Tprm;

/**
 * The two maturity lenses this product scores a third-party programme against
 * — FR-RPT-06.
 *
 * THE VRMMM CRITERIA BELOW ARE OURS, NOT SHARED ASSESSMENTS'. The VRMMM's
 * eight category names are publicly documented and are used as such; the
 * detailed level criteria behind them are licensed content, and TRD Appendix E
 * item 7 is explicit that licensed material must not be redistributed in this
 * product. So each category ships a criterion written here, describing what
 * THIS product can evidence at each level, and the framework version string
 * says so. A client that licenses the VRMMM can score against the real rubric
 * and record the same numbers; nothing here claims to BE the VRMMM.
 *
 * THE NIST CSF SUBCATEGORIES ARE QUOTED, because CSF 2.0 is a US Government
 * work in the public domain — and they are read from the shipped framework
 * library rather than restated here, so the catalogue has one home.
 *
 * THE LEVEL SCALE IS THE ONE THE PROMPT SPECIFIES, and level 0 is a real
 * assessed finding rather than a missing value. A category nobody has looked
 * at is null in the database and reads "Not assessed" on the page; a category
 * assessed as absent is 0 and reads "Non-existent". Collapsing the two would
 * let an unstarted programme and an unassessed one look identical.
 */
class MaturityModel
{
    public const FRAMEWORK_VRMMM = 'vrmmm';

    public const FRAMEWORK_NIST_CSF = 'nist_csf';

    /**
     * Bump when a criterion below changes. A level 3 recorded under one
     * version and one recorded under another are not the same claim.
     */
    public const VERSION = 'tprm-maturity.v1';

    /** @var array<int, string> */
    public const LEVELS = [
        0 => 'Non-existent',
        1 => 'Initial visioning',
        2 => 'Determining the roadmap',
        3 => 'Fully determined and established',
        4 => 'Fully implemented and operational',
        5 => 'Continuous improvement',
    ];

    /**
     * The VRMMM's eight categories, with a criterion written for this product.
     *
     * @return list<array{code: string, name: string, criterion: string}>
     */
    public static function vrmmmCategories(): array
    {
        return [
            [
                'code' => 'VRMMM-01',
                'name' => 'Programme governance',
                'criterion' => 'A named owner, a mandate approved above the risk function, a committee that '
                    .'receives third-party reporting, and a defined escalation path for a vendor in breach.',
            ],
            [
                'code' => 'VRMMM-02',
                'name' => 'Policies, standards and procedures',
                'criterion' => 'A third-party policy that is current, approved, and specific enough that two '
                    .'people applying it to the same vendor reach the same tier.',
            ],
            [
                'code' => 'VRMMM-03',
                'name' => 'Contract development, adherence and management',
                'criterion' => 'A mandatory clause set applied by tier, gaps tracked to closure or to an '
                    .'approved waiver, obligations with named owners and due dates, and notice periods '
                    .'monitored before they lapse rather than after.',
            ],
            [
                'code' => 'VRMMM-04',
                'name' => 'Vendor risk assessment process',
                'criterion' => 'Inherent risk assessed at intake, scope driven by that assessment, answers '
                    .'reviewed before they score, and a residual figure whose derivation two people can '
                    .'reproduce.',
            ],
            [
                'code' => 'VRMMM-05',
                'name' => 'Skills and expertise',
                'criterion' => 'Assessors competent in the domains they review, with specialist input for '
                    .'cyber, data protection and financial assessment rather than one generalist reviewing all '
                    .'three.',
            ],
            [
                'code' => 'VRMMM-06',
                'name' => 'Communication and information sharing',
                'criterion' => 'Findings reaching the relationship owner and the vendor, a route for the vendor '
                    .'to respond, and reporting the business unit that bought the service actually reads.',
            ],
            [
                'code' => 'VRMMM-07',
                'name' => 'Tools, measurement and analysis',
                'criterion' => 'A single register rather than a spreadsheet estate, metrics defined once and '
                    .'collected automatically, and analysis that survives a question about its inputs.',
            ],
            [
                'code' => 'VRMMM-08',
                'name' => 'Monitoring and review',
                'criterion' => 'Continuous signals rather than an annual questionnaire, evidence currency '
                    .'tracked and decaying assurance when it lapses, service reviews held on a cadence, and '
                    .'exit plans exercised rather than written.',
            ],
        ];
    }

    /**
     * Whether a level is on the scale.
     */
    public static function isValidLevel(?int $level): bool
    {
        return $level === null || array_key_exists($level, self::LEVELS);
    }

    public static function levelLabel(?int $level): string
    {
        // The distinction the whole model turns on.
        return $level === null ? 'Not assessed' : (self::LEVELS[$level] ?? 'Unknown level');
    }
}
