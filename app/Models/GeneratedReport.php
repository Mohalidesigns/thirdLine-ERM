<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneratedReport extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'generated_by',
        'name',
        'report_type',
        'scope',
        'period',
        'period_as_at',
        'status',
        'progress_pct',
        'error_message',
        'started_at',
        'completed_at',
        'file_name',
        'format',
        'disk',
        'file_path',
        'mime_type',
        'size_bytes',
        'version',
        'download_route',
        'parameters',
    ];

    protected $casts = [
        'parameters' => 'array',
        'period_as_at' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress_pct' => 'integer',
        'size_bytes' => 'integer',
        'version' => 'integer',
    ];

    /**
     * Whether a stored artifact exists to download.
     *
     * A completed row without a file_path is a legacy row from before reports
     * were rendered to storage; those still fall back to re-running
     * download_route.
     */
    public function hasStoredFile(): bool
    {
        return $this->status === 'completed' && ! empty($this->file_path);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }

    public function getSizeForHumansAttribute(): ?string
    {
        if (! $this->size_bytes) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function getTypeAttribute(): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $this->report_type));
    }

    public function getDownloadUrlAttribute(): ?string
    {
        // A stored artifact is served as-is, byte for byte. Only legacy rows
        // that never had one fall back to re-running the generating route —
        // which produces a *different* document if the data has since moved.
        if ($this->hasStoredFile()) {
            return route('risk.reports.download', $this);
        }

        if (! $this->download_route) {
            return null;
        }
        try {
            // Pass the stored parameters as query string so the re-run
            // reproduces the same filters the user originally chose.
            $params = is_array($this->parameters) ? $this->parameters : [];

            return route($this->download_route, $params);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
