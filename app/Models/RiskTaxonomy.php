<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskTaxonomy extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'description', 'framework', 'parent_id',
        'sort_order', 'depth', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function parent()       { return $this->belongsTo(self::class, 'parent_id'); }
    public function children()     { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order'); }
}
