<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * A piece of evidence — TRD §8.5, FR-EVD-01 through FR-EVD-04.
 *
 * "An attachment is a file someone put on a record. Evidence is a claim with
 * an issuer, a scope and an expiry date." Everything below follows from that
 * distinction: `valid_to` drives the expiry monitor and the decay of assurance;
 * `scope_text` drives the ×0.7 scope-mismatch modifier; `issuer` is what makes
 * "independently assured" a checkable claim rather than a checkbox.
 *
 * VERSIONS SUPERSEDE, THEY DO NOT OVERWRITE. A vendor's 2026 SOC 2 does not
 * delete the 2025 one — an assessment scored last year cited the older report,
 * and a citation that resolves to a document nobody can retrieve is not a
 * citation. `supersede()` links the chain and leaves both rows readable.
 *
 * `owner_type` holds a short kind (`engagement`, `third_party`, …) rather than
 * a class name, so it is NOT resolved through the application's enforced morph
 * map. That map is a global namespace, and `finding` or `incident` in it would
 * claim names the wider ERM product may want. The kinds are local to this
 * table and resolved locally, the same way `tp_waivers.waivable_type` is.
 */
class Document extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_documents';

    public const OWNER_THIRD_PARTY = 'third_party';

    public const OWNER_ENGAGEMENT = 'engagement';

    public const OWNER_ASSESSMENT = 'assessment';

    public const OWNER_CONTRACT = 'contract';

    public const OWNER_FINDING = 'finding';

    public const OWNER_INCIDENT = 'incident';

    /**
     * The owner kinds this table understands and the model each resolves to.
     *
     * Contracts, findings and incidents arrive in later phases; their entries
     * are absent rather than pointing at classes that do not exist, and
     * `ownerModel()` returns null for a kind it cannot resolve yet instead of
     * fataling. A document uploaded against a contract in Phase 6 will resolve
     * the moment that line is added.
     *
     * @return array<string, class-string<Model>>
     */
    public static function ownerModels(): array
    {
        return [
            self::OWNER_THIRD_PARTY => ThirdParty::class,
            self::OWNER_ENGAGEMENT => Engagement::class,
            self::OWNER_ASSESSMENT => Assessment::class,
        ];
    }

    protected $fillable = [
        'organization_id', 'owner_type', 'owner_id', 'document_type_id', 'title',
        'file_path', 'mime', 'size', 'hash', 'version', 'issuer', 'scope_text',
        'issue_date', 'valid_from', 'valid_to', 'confidentiality',
        'uploaded_by', 'uploaded_via', 'updated_by',
    ];

    /**
     * Not fillable, on purpose. A scan verdict, an extraction status and the
     * supersession chain are written by the code that owns them; a form post
     * must never be able to declare its own upload virus-free.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'virus_scan_status', 'extraction_status', 'is_superseded', 'superseded_by_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_superseded' => 'boolean',
        'size' => 'integer',
        'version' => 'integer',
    ];

    protected $attributes = [
        'version' => 1,
        'uploaded_via' => 'internal',
        'virus_scan_status' => 'pending',
        'extraction_status' => 'none',
        'is_superseded' => false,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The document's type.
     *
     * THE ORGANISATION SCOPE IS DROPPED HERE, and it has to be. The document
     * type catalogue is shipped with the product carrying
     * `organization_id = null`, and `BelongsToOrganization` filters to
     * `organization_id = <current>`, which excludes null — so without this,
     * every document's type resolves to null, no extractor is ever found, and
     * the expiry defaults never apply. Exactly the trap `QuestionnaireTemplate`
     * documents, one table along.
     *
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<self, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** @return HasMany<self, $this> */
    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    /** @return HasMany<DocumentExtraction, $this> */
    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class, 'document_id');
    }

    /** @return HasOne<Soc2Detail, $this> */
    public function soc2(): HasOne
    {
        return $this->hasOne(Soc2Detail::class, 'document_id');
    }

    /**
     * The record this document is evidence for, or null for a kind whose phase
     * has not landed yet.
     */
    public function ownerModel(): ?Model
    {
        $class = self::ownerModels()[$this->owner_type] ?? null;

        if ($class === null) {
            return null;
        }

        return $class::query()->find($this->owner_id);
    }

    public function ownerLabel(): string
    {
        return ucwords(str_replace('_', ' ', (string) $this->owner_type));
    }

    /* ------------------------------------------------------------------ */
    /*  Validity */
    /* ------------------------------------------------------------------ */

    /**
     * A document with no `valid_to` never expires, and that is a deliberate
     * answer rather than an oversight: a penetration test report has no expiry
     * date printed on it. The document type's `has_expiry` is what decides
     * whether a missing date is a gap worth chasing.
     */
    public function isExpired(): bool
    {
        return $this->valid_to !== null && $this->valid_to->isBefore(now()->startOfDay());
    }

    public function isCurrent(): bool
    {
        if ($this->is_superseded || $this->isExpired()) {
            return false;
        }

        return $this->valid_from === null || ! $this->valid_from->isAfter(now()->endOfDay());
    }

    /** Negative once expired, null when the document carries no expiry. */
    public function daysUntilExpiry(): ?int
    {
        if ($this->valid_to === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_to, false);
    }

    /**
     * Whether this document covers a given date — the question the SOC 2
     * period test asks. An assessment scored against a report whose period
     * ended before the assessment began is relying on evidence about a
     * different year.
     */
    public function coversDate(\DateTimeInterface $date): bool
    {
        if ($this->valid_from !== null && $this->valid_from->isAfter($date)) {
            return false;
        }

        return $this->valid_to === null || ! $this->valid_to->isBefore($date);
    }

    /**
     * Mark this document superseded by a newer one.
     *
     * Both rows survive. The version number is derived from this document
     * rather than counted, so a chain that has had a member soft-deleted does
     * not reuse a version number that a citation already refers to.
     */
    public function supersede(self $replacement): void
    {
        $replacement->forceFill(['version' => $this->version + 1])->save();

        $this->forceFill([
            'is_superseded' => true,
            'superseded_by_id' => $replacement->getKey(),
        ])->save();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_superseded', false)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', now()->toDateString()));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('is_superseded', false)
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<', now()->toDateString());
    }

    /**
     * Documents expiring within `$days`, excluding those already expired.
     *
     * The expiry monitor's windows (90/60/30/7) all come through here, so the
     * boundary is defined once: a document expiring exactly `$days` from today
     * IS in the window, because that is the day the notice is meant to fire.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->where('is_superseded', false)
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '>=', now()->toDateString())
            ->whereDate('valid_to', '<=', now()->addDays($days)->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOwner(Builder $query, string $type, int $id): Builder
    {
        return $query->where('owner_type', $type)->where('owner_id', $id);
    }
}
