<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One execution of one connector.
 *
 * records_read and records_written are separate on purpose: a run that read
 * four hundred rows and wrote none is a mapping problem, and a run that read
 * none is a source problem. Collapsing them into one "records" count loses the
 * distinction that tells an operator which of the two to go and look at.
 */
class ConnectorRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'connector_id', 'trigger', 'dry_run', 'status',
        'started_at', 'finished_at', 'records_read', 'records_written',
        'records_skipped', 'errors', 'reconciliation', 'triggered_by', 'job_run_id',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'errors' => 'array',
        'reconciliation' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'records_read' => 'integer',
        'records_written' => 'integer',
        'records_skipped' => 'integer',
    ];

    public function connector()
    {
        return $this->belongsTo(Connector::class);
    }

    public function triggerer()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function durationSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return round($this->started_at->diffInMilliseconds($this->finished_at) / 1000, 1);
    }

    /**
     * The line an operator actually reads.
     *
     * "Read 412, wrote 0" is the message that matters; a green tick over a run
     * that imported nothing is how a KRI quietly stops being measured.
     */
    public function summary(): string
    {
        if ($this->status === 'failed') {
            return 'Failed: '.\Illuminate\Support\Str::limit((string) collect($this->errors)->first(), 120);
        }

        $summary = sprintf(
            'Read %d, wrote %d',
            $this->records_read,
            $this->records_written,
        );

        if ($this->records_skipped > 0) {
            $summary .= ', skipped '.$this->records_skipped;
        }

        if ($this->records_read > 0 && $this->records_written === 0 && ! $this->dry_run) {
            $summary .= ' — nothing matched the field mapping.';
        }

        return $summary.($this->dry_run ? ' (dry run — nothing was written)' : '');
    }
}
