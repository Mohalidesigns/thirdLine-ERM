<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A section of a questionnaire template.
 *
 * No tenancy trait: a section belongs to its template and is scoped through it.
 * `domain_tag` is what the domain-level scores in FR-ASM-10 group by, and it is
 * deliberately free text rather than an enum — a tenant's own pack groups by
 * whatever its risk function thinks in.
 */
class QuestionnaireSection extends Model
{
    protected $table = 'tp_questionnaire_sections';

    protected $fillable = [
        'template_id', 'code', 'title', 'description', 'sort_order',
        'weight', 'domain_tag', 'visibility_rule',
    ];

    protected $casts = [
        'visibility_rule' => 'array',
        'weight' => 'decimal:3',
    ];

    /** @return BelongsTo<QuestionnaireTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class, 'template_id');
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'section_id')->orderBy('sort_order');
    }
}
