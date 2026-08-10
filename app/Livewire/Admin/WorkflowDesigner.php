<?php

namespace App\Livewire\Admin;

use App\Enums\WorkflowNodeType;
use App\Models\ObjectType;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\Workflow\SubjectRegistry;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Services\Workflow\WorkflowPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * WP-06 TASK 3 — the visual designer.
 *
 * Nodes are dragged on a canvas; edges are drawn by picking a source and a
 * target. Position is stored on the node (x, y) so a process looks the same to
 * the next person who opens it — a layout that reshuffles on every load is a
 * diagram nobody trusts and everybody redraws.
 *
 * VALIDATION IS ON PUBLISH, NOT ON SAVE. A half-drawn process is a normal thing
 * to have saved. What must never happen is a half-drawn process being the one
 * new instances start on, so publish runs the full check and reports every
 * error at once rather than one per attempt.
 */
class WorkflowDesigner extends Component
{
    public ?int $definitionId = null;

    /* ---- definition header ---- */
    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $entityType = '';

    public ?int $objectTypeId = null;

    public string $trigger = 'manual';

    /* ---- graph ---- */

    /** @var list<array<string, mixed>> */
    public array $nodes = [];

    /** @var list<array<string, mixed>> */
    public array $edges = [];

    /** @var list<array<string, mixed>> */
    public array $escalationRules = [];

    /* ---- ui ---- */
    public ?string $selectedNode = null;

    public ?int $selectedEdge = null;

    public string $edgeFrom = '';

    public string $edgeTo = '';

    /** @var list<string> */
    public array $publishErrors = [];

    public bool $dirty = false;

    public function mount(?int $definitionId = null): void
    {
        $this->definitionId = $definitionId;

        if ($definitionId !== null) {
            $this->loadDefinition($definitionId);

            return;
        }

        // A blank canvas is not a useful starting point for a process: every
        // approval begins somewhere and ends two ways, so the designer opens
        // with that skeleton already drawn.
        $this->nodes = [
            ['code' => 'start', 'type' => 'start', 'name' => 'Submitted', 'x' => 40, 'y' => 120],
            [
                'code' => 'review', 'type' => 'approval', 'name' => 'Review',
                'assignee_rule' => 'role', 'assignee_config' => ['roles' => []],
                'sla_hours' => 72, 'on_timeout' => 'escalate',
                'allow_delegate' => true, 'allow_return' => true,
                'x' => 280, 'y' => 120,
            ],
            ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved', 'x' => 540, 'y' => 60],
            ['code' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'outcome' => 'rejected', 'x' => 540, 'y' => 200],
        ];

        $this->edges = [
            ['from' => 'start', 'to' => 'review'],
            ['from' => 'review', 'to' => 'rejected', 'when' => "outcome == 'reject'", 'label' => 'Rejected'],
            ['from' => 'review', 'to' => 'approved', 'label' => 'Approved'],
        ];
    }

    public function render()
    {
        return view('livewire.admin.workflow-designer', [
            'nodeTypes' => WorkflowNodeType::cases(),
            'roles' => Role::orderBy('name')->pluck('name')->all(),
            'users' => User::where('organization_id', TenantContext::organizationId())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'subjectTypes' => app(SubjectRegistry::class)->boundTypes(),
            'objectTypes' => ObjectType::orderBy('name')->get(['id', 'name', 'code']),
            'liveErrors' => $this->validator()->errors($this->graph()),
            'definition' => $this->definitionId ? WorkflowDefinition::find($this->definitionId) : null,
        ]);
    }

    /* ================================================================== */
    /*  Nodes */
    /* ================================================================== */

