<?php

namespace App\Http\Requests\Workflow;

use App\Enums\WorkflowNodeType;
use App\Models\WorkflowDefinition;
use App\Services\Workflow\SubjectRegistry;
use App\Support\Metadata\MetadataRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Save a workflow design (migration Phase 6.5).
 *
 * WorkflowDesigner::save() validated three fields — code, name and entityType —
 * and posted everything else straight into the JSON columns. What that let
 * through:
 *
 * - `object_type_id` had no rule at all. WorkflowDefinition is strictly
 *   tenant-scoped and so are object types, so a definition could be bound to
 *   another institution's type.
 * - `entity_type` was `required|string|max:50` against a form offering
 *   `SubjectRegistry::boundTypes()`. An unbound type means the workflow can
 *   never start, and nothing says so until somebody tries.
 * - `trigger` had no rule. WorkflowTrigger reads it; an unknown value simply
 *   never fires.
 * - `escalation_rules` was untouched. A rule may name a `node` — omitting it
 *   applies the rule to every step — and a rule naming a step that does not
 *   exist escalates to nowhere: a deadline that passes in silence, which is
 *   the one thing an escalation rule exists to prevent.
 *
 * NODE AND EDGE SHAPE IS CHECKED HERE; GRAPH COHERENCE IS NOT. That split is
 * deliberate and it is the designer's central rule: **validation is on publish,
 * not on save.** A half-drawn process is a normal thing to have saved. What
 * must never happen is a half-drawn process being the one new instances start
 * on, so WorkflowDefinitionValidator runs at publish and reports every error at
 * once. This request only refuses what could not be a workflow at all — an edge
 * naming a step that is not on the canvas, a node of a type the engine has
 * never heard of.
 */
class SaveWorkflowDesignRequest extends FormRequest
{
    /**
     * The four things WorkflowTriggerService::fire() and
     * RunScheduledWorkflows look for, plus `manual`.
     *
     * The first draft of this list said `scheduled`, which nothing in the
     * platform has ever written or read — the command queries `on_schedule` —
     * and omitted `on_event`, which a shipped process uses. Both were caught by
     * DesignerRoundTripTest, which is the point of asserting against what the
     * platform actually ships rather than against a list somebody typed.
     */
    public const TRIGGERS = ['manual', 'on_create', 'on_transition', 'on_event', 'on_schedule'];

    /** What WorkflowEngine::sweep() does when a task passes its due date. */
    public const TIMEOUT_ACTIONS = ['escalate', 'notify', 'auto_approve', 'auto_reject'];

    /**
     * Every rule AssigneeResolver::resolve() handles.
     *
     * Taken from that match statement rather than from the designer's picker,
     * which offers a readable subset. The first draft of this request listed
     * five and omitted `delegate` — which three of the ten shipped processes
     * use — so the designer would have refused to save a workflow the platform
     * itself ships. DesignerRoundTripTest caught it, which is what that test is
     * for.
     */
    public const ASSIGNEE_RULES = [
        'user', 'role', 'group', 'owner', 'delegate', 'manager',
        'relationship_traversal', 'expression',
    ];

