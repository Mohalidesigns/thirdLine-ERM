<?php

namespace App\Services\Workflow;

use App\Models\ObjectType;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Support\MorphTypes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes and publishes definitions.
 *
 * PUBLISHING NEVER OVERWRITES. It writes a new row at version + 1 and
 * unpublishes the previous one, inside a transaction. Instances hold
 * definition_id, so everything already running keeps the graph it started on —
 * which is the whole reason versions exist. Editing a live process in place
 * would change the rules under decisions already half-made, and there would be
 * no way afterwards to say which rules a given approval was granted under.
 */
class WorkflowPublisher
{
    public function __construct(private WorkflowDefinitionValidator $validator) {}

    /**
     * Save a draft. Any shape is allowed: a half-drawn process is a legitimate
     * thing to have saved on a Friday afternoon.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveDraft(?WorkflowDefinition $definition, array $attributes, ?User $actor = null): WorkflowDefinition
    {
        if ($definition !== null && $definition->is_published) {
            // A published version is immutable. Editing one starts a draft of
            // the next version instead of mutating history.
            $definition = null;
            $attributes['version'] = null;
        }

        $organizationId = $attributes['organization_id']
            ?? $definition?->organization_id
            ?? \App\Support\Tenancy\TenantContext::organizationId();

        $code = $attributes['code'] ?? $definition?->code;

        $attributes['organization_id'] = $organizationId;
        $attributes['entity_type'] = MorphTypes::normalise($attributes['entity_type'] ?? $definition?->entity_type)
            ?? ($attributes['entity_type'] ?? $definition?->entity_type);
        $attributes['version'] = $attributes['version']
            ?? $definition?->version
            ?? $this->nextVersion($organizationId, $code);
        $attributes['is_published'] = false;
        $attributes['created_by'] = $definition?->created_by ?? $actor?->id ?? auth()->id();

        if ($definition === null) {
            return WorkflowDefinition::withoutGlobalScopes()->create($attributes);
        }

        $definition->fill($attributes)->save();

        return $definition->refresh();
    }

    /**
     * Publish a draft, making it the definition new instances start on.
     *
     * @throws RuntimeException with every validation error, so the designer can
     *                          show them all at once rather than one per attempt
     */
    public function publish(WorkflowDefinition $definition, ?User $actor = null): WorkflowDefinition
    {
        $errors = $this->validator->errors((array) $definition->definition);

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        return DB::transaction(function () use ($definition, $actor) {
            WorkflowDefinition::withoutGlobalScopes()
                ->where('organization_id', $definition->organization_id)
                ->where('code', $definition->code)
                ->whereKeyNot($definition->id)
                ->where('is_published', true)
                ->update(['is_published' => false]);

            $definition->forceFill([
                'is_published' => true,
                'is_active' => true,
                'published_at' => now(),
                'published_by' => $actor?->id ?? auth()->id(),
            ])->save();

            return $definition->refresh();
        });
    }

    /**
     * Start a new draft from a published definition, at the next version.
     *
     * This is what the designer's "edit" does to a published process.
     */
    public function draftNewVersion(WorkflowDefinition $published, ?User $actor = null): WorkflowDefinition
    {
        $draft = WorkflowDefinition::withoutGlobalScopes()
            ->where('organization_id', $published->organization_id)
            ->where('code', $published->code)
            ->where('is_published', false)
            ->orderByDesc('version')
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        return WorkflowDefinition::withoutGlobalScopes()->create([
            'organization_id' => $published->organization_id,
            'code' => $published->code,
            'version' => $published->latestVersionNumber() + 1,
            'name' => $published->name,
            'description' => $published->description,
            'entity_type' => $published->entity_type,
            'object_type_id' => $published->object_type_id,
            'definition' => $published->definition,
            'escalation_rules' => $published->escalation_rules,
            'trigger' => $published->trigger,
            'trigger_config' => $published->trigger_config,
            'scope_filter' => $published->scope_filter,
            'is_active' => true,
            'is_published' => false,
            'is_system' => false,
            'created_by' => $actor?->id ?? auth()->id(),
        ]);
    }

    public function unpublish(WorkflowDefinition $definition): WorkflowDefinition
    {
        $definition->forceFill(['is_published' => false])->save();

        return $definition->refresh();
    }

    /**
     * Install the shipped library for an organization.
     *
     * Idempotent: a definition already present at any version is left exactly
     * as it is, because the customer may have edited it and a "reinstall" that
     * silently reverted their process would be worse than no reinstall at all.
     *
     * @return list<string> the codes actually installed
     */
    public function provision(int $organizationId, bool $force = false): array
    {
        $installed = [];

        foreach (WorkflowLibrary::definitions() as $blueprint) {
            $exists = WorkflowDefinition::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('code', $blueprint['code'])
                ->exists();

            if ($exists && ! $force) {
                continue;
            }

            $objectTypeId = null;

            if (isset($blueprint['object_type_code'])) {
                $objectTypeId = ObjectType::withoutGlobalScopes()
                    ->where('code', $blueprint['object_type_code'])
                    ->orderByRaw('organization_id is null')
                    ->value('id');
            }

            $definition = WorkflowDefinition::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'code' => $blueprint['code'],
                'version' => $this->nextVersion($organizationId, $blueprint['code']),
                'name' => $blueprint['name'],
                'description' => $blueprint['description'],
                'entity_type' => $blueprint['entity_type'],
                'object_type_id' => $objectTypeId,
                'definition' => $blueprint['definition'],
                'escalation_rules' => $blueprint['escalation_rules'] ?? null,
                'trigger' => $blueprint['trigger'] ?? 'manual',
                'trigger_config' => $blueprint['trigger_config'] ?? null,
                'is_active' => true,
                'is_system' => true,
                'created_by' => null,
            ]);

            $this->publish($definition);

            $installed[] = $blueprint['code'];
        }

        return $installed;
    }

    private function nextVersion(int $organizationId, ?string $code): int
    {
        if ($code === null) {
            return 1;
        }

        return 1 + (int) WorkflowDefinition::withoutGlobalScopes()
            ->withTrashed()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->max('version');
    }
}
