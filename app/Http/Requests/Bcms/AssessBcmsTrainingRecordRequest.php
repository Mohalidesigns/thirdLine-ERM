<?php

namespace App\Http\Requests\Bcms;

use App\Models\Bcms\TrainingRecord;
use App\Models\User;
use App\Services\Bcms\Training\TrainingComplianceService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Assessing an existing attendance-only record.
 *
 * B4: THERE IS NO ASSESSOR FIELD. The assessor is always the acting user —
 * accepting a free `assessor_id` let a manage holder name a colleague as
 * their own assessor. Any `assessor_id` submitted here is not validated and
 * is never read by the controller.
 *
 * R2: THE RECORD'S SUBJECT MUST BE IN THE ACTOR'S OWN UNIT SCOPE (B11). A
 * unit-scoped `bcms.training.manage` holder binds `TrainingRecord` by id
 * under tenancy alone — nothing previously stopped them assessing anyone's
 * record in the bank. Checked via `TrainingComplianceService::
 * subjectVisibleToActor()`, the same resolver `StoreBcmsTrainingRecordRequest`
 * uses, so the create and assess paths cannot drift apart.
 */
class AssessBcmsTrainingRecordRequest extends FormRequest
{
    /**
     * Code review #3, A4: `$record->user_id` is a trusted, already-scalar
     * column (route-model-bound, never raw request input), so the array-
     * injection half of A4 does not apply here — but the FAIL-OPEN half
     * did: `if ($subject === null) { return true; }` let a record whose
     * subject Eloquent cannot resolve (a SOFT-DELETED user, `User`'s own
     * `SoftDeletes` global scope) through unauthorised. Fails closed now.
     */
    public function authorize(): bool
    {
        if ($this->user()?->can('bcms.training.manage') !== true) {
            return false;
        }

        $record = $this->route('record');

        if (! $record instanceof TrainingRecord) {
            return true; // an unresolvable/missing binding 404s before this ever runs in practice
        }

        $subject = User::query()->find($record->user_id);

        if ($subject === null) {
            return false;
        }

        return app(TrainingComplianceService::class)->subjectVisibleToActor($this->user(), $subject);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
