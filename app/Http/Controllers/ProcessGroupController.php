<?php

namespace App\Http\Controllers;

use App\Http\Requests\Process\StoreProcessGroupRequest;
use App\Http\Requests\Process\UpdateProcessGroupRequest;
use App\Models\ProcessGroup;
use App\Presenters\ProcessPresenter;
use App\Services\ProcessGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Process Groups Admin controller (ADR 0028 Phase 2).
 *
 * Thin controller (Standard §1): authorises via ProcessGroupPolicy, delegates
 * domain logic to ProcessGroupService and view shaping to ProcessPresenter.
 */
class ProcessGroupController extends Controller
{
    public function __construct(
        private readonly ProcessGroupService $service,
        private readonly ProcessPresenter $presenter,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', ProcessGroup::class);

        return Inertia::render(
            'ProcessGroups/Index',
            $this->presenter->groupsIndex($request, $request->user())
        );
    }

    public function store(StoreProcessGroupRequest $request): RedirectResponse
    {
        $group = $this->service->create($request->validated(), $request->user());

        return redirect()
            ->route('risk.process-groups.index')
            ->with('success', "Process group {$group->code} — {$group->name} saved.");
    }

    public function update(UpdateProcessGroupRequest $request, ProcessGroup $processGroup): RedirectResponse
    {
        $updated = $this->service->update($processGroup, $request->validated(), $request->user());

        return redirect()
            ->route('risk.process-groups.index')
            ->with('success', "Process group {$updated->code} — {$updated->name} saved.");
    }

    public function deactivate(Request $request, ProcessGroup $processGroup): RedirectResponse
    {
        Gate::authorize('update', $processGroup);

        $this->service->deactivate($processGroup, $request->user());

        return redirect()
            ->route('risk.process-groups.index')
            ->with('success', "Process group {$processGroup->code} has been deactivated.");
    }

    public function toggleActive(Request $request, ProcessGroup $processGroup): RedirectResponse
    {
        Gate::authorize('update', $processGroup);

        if ($processGroup->isActive()) {
            $this->service->deactivate($processGroup, $request->user());
            $message = "Process group {$processGroup->code} has been deactivated.";
        } else {
            $this->service->reactivate($processGroup, $request->user());
            $message = "Process group {$processGroup->code} has been reactivated.";
        }

        return redirect()
            ->route('risk.process-groups.index')
            ->with('success', $message);
    }
}
