<?php

namespace App\Services\Mcp;

use App\Http\Api\ApiResourceRegistry;
use App\Models\ApiToken;
use App\Models\GraphObject;
use App\Models\Measure;
use App\Models\ObjectType;
use App\Models\Period;
use App\Models\WorkflowTask;
use App\Services\Graph\GraphQueryService;
use App\Services\MeasureService;
use App\Services\Workflow\TaskQueryService;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 5 — the tools an AI agent may call against this platform.
 *
 * THREE RULES, AND THEY ARE THE WHOLE DESIGN.
 *
 * 1. READ-ONLY. Every tool here reads. An agent that can write to a risk
 *    register can fabricate a control test result, and no audit trail
 *    distinguishes that from a real one after the fact. Governed writes go
 *    through the workflow engine and the approval scope, not through here.
 *
 * 2. THE CALLER'S PERMISSIONS, NOT THE AGENT'S. Every tool runs as the identity
 *    behind the token — same scopes, same tenancy, same node-scoped visibility
 *    as if that person had opened the screen. An MCP server that reads with
 *    elevated rights is a permission system with a hole in the shape of a
 *    chatbot.
 *
 * 3. CITATIONS ALWAYS. Every result carries the object ids it came from, so an
 *    answer can be checked against the register rather than believed. On a
 *    platform whose WP-02 removed twelve fabricated figures, an AI surface that
 *    returns numbers without provenance would put them straight back.
 */
class McpToolRegistry
{
    public function __construct(
        private GraphQueryService $graph,
        private MeasureService $measures,
        private TaskQueryService $tasks,
    ) {}

