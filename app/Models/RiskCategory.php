<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RiskCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'code',
        'name',
        'description',
        'level',
        'path',
        'cbn_mapping_code',
        'basel_category',
        'assessment_criteria',
        'is_active',
        'sort_order',
        'icon',
        'color',
        'created_by',
    ];

    protected $casts = [
        'assessment_criteria' => 'array',
        'is_active'           => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function risks()
    {
        return $this->hasMany(Risk::class, 'category_id');
    }

    public function appetiteStatements()
    {
        return $this->hasMany(RiskAppetite::class, 'risk_category_id');
    }
}
