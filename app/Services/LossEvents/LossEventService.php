<?php

namespace App\Services\LossEvents;

use App\Events\LossEventAmountChanged;
use App\Events\LossEventCreated;
use App\Models\LossEvent;
use App\Services\AuditTrailService;
use App\Services\ReferenceCodeService;
use App\Support\RiskCalculationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Reporting and amending a loss event (migration Phase 4.3).
 *
 * Lifted out of LossEventController::store() and update(), which carried the
 * canonical/legacy column mapping between them.
 *
 * WHY THE MAPPING IS WORTH THIS MUCH CARE. `loss_events` has two columns per
 * concept — the originals and the 200038 duplicates the forms post — and which
 * one a value lands in, IN WHAT CASE, decides whether a bank's CBN, NFIU and
 * EFCC alerts fire. WP-01 found the fraud alerts had never fired at all,
 * because the category was written lower case while RegulatoryThresholdService
 * matched upper. `tests/Feature/Characterisation/LossEventStoreCharacterisationTest`
 * pins the whole stored row, both column sets, so a refactor cannot repeat it.
 */
class LossEventService
{
    /**
     * The regulatory alerts raised by the most recent report(), for the caller
     * to show the reporter. Not state the service reasons about — a courier.
     *
     * @var list<array<string, mixed>>
     */
    private array $lastAlerts = [];

    /**
     * Form fields that already name canonical columns.
     *
     * Taken by allow-list rather than by unsetting the deprecated keys: an
     * unset-list silently lets a newly added form field through to a column
     * that may not exist, which is how the two-sources-of-truth problem got
     * here in the first place.
     *
     * @var list<string>
     */
    public const PASSTHROUGH_FIELDS = [
        'date_of_loss',
        'date_discovered',
        'business_unit_id',
        'reported_by',
        'currency',
        'corrective_action_summary',
        'regulatory_body',
        'reporting_deadline',
        'is_regulatory_reportable',
    ];

    /**
     * Report a new loss event.
     *
     * THE REGULATORY EVALUATION IS NOT HERE, and its absence is the change. The
     * old store() dispatched LossEventCreated — whose EvaluateRegulatoryThresholds
     * listener evaluates the thresholds — and then constructed a second
     * RegulatoryThresholdService by hand and evaluated them AGAIN, so every
     * reported loss ran the whole Nigerian threshold set twice and wrote its
     * domain events from one of the two passes. The listener is kept; the
     * inline call is gone. `LossEventThresholdEvaluationTest` spies on the
     * service and pins the count at one.
     *
     * @param  array<string, mixed>  $validated
     */
    public function report(array $validated, ?int $actorId): LossEvent
    {
        return DB::transaction(function () use ($validated, $actorId) {
            $validated = $this->applyNearMissRules($validated);

            $lossEvent = LossEvent::create(array_merge(
                $this->passthroughAttributes($validated),
                $this->canonicalAttributes($validated),
                [
                    'organization_id' => TenantContext::organizationId(),
                    'event_reference' => ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'),
                    // The form's `risk_id` is `risk_register_id` in the table —
                    // the one field whose name changes outside the canonical
                    // mapping.
                    'risk_register_id' => $validated['risk_id'] ?? null,
                    'is_regulatory_reportable' => $this->isReportable($validated),
                    'is_near_miss' => (bool) ($validated['is_near_miss'] ?? false),
                    'current_status' => 'REPORTED',
                    'created_by' => $actorId,
                ],
            ));

            // EvaluateRegulatoryThresholds returns what it found, so the
            // reporter can be shown the alerts without the thresholds being
            // evaluated a second time — which is exactly what store() used to
            // do. `alerts` is read by the controller and flashed.
            $this->lastAlerts = $this->alertsFrom(LossEventCreated::dispatch($lossEvent));

            AuditTrailService::record($lossEvent, 'create');

            return $lossEvent;
        });
    }

    /**
     * Amend a reported event.
     *
     * A changed gross amount raises LossEventAmountChanged, which re-evaluates
     * the regulatory thresholds — an event that grows past a limit after the
     * fact still has to be notified.
     *
     * @param  array<string, mixed>  $validated
     */
    public function amend(LossEvent $lossEvent, array $validated, ?int $actorId): LossEvent
    {
        $original = $lossEvent->getAttributes();

        $lossEvent->update(array_merge(
            $this->passthroughAttributes($validated),
            $this->canonicalAttributes($validated),
            [
                'risk_register_id' => $validated['risk_id'] ?? null,
                'updated_by' => $actorId,
            ],
        ));

        AuditTrailService::recordChanges($lossEvent, $original);

        $newKobo = $this->kobo($validated['gross_loss_amount'] ?? 0);
        $oldKobo = (int) ($original['gross_loss_amount_kobo'] ?? 0);

        if ($newKobo !== $oldKobo) {
            LossEventAmountChanged::dispatch($lossEvent, $oldKobo, $newKobo);
        }

        return $lossEvent;
    }

