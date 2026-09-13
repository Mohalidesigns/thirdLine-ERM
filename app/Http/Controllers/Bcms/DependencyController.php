<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\DependencyType;
use App\Http\Controllers\Controller;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Process;
use App\Services\Bcms\Bia\DependencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The dependency explorer, the SPOF register and the reverse-impact view.
 *
 * THE GRAPH IS BUILT SERVER-SIDE AND SENT AS NODES AND EDGES. The page draws it;
 * it does not decide what is in it. Otherwise the same question — "which
 * dependencies are shared" — gets a different answer on screen from the one the
 * SPOF register gives, and the two would drift the first time either changed.
 */
class DependencyController extends Controller
{
    public function __construct(private DependencyService $dependencies) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.bia.view');

        $filters = $request->only(['type', 'criticality', 'spof_only']);

        return Inertia::render('Bcms/Bia/Dependencies', [
            'graph' => $this->graph($request->user(), $filters),
            'spof_register' => $this->dependencies->spofRegister(),
            'shared' => $this->dependencies->sharedDependencies(),
            'filters' => $filters,
            'types' => array_map(fn (DependencyType $t) => [
                'value' => $t->value, 'label' => $t->label(), 'owned_by_bcms' => $t->isOwnedByBcms(),
            ], DependencyType::cases()),
        ]);
    }

    /**
     * "If this fails, what stops?" — the reverse view.
     *
     * The route takes a morph key and an id rather than a model, because the
     * seven types live in four different modules and a route-model binding
     * would need seven routes.
     */
    public function impactOf(Request $request, string $type, int $id): Response
    {
        Gate::authorize('bcms.bia.view');

        $dependencyType = DependencyType::tryFrom($type);

        if ($dependencyType === null) {
            throw new NotFoundHttpException;
        }

        $target = $dependencyType->modelClass()::query()->find($id);

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Bcms/Bia/ReverseImpact', [
            'impact' => $this->dependencies->impactOf($target),
        ]);
    }

    /**
     * Nodes and edges for the explorer.
     *
     * @param  array<string, mixed>  $filters
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function graph(?\App\Models\User $user, array $filters): array
    {
        $processes = Process::query()->visibleTo($user)->where('status', 'active')->get(['id', 'code', 'name', 'criticality_tier', 'is_critical_service']);

        $edges = Dependency::query()
            ->whereIn('assessment_id', BiaAssessment::query()->whereIn('process_id', $processes->pluck('id'))->select('id'))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('dependable_type', $v))
            ->when($filters['criticality'] ?? null, fn ($q, $v) => $q->where('criticality', $v))
            ->when(($filters['spof_only'] ?? null) === 'yes', fn ($q) => $q->where('single_point_of_failure', true))
            ->with('assessment:id,process_id')
            ->get();

        $nodes = [];

        foreach ($processes as $process) {
            $nodes['bcms_process:'.$process->id] = [
                'id' => 'bcms_process:'.$process->id,
                'kind' => 'process',
                'label' => $process->name,
                'code' => $process->code,
                'tier' => $process->criticality_tier,
                'critical_service' => (bool) $process->is_critical_service,
            ];
        }

        $links = [];

        foreach ($edges as $edge) {
            $processId = $edge->assessment?->process_id;

            if ($processId === null || ! isset($nodes['bcms_process:'.$processId])) {
                continue;
            }

            $targetId = $edge->dependable_type.':'.$edge->dependable_id;

            $nodes[$targetId] ??= [
                'id' => $targetId,
                'kind' => 'dependency',
                'type' => $edge->dependable_type,
                'type_label' => $edge->type()?->label(),
                'label' => $edge->dependableLabel(),
                'dependent_count' => 0,
                'spof' => false,
            ];

            $nodes[$targetId]['dependent_count']++;
            $nodes[$targetId]['spof'] = $nodes[$targetId]['spof'] || (bool) $edge->single_point_of_failure;

            $links[] = [
                'source' => 'bcms_process:'.$processId,
                'target' => $targetId,
                'relation' => $edge->dependency_type,
                'criticality' => $edge->criticality,
                'spof' => (bool) $edge->single_point_of_failure,
            ];
        }

        return ['nodes' => array_values($nodes), 'edges' => $links];
    }
}
