<?php

namespace Database\Seeders\Tprm\Reference;

/**
 * The framework libraries other than ISO 27002, and — just as importantly —
 * an honest statement of how complete each one is.
 *
 * TRD §4.3 divides these into two kinds and the distinction is load-bearing.
 * ISO 27002 control IDs, NIST 800-53r5 control IDs, CSF 2.0 subcategory IDs,
 * CCM v4 control IDs and TSC criteria IDs are STABLE — a mapping stored
 * against one still means the same thing after the next revision. SIG domain
 * letters and DORA register template codes are NOT, and a mapping against them
 * has to be re-verified on every version bump. `has_stable_keys` carries that.
 *
 * WHY SOME CATALOGUES ARE PARTIAL. Three of these — CSA CCM v4's 197 control
 * objectives, the AICPA Trust Services Criteria points of focus, and the
 * Shared Assessments SIG — are licensed content. Their identifiers are freely
 * citable and are what a mapping actually needs; their control text is not
 * ours to reproduce. Rather than seed a plausible-sounding paraphrase, the
 * catalogue holds the identifiers it can state and declares itself partial, so
 * a screen can say "14 of 197" instead of implying the library is exhaustive.
 * Inventing regulatory text in a compliance product is the one failure mode
 * this module cannot afford: a client would act on it.
 *
 * The Nigerian and European instruments below are cited by SECTION, which is
 * the citable unit, with a descriptive title of the obligation rather than a
 * quotation of it.
 */
class FrameworkLibraries
{
    /**
     * @return list<array{
     *   code: string, name: string, version: string, publisher: string,
     *   has_stable_keys: bool, declared_control_count: int|null,
     *   catalogue_status: string, catalogue_note: string|null,
     *   controls: list<array{control_id: string, title: string, domain?: string, supplier_relevant?: bool}>
     * }>
     */
    public static function all(): array
    {
        return [
            self::nist80053r5SupplyChain(),
            self::nistCsf20(),
            self::pciDss401(),
            self::cbnCyber2024(),
            self::ndpaGaid(),
            self::iso270362(),
            self::dora(),
            self::bcbsThirdParty(),
            self::csaCcmV4(),
            self::aicpaTsc(),
            self::vrmmm(),
        ];
    }

    /**
     * NIST SP 800-53 Rev. 5, the SR (Supply Chain Risk Management) family.
     *
     * Only the SR family is catalogued: 800-53r5 has over a thousand controls
     * across twenty families, and a third-party module that offers all of them
     * as mapping targets makes the mapping gate in FR-ASM-05 useless — an
     * author will find something to map to. The SR family is the part of
     * 800-53 that is about this problem.
     */
    private static function nist80053r5SupplyChain(): array
    {
        return [
            'code' => 'nist80053r5',
            'name' => 'NIST SP 800-53 Rev. 5 — Supply Chain Risk Management (SR) family',
            'version' => 'r5',
            'publisher' => 'NIST',
            'has_stable_keys' => true,
            'declared_control_count' => 12,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'The SR family only. Other 800-53 families are out of scope for supplier mapping.',
            'controls' => self::rows('Supply Chain Risk Management', [
                'SR-1' => 'Policy and Procedures',
                'SR-2' => 'Supply Chain Risk Management Plan',
                'SR-3' => 'Supply Chain Controls and Processes',
                'SR-4' => 'Provenance',
                'SR-5' => 'Acquisition Strategies, Tools, and Methods',
                'SR-6' => 'Supplier Assessments and Reviews',
                'SR-7' => 'Supply Chain Operations Security',
                'SR-8' => 'Notification Agreements',
                'SR-9' => 'Tamper Resistance and Detection',
                'SR-10' => 'Inspection of Systems or Components',
                'SR-11' => 'Component Authenticity',
                'SR-12' => 'Component Disposal',
            ], supplierRelevant: true),
        ];
    }

