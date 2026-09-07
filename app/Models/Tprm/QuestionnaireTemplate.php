<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * A questionnaire template — FR-ASM-01.
 *
 * `organization_id` IS NULLABLE, and null means a pack shipped with the
 * product: readable by every tenant, editable by none. A tenant customises one
 * by CLONING it, which is what "templates may be cloned and tenant-customised
 * without breaking historical responses" requires — editing the shared pack in
 * place would rewrite the questions behind every assessment ever answered
 * against it, in every tenant.
 *
 * PUBLISHING IS BLOCKED WHILE ANY QUESTION IS UNMAPPED (FR-ASM-05). That gate
 * is not bookkeeping: it is "the mechanism that keeps questionnaires short".
 * An author who must justify every question against a named control in
 * ISO 27002, 800-53, CSF, CCM or the TSC writes forty questions; an author who
 * need not writes two hundred and sixty, and the vendor answers none of them
 * carefully.
 */
class QuestionnaireTemplate extends Model
{
    use BelongsToOrganization, SoftDeletes, TprmAuditable;

    protected $table = 'tp_questionnaire_templates';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    /** @var list<string> */
    public const SCORING_MODES = ['weighted', 'pass_fail', 'hybrid'];

    protected $fillable = [
        'organization_id', 'code', 'name', 'description', 'framework_tags',
        'version', 'status', 'applies_to', 'scoring_mode', 'published_at',
        'parent_template_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'framework_tags' => 'array',
        'applies_to' => 'array',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'scoring_mode' => 'weighted',
        'version' => '1.0',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            // A published template is frozen: its questions are what a
            // historical assessment was answered against, and `template_version`
            // on the assessment promises they can still be fetched.
            if ($template->getOriginal('status') !== self::STATUS_PUBLISHED) {
                return;
            }

            $frozen = array_intersect(array_keys($template->getDirty()), ['code', 'version', 'scoring_mode']);

            if ($frozen !== []) {
                throw new RuntimeException(
                    "Template {$template->code} v{$template->version} is published and cannot be changed "
                    .'('.implode(', ', $frozen).'). Clone it and publish a new version instead.'
                );
            }
        });
    }

    /**
     * Route binding admits the shipped packs.
     *
     * THE TENANCY GLOBAL SCOPE HIDES THEM, and that is the one thing about
     * this table that catches everybody. A shipped pack carries
     * `organization_id = null` deliberately — it belongs to no tenant and is
     * readable by all of them — but `BelongsToOrganization` filters to
     * `organization_id = <current>`, which excludes null. Without this
     * override, opening a shipped pack 404s, cloning one 404s, and an
     * assessment's `template` relation resolves to null so the console throws
     * reading its name.
     *
     * The scope is dropped only for the organisation filter, never for soft
     * deletes: a retired template stays gone.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where(function (Builder $query) {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', TenantContext::organizationIdOrNull());
            })
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Shipped packs plus this tenant's own.
     *
     * The global scope filters to the tenant, so a null-organisation row would
     * be invisible without this — which is why the system packs are read
     * through it rather than through the plain query.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailableTo(Builder $query, ?int $organizationId): void
    {
        // Only the ORGANISATION scope is dropped. `withoutGlobalScopes()`
        // would take the soft-delete scope with it and resurrect retired
        // templates into the issue screen's list.
        $query->withoutGlobalScope(OrganizationScope::class)
            ->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId));
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<QuestionnaireSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(QuestionnaireSection::class, 'template_id')->orderBy('sort_order');
    }

    /** @return BelongsTo<self, $this> */
    public function parentTemplate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether this is a pack shipped with the product rather than a tenant's own. */
    public function isSystemPack(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * The questions that block publication — FR-ASM-05.
     *
     * @return \Illuminate\Support\Collection<int, Question>
     */
    public function unmappedQuestions()
    {
        return Question::query()
            ->whereIn('section_id', $this->sections()->pluck('id'))
            ->whereDoesntHave('controlMaps')
            ->get();
    }

    public function canPublish(): bool
    {
        return $this->sections()->exists() && $this->unmappedQuestions()->isEmpty();
    }
}
