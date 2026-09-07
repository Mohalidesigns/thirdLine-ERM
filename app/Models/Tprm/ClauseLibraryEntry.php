<?php

namespace App\Models\Tprm;

use App\Support\Tprm\RuleEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * One clause in the mandatory clause library — TRD Appendix C.
 *
 * `is_blocking` IS THE FEATURE (FR-CTR-05, AC-06). A blocking clause that is
 * applicable to an engagement and absent from its contract stops that
 * engagement reaching `active`. Everyone else produces a gap report and leaves
 * the institution to notice it.
 *
 * `applicability_rule` is what keeps the gate credible. A clause required only
 * of cross-border personal-data processors must not appear as a gap on a
 * stationery contract, because a report full of irrelevant gaps is a report
 * nobody reads — and the moment people learn to ignore it, the blocking gate
 * becomes an obstacle to route around rather than a control.
 *
 * SYSTEM-OWNED CLAUSES CANNOT BE DELETED, ONLY WAIVED PER INSTANCE. A tenant
 * able to delete `CBN-CYB-05` could make its own audit-rights gap disappear,
 * which is precisely the gap the regulator cares about. The model refuses the
 * delete; the policy refuses the request; the waiver register records the
 * exception that was granted instead.
 *
 * Rows with a null `organization_id` are the shipped library — the same
 * pattern, and the same tenancy trap, as `QuestionnaireTemplate` and
 * `DocumentType`.
 */
class ClauseLibraryEntry extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_clause_library';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'organization_id', 'code', 'title', 'category', 'regulatory_source',
        'citation', 'applicability_rule', 'is_blocking', 'model_text', 'guidance',
        'framework_maps', 'version', 'status',
    ];

    /**
     * Not fillable. A tenant creating a clause must not be able to declare it
     * system-owned — that flag is what makes a clause undeletable, and a
     * tenant that could set it could lock its own library.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = ['is_system_owned'];

    protected $casts = [
        'applicability_rule' => 'array',
        'framework_maps' => 'array',
        'is_blocking' => 'boolean',
        'is_system_owned' => 'boolean',
    ];

    protected $attributes = [
        'is_blocking' => false,
        'is_system_owned' => false,
        'version' => '1.0',
        'status' => self::STATUS_PUBLISHED,
    ];

    protected static function booted(): void
    {
        // Enforced on the model, not only in the policy. A seeder, a console
        // command and an HTTP request all reach this; only one of them passes
        // through a policy.
        static::deleting(function (self $clause): void {
            if ($clause->is_system_owned) {
                throw new \RuntimeException(
                    "The clause {$clause->code} is a regulatory requirement shipped with the product and cannot "
                    .'be deleted. Where it does not apply to a particular engagement, waive it there with a '
                    .'rationale and an expiry — the waiver appears on the override register, and a deletion '
                    .'would not.'
                );
            }
        });
    }

    /** @return HasMany<ContractClause, $this> */
    public function contractClauses(): HasMany
    {
        return $this->hasMany(ContractClause::class, 'clause_library_id');
    }

    /**
     * Whether this clause applies to an engagement, given its fact context.
     *
     * A clause with NO rule applies to everything. That is the right default
     * for this library: every clause in it comes from a regulation that binds
     * the institution generally, and the rules narrow the few that are
     * conditional. A default of "applies to nothing" would mean a clause
     * whose rule was mistyped silently stops gating anything.
     *
     * @param  array<string, mixed>  $facts
     */
    public function appliesTo(array $facts, ?RuleEvaluator $evaluator = null): bool
    {
        if (empty($this->applicability_rule)) {
            return true;
        }

        return ($evaluator ?? new RuleEvaluator)->evaluate($this->applicability_rule, $facts);
    }

    /**
     * System rows plus this tenant's own — see `DocumentType::availableTo()`
     * for why the scope is dropped and immediately replaced.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailableTo(Builder $query, ?int $organizationId = null): Builder
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        return $query->withoutGlobalScope(OrganizationScope::class)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return static::query()
            ->availableTo()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Whether a tenant may edit this row at all.
     *
     * A tenant MAY edit `model_text` on a system clause — the model text is
     * theirs to draft and their lawyers' to own — but not its code, citation
     * or blocking status, which is what makes the clause the regulation rather
     * than an opinion about it.
     *
     * @return list<string>
     */
    public function tenantEditableFields(): array
    {
        return $this->is_system_owned
            ? ['model_text', 'guidance']
            : ['code', 'title', 'category', 'regulatory_source', 'citation',
                'applicability_rule', 'is_blocking', 'model_text', 'guidance', 'status'];
    }
}
