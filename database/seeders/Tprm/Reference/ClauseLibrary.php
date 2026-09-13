<?php

namespace Database\Seeders\Tprm\Reference;

/**
 * The mandatory contract clause library — TRD Appendix C.
 *
 * `is_blocking` IS THE FEATURE. A clause marked blocking, and applicable to an
 * engagement by its `applicability_rule`, stops that engagement reaching
 * `active` while it is absent or partial without an approved waiver
 * (FR-CTR-05, AC-06). No competitor ships this: everyone else produces a gap
 * report and leaves the institution to notice it.
 *
 * `applicability_rule` is a Phase 0 DSL rule, evaluated against the
 * engagement's own attributes. That is what keeps the gate credible — a clause
 * required only of cross-border personal-data processors must not appear as a
 * gap on a stationery contract, or the whole report gets ignored.
 *
 * SYSTEM-OWNED CLAUSES CANNOT BE DELETED BY A TENANT, only waived per
 * instance with an approver and an expiry. A tenant that could delete
 * `CBN-CYB-05` could make its own audit-rights gap disappear, which is exactly
 * the gap the regulator cares about.
 *
 * `model_text` is left NULL here. The TRD asks for model text drafted for
 * Nigerian law; drafting contract language is a lawyer's job, not a seeder's,
 * and a plausible-looking clause that a client pastes into a real contract is
 * a liability rather than a feature. The column and the settings screen exist
 * so a tenant's legal function can supply its own; Phase 4 ships reviewed
 * text.
 */
class ClauseLibrary
{
    /* Rule fragments, named so the intent reads rather than the JSON. */

    private const IF_PERSONAL_DATA = ['fact' => 'engagement.processes_personal_data', 'op' => 'eq', 'value' => true];

    private const IF_PCI = ['fact' => 'engagement.pci_in_scope', 'op' => 'eq', 'value' => true];

