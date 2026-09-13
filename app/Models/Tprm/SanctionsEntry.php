<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One designated person or entity.
 *
 * `normalised_name` IS FOLDED AT REFRESH, NOT AT QUERY TIME. Sanctions lists
 * carry names transliterated, punctuated and ordered inconsistently — "AL-QADI,
 * Yasin" against a subject recorded as "Yasin al-Qadi" — and searching the
 * printed form misses exactly the designations the list exists to catch.
 * Folding per query would mean a full table scan on every screening run
 * against a list of thousands.
 */
class SanctionsEntry extends Model
{
    protected $table = 'tp_sanctions_entries';

    protected $fillable = [
        'list_id', 'external_id', 'name', 'normalised_name', 'aliases',
        'entity_type', 'country', 'date_of_birth', 'programme', 'listed_on', 'raw',
    ];

    protected $casts = [
        'aliases' => 'array',
        'raw' => 'array',
        'listed_on' => 'date',
    ];

    /** @return BelongsTo<SanctionsList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(SanctionsList::class, 'list_id');
    }

    /**
     * Fold a name into the form the index stores and the search compares.
     *
     * Lower-cased, accents stripped, punctuation removed, tokens sorted. The
     * sort is what makes "AL-QADI, Yasin" and "Yasin al-Qadi" the same string;
     * without it the comma-inverted forms that sanctions lists print would
     * never match a subject recorded the way people write their own names.
     */
    public static function normalise(string $name): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $folded = $folded === false ? $name : $folded;

        $folded = strtolower($folded);
        $folded = preg_replace('/[^a-z0-9\s]/', ' ', $folded) ?? $folded;

        $tokens = array_values(array_filter(preg_split('/\s+/', $folded) ?: []));
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Every normalised spelling this entry answers to.
     *
     * @return list<string>
     */
    public function normalisedForms(): array
    {
        return array_values(array_unique(array_merge(
            [$this->normalised_name],
            array_map(fn ($alias) => self::normalise((string) $alias), (array) ($this->aliases ?? [])),
        )));
    }
}
