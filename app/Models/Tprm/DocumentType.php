<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\DocumentExtractor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The evidence catalogue — what kinds of document exist, which extractor reads
 * them, and how far each can raise an answer's assurance.
 *
 * `is_assurance_evidence` is the column that stops the module's central claim
 * being gamed. A signed NDA is a document; it is not evidence that a control
 * operates, and without this flag any upload against any question would raise
 * its confidence coefficient. `default_assurance_level` then caps how far:
 * a policy PDF reaches `documented` and stops there, whatever the reviewer
 * would like it to mean.
 *
 * Rows with a null `organization_id` are the system catalogue, shared by every
 * tenant — and the tenancy global scope HIDES THEM, because it filters to
 * `organization_id = <current>` and null is not that. So the trait is applied
 * (a table with an `organization_id` column may not opt out of it; that rule
 * is enforced by `TenancyIsolationTest` and is worth more than this model's
 * convenience) and every read that should see the system catalogue goes
 * through `availableTo()`, which drops the organisation filter DELIBERATELY
 * and re-applies the correct one: system rows plus this tenant's own. The same
 * shape as `QuestionnaireTemplate`, for the same reason.
 */
class DocumentType extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_document_types';

    protected $fillable = [
        'organization_id', 'code', 'name', 'category',
        'has_expiry', 'default_validity_months', 'extractor',
        'is_assurance_evidence', 'default_assurance_level', 'is_active',
    ];

    protected $casts = [
        'has_expiry' => 'boolean',
        'is_assurance_evidence' => 'boolean',
        'is_active' => 'boolean',
        'extractor' => DocumentExtractor::class,
        'default_assurance_level' => AssuranceLevel::class,
    ];

    protected $attributes = [
        'has_expiry' => false,
        'is_assurance_evidence' => false,
        'is_active' => true,
        'extractor' => 'generic',
    ];

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'document_type_id');
    }

    /**
     * System rows plus this tenant's own.
     *
     * The organisation scope is dropped and immediately replaced, rather than
     * simply removed: a caller that wanted every tenant's document types would
     * have to say so explicitly somewhere this method is not.
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
     * Resolve a route-bound type against the system catalogue as well as the
     * tenant's own, for the same reason `QuestionnaireTemplate` does: without
     * it, opening a shipped document type 404s.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::query()
            ->availableTo()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The highest assurance level a document of this type can support.
     *
     * A type that is not assurance evidence supports none at all — and that is
     * `null` rather than `SelfAttested`, because "this document proves nothing
     * about the control" and "the vendor asserted it" are different claims.
     */
    public function assuranceCeiling(): ?AssuranceLevel
    {
        if (! $this->is_assurance_evidence) {
            return null;
        }

        return $this->default_assurance_level ?? AssuranceLevel::Documented;
    }
}
