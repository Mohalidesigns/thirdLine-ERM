<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RelatedObjectsRequest;
use App\Models\GraphObject;
use App\Services\Graph\GraphQueryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 2 — traversal, which a flat resource list cannot express.
 *
 * "Every risk under this business unit, including its sub-units" is one
 * question. Answered through /risks it is a client walking the org tree itself,
 * one request per node, and getting the node-scoped authorization wrong on the
 * way. GraphQueryService already answers it correctly and applies the caller's
 * visibility; this exposes that rather than a second implementation.
 */
class GraphController extends Controller
{
    public function __construct(private GraphQueryService $graph) {}

    /**
     * Everything below a node, optionally narrowed to certain object types.
     */
    public function descendants(Request $request, GraphObject $object): JsonResponse
    {
        $this->assertSameTenant($object);

        $types = array_filter(explode(',', (string) $request->input('types', '')));
        $depth = $request->filled('depth') ? max(1, (int) $request->input('depth')) : null;

        return $this->collection(
            $this->graph->descendants($object->id, $types, $depth, $request->user()),
            ['from' => $object->id, 'types' => $types, 'depth' => $depth],
        );
    }

    /** The chain from a node up to the root. */
    public function ancestors(Request $request, GraphObject $object): JsonResponse
    {
        $this->assertSameTenant($object);

        return $this->collection(
            $this->graph->ancestors($object->id, $request->user()),
            ['from' => $object->id],
        );
    }

    /**
     * Objects reached by following a typed relationship — 'mitigates',
     * 'assures', 'depends_on'.
     */
    public function related(RelatedObjectsRequest $request, GraphObject $object): JsonResponse
    {
        $this->assertSameTenant($object);

        $validated = $request->validated();

        return $this->collection(
            $this->graph->related(
                $object->id,
                $validated['relationship'],
                $validated['direction'] ?? 'out',
                (int) ($validated['depth'] ?? 1),
                $request->user(),
            ),
            [
                'from' => $object->id,
                'relationship' => $validated['relationship'],
                'direction' => $validated['direction'] ?? 'out',
                'depth' => (int) ($validated['depth'] ?? 1),
            ],
        );
    }

    /* ------------------------------------------------------------------ */

    /** @param Collection<int, GraphObject> $objects */
    private function collection(Collection $objects, array $meta): JsonResponse
    {
        return response()->json([
            'data' => $objects->map(fn (GraphObject $object) => [
                'type' => 'objects',
                'id' => (string) $object->id,
                'attributes' => [
                    'object_type_id' => $object->object_type_id,
                    'code' => $object->code,
                    'name' => $object->name,
                    'lifecycle_state' => $object->lifecycle_state,
                    'status' => $object->status,
                    'owner_id' => $object->owner_id,
                    'parent_id' => $object->parent_id,
                    'hierarchy_path' => $object->hierarchy_path,
                    'hierarchy_depth' => $object->hierarchy_depth,
                    'source_model_type' => $object->source_model_type,
                    'source_model_id' => $object->source_model_id,
                ],
            ])->values()->all(),
            'meta' => $meta + ['count' => $objects->count()],
        ]);
    }

    private function assertSameTenant(GraphObject $object): void
    {
        abort_unless($object->organization_id === TenantContext::organizationId(), 404, 'Not found.');
    }
}