    /**
     * NIST CSF 2.0 — the supplier-relevant subcategories.
     *
     * GV.SC is the Cybersecurity Supply Chain Risk Management category that
     * CSF 2.0 introduced, plus the four subcategories elsewhere in the
     * framework that speak to third parties directly.
     */
    private static function nistCsf20(): array
    {
        return [
            'code' => 'csf20',
            'name' => 'NIST Cybersecurity Framework 2.0 — supplier-relevant subcategories',
            'version' => '2.0',
            'publisher' => 'NIST',
            'has_stable_keys' => true,
            'declared_control_count' => 14,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'The GV.SC category in full, plus ID.AM-04, ID.RA-09, ID.RA-10 and DE.CM-06.',
            'controls' => array_merge(
                self::rows('GV.SC — Cybersecurity Supply Chain Risk Management', [
                    'GV.SC-01' => 'A cybersecurity supply chain risk management programme, strategy, objectives, policies and processes are established and agreed to by organisational stakeholders',
                    'GV.SC-02' => 'Cybersecurity roles and responsibilities for suppliers, customers and partners are established, communicated and coordinated internally and externally',
                    'GV.SC-03' => 'Cybersecurity supply chain risk management is integrated into cybersecurity and enterprise risk management, risk assessment and improvement processes',
                    'GV.SC-04' => 'Suppliers are known and prioritised by criticality',
                    'GV.SC-05' => 'Requirements to address cybersecurity risks in supply chains are established, prioritised and integrated into contracts and other agreements with suppliers and other relevant third parties',
                    'GV.SC-06' => 'Planning and due diligence are performed to reduce risks before entering into formal supplier or other third-party relationships',
                    'GV.SC-07' => 'The risks posed by a supplier, their products and services, and other third parties are understood, recorded, prioritised, assessed, responded to and monitored over the course of the relationship',
                    'GV.SC-08' => 'Relevant suppliers and other third parties are included in incident planning, response and recovery activities',
                    'GV.SC-09' => 'Supply chain security practices are integrated into cybersecurity and enterprise risk management programmes, and their performance is monitored throughout the technology product and service life cycle',
                    'GV.SC-10' => 'Cybersecurity supply chain risk management plans include provisions for activities that occur after the conclusion of a partnership or service agreement',
                ], supplierRelevant: true),
                self::rows('Other supplier-relevant subcategories', [
                    'ID.AM-04' => 'Inventories of services provided by suppliers are maintained',
                    'ID.RA-09' => 'The authenticity and integrity of hardware and software are assessed prior to acquisition and use',
                    'ID.RA-10' => 'Critical suppliers are assessed prior to acquisition',
                    'DE.CM-06' => 'External service provider activities and services are monitored to find potentially adverse events',
                ], supplierRelevant: true),
            ),
        ];
    }

