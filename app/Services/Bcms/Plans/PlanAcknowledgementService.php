<?php

namespace App\Services\Bcms\Plans;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanAttestation;
use App\Models\User;
use App\Services\Bcms\AudienceResolver;
use App\Support\Bcms\AudienceRule;
use InvalidArgumentException;

/**
 * Read-acknowledgement of a plan — ISO 22301 clause 7.4 evidence.
 *
 * NOT ITS OWN TABLE. `bcms_plan_attestations` already holds a dated, signed
 * attestation of a plan with the signer's name and role snapshotted; a reader
 * acknowledgement is exactly that, and `attestation_type` exists to tell the
 * kinds apart. ADR 0011 argues it at length. The short version: a second table
 * would have been a second export path and a second answer to "who has signed
 * what", built because the first table's docblock happened to mention
 * directors.
 *
 * ANYTHING COUNTING ATTESTATIONS MUST FILTER ON THE TYPE. A board-attestation
 * count that silently included every branch manager who ticked "I have read
 * this" is a governance number that is wrong, and wrong in the direction that
 * flatters.
 *
 * COVERAGE HAS A DENOMINATOR OR IT IS NOT REPORTED. "12 acknowledgements" is
 * not evidence of anything; "12 of 41" is. The denominator is the plan's
 * `distribution_rule`, resolved through the audience grammar every other
 * targeting feature uses. A plan with no distribution rule reports its
 * acknowledgements and a NULL percentage — never 100%.
 */
class PlanAcknowledgementService
{
    public const TYPE = 'read';

    public const STATEMENT = 'I confirm that I have read this plan, that I understand the role it assigns me, '
        .'and that I know where to find it during a disruption.';

    public function __construct(private readonly AudienceResolver $audience) {}

    /**
     * Record that this user has read this plan version.
     *
     * ONLY AN APPROVED VERSION CAN BE ACKNOWLEDGED. Acknowledging a draft is
     * evidence of nothing — the document changes afterwards — and clause 7.4 is
     * about people knowing the plan they will actually be handed.
     */
    public function acknowledge(Plan $plan, User $reader, ?string $ipAddress = null, ?int $periodYear = null): PlanAttestation
    {
        if ($plan->status !== 'approved') {
            throw new InvalidArgumentException(
                'Only an approved plan version can be acknowledged. A draft changes after it is read, so an '
                .'acknowledgement of one is evidence of nothing.'
            );
        }

        return PlanAttestation::query()->updateOrCreate(
            [
                'plan_id' => $plan->getKey(),
                'attestation_type' => self::TYPE,
                // Part of the key, so re-acknowledging in a new year records a
                // new act rather than overwriting last year's. That is what an
                // annual re-acknowledgement cycle needs, and it comes free from
                // reusing this table.
                'period_year' => $periodYear ?? (int) now()->year,
                'attested_by' => $reader->getKey(),
            ],
            [
                'organization_id' => $plan->organization_id,
                // Snapshotted with the signature: somebody who has since left
                // still acknowledged on the day they acknowledged.
                'attested_by_name' => $reader->name,
                'attested_by_role' => $reader->job_title,
                'attested_at' => now(),
                'statement' => self::STATEMENT,
                'ip_address' => $ipAddress,
                'iso_clause_ref' => IsoClauseRef::Iso22301_7_4->value,
            ]
        );
    }

