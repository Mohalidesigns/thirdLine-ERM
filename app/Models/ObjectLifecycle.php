<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A configurable state machine for an object type.
 *
 * The seeded lifecycles use the state codes ALREADY in the domain tables. That
 * is why the issue lifecycle is uppercase and the risk lifecycle is not: a
 * lifecycle that disagrees with its own table can validate nothing, and
 * renaming live states is a data migration, not a definition change.
 */
class ObjectLifecycle extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'object_lifecycles';

    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'object_type_id',
        'code',
        'name',
        'states',
        'is_system',
    ];

    protected $casts = [
        'states' => 'array',
        'is_system' => 'boolean',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ObjectType, $this> */
    public function objectType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(string $code): ?array
    {
        foreach ($this->states ?? [] as $state) {
            if (($state['code'] ?? null) === $code) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function initialState(): ?array
    {
        foreach ($this->states ?? [] as $state) {
            if ($state['is_initial'] ?? false) {
                return $state;
            }
        }

        return ($this->states ?? [])[0] ?? null;
    }

    /**
     * Whether $to is a legal next state from $from.
     *
     * An unknown $from returns false rather than true: a state the lifecycle
     * has never heard of is a data problem, and allowing every transition out
     * of it would let one bad row escape the machine entirely.
     */
    public function allowsTransition(?string $from, string $to): bool
    {
        if ($from === null || $from === '') {
            return ($this->initialState()['code'] ?? null) === $to;
        }

        if ($from === $to) {
            return true;
        }

        $state = $this->state($from);

        if ($state === null) {
            return false;
        }

        return in_array($to, $state['allowed_transitions'] ?? [], true);
    }

    /**
     * The permission a user needs to move an object INTO $to, or null when the
     * transition is unguarded.
     */
    public function permissionFor(string $to): ?string
    {
        return $this->state($to)['required_permission'] ?? null;
    }

    /** @return list<string> */
    public function terminalStates(): array
    {
        return array_values(array_map(
            fn (array $state) => $state['code'],
            array_filter($this->states ?? [], fn (array $state) => $state['is_terminal'] ?? false)
        ));
    }
}
