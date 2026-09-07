<?php

namespace Database\Seeders\Tprm\Reference;

/**
 * The shipped questionnaire packs — TRD Appendix B, FR-ASM-04.
 *
 * EVERY QUESTION HERE IS OURS. The phase prompt is explicit: "write the
 * question content yourself; do not reproduce licensed SIG content", and the
 * same restraint applies to the CAIQ. Where a pack is described as
 * SIG-aligned or CAIQ-aligned, what is aligned is the MAPPING — our own
 * questions carry the SIG domain letter or the CCM control ID, version-stamped
 * — never the licensed text.
 *
 * EVERY QUESTION CARRIES AT LEAST ONE CONTROL MAP, because FR-ASM-05 blocks
 * publication otherwise and because the discipline is the point: a question
 * that cannot name the control it tests is a question that should not be
 * asked. Writing these packs against that rule is what keeps them at forty
 * questions rather than two hundred.
 *
 * Five packs ship in this phase, in the priority order the phase prompt sets.
 * The remaining nine in Appendix B are later work; the module reports which
 * packs it holds rather than implying it holds all fourteen.
 */
class QuestionnairePacks
{
    /**
     * How many questions a pack actually ships, against the size Appendix B
     * specifies.
     *
     * Computed rather than stated, so the two cannot drift: adding a question
     * to a pack updates the count on the same commit.
     *
     * @return array{shipped: int, declared: int|null, status: string}
     */
    public static function completeness(array $pack): array
    {
        $shipped = 0;

        foreach ($pack['sections'] as $section) {
            $shipped += count($section['questions']);
        }

        $declared = $pack['declared_question_count'] ?? null;

        return [
            'shipped' => $shipped,
            'declared' => $declared,
            'status' => ($declared === null || $shipped >= $declared) ? 'complete' : 'partial',
        ];
    }

    /**
     * @return list<array{
     *   code: string, name: string, description: string, version: string,
     *   framework_tags: list<string>, applies_to: array<string, mixed>,
     *   sections: list<array<string, mixed>>
     * }>
     */
    public static function all(): array
    {
        return [
            self::cbnCyberCore(),
            self::ndpaProcessor(),
            self::pciTpsp(),
            self::isoSupplierSet(),
            self::internalLowTier(),
        ];
    }

