<?php

namespace App\Support\Tprm;

use App\Enums\Tprm\RiskTier;

/**
 * The tiering ruleset this product ships — TRD §7.2, §7.3 and Appendix A.
 *
 * Seeded into `tp_rulesets` as version 1.0 for every tenant, and editable from
 * there. This class is the DEFAULT, not the runtime authority: once a tenant
 * publishes its own version, the calculator reads that row and this file is
 * only consulted for a tenant that has none.
 *
 * WHERE THE NUMBERS COME FROM, AND WHERE THEY DO NOT.
 *
 * The factor weights, the option scores for DATA, ACCESS, CRIT, SUB and GEO,
 * and all ten knockout floors are stated explicitly in the TRD and are
 * reproduced exactly. Two are NOT stated and are our defaults, marked
 * `derived` below so that nobody mistakes them for a requirement:
 *
 *   REG  — the TRD says "count and severity of applicable regimes … normalised
 *          0–1" without giving the severity of each regime. The weights here
 *          rank a regime by what non-compliance with it actually costs a
 *          Nigerian bank: a CBN cyber breach or an AML failure is an
 *          enforcement matter with a licence attached; consumer-protection
 *          exposure is a remediation matter. They are a starting point for the
 *          tenant's own risk function, which is why they are in an editable
 *          ruleset rather than in the calculator.
 *
 *   FIN  — the TRD says "banded against tenant thresholds" and gives no
 *          thresholds, because they depend on the institution's size. The
 *          defaults here are NGN bands sized for a mid-tier Nigerian
 *          commercial bank. A microfinance bank should lower every one of
 *          them, and the ruleset editor is where.
 *
 * Nothing here is a regulatory citation. The knockout CITATIONS are, and those
 * are reproduced verbatim from TRD §7.3 because a wrong citation on a
 * compliance screen tells a client it must do something no regulator said.
 */
class DefaultRuleset
{
    public const VERSION = '1.0';

    /**
     * The seven weighted factors. Weights must total 100.
     *
     * @return array<string, array{label: string, weight: int, source: string, description: string, options: list<array{value: string, label: string, score: float}>}>
     */
    public static function factors(): array
    {
        /** @var array<string, int> $weights */
        $weights = config('tprm.scoring.inherent_weights');

        return [
            'DATA' => [
                'label' => 'Data sensitivity and volume',
                'weight' => $weights['DATA'],
                'source' => 'trd',
                'description' => 'Highest data classification the third party will access, process, store or transmit, '
                    .'multiplied by a volume band factor. Appendix A questions A1 and A2.',
                // A1. The score is multiplied by the A2 volume factor in the
                // calculator, which is why this list is classification only.
                'options' => self::options([
                    ['none', 'No data', 0.0],
                    ['public', 'Public', 0.1],
                    ['internal', 'Internal', 0.3],
                    ['confidential', 'Confidential', 0.7],
                    ['restricted', 'Restricted — customer PII, financial, cardholder or authentication data', 1.0],
                ]),
            ],

            'ACCESS' => [
                'label' => 'System and network access',
                'weight' => $weights['ACCESS'],
                'source' => 'trd',
                'description' => 'The level of access to our systems the service requires. Appendix A question A5.',
                'options' => self::options([
                    ['none', 'No access to our systems', 0.0],
                    ['read_only', 'Read-only application access', 0.3],
                    ['write', 'Write access to an application', 0.5],
                    ['network_api', 'Network or API integration', 0.7],
                    ['privileged', 'Privileged or administrative access, or core banking / switch connectivity', 1.0],
                ]),
            ],

            'CRIT' => [
                'label' => 'Business criticality and operational dependency',
                'weight' => $weights['CRIT'],
                'source' => 'trd',
                'description' => 'Highest criticality among the business functions the engagement supports, '
                    .'adjusted by the longest tolerable outage. Appendix A questions A7 and A8.',
                'options' => self::options([
                    ['standard', 'Standard', 0.2],
                    ['important', 'Important', 0.6],
                    ['critical', 'Critical', 1.0],
                ]),
            ],

            'REG' => [
                'label' => 'Regulatory and compliance exposure',
                'weight' => $weights['REG'],
                // DERIVED: the TRD names the regimes but not their severity.
                'source' => 'derived',
                'description' => 'Count and severity of the regulatory regimes applying to this service, normalised '
                    .'to 0–1. The per-regime severities are this product\'s defaults, not a regulatory statement — '
                    .'edit them to your own risk function\'s view. Appendix A question A10.',
                'options' => self::options([
                    ['cbn_cyber', 'CBN Risk-Based Cybersecurity Framework', 1.0],
                    ['aml_cft', 'AML/CFT', 1.0],
                    ['ndpa', 'Nigeria Data Protection Act', 0.9],
                    ['pci_dss', 'PCI DSS', 0.9],
                    ['open_banking', 'Open banking', 0.7],
                    ['sector_specific', 'Sector-specific regulation', 0.6],
                    ['consumer_protection', 'CBN consumer protection', 0.5],
                ]),
            ],

            'SUB' => [
                'label' => 'Substitutability and concentration',
                'weight' => $weights['SUB'],
                'source' => 'trd',
                'description' => 'How readily this provider could be replaced. A time to transition of more than six '
                    .'months scores as sole source regardless of the answer given. Appendix A questions A12 and A13.',
                'options' => self::options([
                    ['many', 'Many alternatives', 0.1],
                    ['several', 'Several alternatives', 0.35],
                    ['few', 'Few alternatives', 0.7],
                    ['sole', 'Sole source', 1.0],
                ]),
            ],

            'GEO' => [
                'label' => 'Geography and cross-border',
                'weight' => $weights['GEO'],
                'source' => 'trd',
                'description' => 'Where data is stored and processed, and whether a lawful transfer basis is recorded. '
                    .'A jurisdiction that impedes supervisory access adds 0.2, capped at 1.0. '
                    .'Appendix A questions A3 and A4.',
                'options' => self::options([
                    ['domestic', 'Nigeria only', 0.1],
                    ['ecowas', 'ECOWAS', 0.3],
                    ['adequate_basis', 'Outside ECOWAS, with a recorded NDPA §41 transfer basis', 0.6],
                    ['no_basis', 'Outside Nigeria with no recorded transfer basis', 1.0],
                ]),
            ],

            'FIN' => [
                'label' => 'Financial exposure',
                'weight' => $weights['FIN'],
                // DERIVED: the TRD says "banded against tenant thresholds" and
                // gives none, because they depend on the institution's size.
                'source' => 'derived',
                'description' => 'Annual spend, banded. These NGN bands are sized for a mid-tier commercial bank and '
                    .'are a starting point — a microfinance bank should lower every one of them. '
                    .'Appendix A question A14.',
                'options' => self::options([
                    ['under_10m', 'Under ₦10m', 0.1],
                    ['10m_50m', '₦10m – ₦50m', 0.3],
                    ['50m_250m', '₦50m – ₦250m', 0.55],
                    ['250m_1b', '₦250m – ₦1bn', 0.8],
                    ['over_1b', 'Over ₦1bn', 1.0],
                ]),
            ],
        ];
    }

