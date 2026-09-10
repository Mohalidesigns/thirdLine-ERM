<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Support\Str;

/**
 * The validation rule catalogue of §7.3, applied to one staged row at a time.
 *
 * THREE SEVERITIES, AND THE DIFFERENCE BETWEEN THEM IS WHAT GETS WRITTEN.
 *
 *   error     — the row cannot be published. Something required is absent or
 *               names a thing that does not exist.
 *   warning   — the row publishes, with something less than the user intended:
 *               an unresolved control owner becomes unassigned, an unknown
 *               system is not linked. The preview says so; the row still goes.
 *   duplicate — the row already exists in the published universe. It defaults
 *               to skip, and the user may switch the batch to update instead.
 *
 * Getting that split wrong in either direction is how a bulk upload fails: too
 * many errors and a bank abandons the feature after one attempt; too few and
 * the universe fills with rows nobody meant.
 *
 * IN-FILE DUPLICATES ARE NOT THE SAME AS PUBLISHED ONES. Two rows in the same
 * file with the same risk are usually a risk with TWO CONTROLS, which is how
 * the template asks you to write it — so they are merged, not flagged. A row
 * matching something already published is the real duplicate.
 */
class RcsaImportValidator
{
    /** §7.3: a risk statement shorter than this describes nothing anyone can assess. */
    private const MIN_STATEMENT = 20;

    /** §7.3: free-text fields are capped so a pasted document cannot become a risk. */
    private const MAX_TEXT = 2000;

    /**
     * @var array<string, list<int>>|null Published row hashes => ids, loaded once.
     */
    private ?array $published = null;

