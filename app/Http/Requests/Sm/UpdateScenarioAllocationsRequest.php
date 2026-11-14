<?php

namespace App\Http\Requests\Sm;

use App\Models\BusinessUnit;
use App\Models\Sm\Scenario;
use App\Services\Sm\AllocationService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Qualitative tab's business-line allocation — `PUT {scenario}/allocations`
 * (spec 03 §2, §5, §5.6).
 *
 * ── THE SUM-TO-100 RULE IS DELIBERATELY NOT HERE ──────────────────────────────
 *
 * §A1.5: the invariant is "enforced at the **`in_design → designed` transition** and
 * not on every draft save — a draft legitimately sums to 60% mid-entry, and refusing
 * to save it pushes users into one big form." Patched spec 03 §5.2 states the
 * consequence for this endpoint plainly: "**Save allocation** succeeds with any sum,
 * including an incomplete one", the ≠100 state on the tab is ADVISORY, and "a screen
 * that refused to save 60% would be enforcing a stricter rule than the server has —
 * which is its own kind of lie, and the one that pushes designers back to the
 * spreadsheet mid-workshop."
 *
 * The block happens in `TransitionScenarioRequest`, which asks
 * `ScenarioLifecycle::missingGateFields()` and answers **422 on key `allocations`**
 * with spec 03 §7's exact sentence. Coordinator ruling 4 pins the same split.
 *
 * ── TARGETS ARE BUSINESS UNITS ONLY ───────────────────────────────────────────
 *
 * §A1.5 REFUSED `business_processes`, and the coordinator upheld it, so
 * `allocation.options[].kind` and `business_process_id` came out of spec 03 §2 and out
 * of this body. Every foreign key is tenant-bound (standard §4): a bare
 * `exists:business_units,id` is an existence oracle.
 *
 * ── PERCENTS ARE DECIMALS, NOT FLOATS ─────────────────────────────────────────
 *
 * `decimal(5,2)` on the column, `decimal:0,2` in the rules (normalised to a 2dp
 * string on the way in), and `AllocationService`
 * compares the total in integer cents. Spec 01 §4.5: a float that has been through
 * `json_encode` can arrive as `100` instead of `100.00`, and summing those in JS
 * reintroduces exactly the artefact the server was told to avoid.
 */
class UpdateScenarioAllocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->scenario()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            // `present`, not `required`: an EMPTY array is a legitimate post meaning
            // "remove every line". Spec 03 §5.4 renders that as "Not allocated" and
            // never as "0%", and refusing it would make the allocation impossible to
            // clear.
            'allocations' => ['present', 'array'],

            'allocations.*.business_unit_id' => [
                'required', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],

            // `nullable` rather than `required` so the MISSING case can be answered
            // with spec 03 §7's labelled sentence ("Enter a percentage for {line
            // label}, or remove the line.") in after() — a bare `required` message
            // cannot name the row, and a designer staring at six rows needs to know
            // which one.
            //
            // `decimal:0,2`, NOT `decimal:2`: the message below promises "at most 2
            // decimal places", and `decimal:2` would refuse a posted "60" — a stale
            // tab or a direct PUT then gets a rejection that contradicts its own
            // wording. `AllocationService::normalisePercent()` pads to the column's
            // 2dp either way, so "60" and "60.00" store identically.
            'allocations.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkMissingPercents($validator);
                $this->checkDuplicateLines($validator);
            },
        ];
    }

    /**
     * Verbatim from spec 03 §7. Standard §4 puts validation in Form Requests, and "a
     * message that exists only in JSX is a message a non-JS 422 or a stale tab will
     * not carry".
     *
     * `allocations.{i}` indexes THE POSTED ARRAY, which is why spec 03 §2 records that
     * the allocation list is not re-sortable in Phase 1: a drag-to-reorder would
     * silently move these errors onto the wrong rows, and no test in this repository
     * could catch it.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allocations.present' => 'No allocation lines were sent.',
            'allocations.array' => 'No allocation lines were sent.',

            'allocations.*.business_unit_id.required' => 'Choose a business line for this row.',
            'allocations.*.business_unit_id.integer' => 'Choose a business line for this row.',
            'allocations.*.business_unit_id.exists' => "That business unit isn't in your organisation.",

            'allocations.*.percent.numeric' => 'A share must be a number.',
            'allocations.*.percent.min' => 'A share must be between 0 and 100.',
            'allocations.*.percent.max' => 'A share must be between 0 and 100.',
            'allocations.*.percent.decimal' => 'Use at most 2 decimal places.',
        ];
    }

    public function scenario(): Scenario
    {
        return $this->route('scenario');
    }

    /**
     * The posted lines, normalised: percent as a 2dp decimal STRING.
     *
     * @return list<array{business_unit_id: int, percent: string}>
     */
    public function allocationLines(): array
    {
        $lines = [];

        foreach ((array) $this->validated()['allocations'] as $line) {
            if (! is_array($line) || ! isset($line['business_unit_id'])) {
                continue;
            }

            $percent = $line['percent'] ?? null;

            if ($percent === null || $percent === '') {
                continue;
            }

            $lines[] = [
                'business_unit_id' => (int) $line['business_unit_id'],
                'percent' => AllocationService::normalisePercent($percent),
            ];
        }

        return $lines;
    }

    /**
     * Spec 03 §7: "Enter a percentage for {line label}, or remove the line."
     *
     * The label needs a query, which is why this is `after()` and not a rule: a
     * declarative message cannot name the row, and spec 03 §5.2 is explicit that "an
     * empty percent is treated as *missing*, not as `0`, so the sum shown is never a
     * lie about what has been entered".
     */
    private function checkMissingPercents(Validator $validator): void
    {
        $rows = (array) $this->input('allocations', []);
        $labels = $this->unitLabels();

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $percent = $row['percent'] ?? null;

            if ($percent !== null && $percent !== '') {
                continue;
            }

            $unitId = (int) ($row['business_unit_id'] ?? 0);
            $label = $labels[$unitId] ?? 'this line';

            // Replace the generic `nullable` pass with the labelled refusal, so one
            // row produces exactly one error and it names the row.
            $validator->errors()->forget("allocations.{$index}.percent");
            $validator->errors()->add(
                "allocations.{$index}.percent",
                "Enter a percentage for {$label}, or remove the line."
            );
        }
    }

    /**
     * Spec 03 §7: "{line label} is already allocated."
     *
     * §A1.5's `sm_allocations_scenario_unit_unique` makes a duplicate a database error,
     * and spec 03 §5.1 has the picker remove already-used options — so this is
     * "should be unreachable", which is exactly when a server-side check is worth
     * having: the alternative is a 23000 the user cannot interpret.
     */
    private function checkDuplicateLines(Validator $validator): void
    {
        $rows = (array) $this->input('allocations', []);
        $labels = $this->unitLabels();
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! isset($row['business_unit_id'])) {
                continue;
            }

            $unitId = (int) $row['business_unit_id'];

            if (! isset($seen[$unitId])) {
                $seen[$unitId] = $index;

                continue;
            }

            $label = $labels[$unitId] ?? 'That business line';

            $validator->errors()->add("allocations.{$index}.business_unit_id", "{$label} is already allocated.");
        }
    }

    /**
     * Unit names for this tenant, for the two messages that have to name a row.
     *
     * @return array<int, string>
     */
    private function unitLabels(): array
    {
        $ids = [];

        foreach ((array) $this->input('allocations', []) as $row) {
            if (is_array($row) && isset($row['business_unit_id'])) {
                $ids[] = (int) $row['business_unit_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return BusinessUnit::query()
            ->where('organization_id', TenantContext::organizationId())
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
