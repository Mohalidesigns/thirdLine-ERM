<?php

use App\Services\CurrencyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-04 TASK 6 — every organisation reports in a named currency.
 *
 * organizations.settings is already a JSON bag, so this is a data change rather
 * than a schema one. It is still worth a migration: CurrencyService falls back
 * to NGN when the key is absent, and a fallback that is never made explicit is
 * a fallback nobody knows they are relying on until a subsidiary reporting in
 * cedis produces a group total in naira without saying so.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->orderBy('id')->each(function ($organization) {
            $settings = $organization->settings;
            $settings = is_string($settings) ? json_decode($settings, true) : $settings;
            $settings = is_array($settings) ? $settings : [];

            $changed = false;

            if (! array_key_exists('reporting_currency', $settings)) {
                $settings['reporting_currency'] = CurrencyService::DEFAULT_REPORTING_CURRENCY;
                $changed = true;
            }

            if (! array_key_exists('default_fx_rate_type', $settings)) {
                // CBN official is the rate a Nigerian regulated institution
                // files against; a fintech marking to NAFEM changes this in
                // organisation settings.
                $settings['default_fx_rate_type'] = CurrencyService::DEFAULT_RATE_TYPE;
                $changed = true;
            }

            if ($changed) {
                DB::table('organizations')->where('id', $organization->id)
                    ->update(['settings' => json_encode($settings)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('organizations')->orderBy('id')->each(function ($organization) {
            $settings = $organization->settings;
            $settings = is_string($settings) ? json_decode($settings, true) : $settings;

            if (! is_array($settings)) {
                return;
            }

            unset($settings['reporting_currency'], $settings['default_fx_rate_type']);

            DB::table('organizations')->where('id', $organization->id)
                ->update(['settings' => json_encode($settings)]);
        });
    }
};
