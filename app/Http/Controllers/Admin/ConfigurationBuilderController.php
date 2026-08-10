<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObjectType;

/**
 * WP-05 — page shells for the configuration builder.
 *
 * The screens themselves are Livewire components; these actions exist to give
 * each one a route, a permission and a layout. Keeping the shells thin is
 * deliberate: every one of these routes is guarded, and RouteAuthorizationTest
 * fails the build if one is not.
 */
class ConfigurationBuilderController extends Controller
{
    /** The builder landing page: what is configured and what it affects. */
    public function index()
    {
        return view('admin.builder.index', [
            'typeCount' => ObjectType::count(),
            'attributeCount' => \App\Models\ObjectAttribute::whereIn(
                'object_type_id',
                ObjectType::query()->select('id')
            )->count(),
            'relationshipTypeCount' => \App\Models\ObjectRelationshipType::count(),
            'lifecycleCount' => \App\Models\ObjectLifecycle::count(),
            'scoringProfileCount' => \App\Models\ScoringProfile::count(),
        ]);
    }

    public function objectTypes()
    {
        return view('admin.builder.object-types');
    }

    /** The attribute editor for one type. */
    public function attributes(ObjectType $objectType)
    {
        return view('admin.builder.attributes', ['objectType' => $objectType]);
    }

    public function relationshipTypes()
    {
        return view('admin.builder.relationship-types');
    }

    public function lifecycles()
    {
        return view('admin.builder.lifecycles');
    }

    public function scoringProfiles()
    {
        return view('admin.builder.scoring-profiles');
    }
}