    /**
     * The volume band multiplier applied to the DATA classification score —
     * TRD §7.2, Appendix A question A2.
     *
     * A multiplier rather than a factor of its own because a million records
     * of public data is not a risk and the model has to say so: multiplying
     * keeps volume subordinate to classification, where adding would not.
     *
     * @return array<string, float>
     */
    public static function dataVolumeBands(): array
    {
        return [
            'none' => 0.0,
            'under_1k' => 0.7,
            '1k_100k' => 0.85,
            '100k_1m' => 0.95,
            'over_1m' => 1.0,
        ];
    }

    /**
     * The RTO multiplier applied to the CRIT criticality score — TRD §7.2,
     * Appendix A question A8.
     *
     * @return array<string, float>
     */
    public static function rtoBands(): array
    {
        return [
            'under_4h' => 1.0,
            'under_24h' => 0.9,
            'under_72h' => 0.75,
            'over_72h' => 0.6,
        ];
    }

    /**
     * The ten knockout rules of TRD §7.3.
     *
     * A knockout sets a FLOOR on the tier and never reduces it. Conditions are
     * Phase 0 DSL rules evaluated against the intake answers and the
     * engagement's own attributes, so the same evaluator that decides question
     * visibility decides these.
     *
     * The citations are reproduced verbatim from the TRD. They appear on
     * screen beside the fired rule, and a wrong one tells a client a regulator
     * said something it did not.
     *
     * @return list<array{code: string, name: string, floor: string, citation: string, condition: array<string, mixed>, suspends: bool}>
     */
    public static function knockouts(): array
    {
        return [
            [
                'code' => 'KO-CORE-CONN',
                'name' => 'Direct connectivity to the core banking system or the payment switch',
                'floor' => RiskTier::Critical->value,
                'citation' => 'CBN Cyber Framework App. II §1.4, App. III §1.3',
                'suspends' => false,
                'condition' => ['any' => [
                    ['fact' => 'answer.A5', 'op' => 'eq', 'value' => 'privileged'],
                    ['fact' => 'engagement.has_core_banking_connection', 'op' => 'eq', 'value' => true],
                ]],
            ],
            [
                'code' => 'KO-CIF',
                'name' => 'Supports a function classified Critical with an RTO of four hours or less',
                'floor' => RiskTier::Critical->value,
                'citation' => 'DORA Art. 3(22); Interagency "critical activity"; BCBS P3',
                'suspends' => false,
                'condition' => ['all' => [
                    ['fact' => 'engagement.max_function_criticality', 'op' => 'eq', 'value' => 'critical'],
                    ['fact' => 'engagement.min_function_rto_hours', 'op' => 'lte', 'value' => 4],
                ]],
            ],
            [
                'code' => 'KO-CHD',
                'name' => 'Stores, processes or transmits cardholder data',
                'floor' => RiskTier::Critical->value,
                'citation' => 'PCI DSS 12.8',
                'suspends' => false,
                'condition' => ['any' => [
                    ['fact' => 'answer.A17', 'op' => 'eq', 'value' => true],
                    ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true],
                ]],
            ],
            [
                'code' => 'KO-PII-XB',
                'name' => 'Personal data crosses a border with no recorded lawful transfer basis',
                'floor' => RiskTier::Critical->value,
                'citation' => 'NDPA §41(2); GAID Art. 34(2)',
                'suspends' => false,
                'condition' => ['all' => [
                    ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true],
                    ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
                    ['not' => ['fact' => 'engagement.transfer_basis', 'op' => 'in', 'value' => [
                        'ndpa_41_law', 'bcr', 'scc', 'code_of_conduct', 'certification', 'derogation_43',
                    ]]],
                ]],
            ],
            [
                'code' => 'KO-REGACT',
                'name' => 'Performs a regulated activity on our behalf',
                'floor' => RiskTier::High->value,
                'citation' => 'REG-NG-05; BOFIA agency rules',
                'suspends' => false,
                'condition' => ['any' => [
                    ['fact' => 'answer.A11', 'op' => 'eq', 'value' => true],
                    ['fact' => 'engagement.type', 'op' => 'eq', 'value' => 'agency'],
                ]],
            ],
            [
                'code' => 'KO-SOLE',
                'name' => 'Sole-source provider of a critical or important function',
                'floor' => RiskTier::Critical->value,
                'citation' => 'DORA Art. 29; BCBS P3',
                'suspends' => false,
                'condition' => ['all' => [
                    ['fact' => 'engagement.substitutability', 'op' => 'eq', 'value' => 'sole'],
                    ['fact' => 'engagement.max_function_criticality', 'op' => 'in', 'value' => ['critical', 'important']],
                ]],
            ],
            [
                'code' => 'KO-SANCTION',
                'name' => 'Confirmed sanctions or adverse-PEP true match on the entity or a beneficial owner',
                'floor' => RiskTier::Critical->value,
                'citation' => 'CBN AML/CFT Reg. 6, Reg. 29',
                // The only knockout that also suspends. AC-08.
                'suspends' => true,
                'condition' => ['fact' => 'third_party.has_true_match', 'op' => 'eq', 'value' => true],
            ],
            [
                'code' => 'KO-PRIV',
                'name' => 'Holds privileged or administrative access to production systems',
                'floor' => RiskTier::High->value,
                'citation' => 'CBN Cyber App. III §1.3',
                'suspends' => false,
                'condition' => ['any' => [
                    ['fact' => 'answer.A5', 'op' => 'eq', 'value' => 'privileged'],
                    ['fact' => 'engagement.has_privileged_access', 'op' => 'eq', 'value' => true],
                ]],
            ],
            [
                'code' => 'KO-PII-VOL',
                'name' => 'Processes personal data of more than 100,000 data subjects',
                'floor' => RiskTier::High->value,
                'citation' => 'NDPA §44; NDPR audit thresholds',
                'suspends' => false,
                'condition' => ['fact' => 'answer.A2', 'op' => 'in', 'value' => ['100k_1m', 'over_1m']],
            ],
            [
                'code' => 'KO-NOCONTRACT',
                'name' => 'Active engagement with no executed contract',
                'floor' => RiskTier::High->value,
                'citation' => 'Interagency; GAID Art. 34(2)',
                'suspends' => false,
                'condition' => ['all' => [
                    ['fact' => 'engagement.status', 'op' => 'eq', 'value' => 'active'],
                    ['fact' => 'engagement.has_executed_contract', 'op' => 'eq', 'value' => false],
                ]],
            ],
        ];
    }

    /**
     * Tier edges on the weighted 0–100 score, before knockouts (FR-TIER-03).
     *
     * @return array<string, array{int, int}>
     */
    public static function bandEdges(): array
    {
        /** @var array<string, array{int, int}> $edges */
        $edges = config('tprm.scoring.inherent_tiers');

        return $edges;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float}>  $rows
     * @return list<array{value: string, label: string, score: float}>
     */
    private static function options(array $rows): array
    {
        return array_map(
            fn (array $row) => ['value' => $row[0], 'label' => $row[1], 'score' => $row[2]],
            $rows
        );
    }
}
