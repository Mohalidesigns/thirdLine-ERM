<?php

namespace App\Models\Rcsa;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A row of the RCSA Universe — workbook columns A to I, plus its controls.
 *
 * This is MASTER DATA, not an assessment. It says a risk exists in a place; it
 * says nothing about how likely or how severe anyone thinks it is this quarter.
 * That separation is the point of the module: opening a cycle copies published
 * rows into `rcsa_assessment_lines`, so a business unit finds its processes,
 * risks and controls already populated instead of retyping them, and the
 * assessment's copy is then immune to later edits here.
 *
 * `default_likelihood` and `default_impact` sit awkwardly close to being an
 * assessment and are not one: nothing scores, reports or bands off them. They
 * are a starting position a cycle copies into the line, which the assessor then
 * confirms or changes. If they ever appear on a dashboard, that is a defect.
 *
 * @property-read Collection<int, RcsaRegisterControl> $controls
 */
class RcsaRegisterRisk extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    /** @var list<string> */
    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::RETIRED];

    protected $table = 'rcsa_register_risks';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'process_id',
        'sub_process_id',
        'risk_no',
        'potential_risk',
        'risk_driver',
        'risk_category',
        'secondary_categories',
        'system_ids',
        'default_likelihood',
        'default_impact',
        'status',
        'version',
        'published_at',
        'published_by',
        'source_batch_id',
        'row_hash',
        'owner_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'secondary_categories' => 'array',
        'system_ids' => 'array',
        'default_likelihood' => 'integer',
        'default_impact' => 'integer',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // The hash is derived, never supplied. Recomputed on every save because
        // an edit to the risk statement or a move to another process changes
        // what the row IS, and a stale hash would make the next import treat a
        // genuine duplicate as new.
        static::saving(function (self $model) {
            $model->row_hash = self::hashFor(
                (int) $model->business_unit_id,
                $model->process_id !== null ? (int) $model->process_id : null,
                $model->sub_process_id !== null ? (int) $model->sub_process_id : null,
                (string) $model->potential_risk,
            );
        });
    }

    /**
     * The duplicate key: the same risk, stated in the same place.
     *
     * THE PLAN SPECIFIES THIS OVER NAMES — `sha256(lower(trim(bu + process +
     * sub_process + potential_risk)))`. It is computed over resolved IDs
     * instead, because the names are not the identity: renaming "Customer
     * Onboarding" to "Client Onboarding" would make every risk under it look
     * new to the next upload, and two units may legitimately run processes with
     * the same name. The import resolves business unit and process before
     * hashing, so both sides still compute the same value for the same row —
     * which is the only property the hash needs.
     */
    public static function hashFor(int $businessUnitId, ?int $processId, ?int $subProcessId, string $potentialRisk): string
    {
        $statement = Str::lower(trim(preg_replace('/\s+/', ' ', $potentialRisk) ?? ''));

        return hash('sha256', implode('|', [
            $businessUnitId,
            $processId ?? '',
            $subProcessId ?? '',
            $statement,
        ]));
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function subProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'sub_process_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<RcsaRegisterControl, $this> */
    public function controls(): HasMany
    {
        return $this->hasMany(RcsaRegisterControl::class, 'register_risk_id')->orderBy('sort_order');
    }

    /* ------------------------------------------------------------------ */
    /*  State */
    /* ------------------------------------------------------------------ */

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /**
     * Only published rows are provisioned into a cycle.
     *
     * This scope is the enforcement of rule 1 of the process flow — the system
     * populates process, risk and control from APPROVED master data. Anything
     * that reads the universe on behalf of an assessment goes through here
     * rather than filtering by hand, so there is one place to be wrong.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeAssessable($query): void
    {
        $query->where('status', self::PUBLISHED);
    }
}
