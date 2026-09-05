<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegulatoryFiling extends Model
{
    protected $fillable = [
        'deadline_id', 'filing_date', 'filed_by', 'status', 'document_ref', 'notes',
    ];

    protected $casts = ['filing_date' => 'date'];

    /** @return BelongsTo<RegulatoryDeadline, $this> */
    public function deadline(): BelongsTo
    {
        return $this->belongsTo(RegulatoryDeadline::class, 'deadline_id');
    }

    /** @return BelongsTo<User, $this> */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
