<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Presenters\Bcms\BcmsHomePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BCMS home — the resilience posture screen (Blueprint §15, screen 1).
 *
 * PHASE 0 SHOWS WHAT EXISTS AND SAYS SO ABOUT WHAT DOES NOT. The blueprint's
 * home screen wants a maturity score, plans-current percentage, exercises
 * completed against planned and the next thirty days of the calendar. Three of
 * those four have no data in Phase 0, and the honest thing on the screen is a
 * sentence, not a zero: a rate over nothing is undefined, not zero
 * (development standard §5), and a green "100% of plans current" over an empty
 * plan register is the exact class of defect `NoFabricatedNumbersTest` exists
 * to catch.
 */
class HomeController extends Controller
{
    public function index(Request $request, BcmsHomePresenter $presenter): Response
    {
        Gate::authorize('bcms.view');

        return Inertia::render('Bcms/Home', $presenter->present($request->user()));
    }
}
