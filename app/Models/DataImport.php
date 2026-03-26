<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataImport extends Model
{
    protected $fillable = [
        'organization_id', 'import_type', 'file_name', 'file_path',
        'total_rows', 'success_count', 'error_count', 'skipped_count',
        'column_mapping', 'errors', 'status', 'imported_by', 'completed_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'errors'         => 'array',
        'completed_at'   => 'datetime',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function importer()     { return $this->belongsTo(User::class, 'imported_by'); }
}
