<?php

namespace App\Models\Sm;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One entry in a scenario's assumptions / dissent / challenge / workshop-minute
 * log (ADR 0027 Amendment 1 §A1.7).
 *
 * APPEND-ONLY. Phase 1 registers no update and no destroy route, and there is no
 * `update`/`delete` policy ability to call. A corrected note is a NEW note. This
 * is the bias-mitigation trail D4 rests on, and a dissent its author can quietly
 * edit is not a dissent trail. Enforced by the absence of routes plus a
 * store-only Form Request, not by a DB trigger — the product has none.
 *
 * `author_name` is a snapshot for §A1.6's reason: an anonymous dissent cannot be
 * followed up, which is worse than none. Identity is NOT a field on the form —
 * the author is the authenticated user and the timestamp is the server's. A note
 * whose author can be typed is not evidence.
 *
 * `kind` is `string(20)`, not a DB `enum` — ADR 0026 §2's rule: a fifth kind is
 * then a code change, not an ALTER TABLE. The four are frozen all the same.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $kind
 * @property int|null $author_id
 * @property string $author_name
 * @property string $body
 */
class Note extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scenario_notes';

    /**
     * The four frozen kinds (D4, §A1.7). `challenge` and `dissent` are the two
     * that satisfy addendum-1's `no_challenge_recorded` warning, and a
     * return-for-rework or reopen reason is written here as a `challenge` (spec
     * 01 §5.3, §5.5) — which is why the log is trustworthy: the system's own
     * entries are indistinguishable in format from a human's and equally
     * attributable.
     *
     * @var list<string>
     */
    public const KINDS = ['assumption', 'dissent', 'challenge', 'workshop_minute'];

    /** @var array<string, string> value => label */
    public const KIND_LABELS = [
        'assumption' => 'Assumption',
        'dissent' => 'Dissent',
        'challenge' => 'Challenge',
        'workshop_minute' => 'Workshop minute',
    ];

    /**
     * What each kind is FOR, rendered under the type select (spec 02 §7.1). The
     * hints are the point — they are what makes a risk officer write a useful
     * note instead of "discussed".
     *
     * @var array<string, string> value => hint
     */
    public const KIND_HINTS = [
        'assumption' => 'Something taken as true without evidence. Record it so a validator can challenge it.',
        'dissent' => 'Somebody disagreed. Record who and why — a scenario with no recorded dissent was probably not argued about.',
        'challenge' => 'A second-line or independent challenge to the design, the figures or the conclusions.',
        'workshop_minute' => 'What was decided in a session, so the decision outlives the room.',
    ];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'kind',
        'author_id',
        'author_name',
        'body',
        'is_ai_generated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'sm_scenario_id' => 'integer',
            'author_id' => 'integer',
            'is_ai_generated' => 'boolean',
        ];
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }

    /** Nullable: the snapshot in `author_name` is the evidence, not this. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
