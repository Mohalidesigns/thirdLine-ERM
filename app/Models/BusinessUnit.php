<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessUnit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'code',
        'name',
        'description',
        'head_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function head()
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function risks()
    {
        return $this->hasMany(Risk::class);
    }

    public function controls()
    {
        return $this->hasMany(Control::class);
    }

    public function issues()
    {
        return $this->hasMany(Issue::class);
    }

    public function lossEvents()
    {
        return $this->hasMany(LossEvent::class);
    }

    public function nearMisses()
    {
        return $this->hasMany(NearMiss::class);
    }

    public function businessProcesses()
    {
        return $this->hasMany(BusinessProcess::class);
    }
}
