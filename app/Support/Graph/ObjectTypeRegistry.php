<?php

namespace App\Support\Graph;

/**
 * The system type registry for the object graph.
 *
 * Every governed thing in the platform is a typed node in one graph. This class
 * is the single declaration of which types exist, how they may relate, and what
 * states they move through — read by the seeding migration, by the seeder that
 * re-runs it idempotently, and by the tests that assert the registry is intact.
 *
 * These are *system* types: they are seeded with organization_id NULL and are
 * visible to every tenant. A tenant may add its own types alongside them (same
 * table, its own organization_id), which is the "configure-don't-code" half of
 * the model. Nothing here may be edited by a tenant.
 *
 * TYPE CODES ARE PascalCase, not UPPER_SNAKE. They are what a model declares in
 * its $objectTypeCode property, they appear in relationship definitions, and
 * they read as the domain nouns they are ("KeyRiskIndicator", not "KRI" or
 * "KEY_RISK_INDICATOR"). The legacy entity_types table uses UPPER_SNAKE codes;
 * legacyEntityTypeMap() is the bridge between the two.
 */
class ObjectTypeRegistry
{
    public const CATEGORY_ORG_NODE = 'org_node';

    public const CATEGORY_GOVERNANCE = 'governance';

    public const CATEGORY_ASSESSMENT = 'assessment';

    public const CATEGORY_REFERENCE = 'reference';

    public const CATEGORIES = [
        self::CATEGORY_ORG_NODE,
        self::CATEGORY_GOVERNANCE,
        self::CATEGORY_ASSESSMENT,
        self::CATEGORY_REFERENCE,
    ];

    /**
     * Every system object type.
     *
     * is_node_type marks the org-graph backbone: the types a governance object
     * can *hang off*. objects.node_id always points at one of these, which is
     * what makes "every risk under Retail Banking, at any depth" an indexed
     * prefix match rather than a join across four different owning tables.
     *
     * level_hint is advisory only — it drives default ordering and the tree
     * icons, and is deliberately not a constraint, because a real bank has
     * branches under regions under divisions in one subsidiary and branches
     * directly under a division in another.
     *
     * @return list<array<string, mixed>>
     */
    public static function types(): array
    {
        return array_merge(self::orgNodeTypes(), self::governanceTypes(), self::assessmentTypes(), self::referenceTypes());
    }

