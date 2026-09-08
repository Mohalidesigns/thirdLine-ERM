<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\DistributionMode;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Services\Bcms\AudienceResolver;
use App\Support\Bcms\AudienceRule;
use InvalidArgumentException;

/**
 * Exercise definitions: the declared rhythm, before it becomes dates.
 *
 * THE TYPE'S DEFAULTS ARE A STARTING POINT, NOT A CONSTRAINT. A definition
 * created from `DRFAILOVER` inherits 4×/year, 480 minutes and a 10-day lead
 * because the CBN's open banking framework says so; a bank that wants six is
 * free to say six. What it should not be able to do is drop below a
 * regulator's cadence WITHOUT BEING TOLD — `cadenceWarnings()` is that
 * sentence, and like every other rule in this module it warns rather than
 * blocks, because the system's job is to hold the truth about what the bank
 * does, not to refuse to record it.
 *
 * THE PREVIEW RUNS THE REAL GENERATOR. "This will create 4 occurrences and 56
 * notifications" is a promise, and a preview computed by a second, simpler
 * algorithm is a promise the product then breaks the first time a blackout
 * moves something.
 */
class ExerciseDefinitionService
{
    public function __construct(
        private readonly OccurrenceGenerator $generator,
        private readonly LadderAdvisor $ladder,
        private readonly AudienceResolver $audience,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        ExerciseProgramme $programme,
        ExerciseType $type,
        string $name,
        array $attributes = [],
        ?int $userId = null,
    ): ExerciseDefinition {
        return ExerciseDefinition::query()->create(array_merge([
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => $name,
            // Inherited from the type where the caller has not said otherwise.
            'frequency_per_year' => $type->default_frequency_per_year,
            'duration_minutes' => $type->default_duration_minutes,
            'lead_time_days' => $type->default_lead_time_days,
            'distribution_mode' => DistributionMode::Even->value,
            // Set explicitly rather than left to the column default: a model
            // returned by `create()` carries only the attributes it was given,
            // so an unset flag reads as null — and null is falsy, which would
            // make the wizard's notification estimate silently count one
            // reminder instead of eleven.
            'daily_reminder_enabled' => true,
            'min_notice_days' => 3,
            'objectives' => $type->objectives_template,
            'regulatory_drivers' => array_filter([$type->cadence_clause_ref]),
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_8_5_programme->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * What generating this definition would do — without writing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(ExerciseDefinition $definition): array
    {
        $plan = $this->generator->preview($definition);
        $audience = $this->audienceSize($definition);

        return [
            'log' => $plan['log'],
            'occurrences' => array_map(fn (array $o) => [
                'sequence_no' => $o['sequence_no'],
                'date' => $o['date'],
                'start' => $o['start'],
            ], $plan['occurrences']),
            'audience_size' => $audience,
            // The wizard's headline sentence. The notification count is the
            // ladder Phase 5 will materialise — `lead_time_days` daily alerts
            // plus the day itself — times the resolved audience, per occurrence.
            'notification_estimate' => $this->notificationEstimate($definition, count($plan['occurrences']), $audience),
            'ladder_warnings' => $this->ladder->adviseDefinition($definition),
            'cadence_warnings' => $this->cadenceWarnings($definition),
        ];
    }

    public function generate(ExerciseDefinition $definition, ?int $userId = null): array
    {
        if ($definition->status === 'retired') {
            throw new InvalidArgumentException('A retired definition does not generate occurrences.');
        }

        return $this->generator->generate($definition, $userId);
    }

    /**
     * Where the definition asks for less testing than a regulator does.
     *
     * @return list<array{severity: string, message: string}>
     */
    public function cadenceWarnings(ExerciseDefinition $definition): array
    {
        $type = $definition->exerciseType;

        if ($type === null || blank($type->cadence_clause_ref)) {
            return [];
        }

        $required = (int) $type->default_frequency_per_year;
        $chosen = (int) $definition->frequency_per_year;

        if ($chosen >= $required) {
            return [];
        }

        return [[
            'severity' => 'warning',
            'message' => 'This exercise is set to run '.$chosen.' times a year. '.$type->name
                .' has a regulatory cadence of '.$required.' ('.$type->cadence_clause_ref.'), so at '.$chosen
                .' the institution is outside it. Recording a lower figure is allowed — being unable to say which '
                .'rule it falls short of is not.',
        ]];
    }

    /**
     * How many people this definition currently resolves to.
     *
     * Zero is a real answer and is shown as one: a definition whose audience
     * resolves to nobody will generate a year of exercises that nobody is told
     * about, and that is worth seeing in the wizard rather than in March.
     */
    public function audienceSize(ExerciseDefinition $definition): int
    {
        $rule = AudienceRule::fromJson($definition->default_audience_rule);

        return $rule === null ? 0 : $this->audience->count($rule);
    }

    /**
     * @return array{per_occurrence: int, total: int, basis: string}
     */
    public function notificationEstimate(ExerciseDefinition $definition, int $occurrences, int $audience): array
    {
        // The T-10 ladder is `lead_time_days` daily reminders plus the day
        // itself. An unannounced exercise sends none of them to participants —
        // that is the whole point of it — so the estimate is the facilitator
        // only, which is one person.
        $ladderDays = $definition->daily_reminder_enabled
            ? max(1, (int) $definition->lead_time_days) + 1
            : 1;

        $recipients = $definition->unannounced ? 1 : $audience;
        $perOccurrence = $ladderDays * $recipients;

        return [
            'per_occurrence' => $perOccurrence,
            'total' => $perOccurrence * $occurrences,
            'basis' => $definition->unannounced
                ? 'Unannounced: participants are not warned, so only the facilitator is counted.'
                : $ladderDays.' daily reminders to '.$audience.' people, per occurrence.',
        ];
    }
}