    public function addNode(string $type): void
    {
        $enum = WorkflowNodeType::tryFrom($type) ?? WorkflowNodeType::Task;
        $code = $this->uniqueCode($enum->value);

        $node = [
            'code' => $code,
            'type' => $enum->value,
            'name' => $enum->label(),
            'x' => 320,
            'y' => 320,
        ];

        if ($enum->waitsForHuman()) {
            $node += [
                'assignee_rule' => 'role',
                'assignee_config' => ['roles' => []],
                'sla_hours' => 72,
                'on_timeout' => 'escalate',
                'allow_delegate' => true,
                'allow_return' => true,
            ];
        }

        if ($enum === WorkflowNodeType::End) {
            $node['outcome'] = 'approved';
        }

        $this->nodes[] = $node;
        $this->selectedNode = $code;
        $this->dirty = true;
    }

    public function selectNode(string $code): void
    {
        $this->selectedNode = $code;
        $this->selectedEdge = null;
    }

    /**
     * Called by the Alpine drag handler when a node is dropped.
     *
     * Rounded to a 20px grid: a diagram whose boxes are two pixels out of line
     * reads as sloppy, and nobody wants to nudge them by hand.
     */
    public function moveNode(string $code, int $x, int $y): void
    {
        foreach ($this->nodes as $index => $node) {
            if ($node['code'] === $code) {
                $this->nodes[$index]['x'] = max(0, (int) (round($x / 20) * 20));
                $this->nodes[$index]['y'] = max(0, (int) (round($y / 20) * 20));
                $this->dirty = true;

                return;
            }
        }
    }

    public function renameNode(string $code, string $newCode): void
    {
        $newCode = Str::slug($newCode, '_');

        if ($newCode === '' || $newCode === $code) {
            return;
        }

        if (collect($this->nodes)->contains(fn (array $n) => $n['code'] === $newCode)) {
            $this->addError('nodeCode', "There is already a step called [{$newCode}].");

            return;
        }

        foreach ($this->nodes as $index => $node) {
            if ($node['code'] === $code) {
                $this->nodes[$index]['code'] = $newCode;
            }
        }

        // Renaming without rewiring is the mistake this method exists to
        // prevent: it leaves edges pointing at a step that no longer exists,
        // and the failure surfaces as a workflow that silently stops.
        foreach ($this->edges as $index => $edge) {
            if ($edge['from'] === $code) {
                $this->edges[$index]['from'] = $newCode;
            }
            if ($edge['to'] === $code) {
                $this->edges[$index]['to'] = $newCode;
            }
        }

        $this->selectedNode = $newCode;
        $this->dirty = true;
    }

    public function deleteNode(string $code): void
    {
        $this->nodes = array_values(array_filter($this->nodes, fn (array $n) => $n['code'] !== $code));
        $this->edges = array_values(array_filter(
            $this->edges,
            fn (array $e) => $e['from'] !== $code && $e['to'] !== $code
        ));

        if ($this->selectedNode === $code) {
            $this->selectedNode = null;
        }

        $this->dirty = true;
    }

    /* ================================================================== */
    /*  Edges */
    /* ================================================================== */

    public function addEdge(): void
    {
        if ($this->edgeFrom === '' || $this->edgeTo === '' || $this->edgeFrom === $this->edgeTo) {
            $this->addError('edge', 'Choose two different steps to connect.');

            return;
        }

        $exists = collect($this->edges)->contains(
            fn (array $e) => $e['from'] === $this->edgeFrom && $e['to'] === $this->edgeTo
        );

        if ($exists) {
            $this->addError('edge', 'Those two steps are already connected.');

            return;
        }

        $this->edges[] = ['from' => $this->edgeFrom, 'to' => $this->edgeTo, 'when' => null, 'label' => null];
        $this->selectedEdge = count($this->edges) - 1;
        $this->edgeFrom = '';
        $this->edgeTo = '';
        $this->dirty = true;
    }

    public function selectEdge(int $index): void
    {
        $this->selectedEdge = $index;
        $this->selectedNode = null;
    }

