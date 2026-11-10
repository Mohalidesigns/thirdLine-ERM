<?php

namespace App\Models\Sm;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One source's sign-off on a scenario (ADR 0027 Amendment 1 §A1.6, plus
 * addendum-1's `warnings_acknowledged`).
 *
 * APPENDED TO, NEVER EDITED. No soft deletes and no `updated_by`: a sign-off is
 * evidence, and evidence that can be amended in place is not evidence.
 *
 * THERE IS DELIBERATELY NO UNIQUE KEY ON (sm_scenario_id, source). D4 says
 * sign-off freezes and post-sign-off edits require revalidation — so source 1
 * signs, the scenario is returned, and source 1 signs AGAIN; a unique key would
 * force an UPDATE over the record this table exists to protect. The current
 * sign-off for a source is the LATEST ROW BY `signed_at`
 * (`Scenario::currentSignoffs()`); an `is_current` flag was refused as a second
 * source of truth that drifts on the third revalidation.
 *
 * `signatory_name` and `role` are SNAPSHOTS, as ADR 0026 §5 froze `email`+`name`
 * on `kri_return_notice_recipients`: `user_id` is `nullOnDelete`, and a sign-off
 * whose signatory has left must still say who signed it. "Signed by (deleted
 * user) 4" is not evidence. `role` is a FREE-TEXT CAPACITY — "Head of
 * Operational Risk", "Second Line — Independent Challenge" — NOT a platform role
 * name, because Spatie names are an implementation detail and renaming one would
 * silently falsify every historical sign-off storing it.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $source
 * @property int|null $user_id
 * @property string $signatory_name
 * @property string $role
 * @property string $decision
 * @property bool $is_independent_challenge
 * @property string|null $comment
 * @property array<int, string> $warnings_acknowledged
 * @property \Illuminate\Support\Carbon|null $signed_at
 */
