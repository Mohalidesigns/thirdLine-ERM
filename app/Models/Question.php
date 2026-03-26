<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'section_id', 'question_type', 'question_text', 'options', 'scoring_rules',
        'is_required', 'sort_order', 'conditional_logic', 'help_text', 'weight',
    ];

    protected $casts = [
        'options'           => 'array',
        'scoring_rules'     => 'array',
        'conditional_logic' => 'array',
        'is_required'       => 'boolean',
        'weight'            => 'decimal:2',
    ];

    public function section() { return $this->belongsTo(QuestionnaireSection::class, 'section_id'); }
}
