<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulatoryFiling extends Model
{
    protected $fillable = [
        'deadline_id', 'filing_date', 'filed_by', 'status', 'document_ref', 'notes',
    ];

    protected $casts = ['filing_date' => 'date'];

    public function deadline() { return $this->belongsTo(RegulatoryDeadline::class, 'deadline_id'); }
    public function filer()    { return $this->belongsTo(User::class, 'filed_by'); }
}
