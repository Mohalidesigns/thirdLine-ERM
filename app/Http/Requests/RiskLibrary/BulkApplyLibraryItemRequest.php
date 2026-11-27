<?php

namespace App\Http\Requests\RiskLibrary;

use App\Models\Entity;
use App\Models\Risk;
use App\Models\RiskLibraryItem;
use App\Policies\EntityPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

class BulkApplyLibraryItemRequest extends FormRequest
{
    /**
     * Bulk-apply runs synchronously in one transaction: every item × target pair
     * generates a reference code, creates a risk, seeds its causes and writes an
     * audit row. These caps keep one request inside a web timeout and one lock
     * window; a larger rollout is several smaller applies.
     */
    public const MAX_ITEMS = 50;

    public const MAX_TARGETS = 100;

    public const MAX_PAIRS = 500;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Risk::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'library_item_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'library_item_ids.*' => [
                'required',
                'integer',
                Rule::exists('risk_library_items', 'id')
                    ->where('organization_id', $orgId)
                    ->where('status', RiskLibraryItem::STATUS_ACTIVE),
            ],
            'target_mode' => ['required', Rule::in(['entities', 'processes'])],
            'entity_ids' => ['required_if:target_mode,entities', 'array', 'max:'.self::MAX_TARGETS],
            'entity_ids.*' => [
                'integer',
                Rule::exists('entities', 'id')->where('organization_id', $orgId),
            ],
            'process_ids' => ['required_if:target_mode,processes', 'array', 'max:'.self::MAX_TARGETS],
            'process_ids.*' => [
                'integer',
                Rule::exists('business_processes', 'id')->where('organization_id', $orgId),
            ],
            'default_owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId),
            ],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $items = count((array) $this->input('library_item_ids', []));
            $targets = $this->input('target_mode') === 'entities'
                ? count((array) $this->input('entity_ids', []))
                : count((array) $this->input('process_ids', []));

            if ($items * $targets > self::MAX_PAIRS) {
                $validator->errors()->add('library_item_ids', sprintf(
                    'This would create %d risks at once; the limit is %d. Apply fewer items or targets per run.',
                    $items * $targets,
                    self::MAX_PAIRS,
                ));
            }

            // Reach, not just tenancy: a user limited to part of the organisation may
            // only create risks there (AttachLibraryRisksToEntityRequest applies the
            // same rule to the single-entity path).
            if ($this->input('target_mode') === 'entities') {
                $user = $this->user();
                $rootPath = EntityPolicy::subtreePathFor($user);
                $outside = Entity::query()
                    ->whereIn('id', array_map('intval', (array) $this->input('entity_ids', [])))
                    ->get()
                    ->reject(static fn (Entity $entity) => EntityPolicy::withinResolvedReach($user, $rootPath, $entity));

                if ($outside->isNotEmpty()) {
                    $validator->errors()->add(
                        'entity_ids',
                        'You can only apply library risks to entities in the part of the organisation you manage.'
                    );
                }
            }
        });
    }
}