    /**
     * The digital twin of the organisation: the things risk is carried *by*.
     *
     * @return list<array<string, mixed>>
     */
    private static function orgNodeTypes(): array
    {
        $t = fn (string $code, string $name, string $plural, int $level, string $icon, string $color, string $prefix, array $children = []) => [
            'code' => $code,
            'name' => $name,
            'plural_name' => $plural,
            'category' => self::CATEGORY_ORG_NODE,
            'icon' => $icon,
            'color' => $color,
            'level_hint' => $level,
            'is_node_type' => true,
            'code_prefix' => $prefix,
            'allowed_child_type_codes' => $children,
        ];

        return [
            $t('Enterprise', 'Enterprise', 'Enterprises', 0, 'domain', '#1A365D', 'ENT',
                ['LegalEntity', 'Division', 'Geography', 'Project', 'Vendor']),
            $t('LegalEntity', 'Legal Entity', 'Legal Entities', 1, 'account_balance', '#2C5282', 'LE',
                ['Division', 'Department', 'Geography', 'Product', 'Channel', 'ImportantBusinessService', 'Project']),
            $t('Division', 'Division', 'Divisions', 2, 'store', '#2D7D46', 'DIV',
                ['BusinessUnit', 'Department', 'Geography', 'Product', 'Channel', 'Process', 'Project']),
            $t('BusinessUnit', 'Business Unit', 'Business Units', 3, 'workspaces', '#3182CE', 'BU',
                ['Department', 'Process', 'Branch', 'Product', 'Channel', 'System', 'Project']),
            $t('Department', 'Department', 'Departments', 3, 'groups', '#DD6B20', 'DEPT',
                ['Process', 'Branch', 'System', 'Project']),
            $t('Branch', 'Branch', 'Branches', 4, 'location_city', '#718096', 'BR',
                ['Process', 'Asset']),
            $t('Process', 'Process', 'Processes', 4, 'sync_alt', '#ED8936', 'PRC',
                ['Process', 'System', 'Asset']),
            $t('System', 'System', 'Systems', 4, 'computer', '#9F7AEA', 'SYS',
                ['Application', 'Asset']),
            $t('Application', 'Application', 'Applications', 4, 'apps', '#805AD5', 'APP',
                ['Asset']),
            $t('Asset', 'Asset', 'Assets', 4, 'inventory_2', '#4A5568', 'AST'),
            $t('Product', 'Product', 'Products', 3, 'sell', '#38A169', 'PRD',
                ['Channel', 'Process']),
            $t('Channel', 'Channel', 'Channels', 3, 'hub', '#319795', 'CHN',
                ['Process', 'System']),
            $t('Geography', 'Geography', 'Geographies', 3, 'location_on', '#3182CE', 'GEO',
                ['Geography', 'Branch', 'BusinessUnit']),
            // A project is a risk-bearing node in its own right — Corporater's
            // Solution Benefits prose claims project/portfolio risk, and it is
            // one of the ten parity items the published feature list omits.
            $t('Project', 'Project', 'Projects', 3, 'assignment', '#D4AF37', 'PRJ',
                ['Project', 'Process', 'System']),
            $t('Vendor', 'Vendor', 'Vendors', 3, 'handshake', '#B7791F', 'VND',
                ['ImportantBusinessService', 'System']),
            $t('ImportantBusinessService', 'Important Business Service', 'Important Business Services', 3, 'star', '#C05621', 'IBS',
                ['Process', 'System', 'Application']),
        ];
    }

