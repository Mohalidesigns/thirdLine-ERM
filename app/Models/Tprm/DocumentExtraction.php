<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\DocumentExtractor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * What an extractor read out of a document, and whether a human has agreed
 * with it — TRD §12.1.
 *
 * `status` STARTS `pending` AND NOTHING DOWNSTREAM READS AN EXTRACTION THAT IS
 * NOT `confirmed`. That single rule is what makes the module's AI usage
 * defensible to a supervisor: the model proposes, a named person disposes, and
 * `confirmed_by`/`confirmed_at` say who and when.
 *
 * `model` and `prompt_version` are stored because an extraction that cannot be
 * reproduced cannot be explained, and because without both, a prompt
 * improvement and a model upgrade are indistinguishable when accuracy moves.
 *
 * `corrections` is the honest half. It records what the human CHANGED, which
 * is the only measurement of extractor accuracy that is not the extractor's
 * own confidence score marking its own homework.
 */
class DocumentExtraction extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_document_extractions';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'organization_id', 'document_id', 'extractor', 'model', 'prompt_version',
        'extracted', 'confidence', 'citations', 'status',
        'confirmed_by', 'confirmed_at', 'corrections',
    ];

    protected $casts = [
        'extracted' => 'array',
        'citations' => 'array',
        'corrections' => 'array',
        'confidence' => 'decimal:3',
        'confirmed_at' => 'datetime',
        'extractor' => DocumentExtractor::class,
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * A field's value, or null when the extractor did not find it.
     *
     * Reads through `extracted` rather than exposing the array, so that a
     * caller cannot accidentally read a field off an UNCONFIRMED extraction by
     * touching the attribute directly.
     */
    public function field(string $key): mixed
    {
        return data_get($this->extracted, $key);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }
}
