<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Presenters\Bcms\ResilienceCalendarPresenter;
use App\Services\Bcms\Exercises\IcsFeedBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The resilience calendar — the module's centrepiece screen.
 *
 * ONE ROUTE, EIGHT VIEWS, because they are one calendar. A route per view would
 * mean eight sets of filters to keep in step, and the first thing that breaks
 * is that the month view and the compliance view disagree about whether an
 * exercise happened.
 */
class CalendarController extends Controller
{
    public function __construct(private ResilienceCalendarPresenter $presenter) {}

    public function index(Request $request)
    {
        Gate::authorize('bcms.exercise.view');

        $data = $request->validate([
            'view' => ['nullable', Rule::in(ResilienceCalendarPresenter::views())],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'business_unit_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'exercise_type_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'],
            'ladder_level' => ['nullable', 'string', 'max:20'],
            'regulatory_driver' => ['nullable', 'string', 'max:60'],
            'criticality_tier' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $year = (int) ($data['year'] ?? now()->year);

        return Inertia::render('Bcms/Calendar/Index', $this->presenter->present(
            view: $data['view'] ?? 'year',
            year: $year,
            from: isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null,
            to: isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null,
            user: $request->user(),
            filters: array_filter([
                'business_unit_id' => $data['business_unit_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'exercise_type_id' => $data['exercise_type_id'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'status' => $data['status'] ?? null,
                'ladder_level' => $data['ladder_level'] ?? null,
                'regulatory_driver' => $data['regulatory_driver'] ?? null,
                'criticality_tier' => $data['criticality_tier'] ?? null,
            ], fn ($v) => $v !== null),
        ));
    }

    /**
     * The subscribable feed.
     *
     * SIGNED, NOT AUTHENTICATED, and deliberately outside the session: Outlook
     * and Google fetch this with no cookie and no bearer token, so a route
     * behind `auth` would simply never work. The signature is the credential —
     * per user, tamper-evident, and revocable by rotating the app key. What it
     * can expose is one user's own calendar, which that user could see anyway.
     *
     * THE TENANT IS SET FROM THE BOUND USER, EXPLICITLY, AND THAT IS NOT
     * OPTIONAL. `OrganizationScope` is INERT when no tenant is resolved — a
     * deliberate choice so that console commands and seeders work — and its
     * docblock's reason for being safe is that "HTTP requests can never reach a
     * controller untenanted because ResolveTenant aborts first". This route is
     * the exception to that sentence: it has no session for `ResolveTenant` to
     * work from. Without the line below, every tenant's exercises would be in
     * every feed.
     *
     * UNANNOUNCED EXERCISES ARE NOT IN IT. Publishing a surprise call-tree test
     * to the participants' Outlook would defeat the exercise.
     */
    public function ics(Request $request, User $user, IcsFeedBuilder $ics): Response
    {
        if (! $user->is_active || $user->organization_id === null) {
            // A disabled account keeps a valid signature until the key rotates.
            // The feed is where that matters most, because a subscription keeps
            // fetching long after somebody has left.
            abort(404);
        }

        TenantContext::set((int) $user->organization_id);

        try {
            $body = $ics->build($user);
        } finally {
            TenantContext::clear();
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="bcms-exercises.ics"',
            // A subscription is polled. Telling the client not to cache means a
            // reschedule shows up on the next refresh rather than whenever the
            // proxy feels like it.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
