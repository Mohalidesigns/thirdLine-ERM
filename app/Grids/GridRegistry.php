<?php

namespace App\Grids;

use InvalidArgumentException;

/**
 * Name → definition map. The Livewire component receives the NAME (a string
 * survives Livewire hydration; an object would not) and resolves it here on
 * every request.
 */
class GridRegistry
{
    /** @var array<string, class-string<GridDefinition>> */
    protected static array $grids = [
        'controls' => Definitions\ControlsGrid::class,
        'risks' => Definitions\RisksGrid::class,
        'risk_library' => Definitions\RiskLibraryGrid::class,
        'processes' => Definitions\ProcessGrid::class,
        'process_groups' => Definitions\ProcessGroupGrid::class,
        'treatments' => Definitions\TreatmentPlansGrid::class,
        'issues' => Definitions\IssuesGrid::class,
        'loss_events' => Definitions\LossEventsGrid::class,
        'kris' => Definitions\KrisGrid::class,
        'kri_breaches' => Definitions\KriBreachesGrid::class,
        'assessments' => Definitions\RiskAssessmentsGrid::class,
        'entities' => Definitions\EntitiesGrid::class,
        'near_misses' => Definitions\NearMissesGrid::class,
        'control_tests' => Definitions\ControlTestsGrid::class,
        'campaigns' => Definitions\CampaignsGrid::class,
        'questionnaires' => Definitions\QuestionnairesGrid::class,
        'question_library' => Definitions\QuestionLibraryGrid::class,
        'imports' => Definitions\DataImportsGrid::class,
        'admin_users' => Definitions\AdminUsersGrid::class,
        'emerging_risks' => Definitions\EmergingRisksGrid::class,
        'reports_library' => Definitions\ReportsLibraryGrid::class,
        'approvals_history' => Definitions\ApprovalsHistoryGrid::class,
        'circulars' => Definitions\RegulatoryCircularsGrid::class,
        'tprm_third_parties' => Definitions\TprmThirdPartiesGrid::class,
        'tprm_engagements' => Definitions\TprmEngagementsGrid::class,
        'tprm_assessments' => Definitions\TprmAssessmentsGrid::class,
        'tprm_documents' => Definitions\TprmDocumentsGrid::class,
        'tprm_contracts' => Definitions\TprmContractsGrid::class,
        'tprm_obligations' => Definitions\TprmObligationsGrid::class,
        'tprm_ai_usage_events' => Definitions\TprmAiUsageEventsGrid::class,
        // Scenario Modelling & Analysis (ADR 0027, spec 00). Registered here rather than
        // by a provider because Sm has no service provider (§A1.11, ADR 0007 deviation 2)
        // — and `can:view-grid,grid` on the `risk.grids.{grid}` route group resolves the
        // definition's own `permission()` through this map, so the grid's CSV/XLSX export
        // is gated by `scenario.view` with no new route.
        'scenarios' => Definitions\ScenariosGrid::class,
    ];

    public static function resolve(string $name): GridDefinition
    {
        $class = static::$grids[$name] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown data grid [{$name}].");
        }

        return app($class);
    }

    /** @param class-string<GridDefinition> $class */
    public static function register(string $name, string $class): void
    {
        static::$grids[$name] = $class;
    }
}
