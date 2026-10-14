<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/risk/rcsa` on its own — the URL a person types, or trims a deeper one back
 * to (RCSA-14: it was a 404 because every RCSA route sits one segment lower).
 *
 * IT RENDERS NOTHING AND READS NOTHING. It picks the first front door the user
 * can actually OPEN and redirects to it:
 *
 *   1. `rcsa_v2` on AND `rcsa_assessment.view`  ->  rcsa.dashboard.index
 *   2. `rcsa.view` (the legacy permission)       ->  risk.rcsa.dashboard
 *   3. otherwise                                 ->  403
 *
 * CHOOSING BY THE FLAG ALONE WAS THE DEFECT. A user holding only the legacy
 * `rcsa.view` with the flag on was sent to the v2 dashboard and met a 403, and
 * a user holding only `rcsa_assessment.view` with the flag off was sent to the
 * legacy one and met the same. The route's own middleware lets either
 * permission through (it has to answer in both states), so the choice of target
 * has to be made against what the user holds.
 *
 * The flag off NEVER targets v2: the v2 routes are not registered behind it,
 * and the module being replaced stays live and untouched until cutover.
 */
class ModuleRootController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        $v2On = filter_var(config('features.rcsa_v2', false), FILTER_VALIDATE_BOOLEAN);

        if ($v2On && $user->can('rcsa_assessment.view')) {
            return redirect()->route('rcsa.dashboard.index');
        }

        if ($user->can('rcsa.view')) {
            return redirect()->route('risk.rcsa.dashboard');
        }

        abort(403);
    }
}
