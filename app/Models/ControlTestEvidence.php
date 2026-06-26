<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControlTestEvidence extends Model
{
    protected $table = 'control_test_evidence';

    protected $fillable = [
        'control_test_id', 'file_name', 'file_path', 'file_type', 'file_size',
        'description', 'uploaded_by',
    ];

    public function controlTest() { return $this->belongsTo(ControlTest::class); }
    public function uploader()    { return $this->belongsTo(User::class, 'uploaded_by'); }
    // Alias used by the unified DocumentRepository.
    public function uploadedBy()  { return $this->uploader(); }
}
