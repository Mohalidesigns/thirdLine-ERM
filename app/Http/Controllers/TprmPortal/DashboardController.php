<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Models\Tprm\PortalUser;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The vendor's landing page — FR-PRT-02.
 *
 * A placeholder in this slice: the guard is what Phase 8 has to get right
 * first, and a dashboard reading half-built services would be a worse test of
 * it than an empty one. Filled in with open requests, expiring documents, open
 * findings and trust-profile completeness in the next slice.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var PortalUser $user */
        $user = $request->user('tprm-portal');

        return Inertia::render('TprmPortal/Dashboard', [
            'vendor' => $user->thirdParty?->legal_name,
        ]);
    }
}
