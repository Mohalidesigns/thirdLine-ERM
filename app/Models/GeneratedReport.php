<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneratedReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'generated_by',
        'name',
        'report_type',
        'scope',
        'period',
        'file_name',
        'download_route',
        'parameters',
    ];

    protected $casts = [
        'parameters' => 'array',
    ];

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
