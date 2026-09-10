<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectLifecycle;
use App\Support\Metadata\MetadataRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Define a state machine (migration Phase 6.3).
 *
 * LifecycleBuilder validated the lifecycle's own five fields and **nothing
 * inside a state beyond its code and name**. The states array carried four
 * more values straight into the JSON column:
 *
 * - `required_workflow_id` — no rule of any kind. The form offered this
 *   tenant's workflow definitions; the component stored whatever integer
 *   arrived. WorkflowDefinition is strictly tenant-scoped, so a state could
 *   demand another institution's workflow before a record could move — a
 *   transition nobody in either organisation could ever complete.
 * - `required_permission` — no rule. An invented permission fails closed,
 *   which is the safe direction, but it is a transition that silently refuses
 *   everybody and says nothing about why.
 * - `color` — no rule. It reaches a style attribute.
 * - `is_initial` / `is_terminal` — booleans, and coherence between them is
 *   MetadataGuard's job, not this one's.
 *
 * Coherence — exactly one initial state, at least one terminal state, no
 * transition to a state that does not exist, no state unreachable from the
 * initial one — stays in MetadataGuard::assertLifecycleIsCoherent() so that a
 * configuration bundle import is held to the same rules as this screen.
 */
class StoreLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ObjectLifecycle::class);
    }

    protected function subject(): ?ObjectLifecycle
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'object_type_id' => ['required', 'integer', MetadataRules::objectType()],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_\-]*$/'],

            'states' => ['required', 'array', 'min:1'],
            'states.*.code' => ['required', 'string', 'max:64'],
            'states.*.name' => ['required', 'string', 'max:120'],
            'states.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'states.*.is_initial' => ['boolean'],
            'states.*.is_terminal' => ['boolean'],
            'states.*.allowed_transitions' => ['array'],
            'states.*.allowed_transitions.*' => ['string', 'max:64'],
            'states.*.required_permission' => ['nullable', 'string', 'max:120', Rule::exists('permissions', 'name')],
            'states.*.required_workflow_id' => ['nullable', 'integer', MetadataRules::workflowDefinition()],
        ];
    }

    /**
     * The states as MetadataGuard and the model expect them.
     *
     * @return list<array<string, mixed>>
     */
    public function states(): array
    {
        return array_map(fn (array $state) => [
            'code' => trim((string) $state['code']),
            'name' => trim((string) $state['name']),
            'color' => ($state['color'] ?? '') ?: '#94a3b8',
            'is_initial' => (bool) ($state['is_initial'] ?? false),
            'is_terminal' => (bool) ($state['is_terminal'] ?? false),
            'allowed_transitions' => array_values(array_filter($state['allowed_transitions'] ?? [])),
            'required_permission' => ($state['required_permission'] ?? null) ?: null,
            'required_workflow_id' => ($state['required_workflow_id'] ?? null) ?: null,
        ], $this->validated()['states']);
    }
}