    /**
     * The tool manifest, in MCP's shape.
     *
     * @return list<array<string, mixed>>
     */
    public function manifest(): array
    {
        return [
            [
                'name' => 'list_object_types',
                'description' => 'List the kinds of governed object this organization holds — risks, controls, '
                    .'business units, and any type its administrators have defined.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'description' => 'org_node, governance, assessment or reference'],
                    ],
                ],
            ],
            [
                'name' => 'query_objects',
                'description' => 'Search the register. Returns matching records with their ids, so every '
                    .'statement made from them can be cited.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'A resource name from list_object_types, e.g. risks'],
                        'filters' => ['type' => 'object', 'description' => 'field => value, restricted to the filterable fields of that type'],
                        'search' => ['type' => 'string', 'description' => 'Free text matched against the title'],
                        'limit' => ['type' => 'integer', 'description' => 'Up to 100; defaults to 25'],
                    ],
                    'required' => ['type'],
                ],
            ],
            [
                'name' => 'get_object',
                'description' => 'Fetch one record in full.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'id' => ['type' => 'integer'],
                    ],
                    'required' => ['type', 'id'],
                ],
            ],
            [
                'name' => 'traverse_graph',
                'description' => 'Walk the object graph: everything under a business unit, or everything a '
                    .'control mitigates. Answers questions a flat search cannot.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'object_id' => ['type' => 'integer'],
                        'direction' => ['type' => 'string', 'description' => 'descendants, ancestors or related'],
                        'relationship' => ['type' => 'string', 'description' => 'For direction=related, e.g. mitigates'],
                        'depth' => ['type' => 'integer'],
                    ],
                    'required' => ['object_id'],
                ],
            ],
            [
                'name' => 'get_measure_series',
                'description' => 'A measure over time for one object — a KRI trend, a risk score history. '
                    .'Periods with no reading come back as null rather than being omitted, so a gap is visible.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'measure_code' => ['type' => 'string'],
                        'object_id' => ['type' => 'integer'],
                        'periods' => ['type' => 'integer', 'description' => 'How many recent periods; defaults to 12'],
                        'scenario' => ['type' => 'string', 'description' => 'actual, target, budget, forecast…'],
                    ],
                    'required' => ['measure_code', 'object_id'],
                ],
            ],
            [
                'name' => 'list_my_tasks',
                'description' => 'The open decisions waiting on the calling user, with due dates.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'overdue_only' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Execute a tool as the identity behind the token.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments, ApiToken $token): array
    {
        return match ($tool) {
            'list_object_types' => $this->listObjectTypes($arguments, $token),
            'query_objects' => $this->queryObjects($arguments, $token),
            'get_object' => $this->getObject($arguments, $token),
            'traverse_graph' => $this->traverseGraph($arguments, $token),
            'get_measure_series' => $this->getMeasureSeries($arguments, $token),
            'list_my_tasks' => $this->listMyTasks($arguments, $token),
            default => throw new RuntimeException("There is no [{$tool}] tool. Call tools/list for the manifest."),
        };
    }

    /* ================================================================== */

    private function listObjectTypes(array $args, ApiToken $token): array
    {
        $this->authorize($token, 'risk.view');

        $types = ObjectType::query()
            ->when($args['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'plural_name', 'category', 'is_node_type']);

        // The API resource names too, because those are what query_objects
        // takes — an agent given only graph type codes would guess wrong.
        return [
            'queryable_types' => collect(ApiResourceRegistry::all())
                ->filter(fn (array $d) => $token->permits($d['permissions']['view']))
                ->keys()
                ->values()
                ->all(),
            'graph_types' => $types->toArray(),
            'citations' => $types->pluck('id')->all(),
        ];
    }

    private function queryObjects(array $args, ApiToken $token): array
    {
        [$name, $definition] = $this->resource($args['type'] ?? '', $token);

        $query = $definition['model']::query();

        foreach ((array) ($args['filters'] ?? []) as $field => $value) {
            // The same allowlist the REST API uses. Without it an agent can be
            // talked into filtering on a column nobody published.
            if (in_array($field, (array) ($definition['filters'] ?? []), true)) {
                is_array($value) ? $query->whereIn($field, $value) : $query->where($field, $value);
            }
        }

        if (! blank($args['search'] ?? null)) {
            $term = '%'.addcslashes((string) $args['search'], '%_\\').'%';

            $query->where(function ($q) use ($term, $definition) {
                foreach (array_intersect(['title', 'name', 'description'], (array) $definition['fields']) as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        $limit = min(100, max(1, (int) ($args['limit'] ?? 25)));
        $records = $query->limit($limit)->get();

        return [
            'type' => $name,
            'count' => $records->count(),
            'truncated' => $records->count() === $limit,
            'records' => $records->map(fn ($model) => collect($model->getAttributes())
                ->only((array) $definition['fields'])
                ->put('id', $model->getKey())
                ->all())->all(),
            // Every answer built from this can name its sources.
            'citations' => $records->pluck('id')->map(fn ($id) => $name.':'.$id)->all(),
        ];
    }

    private function getObject(array $args, ApiToken $token): array
    {
        [$name, $definition] = $this->resource($args['type'] ?? '', $token);

        $model = $definition['model']::query()->find($args['id'] ?? 0);

        if ($model === null) {
            return ['found' => false, 'citations' => []];
        }

        return [
            'found' => true,
            'type' => $name,
            'record' => collect($model->getAttributes())
                ->only((array) $definition['fields'])
                ->put('id', $model->getKey())
                ->all(),
            'citations' => [$name.':'.$model->getKey()],
        ];
    }

    private function traverseGraph(array $args, ApiToken $token): array
    {
        $this->authorize($token, 'risk.view');

        $objectId = (int) ($args['object_id'] ?? 0);
        $object = GraphObject::find($objectId);

        if ($object === null) {
            return ['found' => false, 'objects' => [], 'citations' => []];
        }

        $user = $token->actingUser();
        $direction = $args['direction'] ?? 'descendants';
        $depth = isset($args['depth']) ? max(1, (int) $args['depth']) : null;

        // The user is passed through, so node-scoped visibility applies exactly
        // as it would on screen. An agent must not see a subtree its caller
        // cannot.
        $objects = match ($direction) {
            'ancestors' => $this->graph->ancestors($objectId, $user),
            'related' => $this->graph->related(
                $objectId,
                (string) ($args['relationship'] ?? throw new RuntimeException('direction=related needs a relationship.')),
                'out',
                $depth ?? 1,
                $user,
            ),
            default => $this->graph->descendants($objectId, [], $depth, $user),
        };

        return [
            'found' => true,
            'from' => ['id' => $object->id, 'code' => $object->code, 'name' => $object->name],
            'direction' => $direction,
            'count' => $objects->count(),
            'objects' => $objects->map(fn (GraphObject $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'object_type_id' => $o->object_type_id,
                'lifecycle_state' => $o->lifecycle_state,
            ])->values()->all(),
            'citations' => $objects->pluck('id')->map(fn ($id) => 'objects:'.$id)->all(),
        ];
    }

    private function getMeasureSeries(array $args, ApiToken $token): array
    {
        $this->authorize($token, 'measure.view');

        $measure = Measure::where('code', $args['measure_code'] ?? '')->first();

        if ($measure === null) {
            return ['found' => false, 'points' => [], 'citations' => []];
        }

        $periods = Period::query()
            ->orderByDesc('start_date')
            ->limit(min(60, max(1, (int) ($args['periods'] ?? 12))))
            ->get()
            ->reverse()
            ->values();

        $values = $this->measures->series(
            $measure,
            (int) ($args['object_id'] ?? 0),
            $periods->pluck('id')->all(),
            (string) ($args['scenario'] ?? 'actual'),
            TenantContext::organizationId(),
        );

        return [
            'found' => true,
            'measure' => ['code' => $measure->code, 'name' => $measure->name, 'unit_id' => $measure->unit_id],
            'object_id' => (int) ($args['object_id'] ?? 0),
            // A period with no reading is null, not absent. An agent handed
            // only the periods that have values cannot tell a gap from the end
            // of the series, and will describe a KRI that stopped being
            // collected as one that is stable.
            'points' => $periods->map(fn (Period $p) => [
                'period' => $p->code,
                'start_date' => $p->start_date?->toDateString(),
                'value' => $values[$p->id] ?? null,
            ])->all(),
            'citations' => ['measures:'.$measure->id],
        ];
    }

    private function listMyTasks(array $args, ApiToken $token): array
    {
        $this->authorize($token, 'task.view');

        $user = $token->actingUser();

        if ($user === null) {
            // A machine token has no "my". Saying so is better than returning
            // an empty list, which reads as "you have nothing to do".
            return [
                'error' => 'This is a machine token, which acts as no user, so it has no task list.',
                'tasks' => [],
                'citations' => [],
            ];
        }

        $tasks = $this->tasks->openFor($user, 100)
            ->when($args['overdue_only'] ?? false, fn ($c) => $c->filter->isOverdue());

        return [
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (WorkflowTask $task) => [
                'id' => $task->id,
                'step' => $task->node_name ?? $task->node_code,
                'process' => $task->instance?->definition?->name,
                'about' => [
                    'type' => $task->instance?->entity_type,
                    'id' => $task->instance?->entity_id,
                ],
                'due_at' => $task->due_at?->toIso8601String(),
                'overdue' => $task->isOverdue(),
                'hours_overdue' => $task->hoursOverdue(),
            ])->values()->all(),
            'citations' => $tasks->pluck('id')->map(fn ($id) => 'tasks:'.$id)->all(),
        ];
    }

    /* ================================================================== */

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resource(string $name, ApiToken $token): array
    {
        $definition = ApiResourceRegistry::find($name);

        if ($definition === null) {
            throw new RuntimeException("There is no [{$name}] type. Call list_object_types for what is available.");
        }

        $this->authorize($token, $definition['permissions']['view']);

        return [$name, $definition];
    }

    /**
     * The caller's permission, not the agent's.
     */
    private function authorize(ApiToken $token, string $permission): void
    {
        if (! $token->permits($permission)) {
            throw new RuntimeException(
                "This token may not {$permission}, so that information is not available through it."
            );
        }
    }
}