    /**
     * PCI DSS v4.0.1 — the twelve requirements, with 12.8 and 12.9 enumerated.
     *
     * 12.8 (managing TPSPs) and 12.9 (the TPSP's own acknowledgement) are the
     * requirements this module exists to evidence, so they are catalogued at
     * sub-requirement level while the other eleven stay at requirement level.
     */
    private static function pciDss401(): array
    {
        return [
            'code' => 'pci_dss_401',
            'name' => 'PCI DSS v4.0.1',
            'version' => '4.0.1',
            'publisher' => 'PCI Security Standards Council',
            'has_stable_keys' => true,
            'declared_control_count' => 19,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'The twelve requirements at requirement level, with 12.8.1–12.8.5 and 12.9.1–12.9.2 enumerated.',
            'controls' => array_merge(
                self::rows('Requirements', [
                    '1' => 'Install and maintain network security controls',
                    '2' => 'Apply secure configurations to all system components',
                    '3' => 'Protect stored account data',
                    '4' => 'Protect cardholder data with strong cryptography during transmission over open, public networks',
                    '5' => 'Protect all systems and networks from malicious software',
                    '6' => 'Develop and maintain secure systems and software',
                    '7' => 'Restrict access to system components and cardholder data by business need to know',
                    '8' => 'Identify users and authenticate access to system components',
                    '9' => 'Restrict physical access to cardholder data',
                    '10' => 'Log and monitor all access to system components and cardholder data',
                    '11' => 'Test security of systems and networks regularly',
                    '12' => 'Support information security with organisational policies and programmes',
                ]),
                self::rows('12.8 — Third-party service providers', [
                    '12.8.1' => 'A list of all third-party service providers with which account data is shared, or that could affect the security of account data, is maintained',
                    '12.8.2' => 'Written agreements with TPSPs are maintained, including an acknowledgement of their responsibility for the security of account data they possess or otherwise handle',
                    '12.8.3' => 'An established process is implemented for engaging TPSPs, including proper due diligence prior to engagement',
                    '12.8.4' => 'A programme is implemented to monitor TPSPs\' PCI DSS compliance status at least once every 12 months',
                    '12.8.5' => 'Information is maintained about which PCI DSS requirements are managed by each TPSP, which are managed by the entity, and any that are shared',
                ], supplierRelevant: true),
                self::rows('12.9 — TPSP acknowledgements', [
                    '12.9.1' => 'TPSPs acknowledge in writing to customers their responsibility for the security of account data they possess or otherwise handle',
                    '12.9.2' => 'TPSPs support their customers\' requests for information to meet requirements 12.8.4 and 12.8.5',
                ], supplierRelevant: true),
            ),
        ];
    }

    /**
     * CBN Risk-Based Cybersecurity Framework 2024 — the outsourcing and
     * third-party provisions.
     *
     * Titles describe the obligation each provision imposes; the framework's
     * own wording is not reproduced. §2.3(i)–(viii) is the third-party section;
     * Appendix II §1.4 is the ICT third-party and cloud register; Appendix III
     * §1.3 is third-party access control.
     *
     * VERIFICATION CAVEAT, carried from TRD Appendix E and repeated wherever
     * this library is rendered: there is no single consolidated CBN
     * outsourcing guideline. A client must never be told it complies with a
     * document that does not exist.
     */
    private static function cbnCyber2024(): array
    {
        return [
            'code' => 'cbn_cyber_2024',
            'name' => 'CBN Risk-Based Cybersecurity Framework 2024 — third-party provisions',
            'version' => '2024',
            'publisher' => 'Central Bank of Nigeria',
            'has_stable_keys' => true,
            'declared_control_count' => 10,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'Section 2.3(i)–(viii), Appendix II §1.4 and Appendix III §1.3. Descriptive titles; the framework text is not reproduced. There is no consolidated CBN outsourcing guideline — see TRD Appendix E.',
            'controls' => array_merge(
                self::rows('§2.3 Third-party and outsourcing', [
                    '2.3(i)' => 'Third-party risk assessment before engagement and periodically thereafter',
                    '2.3(ii)' => 'Cybersecurity awareness programme extended to third-party personnel, at least annually',
                    '2.3(iii)' => 'Contractual cybersecurity obligations on the third party',
                    '2.3(iv)' => 'Ongoing monitoring and review of third-party security performance',
                    '2.3(v)' => 'Right of audit and regulatory access recorded in the contract',
                    '2.3(vi)' => 'Independent assurance over the third party\'s control environment',
                    '2.3(vii)' => 'Third-party participation in business continuity and disaster recovery testing',
                    '2.3(viii)' => 'Insurance cover appropriate to the service and the exposure',
                ], supplierRelevant: true),
                self::rows('Appendices', [
                    'App.II §1.4' => 'Register of ICT third parties and cloud service providers, with connection documentation status',
                    'App.III §1.3' => 'Third-party access control: senior-management approval, time-bounded validity, escort where physical, and a stated monitoring method',
                ], supplierRelevant: true),
            ),
        ];
    }

