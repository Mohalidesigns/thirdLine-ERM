<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Services\MyResponsibilitiesService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WP-08 TASK 5 — /my, the personal work queue.
 *
 * THE ADOPTION SURFACE. Risk managers open registers; everyone else opens
 * "what do I owe". This page is computed entirely from data that already
 * exists (workflow tasks, ownership columns, breach rows, due dates) and is
 * the default landing page for first-line roles — see the login redirect.
 *
 * The first page rendered through Inertia (migration Phase 0). The props are
 * exactly what MyResponsibilitiesService produces; the React page adds
 * labels and icons, nothing else.
 */
class MyResponsibilitiesController extends Controller
{
    public function index(Request $request, MyResponsibilitiesService $responsibilities): Response
    {
        return Inertia::render('My/Index', [
            'queue' => $responsibilities->for($request->user()),
        ]);
    }
}
