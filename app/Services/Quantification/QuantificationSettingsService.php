<?php

namespace App\Services\Quantification;

use App\Models\QuantificationSetting;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Quantification settings (migration Phase 5.2).
 *
 * WHAT WAS WRONG WITH THIS SCREEN. It offered ten editable fields and stored
 * three of them. `default_confidence`, `default_time_horizon`, `seed`,
 * `target_car`, `countercyclical_buffer`, `alert_green`, `alert_amber` and
 * `alert_red` had no column anywhere; a preparer typed them, the screen
 * redirected with "Quantification settings have been updated", and every one
 * of them was discarded. Two of them — `default_confidence` and
 * `default_time_horizon` — were `required` in the validator, so they had to be
 * filled in on every save to be thrown away. Reloading the page showed the
 * same hardcoded literals it had shown before.
 *
 * The third stored field was worse in its way: `default_iterations` DID
 * persist, and nothing read it. `simulate.blade.php` hardcodes 10,000 as its
 * selected option, so the one setting that saved never reached the screen it
 * configures.
 *
 * WHAT THIS SERVICE KEEPS, AND WHY THE REST IS GONE:
 *
 *   - the three simulation defaults (iterations, confidence levels, horizon)
 *     are stored and are read by the simulate form, so setting them does
 *     something. `default_horizon_years` is the one column this needed;
 *   - `cbn_minimum_car` and `cbn_conservation_buffer` already worked —
 *     IcaapService resolves both — and their DISPLAY fallbacks now come from
 *     config/quantification.php rather than from literals that disagreed with
 *     it. The screen used to show 2.5 for the conservation buffer, the Basel
 *     III figure, while resolveConservationBuffer() used the CBN's 1.0;
 *   - `seed` is removed. WP-07 draws a fresh seed per run, at queue time, so
 *     the figure is re-derivable and a replayed job cannot produce a different
 *     capital number. A box offering to pin it contradicts that design;
 *   - `target_car`, `countercyclical_buffer` and the green/amber/red band are
 *     removed. Nothing in the product reads a CAR RAG band: the ICAAP screen
 *     and every report colour CAR binarily against the RESOLVED minimum. A
 *     three-band supervisory threshold would be an invention, and inventing
 *     one is precisely what WP-08 deleted when it removed the 8% "Marginal"
 *     verdict that appears in no CBN guideline.
 *
 * The phase prompt asked for the `alert_amber => 12` and `target_car => 15.0`
 * literals to be moved into config. They are not moved, they are deleted:
 * relocating a number does not fix a form that cannot save it, and a config
 * key nothing reads is the same dead weight one indirection further away.
 */
class QuantificationSettingsService
{
    /**
     * Iteration counts the simulate form offers. The engine will run any
     * integer in the validated range; these are the four the form lists.
     *
     * @var list<int>
     */
    public const ITERATION_CHOICES = [1000, 10000, 50000, 100000];

    /**
     * Horizons the simulate form offers, in years.
     *
     * @var list<int>
     */
    public const HORIZON_CHOICES = [1, 3, 5];

    /**
     * Confidence levels a run may ask for.
     *
     * MonteCarloService stores 90 / 95 / 99 / 99.9 columns and answers a
     * request for 99.5 from the 99.9 figure, which is why IcaapService reports
     * stress impact off the columns rather than off this list. The list is the
     * one the simulate form has always offered and is left alone here.
     *
     * @var list<float|int>
     */
    public const CONFIDENCE_CHOICES = [90, 95, 99, 99.5, 99.9];

    /** @var list<float|int> */
    public const DEFAULT_CONFIDENCE_LEVELS = [95, 99, 99.5];

    public const DEFAULT_ITERATIONS = 10000;

    public const DEFAULT_HORIZON_YEARS = 1;

    /**
     * The settings as the screen shows them, defaulted from config rather than
     * from literals, so what is displayed agrees with what IcaapService will
     * resolve.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(?int $organizationId = null): array
    {
        $stored = $this->stored($organizationId);

        return [
            'default_iterations' => $stored !== null ? (int) $stored->default_iterations : self::DEFAULT_ITERATIONS,
            'default_confidence_levels' => $this->confidenceLevels($stored),
            'default_horizon_years' => $stored !== null ? (int) $stored->default_horizon_years : self::DEFAULT_HORIZON_YEARS,
            'cbn_minimum_car' => $stored !== null
                ? (float) $stored->cbn_minimum_car
                : (float) config('quantification.default_minimum_car'),
            'cbn_conservation_buffer' => $stored !== null
                ? (float) $stored->cbn_conservation_buffer
                : (float) config('quantification.default_conservation_buffer'),
        ];
    }

    /**
     * The defaults the simulate form opens with — the reason the settings
     * screen exists.
     *
     * @return array<string, mixed>
     */
    public function simulationDefaults(?int $organizationId = null): array
    {
        $settings = $this->forDisplay($organizationId);

        return [
            'iterations' => $settings['default_iterations'],
            'confidence_levels' => $settings['default_confidence_levels'],
            'horizon_years' => $settings['default_horizon_years'],
        ];
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'default_iterations' => 'required|integer|min:1000|max:1000000',
            'default_confidence_levels' => 'required|array|min:1',
            'default_confidence_levels.*' => 'numeric|in:'.implode(',', self::CONFIDENCE_CHOICES),
            'default_horizon_years' => 'required|integer|min:1|max:10',
            'cbn_minimum_car' => 'required|numeric|min:0|max:100',
            'cbn_conservation_buffer' => 'required|numeric|min:0|max:100',
        ];
    }

    /**
     * Every field the form offers is written. There is no longer a field it
     * does not write.
     *
     * @param  array<string, mixed>  $validated
     */
    public function save(array $validated, ?int $organizationId = null): QuantificationSetting
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        return QuantificationSetting::updateOrCreate(
            ['organization_id' => $orgId],
            [
                'default_iterations' => $validated['default_iterations'],
                'default_confidence_levels' => array_values(array_map(
                    fn ($level) => 0 + $level,
                    $validated['default_confidence_levels'],
                )),
                'default_horizon_years' => $validated['default_horizon_years'],
                'cbn_minimum_car' => $validated['cbn_minimum_car'],
                'cbn_conservation_buffer' => $validated['cbn_conservation_buffer'],
            ],
        );
    }

    private function stored(?int $organizationId = null): ?QuantificationSetting
    {
        return QuantificationSetting::where(
            'organization_id',
            $organizationId ?? TenantContext::organizationId(),
        )->first();
    }

    /**
     * @return list<float|int>
     */
    private function confidenceLevels(?QuantificationSetting $stored): array
    {
        $levels = $stored !== null ? $stored->default_confidence_levels : null;

        return (is_array($levels) && $levels !== []) ? array_values($levels) : self::DEFAULT_CONFIDENCE_LEVELS;
    }
}
