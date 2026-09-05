<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskTaxonomy extends Model
{
    use BelongsToOrganization;

    /**
     * The frameworks a node may be tagged against.
     *
     * `framework` is a free string column; this is the list the add-node form
     * has always offered, lifted out of the Blade template so the form and any
     * future validator read the same one.
     *
     * @var list<string>
     */
    public const FRAMEWORKS = ['Basel III', 'COSO ERM', 'ISO 31000', 'CBN ORMS'];

    protected $fillable = [
        'organization_id', 'name', 'description', 'framework', 'parent_id',
        'sort_order', 'depth', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
