<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionnaireSection extends Model
{
    protected $fillable = [
        'questionnaire_id', 'title', 'description', 'sort_order', 'weight',
    ];

    protected $casts = ['weight' => 'decimal:2'];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Questionnaire, $this> */
    public function questionnaire(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Question, $this> */
    public function questions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Question::class, 'section_id')->orderBy('sort_order');
    }
}
