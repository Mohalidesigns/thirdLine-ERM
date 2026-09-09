<?php

namespace App\Services\Tprm\Reporting;

use App\Models\KeyRiskIndicator;
use App\Models\Tprm\KriLink;
use App\Services\KriMeasureBridge;
use App\Support\Tprm\KriCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * Publishes the nine third-party metrics into the KRI Collection module —
 * FR-RPT-10.
 *
 * IT RECORDS THROUGH `KriMeasureBridge`, NOT BY WRITING `current_value`.
 * The bridge resolves the period, writes the measure value, mirrors onto the
 * legacy tables and reconciles the breach — in that order, for reasons its own
 * comments give. Setting the column directly would produce a KRI whose number
 * moved and whose RAG band, breach record and history did not.
 *
 * A NULL READING IS SKIPPED AND SAID OUT LOUD. `KriCalculator` returns null
 * where a metric has no computable value — no Critical vendors, no executed
 * contracts, no scored engagements — and publishing a zero there would open a
 * red breach, notify an owner and reach a board pack from a division by
 * nothing. The KRI keeps its last real reading and its own staleness shows.
 *
 * ADOPTION IS ONE-WAY AND NON-DESTRUCTIVE. The first publish creates a KRI
 * with the shipped defaults; every publish after that writes only a
 * MEASUREMENT. Thresholds, owner and name belong to the tenant from the moment
 * the KRI exists, and a republish that reset a threshold somebody had tuned
 * would be the module overruling its own user once a day, silently.
 */
class KriPublisher
{
    public function __construct(
        private readonly KriCalculator $calculator,
        private readonly KriMeasureBridge $bridge,
    ) {}

    /**
     * Create any missing KRI for this tenant, and link it.
     *
     * @return array{created: int, existing: int}
     */
    public function adopt(?int $ownerId = null): array
    {
        $created = 0;
        $existing = 0;

        foreach (KriCatalogue::all() as $metric) {
            $link = KriLink::query()->where('metric_code', $metric['code'])->first();

            if ($link !== null) {
                $existing++;

                continue;
            }

            DB::transaction(function () use ($metric, $ownerId, &$created) {
                $kri = $this->createKri($metric, $ownerId);

                KriLink::create([
                    'organization_id' => TenantContext::organizationId(),
                    'metric_code' => $metric['code'],
                    'key_risk_indicator_id' => $kri->getKey(),
                ]);

                $created++;
            });
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * Compute and record a reading for every linked metric.
     *
     * @return array{published: int, skipped: int, failed: int, details: list<array<string, mixed>>}
     */
    public function publish(?CarbonImmutable $on = null, ?int $actorId = null): array
    {
        $on ??= CarbonImmutable::now();

        $result = ['published' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

        $links = KriLink::query()->with('keyRiskIndicator')->get();

        foreach ($links as $link) {
            $metric = KriCatalogue::find($link->metric_code);

            if ($metric === null || $link->keyRiskIndicator === null) {
                $result['skipped']++;
                $result['details'][] = [
                    'metric' => $link->metric_code,
                    'outcome' => 'skipped',
                    'note' => 'The metric or its indicator no longer exists.',
                ];

                continue;
            }

            $reading = $this->calculator->compute($link->metric_code);

            if ($reading['value'] === null) {
                // Not zero. A fabricated reading here opens a breach and
                // reaches a board pack from a division by nothing.
                $result['skipped']++;
                $result['details'][] = [
                    'metric' => $link->metric_code,
                    'name' => $metric['name'],
                    'outcome' => 'skipped',
                    'note' => $reading['note'],
                ];

                continue;
            }

            try {
                $this->bridge->recordMeasurement(
                    $link->keyRiskIndicator,
                    $on,
                    $reading['value'],
                    [
                        'entered_by' => $actorId,
                        'source' => 'tprm',
                        'notes' => $reading['note'],
                    ],
                );

                $link->forceFill(['last_published_at' => $on])->save();

                $result['published']++;
                $result['details'][] = [
                    'metric' => $link->metric_code,
                    'name' => $metric['name'],
                    'outcome' => 'published',
                    'value' => $reading['value'],
                    'note' => $reading['note'],
                ];
            } catch (Throwable $exception) {
                // One metric must not take the sweep down with it.
                Log::error('TPRM KRI publication failed', [
                    'metric' => $link->metric_code,
                    'message' => $exception->getMessage(),
                ]);

                $result['failed']++;
                $result['details'][] = [
                    'metric' => $link->metric_code,
                    'name' => $metric['name'],
                    'outcome' => 'failed',
                    'note' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * What the overview screen shows: every metric, whether it is adopted, and
     * its current reading.
     *
     * @return list<array<string, mixed>>
     */
    public function status(): array
    {
        $links = KriLink::query()->with('keyRiskIndicator')->get()->keyBy('metric_code');

        return array_map(function (array $metric) use ($links) {
            $link = $links->get($metric['code']);
            $reading = $this->calculator->compute($metric['code']);

            return [
                'code' => $metric['code'],
                'kri_code' => $metric['kri_code'],
                'name' => $metric['name'],
                'unit' => $metric['unit'],
                'direction' => $metric['direction'],
                'description' => $metric['description'],
                'formula' => $metric['formula'],
                'published' => $link !== null,
                'kri_id' => $link?->key_risk_indicator_id,
                'last_published_at' => $link?->last_published_at?->toDayDateTimeString(),
                'current_status' => $link?->keyRiskIndicator?->current_status,
                // The live figure, whether or not it has been published — and
                // null where it cannot be computed, with the reason.
                'value' => $reading['value'],
                'note' => $reading['note'],
            ];
        }, KriCatalogue::all());
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    private function createKri(array $metric, ?int $ownerId): KeyRiskIndicator
    {
        $direction = $metric['direction'];
        $green = (float) $metric['green'];
        $red = (float) $metric['red'];

        $bands = $direction === KriCatalogue::HIGHER_IS_WORSE
            ? [
                'green_threshold_min' => null, 'green_threshold_max' => $green,
                'amber_threshold_min' => $green, 'amber_threshold_max' => $red,
                'red_threshold_min' => $red, 'red_threshold_max' => null,
            ]
            : [
                'green_threshold_min' => $green, 'green_threshold_max' => null,
                'amber_threshold_min' => $red, 'amber_threshold_max' => $green,
                'red_threshold_min' => null, 'red_threshold_max' => $red,
            ];

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => TenantContext::organizationId(),
            'kri_code' => $metric['kri_code'],
            // `key_risk_indicators` carries two names for several things — the
            // original NOT NULL columns and the alignment columns — and
            // KriService writes both. So does this.
            'name' => $metric['name'],
            'kri_name' => $metric['name'],
            'description' => $metric['description'],
            'metric_formula' => $metric['formula'],
            'data_source' => 'Third-Party Risk Management module',
            'measurement_frequency' => $metric['frequency'],
            'unit_of_measure' => $metric['unit'],
            'measurement_unit' => $metric['unit'],
            'direction' => $direction,
            'threshold_direction' => $direction === KriCatalogue::HIGHER_IS_WORSE
                ? 'higher_worse'
                : 'lower_worse',
            'owner_id' => $ownerId,
            'kri_owner_id' => $ownerId,
            // Automated in the sense that a scheduled command supplies the
            // reading. It is NOT automated in the sense of needing no owner —
            // a KRI nobody owns is a number nobody acts on.
            'is_automated' => true,
            'automation_config' => ['source' => 'tprm', 'metric_code' => $metric['code']],
            'is_active' => true,
        ], $bands));
    }
}
