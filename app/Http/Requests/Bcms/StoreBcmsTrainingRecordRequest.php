<?php

namespace App\Http\Requests\Bcms;

use App\Models\User;
use App\Services\Bcms\Training\TrainingComplianceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a training outcome — attendance, and (for an assessed curriculum)
 * a competency result.
 *
 * NO CERTIFICATE FIELD EXISTS HERE (ADR 0021 §3). The assessor, the date and
 * the score are the record; there is no file input to validate.
 *
 * B4: NO ASSESSOR FIELD EITHER. The assessor is always the acting user
 * (the one submitting this form) — a free `assessor_id` field let a manage
 * holder name a colleague as their own assessor. Any `assessor_id`
 * submitted is not validated and is never read by the service. The
 * assessor-≠-subject rule is still enforced, in the service, against the
 * acting user rather than a submitted field.
 *
 * A10: A RETIRED CURRICULUM (`is_active = false`) REFUSES A NEW RECORD — the
 * superseded-code retirement (ADR 0021 §3, §9's fix) keeps a retired
 * curriculum's historical rows resolvable, not open to new ones. A FUTURE
 * `completed_at` is refused too: a training outcome describes something
 * that already happened.
 *
 * R2: THE REGISTER'S UNIT SCOPE (B11) NOW GOVERNS WHO CAN BE RECORDED FOR,
 * NOT ONLY WHO CAN BE SEEN. A unit-scoped `bcms.training.manage` holder
 * (e.g. `risk-manager`, who does not hold `rcsa_scope.all_units`) could
 * previously record an outcome for anyone in the tenant — the request only
 * checked `user_id` against the tenant, never against the actor's own
 * units — and the read side's assessor exception (`TrainingComplianceService::
 * rowVisibleToViewer()`) would then keep that person permanently visible in
 * their register, which is exactly what NDPA register §11.4 rule 3 rules
 * out. Checked here via `TrainingComplianceService::subjectVisibleToActor()`
 * — the SAME resolver the read side's `personInScope()` core uses, so the
 * two rules cannot drift apart.
 */
class StoreBcmsTrainingRecordRequest extends FormRequest
{
    /**
     * Code review #3, A4: two defects, both in how a bad `user_id` was
     * handled.
     *
     *   1. `User::query()->find($this->input('user_id'))` — an ARRAY
     *      `user_id` (`user_id[]=1&user_id[]=2`) makes `find()` return a
     *      `Collection`, not a model-or-null; that then hits
     *      `subjectVisibleToActor()`'s typed `?User` parameter and 500s,
     *      where a MALFORMED submission should 422, not 500. A non-scalar
     *      `user_id` is never handed to `find()` at all — it is deferred
     *      to `rules()`'s own `integer` rule below, which already refuses
     *      an array with a proper 422, field-level message.
     *   2. `if ($subject === null) { return true; }` FAILED OPEN for a
     *      well-FORMED but UNRESOLVABLE `user_id` (a soft-deleted user, or
     *      one genuinely missing) — the comment's own reasoning
     *      ("`rules()`'s job to refuse") does not hold: `Rule::exists('users',
     *      'id')` below carries no `whereNull('deleted_at')`, so a
     *      SOFT-DELETED user's row still passes validation even though
     *      `User::query()->find()` (which DOES apply the model's own
     *      `SoftDeletes` global scope) returns null for it — the exact
     *      case named. A subject this method cannot resolve must FAIL
     *      CLOSED (403), never wave the scope question through to a
     *      validation rule that does not ask it.
     */
    public function authorize(): bool
    {
        if ($this->user()?->can('bcms.training.manage') !== true) {
            return false;
        }

        $userId = $this->input('user_id');

        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            return true;
        }

        $subject = User::query()->find((int) $userId);

        if ($subject === null) {
            return false;
        }

        return app(TrainingComplianceService::class)->subjectVisibleToActor($this->user(), $subject);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'curriculum_id' => [
                'required', 'integer',
                Rule::exists('bcms_training_curricula', 'id')
                    ->where(fn ($q) => $q
                        ->where(fn ($q2) => $q2->where('organization_id', $organizationId)->orWhereNull('organization_id'))
                        ->where('is_active', true)),
            ],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('organization_id', $organizationId)],
            'completed_at' => ['required', 'date', 'before_or_equal:today'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'curriculum_id.exists' => 'That curriculum does not exist, or is no longer active — a retired curriculum accepts no new records.',
            'completed_at.before_or_equal' => 'A training outcome cannot be dated in the future.',
        ];
    }
}
