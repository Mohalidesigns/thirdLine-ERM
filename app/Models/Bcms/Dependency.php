<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\DependencyType;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * What a process needs in order to run.
 *
 * `dependable_type` stores a SHORT MORPH KEY — `vendors`, `applications` —
 * never a class name, and the map is enforced in `AppServiceProvider` so an
 * unregistered class throws rather than silently persisting an FQCN
 * (ADR 0002).
 *
 * `dependable` MAY BE NULL for a live row: a vendor soft-deleted in TPRM
 * leaves a dependency pointing at nothing, and a screen must say "no longer in
 * the register" rather than render blank.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $assessment_id
 * @property string $dependable_type
 * @property int $dependable_id
 * @property ?string $dependency_type
 * @property ?string $criticality
 * @property bool $single_point_of_failure
 * @property ?string $recovery_notes
 * @property bool $alternative_available
 * @property ?string $external_ref
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class Dependency extends Model
{
    use BelongsToOrganization, BindsToVisibleRecord, HasFactory;

    protected $table = 'bcms_dependencies';

    /**
     * Derived (ADR 0017 §2): a dependency has no unit column of its own and
     * takes the shortest path to an anchor — through its assessment to the
     * process it assesses. Two hops, and it is a single path (§2 rule 2).
     */
    public function orgAnchorPath(): string
    {
        return 'assessment.process';
    }

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'assessment_id', 'dependable_type', 'dependable_id', 'dependency_type',
        'criticality', 'single_point_of_failure', 'recovery_notes', 'alternative_available',
        'external_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'assessment_id' => 'integer',
            'dependable_id' => 'integer',
            'single_point_of_failure' => 'boolean',
            'alternative_available' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BiaAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(BiaAssessment::class, 'assessment_id');
    }

    /**
     * The thing depended on, resolved through the enforced morph map.
     *
     * MAY RETURN NULL on a live row. A vendor soft-deleted in TPRM, an
     * application retired from the inventory, a user offboarded — the
     * dependency row survives them all, and it should: "this process depended
     * on something that no longer exists" is a finding, not a rendering bug.
     * Every caller reads through `dependableLabel()` rather than
     * `$dependency->dependable->name`.
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function dependable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The morph key as its enum, or null if the row predates a repointing. */
    public function type(): ?DependencyType
    {
        return DependencyType::tryFrom((string) $this->dependable_type);
    }

    /**
     * What to print for the thing depended on.
     *
     * Never a blank and never a fabricated placeholder: a dependency whose
     * target has gone says so, and says what kind of thing it was.
     */
    public function dependableLabel(): string
    {
        $target = $this->dependable;

        if ($target === null) {
            $kind = $this->type()?->label() ?? 'Dependency';

            return $kind.' no longer in the register';
        }

        foreach (['name', 'full_name', 'legal_name', 'title'] as $attribute) {
            $value = $target->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return ($this->type()?->label() ?? 'Dependency').' #'.$target->getKey();
    }
}
