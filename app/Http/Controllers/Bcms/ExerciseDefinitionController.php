<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreExerciseDefinitionRequest;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Exercise definitions: the declared rhythm.
 *
 * THE PREVIEW IS A JSON ENDPOINT, NOT A REDIRECT. The wizard's live sentence —
 * "this will create 4 occurrences and 56 notifications across the year" —
 * updates as somebody changes the frequency, and a full Inertia round trip per
 * keystroke would make it stop being live.
 */
class ExerciseDefinitionController extends Controller
{
    public function __construct(
        private ExerciseDefinitionService $definitions,
        private ExerciseProgrammeService $programmes,
    ) {}

    public function store(StoreExerciseDefinitionRequest $request, ExerciseProgramme $programme): RedirectResponse
    {
        $type = ExerciseType::query()->findOrFail($request->integer('exercise_type_id'));

        $definition = $this->definitions->create(
            $programme,
            $type,
            $request->string('name')->toString(),
            $request->safe()->except(['exercise_type_id', 'name']),
            $request->user()?->getKey(),
        );

        return back()->with('success', 'Exercise "'.$definition->name.'" added to the '.$programme->year.' programme.');
    }

    public function update(StoreExerciseDefinitionRequest $request, ExerciseDefinition $definition): RedirectResponse
    {
        $definition->update($request->safe()->all() + ['updated_by' => $request->user()?->getKey()]);

        return back()->with(
            'success',
            'Exercise updated. Regenerate the occurrences for the change to reach the calendar.'
        );
    }

    /**
     * What generating would do. Nothing is written.
     *
     * Accepts an unsaved definition's attributes so the wizard can preview
     * before anything exists — the numbers are the point of the wizard, and a
     * preview only available after saving would arrive too late to change a
     * decision.
     */
    public function preview(Request $request, ExerciseProgramme $programme): JsonResponse
    {
        Gate::authorize('bcms.exercise.manage');

        $data = $request->validate([
            'definition_id' => ['nullable', 'integer'],
            'exercise_type_id' => ['required_without:definition_id', 'nullable', 'integer'],
            'frequency_per_year' => ['nullable', 'integer', 'min:1', 'max:52'],
            'distribution_mode' => ['nullable', 'string', 'max:20'],
            'preferred_window' => ['nullable', 'array'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'daily_reminder_enabled' => ['nullable', 'boolean'],
            'unannounced' => ['nullable', 'boolean'],
            'business_unit_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'process_ids' => ['nullable', 'array'],
            'default_audience_rule' => ['nullable', 'array'],
        ]);

        $definition = isset($data['definition_id'])
            ? ExerciseDefinition::query()->find($data['definition_id'])
            : null;

        if ($definition === null) {
            // An UNSAVED model: filled, related to the programme so the
            // generator can read the year, and never persisted. Previewing by
            // creating and rolling back would burn ids and fire events.
            $definition = new ExerciseDefinition(array_merge([
                'exercise_programme_id' => $programme->getKey(),
                'organization_id' => $programme->organization_id,
                'name' => 'Preview',
                'frequency_per_year' => 1,
                'distribution_mode' => 'even',
                'duration_minutes' => 120,
                'lead_time_days' => 10,
            ], array_diff_key($data, array_flip(['definition_id']))));

            $definition->setRelation('exerciseProgramme', $programme);
        } else {
            $definition->fill(array_diff_key($data, array_flip(['definition_id', 'exercise_type_id'])));
        }

        return response()->json($this->definitions->preview($definition));
    }

    /** Materialise the occurrences and arm the schedule. */
    public function generate(Request $request, ExerciseDefinition $definition): RedirectResponse
    {
        Gate::authorize('bcms.exercise.manage');

        try {
            $log = $this->definitions->generate($definition, $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $programme = $definition->exerciseProgramme;

        if ($programme !== null) {
            $this->programmes->refreshCounts($programme);
        }

        return back()->with('success', sprintf(
            '%d occurrences placed%s%s.',
            $log['placed'],
            $log['shifted'] > 0 ? ', '.$log['shifted'].' shifted around a conflict' : '',
            $log['needs_scheduling'] > 0
                ? ', '.$log['needs_scheduling'].' could not be placed and are waiting to be scheduled'
                : '',
        ));
    }
}
