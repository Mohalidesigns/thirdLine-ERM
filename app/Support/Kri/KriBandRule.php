<?php

namespace App\Support\Kri;

use App\Models\KeyRiskIndicator;
use App\Models\MeasureThreshold;
use App\Models\Period;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Support\Measures\KriMeasureMigrator;

/**
 * THE ONLY SERVER ENTRY POINT FOR "WHICH BAND IS THIS VALUE IN" on the
 * departmental KRI screens (ADR 0026 section 6.1).
 *
 * Every caller uses it: UpdateKriRequest, RecordKriMeasurementRequest, the
 * champion draft and submit requests, and every presenter that sends a
 * `bandRule` prop. It does not reimplement the band walk - it delegates to
 * MeasureService / MeasureThreshold::bandFor(), which is what the engine itself
 * evaluates, so a screen and the engine cannot disagree.
 *
 * THE EDGES, FROZEN: a value exactly on the red boundary is RED in both
 * directions; a value exactly on the green boundary is AMBER in both.
 *
 * The browser mirror (resources/js/lib/kri-band.js) is a display mirror only;
 * the server recomputes on every write.
 *
 * @phpstan-type Band array{code: string, label: string, color: string, min: ?float, max: ?float}
 * @phpstan-type Rule array{direction: string, bands: list<Band>}
 */
class KriBandRule
{
    public function __construct(
        private readonly MeasureService $measures,
        private readonly KriMeasureBridge $bridge,
        private readonly KriMeasureMigrator $migrator,
    ) {}

    /**
     * The exact band list and direction bandCodeFor() evaluates for this KRI in
     * this period. Empty bands when no threshold is in force.
     *
     * @return array{direction: string, bands: list<array{code: string, label: string, color: string, min: ?float, max: ?float}>}
     */
    public function forReading(KeyRiskIndicator $kri, Period $period): array
    {
        $measure = $this->bridge->measureFor($kri);
        $objectId = $this->measures->objectIdFor($kri);

        $threshold = $measure === null || $objectId === null
            ? null
            : $this->measures->activeThreshold($measure, $objectId, $period->end_date?->toDateString());

        if (! $threshold instanceof MeasureThreshold) {
            return ['direction' => $this->migrator->directionOf($kri), 'bands' => []];
        }

        $bands = $this->measures->resolveBands($threshold, ['period_id' => $period->id, 'object_id' => $objectId]);

        return [
            'direction' => $threshold->direction === 'lower_worse' ? 'lower_worse' : 'higher_worse',
            'bands' => $this->normalise($bands),
        ];
    }

    /**
     * Attach the measurement period the rule was resolved for (Amendment 1,
     * A1.2). The client may show a live band only while the entered date lies
     * inside it; for any other date the server's 422 is the authority.
     *
     * @param  array{direction: string, bands: list<array<string, mixed>>}  $rule
     * @return array{direction: string, bands: list<array<string, mixed>>, period: array{id: int, label: string, start_date: string, end_date: string}}
     */
    public static function withPeriod(array $rule, Period $period): array
    {
        return $rule + ['period' => [
            'id' => (int) $period->id,
            'label' => (string) $period->name,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
        ]];
    }

    /** Literally MeasureService::bandCodeFor(), not a reimplementation. */
    public function bandForReading(KeyRiskIndicator $kri, Period $period, float $value): ?string
    {
        $measure = $this->bridge->measureFor($kri);
        $objectId = $this->measures->objectIdFor($kri);

        if ($measure === null || $objectId === null) {
            return null;
        }

        return $this->measures->bandCodeFor($measure, $objectId, $value, $period);
    }

    /**
     * The rule an unsaved set of boundaries would produce. The Edit form needs
     * it because the user is typing the boundaries.
     *
     * @param  string  $formDirection  'higher_is_worse' | 'lower_is_worse'
     * @return array{direction: string, bands: list<array{code: string, label: string, color: string, min: ?float, max: ?float}>}
     */
    public function forBoundaries(string $formDirection, float|int|string|null $green, float|int|string|null $red): array
    {
        $higher = in_array($formDirection, ['higher_is_worse', 'higher_worse'], true);

        $kri = new KeyRiskIndicator;
        $kri->forceFill(array_merge(
            ['threshold_direction' => $higher ? 'higher_worse' : 'lower_worse'],
            self::bandColumns($higher ? 'higher_is_worse' : 'lower_is_worse', $green, $red),
        ));

        return [
            'direction' => $higher ? 'higher_worse' : 'lower_worse',
            'bands' => $this->normalise($this->migrator->bandsFor($kri)),
        ];
    }

    /**
     * @param  array{direction: string, bands: list<array<string, mixed>>}  $rule
     */
    public function bandOf(array $rule, ?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $threshold = new MeasureThreshold(['direction' => $rule['direction']]);

        return $threshold->bandFor($value, $rule['bands'])['code'] ?? null;
    }

    /**
     * @param  array{direction: string, bands: list<array<string, mixed>>}  $rule
     */
    public function isRed(array $rule, ?float $value): bool
    {
        return $this->bandOf($rule, $value) === 'red';
    }

    /**
     * The two boundaries fanned out across the six band columns. Moved here from
     * KriService (where it was private) so the server has one fan-out.
     *
     * A band set needs TWO numbers. On a higher-is-worse indicator green runs up
     * to the green boundary, red starts at the red boundary, and amber is what
     * lies between - it has no boundary of its own.
     *
     * @return array<string, float|null>
     */
    public static function bandColumns(string $direction, float|int|string|null $green, float|int|string|null $red): array
    {
        $green = $green === null || $green === '' ? null : (float) $green;
        $red = $red === null || $red === '' ? null : (float) $red;

        if ($direction === 'higher_is_worse') {
            return [
                'green_threshold_min' => null,
                'green_threshold_max' => $green,
                'amber_threshold_min' => $green,
                'amber_threshold_max' => $red,
                'red_threshold_min' => $red,
                'red_threshold_max' => null,
            ];
        }

        return [
            'green_threshold_min' => $green,
            'green_threshold_max' => null,
            'amber_threshold_min' => $red,
            'amber_threshold_max' => $green,
            'red_threshold_min' => null,
            'red_threshold_max' => $red,
        ];
    }

    /**
     * Keep the engine's order and bounds; present them in one stable shape.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<array{code: string, label: string, color: string, min: ?float, max: ?float}>
     */
    private function normalise(array $bands): array
    {
        $out = [];

        foreach ($bands as $band) {
            $out[] = [
                'code' => (string) ($band['code'] ?? ''),
                'label' => (string) ($band['label'] ?? ucfirst((string) ($band['code'] ?? ''))),
                'color' => (string) ($band['color'] ?? ''),
                'min' => isset($band['min']) ? (float) $band['min'] : null,
                'max' => isset($band['max']) ? (float) $band['max'] : null,
            ];
        }

        return $out;
    }
}
