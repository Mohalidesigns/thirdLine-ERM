<?php

namespace App\Support\Bcms;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\PlanSectionSource;
use App\Enums\Bcms\PlanType;

/**
 * The twelve plan templates, declared once in code (ADR 0011).
 *
 * NOT A TABLE. A template is consumed exactly once — at plan creation — and
 * from that moment the plan owns its sections and nothing reads the template
 * again. A `bcms_plan_templates` table would be a tenant-editable copy of code
 * that nothing downstream depends on, plus a seeder to keep it current, plus a
 * migration every time a standard changes. If a customer asks to author their
 * own templates, that is a table and an ADR then.
 *
 * A TEMPLATE NAMES BINDINGS, NEVER IDS. `bia.rto` with no `process_ids` means
 * "the processes this plan covers", resolved through the plan's business unit
 * and site. A template that named process 4 would work for exactly one tenant.
 *
 * THE `guidance` TEXT IS THE SECTION'S STARTING BODY, and it is written as
 * instructions to the author rather than as filler prose. A template that
 * pre-writes "Our organisation is committed to resilience" produces a plan
 * nobody has thought about; a template that says what this section must contain
 * and why an examiner asks for it produces one somebody has to answer.
 */
class PlanTemplates
{
    /**
     * @return list<array{
     *     key: string, label: string, plan_type: string, clause: string,
     *     standard: string, summary: string,
     *     sections: list<array{key: string, title: string, binding: ?array<string, mixed>, guidance: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            self::groupBcp(),
            self::departmentBcp(),
            self::branchPlan(),
            self::paymentsBcp(),
            self::itDrp(),
            self::applicationRunbook(),
            self::crisisManagementPlan(),
            self::incidentResponsePlan(),
            self::cyberIncidentPlan(),
            self::pandemicPlan(),
            self::emergencyResponsePlan(),
            self::policy(),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** @return list<array<string, mixed>> */
    public static function forType(PlanType $type): array
    {
        return array_values(array_filter(
            self::all(),
            fn (array $t) => $t['plan_type'] === $type->value
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Continuity plans */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private static function groupBcp(): array
    {
        return [
            'key' => 'bcp_group',
            'label' => 'Group business continuity plan',
            'plan_type' => PlanType::Bcp->value,
            'clause' => IsoClauseRef::Iso22301_8_4_4->value,
            'standard' => 'ISO 22301 clause 8.4.4',
            'summary' => 'The organisation-wide plan: how the group as a whole responds, who has authority, '
                .'and what every subordinate plan inherits.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'State what this plan covers, what it deliberately does not, and which subordinate plans '
                    .'sit beneath it. An examiner reads this section to decide whether the rest of the document '
                    .'is the one they asked for.'),
                self::free('activation', 'Activation criteria and authority',
                    'The conditions under which this plan is activated and the named roles — not people — who may '
                    .'activate it, including out of hours. A plan whose activation authority is ambiguous is a plan '
                    .'that is activated late.'),
                self::free('response_structure', 'Incident response structure',
                    'How the crisis team, the incident teams and business units relate during a disruption, and who '
                    .'reports to whom (ISO 22301 clause 8.4.2).'),
                self::bound('roles', 'Roles and responsibilities', PlanSectionSource::CrisisTeamContacts),
                self::bound('recovery_objectives', 'Recovery objectives', PlanSectionSource::BiaRto),
                self::bound('strategies', 'Recovery strategies', PlanSectionSource::Strategy),
                self::bound('dependencies', 'Dependencies and single points of failure', PlanSectionSource::BiaDependencies),
                self::bound('call_tree', 'Communication and call cascade', PlanSectionSource::CallTree),
                self::free('external_communication', 'External communication',
                    'Who speaks to customers, the media, the CBN and the NDIC, what is said before facts are '
                    .'confirmed, and who approves it. Include the regulatory notification clock.'),
                self::free('recovery_procedures', 'Recovery procedures',
                    'The ordered steps that restore each activity to its minimum acceptable level, written so '
                    .'somebody who did not draft them can follow them at 3am.'),
                self::free('resources', 'Resource requirements',
                    'People, technology, facilities, information, suppliers and funding the strategies above assume. '
                    .'A strategy whose resources were never budgeted is an aspiration.'),
                self::bound('alternate_sites', 'Alternate sites and assembly points', PlanSectionSource::AssemblyPoints),
                self::bound('vendors', 'Critical vendors and escalation', PlanSectionSource::CriticalVendors),
                self::bound('it_recovery', 'IT recovery tiers and runbooks', PlanSectionSource::DrSystems),
                self::free('return_to_normal', 'Return to normal',
                    'How the organisation stands down: the criteria for declaring recovery complete, the order in '
                    .'which activities move back, backlog reconciliation, and who authorises it.'),
                self::free('appendices', 'Appendices',
                    'Anything the sections above reference: forms, maps, standing agreements, regulatory contact '
                    .'details.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function departmentBcp(): array
    {
        return [
            'key' => 'bcp_department',
            'label' => 'Departmental business continuity plan',
            'plan_type' => PlanType::Department->value,
            'clause' => IsoClauseRef::Iso22301_8_4_4->value,
            'standard' => 'ISO 22301 clause 8.4.4',
            'summary' => 'One department\'s plan: its own processes, its own people, and how it works to the '
                .'group plan above it.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The department this plan covers and the group plan it works to. Name the group plan by title '
                    .'and version so the two cannot silently diverge.'),
                self::free('activation', 'Activation criteria and authority',
                    'When this department activates on its own initiative, and when it activates because the group '
                    .'plan told it to.'),
                self::bound('recovery_objectives', 'Recovery objectives', PlanSectionSource::BiaRto),
                self::bound('strategies', 'Recovery strategies', PlanSectionSource::Strategy),
                self::bound('dependencies', 'Dependencies and single points of failure', PlanSectionSource::BiaDependencies),
                self::free('roles', 'Roles and responsibilities',
                    'Who does what in this department during a disruption, and the deputy for each role. Every '
                    .'named role needs a deputy, because the person who is unreachable is the reason you are '
                    .'reading this.'),
                self::bound('call_tree', 'Call cascade', PlanSectionSource::CallTree),
                self::free('recovery_procedures', 'Recovery procedures',
                    'The steps that get this department back to its minimum acceptable level of service, per '
                    .'process, in priority order.'),
                self::free('workarounds', 'Manual workarounds',
                    'What this department does while the systems are down, including the forms and registers used, '
                    .'and how the work is reconciled afterwards. This is the section most used and least written.'),
                self::free('resources', 'Resource requirements',
                    'Minimum staff, seats, equipment and access needed to run the workarounds above.'),
                self::bound('alternate_sites', 'Alternate site and assembly points', PlanSectionSource::AssemblyPoints),
                self::free('return_to_normal', 'Return to normal',
                    'How this department resumes normal running and clears its backlog.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function branchPlan(): array
    {
        return [
            'key' => 'site_branch',
            'label' => 'Branch / site continuity plan',
            'plan_type' => PlanType::Site->value,
            'clause' => IsoClauseRef::Iso22301_8_4_4->value,
            'standard' => 'ISO 22301 clause 8.4.4',
            'summary' => 'A single location: its people, its building, its cash, and where everyone goes when '
                .'the building is unusable.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The site this plan covers, its normal operating hours and its headcount.'),
                self::free('activation', 'Activation criteria and authority',
                    'Who may declare this site unusable, and what happens between the declaration and the first '
                    .'person arriving at the alternate site.'),
                self::bound('assembly', 'Evacuation and assembly points', PlanSectionSource::AssemblyPoints),
                self::free('roll_call', 'Roll call and accounting for people',
                    'How the site accounts for every person on the premises, including visitors, contractors and '
                    .'cleaners, and who holds the list. Life safety comes before every other section of this plan.'),
                self::bound('call_tree', 'Site call cascade', PlanSectionSource::CallTree),
                self::bound('recovery_objectives', 'Recovery objectives for this site', PlanSectionSource::BiaRto),
                self::free('relocation', 'Relocation arrangements',
                    'The receiving site, how staff get there, what they take, and what the receiving site has to '
                    .'do to absorb them.'),
                self::free('cash_and_security', 'Cash, security and physical assets',
                    'Vault and ATM cash, the security arrangements while the site is unattended, and who holds the '
                    .'keys. A branch plan that does not address cash is not a branch plan.'),
                self::free('customer_service', 'Customer service continuity',
                    'What customers of this branch are told, where they are directed, and how account access '
                    .'continues.'),
                self::free('return_to_normal', 'Reoccupation',
                    'Who inspects and clears the building, and the order in which functions move back.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function paymentsBcp(): array
    {
        return [
            'key' => 'bcp_payments',
            'label' => 'Payments and open banking continuity plan',
            'plan_type' => PlanType::Bcp->value,
            'clause' => IsoClauseRef::Cbn_ob_threshold->value,
            'standard' => 'CBN Open Banking Framework · ISO 22301 clause 8.4.4',
            'summary' => 'The plan a Nigerian bank is examined on: NIP, cards, cheque clearing and the open '
                .'banking APIs, against the CBN\'s own failover expectations.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The payment rails this plan covers and the regulatory expectations attached to each.'),
                self::free('regulatory_thresholds', 'Regulatory thresholds',
                    'The CBN\'s failover and availability expectations for each rail, quoted with their source. '
                    .'Where the bank\'s own RTO is longer than the expectation, say so here and say why — a plan '
                    .'that omits the gap is a plan that hides it.'),
                self::bound('recovery_objectives', 'Recovery objectives', PlanSectionSource::BiaRto),
                self::bound('it_recovery', 'System recovery tiers and runbooks', PlanSectionSource::DrSystems),
                self::bound('strategies', 'Recovery strategies', PlanSectionSource::Strategy),
                self::bound('dependencies', 'Dependencies and single points of failure', PlanSectionSource::BiaDependencies),
                self::bound('vendors', 'Switches, processors and scheme escalation', PlanSectionSource::CriticalVendors),
                self::free('degraded_modes', 'Degraded operating modes',
                    'What still works when each rail is down: offline authorisation limits, stand-in processing, '
                    .'manual clearing, and the exposure each one accepts.'),
                self::free('reconciliation', 'Reconciliation and settlement',
                    'How in-flight and stand-in transactions are reconciled once the rail is restored, and who '
                    .'signs the reconciliation off.'),
                self::free('regulatory_notification', 'Regulatory notification',
                    'What is reported to the CBN and the NDIC, by whom, within what window, and where the '
                    .'submission is filed. Nothing is submitted automatically.'),
                self::bound('call_tree', 'Escalation cascade', PlanSectionSource::CallTree),
                self::free('return_to_normal', 'Return to normal',
                    'Criteria for resuming normal processing and the customer communication that accompanies it.'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Recovery plans */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private static function itDrp(): array
    {
        return [
            'key' => 'drp_it',
            'label' => 'IT disaster recovery plan',
            'plan_type' => PlanType::Drp->value,
            'clause' => IsoClauseRef::Iso22301_8_4_5->value,
            'standard' => 'ISO 22301 clause 8.4.5 · CBN Open Banking',
            'summary' => 'Technology recovery: tiers, sequence, runbooks and the evidence that failover has '
                .'actually been tested.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The estate this plan covers and the boundary with the group BCP. We govern and evidence '
                    .'failover; the plan says who executes it.'),
                self::free('activation', 'Declaration and authority',
                    'Who declares a disaster, who authorises failover, and the decision the bank is really making '
                    .'— failing over is itself a risk and the plan should say who owns that call.'),
                self::bound('systems', 'Systems by recovery tier', PlanSectionSource::DrSystems),
                self::bound('recovery_objectives', 'Business recovery objectives these systems serve', PlanSectionSource::BiaRto),
                self::free('recovery_sequence', 'Recovery sequence',
                    'The order systems are recovered in, and the dependencies that fix that order. A sequence that '
                    .'ignores dependencies recovers an application whose database is not yet up.'),
                self::free('runbooks', 'Runbook references',
                    'Where the executable runbook for each system lives, who maintains it, and when it was last '
                    .'exercised.'),
                self::free('data_recovery', 'Data recovery and backup verification',
                    'Backup schedules, restoration procedure, and the last verified restore for each system. An '
                    .'unverified backup is a hypothesis.'),
                self::bound('dependencies', 'Upstream and downstream dependencies', PlanSectionSource::BiaDependencies),
                self::bound('vendors', 'Vendor and hosting escalation', PlanSectionSource::CriticalVendors),
                self::free('failback', 'Failback',
                    'How production returns to the primary site, the data reconciliation it requires, and the '
                    .'window it needs. Failback is the half of DR that is never rehearsed.'),
                self::bound('call_tree', 'Technical escalation cascade', PlanSectionSource::CallTree),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function applicationRunbook(): array
    {
        return [
            'key' => 'drp_application',
            'label' => 'Application recovery runbook',
            'plan_type' => PlanType::Drp->value,
            'clause' => IsoClauseRef::Iso22301_8_4_5->value,
            'standard' => 'ISO 22301 clause 8.4.5',
            'summary' => 'One application, start to finish: prerequisites, steps, verification and rollback.',
            'sections' => [
                self::free('system', 'System identification',
                    'The application, its owner, its recovery tier and its targets. One runbook, one system.'),
                self::free('prerequisites', 'Prerequisites',
                    'What must already be running before this runbook can start: network, directory, database, '
                    .'message bus. Name them; do not assume them.'),
                self::free('access', 'Access and credentials',
                    'Which roles need which access to execute this runbook, and how that access is obtained during '
                    .'an event. Never record credentials in the plan itself.'),
                self::free('steps', 'Recovery steps',
                    'Numbered, executable steps with the expected result of each. Write for the engineer on call, '
                    .'not the engineer who built it.'),
                self::free('verification', 'Verification',
                    'The checks that prove the application is genuinely serving traffic, not merely running.'),
                self::free('rollback', 'Rollback',
                    'What to do when the recovery attempt makes things worse, and the point of no return.'),
                self::bound('dependencies', 'Dependencies', PlanSectionSource::BiaDependencies),
                self::bound('vendors', 'Vendor support and escalation', PlanSectionSource::CriticalVendors),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Crisis, incident and emergency */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private static function crisisManagementPlan(): array
    {
        return [
            'key' => 'cmp_crisis',
            'label' => 'Crisis management plan',
            'plan_type' => PlanType::Cmp->value,
            'clause' => IsoClauseRef::Iso22361_crisis->value,
            'standard' => 'ISO 22361',
            'summary' => 'How the organisation is led through a crisis: the team, its authority, its decision '
                .'log and what it says to the outside world.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'What distinguishes a crisis from an incident in this organisation, and therefore when this '
                    .'plan applies rather than an incident response plan.'),
                self::free('activation', 'Activation criteria and authority',
                    'The thresholds that convene the crisis team, who may convene it, and how quickly it is '
                    .'expected to be in session.'),
                self::bound('team', 'Crisis management team', PlanSectionSource::CrisisTeamContacts),
                self::free('authority', 'Delegated authority',
                    'What the crisis team may decide without further approval — spending limits, customer '
                    .'commitments, public statements — and what it may not. A team that has to seek approval for '
                    .'every decision is not a crisis team.'),
                self::free('battle_rhythm', 'Meeting cadence and battle rhythm',
                    'How often the team meets, what each meeting covers, and when situation reports are issued.'),
                self::free('decision_log', 'Decision log',
                    'How decisions, the information they were based on and the time they were taken are recorded. '
                    .'The log is the evidence the board and the regulator will ask for.'),
                self::free('communication', 'Communication strategy',
                    'Holding statements, spokesperson, approval chain, and the channels used for staff, customers, '
                    .'regulators and media. Pre-approved holding statements save the first hour.'),
                self::bound('call_tree', 'Crisis notification cascade', PlanSectionSource::CallTree),
                self::free('stakeholders', 'Stakeholder map',
                    'Who must be told what, in what order, and by whom.'),
                self::free('stand_down', 'Stand-down and review',
                    'The criteria for standing the team down and the commitment to a post-incident review.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function incidentResponsePlan(): array
    {
        return [
            'key' => 'irp_incident',
            'label' => 'Incident response plan',
            'plan_type' => PlanType::Irp->value,
            'clause' => IsoClauseRef::Iso22301_8_4_2->value,
            'standard' => 'ISO 22301 clause 8.4.2 · ISO 22320',
            'summary' => 'The first hour: detect, assess, categorise, escalate — and the structure that does it.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The incidents this plan covers and the point at which they become a crisis.'),
                self::free('categorisation', 'Categorisation and severity',
                    'The severity scale, what each level means in customer and regulatory terms, and who assigns '
                    .'it. A severity scale nobody can apply consistently produces incidents that are all Sev 3.'),
                self::free('response_structure', 'Response structure',
                    'The incident commander role, the teams beneath it, and how command transfers between shifts '
                    .'(ISO 22320).'),
                self::free('triage', 'Detection and triage',
                    'How incidents reach this process, who performs first assessment, and within what time.'),
                self::free('escalation', 'Escalation matrix',
                    'Which severity escalates to whom, within what window, and what happens if the first contact '
                    .'does not answer.'),
                self::bound('call_tree', 'Notification cascade', PlanSectionSource::CallTree),
                self::bound('dependencies', 'Affected dependencies', PlanSectionSource::BiaDependencies),
                self::free('containment', 'Containment and recovery actions',
                    'The standing actions available at each severity.'),
                self::free('regulatory_notification', 'Regulatory notification',
                    'The reportable thresholds, the clock each one starts, and who files. Nothing is submitted '
                    .'automatically.'),
                self::free('closure', 'Closure and post-incident review',
                    'When an incident is closed, and the review that follows.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function cyberIncidentPlan(): array
    {
        return [
            'key' => 'irp_cyber',
            'label' => 'Cyber incident response plan',
            'plan_type' => PlanType::Irp->value,
            'clause' => IsoClauseRef::Iso22301_8_4_2->value,
            'standard' => 'ISO 22301 clause 8.4.2 · NDPA 2023',
            'summary' => 'A cyber event is not an outage: evidence must be preserved, the attacker may be '
                .'watching, and a data breach starts a regulatory clock of its own.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The cyber events this plan covers — ransomware, data exfiltration, account takeover, denial '
                    .'of service — and how it relates to the general incident response plan.'),
                self::free('activation', 'Activation and authority',
                    'Who declares a cyber incident, and the authority to disconnect systems or take services '
                    .'offline. That decision cannot wait for a committee.'),
                self::free('containment', 'Containment',
                    'Isolation options and what each one costs the business. Pulling the network is a business '
                    .'decision made under time pressure; the options should be priced in advance.'),
                self::free('evidence', 'Evidence preservation and forensics',
                    'What must be preserved before recovery begins, who is authorised to collect it, and the '
                    .'external forensic provider on retainer. Recovering a compromised system destroys the '
                    .'evidence of how it was compromised.'),
                self::free('comms_security', 'Out-of-band communication',
                    'How the response team communicates when the assumption is that corporate email and chat are '
                    .'compromised. Name the alternative channel and test it.'),
                self::bound('it_recovery', 'Systems and recovery tiers', PlanSectionSource::DrSystems),
                self::free('data_breach', 'Personal data breach assessment',
                    'How the team determines whether personal data was affected, the NDPA notification obligation '
                    .'that follows, and who assesses it.'),
                self::free('regulatory_notification', 'Regulatory and law-enforcement notification',
                    'CBN, NDIC, the NDPC and the police: thresholds, windows and who files each one.'),
                self::bound('vendors', 'Vendors and third-party exposure', PlanSectionSource::CriticalVendors),
                self::bound('call_tree', 'Notification cascade', PlanSectionSource::CallTree),
                self::free('recovery', 'Recovery and rebuild',
                    'The criteria for trusting a system enough to return it to service, including rebuild-from-'
                    .'known-good where restoration is not trustworthy.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function pandemicPlan(): array
    {
        return [
            'key' => 'pandemic',
            'label' => 'Pandemic and infectious disease plan',
            'plan_type' => PlanType::Pandemic->value,
            'clause' => IsoClauseRef::Iso22301_8_4_4->value,
            'standard' => 'ISO 22301 clause 8.4.4',
            'summary' => 'The disruption that takes the people rather than the building: sustained absenteeism, '
                .'phased response, and a workforce that cannot gather.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'What this plan covers and the assumption it is built on — that premises and systems remain '
                    .'available and staff do not.'),
                self::free('phases', 'Phased response',
                    'The alert phases the organisation recognises, what triggers each, and the measures that come '
                    .'into force at each. Escalation and de-escalation criteria both.'),
                self::free('absenteeism', 'Absenteeism planning',
                    'The absence rates planned for and what is sustained at each. This is the number the whole '
                    .'plan turns on and it should be stated, not implied.'),
                self::bound('recovery_objectives', 'Priority activities', PlanSectionSource::BiaRto),
                self::free('critical_roles', 'Critical roles and succession',
                    'The roles that cannot be vacant, their deputies and their deputies\' deputies. Depth of two '
                    .'is not depth.'),
                self::free('remote_working', 'Remote working capability',
                    'Who can work remotely, the capacity of the access infrastructure, and what happens when '
                    .'demand exceeds it.'),
                self::free('workplace_measures', 'Workplace health measures',
                    'Screening, distancing, split teams and cleaning regimes, and the authority to impose them.'),
                self::free('staff_communication', 'Staff communication and welfare',
                    'How staff are kept informed and supported over a disruption measured in months rather than '
                    .'hours.'),
                self::bound('call_tree', 'Notification cascade', PlanSectionSource::CallTree),
                self::free('customer_service', 'Customer service under reduced capacity',
                    'Which channels are prioritised and what customers are told about the rest.'),
                self::free('return_to_normal', 'Recovery and return to workplace',
                    'The criteria for relaxing each measure, and the order in which people return.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function emergencyResponsePlan(): array
    {
        return [
            'key' => 'emergency_response',
            'label' => 'Emergency response and evacuation plan',
            'plan_type' => PlanType::EmergencyResponse->value,
            'clause' => IsoClauseRef::Iso22301_8_4_2->value,
            'standard' => 'ISO 22301 clause 8.4.2',
            'summary' => 'Life safety in the first ten minutes. This plan is read standing up.',
            'sections' => [
                self::free('purpose_scope', 'Purpose and scope',
                    'The emergencies this plan covers — fire, flood, bomb threat, civil unrest, medical emergency '
                    .'— and the premises it applies to.'),
                self::free('immediate_actions', 'Immediate actions',
                    'What the first person to notice does, per emergency type. Short sentences, no cross-'
                    .'references. Somebody is reading this while walking.'),
                self::free('wardens', 'Wardens and marshals',
                    'The fire wardens and first aiders per floor and per shift, their deputies, and how they are '
                    .'identified.'),
                self::bound('assembly', 'Evacuation routes and assembly points', PlanSectionSource::AssemblyPoints),
                self::free('roll_call', 'Roll call',
                    'How every person on site is accounted for, including visitors and contractors, and who '
                    .'reports the headcount to whom.'),
                self::free('special_assistance', 'People needing assistance',
                    'Personal evacuation arrangements for anybody who cannot use the normal route, agreed with '
                    .'them in advance.'),
                self::free('emergency_services', 'Emergency services liaison',
                    'Who meets the fire service, what information they need on arrival, and where it is kept.'),
                self::bound('call_tree', 'Notification cascade', PlanSectionSource::CallTree),
                self::free('all_clear', 'All-clear and reoccupation',
                    'Who is authorised to declare the building safe. Nobody re-enters on their own judgement.'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Policy */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private static function policy(): array
    {
        return [
            'key' => 'policy',
            'label' => 'Business continuity policy',
            'plan_type' => PlanType::Policy->value,
            'clause' => IsoClauseRef::Iso22301_5_2->value,
            'standard' => 'ISO 22301 clause 5.2 · CBN Corporate Governance Guidelines',
            'summary' => 'Top management\'s statement of intent — short, signed and reviewed annually.',
            'sections' => [
                self::free('statement', 'Policy statement',
                    'The organisation\'s commitment to business continuity, in top management\'s own words. A page, '
                    .'not ten.'),
                self::free('scope', 'Scope',
                    'The parts of the organisation the BCMS covers, and any exclusion with its justification '
                    .'(clause 4.3).'),
                self::free('objectives', 'Objectives',
                    'What the BCMS is meant to achieve, expressed so that achievement can be measured (clause 6.2).'),
                self::free('roles', 'Governance and accountability',
                    'The board\'s role, the executive sponsor, and the management committee that oversees the '
                    .'programme.'),
                self::free('resources', 'Commitment of resources',
                    'The commitment top management makes to fund and staff the BCMS. Clause 5.1 asks for this '
                    .'explicitly and its absence is a common nonconformity.'),
                self::free('compliance', 'Standards and regulatory alignment',
                    'The standards and regulations the BCMS is aligned to, named with their versions.'),
                self::free('review', 'Review and communication',
                    'How often this policy is reviewed, by whom, and how it is communicated to staff and to '
                    .'interested parties.'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Section builders */
    /* ------------------------------------------------------------------ */

    /** @return array{key: string, title: string, binding: null, guidance: string} */
    private static function free(string $key, string $title, string $guidance): array
    {
        return ['key' => $key, 'title' => $title, 'binding' => null, 'guidance' => $guidance];
    }

    /** @return array{key: string, title: string, binding: array<string, mixed>, guidance: string} */
    private static function bound(string $key, string $title, PlanSectionSource $source): array
    {
        return [
            'key' => $key,
            'title' => $title,
            // No ids: scope is inherited from the plan. A template that named
            // process 4 would work for exactly one tenant.
            'binding' => ['source' => $source->value],
            'guidance' => $source->renders(),
        ];
    }
}
