<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Services\Bcms\MyResilienceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Resilience — the employee's own page, `docs/bcms/screens/
 * my-resilience.md`.
 *
 * PERMISSION: `my.view` — the platform's own baseline "see your own
 * responsibilities page" grant, already held by every employee (`RiskPermiss
 * ionCatalog::baseline()`). The screen spec surfaced this as an open question
 * rather than assuming `bcms.myprofile.manage` (which is not in the baseline
 * list); `my.view` is the existing universal grant that reads as intended
 * here, so it is the one this route uses — recorded as the decision, per the
 * task brief, rather than left open a second time.
 */
class MyResilienceController extends Controller
{
    public function __construct(private readonly MyResilienceService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('my.view');

        return Inertia::render('Bcms/MyResilience/Index', $this->service->forUser($request->user()));
    }
}
