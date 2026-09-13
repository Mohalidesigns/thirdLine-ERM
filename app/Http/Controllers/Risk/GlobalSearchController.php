<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * WP-08 TASK 6 — global search over the object graph.
 *
 * One index to search because WP-03 built one: every risk, control, issue,
 * loss event, KRI and org node is a row in `objects` with name, code,
 * description and the attribute bag. Searching the graph therefore searches
 * the whole platform, and jump-to resolves back to the typed screen through
 * source_model_type.
 *
 * PERMISSION-CORRECTNESS is two filters, both applied in SQL:
 *   1. module permission — a viewer without loss_event.view does not get
 *      loss events in the dropdown (titles leak: "Fraud loss — Lagos branch,
 *      ₦2.1bn" IS the incident);
 *   2. node scope — a subtree-pinned viewer searches their subtree.
 * Tenancy is the model's global scope, as everywhere.
 */
class GlobalSearchController extends Controller
{
    /**
     * source_model_type → [required permission, show route].
     *
     * A null route means the object has no screen anywhere in the product, and
     * search() drops those results — the module's standing rule is that a
     * result links somewhere real.
     *
     * These entries changed when Business HQ was retired (see the RETIRED
     * SURFACES note in routes/web.php). Anything without a typed screen used to
     * fall back to the node's HQ page:
     *   - entity and control_test turned out to have real screens of their own
     *     all along, so they are wired to those and are now better targets than
     *     the generic node page ever was;
     *   - business_unit and business_process have no screen at all, so they
     *     leave the map entirely rather than being queried and then discarded;
     *   - near_miss keeps a null route: it is reachable only by conversion.
     */
    private const TYPE_MAP = [
        'risk' => ['risk.view', 'risk.register.show'],
        'control' => ['control.view', 'risk.controls.show'],
        'issue' => ['issue.view', 'risk.issues.show'],
        'loss_event' => ['loss_event.view', 'risk.loss-events.show'],
        'key_risk_indicator' => ['kri.view', 'risk.kri.show'],
        'treatment_plan' => ['treatment.view', 'risk.treatments.show'],
        'control_test' => ['control_test.view', 'risk.control-tests.show'],
        'risk_assessment' => ['assessment.view', 'risk.assessments.show'],
        'near_miss' => ['loss_event.view', null],
        'entity' => ['entity.view', 'risk.scoping.show'],
    ];

    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        return Inertia::render('Search/Index', [
            'term' => $term,
            'results' => $term === '' ? [] : $this->search($request->user(), $term, 50)->values()->all(),
        ]);
    }

    public function suggest(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        return response()->json([
            'results' => $this->search($request->user(), $term, 8)->values(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function search(User $user, string $term, int $limit)
    {
        $query = GraphObject::query()
            ->where(function (Builder $q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    // The configured-attribute bag is JSON text; a LIKE over
                    // it finds values without needing per-key indexes.
                    ->orWhere('attributes', 'like', $like);
            });

        $this->applyPermissionFilter($query, $user);
        $this->applyNodeScope($query, $user);

        $types = ObjectType::query()->get(['id', 'name', 'icon', 'is_node_type'])->keyBy('id');

        return $query
            ->orderByRaw('case when name like ? then 0 else 1 end', [$term.'%'])
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'description', 'object_type_id', 'source_model_type', 'source_model_id'])
            ->map(function (GraphObject $object) use ($types) {
                $type = $types->get($object->object_type_id);

                return [
                    'id' => (int) $object->id,
                    'name' => $object->name,
                    'code' => $object->code,
                    'type' => $type?->name,
                    'icon' => $type?->icon,
                    'description' => str($object->description ?? '')->limit(120)->toString(),
                    'url' => $this->urlFor($object, (bool) ($type?->is_node_type)),
                ];
            })
            ->filter(fn (array $result) => $result['url'] !== null)
            ->values();
    }

    /** Only object kinds whose module the viewer can see, in one WHERE IN. */
    private function applyPermissionFilter(Builder $query, User $user): void
    {
        $allowed = collect(self::TYPE_MAP)
            ->filter(fn (array $entry) => $user->can($entry[0]))
            ->keys()
            ->all();

        // Graph-native objects with no typed source used to be included here
        // for hq.view holders, because Business HQ could render them. With that
        // surface retired they have no screen, so search() would discard them
        // anyway — they are no longer queried for.
        $query->whereIn('source_model_type', $allowed);
    }

    /** The same subtree pin GraphQueryService enforces, expressed inline. */
    private function applyNodeScope(Builder $query, User $user): void
    {
        if (! GraphScope::isSubtreeLimited($user)) {
            return;
        }

        $rootId = GraphObject::query()->toBase()
            ->where('source_model_type', 'entity')
            ->where('source_model_id', $user->scope_entity_id)
            ->whereNull('deleted_at')
            ->value('id');

        if ($rootId === null) {
            $query->whereRaw('1 = 0'); // fail closed, like GraphScope

            return;
        }

        $rootPath = GraphObject::query()->toBase()->where('id', $rootId)->value('hierarchy_path')
            ?: '/'.$rootId.'/';

        $query->where(function (Builder $q) use ($rootPath, $rootId) {
            $q->where('hierarchy_path', 'like', $rootPath.'%')
                ->orWhereIn('node_id', GraphObject::query()->toBase()
                    ->select('id')
                    ->where('hierarchy_path', 'like', $rootPath.'%'))
                ->orWhere('id', $rootId);
        });
    }

    /**
     * Where a result jumps to, or null when nothing in the product shows it —
     * in which case search() drops the result rather than offering a dead link.
     *
     * Node-type objects go to the node's Business HQ page. While that surface
     * was retired this returned null and every org-structure hit rendered
     * unlinked; WP-12 brings it back. Entities keep their own screen through
     * TYPE_MAP — a bank's legal-entity page is a better destination for an
     * entity than a generic node dashboard, and that mapping is unchanged.
     */
    private function urlFor(GraphObject $object, bool $isNode): ?string
    {
        $entry = self::TYPE_MAP[$object->source_model_type] ?? null;

        if ($isNode && $entry === null) {
            // Every node object has an HQ page, so this is always a live link.
            // Wrapped anyway: an object row whose id has since been deleted
            // should drop out of the results, not 500 the search box.
            try {
                return route('hq.show', $object->id);
            } catch (\Throwable $e) {
                return null;
            }
        }

        if ($entry !== null && $entry[1] !== null && $object->source_model_id !== null) {
            try {
                return route($entry[1], $object->source_model_id);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
