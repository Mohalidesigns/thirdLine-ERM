<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class QuestionLibrary extends Model
{
    use BelongsToOrganization;

    protected $table = 'question_library';

    /**
     * The question library intentionally holds shared, system-wide questions
     * (organization_id NULL, is_global true) alongside each tenant's own.
     */
    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id', 'category', 'question_text', 'question_type',
        'default_options', 'tags', 'usage_count', 'is_global',
    ];

    protected $casts = [
        'default_options' => 'array',
        'tags' => 'array',
        'is_global' => 'boolean',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Organization, $this> */
    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
