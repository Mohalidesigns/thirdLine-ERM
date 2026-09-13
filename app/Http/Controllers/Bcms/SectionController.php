<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Support\Bcms\ModuleSections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Phase 0 shell for the twelve sub-modules of Blueprint §4.1.
 *
 * ONE CONTROLLER, TWELVE ROUTES, ONE REGISTRY. The sections come from
 * {@see ModuleSections}, which the routes and the navigation are also built
 * from, so a menu item cannot point at a route nobody registered.
 *
 * EACH SCREEN SAYS WHAT LANDS AND WHEN. A blank page is indistinguishable from
 * a broken one; a page that says "the BIA engine arrives in Phase 2, and here
 * is what it will do" is honest and is also the demo script. Each of these
 * routes is replaced by its phase's real controller — the URL and the permission
 * are the contract, and they are settled now so no later phase invents its own.
 *
 * THE PERMISSION IS PER SECTION, NOT A BLANKET `bcms.view`. A user who can see
 * the calendar but not the contact roster gets the calendar and no menu entry
 * for the roster, which is the behaviour the whole permission split exists for
 * and is far easier to get right now than to retrofit.
 */
class SectionController extends Controller
{
    public function show(Request $request, string $section): Response
    {
        $definition = ModuleSections::find($section);

        if ($definition === null) {
            throw new NotFoundHttpException;
        }

        Gate::authorize($definition['permission']);

        return Inertia::render('Bcms/Section', [
            'section' => $definition,
            'sections' => array_values(array_filter(
                ModuleSections::all(),
                fn (array $s) => $request->user()?->can($s['permission']) === true
            )),
        ]);
    }
}
