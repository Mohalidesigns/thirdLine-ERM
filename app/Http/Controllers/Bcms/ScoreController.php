<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreExerciseScoreRequest;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseScore;
use App\Services\Bcms\Exercises\ScoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Observer scoring — mobile-first, one objective at a time
 * (observer-scoring spec).
 *
 * A NARROWER PERMISSION THAN FACILITATING. `bcms.exercise.evaluate` is a
 * separate grant from `bcms.exercise.facilitate` (clause map §1.3) — an
 * observer scores; they do not run the exercise.
 *
 * THIS SCREEN SHOWS ONLY THE SIGNED-IN EVALUATOR'S OWN ROWS. Multiple
 * evaluators may score the same objective; nobody edits anybody else's row
 * (spec §3).
 */
class ScoreController extends Controller
{
    public function __construct(private ScoringService $scoring) {}

    public function show(Request $request, ExerciseOccurrence $occurrence): Response
    {
        Gate::authorize('bcms.exercise.evaluate');

        $evaluatorId = (int) $request->user()?->getKey();
        $objectives = $this->scoring->objectivesFor($occurrence);

        $mine = ExerciseScore::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('evaluator_id', $evaluatorId)
            ->get()
            ->keyBy('objective_text');

        $othersCount = ExerciseScore::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('evaluator_id', '!=', $evaluatorId)
            ->whereNotNull('evaluator_id')
            ->get()
            ->countBy('objective_text');

        $closed = $occurrence->aar?->status === 'final';

        return Inertia::render('Bcms/Exercises/Score', [
            'occurrence' => [
                'uuid' => $occurrence->uuid,
                'title' => $occurrence->definition?->name,
            ],
            'closed' => $closed,
            'objectives' => collect($objectives)->map(function (array $o) use ($mine, $othersCount) {
                $existing = $mine->get($o['text']);

                return [
                    'index' => $o['index'],
                    'text' => $o['text'],
                    // The saved score row's own id — evidence (ADR 0019, kind
                    // `score`) attaches to this once the score itself exists;
                    // there is nothing to attach a photo to before the first
                    // save, so the capture control only appears once this is
                    // non-null (spec §3's "already scored" state).
                    'score_id' => $existing?->getKey(),
                    'my_score' => $existing?->score,
                    'my_commentary' => $existing?->commentary,
                    'saved' => $existing !== null,
                    'others_count' => (int) ($othersCount[$o['text']] ?? 0),
                ];
            })->values()->all(),
            'store_url' => route('bcms.occurrences.scores.store', $occurrence),
            'workspace_url' => route('bcms.occurrences.workspace', $occurrence),
            // Same evidence endpoint the workspace uses (ADR 0019 §1.5's
            // `KIND_SCORE` owner kind) — this screen posts `owner_type=score`,
            // `owner_id` = the row's own id above.
            'evidence_upload_url' => route('bcms.evidence.store', $occurrence),
        ]);
    }

    public function store(StoreExerciseScoreRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->scoring->score(
                $occurrence,
                (int) $data['objective_index'],
                (int) $data['score'],
                $data['commentary'] ?? null,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }
}
