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

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections()
    {
        return $this->hasMany(QuestionnaireSection::class)->orderBy('sort_order');
    }

    public function questions()
    {
        return $this->hasManyThrough(Question::class, QuestionnaireSection::class, 'questionnaire_id', 'section_id');
    }

    public function questionCount(): int
    {
        return $this->questions()->count();
    }
}
