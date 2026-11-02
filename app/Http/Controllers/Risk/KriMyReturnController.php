<?php

namespace App\Http\Controllers\Risk;

use App\Enums\KriReturnStatus;
use App\Exceptions\KriCycleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kri\ReopenKriReturnRequest;
use App\Http\Requests\Kri\SaveKriReturnDraftRequest;
use App\Http\Requests\Kri\SubmitKriReturnRequest;
use App\Models\BusinessUnit;
use App\Models\KriReturn;
use App\Models\Period;
use App\Presenters\Kri\KriReturnPresenter;
use App\Services\Kri\KriReturnService;
use App\Support\Kri\KriDepartmentScope;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Complete my KRIs" (ADR 0026 sections 10 and 16): the champion's screen and
 * its three writes. The controller resolves, authorises (policy / request) and
 * hands the work to KriReturnService; lifecycle refusals answer with a flash
 * message, never a 403.
 */
class KriMyReturnController extends Controller
{
    public function show(Request $request, KriReturnPresenter $presenter, KriReturnService $service, KriDepartmentScope $scope, ?int $department = null, ?int $period = null)
    {
        $user = $request->user();
        $championUnits = $scope->championUnitIdsFor($user);

        if ($championUnits === []) {
            return Inertia::render('Kri/MyReturn', $presenter->myReturnShell('not_configured', []));
        }

        // The default is the champion's first department, by name, that has a
        // return still waiting on them; failing that, the first by name.
        $unit = $department !== null
            ? BusinessUnit::query()->find($department)
            : (BusinessUnit::query()
                ->whereIn('id', $championUnits)
                ->whereIn('id', KriReturn::query()
                    ->whereIn('business_unit_id', $championUnits)
                    ->whereIn('status', [KriReturnStatus::Draft->value, KriReturnStatus::Reopened->value])
                    ->select('business_unit_id'))
                ->orderBy('name')
                ->first()
                ?? BusinessUnit::query()->whereIn('id', $championUnits)->orderBy('name')->first());

        abort_if($unit === null, 404);
        if (! in_array((int) $unit->id, $championUnits, true)) {
            abort(403);
        }

        $query = KriReturn::query()->where('business_unit_id', $unit->id)->with('period');

        $return = $period !== null
            ? $query->where('period_id', $period)->first()
            : $query->get()->sortByDesc(fn (KriReturn $r) => $r->period?->end_date?->toDateString())->first();

        if ($return === null) {
            $requested = $period !== null ? Period::query()->find($period) : null;

            return Inertia::render('Kri/MyReturn', $presenter->myReturnShell('not_open', $championUnits, $unit, $requested));
        }

        return Inertia::render('Kri/MyReturn', $presenter->myReturn(
            $user,
            $return,
            $championUnits,
            readOnly: false,
            periodClosed: $service->periodClosed($return),
        ));
    }

    public function saveDraft(SaveKriReturnDraftRequest $request, KriReturn $return, KriReturnService $service)
    {
        try {
            $service->saveDraft($return, $request->validated()['lines'], $request->user(), (int) $request->validated()['lock_version']);
        } catch (KriCycleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft saved. Nothing is submitted yet.');
    }

    public function submit(SubmitKriReturnRequest $request, KriReturn $return, KriReturnService $service)
    {
        try {
            $service->submit($return, $request->validated()['lines'], $request->user(), (int) $request->validated()['lock_version']);
        } catch (KriCycleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return submitted.');
    }

    public function reopen(ReopenKriReturnRequest $request, KriReturn $return, KriReturnService $service)
    {
        $validated = $request->validated();

        try {
            $service->reopen($return, $request->user(), (int) $validated['lock_version'], (string) $validated['reason']);
        } catch (KriCycleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return reopened. Submit it again when your changes are made.');
    }
}
