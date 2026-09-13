<?php

namespace App\Support\Tprm;

/**
 * The duties each contract clause creates, and on whom.
 *
 * A clause and an obligation are not the same thing, and this file is where
 * the difference is written down. "The contract grants a right to audit" is a
 * clause. "Someone here obtains and reads the provider's assurance report every
 * year" is the obligation, and a bank that has the first without the second has
 * a term it never exercises — which is the state most audit-rights clauses are
 * actually in.
 *
 * OBLIGATIONS RUN BOTH WAYS AND THE ENTITY'S SIDE IS THE POINT. Almost every
 * competitor's obligation register holds the vendor's duties: report monthly,
 * notify within 24 hours, maintain insurance. Those are useful. But the duties
 * an institution is found to have breached are its own — the quarterly access
 * review the contract entitles it to perform and nobody scheduled, the annual
 * assurance report nobody obtained, the sub-processor register nobody kept.
 * Every template below that names `entity` exists because a supervisor can ask
 * for evidence of it and an institution that has none cannot say the vendor
 * was responsible.
 *
 * `on_event` IS A REAL FREQUENCY, NOT A NULL. A breach-notification duty has no
 * due date until something happens, and recording it as a one-off with no date
 * would either show as permanently overdue or vanish from the register. It sits
 * in the register as a standing duty with no clock, which is exactly what it is.
 *
 * These templates are ours. They are the duties the clause text implies, not a
 * regulator's enumeration of them, and a tenant's legal function should read
 * them the way it reads the model text.
 */
class ObligationTemplates
{
    /**
     * clause code => the obligations that clause creates.
     *
     * @return array<string, list<array{obligor: string, title: string, description: string, frequency: string, evidence_required: bool}>>
     */
    public static function all(): array
    {
        return [
            'CBN-CYB-05' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Provide a current independent assurance report',
                    'description' => 'The provider supplies its SOC 2 Type II, ISO/IEC 27001 certificate or '
                        .'equivalent covering the service, within the period the contract specifies.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
                [
                    'obligor' => 'entity',
                    'title' => 'Obtain and review the provider\'s assurance report',
                    'description' => 'An audit right nobody exercises is a term the institution paid for and '
                        .'never used. Someone here obtains the report, reads it, and records what it said — '
                        .'including its exceptions and its complementary user entity controls.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'CBN-CYB-07' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Participate in continuity and recovery testing',
                    'description' => 'The provider takes part in the institution\'s continuity testing for the '
                        .'service, and supplies the results of its own.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
                [
                    'obligor' => 'entity',
                    'title' => 'Include this provider in continuity testing',
                    'description' => 'A vendor that has never been included in a test is a vendor whose '
                        .'recovery is theoretical, whatever the contract says it will do.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'CBN-CYB-08' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Maintain and evidence technology and cyber insurance',
                    'description' => 'A current certificate naming the contracting entity, with the cover types '
                        .'and limits the contract requires.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'CBN-ACC-01' => [
                [
                    'obligor' => 'entity',
                    'title' => 'Review the provider\'s access to our systems',
                    'description' => 'Every account the provider holds in our environment is confirmed as still '
                        .'needed, still least-privilege and still owned by a named individual. Quarterly, '
                        .'because access accumulates faster than anything else on this register.',
                    'frequency' => 'quarterly',
                    'evidence_required' => true,
                ],
                [
                    'obligor' => 'provider',
                    'title' => 'Notify leavers with access to our environment',
                    'description' => 'The provider tells us when a member of its staff with access to our '
                        .'systems leaves or changes role, within the period the contract specifies.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
            ],

            'NDPA-DPA-01' => [
                [
                    'obligor' => 'entity',
                    'title' => 'Confirm the data processing agreement remains complete and current',
                    'description' => 'The twenty GAID Art. 34(2) elements are re-checked against the agreement '
                        .'in force, and against how the processing has actually changed during the year.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'NDPA-BR-01' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Notify a personal data breach within the contractual period',
                    'description' => 'The notification has to reach us with enough time left for our own '
                        .'seventy-two-hour NDPC notification — so the provider\'s clock is shorter than ours, '
                        .'and a provider notifying "within 72 hours" has left us none.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
            ],

            'NDPA-SP-01' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Seek authorisation before engaging a new sub-processor',
                    'description' => 'Prior authorisation, not notification after the fact.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
                [
                    'obligor' => 'entity',
                    'title' => 'Reconcile the declared sub-processor list',
                    'description' => 'The provider\'s declared list is compared against what we know from its '
                        .'assurance reports and public pages. A sub-processor that appears in a SOC 2 carve-out '
                        .'and never in a declaration is a broken disclosure obligation we can prove.',
                    'frequency' => 'semi_annual',
                    'evidence_required' => true,
                ],
            ],

            'NDPA-XB-01' => [
                [
                    'obligor' => 'entity',
                    'title' => 'Confirm the cross-border transfer basis still holds',
                    'description' => 'Transfer bases lapse: an adequacy decision is withdrawn, a contractual '
                        .'clause set is superseded, a processing location moves. The record has to be the '
                        .'current one, not the one relied on at onboarding.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'PCI-12.8.5' => [
                [
                    'obligor' => 'entity',
                    'title' => 'Confirm the PCI DSS responsibility matrix with the provider',
                    'description' => 'Every PCI requirement is assigned to the provider, to us, to both, or '
                        .'marked not applicable — and the provider agrees the assignment. This is the '
                        .'deliverable a QSA asks for.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'PCI-12.8.2' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Maintain written acknowledgement of account data responsibility',
                    'description' => 'The acknowledgement is re-confirmed when the service changes or the '
                        .'agreement is renewed.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'GEN-SLA' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Report service level performance',
                    'description' => 'Measured performance against each agreed target, for the period.',
                    'frequency' => 'monthly',
                    'evidence_required' => true,
                ],
                [
                    'obligor' => 'entity',
                    'title' => 'Review reported service levels and claim credits due',
                    'description' => 'Service credits that are earned and never claimed are the part of a '
                        .'penalty regime that quietly stops working, and a provider learns quickly which '
                        .'clients check.',
                    'frequency' => 'monthly',
                    'evidence_required' => false,
                ],
            ],

            'GEN-INC-NOT' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Notify security and service incidents within the defined period',
                    'description' => 'Within the period the contract states, and to the contact the contract '
                        .'names — not to whoever the provider last spoke to.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
            ],

            'GEN-SUBCON' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Obtain consent before sub-contracting and flow down our terms',
                    'description' => 'Consent before, and the same obligations passed down — a sub-contractor '
                        .'bound by weaker terms makes the flow-down clause decorative.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
            ],

            'GEN-DATA-RET' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Return or destroy our data on termination, with certification',
                    'description' => 'Certified destruction, and a statement of what was held and where. Due at '
                        .'exit rather than on a cycle, which is why this one carries no clock until the exit '
                        .'begins.',
                    'frequency' => 'on_event',
                    'evidence_required' => true,
                ],
            ],

            'GEN-REGACC' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Give the regulator access on request',
                    'description' => 'Access to records, premises and systems relating to the service, when the '
                        .'CBN or another supervisor asks for it.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
            ],

