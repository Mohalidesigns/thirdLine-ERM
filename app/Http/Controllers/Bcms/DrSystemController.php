<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\RecordBcmsDrBackupAttestationRequest;
use App\Http\Requests\Bcms\StoreBcmsDrSystemRequest;
use App\Models\Bcms\DrSystem;
use App\Presenters\Bcms\DrPresenter;
use App\Services\Bcms\Dr\DrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** `docs/bcms/screens/dr-system-register.md` — the IT DR register. */
class DrSystemController extends Controller
{
    public function __construct(private DrService $dr, private DrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.dr.view');

        return Inertia::render('Bcms/Dr/Systems/Index', $this->presenter->register(
            $request->string('search')->toString() ?: null,
            $request->boolean('mismatches_only'),
            $request->boolean('overdue_only'),
        ));
    }

    public function store(StoreBcmsDrSystemRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $request->user()->organization_id;
        $data['created_by'] = $request->user()->getKey();

        $system = DrSystem::query()->create($data);
        $system->update(['next_test_due' => $this->dr->deriveNextTestDue($system, now())]);

        return back()->with('success', "DR system {$system->name} added.");
    }

    public function update(StoreBcmsDrSystemRequest $request, DrSystem $system): RedirectResponse
    {
        $data = $request->validated();
        $data['updated_by'] = $request->user()->getKey();

        $system->update($data);
        $system->update(['next_test_due' => $this->dr->deriveNextTestDue($system, $system->last_test_date ?? now())]);

        return back()->with('success', "DR system {$system->name} updated.");
    }

    public function backupAttestation(RecordBcmsDrBackupAttestationRequest $request, DrSystem $system): RedirectResponse
    {
        try {
            $this->dr->backupAttestation($system, $request->user(), $request->validated('statement'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Backup attestation recorded.');
    }
}
