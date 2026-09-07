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
