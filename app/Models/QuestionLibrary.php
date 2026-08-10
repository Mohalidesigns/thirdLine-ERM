<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

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

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
