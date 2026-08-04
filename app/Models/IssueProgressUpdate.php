<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueProgressUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id',
        'update_type',
        'content',
        'created_by',
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