    /**
     * NDPA 2023 and GAID 2025 — the processor and cross-border provisions.
     *
     * The Nigeria Data Protection Act 2023 sections and the General
     * Application and Implementation Directive 2025 articles this module has
     * to evidence. Art. 34(2) is checked element by element by the DPA
     * extractor, which is why it is catalogued as one control and expanded in
     * the extractor rather than as twenty rows here — the twenty elements are
     * an extraction schema, not twenty independent obligations.
     */
    private static function ndpaGaid(): array
    {
        return [
            'code' => 'ndpa_gaid',
            'name' => 'NDPA 2023 and GAID 2025 — processor and transfer provisions',
            'version' => '2023/2025',
            'publisher' => 'Nigeria Data Protection Commission',
            'has_stable_keys' => true,
            'declared_control_count' => 12,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'Descriptive titles for the cited sections and articles; statutory text is not reproduced.',
            'controls' => array_merge(
                self::rows('NDPA 2023', [
                    'NDPA §28' => 'Lawful basis for processing personal data',
                    'NDPA §29' => 'Conditions for processing sensitive personal data',
                    'NDPA §39' => 'Security of processing',
                    'NDPA §40' => 'Personal data breach notification — 72 hours to the Commission, and communication to data subjects where the risk is high',
                    'NDPA §41' => 'Cross-border transfer of personal data and the lawful bases for it',
                    'NDPA §44' => 'Obligations of a data processor, including sub-processor disclosure',
                    'NDPA §48' => 'Data protection compliance audit and returns',
                ], supplierRelevant: true),
                self::rows('GAID 2025', [
                    'GAID Art. 10' => 'Compliance audit returns, filed by 31 March each year',
                    'GAID Art. 28' => 'Data protection impact assessment and the triggers requiring one',
                    'GAID Art. 32' => 'Records of processing activities',
                    'GAID Art. 33' => 'Technical and organisational measures',
                    'GAID Art. 34' => 'Mandatory content of a data processing agreement — the twenty elements of Art. 34(2)(a)–(t)',
                ], supplierRelevant: true),
            ),
        ];
    }

    private static function iso270362(): array
    {
        return [
            'code' => 'iso27036_2',
            'name' => 'ISO/IEC 27036-2 — Information security for supplier relationships',
            'version' => '2022',
            'publisher' => 'ISO/IEC',
            'has_stable_keys' => true,
            'declared_control_count' => 5,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'Clause 7 process areas. Clause titles only; the standard text is copyright ISO and is not reproduced.',
            'controls' => self::rows('Clause 7 — Supplier relationship processes', [
                '7.1' => 'Acquisition process — planning and requirements',
                '7.2' => 'Supplier selection and agreement',
                '7.3' => 'Agreement and contract management',
                '7.4' => 'Supplier relationship monitoring and change management',
                '7.5' => 'Supplier relationship termination',
            ], supplierRelevant: true),
        ];
    }