    private const IF_CROSS_BORDER = ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true];

    private const IF_CRITICAL_FUNCTION = ['fact' => 'engagement.supports_critical_function', 'op' => 'eq', 'value' => true];

    private const IF_AGENCY = ['fact' => 'engagement.type', 'op' => 'eq', 'value' => 'agency'];

    private const IF_ACCESS_GRANTED = ['fact' => 'engagement.has_privileged_access', 'op' => 'eq', 'value' => true];

    /**
     * @return list<array{
     *   code: string, title: string, category: string, regulatory_source: string,
     *   citation: string, is_blocking: bool, applicability_rule: array<string, mixed>|null,
     *   guidance: string|null
     * }>
     */
    public static function clauses(): array
    {
        return [
            /* ---- CBN Cyber Framework 2024 §2.3 ------------------------- */
            [
                'code' => 'CBN-CYB-03',
                'title' => 'Security measures meeting the institution\'s cybersecurity programme objectives',
                'category' => 'security',
                'regulatory_source' => 'CBN Risk-Based Cybersecurity Framework 2024',
                'citation' => 'CBN Cyber 2024 §2.3(iii)',
                'is_blocking' => true,
                'applicability_rule' => null,
                'guidance' => 'The contract must oblige the third party to security measures that meet the institution\'s own programme objectives, not merely the third party\'s standard terms.',
            ],
            [
                'code' => 'CBN-CYB-05',
                'title' => 'Right to audit the third party or to receive its audit reports',
                'category' => 'assurance',
                'regulatory_source' => 'CBN Risk-Based Cybersecurity Framework 2024',
                'citation' => 'CBN Cyber 2024 §2.3(v)',
                'is_blocking' => true,
                'applicability_rule' => null,
                // The clause AC-06 is written around, and the commonest real
                // gap: a vendor's standard MSA almost never grants it.
                'guidance' => 'Without an audit right, no independent assurance over this vendor is obtainable at all, and every answer it gives stays self-attested.',
            ],
            [
                'code' => 'CBN-CYB-06',
                'title' => 'Compliance with PCI DSS, NDPA, ISO 27001 and ISO 8583 as applicable',
                'category' => 'compliance',
                'regulatory_source' => 'CBN Risk-Based Cybersecurity Framework 2024',
                'citation' => 'CBN Cyber 2024 §2.3(vi)',
                'is_blocking' => true,
                'applicability_rule' => ['any' => [self::IF_PCI, self::IF_PERSONAL_DATA]],
                'guidance' => 'Blocking only where one of the named regimes actually applies to the engagement.',
            ],
            [
                'code' => 'CBN-CYB-07',
                'title' => 'Participation in business continuity response, recovery planning and testing',
                'category' => 'resilience',
                'regulatory_source' => 'CBN Risk-Based Cybersecurity Framework 2024',
                'citation' => 'CBN Cyber 2024 §2.3(vii)',
                'is_blocking' => true,
                'applicability_rule' => self::IF_CRITICAL_FUNCTION,
                'guidance' => 'Blocking for engagements supporting a critical or important function. A vendor that will not test with us cannot be relied on in a disruption.',
            ],
            [
                'code' => 'CBN-CYB-08',
                'title' => 'Insurance cover for insurable technology risks',
                'category' => 'financial',
                'regulatory_source' => 'CBN Risk-Based Cybersecurity Framework 2024',
                'citation' => 'CBN Cyber 2024 §2.3(viii)',
                'is_blocking' => false,
                'applicability_rule' => null,
                'guidance' => 'Required, but not a gate on activation: insurance is a recovery mechanism, not a control.',
            ],
            [
                'code' => 'CBN-ACC-01',
                'title' => 'Controlled, approved, time-bounded and supervised vendor access',
                'category' => 'access',
                'regulatory_source' => 'CBN Risk-Based Cybersecurity Framework 2024',
                'citation' => 'CBN Cyber 2024 App. III §1.3',
                'is_blocking' => true,
                'applicability_rule' => self::IF_ACCESS_GRANTED,
                'guidance' => 'Blocking wherever the engagement involves access to our systems.',
            ],

            /* ---- NDPA 2023 / GAID 2025 --------------------------------- */
            [
                'code' => 'NDPA-DPA-01',
                'title' => 'Data processing agreement containing all twenty GAID Art. 34(2) elements',
                'category' => 'data_protection',
                'regulatory_source' => 'NDPA 2023; GAID 2025',
                'citation' => 'NDPA §29(2); GAID Art. 34(2)(a)–(t)',
                'is_blocking' => true,
                'applicability_rule' => self::IF_PERSONAL_DATA,
                // The TRD lists these as twenty separately tracked rows. They
                // are one clause here with a twenty-element extraction schema
                // behind it, because a DPA is one document that either
                // contains an element or does not: twenty clause rows against
                // one document produce twenty near-identical gap findings and
                // a report nobody reads. The DpaExtractor returns a
                // present/partial/absent verdict per element, which is where
                // the element-level detail belongs.
                'guidance' => 'The DPA extractor checks each of the twenty elements (a)–(t) individually and returns a verdict per element; this clause is satisfied only when every mandatory element is present.',
            ],
            [
                'code' => 'NDPA-BR-01',
                'title' => 'Processor breach notification within a period enabling the 72-hour NDPC notification',
                'category' => 'data_protection',
                'regulatory_source' => 'NDPA 2023',
                'citation' => 'NDPA §40(1)–(2)',
                'is_blocking' => true,
                'applicability_rule' => self::IF_PERSONAL_DATA,
                'guidance' => 'Our 72 hours start when the processor tells us. A contract that does not bind the processor to a shorter period makes our own deadline unmeetable.',
            ],
            [
                'code' => 'NDPA-XB-01',
                'title' => 'Cross-border transfer basis and its record',
                'category' => 'data_protection',
                'regulatory_source' => 'NDPA 2023',
                'citation' => 'NDPA §41',
                'is_blocking' => true,
                'applicability_rule' => ['all' => [self::IF_PERSONAL_DATA, self::IF_CROSS_BORDER]],
                'guidance' => 'Blocking where personal data leaves Nigeria. The absence of a recorded lawful basis is also the KO-PII-XB knockout.',
            ],
            [
                'code' => 'NDPA-SP-01',
                'title' => 'Sub-processor notification and consent',
                'category' => 'data_protection',
                'regulatory_source' => 'NDPA 2023; GAID 2025',
                'citation' => 'NDPA §29(1); GAID Art. 34(3)',
                'is_blocking' => true,
                'applicability_rule' => self::IF_PERSONAL_DATA,
                'guidance' => 'Without it, a vendor may change its sub-processors — and therefore where our data sits — without telling us.',
            ],

            /* ---- PCI DSS v4.0.1 ---------------------------------------- */
            [
                'code' => 'PCI-12.8.2',
                'title' => 'TPSP written acknowledgement of responsibility for account data security',
                'category' => 'compliance',
                'regulatory_source' => 'PCI DSS v4.0.1',
                'citation' => 'PCI DSS v4.0.1 12.8.2',
                'is_blocking' => true,
                'applicability_rule' => self::IF_PCI,
                'guidance' => null,
            ],
            [
                'code' => 'PCI-12.8.5',
                'title' => 'Documented responsibility matrix across PCI DSS requirements',
                'category' => 'compliance',
                'regulatory_source' => 'PCI DSS v4.0.1',
                'citation' => 'PCI DSS v4.0.1 12.8.5',
                'is_blocking' => true,
                'applicability_rule' => self::IF_PCI,
                'guidance' => 'The matrix builder produces this as the audit deliverable; the clause is what obliges the TPSP to agree it.',
            ],

            /* ---- General lifecycle clauses ----------------------------- */
            [
                'code' => 'GEN-DATA-RET',
                'title' => 'Data return and certified destruction on termination',
                'category' => 'exit',
                'regulatory_source' => 'Interagency Guidance 2023; DORA',
                'citation' => 'DORA Art. 30(2)(d); Interagency termination',
                'is_blocking' => true,
                'applicability_rule' => null,
                'guidance' => 'The clause that makes an exit plan executable. Without it, "get our data back" is a negotiation held at the worst possible moment.',
            ],
            [
                'code' => 'GEN-TRANS',
                'title' => 'Transition assistance period with continued service',
                'category' => 'exit',
                'regulatory_source' => 'Interagency Guidance 2023; DORA',
                'citation' => 'DORA Art. 30(3)(f); Interagency termination',
                'is_blocking' => true,
                'applicability_rule' => self::IF_CRITICAL_FUNCTION,
                'guidance' => null,
            ],
            [
                'code' => 'GEN-REGACC',
                'title' => 'Regulator access to records, premises and systems',
                'category' => 'assurance',
                'regulatory_source' => 'CBN supervision; DORA',
                'citation' => 'DORA Art. 30(3)(e); CBN supervisory access',
                'is_blocking' => true,
                'applicability_rule' => null,
                'guidance' => 'Distinct from our own audit right: a supervisor\'s access cannot be exercised under our contract unless the contract grants it.',
            ],
            [
                'code' => 'GEN-SUBCON',
                'title' => 'Sub-contracting consent, notification and flow-down of obligations',
                'category' => 'supply_chain',
                'regulatory_source' => 'Interagency Guidance 2023; DORA',
                'citation' => 'DORA Art. 30(2)(a); Interagency',
                'is_blocking' => true,
                'applicability_rule' => null,
                'guidance' => 'Flow-down is the part usually missing: consent to sub-contract without an obligation to impose the same terms downstream leaves the nth party unbound.',
            ],
            [
                'code' => 'GEN-AGENT-LIAB',
                'title' => 'Liability for the acts of agents and intermediaries',
                'category' => 'legal',
                'regulatory_source' => 'CBN Consumer Protection Regulations',
                'citation' => 'CBN Consumer Protection Regulations §4.6.6',
                'is_blocking' => true,
                'applicability_rule' => self::IF_AGENCY,
                'guidance' => 'The institution is liable for its agents\' acts regardless; the clause governs recourse, not liability.',
            ],
            [
                'code' => 'GEN-SLA',
                'title' => 'Quantitative and qualitative performance targets',
                'category' => 'performance',
                'regulatory_source' => 'Interagency Guidance 2023; DORA',
                'citation' => 'DORA Art. 30(3)(a); Interagency',
                'is_blocking' => true,
                'applicability_rule' => self::IF_CRITICAL_FUNCTION,
                'guidance' => 'A target expressed only qualitatively cannot be breached, measured or credited.',
            ],
            [
                'code' => 'GEN-INC-NOT',
                'title' => 'Incident notification within a defined period',
                'category' => 'incident',
                'regulatory_source' => 'CBN Cyber 2024; DORA',
                'citation' => 'CBN Cyber 2024 §5.0; DORA Art. 30(2)(f)',
                'is_blocking' => true,
                'applicability_rule' => null,
                'guidance' => 'Both regulatory clocks start from what the vendor tells us. "Promptly" is not a defined period.',
            ],
            [
                'code' => 'GEN-TERM',
                'title' => 'Termination rights including for regulatory direction and control weakness',
                'category' => 'exit',
                'regulatory_source' => 'DORA; Interagency Guidance 2023',
                'citation' => 'DORA Art. 28(7); Interagency',
                'is_blocking' => true,
                'applicability_rule' => null,
                'guidance' => 'Termination for convenience is not enough: the institution must be able to exit when a supervisor directs it or when the vendor\'s controls fail.',
            ],
        ];
    }
}
