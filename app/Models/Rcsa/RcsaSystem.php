<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An application a risk touches — workbook column E.
 *
 * Deliberately thin. It exists so that "which risks touch Finacle" is a query
 * rather than a search through free text, and it is expected to be superseded:
 * when the product grows a real application register, `system_ids` becomes a
 * foreign key set against that and these rows migrate into it. Nothing is built
 * here that would make that migration expensive.
 */
class RcsaSystem extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'rcsa_systems';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'owner_id',
        'is_active',
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

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