    /**
     * Check one normalised row.
     *
     * `$authorisedUnitIds` is the set of business units the uploading user may
     * write to. Null means "no restriction", which is the state until P7 adds
     * business-unit assignments — the parameter exists now so that the rule
     * from §7.3 ("Business Unit exists and user is authorised for it") has a
     * home rather than being retrofitted through the whole pipeline later.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array{field: string, message: string}>  $notes  From the normaliser.
     * @param  list<int>|null  $authorisedUnitIds
     * @return array{status: string, errors: list<array{field: string, rule: string, severity: string, message: string}>, target_id: int|null, action: string}
     */
    public function check(array $values, array $notes, ?array $authorisedUnitIds = null): array
    {
        $problems = [];

        $add = function (string $field, string $rule, string $severity, string $message) use (&$problems) {
            $problems[] = compact('field', 'rule', 'severity', 'message');
        };

        // Everything the normaliser could not resolve cleanly is a warning
        // before any rule runs, so a fuzzy unit match is visible even on a row
        // that is otherwise perfect.
        foreach ($notes as $note) {
            $add($note['field'], 'normalisation', RcsaImportRow::WARNING, $note['message']);
        }

        /* --- Business unit --------------------------------------------- */

        if (blank($values['business_unit'] ?? null)) {
            $add('business_unit', 'required', RcsaImportRow::ERROR, 'Business Unit is required.');
        } elseif (($values['business_unit_id'] ?? null) === null) {
            $add('business_unit', 'exists', RcsaImportRow::ERROR, sprintf(
                'No business unit called "%s". Check the spelling against the dropdown.',
                $values['business_unit']
            ));
        } elseif ($authorisedUnitIds !== null && ! in_array((int) $values['business_unit_id'], $authorisedUnitIds, true)) {
            $add('business_unit', 'authorised', RcsaImportRow::ERROR, sprintf(
                'You are not authorised to add risks to "%s".',
                $values['business_unit']
            ));
        }

        /* --- Process and sub-process ----------------------------------- */

        if (filled($values['process'] ?? null) && ($values['process_id'] ?? null) === null) {
            $add('process', 'exists', RcsaImportRow::ERROR, sprintf(
                'No process called "%s" in that business unit. Create it first, or leave the column blank.',
                $values['process']
            ));
        }

        if (filled($values['sub_process'] ?? null)) {
            if (blank($values['process'] ?? null)) {
                $add('sub_process', 'parent', RcsaImportRow::ERROR, 'Name the Process before naming a Sub-Process.');
            } elseif (($values['sub_process_id'] ?? null) === null) {
                $add('sub_process', 'exists', RcsaImportRow::ERROR, sprintf(
                    '"%s" is not a sub-process of "%s".',
                    $values['sub_process'],
                    $values['process']
                ));
            }
        }

        /* --- The risk statement ---------------------------------------- */

        $statement = (string) ($values['potential_risk'] ?? '');

        if (blank($statement)) {
            $add('potential_risk', 'required', RcsaImportRow::ERROR, 'Potential Risk is required.');
        } elseif (Str::length($statement) < self::MIN_STATEMENT) {
            $add('potential_risk', 'min', RcsaImportRow::ERROR, sprintf(
                'Potential Risk must be at least %d characters — this is %d. Describe what could go wrong and what would follow.',
                self::MIN_STATEMENT,
                Str::length($statement)
            ));
        }

        if (blank($values['risk_driver'] ?? null)) {
            $add('risk_driver', 'recommended', RcsaImportRow::WARNING, 'No risk driver given, so the root cause is not recorded.');
        }

        /* --- Category --------------------------------------------------- */

        if (blank($values['risk_category'] ?? null)) {
            $add('risk_category', 'required', RcsaImportRow::ERROR,
                'Risk Category is required and must be one of the approved values.');
        } elseif ($values['risk_category'] === Template::CATEGORY_OTHERS && ($values['secondary_categories'] ?? []) === []) {
            $add('secondary_categories', 'required_if', RcsaImportRow::ERROR,
                'Name the applicable categories when the Risk Category is "Others".');
        }

        /* --- Control ---------------------------------------------------- */

        if (blank($values['existing_control'] ?? null)) {
            $add('existing_control', 'recommended', RcsaImportRow::WARNING,
                'No existing control. The assessment will have nothing to rate for this risk.');
        }

        /* --- Length ------------------------------------------------------ */

        foreach (['potential_risk', 'risk_driver', 'existing_control'] as $field) {
            if (Str::length((string) ($values[$field] ?? '')) > self::MAX_TEXT) {
                $add($field, 'max', RcsaImportRow::ERROR, sprintf(
                    'Longer than %d characters. Summarise it — the detail belongs in the evidence, not the register.',
                    self::MAX_TEXT
                ));
            }
        }

        /* --- Risk number ------------------------------------------------ */

        $riskNo = $values['risk_no'] ?? null;
        $unitId = $values['business_unit_id'] ?? null;

        if (filled($riskNo) && $unitId !== null) {
            $taken = RcsaRegisterRisk::withTrashed()
                ->where('business_unit_id', $unitId)
                ->where('risk_no', $riskNo)
                ->exists();

            if ($taken) {
                $add('risk_no', 'unique', RcsaImportRow::ERROR, sprintf(
                    'Risk number "%s" is already used in that business unit. Leave it blank to have one generated.',
                    $riskNo
                ));
            }
        }

        /* --- Duplicate of something already published -------------------- */

        $targetId = null;

        if ($unitId !== null && filled($statement)) {
            $hash = RcsaRegisterRisk::hashFor(
                (int) $unitId,
                $values['process_id'] ?? null,
                $values['sub_process_id'] ?? null,
                $statement,
            );

            $targetId = $this->published()[$hash][0] ?? null;
        }

        /* --- Resolve to a status ----------------------------------------- */

        $severities = array_column($problems, 'severity');

        if (in_array(RcsaImportRow::ERROR, $severities, true)) {
            return ['status' => RcsaImportRow::ERROR, 'errors' => $problems, 'target_id' => $targetId, 'action' => RcsaImportRow::SKIP];
        }

        if ($targetId !== null) {
            $problems[] = [
                'field' => 'potential_risk',
                'rule' => 'duplicate',
                'severity' => RcsaImportRow::DUPLICATE,
                'message' => 'This risk is already published in that business unit and process. '
                    .'It will be skipped unless you choose to update existing rows.',
            ];

            return ['status' => RcsaImportRow::DUPLICATE, 'errors' => $problems, 'target_id' => $targetId, 'action' => RcsaImportRow::SKIP];
        }

        return [
            'status' => in_array(RcsaImportRow::WARNING, $severities, true) ? RcsaImportRow::WARNING : RcsaImportRow::VALID,
            'errors' => $problems,
            'target_id' => null,
            'action' => RcsaImportRow::CREATE,
        ];
    }

    /**
     * The published universe, by row hash.
     *
     * Loaded once per batch. Checking each row with its own query would be a
     * thousand queries on a thousand-row file; a register of any realistic size
     * fits in memory as hash => ids.
     *
     * @return array<string, list<int>>
     */
    private function published(): array
    {
        if ($this->published !== null) {
            return $this->published;
        }

        $this->published = [];

        RcsaRegisterRisk::query()
            ->where('status', RcsaRegisterRisk::PUBLISHED)
            ->whereNotNull('row_hash')
            ->select(['id', 'row_hash'])
            ->chunk(1000, function ($risks) {
                foreach ($risks as $risk) {
                    $this->published[$risk->row_hash][] = (int) $risk->id;
                }
            });

        return $this->published;
    }
}
