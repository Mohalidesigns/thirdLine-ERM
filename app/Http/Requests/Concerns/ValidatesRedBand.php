<?php

namespace App\Http\Requests\Concerns;

use App\Models\KeyRiskIndicator;
use App\Services\Kri\KriRedBandService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Server-side enforcement of the red-band explanation (ADR 0026 section 6.4).
 *
 * A red reading or red target must carry `red_band: {root_cause, action_point,
 * timeline, owner_id?, timeline_change_reason?}`. Whether a value IS red is
 * decided by the caller through KriBandRule - never here, never in the browser.
 * The browser mirror is a display aid only.
 *
 * Errors land on `{prefix}.root_cause`, `{prefix}.action_point`,
 * `{prefix}.timeline` (and `.timeline_change_reason`), where the form's
 * red-band component reads them.
 */
trait ValidatesRedBand
{
    /**
     * Shape rules for a red_band payload. Required-ness is NOT here: it depends
     * on the band, which only the caller can compute.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function redBandShapeRules(string $prefix = 'red_band'): array
    {
        $orgId = TenantContext::organizationId();

        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.root_cause" => ['nullable', 'string', 'max:5000'],
            "{$prefix}.action_point" => ['nullable', 'string', 'max:5000'],
            "{$prefix}.timeline" => ['nullable', 'date_format:Y-m-d'],
            "{$prefix}.owner_id" => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            "{$prefix}.timeline_change_reason" => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Add the 422s a RED value owes: the three required fields, a timeline not
     * in the past (unless it confirms the open Issue's current date), a reason
     * when the open Issue's date moves, and - on the champion path - an owner
     * who belongs to the department.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function requireRedBand(
        Validator $validator,
        KeyRiskIndicator $kri,
        ?array $payload,
        string $prefix = 'red_band',
        ?int $championUnitId = null,
    ): void {
        $payload ??= [];
        $errors = $validator->errors();

        $required = [
            'root_cause' => 'A root cause is required when the KRI is in the red band.',
            'action_point' => 'An action point is required when the KRI is in the red band.',
            'timeline' => 'A timeline is required when the KRI is in the red band.',
        ];

        foreach ($required as $field => $message) {
            $value = $payload[$field] ?? null;

            if ((is_string($value) ? trim($value) : $value) === null || (is_string($value) && trim($value) === '')) {
                if (! $errors->has("{$prefix}.{$field}")) {
                    $errors->add("{$prefix}.{$field}", $message);
                }
            }
        }

        $timeline = $payload['timeline'] ?? null;

        if (is_string($timeline) && $timeline !== '' && ! $errors->has("{$prefix}.timeline")) {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $timeline);

            if ($date instanceof CarbonImmutable) {
                $date = $date->startOfDay();
                $service = app(KriRedBandService::class);

                if (($problem = $service->timelineProblem($kri, $date)) !== null) {
                    $errors->add("{$prefix}.timeline", $problem);
                } elseif ($service->changeReasonRequired($kri, $date)
                    && trim((string) ($payload['timeline_change_reason'] ?? '')) === ''
                    && ! $errors->has("{$prefix}.timeline_change_reason")) {
                    $errors->add("{$prefix}.timeline_change_reason", 'Say why the timeline of the open KRI issue is changing.');
                }
            }
        }

        $owner = $payload['owner_id'] ?? null;

        if ($championUnitId !== null && $owner !== null && $owner !== '' && ! $errors->has("{$prefix}.owner_id")
            && ! $this->ownerBelongsToDepartment((int) $owner, $championUnitId)) {
            $errors->add("{$prefix}.owner_id", 'The action owner must be a member or contact of this department.');
        }
    }

    /** The department's KRI contacts and its business_unit_user members. */
    protected function ownerBelongsToDepartment(int $userId, int $unitId): bool
    {
        return app(KriRedBandService::class)->ownerBelongsToDepartment($userId, $unitId);
    }
}
