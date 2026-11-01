<?php

namespace App\Services\Kri;

use Carbon\CarbonImmutable;

/**
 * The red-band explanation (ADR 0026 section 6.2): three required fields and
 * two optional ones. Pure data - the writer is KriRedBandService.
 */
final class RedBandExplanation
{
    public function __construct(
        public string $rootCause,
        public string $actionPoint,
        public CarbonImmutable $timeline,
        public ?int $ownerId = null,
        public ?string $timelineChangeReason = null,
    ) {}

    /**
     * Build from the `red_band` payload the requests validate, or null when it
     * is incomplete (a draft, or a non-red reading).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $root = trim((string) ($payload['root_cause'] ?? ''));
        $action = trim((string) ($payload['action_point'] ?? ''));
        $timeline = $payload['timeline'] ?? null;

        if ($root === '' || $action === '' || $timeline === null || $timeline === '') {
            return null;
        }

        $owner = $payload['owner_id'] ?? null;
        $reason = isset($payload['timeline_change_reason']) ? trim((string) $payload['timeline_change_reason']) : null;

        return new self(
            $root,
            $action,
            CarbonImmutable::createFromFormat('Y-m-d', substr((string) $timeline, 0, 10))->startOfDay(),
            $owner === null || $owner === '' ? null : (int) $owner,
            $reason === '' ? null : $reason,
        );
    }
}
