<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObjectAttribute;
use App\Models\ObjectLifecycle;
use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use App\Models\ScoringProfile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The configuration builder landing page.
 *
 * WP-05 gave each Livewire builder a route, a permission and a layout from
 * here; migration Phase 6.3 moved those screens to their own controllers, so
 * what is left is the landing page itself: what is configured, what it
 * affects, and the warning that it affects every record in the organisation.
 *
 * The cards are built here rather than in the page because each one is gated
 * on a different permission — `admin.metadata`, `admin.scoring` and
 * `admin.configuration` are separately grantable, which is the whole reason
 * the routes are in three middleware groups.
 */
class ConfigurationBuilderController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Builder/Index', [
            'cards' => collect($this->cards())
                ->filter(fn (array $card) => Gate::allows($card['permission']))
                ->values(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(): array
    {
        return [
            [
                'href' => route('admin.builder.object-types'),
                'permission' => 'admin.metadata',
                'icon' => 'category',
                'title' => 'Object types',
                'count' => ObjectType::count(),
                'noun' => 'types',
                'blurb' => 'The kinds of thing that exist: risks, controls, third parties, projects. Add your own, or inherit from an existing one.',
            ],
            [
                'href' => route('admin.builder.object-types'),
                'permission' => 'admin.metadata',
                'icon' => 'view_list',
                'title' => 'Fields',
                'count' => ObjectAttribute::whereIn('object_type_id', ObjectType::query()->select('id'))->count(),
                'noun' => 'fields configured',
                'blurb' => 'What each type records. Every data type, validation rules, conditional visibility and calculated fields.',
            ],
            [
                'href' => route('admin.builder.relationship-types'),
                'permission' => 'admin.metadata',
                'icon' => 'account_tree',
                'title' => 'Relationship types',
                'count' => ObjectRelationshipType::count(),
                'noun' => 'edge types',
                'blurb' => 'How things connect, and with what weight — which is what makes roll-up across the graph possible.',
            ],
            [
                'href' => route('admin.builder.lifecycles'),
                'permission' => 'admin.metadata',
                'icon' => 'linear_scale',
                'title' => 'Lifecycles',
                'count' => ObjectLifecycle::count(),
                'noun' => 'state machines',
                'blurb' => 'The states a record moves through, the transitions between them, and the permission each transition needs.',
            ],
            [
                'href' => route('admin.builder.scoring-profiles'),
                'permission' => 'admin.scoring',
                'icon' => 'grid_on',
                'title' => 'Scoring profiles',
                'count' => ScoringProfile::count(),
                'noun' => 'profiles',
                'blurb' => 'What a score means: matrix size, scale definitions, impact dimensions, rating bands and the residual formula.',
            ],
            [
                'href' => route('admin.configuration'),
                'permission' => 'admin.configuration',
                'icon' => 'inventory_2',
                'title' => 'Configuration bundles',
                'count' => null,
                'noun' => '',
                'blurb' => 'Export this configuration, diff it against another environment, and import it with a dry run first.',
            ],
        ];
    }
}