    public function authorize(): bool
    {
        $definition = $this->route('definition');

        return $definition === null
            ? $this->user()->can('create', WorkflowDefinition::class)
            : $this->user()->can('update', $definition);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $definition = $this->route('definition');

        return [
            'code' => [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('workflow_definitions', 'code')
                    ->where('organization_id', TenantContext::organizationId())
                    ->whereNull('deleted_at')
                    ->ignore($definition?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'entity_type' => ['required', 'string', 'max:50', Rule::in(app(SubjectRegistry::class)->boundTypes())],
            'object_type_id' => ['nullable', 'integer', MetadataRules::objectType()],
            'trigger' => ['required', Rule::in(self::TRIGGERS)],

            'definition' => ['required', 'array'],
            'definition.nodes' => ['required', 'array', 'min:1'],
            'definition.nodes.*.code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'definition.nodes.*.type' => ['required', Rule::in(array_column(WorkflowNodeType::cases(), 'value'))],
            'definition.nodes.*.name' => ['required', 'string', 'max:255'],
            'definition.nodes.*.x' => ['integer', 'min:0', 'max:20000'],
            'definition.nodes.*.y' => ['integer', 'min:0', 'max:20000'],
            'definition.nodes.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            // WorkflowEngine::sweep() matches on exactly these, with anything
            // unrecognised falling through to escalate. The first draft listed
            // `nothing`, which the engine has never had — it would have
            // escalated, and the label would have been a lie — and omitted
            // `notify`, which two shipped processes use.
            'definition.nodes.*.on_timeout' => ['nullable', Rule::in(self::TIMEOUT_ACTIONS)],
            'definition.nodes.*.assignee_rule' => ['nullable', Rule::in(self::ASSIGNEE_RULES)],
            // The config's shape varies by rule — a traversal carries a path, an
            // expression carries an expression — so only the parts that name a
            // principal are constrained here. Whether the config actually fills
            // its rule in is WorkflowDefinitionValidator's question, asked at
            // publish.
            'definition.nodes.*.assignee_config' => ['nullable', 'array'],
            'definition.nodes.*.assignee_config.roles' => ['nullable', 'array'],
            'definition.nodes.*.assignee_config.roles.*' => ['string', Rule::exists('roles', 'name')],
            'definition.nodes.*.assignee_config.fallback_roles' => ['nullable', 'array'],
            'definition.nodes.*.assignee_config.fallback_roles.*' => ['string', Rule::exists('roles', 'name')],
            'definition.nodes.*.assignee_config.user_id' => ['nullable', 'integer', $this->tenantUser()],
            'definition.nodes.*.assignee_config.user_ids' => ['nullable', 'array'],
            'definition.nodes.*.assignee_config.user_ids.*' => ['integer', $this->tenantUser()],
            'definition.nodes.*.fallback_roles' => ['nullable', 'array'],
            'definition.nodes.*.fallback_roles.*' => ['string', Rule::exists('roles', 'name')],
            'definition.nodes.*.outcome' => ['nullable', 'string', 'max:60'],
            'definition.nodes.*.instructions' => ['nullable', 'string', 'max:2000'],
            'definition.nodes.*.escalate_to' => ['nullable', 'array'],
            'definition.nodes.*.escalate_to.rule' => ['nullable', Rule::in(self::ASSIGNEE_RULES)],
            'definition.nodes.*.escalate_to.config' => ['nullable', 'array'],
            'definition.nodes.*.escalate_to.config.roles' => ['nullable', 'array'],
            'definition.nodes.*.escalate_to.config.roles.*' => ['string', Rule::exists('roles', 'name')],
            'definition.nodes.*.allow_delegate' => ['boolean'],
            'definition.nodes.*.allow_return' => ['boolean'],

            'definition.edges' => ['array'],
            'definition.edges.*.from' => ['required', 'string', 'max:80'],
            'definition.edges.*.to' => ['required', 'string', 'max:80'],
            'definition.edges.*.when' => ['nullable', 'string', 'max:500'],
            'definition.edges.*.label' => ['nullable', 'string', 'max:120'],

            'escalation_rules' => ['nullable', 'array'],
            // NULLABLE, and that is the shipped shape: a rule with no node
            // applies to every step, which is how WorkflowEngine::timeoutAction()
            // reads it. The first draft made it required and would have refused
            // every process the platform ships.
            'escalation_rules.*.node' => ['nullable', 'string', 'max:80'],
            'escalation_rules.*.after_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'escalation_rules.*.action' => ['required', Rule::in(self::TIMEOUT_ACTIONS)],
            'escalation_rules.*.escalate_to' => ['nullable', 'array'],
            'escalation_rules.*.escalate_to.rule' => ['nullable', 'in:role,user,manager'],
            'escalation_rules.*.escalate_to.roles' => ['nullable', 'array'],
            'escalation_rules.*.escalate_to.roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * Every edge and every escalation rule must name a step that is on the
     * canvas.
     *
     * Renaming a step without rewiring is the mistake the designer's rename
     * handler exists to prevent, and this is the backstop: an edge pointing at
     * a step that no longer exists surfaces as a workflow that silently stops,
     * and an escalation rule pointing at one is a deadline that passes without
     * a sound.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $codes = array_column((array) $this->input('definition.nodes', []), 'code');

            foreach ((array) $this->input('definition.edges', []) as $index => $edge) {
                foreach (['from', 'to'] as $end) {
                    $code = $edge[$end] ?? null;

                    if ($code !== null && ! in_array($code, $codes, true)) {
                        $validator->errors()->add(
                            "definition.edges.{$index}.{$end}",
                            "There is no step called [{$code}] on this canvas.",
                        );
                    }
                }
            }

            foreach ((array) $this->input('escalation_rules', []) as $index => $rule) {
                $code = $rule['node'] ?? null;

                if ($code !== null && ! in_array($code, $codes, true)) {
                    $validator->errors()->add(
                        "escalation_rules.{$index}.node",
                        "There is no step called [{$code}] to escalate from.",
                    );
                }
            }
        });
    }

    /**
     * A user in this institution. Assigning a step to somebody in another one
     * is a step nobody can action.
     */
    private function tenantUser(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('users', 'id')
            ->where('organization_id', TenantContext::organizationId())
            ->whereNull('deleted_at');
    }

    /**
     * The attributes WorkflowPublisher::saveDraft() expects.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'entity_type' => $validated['entity_type'],
            'object_type_id' => $validated['object_type_id'] ?? null,
            'trigger' => $validated['trigger'],
            // THE GRAPH IS TAKEN WHOLE, not from validated().
            //
            // validated() returns only the keys the rules name, and a node
            // carries more than this request knows about — `instructions` and a
            // node-level `escalate_to` among them, both of which the shipped
            // library uses. Building the payload from validated() dropped them
            // silently: opening a seeded process in the designer and pressing
            // save would have quietly deleted parts of it. DesignerRoundTripTest
            // is what caught that, and is what will catch the next key somebody
            // adds to a node without telling this class.
            //
            // The rules above still constrain everything they name; what passes
            // through untouched is the rest of the tenant's own document, which
            // the engine ignores when it does not recognise it.
            'definition' => [
                'nodes' => array_values((array) $this->input('definition.nodes', [])),
                'edges' => array_values((array) $this->input('definition.edges', [])),
            ],
            'escalation_rules' => array_values((array) $this->input('escalation_rules', [])) ?: null,
        ];
    }
}