    /**
     * DORA (Regulation (EU) 2022/2554) — Articles 28, 29 and 30.
     *
     * Art. 30(2)(a)–(i) and 30(3)(a)–(f) are the mandatory contractual
     * provisions, and they are what the clause library maps against.
     *
     * Used as-is for any EU deployment and as the internal register structure
     * for Nigerian tenants, where it exceeds the CBN minimum. The DORA
     * REGISTER TEMPLATE CODES (RT.01.01 and the rest) are a separate matter —
     * they are version-stamped and NOT stable, per TRD §4.3, so they are not
     * catalogued as control identifiers here.
     */
    private static function dora(): array
    {
        return [
            'code' => 'dora',
            'name' => 'DORA — ICT third-party risk provisions',
            'version' => '2022/2554',
            'publisher' => 'European Union',
            'has_stable_keys' => true,
            'declared_control_count' => 17,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'Articles 28 and 29 at article level; Article 30(2) and 30(3) at paragraph level. Register template codes (RT.xx.xx) are version-stamped and are deliberately not catalogued as stable control identifiers.',
            'controls' => array_merge(
                self::rows('General principles', [
                    'Art. 28' => 'General principles for the sound management of ICT third-party risk, including the register of information',
                    'Art. 29' => 'Preliminary assessment of ICT concentration risk at entity level',
                ], supplierRelevant: true),
                self::rows('Art. 30(2) — Contractual provisions for all arrangements', [
                    'Art. 30(2)(a)' => 'Clear and complete description of all functions and ICT services',
                    'Art. 30(2)(b)' => 'Locations where functions and ICT services are provided and where data is processed and stored, with notice of any change',
                    'Art. 30(2)(c)' => 'Provisions on availability, authenticity, integrity and confidentiality of data protection',
                    'Art. 30(2)(d)' => 'Provisions on access, recovery and return of data in an accessible format on insolvency, resolution, discontinuation or termination',
                    'Art. 30(2)(e)' => 'Service level descriptions, including updates and revisions',
                    'Art. 30(2)(f)' => 'Obligation to provide assistance at no additional cost, or at a pre-determined cost, on an ICT incident related to the service',
                    'Art. 30(2)(g)' => 'Obligation to fully cooperate with the competent authorities and resolution authorities',
                    'Art. 30(2)(h)' => 'Termination rights and associated minimum notice periods',
                    'Art. 30(2)(i)' => 'Conditions for participation in the entity\'s ICT security awareness programmes and digital operational resilience training',
                ], supplierRelevant: true),
                self::rows('Art. 30(3) — Additional provisions for critical or important functions', [
                    'Art. 30(3)(a)' => 'Full service level descriptions with precise quantitative and qualitative performance targets',
                    'Art. 30(3)(b)' => 'Notice periods and reporting obligations to the financial entity',
                    'Art. 30(3)(c)' => 'Requirement to implement and test business contingency plans and ICT security measures',
                    'Art. 30(3)(d)' => 'Obligation to participate in and fully cooperate with the entity\'s threat-led penetration testing',
                    'Art. 30(3)(e)' => 'Rights of access, inspection and audit by the entity, its appointee and the competent authority, with unrestricted rights',
                    'Art. 30(3)(f)' => 'Exit strategies, including a mandatory adequate transition period',
                ], supplierRelevant: true),
            ),
        ];
    }

    private static function bcbsThirdParty(): array
    {
        return [
            'code' => 'bcbs_third_party',
            'name' => 'BCBS Principles for the Sound Management of Third-Party Risk',
            'version' => '2024',
            'publisher' => 'Basel Committee on Banking Supervision',
            'has_stable_keys' => true,
            'declared_control_count' => 12,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'The twelve principles by number, with a descriptive title of each. Principles 1–9 address banks; 10–12 address supervisors.',
            'controls' => self::rows('Principles', [
                'P1' => 'Board and senior management responsibility for third-party risk management',
                'P2' => 'A comprehensive third-party risk management policy and process across the life cycle',
                'P3' => 'Identification and assessment of risks, including criticality and concentration',
                'P4' => 'Due diligence proportionate to the risk before entering an arrangement',
                'P5' => 'Contractual provisions covering the risks identified, including audit and access rights',
                'P6' => 'Onboarding and implementation controls before reliance begins',
                'P7' => 'Ongoing monitoring of third-party performance and risk throughout the arrangement',
                'P8' => 'Incident and business continuity management covering third-party disruptions',
                'P9' => 'Termination and exit management, including transition arrangements',
                'P10' => 'Supervisory approach to assessing banks\' third-party risk management',
                'P11' => 'Supervisory attention to systemic concentration among service providers',
                'P12' => 'Cross-border and cross-sector supervisory cooperation on third-party risk',
            ], supplierRelevant: true),
        ];
    }

