<?php

namespace Database\Seeders\Bcms\Reference;

use App\Enums\Bcms\LadderLevel;

/**
 * The shipped scenario library.
 *
 * LOCALLY GROUNDED, NOT GENERIC. Every scenario here is one a Nigerian
 * financial institution has either lived through or been examined on: grid
 * failure and diesel supply, fibre cuts on the Lagos–Kano backbone, flooding in
 * Lagos and along the Benue, civil unrest and curfew, a switch or core banking
 * outage, ransomware, and the failure of a shared national utility. A scenario
 * library of "a hurricane strikes the data centre" is one a client reads once.
 *
 * `ladder_level_min` IS ADVISORY AND SAYS WHERE A SCENARIO STARTS BEING USEFUL.
 * A full core-banking loss run as an orientation briefing teaches nothing; the
 * engine warns rather than refuses, because a client who wants to walk a
 * complex scenario through with a new team is doing something reasonable.
 */
class Scenarios
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'GRID-DIESEL', 'name' => 'Extended grid failure and diesel supply disruption',
                'category' => 'power', 'ladder_min' => LadderLevel::Tabletop->value,
                'summary' => 'National grid supply is lost across the region and the diesel supply chain is disrupted, so generator runtime is finite and known.',
                'narrative' => "Grid supply fails across the state at 06:40. The head office and four branches are on generator. The fuel supplier reports a distribution problem and cannot confirm a delivery for 48 hours. Generator runtime at head office is estimated at 30 hours; two branches have less than 12. Battery backup for network equipment is 4 hours.\n\nThe exercise runs from the moment the estimate is received.",
                'objectives' => ['Decide which sites are powered down and in what order', 'Confirm which prioritised activities can run from the remaining sites', 'Exercise the customer and regulator communication for reduced branch service'],
                'drivers' => ['cbn.rcf.bc_dr'],
            ],
            [
                'code' => 'FIBRE-CUT', 'name' => 'Primary and secondary link failure',
                'category' => 'system', 'ladder_min' => LadderLevel::Walkthrough->value,
                'summary' => 'Both terrestrial links to the primary data centre are lost, leaving only the satellite or LTE fallback with a fraction of the capacity.',
                'narrative' => "A road-widening contractor severs the primary fibre at 09:15. The secondary path, which the network diagram shows as diverse, turns out to share the last two kilometres and fails with it. The VSAT fallback carries roughly five per cent of normal throughput.\n\nBranch teller systems are unusable. ATMs and the mobile channel are degraded.",
                'objectives' => ['Confirm which transactions are prioritised onto the surviving link', 'Exercise the manual and offline procedures for branch service', 'Test the decision to invoke the DR site rather than wait for repair'],
                'drivers' => ['cbn.open_banking.failover'],
            ],
            [
                'code' => 'RANSOMWARE', 'name' => 'Ransomware with backup compromise',
                'category' => 'cyber', 'ladder_min' => LadderLevel::Tabletop->value,
                'summary' => 'Encryption across the file and application estate, with the most recent backups also encrypted, forcing recovery from an older restore point.',
                'narrative' => "At 02:10 a scheduled job fails. By 07:00 file shares and three application servers are encrypted and a ransom note is present. The backup catalogue shows the last four nightly backups as completed; two of them will not mount.\n\nThe most recent verifiable restore point is nine days old. A caller claiming to be a journalist contacts the corporate line at 11:00.",
                'objectives' => ['Escalate and notify inside the CBN reporting window', 'Recover without depending on a system the scenario has compromised', 'Exercise the decision on customer notification and the holding statement', 'Draft the NigFinCERT notification'],
                'drivers' => ['cbn.rcf.incident_response', 'cbn.rcf.cyber_drills', 'ndpa.lawful_basis'],
            ],
            [
                'code' => 'CORE-OUTAGE', 'name' => 'Core banking application unavailable',
                'category' => 'system', 'ladder_min' => LadderLevel::Walkthrough->value,
                'summary' => 'The core banking platform is unavailable with no confirmed restoration time, during business hours.',
                'narrative' => "The core banking application becomes unavailable at 10:20 on a Thursday. The vendor acknowledges the incident and will not commit to a restoration time. Branches are open and queues are forming. The mobile and USSD channels fail with it.\n\nThe exercise covers the first four hours.",
                'objectives' => ['Deliver the MBCO for payments and cash service without the core', 'Exercise the customer communication and the branch script', 'Test the reconciliation procedure for transactions taken manually'],
                'drivers' => ['cbn.open_banking.threshold'],
            ],
            [
                'code' => 'FLOOD-LAGOS', 'name' => 'Flooding and site inaccessibility',
                'category' => 'flood', 'ladder_min' => LadderLevel::Tabletop->value,
                'summary' => 'Sustained rainfall makes a head office or branch inaccessible and cuts staff commuting for several days.',
                'narrative' => "Two days of rainfall leave the approach roads to head office impassable. Roughly sixty per cent of head office staff cannot travel. The building itself is undamaged but the ground floor and the generator room have standing water.\n\nThe exercise covers days one to three.",
                'objectives' => ['Confirm which activities run remotely and at what staffing', 'Exercise the relocation of the activities that cannot', 'Test the roll-call and welfare check for staff in the affected area'],
                'drivers' => [],
            ],
            [
                'code' => 'UNREST-CURFEW', 'name' => 'Civil unrest and curfew',
                'category' => 'civil_unrest', 'ladder_min' => LadderLevel::Tabletop->value,
                'summary' => 'Unrest in a city leads to a curfew announced at short notice, with branches closed and staff movement restricted.',
                'narrative' => "Unrest begins mid-morning. By 14:00 a curfew is announced with effect from 18:00. Three branches close early with cash on site. Staff movement is restricted for an unknown number of days and the security situation is changing hourly.\n\nThe exercise begins at the curfew announcement.",
                'objectives' => ['Account for every member of staff', 'Decide and execute the cash and premises security response', 'Exercise the decision to suspend service at affected sites and to communicate it'],
                'drivers' => [],
            ],
            [
                'code' => 'SUPPLIER-FAIL', 'name' => 'Critical supplier failure',
                'category' => 'supplier', 'ladder_min' => LadderLevel::Walkthrough->value,
                'summary' => 'A critical third party — a switch, a card processor, a cloud provider region — becomes unavailable with no committed restoration time.',
                'narrative' => "A critical service provider suffers an outage at 08:00. Their status page acknowledges it; their account team cannot give a restoration estimate. The contract carries an availability commitment and a notification obligation, neither of which has been met.\n\nThe exercise covers the operational response and the contractual one.",
                'objectives' => ['Invoke the documented workaround for the affected service', 'Exercise the contractual notification and escalation path', 'Confirm whether the vendor\'s own continuity arrangement was invoked and evidenced'],
                'drivers' => ['iso22318.supply_chain'],
            ],
            [
                'code' => 'DC-LOSS', 'name' => 'Total loss of the primary data centre',
                'category' => 'system', 'ladder_min' => LadderLevel::Functional->value,
                'summary' => 'The primary data centre is lost — fire, structural failure or prolonged inaccessibility — and every tier-1 system must recover at DR.',
                'narrative' => "A fire in the primary data centre at 03:00 leads to full suppression discharge and the site is handed to the fire service. No equipment can be recovered for at least 72 hours.\n\nEvery tier-1 system must run from DR by the start of business.",
                'objectives' => ['Meet the RTO for every tier-1 system', 'Recover dependencies in the documented order', 'Confirm data loss is inside the RPO and evidence it'],
                'drivers' => ['cbn.open_banking.dr_test'],
            ],
            [
                'code' => 'PANDEMIC', 'name' => 'Prolonged mass absence',
                'category' => 'pandemic', 'ladder_min' => LadderLevel::Tabletop->value,
                'summary' => 'A health emergency removes a large proportion of staff for an extended period and restricts movement.',
                'narrative' => "A public health emergency is declared. Absence rises to forty per cent over two weeks and movement between states is restricted. Branch service is reduced by public health direction.\n\nThe exercise covers weeks two to six.",
                'objectives' => ['Run each prioritised activity at its stated minimum staffing', 'Exercise succession for every critical role', 'Confirm the technology supports the volume of remote working assumed'],
                'drivers' => [],
            ],
            [
                'code' => 'INSIDER-FRAUD', 'name' => 'Insider incident with service impact',
                'category' => 'cyber', 'ladder_min' => LadderLevel::Tabletop->value,
                'summary' => 'A privileged insider action causes both a control failure and a service disruption, so the response is simultaneously an investigation and a recovery.',
                'narrative' => "An administrator's account is used out of hours to alter a scheduled process. The change is detected two days later during reconciliation. The account belongs to a member of staff currently on duty and the extent of the change is unknown.\n\nThe exercise covers containment, investigation and the regulatory decision.",
                'objectives' => ['Contain without alerting the subject prematurely or destroying evidence', 'Determine reportability and the notification timeline', 'Exercise the interaction between the incident response and the disciplinary and legal processes'],
                'drivers' => ['cbn.rcf.incident_response'],
            ],
        ];
    }
}
