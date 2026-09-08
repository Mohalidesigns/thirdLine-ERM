<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A Board and Risk Committee pack — FR-RPT-05.
 *
 * THE FIGURES ARE FROZEN ON THE ROW, and that is the whole reason this is a
 * model rather than a query. The pack a committee approved in March must
 * reprint in June exactly as it was read; a pack rendered live would restate
 * itself every quarter and the minutes would cite numbers the system can no
 * longer produce. `engine_version` records which scoring rules produced them,
 * the same discipline `tp_score_runs` applies to a single engagement.
 *
 * `narrative_source` IS NOT DECORATION. A committee reading a paragraph about
 * its own third-party exposure is entitled to know whether a person wrote it,
 * a model drafted it, or the product assembled it from the figures. Editing an
 * AI draft moves the source to `edited`, and the value is set by the service
 * that does the writing rather than by whatever posts the form.
 *
 * SIGNING OFF IS NOT PREPARING. Both are recorded, with different people and
 * different timestamps, for the same reason Phase 9 separated approving a
 * regulatory notification from recording it as submitted: assembling a
 * document and standing behind it are different decisions.
 */
class BoardPack extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_board_packs';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_SIGNED_OFF = 'signed_off';

    /** Where the narrative on the page came from. */
    public const SOURCE_DETERMINISTIC = 'deterministic';

    public const SOURCE_AI_ASSISTED = 'ai_assisted';

    public const SOURCE_EDITED = 'edited';

    protected $fillable = [
        'organization_id', 'period_label', 'as_at', 'created_by', 'updated_by',
    ];

    /**
     * Written by BoardPackService only.
     *
     * A form that could set `figures` could table a pack whose numbers nothing
     * computed, and one that could set `signed_off_by` could record an
     * approval the named person never gave.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'narrative', 'narrative_source', 'narrative_generated_at',
        'narrative_edited_by', 'narrative_edited_at', 'figures', 'engine_version',
        'prepared_by', 'prepared_at', 'signed_off_by', 'signed_off_at',
    ];

    protected $casts = [
        'as_at' => 'date',
        'figures' => 'array',
        'narrative_generated_at' => 'datetime',
        'narrative_edited_at' => 'datetime',
        'prepared_at' => 'datetime',
        'signed_off_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function signatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    /** @return BelongsTo<User, $this> */
    public function narrativeEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'narrative_edited_by');
    }

    public function isSignedOff(): bool
    {
        return $this->status === self::STATUS_SIGNED_OFF;
    }

    /**
     * Whether the figures may still move.
     *
     * A signed-off pack is refused a refresh rather than silently regenerated:
     * the committee approved a set of numbers, and quietly replacing them
     * under the same period label is how minutes stop matching the pack they
     * reference.
     */
    public function isEditable(): bool
    {
        return ! $this->isSignedOff();
    }

    /**
     * How the narrative should be labelled to a reader.
     */
    public function narrativeProvenance(): string
    {
        return match ($this->narrative_source) {
            self::SOURCE_AI_ASSISTED => 'Drafted with AI assistance from the figures in this pack, and not yet edited.',
            self::SOURCE_EDITED => 'Edited by '.($this->narrativeEditor === null ? 'a reviewer' : $this->narrativeEditor->name).'.',
            self::SOURCE_DETERMINISTIC => 'Assembled from the figures in this pack. No model was used.',
            default => 'No narrative has been drafted.',
        };
    }

    /** @param  Builder<self>  $query */
    public function scopeSignedOff(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SIGNED_OFF);
    }
}