    /**
     * Who has acknowledged this plan, and who was meant to.
     *
     * @return array{
     *   acknowledged: list<array<string, mixed>>, outstanding: list<array<string, mixed>>,
     *   acknowledged_count: int, distribution_count: int, percentage: ?float,
     *   unreachable_count: int, note: ?string
     * }
     */
    public function coverage(Plan $plan, ?int $periodYear = null): array
    {
        $year = $periodYear ?? (int) now()->year;

        $records = PlanAttestation::query()
            ->where('plan_id', $plan->getKey())
            ->where('attestation_type', self::TYPE)
            ->where('period_year', $year)
            ->orderBy('attested_at')
            ->get();

        $acknowledged = $records->map(fn (PlanAttestation $a) => [
            'user_id' => (int) $a->attested_by,
            'name' => $a->attested_by_name,
            'role' => $a->attested_by_role,
            'acknowledged_at' => $a->attested_at?->toIso8601String(),
        ])->all();

        $acknowledgedIds = $records->pluck('attested_by')->map(fn ($id) => (int) $id)->all();

        $rule = AudienceRule::fromJson($plan->distribution_rule);

        if ($rule === null) {
            return [
                'acknowledged' => $acknowledged,
                'outstanding' => [],
                'acknowledged_count' => count($acknowledged),
                'distribution_count' => 0,
                // Null, not 100. A plan with nobody on its distribution list has
                // not achieved full coverage; nobody has been asked.
                'percentage' => null,
                'unreachable_count' => 0,
                'note' => 'This plan has no distribution list, so there is no population to measure '
                    .'acknowledgement against. Set one before quoting a coverage figure.',
            ];
        }

        $contacts = $this->audience->resolve($rule);

        $outstanding = [];
        $unreachable = 0;
        $expected = 0;

        foreach ($contacts as $contact) {
            // A contact with no platform login cannot press the button. They
            // are counted as unreachable rather than outstanding, and named as
            // such: a guard or a contractor on the distribution list is a real
            // recipient of a printed plan and a false negative on a screen.
            if ($contact->user_id === null) {
                $unreachable++;

                continue;
            }

            $expected++;

            if (in_array((int) $contact->user_id, $acknowledgedIds, true)) {
                continue;
            }

            $outstanding[] = [
                'user_id' => (int) $contact->user_id,
                'name' => $contact->full_name,
                'role' => $contact->title,
                'business_unit_id' => $contact->business_unit_id,
            ];
        }

        // Only people ON the distribution list count towards its coverage.
        // Somebody who read the plan without being on the list has still
        // acknowledged it — the row is kept — but they cannot raise a
        // percentage that measures whether the intended audience was reached.
        $confirmed = $expected - count($outstanding);

        return [
            'acknowledged' => $acknowledged,
            'outstanding' => $outstanding,
            'acknowledged_count' => count($acknowledged),
            'distribution_count' => $expected,
            'percentage' => $expected === 0 ? null : round(($confirmed / $expected) * 100, 1),
            'unreachable_count' => $unreachable,
            'note' => $unreachable === 0
                ? null
                : $unreachable.' people on this distribution list have no platform login and cannot acknowledge '
                    .'here. They are excluded from the percentage; distributing to them is a manual step.',
        ];
    }

    /**
     * The clause 7.4 evidence export: one row per acknowledgement.
     *
     * @return list<array<string, mixed>>
     */
    public function evidence(Plan $plan): array
    {
        return PlanAttestation::query()
            ->where('plan_id', $plan->getKey())
            ->where('attestation_type', self::TYPE)
            ->orderBy('period_year')
            ->orderBy('attested_at')
            ->get()
            ->map(fn (PlanAttestation $a) => [
                'plan' => $plan->title,
                'version' => $plan->version,
                'period_year' => (int) $a->period_year,
                'name' => $a->attested_by_name,
                'role' => $a->attested_by_role,
                'acknowledged_at' => $a->attested_at?->toIso8601String(),
                // The words they agreed to, stored with the signature. An
                // acknowledgement whose statement could be edited afterwards
                // acknowledges nothing.
                'statement' => $a->statement,
                'ip_address' => $a->ip_address,
                'iso_clause_ref' => $a->iso_clause_ref,
            ])
            ->all();
    }

    /** Whether this user has acknowledged this plan in the given year. */
    public function hasAcknowledged(Plan $plan, User $user, ?int $periodYear = null): bool
    {
        return PlanAttestation::query()
            ->where('plan_id', $plan->getKey())
            ->where('attestation_type', self::TYPE)
            ->where('period_year', $periodYear ?? (int) now()->year)
            ->where('attested_by', $user->getKey())
            ->exists();
    }
}