    /**
     * The governance objects: the things that describe, measure or treat risk.
     *
     * @return list<array<string, mixed>>
     */
    private static function governanceTypes(): array
    {
        $t = fn (string $code, string $name, string $plural, string $icon, string $color, string $prefix, ?string $parent = null) => [
            'code' => $code,
            'name' => $name,
            'plural_name' => $plural,
            'category' => self::CATEGORY_GOVERNANCE,
            'icon' => $icon,
            'color' => $color,
            'level_hint' => null,
            'is_node_type' => false,
            'code_prefix' => $prefix,
            'parent_type_code' => $parent,
        ];

        return [
            $t('Risk', 'Risk', 'Risks', 'warning', '#C53030', 'RK'),
            // Opportunity inherits from Risk: same scoring model, opposite sign.
            $t('Opportunity', 'Opportunity', 'Opportunities', 'trending_up', '#2F855A', 'OPP', 'Risk'),
            $t('EmergingRisk', 'Emerging Risk', 'Emerging Risks', 'radar', '#DD6B20', 'ER', 'Risk'),
            $t('Control', 'Control', 'Controls', 'shield', '#2B6CB0', 'CTL'),
            $t('KeyRiskIndicator', 'Key Risk Indicator', 'Key Risk Indicators', 'speed', '#D69E2E', 'KRI'),
            $t('KeyPerformanceIndicator', 'Key Performance Indicator', 'Key Performance Indicators', 'insights', '#38A169', 'KPI'),
            $t('Objective', 'Objective', 'Objectives', 'flag', '#1A365D', 'OBJ'),
            $t('TreatmentPlan', 'Treatment Plan', 'Treatment Plans', 'build', '#805AD5', 'TP'),
            $t('Issue', 'Issue', 'Issues', 'report_problem', '#E53E3E', 'ISS'),
            $t('Action', 'Action', 'Actions', 'task_alt', '#4299E1', 'ACT'),
            $t('LossEvent', 'Loss Event', 'Loss Events', 'money_off', '#9B2C2C', 'LE'),
            $t('NearMiss', 'Near Miss', 'Near Misses', 'error_outline', '#D69E2E', 'NM'),
            $t('RiskAppetite', 'Risk Appetite', 'Risk Appetites', 'tune', '#D4AF37', 'RA'),
            $t('Policy', 'Policy', 'Policies', 'gavel', '#2C5282', 'POL'),
            $t('Obligation', 'Obligation', 'Obligations', 'balance', '#B7791F', 'OBL'),
            $t('Scenario', 'Scenario', 'Scenarios', 'science', '#6B46C1', 'SCN'),
            $t('Audit', 'Audit', 'Audits', 'fact_check', '#285E61', 'AUD'),
            $t('Finding', 'Finding', 'Findings', 'search', '#C05621', 'FND'),
            $t('AiSystem', 'AI System', 'AI Systems', 'smart_toy', '#553C9A', 'AIS'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function assessmentTypes(): array
    {
        $t = fn (string $code, string $name, string $plural, string $icon, string $prefix) => [
            'code' => $code,
            'name' => $name,
            'plural_name' => $plural,
            'category' => self::CATEGORY_ASSESSMENT,
            'icon' => $icon,
            'color' => '#4C51BF',
            'level_hint' => null,
            'is_node_type' => false,
            'code_prefix' => $prefix,
        ];

        return [
            $t('RiskAssessment', 'Risk Assessment', 'Risk Assessments', 'assessment', 'RAS'),
            $t('AssessmentCampaign', 'Assessment Campaign', 'Assessment Campaigns', 'campaign', 'CMP'),
            $t('ControlTest', 'Control Test', 'Control Tests', 'checklist', 'CT'),
            $t('Questionnaire', 'Questionnaire', 'Questionnaires', 'quiz', 'QN'),
            $t('MaturityAssessment', 'Maturity Assessment', 'Maturity Assessments', 'stairs', 'MAT'),
            $t('DPIA', 'Data Protection Impact Assessment', 'Data Protection Impact Assessments', 'privacy_tip', 'DPIA'),
            $t('BIA', 'Business Impact Analysis', 'Business Impact Analyses', 'analytics', 'BIA'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function referenceTypes(): array
    {
        $t = fn (string $code, string $name, string $plural, string $icon, string $prefix) => [
            'code' => $code,
            'name' => $name,
            'plural_name' => $plural,
            'category' => self::CATEGORY_REFERENCE,
            'icon' => $icon,
            'color' => '#718096',
            'level_hint' => null,
            'is_node_type' => false,
            'code_prefix' => $prefix,
        ];

        return [
            $t('RiskCategory', 'Risk Category', 'Risk Categories', 'category', 'RC'),
            $t('Taxonomy', 'Taxonomy', 'Taxonomies', 'account_tree', 'TAX'),
            $t('Framework', 'Framework', 'Frameworks', 'library_books', 'FW'),
            $t('Requirement', 'Requirement', 'Requirements', 'rule', 'REQ'),
            $t('Unit', 'Unit of Measure', 'Units of Measure', 'straighten', 'UOM'),
            $t('Currency', 'Currency', 'Currencies', 'payments', 'CUR'),
        ];
    }

    /**
     * The typed edges.
     *
     * from/to are declared as type codes and resolved to ids at seed time, so
     * a relationship can be validated before it is written: 'mitigates' from
     * anything other than a Control is a data error, not a preference.
     *
     * An empty from/to list means "any type" — used where the edge is
     * deliberately generic (maps_to, governed_by).
     *
     * @return list<array<string, mixed>>
     */
    public static function relationshipTypes(): array
    {
        return [
            [
                'code' => 'mitigates',
                'name' => 'Mitigates',
                'inverse_code' => 'mitigated_by',
                'from_type_codes' => ['Control'],
                'to_type_codes' => ['Risk', 'Opportunity', 'EmergingRisk'],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
                'attribute_schema' => [
                    'is_key_control' => ['type' => 'bool', 'label' => 'Key control'],
                    'mapping_rationale' => ['type' => 'text', 'label' => 'Rationale'],
                ],
            ],
            [
                'code' => 'owns',
                'name' => 'Owns',
                'inverse_code' => 'owned_by',
                'from_type_codes' => [],
                'to_type_codes' => [],
                'cardinality' => 'one_to_many',
                'has_weight' => false,
            ],
            [
                'code' => 'supports',
                'name' => 'Supports',
                'inverse_code' => 'supported_by',
                'from_type_codes' => [],
                'to_type_codes' => ['Objective', 'ImportantBusinessService', 'Product', 'Process'],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
            ],
            [
                'code' => 'depends_on',
                'name' => 'Depends on',
                'inverse_code' => 'depended_on_by',
                'from_type_codes' => [],
                'to_type_codes' => [],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
            ],
            [
                'code' => 'maps_to',
                'name' => 'Maps to',
                'inverse_code' => 'mapped_from',
                'from_type_codes' => [],
                'to_type_codes' => ['Requirement', 'Framework', 'Obligation', 'Taxonomy', 'RiskCategory'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
                'attribute_schema' => [
                    'regulation_name' => ['type' => 'string', 'label' => 'Regulation'],
                    'requirement_ref' => ['type' => 'string', 'label' => 'Requirement reference'],
                    'mapping_notes' => ['type' => 'text', 'label' => 'Notes'],
                ],
            ],
            [
                // Three-lines assurance map: which line assures what.
                'code' => 'assures',
                'name' => 'Assures',
                'inverse_code' => 'assured_by',
                'from_type_codes' => ['Audit', 'ControlTest', 'AssessmentCampaign'],
                'to_type_codes' => [],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
                'attribute_schema' => [
                    'assurance_line' => ['type' => 'enum', 'options' => ['first', 'second', 'third'], 'label' => 'Line of defence'],
                ],
            ],
            [
                'code' => 'reports_to',
                'name' => 'Reports to',
                'inverse_code' => 'receives_report_from',
                'from_type_codes' => [],
                'to_type_codes' => [],
                'cardinality' => 'one_to_many',
                // Weighted: a subsidiary contributing 30% of group exposure
                // reports to the group at 0.3, and that is the number the
                // graph-derived roll-up multiplies by.
                'has_weight' => true,
            ],
            [
                'code' => 'assessed_in',
                'name' => 'Assessed in',
                'inverse_code' => 'assesses',
                'from_type_codes' => ['Risk', 'Control', 'Opportunity'],
                'to_type_codes' => ['RiskAssessment', 'AssessmentCampaign', 'MaturityAssessment', 'BIA', 'DPIA'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
            ],
            [
                'code' => 'threatens',
                'name' => 'Threatens',
                'inverse_code' => 'threatened_by',
                'from_type_codes' => ['Risk', 'EmergingRisk', 'Scenario'],
                'to_type_codes' => [],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
            ],
            [
                'code' => 'derives_from',
                'name' => 'Derives from',
                'inverse_code' => 'derives',
                'from_type_codes' => [],
                'to_type_codes' => [],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
                'attribute_schema' => [
                    'relationship_type' => ['type' => 'string', 'label' => 'Relationship type'],
                    'correlation_strength' => ['type' => 'string', 'label' => 'Correlation strength'],
                ],
            ],
            [
                'code' => 'causes',
                'name' => 'Causes',
                'inverse_code' => 'caused_by',
                'from_type_codes' => [],
                'to_type_codes' => [],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
            ],
            [
                'code' => 'results_in',
                'name' => 'Results in',
                'inverse_code' => 'resulted_from',
                'from_type_codes' => ['Risk', 'NearMiss', 'Issue', 'Scenario'],
                'to_type_codes' => ['LossEvent', 'Issue', 'Action'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
            ],
            [
                'code' => 'governed_by',
                'name' => 'Governed by',
                'inverse_code' => 'governs',
                'from_type_codes' => [],
                'to_type_codes' => ['Policy', 'Obligation', 'Framework', 'RiskAppetite'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
            ],
            [
                'code' => 'tested_by',
                'name' => 'Tested by',
                'inverse_code' => 'tests',
                'from_type_codes' => ['Control'],
                'to_type_codes' => ['ControlTest'],
                'cardinality' => 'one_to_many',
                'has_weight' => false,
            ],
            [
                'code' => 'delivered_by',
                'name' => 'Delivered by',
                'inverse_code' => 'delivers',
                'from_type_codes' => ['ImportantBusinessService', 'Product', 'Channel'],
                'to_type_codes' => ['Process', 'System', 'Application', 'Vendor', 'BusinessUnit'],
                'cardinality' => 'many_to_many',
                'has_weight' => true,
            ],

            /* ---- edges the pivot migration needs beyond the seeded fifteen ---- */
            [
                'code' => 'failed_control',
                'name' => 'Failed control',
                'inverse_code' => 'failed_in',
                'from_type_codes' => ['LossEvent'],
                'to_type_codes' => ['Control'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
                'attribute_schema' => [
                    'failure_type' => ['type' => 'string', 'label' => 'Failure type'],
                    'failure_description' => ['type' => 'text', 'label' => 'Failure description'],
                ],
            ],
            [
                'code' => 'monitored_by',
                'name' => 'Monitored by',
                'inverse_code' => 'monitors',
                'from_type_codes' => ['Risk', 'Opportunity', 'Objective'],
                'to_type_codes' => ['KeyRiskIndicator', 'KeyPerformanceIndicator'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
                'attribute_schema' => [
                    'correlation_type' => ['type' => 'string', 'label' => 'Correlation type'],
                ],
            ],
            [
                'code' => 'converted_to',
                'name' => 'Converted to',
                'inverse_code' => 'converted_from',
                'from_type_codes' => ['NearMiss'],
                'to_type_codes' => ['LossEvent'],
                'cardinality' => 'one_to_one',
                'has_weight' => false,
            ],
            [
                'code' => 'treats',
                'name' => 'Treats',
                'inverse_code' => 'treated_by',
                'from_type_codes' => ['TreatmentPlan'],
                'to_type_codes' => ['Risk', 'Opportunity', 'Issue'],
                'cardinality' => 'many_to_many',
                'has_weight' => false,
            ],
        ];
    }

    /**
     * Default lifecycles.
     *
     * The state codes are the values already sitting in the domain tables, not
     * an idealised set — a lifecycle whose states do not match the data cannot
     * validate a transition, and inventing new spellings here would strand
     * every existing row in an unknown state. Where a table's status column is
     * uppercase (issues, loss_events) the lifecycle is uppercase too.
     *
     * @return list<array<string, mixed>>
     */
    public static function lifecycles(): array
    {
        return [
            [
                'object_type_code' => 'Risk',
                'code' => 'risk_default',
                'name' => 'Risk lifecycle',
                'states' => [
                    self::state('draft', 'Draft', '#A0AEC0', ['active'], initial: true, permission: 'risk.create'),
                    self::state('active', 'Active', '#C53030', ['dormant', 'closed'], permission: 'risk.edit'),
                    self::state('dormant', 'Dormant', '#718096', ['active', 'closed'], permission: 'risk.edit'),
                    self::state('closed', 'Closed', '#2F855A', [], terminal: true, permission: 'risk.approve'),
                ],
            ],
            [
                'object_type_code' => 'Control',
                'code' => 'control_default',
                'name' => 'Control lifecycle',
                'states' => [
                    self::state('draft', 'Draft', '#A0AEC0', ['active'], initial: true, permission: 'control.create'),
                    self::state('active', 'Active', '#2B6CB0', ['inactive', 'retired'], permission: 'control.edit'),
                    self::state('inactive', 'Inactive', '#718096', ['active', 'retired'], permission: 'control.edit'),
                    self::state('retired', 'Retired', '#4A5568', [], terminal: true, permission: 'control.delete'),
                ],
            ],
            [
                'object_type_code' => 'Issue',
                'code' => 'issue_default',
                'name' => 'Issue lifecycle',
                'states' => [
                    self::state('OPEN', 'Open', '#E53E3E', ['IN_PROGRESS', 'OVERDUE', 'CLOSED'], initial: true, permission: 'issue.create'),
                    self::state('IN_PROGRESS', 'In progress', '#DD6B20', ['OVERDUE', 'PENDING_CLOSURE', 'CLOSED'], permission: 'issue.edit'),
                    self::state('OVERDUE', 'Overdue', '#9B2C2C', ['IN_PROGRESS', 'PENDING_CLOSURE', 'CLOSED'], permission: 'issue.edit'),
                    self::state('PENDING_CLOSURE', 'Pending closure', '#D69E2E', ['CLOSED', 'IN_PROGRESS'], permission: 'issue.edit'),
                    self::state('CLOSED', 'Closed', '#2F855A', [], terminal: true, permission: 'issue.close'),
                ],
            ],
            [
                'object_type_code' => 'TreatmentPlan',
                'code' => 'treatment_default',
                'name' => 'Treatment plan lifecycle',
                'states' => [
                    self::state('draft', 'Draft', '#A0AEC0', ['not_started', 'approved'], initial: true, permission: 'treatment.create'),
                    self::state('not_started', 'Not started', '#718096', ['in_progress', 'approved'], permission: 'treatment.edit'),
                    self::state('approved', 'Approved', '#4299E1', ['in_progress'], permission: 'treatment.approve'),
                    self::state('in_progress', 'In progress', '#DD6B20', ['completed', 'cancelled'], permission: 'treatment.edit'),
                    self::state('completed', 'Completed', '#2F855A', [], terminal: true, permission: 'treatment.approve'),
                    self::state('cancelled', 'Cancelled', '#4A5568', [], terminal: true, permission: 'treatment.approve'),
                ],
            ],
            [
                'object_type_code' => 'LossEvent',
                'code' => 'loss_event_default',
                'name' => 'Loss event lifecycle',
                'states' => [
                    self::state('NEW', 'New', '#A0AEC0', ['REPORTED'], initial: true, permission: 'loss_event.create'),
                    self::state('REPORTED', 'Reported', '#4299E1', ['INVESTIGATING', 'UNDER_INVESTIGATION'], permission: 'loss_event.edit'),
                    self::state('INVESTIGATING', 'Investigating', '#DD6B20', ['RCA_COMPLETE', 'UNDER_INVESTIGATION'], permission: 'loss_event.edit'),
                    self::state('UNDER_INVESTIGATION', 'Under investigation', '#DD6B20', ['RCA_COMPLETE'], permission: 'loss_event.edit'),
                    self::state('RCA_COMPLETE', 'RCA complete', '#D69E2E', ['CLOSED'], permission: 'loss_event.approve'),
                    self::state('CLOSED', 'Closed', '#2F855A', [], terminal: true, permission: 'loss_event.approve'),
                ],
            ],
            [
                'object_type_code' => 'Policy',
                'code' => 'policy_default',
                'name' => 'Policy lifecycle',
                'states' => [
                    self::state('draft', 'Draft', '#A0AEC0', ['under_review'], initial: true, permission: 'regulatory.manage'),
                    self::state('under_review', 'Under review', '#DD6B20', ['approved', 'draft'], permission: 'regulatory.manage'),
                    self::state('approved', 'Approved', '#4299E1', ['published'], permission: 'approval.act'),
                    self::state('published', 'Published', '#2F855A', ['superseded', 'under_review'], permission: 'regulatory.manage'),
                    self::state('superseded', 'Superseded', '#4A5568', [], terminal: true, permission: 'regulatory.manage'),
                ],
            ],
            [
                'object_type_code' => 'Obligation',
                'code' => 'obligation_default',
                'name' => 'Obligation lifecycle',
                'states' => [
                    self::state('identified', 'Identified', '#A0AEC0', ['assessed'], initial: true, permission: 'regulatory.manage'),
                    self::state('assessed', 'Assessed', '#4299E1', ['compliant', 'non_compliant', 'partially_compliant'], permission: 'regulatory.manage'),
                    self::state('compliant', 'Compliant', '#2F855A', ['assessed', 'non_compliant', 'partially_compliant'], permission: 'regulatory.manage'),
                    self::state('partially_compliant', 'Partially compliant', '#D69E2E', ['assessed', 'compliant', 'non_compliant'], permission: 'regulatory.manage'),
                    self::state('non_compliant', 'Non-compliant', '#C53030', ['assessed', 'partially_compliant', 'compliant'], permission: 'regulatory.manage'),
                    self::state('retired', 'Retired', '#4A5568', [], terminal: true, permission: 'regulatory.manage'),
                ],
            ],
            [
                'object_type_code' => 'Audit',
                'code' => 'audit_default',
                'name' => 'Audit lifecycle',
                'states' => [
                    self::state('planned', 'Planned', '#A0AEC0', ['fieldwork'], initial: true, permission: 'control_test.create'),
                    self::state('fieldwork', 'Fieldwork', '#DD6B20', ['draft_report'], permission: 'control_test.execute'),
                    self::state('draft_report', 'Draft report', '#D69E2E', ['final_report', 'fieldwork'], permission: 'control_test.review'),
                    self::state('final_report', 'Final report', '#4299E1', ['closed'], permission: 'control_test.review'),
                    self::state('closed', 'Closed', '#2F855A', [], terminal: true, permission: 'control_test.review'),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $transitions
     * @return array<string, mixed>
     */
    private static function state(
        string $code,
        string $name,
        string $color,
        array $transitions,
        bool $initial = false,
        bool $terminal = false,
        ?string $permission = null,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'color' => $color,
            'is_initial' => $initial,
            'is_terminal' => $terminal,
            'allowed_transitions' => $transitions,
            'required_permission' => $permission,
            'required_workflow_id' => null,
        ];
    }

    /**
     * Legacy entity_types.code => system object type code.
     *
     * The nine seeded entity types map onto the sixteen org-node types without
     * loss. Anything a tenant has added to entity_types since is not in this
     * map and is handled by the unification migration, which falls back to
     * matching on the type *name* and, failing that, records the row in the
     * merge report instead of guessing.
     *
     * @return array<string, string>
     */
    public static function legacyEntityTypeMap(): array
    {
        return [
            'GROUP' => 'Enterprise',
            'SUBSIDIARY' => 'LegalEntity',
            'DIVISION' => 'Division',
            'DEPARTMENT' => 'Department',
            'REGION' => 'Geography',
            'BRANCH' => 'Branch',
            'UNIT' => 'BusinessUnit',
            'PROCESS' => 'Process',
            'SYSTEM' => 'System',
        ];
    }

    /**
     * Model class => object type code, for the models carrying HasObjectIdentity.
     *
     * Declared here as well as on the models themselves so the backfill
     * migration can resolve a type without booting Eloquent models whose
     * columns may have changed since the migration was written.
     *
     * Entity is the one model whose type is *not* fixed by its class: an entity
     * row is a Group or a Branch or a Process depending on its entity_type_id.
     * The value below is only the fallback used when that lookup fails;
     * Entity::resolveObjectTypeCode() does the real mapping through
     * legacyEntityTypeMap().
     *
     * @return array<string, string>
     */
    public static function modelTypeMap(): array
    {
        return [
            \App\Models\Risk::class => 'Risk',
            \App\Models\Control::class => 'Control',
            \App\Models\KeyRiskIndicator::class => 'KeyRiskIndicator',
            \App\Models\Issue::class => 'Issue',
            \App\Models\LossEvent::class => 'LossEvent',
            \App\Models\NearMiss::class => 'NearMiss',
            \App\Models\TreatmentPlan::class => 'TreatmentPlan',
            \App\Models\RiskAppetite::class => 'RiskAppetite',
            \App\Models\AssessmentCampaign::class => 'AssessmentCampaign',
            \App\Models\ControlTest::class => 'ControlTest',
            \App\Models\Entity::class => 'BusinessUnit',
            \App\Models\BusinessUnit::class => 'BusinessUnit',
            \App\Models\BusinessProcess::class => 'Process',
            \App\Models\RiskCategory::class => 'RiskCategory',
            \App\Models\QuantificationScenario::class => 'Scenario',
        ];
    }
}
