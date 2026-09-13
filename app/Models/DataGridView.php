<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's named snapshot of a data grid's state (WP-09). See the
 * migration for what "state" holds; the DataGrid component is the only
 * writer.
 */
class DataGridView extends Model
{
    protected $fillable = ['user_id', 'grid', 'name', 'state', 'is_default'];

    protected $casts = [
        'state' => 'array',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
