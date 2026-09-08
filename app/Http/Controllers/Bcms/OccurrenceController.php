<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\ExerciseOccurrence;
use App\Services\Bcms\Exercises\RescheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Moving and cancelling exercises.
 *
 * BOTH ROUTES REFUSE BEFORE THEY ACT. A reschedule inside `min_notice_days`
 * returns without moving anything and with an approval request instead; a
 * cancellation of a mandatory exercise refuses without a waiver. The service
 * makes those decisions; this turns them into sentences, because "you cannot"
 * without "and here is what to do instead" is how a user concludes the product
 * is broken.
 */
class OccurrenceController extends Controller
{
    public function __construct(private RescheduleService $reschedules) {}

    public function reschedule(Request $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        Gate::authorize('bcms.exercise.schedule');

        $data = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'justification' => ['required', 'string', 'min:10', 'max:2000'],
            'accept_conflicts' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->reschedules->reschedule(
                $occurrence,
                $data['scheduled_date'],
                $data['justification'],
                $request->user(),
                (bool) ($data['accept_conflicts'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['moved']) {
            return back()->with('success', 'Moved, and the reason is on the record.');
        }

        if ($result['approval'] !== null) {
            return back()->with(
                'success',
                'This exercise is inside its minimum notice period, so it has not moved. A request has gone to the '
                .'programme owner — moving something this close to its date is a decision somebody signs.'
            );
        }

        return back()
            ->with('conflicts', $result['conflicts'])
            ->with('error', 'That date clashes: '.$result['conflicts'][0]['message']
                .' Choose another date, or confirm that you want to double-book.');
    }

    public function cancel(Request $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        Gate::authorize('bcms.exercise.schedule');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'waiver' => ['nullable', 'boolean'],
        ]);

        // The waiver is not a checkbox anybody can tick: cancelling a mandatory
        // exercise is an executive act, and `bcms.exercise.approve` is who
        // holds it.
        $waiver = (bool) ($data['waiver'] ?? false)
            && $request->user()?->can('bcms.exercise.approve') === true;

        try {
            $this->reschedules->cancel($occurrence, $data['reason'], $request->user(), $waiver);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Exercise cancelled, with the reason recorded against it.');
    }
}
