<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class DataImport extends Model
{
    use BelongsToOrganization;

    /**
     * The states an import passes through.
     *
     * The column was an enum of four and the pipeline wrote six; see migration
     * 2026_09_05_140000. The list lives here now so the screens, the job and
     * the processor read one definition rather than keeping a database enum in
     * step with the code by hand.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'queued', 'processing', 'completed', 'failed', 'cancelled'];

    /**
     * What a column mapping is allowed to name, per import type.
     *
     * THE ONE LIST. The mapping screen offers these; ProcessImportRequest
     * accepts only these; DataImportProcessor writes only these. Until Phase
     * 5.5 the screen's copy lived on the controller and the validator had NO
     * rule on the mapping's keys at all — and those keys become the attribute
     * names in `Model::create()`, where `organization_id` is fillable on every
     * one of these models and BelongsToOrganization deliberately leaves an
     * explicitly-set id alone. A mapping naming it wrote the spreadsheet into
     * whatever institution the column contained.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS_BY_TYPE = [
        'risks' => ['title', 'description', 'category_id', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'risk_owner_id', 'status'],
        'controls' => ['name', 'description', 'control_type', 'control_nature', 'frequency', 'automation_level', 'effectiveness_rating', 'status'],
        'loss_events' => ['title', 'description', 'date_of_loss', 'gross_loss_amount_kobo', 'basel_l1_category', 'event_severity'],
        'issues' => ['title', 'description', 'issue_source', 'issue_category', 'priority', 'issue_status', 'remediation_due_date'],
        'kris' => ['name', 'description', 'measurement_frequency', 'baseline_value', 'green_threshold', 'amber_threshold', 'red_threshold'],
    ];

    /**
     * The import types, and how the screens name them.
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        'risks' => 'Risk register',
        'controls' => 'Controls',
        'loss_events' => 'Loss events',
        'issues' => 'Issues',
        'kris' => 'Key risk indicators',
    ];

    /** @return list<string> */
    public static function fieldsFor(string $type): array
    {
        return self::FIELDS_BY_TYPE[$type] ?? [];
    }

    protected $fillable = [
        'organization_id', 'import_type', 'file_name', 'file_path',
        'total_rows', 'success_count', 'error_count', 'skipped_count',
        'column_mapping', 'errors', 'status', 'imported_by', 'completed_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'errors' => 'array',
        'completed_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
