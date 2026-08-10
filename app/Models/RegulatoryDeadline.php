<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegulatoryDeadline extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'regulator', 'report_type', 'title', 'description',
        'deadline_date', 'frequency', 'status', 'responsible_id', 'notes',
    ];

    protected $casts = ['deadline_date' => 'date'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function filings()
    {
        return $this->hasMany(RegulatoryFiling::class, 'deadline_id');
    }

    public function isOverdue(): bool
    {
        return $this->deadline_date->isPast() && ! in_array($this->status, ['submitted', 'not_applicable']);
    }
}
