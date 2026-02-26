<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueProgressUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id',
        'update_date',
        'progress_pct',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'update_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user()
    {
        return $this->creator();
    }
}