    public function deleteEdge(int $index): void
    {
        unset($this->edges[$index]);
        $this->edges = array_values($this->edges);
        $this->selectedEdge = null;
        $this->dirty = true;
    }

    /**
     * Move an edge earlier in the list.
     *
     * Order is meaningful, not cosmetic: an exclusive gateway takes the FIRST
     * satisfied edge, so the default branch belongs last and a condition that
     * should win belongs first.
     */
    public function moveEdge(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->edges[$index], $this->edges[$target])) {
            return;
        }

        [$this->edges[$index], $this->edges[$target]] = [$this->edges[$target], $this->edges[$index]];
        $this->selectedEdge = $target;
        $this->dirty = true;
    }

    /* ================================================================== */
    /*  Escalation rules */
    /* ================================================================== */

    public function addEscalationRule(): void
    {
        $this->escalationRules[] = [
            'node' => null,
            'after_hours' => 24,
            'action' => 'escalate',
            'escalate_to' => ['rule' => 'role', 'roles' => []],
        ];
        $this->dirty = true;
    }

    public function removeEscalationRule(int $index): void
    {
        unset($this->escalationRules[$index]);
        $this->escalationRules = array_values($this->escalationRules);
        $this->dirty = true;
    }

    /* ================================================================== */
    /*  Persistence */
    /* ================================================================== */

    public function save(): void
    {
        $this->validate([
            'code' => 'required|string|max:80|regex:/^[a-z0-9_]+$/',
            'name' => 'required|string|max:255',
            'entityType' => 'required|string|max:50',
        ], [
            'code.regex' => 'The code may contain lower-case letters, numbers and underscores only.',
        ]);

        $definition = $this->definitionId ? WorkflowDefinition::find($this->definitionId) : null;

        $saved = app(WorkflowPublisher::class)->saveDraft($definition, [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'entity_type' => $this->entityType,
            'object_type_id' => $this->objectTypeId,
            'trigger' => $this->trigger,
            'definition' => $this->graph(),
            'escalation_rules' => $this->escalationRules ?: null,
        ], auth()->user());

        $this->definitionId = $saved->id;
        $this->dirty = false;
        $this->publishErrors = [];

        session()->flash('designer-message', 'Draft saved as version '.$saved->version.'.');
    }

    public function publish(): void
    {
        $this->save();

        $definition = WorkflowDefinition::findOrFail($this->definitionId);

        try {
            app(WorkflowPublisher::class)->publish($definition, auth()->user());
        } catch (RuntimeException $e) {
            $this->publishErrors = explode("\n", $e->getMessage());
            session()->flash('designer-error', 'This workflow is not ready to publish.');

            return;
        }

        $this->publishErrors = [];
        session()->flash('designer-message', "Published version {$definition->version}. Instances already running "
            .'stay on the version they started with.');
    }

    /* ================================================================== */

    /** @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>} */
    public function graph(): array
    {
        return [
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
        ];
    }

    private function validator(): WorkflowDefinitionValidator
    {
        return app(WorkflowDefinitionValidator::class);
    }

    private function loadDefinition(int $id): void
    {
        $definition = WorkflowDefinition::findOrFail($id);

        abort_unless($definition->organization_id === TenantContext::organizationId(), 403);

        $this->code = (string) $definition->code;
        $this->name = (string) $definition->name;
        $this->description = (string) $definition->description;
        $this->entityType = (string) $definition->entity_type;
        $this->objectTypeId = $definition->object_type_id;
        $this->trigger = $definition->trigger ?? 'manual';
        $this->nodes = (array) data_get($definition->definition, 'nodes', []);
        $this->edges = (array) data_get($definition->definition, 'edges', []);
        $this->escalationRules = (array) ($definition->escalation_rules ?? []);
    }

    private function uniqueCode(string $base): string
    {
        $codes = array_column($this->nodes, 'code');
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $codes, true)) {
            $candidate = $base.'_'.$suffix++;
        }

        return $candidate;
    }
}