    /**
     * CSA Cloud Controls Matrix v4 — PARTIAL.
     *
     * The seventeen domain codes and the fourteen Supply Chain Management,
     * Transparency and Accountability (STA) control objectives. CCM v4 declares
     * 197 control objectives in total; the remaining 183 are not catalogued
     * because their objective text is CSA's and reproducing a paraphrase of it
     * would put unverified control statements into mappings a client would rely
     * on. A questionnaire may still map to any CCM control ID — the ID is the
     * stable join key — and the UI reports the catalogue as partial.
     */
    private static function csaCcmV4(): array
    {
        return [
            'code' => 'ccm_v4',
            'name' => 'CSA Cloud Controls Matrix v4',
            'version' => '4.0',
            'publisher' => 'Cloud Security Alliance',
            'has_stable_keys' => true,
            'declared_control_count' => 197,
            'catalogue_status' => 'partial',
            'catalogue_note' => 'The 17 domain codes and the 14 STA control objectives are catalogued. The remaining objectives are CSA licensed content and are not reproduced; mapping to any CCM control ID still works, because the ID is the join key.',
            'controls' => array_merge(
                self::rows('Domains', [
                    'A&A' => 'Audit and Assurance',
                    'AIS' => 'Application and Interface Security',
                    'BCR' => 'Business Continuity Management and Operational Resilience',
                    'CCC' => 'Change Control and Configuration Management',
                    'CEK' => 'Cryptography, Encryption and Key Management',
                    'DCS' => 'Datacenter Security',
                    'DSP' => 'Data Security and Privacy Lifecycle Management',
                    'GRC' => 'Governance, Risk and Compliance',
                    'HRS' => 'Human Resources',
                    'IAM' => 'Identity and Access Management',
                    'IPY' => 'Interoperability and Portability',
                    'IVS' => 'Infrastructure and Virtualization Security',
                    'LOG' => 'Logging and Monitoring',
                    'SEF' => 'Security Incident Management, E-Discovery and Cloud Forensics',
                    'STA' => 'Supply Chain Management, Transparency and Accountability',
                    'TVM' => 'Threat and Vulnerability Management',
                    'UEM' => 'Universal Endpoint Management',
                ]),
                self::rows('STA — Supply Chain Management, Transparency and Accountability', [
                    'STA-01' => 'SSRM policy and procedures',
                    'STA-02' => 'SSRM supply chain documentation',
                    'STA-03' => 'SSRM guidance and delineation of responsibilities',
                    'STA-04' => 'SSRM control ownership',
                    'STA-05' => 'SSRM documentation review',
                    'STA-06' => 'SSRM control implementation',
                    'STA-07' => 'Supply chain inventory',
                    'STA-08' => 'Supply chain risk management',
                    'STA-09' => 'Primary service and contractual agreement',
                    'STA-10' => 'Supply chain agreement review',
                    'STA-11' => 'Internal compliance testing',
                    'STA-12' => 'Supply chain service agreement compliance',
                    'STA-13' => 'Supply chain governance review',
                    'STA-14' => 'Supply chain data security assessment',
                ], supplierRelevant: true),
            ),
        ];
    }

