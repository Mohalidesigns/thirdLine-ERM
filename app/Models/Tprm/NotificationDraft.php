<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\Regulator;
use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A pre-filled regulatory notification — TRD §12.7.
 *
 * IT IS NEVER SENT FROM HERE. The draft exists so a compliance officer under a
 * twenty-four-hour clock starts from a document rather than a blank page;
 * `submitted_at` is a person recording that THEY sent it, through whatever
 * channel the regulator actually uses. A product that transmitted to a
 * supervisor on a rule's say-so would be one bug away from filing a
 * notification that was not true, in the bank's name.
 *
 * `gaps` IS AS IMPORTANT AS `body`. A draft assembled from an incident three
 * hours old is missing most of what a regulator asks for, and a document that
 * reads fluently while omitting the number of data subjects invites somebody
 * to send it as it stands. The gaps are listed above the text, not buried.
 *
 * APPROVAL AND SUBMISSION ARE TWO ACTS. Approving says the words are right;
 * submitting says it has gone. Collapsing them would mean the register cannot
 * distinguish a draft that was cleared and then missed from one nobody read.
 */
class NotificationDraft extends Model
{
    use BelongsToOrganization, HasTprmUuid, TprmAuditable;

    protected $table = 'tp_notification_drafts';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'organization_id', 'incident_id', 'regulator', 'title', 'body', 'facts', 'gaps', 'created_by',
    ];

    /**
     * Every one of these is an act by a named person, and none may be posted
     * by a form that merely renders the draft.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'approved_by', 'approved_at', 'submitted_at', 'submission_reference',
    ];

    protected $casts = [
        'regulator' => Regulator::class,
        'facts' => 'array',
        'gaps' => 'array',
        'approved_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Whether this draft may be marked submitted.
     *
     * APPROVAL FIRST, ALWAYS. The whole value of the two-step is that somebody
     * read the words before the bank's name went on them.
     */
    public function canBeSubmitted(): bool
    {
        return $this->isApproved() && ! $this->isSubmitted();
    }
}
