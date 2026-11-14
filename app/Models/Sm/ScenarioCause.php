<?php

namespace App\Models\Sm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One TICKED cause on a scenario's qualitative report (ADR 0027 Amendment 1
 * §A1.4).
 *
 * SPARSE: a row exists only for a cause somebody selected, so absence means "not
 * selected" and unticking deletes the row. Full materialisation was refused along
 * with the sync-on-save machinery it needs — unlike `kri_return_lines`, nothing
 * here is derived from a due-set that changes under the user.
 *
 * NO SOFT DELETES: a child line table, per ADR 0026 §2. `AuditTrailService`
 * records the deletion, which is the trail that matters.
 *
 * `side` and `cause_group` are DERIVED FROM `code` by the service at insert and
 * are NEVER accepted from a request — a client posting them is how a row arrives
 * `side = external` with `code = people_other`. There is deliberately no `label`
 * column: CHECKLIST is the authority, and a stored copy means a Phase 5 rename
 * updates some rows and not others, so the Excel round-trip exports two spellings
 * of one cause.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $side
 * @property string $cause_group
 * @property string $code
 * @property bool $is_selected
 * @property string|null $note
 * @property int $sort_order
 */
class ScenarioCause extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scenario_causes';

    /**
     * THE FROZEN 23-CODE CHECKLIST, in workbook row order (LS sheet cols B/C,
     * rows 32–48), as `code => [side, group, label]`.
     *
     * THIS LIST *IS* THE CHECKLIST; a code not in it is a 422 (§A1.8). Every
     * label is verbatim from `VOCABULARY-EXTRACTION.md`, because D1 makes the
     * workbook the specification and Phase 5's Excel export must round-trip it.
     *
     * Codes are GROUP-PREFIXED so `code` is globally unique and §A1.4's
     * two-column unique key works: the workbook has an "Other" in each of People,
     * Processes and Systems, and three rows sharing `code = 'other'` would make
     * one unique key impossible.
     *
     * THE ONE RECORDED DEVIATION FROM THE ARTEFACT. The workbook's external list
     * contains "Leadway Assurance company" — the reference artefact's own tenant —
     * frozen here as `ext_group_affiliate` "Group/affiliate entity", which is what
     * the extraction itself instructs. Shipping one customer's insurer name into
     * every tenant's checklist would put another bank's competitor on their
     * screen. This is Amendment 1's only departure from a verbatim workbook
     * string, and it is written down for that reason (D1).
     *
     * `config/sm.php` carries the on-screen wording — `group_label`, and the
     * `requires_note` flag spec 03 §4.1 needs — and every `code` in it must be a
     * key here. Config supplies wording, this const supplies the legal set.
     *
     * @var array<string, array{0: string, 1: string, 2: string}> code => [side, group, label]
     */
    public const CHECKLIST = [
        // Internal — People
        'people_inappropriate_resources' => ['internal', 'people', 'Inappropriate level of resources'],
        'people_inadequately_skilled' => ['internal', 'people', 'Inadequately skilled people'],
        'people_inadequately_managed' => ['internal', 'people', 'Inadequately managed people'],
        'people_intentional_act' => ['internal', 'people', 'Intentional act'],
        'people_unintentional_acts' => ['internal', 'people', 'Unintentional acts'],
        'people_other' => ['internal', 'people', 'Other'],
        // Internal — Processes
        'process_inadequately_designed' => ['internal', 'process', 'Inadequately designed process'],
        'process_ineffective' => ['internal', 'process', 'Ineffective process'],
        'process_lack_of' => ['internal', 'process', 'Lack of process'],
        'process_other' => ['internal', 'process', 'Other'],
        // Internal — Systems
        'system_inadequately_designed' => ['internal', 'system', 'Inadequately designed systems'],
        'system_inadequately_maintained' => ['internal', 'system', 'Inadequately maintained systems'],
        'system_other' => ['internal', 'system', 'Other'],
        // External — one flat group; the workbook has no sub-groups here
        'ext_natural_environment' => ['external', 'external', 'Natural environment'],
        'ext_technological' => ['external', 'external', 'Technological'],
        'ext_cultural' => ['external', 'external', 'Cultural'],
        'ext_regulatory' => ['external', 'external', 'Regulatory'],
        'ext_other' => ['external', 'external', 'Others'],
        'ext_supplier' => ['external', 'external', 'Supplier'],
        'ext_customer' => ['external', 'external', 'Customer'],
        'ext_salesforce_distribution' => ['external', 'external', 'External Salesforce / Distribution'],
        'ext_group_affiliate' => ['external', 'external', 'Group/affiliate entity'],
        'ext_unknown' => ['external', 'external', 'Unknown/yet to be determined'],
    ];

    /** @var list<string> */
    public const SIDES = ['internal', 'external'];

    /** @var list<string> */
    public const GROUPS = ['people', 'process', 'system', 'external'];

    /**
     * The codes whose selection is meaningless without a note. Spec 03 §4.1:
     * every "Other", plus "Others", plus "Unknown/yet to be determined" — whose
     * whole content IS the note. The Form Request enforces it with spec 03 §7's
     * message; `requires_note` itself is config (addendum-3) with no Phase 1
     * editing surface, so a tenant needing a 24th cause needs a config change
     * until Phase 5.
     *
     * @var list<string>
     */
    public const REQUIRES_NOTE = [
        'people_other',
        'process_other',
        'system_other',
        'ext_other',
        'ext_unknown',
    ];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'side',
        'cause_group',
        'code',
        'is_selected',
        'note',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'sm_scenario_id' => 'integer',
            'is_selected' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The frozen label for a code, or null when the code is not in the checklist. */
    public static function labelFor(string $code): ?string
    {
        return self::CHECKLIST[$code][2] ?? null;
    }

    /** Whether selecting $code without a note is a 422. */
    public static function requiresNote(string $code): bool
    {
        return in_array($code, self::REQUIRES_NOTE, true);
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
}