class Signoff extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scenario_signoffs';

    /**
     * The workbook's sign-off block: Source 1 / Source 2 / Source 3
     * (`VOCABULARY-EXTRACTION.md`). Frozen at three — a fourth source is a
     * vocabulary change, not a row.
     *
     * @var array<string, string> value => workbook label
     */
    public const SOURCES = [
        'source_1' => 'Source 1',
        'source_2' => 'Source 2',
        'source_3' => 'Source 3',
    ];

    /**
     * The EIGHT warning codes, frozen by addendum-1 from spec 01 §5.6.5's five
     * bullets (bullets 3–5 each carry two alternative messages, so eight codes).
     *
     * CODES ONLY. The rendered WORDING comes from `config/sm.php`
     * (`signoff.warning_messages`) and is NEVER stored: storing wording would
     * freeze text a tenant may reconfigure and duplicate §A1.8's label maps.
     *
     * SEVEN OF THE EIGHT ARE UNREACHABLE THROUGH THE PHASE 1 UI PATH, and are
     * frozen anyway. §A1.8's guard on `in_design → designed` requires ≥1 selected
     * cause and an allocation summing to 100.00, so a scenario cannot reach
     * `signed_off` with those unset, and `no_challenge_recorded` is the sole code
     * that can genuinely fire. They are kept because the sign-off record must be
     * self-describing rather than correct-by-coincidence, and because PHASE 5'S
     * EXCEL IMPORT CAN PRODUCE A SCENARIO THAT NEVER PASSED A GATE. Removing them
     * now would mean re-adding them in Phase 5, over a table that by then holds
     * signed governance records.
     *
     * CORRECTED BY ADR 0027 CORRECTION 1 §C1.1, which wins over addendum-1. The
     * addendum's eighth code was `eligible_to_transfer_not_assessed`
     * (`eligible_to_transfer IS NULL`) — a condition describing a state the schema
     * FORBIDS, because §A1.2 row 25 froze that column NOT NULL default `false` so
     * Phase 2's netting formula stays two-case. It is removed, and
     * `financial_risk_not_assessed` takes the eighth slot: `financial_risk` IS
     * nullable (§A1.2 row 24), spec 01 §5.6.5 bullet 5 warns on it, and without a
     * code the sign-off modal would silently omit one of the two flags it should
     * surface. The count stays at EIGHT and bullets 1–5 are unchanged.
     *
     * Spec 01 §5.6.5's SIXTH bullet — the advisory shown when `eligible_to_transfer`
     * is `false` — therefore has NO CODE and is UI-only, never recorded in
     * `warnings_acknowledged`. A ninth code was refused: `false` is the column's
     * default and so the state of every scenario nobody has considered, and
     * recording it would attach the same warning to most sign-offs and teach signers
     * to skip the list. Spec 03 §6.4's remedy stands instead — a permanent help line
     * on the field plus the modal advisory, with the data consequence ("nobody
     * considered transferability" is indistinguishable from "cover was checked and
     * there is none") written down for Phase 2 rather than encoded as a warning.
     *
     * BOTH this const AND `config/sm.php`'s `signoff.warning_messages` carry the
     * list, and `tests/Feature/Sm/ConfigMatchesFrozenVocabularyTest` asserts they
     * agree — addendum-3's rule that config supplies wording and the const supplies
     * the legal set. Changing one without the other fails that test, which is the
     * mechanism working.
     *
     * @var list<string>
     */
    public const WARNING_CODES = [
        // Bullet 1 — no sm_scenario_notes row of kind `challenge` or `dissent`.
        'no_challenge_recorded',
        // Bullet 2 — no selected cause.
        'no_cause_selected',
        // Bullet 3 — no allocation row.
        'allocation_missing',
        // Bullet 3 — Σ percent ≠ 100.00.
        'allocation_not_100',
        // Bullet 4 — reputational_exposure IS NULL.
        'reputational_not_assessed',
        // Bullet 4 — legal_compliance_exposure IS NULL.
        'legal_not_assessed',
        // Bullet 5 — insurance_risk IS NULL.
        'insurance_risk_not_assessed',
        // Bullet 5 — financial_risk IS NULL. C1.1: swapped in for the removed
        // `eligible_to_transfer_not_assessed`, whose condition the schema forbids.
        'financial_risk_not_assessed',
    ];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'source',
        'user_id',
        'signatory_name',
        'role',
        'decision',
        'is_independent_challenge',
        'comment',
        'warnings_acknowledged',
        'signed_at',
    ];

    /**
     * `signed_at` is `datetime`, not `date`: it is an INSTANT, and ADR 0026's
     * suffix rule reserves `_at` for one. Nullable per standard §13 — an event
     * time the application writes is never a bare `timestamp()`. Phase 1 writes a
     * row only from the sign action, so it is non-null on every row Phase 1
     * creates, and ONLY NON-NULL ROWS COUNT toward the lifecycle.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'sm_scenario_id' => 'integer',
            'user_id' => 'integer',
            'is_independent_challenge' => 'boolean',
            'signed_at' => 'datetime',
            'warnings_acknowledged' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        // Addendum-1: "the sign action ALWAYS writes an array, `[]` when nothing
        // was outstanding. NULL is never written by Phase 1." Enforced here as
        // well as in the service, so no later caller can write the NULL the
        // addendum forbids.
        static::creating(function (self $row): void {
            if ($row->warnings_acknowledged === null) {
                $row->warnings_acknowledged = [];
            }
        });
    }

    /**
     * The stored warning codes, ALWAYS an array.
     *
     * `[]` for a NULL column, which Phase 1 never writes but a Phase 5 import
     * could. A reader that renders "nothing was outstanding" for a row that
     * merely predates the column is falsifying a governance record, so the
     * fallback is here rather than in each caller.
     *
     * @return list<string>
     */
    public function warningCodes(): array
    {
        $stored = $this->warnings_acknowledged;

        if (! is_array($stored)) {
            return [];
        }

        // Codes only, and only codes this build knows. A code a later release
        // added is dropped rather than rendered as a bare string: the wording
        // lives in config, and an unmapped code has none.
        return array_values(array_filter(
            array_map('strval', $stored),
            fn (string $code) => in_array($code, self::WARNING_CODES, true)
        ));
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

    /** Nullable: the snapshot in `signatory_name` is the evidence, not this. */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
