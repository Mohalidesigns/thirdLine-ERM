<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A named audience — the `saved_group` leaf of ADR 0003's grammar.
 *
 * Either an explicit member list or a stored rule that resolves at dispatch.
 * Both are legitimate: "the crisis team" is a list, "everyone at the Kano
 * branch" is a rule that should pick up a new joiner without anybody editing it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $name
 * @property ?string $description
 * @property array<array-key, mixed> $rule
 * @property bool $is_dynamic
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class SavedGroup extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_saved_groups';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'name', 'description', 'rule', 'is_dynamic', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rule' => 'array',
            'organization_id' => 'integer',
            'is_dynamic' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsToMany<Contact, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'bcms_saved_group_members', 'group_id', 'contact_id')->withTimestamps();
    }
}