            'GEN-TRANS' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Provide transition assistance on exit',
                    'description' => 'Continued service and cooperation through the transition period the '
                        .'contract fixes.',
                    'frequency' => 'on_event',
                    'evidence_required' => false,
                ],
            ],

            'CBN-CYB-03' => [
                [
                    'obligor' => 'entity',
                    'title' => 'Confirm the provider\'s security measures still meet our programme',
                    'description' => 'Our own programme objectives move. A commitment made against the 2025 '
                        .'standard is not a commitment against the 2027 one, and nothing prompts that check '
                        .'unless it is on a register.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],

            'CBN-CYB-06' => [
                [
                    'obligor' => 'provider',
                    'title' => 'Evidence continuing compliance with the named regimes',
                    'description' => 'A current PCI AOC, NDPC registration, ISO certificate or equivalent for '
                        .'each regime the engagement brings into scope.',
                    'frequency' => 'annual',
                    'evidence_required' => true,
                ],
            ],
        ];
    }

    /**
     * The obligations a clause creates, or an empty list.
     *
     * A clause with no template creates none, and that is a real answer rather
     * than an omission: `GEN-TERM` grants a termination right and imposes no
     * recurring duty on anybody. Putting a hollow obligation on the register
     * for every clause would bury the ones that need doing.
     *
     * @return list<array{obligor: string, title: string, description: string, frequency: string, evidence_required: bool}>
     */
    public static function for(string $clauseCode): array
    {
        return self::all()[$clauseCode] ?? [];
    }

    /**
     * How many of the shipped clauses carry templates — used by the register
     * to say what it does and does not generate.
     */
    public static function coverage(): int
    {
        return count(self::all());
    }
}
