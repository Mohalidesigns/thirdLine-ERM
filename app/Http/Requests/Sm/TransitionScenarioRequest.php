<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Scenario;
use App\Services\Sm\ScenarioLifecycle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every lifecycle transition — `POST {scenario}/status` (ADR 0027 §A1.11, spec 01
 * §5.2, Correction 1 §C1.3).
 *
 * ── ONE ROUTE, FOURTEEN EDGES, ONE PERMISSION ─────────────────────────────────
 *
 * §A1.11 gates this route with `scenario.validate` and spec 01 §3 says so twice:
 * EVERY lifecycle move rides it, including `draft → in_design`. There is no
 * per-transition permission and `scenario.design` does not exist in Phase 1 (§A1.10
 * defers it to Phase 2 — "nothing in Phase 1 is quantitative, and `scenario.update`
 * covers the qualitative tabs"). With §A1.10's grants only `risk-manager`,
 * `chief-risk-officer` and `super-admin` hold it.
 *
 * ── TWO DIFFERENT REFUSALS, AND THE SPLIT IS DELIBERATE ───────────────────────
 *
 *   - An ILLEGAL EDGE (not in `Scenario::STATUS_TRANSITIONS`) is a FLASH from the
 *     controller, not a 422 and not a 403 — §A1.8 names
 *     `LossEventController::updateStatus()` as the precedent, where "an illegal
 *     target [is] answered with `back()->with('error', …)`". So `to` is validated
 *     against `Scenario::STATUSES` (a known status) and the EDGE is checked by
 *     `ScenarioLifecycle::transition()`.
 *   - A FAILED GATE at `in_design → designed` IS a 422, with spec 03 §7's messages on
 *     the frozen keys. §A1.5 is explicit that the allocation invariant "answers 422 on
 *     key `allocations`, enforced at the `in_design → designed` transition", and spec
 *     03 §7 that "these strings must be the Form Request's messages, not client-side
 *     paraphrases".
 *
 * ── THE REASON AND THE STATUS CHANGE ARE ONE TRANSACTION ──────────────────────
 *
 * C1.3: `reason` is required when `to` is `returned_for_rework`, and
 * `ScenarioLifecycle::transition()` writes the `challenge` note INSIDE the same DB
 * transaction as the status change. Spec 01 §5.3 named the two-request alternative "a
 * real defect": a failure between the writes leaves a returned scenario with no
 * recorded reason, and the trail D4 rests on would have a hole in it.
 */
class TransitionScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transition', $this->scenario()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A KNOWN STATUS, not a legal edge — see the class docblock. An edge the
            // map does not draw is a flash message from the lifecycle service.
            'to' => ['required', 'string', Rule::in(Scenario::STATUSES)],

            // C1.3: required for a return, optional otherwise, "string 10–500 — the
            // shape ADR 0026 A1.10 froze for ReopenKriReturnRequest". The minimum is
            // the point: a return whose reason is "no" gives the designer nothing to
            // work from, and spec 01 §5.3's copy says exactly that.
            'reason' => [
                Rule::requiredIf(fn () => $this->input('to') === 'returned_for_rework'),
                'nullable', 'string', 'min:10', 'max:500',
            ],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // The gate only bites on the one edge §A1.8 attaches it to. Checking
                // it on every transition would block moves the freeze allows — spec
                // 03 §7's warning about "a screen that enforces a stricter rule than
                // its backend", applied to the server itself.
                if ($this->input('to') !== 'designed') {
                    return;
                }

                foreach ($this->lifecycle()->missingGateFields($this->scenario()) as $item) {
                    $validator->errors()->add($item['field'], $item['message']);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.required' => 'Choose where this scenario should move to.',
            'to.in' => 'Choose where this scenario should move to.',
            'to.string' => 'Choose where this scenario should move to.',

            // Spec 01 §5.3, verbatim — the inline error under "What has to change?".
            'reason.required' => 'Say what has to change. A return with no reason gives the designer nothing to work from.',
            'reason.min' => 'Say what has to change. A return with no reason gives the designer nothing to work from.',
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
            'reason.string' => 'Say what has to change. A return with no reason gives the designer nothing to work from.',
        ];
    }

    public function scenario(): Scenario
    {
        return $this->route('scenario');
    }

    /**
     * Resolved from the container rather than injected through the constructor:
     * `FormRequest`'s constructor is `Request`'s nine-argument one, and overriding it
     * to add a dependency is a fragility this module does not need. Nothing here holds
     * state between requests.
     */
    private function lifecycle(): ScenarioLifecycle
    {
        return app(ScenarioLifecycle::class);
    }
}
