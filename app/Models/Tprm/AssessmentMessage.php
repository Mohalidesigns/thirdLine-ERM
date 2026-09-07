<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A message on an assessment, or on one answer within it — FR-PRT-09.
 *
 * "The single most-cited missing feature in competitor reviews." Where this
 * does not exist the conversation happens in email: the reviewer's question
 * and the vendor's answer end up in two inboxes, neither is on the record, and
 * the next reviewer a year later has the answer without the question.
 *
 * `author_type` is `internal` or `vendor` and `author_id` is deliberately NOT
 * a foreign key: a vendor author is a portal user in a different table under a
 * different guard, and a single FK would force the two populations into one.
 */
class AssessmentMessage extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_assessment_messages';

    public const AUTHOR_INTERNAL = 'internal';

    public const AUTHOR_VENDOR = 'vendor';

    protected $fillable = [
        'organization_id', 'assessment_id', 'response_id',
        'author_type', 'author_id', 'body', 'attachments', 'read_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'read_at' => 'datetime',
    ];

    protected $attributes = ['author_type' => self::AUTHOR_INTERNAL];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    /** @return BelongsTo<AssessmentResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(AssessmentResponse::class, 'response_id');
    }

    public function isFromVendor(): bool
    {
        return $this->author_type === self::AUTHOR_VENDOR;
    }
}
