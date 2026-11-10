<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Note;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioCause;
use App\Models\Sm\Signoff;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Addendum-3's split, asserted: **config/sm.php supplies the WORDING, the model consts supply
 * the legal VALUE SET.**
 *
 * "Every `value` and every `code` in it must be one of §A1.8's frozen const values, and a test
 * asserts that — config supplies wording, the consts supply the legal set. That split is the
 * whole point: a tenant editing config can rename a scale point but CANNOT make the database
 * hold a value no code knows."
 *
 * This is the test both docblocks point at (`Signoff::WARNING_CODES` and the config header).
 * Without it the two lists drift silently, and the failure is not a crash: it is a warning code
 * with no message, a cause the picker offers and the Form Request refuses, or a transition
 * button whose label nobody can render — "a label the server does not send does not render: the
 * shell has no fallback vocabulary" (spec 01 §5.2).
 *
 * Asserted in BOTH DIRECTIONS where it matters. Config holding a value the const lacks would
 * put an unstorable value on a screen; a const value config does not describe would render as a
 * bare snake_case string.
 */
class ConfigMatchesFrozenVocabularyTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  The exposure scales */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_reputational_scale_in_config_is_exactly_the_frozen_value_set(): void
    {
        $options = (array) config('sm.exposures.reputational.options');

        $this->assertSame(
            array_keys(Scenario::REPUTATIONAL_EXPOSURES),
            array_column($options, 'value'),
            'config must carry the six frozen values, in the const\'s order — the ordinal lives in the array order and nowhere else (spec 03 §12.8)'
        );

        // Five graded levels plus `no_impact`, and NOT `LossEvent::SEVERITIES`: the workbook's
        // top level is `severe`, and adopting the loss-event list would rename a scale a customer
        // already signed off (§A1.8).
        $this->assertNotContains('catastrophic', array_column($options, 'value'));
        $this->assertContains('severe', array_column($options, 'value'));
        $this->assertContains('no_impact', array_column($options, 'value'));

        $this->assertSame(
            Scenario::REPUTATIONAL_UNSET_LABEL,
            config('sm.exposures.reputational.unset_label'),
            "the unset label is the workbook's own string and the two scales' unset labels differ"
        );

        foreach ($options as $option) {
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('description', $option, 'a null description must be explicit, not absent, or the UI cannot tell "no description" from "missing key"');
        }
    }

    #[Test]
    public function the_legal_scale_in_config_is_exactly_the_frozen_value_set_and_ships_without_descriptions(): void
    {
        $options = (array) config('sm.exposures.legal.options');

        $this->assertSame(
            array_keys(Scenario::LEGAL_COMPLIANCE_EXPOSURES),
            array_column($options, 'value')
        );

        // `no`, NOT `no_impact`: the two dropdowns literally differ in the workbook, and D1 plus
        // Phase 5's round-trip require the stored value to map back to that exact string (§A1.8).
        $this->assertContains('no', array_column($options, 'value'));
        $this->assertNotContains('no_impact', array_column($options, 'value'));

        // Spec 03 §6.3: "The legal scale ships labels only." The five graded descriptions belong
        // to the REPUTATIONAL dropdown; copying them here would put words in the artefact's mouth
        // about legal exposure that it never says.
        foreach ($options as $option) {
            $this->assertNull(
                $option['description'],
                "{$option['value']} must not carry a description — the workbook's legal dropdown is a bare list of six labels"
            );
        }

        $this->assertSame(Scenario::LEGAL_UNSET_LABEL, config('sm.exposures.legal.unset_label'));
    }

    /* ------------------------------------------------------------------ */
    /*  The cause checklist */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_checklist_in_config_is_the_frozen_twenty_three_codes_in_workbook_order(): void
    {
        $checklist = (array) config('sm.causes.checklist');

        $this->assertCount(23, $checklist, '§A1.8 froze exactly 23 codes');
        $this->assertSame(
            array_keys(ScenarioCause::CHECKLIST),
            array_column($checklist, 'code'),
            'config must carry every frozen code, and in the const order — sort is what freezes report and export row order'
        );

        foreach ($checklist as $item) {
            $code = (string) $item['code'];
            [$side, $group] = ScenarioCause::CHECKLIST[$code];

            // `side` and `cause_group` are COLUMN VALUES derived from the code by the service at
            // insert (§A1.4), so config must agree with the const rather than be free to differ —
            // a config that said `external` for `people_other` would put a row on screen the
            // service would never be able to write.
            $this->assertSame($side, $item['side'], "{$code}.side");
            $this->assertSame($group, $item['cause_group'], "{$code}.cause_group");

            $this->assertContains($item['side'], ScenarioCause::SIDES, "{$code}.side is not a frozen side");
            $this->assertContains($item['cause_group'], ScenarioCause::GROUPS, "{$code}.cause_group is not a frozen group");
            $this->assertIsBool($item['requires_note'], "{$code}.requires_note");
            $this->assertIsInt($item['sort'], "{$code}.sort");
            $this->assertNotSame('', $item['label'], "{$code} needs wording");
        }

        // `group_label` is what renders as the group header, not `cause_group`: the stored groups
        // are SINGULAR because they are column values, and the headers read "Processes" and
        // "Systems" because that is the workbook's wording (spec 03 §4.1). Config carries the
        // plural wording so a tenant can rename it; the presenter joins the two.
        $this->assertSame(ScenarioCause::GROUPS, array_keys((array) config('sm.causes.group_labels')));

        // Sort order must be a permutation of 0..22, or two causes share a position and the
        // report order becomes engine-dependent.
        $sorts = array_column($checklist, 'sort');
        sort($sorts);
        $this->assertSame(range(0, 22), $sorts);
    }

    #[Test]
    public function requires_note_is_true_for_exactly_the_four_others_and_the_unknown_item(): void
    {
        // Spec 03 §4.1 and addendum-3: true for every "Other", for "Others", and for
        // "Unknown/yet to be determined" — whose whole content IS the note.
        $fromConfig = [];

        foreach ((array) config('sm.causes.checklist') as $item) {
            if ($item['requires_note'] === true) {
                $fromConfig[] = (string) $item['code'];
            }
        }

        $this->assertSame(ScenarioCause::REQUIRES_NOTE, $fromConfig);
        $this->assertSame(
            ['people_other', 'process_other', 'system_other', 'ext_other', 'ext_unknown'],
            ScenarioCause::REQUIRES_NOTE
        );

        // `ext_group_affiliate` is NOT one of them: the workbook's cell is a named entity, which
        // §A1.8 generalised to "Group/affiliate entity". It carries meaning without a note, and
        // making it required would be the friction that teaches users to type "." into a box.
        $this->assertNotContains('ext_group_affiliate', ScenarioCause::REQUIRES_NOTE);
    }

    #[Test]
    public function the_deviation_from_the_artefact_is_recorded_and_not_the_customers_own_name(): void
    {
        // §A1.8's one departure from a verbatim workbook string: the external list contains
        // "Leadway Assurance company" — the reference artefact's own tenant. Shipping one
        // customer's insurer name into every tenant's checklist would put another bank's
        // competitor on their screen.
        $labels = array_column((array) config('sm.causes.checklist'), 'label');

        $this->assertContains('Group/affiliate entity', $labels);
        $this->assertNotContains('Leadway Assurance company', $labels);

        foreach ($labels as $label) {
            $this->assertStringNotContainsStringIgnoringCase('leadway', (string) $label);
        }

        $this->assertSame('Group/affiliate entity', ScenarioCause::CHECKLIST['ext_group_affiliate'][2]);
    }

    /* ------------------------------------------------------------------ */
    /*  The sign-off warnings */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_warning_code_has_exactly_one_message_and_vice_versa(): void
    {
        // C1.1 changed BOTH lists together — `eligible_to_transfer_not_assessed` out (its NULL
        // condition describes a state §A1.2's NOT NULL column forbids), `financial_risk_not_assessed`
        // in (`financial_risk` IS nullable and spec 01 §5.6.5 warns on it). The config map is
        // asserted against the const, so one without the other fails here: "which is the mechanism
        // working".
        $messages = (array) config('sm.signoff.warning_messages');

        $this->assertSame(Signoff::WARNING_CODES, array_keys($messages));
        $this->assertCount(8, $messages, 'the correction keeps the count at eight');

        foreach ($messages as $code => $message) {
            $this->assertIsString($message, "{$code} needs wording");
            $this->assertNotSame('', trim($message), "{$code} needs wording");
            // The wording is NEVER stored on the row (addendum-1): storing it would freeze text a
            // tenant may reconfigure and duplicate §A1.8's label maps.
            $this->assertStringNotContainsString($message, json_encode(Signoff::WARNING_CODES));
        }

        $this->assertContains('financial_risk_not_assessed', Signoff::WARNING_CODES);
        $this->assertNotContains('eligible_to_transfer_not_assessed', Signoff::WARNING_CODES);
    }

    /* ------------------------------------------------------------------ */
    /*  The lifecycle */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_status_has_a_label_and_no_label_exists_for_a_status_the_freeze_dropped(): void
    {
        $labels = (array) config('sm.status_labels');

        $this->assertSame(Scenario::STATUSES, array_keys($labels));

        foreach ($labels as $status => $label) {
            $this->assertNotSame('', trim((string) $label), "{$status} needs a label");
        }

        // Spec 01 §2's canonical chip words, which are also exactly what
        // GridPresenter::humanise() produces — the index grid humanises rather than reading
        // config, and the two must not disagree (see the config's own note).
        $this->assertSame('In design', $labels['in_design']);
        $this->assertSame('Signed off', $labels['signed_off']);
        $this->assertSame('Returned for rework', $labels['returned_for_rework']);

        foreach (Scenario::STATUSES as $status) {
            $this->assertSame(
                $labels[$status],
                ucfirst(str_replace('_', ' ', $status)),
                "{$status}: config's label must equal what the grid humanises, or the index and the workspace disagree"
            );
        }
    }

    #[Test]
    public function every_transition_edge_has_a_button_label_and_no_label_outlives_an_edge(): void
    {
        // Spec 01 §5.2: fourteen edges, and "a label the server does not send does not render:
        // the shell has no fallback vocabulary". The reverse matters too — a label for an edge
        // the freeze removed (Addendum-4's `archived → in_design`) is dead config that reads as
        // a capability the product does not have.
        $labels = (array) config('sm.transition_labels');

        $edges = [];

        foreach (Scenario::STATUS_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $edges[] = "{$from}>{$to}";
            }
        }

        sort($edges);
        $keys = array_keys($labels);
        sort($keys);

        $this->assertSame($edges, $keys);
        $this->assertCount(14, $keys, 'spec 01 §5.2 lists fourteen edges');

        $this->assertArrayNotHasKey('archived>in_design', $labels, 'Addendum-4 made archived terminal');
    }

    #[Test]
    public function the_decision_and_source_labels_cover_their_frozen_values(): void
    {
        $this->assertSame(
            Scenario::DECISIONS,
            array_keys((array) config('sm.decision_labels')),
            'two decisions, not three — §A1.6 refused approved_with_conditions'
        );

        $this->assertSame(
            Signoff::SOURCES,
            (array) config('sm.sources'),
            "the workbook's sign-off block is Source 1 / Source 2 / Source 3 and nothing else"
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The remaining vocabularies */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_note_kinds_and_cause_groups_in_config_are_the_frozen_ones(): void
    {
        $this->assertSame(ScenarioCause::GROUPS, (array) config('sm.cause_groups'));
        $this->assertSame(ScenarioCause::SIDES, (array) config('sm.cause_sides'));

        $groupLabels = (array) config('sm.causes.group_labels');
        $this->assertSame(ScenarioCause::GROUPS, array_keys($groupLabels));

        // The stored groups are SINGULAR because they are column values; the headers read
        // "Processes" and "Systems" because that is the workbook's wording (spec 03 §4.1). The
        // split is the whole reason `group_label` exists beside `cause_group`.
        $this->assertSame('Processes', $groupLabels['process']);
        $this->assertSame('Systems', $groupLabels['system']);

        // Note kinds are on the MODEL, not in config: §A1.8 puts KINDS on `Sm\Note` and
        // addendum-3's config list does not include the notes vocabulary.
        $this->assertSame(['assumption', 'dissent', 'challenge', 'workshop_minute'], Note::KINDS);
        $this->assertSame(Note::KINDS, array_keys(Note::KIND_LABELS));
        $this->assertSame(Note::KINDS, array_keys(Note::KIND_HINTS));
    }

    #[Test]
    public function the_yes_no_labels_carry_all_three_states(): void
    {
        // Three, not two: `insurance_risk` and `financial_risk` are NULLABLE booleans (§A1.2
        // rows 23–24) and a two-state control renders "not yet assessed" as "No" — which is
        // `DynamicForm`'s `bool` defect and the exact inversion standard §5 forbids.
        $this->assertSame(
            ['yes' => 'Yes', 'no' => 'No', 'unset' => 'Not assessed'],
            (array) config('sm.yes_no')
        );
    }

    #[Test]
    public function the_review_cadence_default_is_twelve_months(): void
    {
        // Addendum-3: "12 is the shipped value, not an invented one — plan §4.2's cadence is
        // annual and capability 10 in plan §3.3 is the annual refresh."
        $this->assertSame(12, config('sm.review_cadence_months'));
        $this->assertSame(12, \App\Services\Sm\ScenarioSettings::DEFAULT_REVIEW_CADENCE_MONTHS);
        $this->assertSame(1, \App\Services\Sm\ScenarioSettings::MIN_REVIEW_CADENCE_MONTHS);
        $this->assertSame(120, \App\Services\Sm\ScenarioSettings::MAX_REVIEW_CADENCE_MONTHS);
    }

    #[Test]
    public function nothing_in_config_introduces_a_value_the_consts_do_not_know(): void
    {
        // The sweep, so a new config key cannot smuggle an unstorable value past the targeted
        // assertions above. Every string in the file that looks like a stored value must be a
        // frozen one; wording is exempt because wording is exactly what config is for.
        $frozen = [
            ...Scenario::STATUSES,
            ...array_keys(Scenario::NATURES),
            ...array_keys(Scenario::WEIGHTINGS),
            ...array_keys(Scenario::REPUTATIONAL_EXPOSURES),
            ...array_keys(Scenario::LEGAL_COMPLIANCE_EXPOSURES),
            ...Scenario::DECISIONS,
            ...array_keys(Signoff::SOURCES),
            ...Signoff::WARNING_CODES,
            ...Note::KINDS,
            ...array_keys(ScenarioCause::CHECKLIST),
            ...ScenarioCause::SIDES,
            ...ScenarioCause::GROUPS,
        ];

        foreach ((array) config('sm.exposures.reputational.options') as $option) {
            $this->assertContains($option['value'], $frozen);
        }

        foreach ((array) config('sm.exposures.legal.options') as $option) {
            $this->assertContains($option['value'], $frozen);
        }

        foreach ((array) config('sm.causes.checklist') as $item) {
            $this->assertContains($item['code'], $frozen);
            $this->assertContains($item['side'], $frozen);
            $this->assertContains($item['cause_group'], $frozen);
        }
    }
}
