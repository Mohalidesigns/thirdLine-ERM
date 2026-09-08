<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Something an exercise, a call-tree test, a DR test or an incident turned up.
 *
 * The four producers are four nullable foreign keys rather than a morph, because
 * the database can check a foreign key and cannot check that a `source_type`
 * string names a real table — and a finding pointing at a deleted AAR is
 * exactly the row an examiner asks about.
 *
 * Track A owns the service and the register (Orchestration §5). Producers only
 * CREATE findings.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $reference
 * @property ?\App\Enums\Bcms\FindingSource $source
 * @property ?int $aar_id
 * @property ?int $incident_id
 * @property ?int $call_tree_test_id
 * @property ?int $dr_test_id
 * @property ?int $management_review_id
 * @property \App\Enums\Bcms\FindingClassification $classification
 * @property ?string $severity
 * @property string $description
 * @property ?string $root_cause
 * @property ?int $affected_plan_id
 * @property ?int $affected_process_id
 * @property ?int $affected_business_unit_id
 * @property ?string $iso_clause_ref
 * @property string $status
 * @property ?int $raised_by
 * @property ?\Illuminate\Support\Carbon $raised_at
 * @property ?\Illuminate\Support\Carbon $closed_at
 * @property ?int $erm_issue_id
 * @property bool $ai_generated
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Finding extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_findings';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'reference', 'source', 'management_review_id', 'aar_id', 'incident_id', 'call_tree_test_id', 'dr_test_id',
        'classification', 'severity', 'description', 'root_cause', 'affected_plan_id',
        'affected_process_id', 'affected_business_unit_id', 'iso_clause_ref', 'status',
        'raised_by', 'raised_at', 'closed_at', 'erm_issue_id', 'ai_generated', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'aar_id' => 'integer',
            'incident_id' => 'integer',
            'call_tree_test_id' => 'integer',
            'dr_test_id' => 'integer',
            'affected_plan_id' => 'integer',
            'affected_process_id' => 'integer',
            'affected_business_unit_id' => 'integer',
            'raised_by' => 'integer',
            'raised_at' => 'datetime',
            'closed_at' => 'datetime',
            'erm_issue_id' => 'integer',
            'ai_generated' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'classification' => FindingClassification::class,
            'source' => FindingSource::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Aar, $this> */
    public function aar(): BelongsTo
    {
        return $this->belongsTo(Aar::class, 'aar_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /** @return BelongsTo<CallTreeTest, $this> */
    public function callTreeTest(): BelongsTo
    {
        return $this->belongsTo(CallTreeTest::class, 'call_tree_test_id');
    }

    /** @return BelongsTo<DrTest, $this> */
    public function drTest(): BelongsTo
    {
        return $this->belongsTo(DrTest::class, 'dr_test_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function affectedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'affected_plan_id');
    }

    /** @return BelongsTo<Process, $this> */
    public function affectedProcess(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'affected_process_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'affected_business_unit_id');
    }

    /** @return HasMany<CorrectiveAction, $this> */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'finding_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function ermIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'erm_issue_id');
    }

    /** @return BelongsTo<ManagementReview, $this> */
    public function managementReview(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }
}