    /**
     * AICPA Trust Services Criteria — PARTIAL, at category level.
     *
     * The criteria identifiers are what a SOC 2 report is organised by and what
     * an extraction maps to, so they are catalogued. The criteria TEXT and the
     * points of focus beneath each are AICPA content and are not reproduced.
     */
    private static function aicpaTsc(): array
    {
        return [
            'code' => 'tsc',
            'name' => 'AICPA Trust Services Criteria',
            'version' => '2017 (rev. 2022)',
            'publisher' => 'AICPA',
            'has_stable_keys' => true,
            'declared_control_count' => null,
            'catalogue_status' => 'partial',
            'catalogue_note' => 'Criteria series at category level, which is the granularity a SOC 2 report and its extraction map against. Criteria text and points of focus are AICPA content and are not reproduced.',
            'controls' => array_merge(
                self::rows('Common Criteria (Security)', [
                    'CC1' => 'Control Environment',
                    'CC2' => 'Communication and Information',
                    'CC3' => 'Risk Assessment',
                    'CC4' => 'Monitoring Activities',
                    'CC5' => 'Control Activities',
                    'CC6' => 'Logical and Physical Access Controls',
                    'CC7' => 'System Operations',
                    'CC8' => 'Change Management',
                    'CC9' => 'Risk Mitigation',
                ], supplierRelevant: true),
                self::rows('Availability', [
                    'A1.1' => 'Capacity management',
                    'A1.2' => 'Environmental protections, backup and recovery infrastructure',
                    'A1.3' => 'Recovery plan testing',
                ]),
                self::rows('Confidentiality', [
                    'C1.1' => 'Identification and maintenance of confidential information',
                    'C1.2' => 'Disposal of confidential information',
                ]),
                self::rows('Processing Integrity', [
                    'PI1.1' => 'Information about processing objectives',
                    'PI1.2' => 'Completeness and accuracy of inputs',
                    'PI1.3' => 'Completeness and accuracy of processing',
                    'PI1.4' => 'Completeness and accuracy of outputs',
                    'PI1.5' => 'Storage of inputs and outputs',
                ]),
                self::rows('Privacy', [
                    'P1' => 'Notice and communication of objectives',
                    'P2' => 'Choice and consent',
                    'P3' => 'Collection',
                    'P4' => 'Use, retention and disposal',
                    'P5' => 'Access',
                    'P6' => 'Disclosure and notification',
                    'P7' => 'Quality',
                    'P8' => 'Monitoring and enforcement',
                ]),
            ),
        ];
    }

    /**
     * Shared Assessments VRMMM — the eight categories, keys explicitly NOT
     * stable.
     *
     * The maturity model's categories are what FR-RPT-06 scores 0–5. SIG
     * domain letters, which questionnaires are often mapped against, change
     * between releases — so anything mapped to them is version-stamped and
     * flagged for re-verification, per TRD §4.3.
     */
    private static function vrmmm(): array
    {
        return [
            'code' => 'vrmmm',
            'name' => 'Shared Assessments Vendor Risk Management Maturity Model',
            'version' => '2024',
            'publisher' => 'Shared Assessments',
            'has_stable_keys' => false,
            'declared_control_count' => 8,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'The eight maturity categories. KEYS ARE NOT STABLE between releases — every mapping against them is version-stamped and must be re-verified on a version change. SIG question content is licensed and is never reproduced; our own questions are mapped to SIG domain letters instead.',
            'controls' => self::rows('Maturity categories', [
                'VRMMM-1' => 'Programme governance',
                'VRMMM-2' => 'Policies, standards and procedures',
                'VRMMM-3' => 'Contracts',
                'VRMMM-4' => 'Vendor risk assessment and due diligence',
                'VRMMM-5' => 'Skills and expertise',
                'VRMMM-6' => 'Communication and information sharing',
                'VRMMM-7' => 'Tools, measurement and analysis',
                'VRMMM-8' => 'Monitoring and review',
            ], supplierRelevant: true),
        ];
    }

    /**
     * PHP casts a numeric-string array key to an int, so the PCI requirement
     * map arrives keyed 1..12 rather than '1'..'12'. `array-key` rather than
     * `string`, and the cast below puts it back.
     *
     * @param  array<array-key, string>  $controls
     * @return list<array{control_id: string, title: string, domain: string, supplier_relevant: bool}>
     */
    private static function rows(string $domain, array $controls, bool $supplierRelevant = false): array
    {
        $rows = [];

        foreach ($controls as $id => $title) {
            $rows[] = [
                'control_id' => (string) $id,
                'title' => $title,
                'domain' => $domain,
                'supplier_relevant' => $supplierRelevant,
            ];
        }

        return $rows;
    }
}
