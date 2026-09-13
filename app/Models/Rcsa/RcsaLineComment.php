<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The ORM's challenge on one line, and the assessor's answer to it (§9.2).
 *
 * A CHALLENGE SUGGESTS, IT DOES NOT APPLY. `suggested_values` carries the
 * rating the reviewer thinks the line should have, and nothing anywhere reads
 * it back into the line. The second line challenges; the first line answers.
 * A reviewer who could overwrite the assessor's rating directly would turn a
 * self-assessment into an ORM assessment, and the audit trail would show the
 * business having said something it never said.
 */
class RcsaLineComment extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const COMMENT = 'comment';

    public const CHALLENGE = 'challenge';

    public const RESPONSE = 'response';

    protected $table = 'rcsa_line_comments';

    protected $fillable = [
        'organization_id',
        'line_id',
        'parent_id',
        'user_id',
        'type',
        'body',
        'suggested_values',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'suggested_values' => 'array',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<RcsaAssessmentLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(RcsaAssessmentLine::class, 'line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('created_at');
    }
}
