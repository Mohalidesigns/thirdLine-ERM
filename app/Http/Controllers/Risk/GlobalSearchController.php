<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
    /** source_model_type → [required permission, show route, route param source]. */
    private const TYPE_MAP = [
        'risk' => ['risk.view', 'risk.register.show'],
        'control' => ['control.view', 'risk.controls.show'],
        'issue' => ['issue.view', 'risk.issues.show'],
        'loss_event' => ['loss_event.view', 'risk.loss-events.show'],
        'key_risk_indicator' => ['kri.view', 'risk.kri.show'],
        'treatment_plan' => ['treatment.view', 'risk.treatments.show'],
        'control_test' => ['control_test.view', null],
        'risk_assessment' => ['assessment.view', 'risk.assessments.show'],
        'near_miss' => ['loss_event.view', null],
        'entity' => ['hq.view', null],
        'business_unit' => ['hq.view', null],
        'business_process' => ['hq.view', null],
    ];

    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        return view('search.index', [
            'term' => $term,
            'results' => $term === '' ? collect() : $this->search($request->user(), $term, 50),
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

        $query->where(function (Builder $q) use ($allowed) {
            $q->whereIn('source_model_type', $allowed);

            // Graph-native objects (no typed source yet — e.g. Opportunity
            // rows) are visible to anyone who can see HQ pages.
            if (auth()->user()?->can('hq.view')) {
                $q->orWhereNull('source_model_type');
            }
        });
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

    private function urlFor(GraphObject $object, bool $isNode): ?string
    {
        if ($isNode) {
            return route('hq.show', $object->id);
        }

        $entry = self::TYPE_MAP[$object->source_model_type] ?? null;

        if ($entry !== null && $entry[1] !== null && $object->source_model_id !== null) {
            try {
                return route($entry[1], $object->source_model_id);
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Governance objects without a dedicated screen land on their node's
        // HQ page, which at least shows them in context.
        return $object->node_id === null ? null : route('hq.show', $object->node_id);
    }
}
