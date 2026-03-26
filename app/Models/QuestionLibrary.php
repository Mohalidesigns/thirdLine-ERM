<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionLibrary extends Model
{
    protected $table = 'question_library';

    protected $fillable = [
        'organization_id', 'category', 'question_text', 'question_type',
        'default_options', 'tags', 'usage_count', 'is_global',
    ];

    protected $casts = [
        'default_options' => 'array',
        'tags'            => 'array',
        'is_global'       => 'boolean',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
}