    /**
     * CBN Cyber §2.3 Core — mandatory for ICT engagements in Nigerian tenants.
     * One section per §2.3 clause plus the Appendix II/III controls.
     */
    private static function cbnCyberCore(): array
    {
        return [
            'code' => 'CBN-CYBER-CORE',
            'declared_question_count' => 55,
            'name' => 'CBN Cyber §2.3 Core',
            'description' => 'The third-party obligations of the CBN Risk-Based Cybersecurity Framework 2024, '
                .'section 2.3, plus the Appendix II register and Appendix III access controls. Mandatory for ICT '
                .'engagements at Nigerian institutions.',
            'version' => '1.0',
            'framework_tags' => ['cbn_cyber_2024', 'iso27002'],
            'applies_to' => ['engagement_types' => ['ict_service', 'outsourcing', 'intra_group'], 'tiers' => ['moderate', 'high', 'critical']],
            'sections' => [
                [
                    'code' => 'governance', 'title' => 'Security governance', 'domain_tag' => 'governance',
                    'questions' => [
                        [
                            'code' => 'CBN-GOV-01', 'text' => 'Do you maintain a documented information security policy approved by your board or an equivalent governing body, and reviewed at least annually?',
                            'risk_weight' => 3, 'evidence_required' => true, 'evidence_types' => ['security_policy'],
                            'maps' => [['iso27002', '2022', '5.1'], ['cbn_cyber_2024', '2024', '2.3(iii)']],
                        ],
                        [
                            'code' => 'CBN-GOV-02', 'text' => 'Is a named individual accountable for information security in your organisation, with a direct reporting line to executive management?',
                            'risk_weight' => 2,
                            'maps' => [['iso27002', '2022', '5.2']],
                        ],
                        [
                            'code' => 'CBN-GOV-03', 'text' => 'Do your personnel complete information security awareness training at least annually, and can you evidence completion for the staff assigned to our account?',
                            'risk_weight' => 2, 'evidence_required' => true,
                            'maps' => [['iso27002', '2022', '6.3'], ['cbn_cyber_2024', '2024', '2.3(ii)']],
                        ],
                    ],
                ],
                [
                    'code' => 'access', 'title' => 'Access control', 'domain_tag' => 'security',
                    'questions' => [
                        [
                            'code' => 'CBN-ACC-01', 'text' => 'Is all access to our systems and data granted on a least-privilege basis, approved by a named individual, and reviewed at least quarterly?',
                            'risk_weight' => 5, 'is_critical' => true, 'evidence_required' => true,
                            'maps' => [['iso27002', '2022', '5.18'], ['cbn_cyber_2024', '2024', 'App.III §1.3']],
                        ],
                        [
                            'code' => 'CBN-ACC-02', 'text' => 'Is multi-factor authentication enforced for every account of yours that can reach our environment, including administrative and emergency accounts?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['iso27002', '2022', '8.5'], ['csf20', '2.0', 'GV.SC-05']],
                        ],
                        [
                            'code' => 'CBN-ACC-03', 'text' => 'Are privileged actions taken in our environment logged, retained for at least twelve months, and available to us on request?',
                            'risk_weight' => 4, 'evidence_required' => true,
                            'maps' => [['iso27002', '2022', '8.15'], ['iso27002', '2022', '8.2']],
                        ],
                        [
                            'code' => 'CBN-ACC-04', 'text' => 'Is access for a departing member of your staff revoked within one business day of their departure?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '6.5'], ['iso27002', '2022', '5.18']],
                        ],
                    ],
                ],
                [
                    'code' => 'assurance', 'title' => 'Independent assurance and audit rights', 'domain_tag' => 'assurance',
                    'questions' => [
                        [
                            'code' => 'CBN-ASR-01', 'text' => 'Do you hold a current independent assurance report — SOC 2 Type II, ISO/IEC 27001 certification, or equivalent — whose scope covers the service you provide to us?',
                            'risk_weight' => 5, 'evidence_required' => true, 'evidence_types' => ['soc2_type2', 'iso27001_cert'],
                            'min_assurance_level' => 'independently_assured',
                            'maps' => [['iso27002', '2022', '5.35'], ['cbn_cyber_2024', '2024', '2.3(vi)']],
                        ],
                        [
                            'code' => 'CBN-ASR-02', 'text' => 'Do you accept our right, and our regulator\'s right, to audit the services provided to us, including on-site inspection where required?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['cbn_cyber_2024', '2024', '2.3(v)'], ['dora', '2022/2554', 'Art. 30(3)(e)']],
                        ],
                        [
                            'code' => 'CBN-ASR-03', 'text' => 'Is an independent penetration test of the systems supporting our service carried out at least annually, with remediation tracked to closure?',
                            'risk_weight' => 4, 'evidence_required' => true, 'evidence_types' => ['pentest_report'],
                            'maps' => [['iso27002', '2022', '8.8'], ['iso27002', '2022', '8.29']],
                        ],
                    ],
                ],
                [
                    'code' => 'resilience', 'title' => 'Continuity and incident response', 'domain_tag' => 'resilience',
                    'questions' => [
                        [
                            'code' => 'CBN-RES-01', 'text' => 'Do you maintain and test a business continuity and disaster recovery plan covering our service at least annually, and will you include us in that testing?',
                            'risk_weight' => 4, 'evidence_required' => true, 'evidence_types' => ['bcp_test_report'],
                            'maps' => [['iso27002', '2022', '5.30'], ['cbn_cyber_2024', '2024', '2.3(vii)']],
                        ],
                        [
                            'code' => 'CBN-RES-02', 'text' => 'Will you notify us of any security incident affecting our data or service within twenty-four hours of becoming aware of it?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['iso27002', '2022', '5.24'], ['csf20', '2.0', 'GV.SC-08']],
                        ],
                        [
                            'code' => 'CBN-RES-03', 'text' => 'What recovery time objective do you commit to for the service you provide to us, and has it been demonstrated in a test rather than only stated?',
                            'type' => 'free_text', 'risk_weight' => 3,
                            'maps' => [['iso27002', '2022', '5.29']],
                        ],
                    ],
                ],
                [
                    'code' => 'supply_chain', 'title' => 'Your own suppliers', 'domain_tag' => 'supply_chain',
                    'questions' => [
                        [
                            'code' => 'CBN-SUP-01', 'text' => 'Do you maintain a current inventory of the sub-contractors and sub-processors involved in delivering our service, and will you notify us before it changes?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '5.21'], ['csf20', '2.0', 'GV.SC-07']],
                        ],
                        [
                            'code' => 'CBN-SUP-02', 'text' => 'Are the security obligations you accept in your contract with us flowed down to those sub-contractors?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '5.20'], ['nist80053r5', 'r5', 'SR-3']],
                        ],
                        [
                            'code' => 'CBN-SUP-03', 'text' => 'Do you carry insurance covering technology and cyber risks arising from the service provided to us?',
                            'risk_weight' => 2, 'evidence_required' => true, 'evidence_types' => ['insurance_cert'],
                            'maps' => [['cbn_cyber_2024', '2024', '2.3(viii)']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** NDPA / GAID Processor Assessment — structured to produce the CAR evidence directly. */
    private static function ndpaProcessor(): array
    {
        return [
            'code' => 'NDPA-PROCESSOR',
            'declared_question_count' => 48,
            'name' => 'NDPA / GAID Processor Assessment',
            'description' => 'The processor obligations of the Nigeria Data Protection Act 2023 and the General '
                .'Application and Implementation Directive 2025, structured so the answers feed the Compliance '
                .'Audit Return evidence pack directly.',
            'version' => '1.0',
            'framework_tags' => ['ndpa_gaid', 'iso27002'],
            'applies_to' => ['flags' => ['processes_personal_data']],
            'sections' => [
                [
                    'code' => 'lawfulness', 'title' => 'Basis and instructions', 'domain_tag' => 'data_protection',
                    'questions' => [
                        [
                            'code' => 'NDPA-LAW-01', 'text' => 'Do you process personal data only on our documented instructions, and will you tell us if you believe an instruction breaches the NDPA?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['ndpa_gaid', '2023/2025', 'NDPA §44'], ['ndpa_gaid', '2023/2025', 'GAID Art. 34']],
                        ],
                        [
                            'code' => 'NDPA-LAW-02', 'text' => 'Is there a written data processing agreement between us containing the mandatory elements of GAID Article 34(2)?',
                            'risk_weight' => 5, 'is_critical' => true, 'evidence_required' => true, 'evidence_types' => ['dpa'],
                            'maps' => [['ndpa_gaid', '2023/2025', 'GAID Art. 34']],
                        ],
                    ],
                ],
                [
                    'code' => 'security', 'title' => 'Security of processing', 'domain_tag' => 'data_protection',
                    'questions' => [
                        [
                            'code' => 'NDPA-SEC-01', 'text' => 'Is personal data encrypted in transit and at rest, and are the keys managed separately from the data?',
                            'risk_weight' => 5, 'evidence_required' => true,
                            'maps' => [['ndpa_gaid', '2023/2025', 'NDPA §39'], ['iso27002', '2022', '8.24']],
                        ],
                        [
                            'code' => 'NDPA-SEC-02', 'text' => 'Are your personnel who access personal data bound by a written confidentiality obligation?',
                            'risk_weight' => 3,
                            'maps' => [['iso27002', '2022', '6.6'], ['ndpa_gaid', '2023/2025', 'NDPA §39']],
                        ],
                    ],
                ],
                [
                    'code' => 'breach', 'title' => 'Breach notification', 'domain_tag' => 'data_protection',
                    'questions' => [
                        [
                            'code' => 'NDPA-BR-01', 'text' => 'Will you notify us of a personal data breach without undue delay and in any case within twenty-four hours of becoming aware, so that we can meet our own seventy-two hour obligation to the Commission?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['ndpa_gaid', '2023/2025', 'NDPA §40']],
                        ],
                    ],
                ],
                [
                    'code' => 'transfers', 'title' => 'Cross-border transfers', 'domain_tag' => 'data_protection',
                    'visibility_rule' => ['fact' => 'engagement.cross_border', 'op' => 'eq', 'value' => true],
                    'questions' => [
                        [
                            'code' => 'NDPA-XB-01', 'text' => 'Which countries is our personal data transferred to or accessible from, and on what lawful basis under NDPA section 41?',
                            'type' => 'free_text', 'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['ndpa_gaid', '2023/2025', 'NDPA §41']],
                        ],
                    ],
                ],
                [
                    'code' => 'subprocessors', 'title' => 'Sub-processors', 'domain_tag' => 'supply_chain',
                    'questions' => [
                        [
                            'code' => 'NDPA-SP-01', 'text' => 'Do you obtain our written authorisation before engaging a new sub-processor for our personal data?',
                            'risk_weight' => 4,
                            'maps' => [['ndpa_gaid', '2023/2025', 'GAID Art. 34'], ['iso27002', '2022', '5.21']],
                        ],
                        [
                            'code' => 'NDPA-SP-02', 'text' => 'Do you impose the same data protection obligations on your sub-processors as you accept from us?',
                            'risk_weight' => 4,
                            'maps' => [['ndpa_gaid', '2023/2025', 'NDPA §44'], ['iso27002', '2022', '5.20']],
                        ],
                    ],
                ],
                [
                    'code' => 'rights_and_deletion', 'title' => 'Data subject rights and deletion', 'domain_tag' => 'data_protection',
                    'questions' => [
                        [
                            'code' => 'NDPA-DSR-01', 'text' => 'Will you assist us in responding to data subject access, rectification and erasure requests within our statutory timescales?',
                            'risk_weight' => 3,
                            'maps' => [['ndpa_gaid', '2023/2025', 'NDPA §44']],
                        ],
                        [
                            'code' => 'NDPA-DEL-01', 'text' => 'On termination, will you return or securely delete all personal data and provide written certification of deletion?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['iso27002', '2022', '8.10'], ['ndpa_gaid', '2023/2025', 'GAID Art. 34']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** PCI DSS TPSP — ends with the 12.8.5 responsibility matrix. */
    private static function pciTpsp(): array
    {
        return [
            'code' => 'PCI-TPSP',
            'declared_question_count' => 42,
            'name' => 'PCI DSS Third-Party Service Provider',
            'description' => 'For providers that store, process or transmit cardholder data, or that could affect '
                .'the security of the cardholder data environment. Ends with the 12.8.5 responsibility matrix.',
            'version' => '1.0',
            'framework_tags' => ['pci_dss_401'],
            'applies_to' => ['flags' => ['pci_in_scope']],
            'sections' => [
                [
                    'code' => 'compliance_status', 'title' => 'Compliance status', 'domain_tag' => 'compliance',
                    'questions' => [
                        [
                            'code' => 'PCI-CS-01', 'text' => 'Do you hold a current PCI DSS Attestation of Compliance covering the services you provide to us?',
                            'risk_weight' => 5, 'is_critical' => true, 'evidence_required' => true, 'evidence_types' => ['pci_aoc'],
                            'min_assurance_level' => 'independently_assured',
                            'maps' => [['pci_dss_401', '4.0.1', '12.8.4']],
                        ],
                        [
                            'code' => 'PCI-CS-02', 'text' => 'Do you acknowledge in writing your responsibility for the security of the account data you possess or otherwise handle on our behalf?',
                            'risk_weight' => 5, 'is_critical' => true,
                            'maps' => [['pci_dss_401', '4.0.1', '12.9.1'], ['pci_dss_401', '4.0.1', '12.8.2']],
                        ],
                    ],
                ],
                [
                    'code' => 'chd_handling', 'title' => 'Cardholder data handling', 'domain_tag' => 'security',
                    'questions' => [
                        [
                            'code' => 'PCI-CHD-01', 'text' => 'Is the primary account number rendered unreadable wherever it is stored, and is it never stored after authorisation where storage is not required?',
                            'risk_weight' => 5, 'evidence_required' => true,
                            'maps' => [['pci_dss_401', '4.0.1', '3']],
                        ],
                        [
                            'code' => 'PCI-CHD-02', 'text' => 'Is cardholder data encrypted with strong cryptography whenever it is transmitted across open or public networks?',
                            'risk_weight' => 5,
                            'maps' => [['pci_dss_401', '4.0.1', '4']],
                        ],
                    ],
                ],
                [
                    'code' => 'responsibility', 'title' => 'Responsibility matrix', 'domain_tag' => 'compliance',
                    'questions' => [
                        [
                            'code' => 'PCI-RM-01', 'text' => 'Will you agree a documented matrix stating, for each PCI DSS requirement, whether responsibility rests with you, with us, or is shared?',
                            'risk_weight' => 4,
                            'maps' => [['pci_dss_401', '4.0.1', '12.8.5'], ['pci_dss_401', '4.0.1', '12.9.2']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** ISO 27001:2022 Supplier Set — the A.5.19–A.5.23 focus. */
    private static function isoSupplierSet(): array
    {
        return [
            'code' => 'ISO-SUPPLIER',
            'declared_question_count' => 40,
            'name' => 'ISO/IEC 27001:2022 Supplier Set',
            'description' => 'Focused on ISO/IEC 27002:2022 controls 5.19 to 5.23 — the supplier relationship '
                .'controls proper — for ICT engagements at Moderate tier and above.',
            'version' => '1.0',
            'framework_tags' => ['iso27002', 'iso27036_2'],
            'applies_to' => ['engagement_types' => ['ict_service', 'outsourcing'], 'tiers' => ['moderate', 'high', 'critical']],
            'sections' => [
                [
                    'code' => 'supplier_controls', 'title' => 'Supplier relationship controls', 'domain_tag' => 'supply_chain',
                    'questions' => [
                        [
                            'code' => 'ISO-SUP-01', 'text' => 'Do you operate a documented process for managing information security in your own supplier relationships?',
                            'risk_weight' => 3, 'evidence_required' => true,
                            'maps' => [['iso27002', '2022', '5.19'], ['iso27036_2', '2022', '7.1']],
                        ],
                        [
                            'code' => 'ISO-SUP-02', 'text' => 'Are information security requirements agreed in writing with each of your suppliers that touches our service?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '5.20'], ['iso27036_2', '2022', '7.3']],
                        ],
                        [
                            'code' => 'ISO-SUP-03', 'text' => 'Do you assess and manage the information security risks of your ICT supply chain, including the components you build our service on?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '5.21'], ['nist80053r5', 'r5', 'SR-2']],
                        ],
                        [
                            'code' => 'ISO-SUP-04', 'text' => 'Do you monitor and review your suppliers\' service delivery and security performance, and manage changes to it?',
                            'risk_weight' => 3,
                            'maps' => [['iso27002', '2022', '5.22'], ['iso27036_2', '2022', '7.4']],
                        ],
                        [
                            'code' => 'ISO-SUP-05', 'text' => 'Where our service uses cloud services, are those services acquired, used and exited under a documented process covering security requirements?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '5.23'], ['ccm_v4', '4.0', 'STA-08']],
                        ],
                    ],
                ],
                [
                    'code' => 'exit', 'title' => 'Termination and exit', 'domain_tag' => 'exit',
                    'questions' => [
                        [
                            'code' => 'ISO-EXIT-01', 'text' => 'On termination, will you return our information and assets in an agreed, usable format within an agreed period?',
                            'risk_weight' => 4,
                            'maps' => [['iso27002', '2022', '5.11'], ['iso27036_2', '2022', '7.5']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Internal Low-Tier Attestation — completed by the relationship owner, not the vendor. */
    private static function internalLowTier(): array
    {
        return [
            'code' => 'INTERNAL-LOW',
            'declared_question_count' => 12,
            'name' => 'Internal Low-Tier Attestation',
            'description' => 'Twelve questions completed by the relationship owner without involving the vendor '
                .'(FR-ASM-13). For Low-tier engagements, where a full questionnaire costs more than the risk '
                .'justifies and would go unanswered anyway.',
            'version' => '1.0',
            'framework_tags' => ['iso27002'],
            'applies_to' => ['tiers' => ['low'], 'internal_only' => true],
            'sections' => [
                [
                    'code' => 'attestation', 'title' => 'Relationship owner attestation', 'domain_tag' => 'governance',
                    'questions' => [
                        [
                            'code' => 'INT-01', 'text' => 'Is the service still required, and still delivered as described in the engagement record?',
                            'risk_weight' => 2,
                            'maps' => [['iso27002', '2022', '5.22']],
                        ],
                        [
                            'code' => 'INT-02', 'text' => 'Has the scope of data or system access changed since the engagement was tiered?',
                            'risk_weight' => 3,
                            'maps' => [['iso27002', '2022', '5.22'], ['csf20', '2.0', 'GV.SC-07']],
                        ],
                        [
                            'code' => 'INT-03', 'text' => 'Is there a signed contract in force covering this service?',
                            'risk_weight' => 3, 'is_critical' => true,
                            'maps' => [['iso27002', '2022', '5.20']],
                        ],
                        [
                            'code' => 'INT-04', 'text' => 'Have there been any service failures, complaints or incidents involving this provider in the period?',
                            'risk_weight' => 3,
                            'maps' => [['iso27002', '2022', '5.24']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
