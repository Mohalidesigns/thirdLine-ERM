<?php

namespace App\Models\Tprm;

use App\Models\Control;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question's mapping to a framework control — FR-ASM-05.
 *
 * The row whose absence blocks publication. `framework_version` travels with
 * every mapping because TRD §4.3 divides these identifiers into stable and
 * unstable: an ISO 27002 control ID means the same thing across revisions, a
 * SIG domain letter does not, and a mapping against the second kind has to be
 * re-verified when the version moves.
 */
class QuestionControlMap extends Model
{
    protected $table = 'tp_question_control_maps';

    public const RELATIONSHIP_PRIMARY = 'primary';

    public const RELATIONSHIP_SUPPORTING = 'supporting';

    protected $fillable = [
        'question_id', 'framework', 'framework_version', 'control_id',
        'relationship', 'internal_control_id',
    ];

    protected $attributes = [
        'relationship' => self::RELATIONSHIP_PRIMARY,
    ];

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    /**
     * The institution's OWN control this vendor question bears on, where one
     * is mapped — so a vendor control gap surfaces against the control it
     * undermines on our side (TRD §15).
     *
     * @return BelongsTo<Control, $this>
     */
    public function internalControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'internal_control_id');
    }
}