    /**
     * The regulatory alerts the last report() raised.
     *
     * @return list<array<string, mixed>>
     */
    public function lastAlerts(): array
    {
        return $this->lastAlerts;
    }

    /**
     * Pick the violation list out of the event's listener responses.
     *
     * LossEventCreated has more than one listener and only this one answers
     * with an array, so the shape is the selector. A future listener that also
     * returns an array would need naming here rather than being guessed at.
     *
     * @param  mixed  $responses
     * @return list<array<string, mixed>>
     */
    private function alertsFrom($responses): array
    {
        foreach ((array) $responses as $response) {
            if (is_array($response) && $response !== [] && isset($response[0]['type'])) {
                return $response;
            }
        }

        return [];
    }

    /* ------------------------------------------------------------------ */
    /*  Mapping */
    /* ------------------------------------------------------------------ */

    /**
     * A near miss has no money and is classified as one, whatever the form
     * said. Applied before the mapping so every downstream figure agrees.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyNearMissRules(array $validated): array
    {
        if (! ($validated['is_near_miss'] ?? false)) {
            return $validated;
        }

        return array_merge($validated, [
            'gross_loss_amount' => 0,
            'recovery_amount' => 0,
            'insurance_recovery' => 0,
            'event_type' => 'near_miss',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function passthroughAttributes(array $validated): array
    {
        return array_intersect_key($validated, array_flip(self::PASSTHROUGH_FIELDS));
    }

    /**
     * The form's field names translated onto the canonical columns.
     *
     * CASE MATTERS. basel_l1_category, cbn_risk_category and event_severity are
     * stored upper case because that is what RegulatoryThresholdService matches
     * on — the previous lower-case write is why the NFIU STR, EFCC and
     * cyber-fraud alerts never fired. See docs/schema/canonical-columns.md.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function canonicalAttributes(array $validated): array
    {
        $baselCategory = strtoupper($validated['basel_event_type'] ?? 'OTHER');

        return [
            'title' => $validated['event_title'],
            'description' => $validated['event_description'],
            'initial_root_cause' => $validated['root_cause_summary'] ?? null,
            'basel_l1_category' => $baselCategory,
            // The form collects a single Basel classification. Mirroring it
            // into L2 keeps the NOT NULL constraint satisfied and matches the
            // behaviour this replaced; a genuine L2/L3 taxonomy is WP-10 work.
            'basel_l2_category' => $baselCategory,
            'cbn_risk_category' => strtoupper($validated['cbn_loss_category'] ?? 'OTHER'),
            'loss_category' => $validated['event_type'] ?? 'actual_loss',
            'event_severity' => strtoupper($validated['severity'] ?? 'MODERATE'),
            // Money is stored in minor units, with the currency alongside it.
            'gross_loss_amount_kobo' => $this->kobo($validated['gross_loss_amount'] ?? 0),
            'insurance_recovery_kobo' => $this->kobo($validated['insurance_recovery'] ?? 0),
            'other_recovery_kobo' => $this->kobo($validated['recovery_amount'] ?? 0),
        ];
    }

    /**
     * Whether the event is flagged for regulatory reporting.
     *
     * The reporter's own answer wins; otherwise it is derived from the amount.
     * The limit was a bare literal (NGN 10 million) in the controller and is
     * `config('risk.regulatory_reportable_threshold_ngn')` now, overridable per
     * organisation. Note in passing that it does NOT agree with
     * RegulatoryThresholdService's CBN limit of NGN 5,000,000 — that
     * disagreement predates this refactor and is written up in the module
     * notes rather than quietly resolved here.
     *
     * @param  array<string, mixed>  $validated
     */
    private function isReportable(array $validated): bool
    {
        if (array_key_exists('is_regulatory_reportable', $validated)
            && $validated['is_regulatory_reportable'] !== null) {
            return (bool) $validated['is_regulatory_reportable'];
        }

        return (float) ($validated['gross_loss_amount'] ?? 0) >= $this->reportableThreshold();
    }

    /**
     * Naira, from config, with a per-organisation override — resolved by the
     * helper that already owns every other tunable risk input.
     */
    private function reportableThreshold(): float
    {
        return RiskCalculationSettings::regulatoryReportableThresholdNgn(
            TenantContext::organizationIdOrNull()
        );
    }

    private function kobo(float|int|string|null $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }
}
