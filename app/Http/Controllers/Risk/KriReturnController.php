<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kri\SendKriReminderRequest;
use App\Models\KriReturn;
use App\Presenters\Kri\KriReturnPresenter;
use App\Services\Kri\KriNoticeService;
use App\Services\Kri\KriReturnService;
use App\Support\Kri\KriDepartmentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The returns drill-down, the read-only view of a return, and the manual
 * reminder (ADR 0026 sections 15 and 16, Amendment 1).
 *
 * `show` and `notices` are guarded by `can:view,return`, not `kri.view`: every
 * contact of the department receives a link to the read-only view.
 */
class KriReturnController extends Controller
{
    public function index(Request $request, KriReturnPresenter $returns)
    {
        return Inertia::render('Kri/Returns/Index', $returns->drillDown(
            $request->user(),
            $request->integer('period') ?: null,
            (string) $request->query('filter', 'not_submitted'),
        ));
    }

    public function show(Request $request, KriReturn $return, KriReturnPresenter $returns, KriReturnService $service, KriDepartmentScope $scope)
    {
        $user = $request->user();

        return Inertia::render('Kri/MyReturn', $returns->myReturn(
            $user,
            $return,
            $scope->championUnitIdsFor($user),
            readOnly: true,
            periodClosed: $service->periodClosed($return),
        ));
    }

    /** The full notice history for one return, newest first (A1.5). */
    public function notices(KriReturn $return, KriReturnPresenter $returns): JsonResponse
    {
        return response()->json(['notices' => $returns->allNotices($return)]);
    }

    public function remind(SendKriReminderRequest $request, KriNoticeService $notices)
    {
        $results = $notices->remind($request->validated()['return_ids'], $request->user());

        $queued = collect($results)->where('outcome', 'queued')->count();

        return back()
            ->with($queued > 0 ? 'success' : 'warning', $queued > 0
                ? "{$queued} ".($queued === 1 ? 'reminder' : 'reminders').' queued.'
                : 'No reminder was queued.')
            ->with('reminderResults', $results);
    }
}
