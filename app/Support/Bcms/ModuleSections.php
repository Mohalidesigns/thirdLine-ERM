<?php

namespace App\Support\Bcms;

/**
 * The twelve BCMS sub-modules of Blueprint §4.1, declared once.
 *
 * ONE DECLARATION FOR THE ROUTES, THE NAVIGATION AND THE SHELL SCREENS. The
 * alternative is a route file, a `NavPresenter` array and a set of page
 * components that each name the same twelve things, and the failure mode of
 * that is a menu item pointing at a route nobody registered — which is a 500 on
 * a customer's screen, found by a customer. `PermissionCatalogCoversRoutesTest`
 * and `AdminNavigationTest` cover the halves separately; this makes them agree
 * by construction.
 *
 * `phase` IS ON EVERY ROW AND IS SHOWN ON THE SCREEN. Phase 0 ships the shell:
 * the schema, the contracts and twelve screens that honestly say what lands and
 * when. A blank screen with no explanation is indistinguishable from a broken
 * one, and the whole point of `docs/DEVELOPMENT_STANDARD.md` §5 — a figure is
 * computed or it is absent — applies to a screen as much as to a number.
 */
class ModuleSections
{
    /**
     * `live` says whether the section's real screen has been built. Phase 0
     * shipped twelve shells; each phase flips its own to true and registers a
     * real controller under the SAME route name, so navigation, permissions and
     * bookmarks survive the replacement.
     *
     * @return list<array{
     *     key: string, label: string, permission: string, phase: string,
     *     summary: string, lands: string, clause: string, live: bool
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'programme',
                'label' => 'Programme Governance',
                'permission' => 'bcms.view',
                'phase' => 'Phase 1',
                'summary' => 'The BC policy, scope, objectives and roles, the maturity score, and the management review record.',
                'lands' => 'Programme register, objectives with their KRI links, maturity scoring and the board attestation workflow.',
                'clause' => 'ISO 22301 clauses 4, 5, 6 and 9.3',
                'live' => true,
            ],
            [
                'key' => 'processes',
                'label' => 'Process Catalogue',
                'permission' => 'bcms.process.view',
                'phase' => 'Phase 1',
                'summary' => 'The prioritised activities the BCMS protects, their criticality tier and their dependencies.',
                'lands' => 'The BCM overlay on the organisation\'s process catalogue, with criticality tiers written by the BIA rather than by hand.',
                'clause' => 'ISO 22301 clause 8.2.2',
                'live' => true,
            ],
            [
                'key' => 'bia',
                'label' => 'Business Impact Analysis',
                'permission' => 'bcms.bia.view',
                'phase' => 'Phase 2',
                'summary' => 'MTPD, RTO, RPO and MBCO per process, with impact scored over time and dependencies mapped.',
                'lands' => 'BIA campaigns with distributed questionnaires, the impact-over-time grid, the dependency graph, and an AI first draft that stays a draft until somebody edits it.',
                'clause' => 'ISO 22301 clause 8.2.2 · ISO/TS 22317',
                'live' => false,
            ],
            [
                'key' => 'strategy',
                'label' => 'Continuity Strategy',
                'permission' => 'bcms.strategy.view',
                'phase' => 'Phase 3',
                'summary' => 'Strategy options per process, their cost, the recovery time they can actually achieve, and the gap.',
                'lands' => 'The strategy register with cost/benefit, resource requirements and the gap against the BIA\'s required RTO.',
                'clause' => 'ISO 22301 clause 8.3 · ISO 22331',
                'live' => false,
            ],
            [
                'key' => 'plans',
                'label' => 'Plans',
                'permission' => 'bcms.plan.view',
                'phase' => 'Phase 3',
                'summary' => 'BCP, DRP, CMP, IRP, pandemic and site plans, versioned, approved and distributed offline.',
                'lands' => 'The plan builder with section bindings to live BIA and call-tree data, approval workflow, and the offline PWA bundle.',
                'clause' => 'ISO 22301 clause 8.4',
                'live' => false,
            ],
            [
                /*
                 * NOT ONE OF BLUEPRINT §4.1's TWELVE SUB-MODULES, and it is here
                 * anyway. Findings and corrective actions are the cross-track
                 * contract of Orchestration §5 — the exercise AAR, the
                 * broken-branch screen, the post-incident review and thirdLine
                 * all create them, and none of those four owns the register.
                 * The blueprint files CAPA inside "Programme Governance"; in
                 * practice the people who work the register are not the people
                 * who own the programme, and burying it two clicks inside
                 * another screen is how an overdue action stops being looked at.
                 */
                'key' => 'findings',
                'label' => 'Findings & Actions',
                'permission' => 'bcms.finding.view',
                'phase' => 'Phase 1',
                'summary' => 'Every finding the programme has produced, and the corrective actions closing them.',
                'lands' => 'The register, filterable by owner, status, source and due date, with overdue highlighting, the verification step, and the one-way mirror into the ERM issue register.',
                'clause' => 'ISO 22301 clause 10.1',
                'live' => true,
            ],

            [
                'key' => 'calendar',
                'label' => 'Resilience Calendar',
                'permission' => 'bcms.exercise.view',
                'phase' => 'Phase 4',
                'summary' => 'The year of exercises: declared by frequency, generated as occurrences, conflict-checked against the blackout calendar.',
                'lands' => 'Frequency-per-year generation, the drag-and-drop year view, conflict and blackout detection, and the T-10 countdown ladder materialised per occurrence.',
                'clause' => 'ISO 22301 clause 8.5 · ISO 22398',
                'live' => false,
            ],
            [
                'key' => 'exercises',
                'label' => 'Exercises & AAR',
                'permission' => 'bcms.exercise.view',
                'phase' => 'Phase 5 and 9',
                'summary' => 'Readiness checklists that gate the exercise, the execution workspace, and the after-action report.',
                'lands' => 'The T-10 daily alerting, blocking readiness tasks with recorded overrides, the live timeline and injects, scoring, and the AAR that feeds the CAPA register.',
                'clause' => 'ISO 22301 clause 8.5 · ISO 22398',
                'live' => false,
            ],
            [
                'key' => 'call-trees',
                'label' => 'Call Trees',
                'permission' => 'bcms.calltree.view',
                'phase' => 'Phase 6',
                'summary' => 'Cascade trees per department, tested live, scored per node, with the broken branch shown for what it is.',
                'lands' => 'The tree designer, generation from Active Directory, live test mode with per-node timing, and the broken-branch screen with its downstream-blocked count.',
                'clause' => 'ISO 22301 clause 8.4.3',
                'live' => false,
            ],
            [
                'key' => 'emns',
                'label' => 'Emergency Notification',
                'permission' => 'bcms.alert.view',
                'phase' => 'Phase 7',
                'summary' => 'Multi-channel dispatch with two-way acknowledgement, the safety roll-call, and a delivery audit trail.',
                'lands' => 'The EMNS console with a live recipient count and cost estimate, real SMS, WhatsApp, voice, Teams and push adapters, and the roll-call that produces a headcount in minutes.',
                'clause' => 'ISO 22301 clause 8.4.3',
                'live' => false,
            ],
            [
                'key' => 'incidents',
                'label' => 'Incidents & Crisis',
                'permission' => 'bcms.incident.view',
                'phase' => 'Phase 10',
                'summary' => 'Declaration, the crisis room, the decision log, situation reports and the post-incident review.',
                'lands' => 'Activation criteria, the war room, an append-only decision log, task assignment, and the regulatory notification clock.',
                'clause' => 'ISO 22320 · ISO 22361',
                'live' => false,
            ],
            [
                'key' => 'it-dr',
                'label' => 'IT Disaster Recovery',
                'permission' => 'bcms.dr.view',
                'phase' => 'Phase 10',
                'summary' => 'Recovery tiers, runbooks, and the DR test register with actual RTO and RPO against target.',
                'lands' => 'The DR system register, failover and failback test records, and backup verification attestations. We govern and evidence failover; we do not execute it.',
                'clause' => 'CBN Open Banking · ISO 22301 clause 8.4.5',
                'live' => false,
            ],
            [
                'key' => 'compliance',
                'label' => 'Training & Evidence',
                'permission' => 'bcms.report.view',
                'phase' => 'Phase 11',
                'summary' => 'Competency records, the clause-by-clause evidence matrix, the board pack and the regulator evidence pack.',
                'lands' => 'Role-based curricula with competency assessment, the ISO 22301 and CBN matrix with every cell drilling into the artefacts that prove it, and a one-click evidence pack.',
                'clause' => 'ISO 22301 clauses 7.2, 9 and 10 · CBN CSAT',
                'live' => false,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }
}
