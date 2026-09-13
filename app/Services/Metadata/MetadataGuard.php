<?php

namespace App\Services\Metadata;

use App\Models\GraphObject;
use App\Models\ObjectAttribute;
use App\Models\ObjectLifecycle;
use App\Models\ObjectRelationship;
use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WP-05 TASK 1 — the rules that stop "configure, don't code" from meaning
 * "delete the schema from a web form".
 *
 * Every one of these exists because the alternative is silent data loss. A
 * configuration UI without them is not a feature, it is an outage with a save
 * button. The guard lives in one class rather than inside the Livewire
 * components so that the same rules apply to the components, the artisan
 * commands and — the case that matters most — a configuration bundle import,
 * which arrives from another environment with no human watching.
 */
class MetadataGuard
{
    /* ------------------------------------------------------------------ */
    /*  Object types */
    /* ------------------------------------------------------------------ */

    /**
     * @throws ValidationException
     */
    public function assertTypeDeletable(ObjectType $type): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages([
                'type' => "{$type->name} is a system type. The platform's own code resolves it by code — "
                    .'deleting it would break the modules built on it. Create your own type that inherits '
                    .'from it instead.',
            ]);
        }

        $objects = GraphObject::withoutGlobalScopes()->where('object_type_id', $type->id)->count();

        if ($objects > 0) {
            throw ValidationException::withMessages([
                'type' => "{$type->name} has {$objects} record(s). Delete or re-type them before deleting the type.",
            ]);
        }

        $children = ObjectType::withoutGlobalScopes()->where('parent_type_id', $type->id)->count();

        if ($children > 0) {
            throw ValidationException::withMessages([
                'type' => "{$type->name} has {$children} child type(s) inheriting from it. Reparent them first, "
                    .'or their attributes will vanish with it.',
            ]);
        }
    }

    /**
     * A type may not be its own ancestor. Without this an SME can build
     * A → B → A through two innocuous-looking edits and every attribute
     * resolution in the product spins until the guard counter in
     * ObjectType::resolvedAttributes() trips.
     *
     * @throws ValidationException
     */
    public function assertNoInheritanceCycle(ObjectType $type, ?int $parentTypeId): void
    {
        if ($parentTypeId === null) {
            return;
        }

        if ($type->exists && $parentTypeId === $type->id) {
            throw ValidationException::withMessages([
                'parent_type_id' => 'A type cannot inherit from itself.',
            ]);
        }

        $seen = [];
        $cursor = ObjectType::withoutGlobalScopes()->find($parentTypeId);

        while ($cursor !== null) {
            if ($type->exists && $cursor->id === $type->id) {
                throw ValidationException::withMessages([
                    'parent_type_id' => "That would make {$type->name} its own ancestor.",
                ]);
            }

            if (isset($seen[$cursor->id])) {
                // Pre-existing cycle in the data. Report it rather than loop.
                throw ValidationException::withMessages([
                    'parent_type_id' => 'The inheritance chain above that type already contains a cycle. '
                        .'Fix that before parenting anything else to it.',
                ]);
            }

            $seen[$cursor->id] = true;
            $cursor = $cursor->parent_type_id
                ? ObjectType::withoutGlobalScopes()->find($cursor->parent_type_id)
                : null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Attributes */
    /* ------------------------------------------------------------------ */

    /**
     * How many stored records carry a value for this attribute.
     *
     * Mapped attributes are backed by a real column and are not counted here:
     * changing the data_type of a mapped attribute changes how a column is
     * rendered and validated, not how it is stored, so it carries none of the
     * lossy-conversion risk.
     */
    public function attributeUsageCount(ObjectAttribute $attribute): int
    {
        if (! $attribute->exists || $attribute->isMapped()) {
            return 0;
        }

        return GraphObject::withoutGlobalScopes()
            ->where('object_type_id', $attribute->object_type_id)
            ->get(['id', 'attributes'])
            ->filter(function (GraphObject $object) use ($attribute) {
                $value = $object->customAttributes()[$attribute->code] ?? null;

                return $value !== null && $value !== '' && $value !== [];
            })
            ->count();
    }

    /**
     * Conversions that cannot lose information, and are therefore allowed on
     * an attribute that already holds data.
     *
     * Everything absent from this map is refused: 'text' → 'int' would turn
     * "approximately ₦4m" into 0 across every record that ever held it, and
     * an UPDATE that does that has no undo.
     *
     * @var array<string, list<string>>
     */
    private const WIDENING_CONVERSIONS = [
        'string' => ['text'],
        'int' => ['decimal', 'string', 'text'],
        'decimal' => ['string', 'text'],
        'money' => ['decimal', 'string', 'text'],
        'bool' => ['string', 'text'],
        'date' => ['datetime', 'string', 'text'],
        'datetime' => ['string', 'text'],
        'enum' => ['multi_enum', 'string', 'text'],
        'user' => ['int', 'string', 'text'],
        'object_ref' => ['int', 'string', 'text'],
    ];

    /**
     * @throws ValidationException
     */
    public function assertDataTypeChangeIsSafe(ObjectAttribute $attribute, string $newType, bool $confirmedLossy = false): void
    {
        $current = $attribute->getOriginal('data_type') ?? $attribute->data_type;

        if ($current === $newType) {
            return;
        }

        if ($attribute->is_system) {
            throw ValidationException::withMessages([
                'data_type' => "{$attribute->label} is a system attribute. Its type is part of the contract the "
                    .'platform code relies on.',
            ]);
        }

        $inUse = $this->attributeUsageCount($attribute);

        if ($inUse === 0) {
            return;
        }

        if (in_array($newType, self::WIDENING_CONVERSIONS[$current] ?? [], true)) {
            return;
        }

        if (! $confirmedLossy) {
            throw ValidationException::withMessages([
                'data_type' => "{$inUse} record(s) already hold a {$current} value for {$attribute->label}. "
                    ."Converting to {$newType} cannot preserve all of them. Choose an explicit migration path: "
                    .'keep the values as text, clear them, or add a new attribute and migrate at your own pace.',
            ]);
        }
    }

    /**
     * Apply an explicitly chosen migration path to the stored values.
     *
     * `preserve_as_text` keeps what was written and lets a human sort it out;
     * `clear` discards it deliberately. There is no third option that silently
     * casts, because that is the behaviour this guard exists to prevent.
     *
     * @return int the number of records touched
     */
    public function migrateAttributeValues(ObjectAttribute $attribute, string $strategy): int
    {
        if (! in_array($strategy, ['preserve_as_text', 'clear'], true)) {
            throw ValidationException::withMessages([
                'data_type' => "Unknown migration path [{$strategy}].",
            ]);
        }

        $touched = 0;

        GraphObject::withoutGlobalScopes()
            ->where('object_type_id', $attribute->object_type_id)
            ->chunkById(200, function ($objects) use ($attribute, $strategy, &$touched) {
                foreach ($objects as $object) {
                    $bag = $object->customAttributes();

                    if (! array_key_exists($attribute->code, $bag)) {
                        continue;
                    }

                    $value = $bag[$attribute->code];

                    if ($value === null || $value === '' || $value === []) {
                        continue;
                    }

                    $bag[$attribute->code] = $strategy === 'clear'
                        ? null
                        : (is_scalar($value) ? (string) $value : json_encode($value));

                    $object->setCustomAttributes($bag);
                    $object->saveQuietly();
                    $touched++;
                }
            });

        return $touched;
    }

    /**
     * @throws ValidationException
     */
    public function assertAttributeDeletable(ObjectAttribute $attribute, bool $confirmed = false): void
    {
        if ($attribute->is_system) {
            throw ValidationException::withMessages([
                'attribute' => "{$attribute->label} is a system attribute and cannot be deleted.",
            ]);
        }

        $inUse = $this->attributeUsageCount($attribute);

        if ($inUse > 0 && ! $confirmed) {
            throw ValidationException::withMessages([
                'attribute' => "{$inUse} record(s) hold a value for {$attribute->label}. Deleting the attribute "
                    .'orphans those values — they stay in the record but nothing will render them again.',
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Relationship types */
    /* ------------------------------------------------------------------ */

    public function relationshipInstanceCount(ObjectRelationshipType $type): int
    {
        return ObjectRelationship::withoutGlobalScopes()
            ->where('relationship_type_id', $type->id)
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * Delete a relationship type, archiving its edges.
     *
     * The edge table is restrictOnDelete against the type, so before
     * archiving existed the only choices were to refuse the delete or to
     * cascade real graph edges away. Archiving keeps the history of what once
     * mitigated what — which is exactly the kind of thing an auditor asks
     * about two years later — while removing the edges from live traversal.
     *
     * @return int the number of edges archived
     *
     * @throws ValidationException
     */
    public function deleteRelationshipType(ObjectRelationshipType $type, bool $confirmed = false): int
    {
        if ($type->is_system) {
            throw ValidationException::withMessages([
                'relationship_type' => "{$type->name} is a system relationship type. The graph queries that build "
                    .'roll-ups and the assurance map resolve it by code.',
            ]);
        }

        $instances = $this->relationshipInstanceCount($type);

        if ($instances > 0 && ! $confirmed) {
            throw ValidationException::withMessages([
                'relationship_type' => "{$type->name} has {$instances} live relationship(s). Deleting it archives "
                    .'them: they stop appearing in traversals, roll-ups and the assurance map, but they are '
                    .'retained for audit. Confirm to proceed.',
            ]);
        }

        return DB::transaction(function () use ($type, $instances) {
            if ($instances > 0) {
                ObjectRelationship::withoutGlobalScopes()
                    ->where('relationship_type_id', $type->id)
                    ->whereNull('archived_at')
                    ->update(['archived_at' => now()]);
            }

            // The type row itself is soft-kept: the archived edges still point
            // at it through a restrictOnDelete foreign key, and an archived
            // edge whose type has vanished cannot be read back. It is instead
            // renamed out of the tenant's namespace so the code can be reused.
            $type->forceFill([
                'code' => $type->code.'__archived_'.now()->format('YmdHis'),
                'name' => $type->name.' (archived)',
            ])->save();

            return $instances;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycles */
    /* ------------------------------------------------------------------ */

    /**
     * A lifecycle must have exactly one initial state, at least one terminal
     * state, and no transition pointing at a state that does not exist.
     *
     * The last one is the rule that catches the real mistake: renaming a state
     * in the editor without updating the transitions that target it leaves a
     * machine that silently refuses a move a user is certain should work.
     *
     * @param  list<array<string, mixed>>  $states
     *
     * @throws ValidationException
     */
    public function assertLifecycleIsCoherent(array $states): void
    {
        if ($states === []) {
            throw ValidationException::withMessages([
                'states' => 'A lifecycle needs at least one state.',
            ]);
        }

        $codes = array_column($states, 'code');
        $blank = array_filter($codes, fn ($code) => ! is_string($code) || trim($code) === '');

        if ($blank !== []) {
            throw ValidationException::withMessages(['states' => 'Every state needs a code.']);
        }

        if (count($codes) !== count(array_unique($codes))) {
            $duplicates = array_unique(array_diff_assoc($codes, array_unique($codes)));

            throw ValidationException::withMessages([
                'states' => 'Duplicate state code(s): '.implode(', ', $duplicates).'.',
            ]);
        }

        $initial = array_filter($states, fn (array $state) => (bool) ($state['is_initial'] ?? false));

        if (count($initial) !== 1) {
            throw ValidationException::withMessages([
                'states' => count($initial) === 0
                    ? 'Exactly one state must be the initial state, or nothing can be created.'
                    : 'Only one state can be the initial state; '.count($initial).' are marked.',
            ]);
        }

        $terminal = array_filter($states, fn (array $state) => (bool) ($state['is_terminal'] ?? false));

        if ($terminal === []) {
            throw ValidationException::withMessages([
                'states' => 'At least one state must be terminal, or nothing can ever be closed.',
            ]);
        }

        foreach ($states as $state) {
            foreach ((array) ($state['allowed_transitions'] ?? []) as $target) {
                if (! in_array($target, $codes, true)) {
                    throw ValidationException::withMessages([
                        'states' => "{$state['code']} allows a transition to [{$target}], which is not a state "
                            .'in this lifecycle.',
                    ]);
                }
            }
        }

        $this->assertEveryStateIsReachable($states, $codes);
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @param  list<string>  $codes
     */
    private function assertEveryStateIsReachable(array $states, array $codes): void
    {
        $initial = null;
        $transitions = [];

        foreach ($states as $state) {
            $transitions[$state['code']] = (array) ($state['allowed_transitions'] ?? []);

            if ($state['is_initial'] ?? false) {
                $initial = $state['code'];
            }
        }

        $reached = [$initial => true];
        $queue = [$initial];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($transitions[$current] ?? [] as $next) {
                if (! isset($reached[$next])) {
                    $reached[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        $orphans = array_diff($codes, array_keys($reached));

        if ($orphans !== []) {
            throw ValidationException::withMessages([
                'states' => 'No path leads to: '.implode(', ', $orphans).'. A state nothing can reach is a '
                    .'state no record will ever be in.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertLifecycleDeletable(ObjectLifecycle $lifecycle): void
    {
        if ($lifecycle->is_system) {
            throw ValidationException::withMessages([
                'lifecycle' => "{$lifecycle->name} is a system lifecycle.",
            ]);
        }

        $assigned = ObjectType::withoutGlobalScopes()
            ->where('default_lifecycle_id', $lifecycle->id)
            ->count();

        if ($assigned > 0) {
            throw ValidationException::withMessages([
                'lifecycle' => "{$lifecycle->name} is the default lifecycle of {$assigned} type(s). "
                    .'Assign them another one first.',
            ]);
        }
    }
}
