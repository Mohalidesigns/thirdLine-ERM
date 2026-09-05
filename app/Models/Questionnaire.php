<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Questionnaire extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'title', 'description', 'version', 'status',
        'scoring_method', 'questionnaire_type', 'created_by',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Organization, $this> */
    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<QuestionnaireSection, $this> */
    public function sections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuestionnaireSection::class)->orderBy('sort_order');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasManyThrough<Question, QuestionnaireSection, $this> */
    public function questions(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Question::class, QuestionnaireSection::class, 'questionnaire_id', 'section_id');
    }

    public function questionCount(): int
    {
        return $this->questions()->count();
    }
}
