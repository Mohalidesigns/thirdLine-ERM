<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionnaireSection extends Model
{
    protected $fillable = [
        'questionnaire_id', 'title', 'description', 'sort_order', 'weight',
    ];

    protected $casts = ['weight' => 'decimal:2'];

    public function questionnaire() { return $this->belongsTo(Questionnaire::class); }
    public function questions()     { return $this->hasMany(Question::class, 'section_id')->orderBy('sort_order'); }
}
