<?php

namespace App\Console\Commands;

use App\Models\FxRate;
use App\Services\AuditTrailService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Records the CBN official exchange rates for a date.
 *
 * Two ways in: the feed configured in config/measures.php, or an explicit
 * --rate= override entered by a person. Both write a platform-wide fx_rates row
 * (organization_id NULL) with the source recorded, and both leave an audit
 * trail entry — a manual FX override flows straight into whether a loss event
 * crosses a CBN reporting threshold, so who typed it matters.
 *
 * With no feed configured and no override the command does NOTHING and says so.
 * A fabricated rate is worse than a missing one: a missing rate makes
 * CurrencyService throw where it is used, which is visible; a made-up rate
 * produces a plausible number in a regulatory return.
 */
class FetchCbnRates extends Command
{
    protected $signature = 'fx:fetch-cbn-rates
                            {--date= : Rate date (defaults to today)}
                            {--currency= : Restrict to one currency, e.g. USD}
                            {--rate= : Manual override — units of NGN per one unit of --currency}
                            {--type=cbn_official : Rate type to record}
                            {--source= : Where the rate came from, for the audit trail}';

    protected $description = 'Record CBN official (or overridden) exchange rates against the naira';

    public function handle(): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? 'today')->toDateString();
        $rateType = (string) $this->option('type');

        if ($this->option('rate') !== null) {
            return $this->recordOverride($date, $rateType);
        }

        $url = config('measures.cbn_rate_url');

        if (empty($url)) {
            $this->warn(
                'No CBN rate feed is configured (config/measures.php -> cbn_rate_url). '
                .'Nothing was recorded. Enter a rate manually with '
                .'`php artisan fx:fetch-cbn-rates --currency=USD --rate=1650.25 --source="CBN daily rates"`.'
            );

            return self::SUCCESS;
        }

        return $this->fetchFeed($url, $date, $rateType);
    }

    /* ------------------------------------------------------------------ */

    private function recordOverride(string $date, string $rateType): int
    {
        $currency = strtoupper((string) $this->option('currency'));
        $rate = (float) $this->option('rate');

        if ($currency === '' || $rate <= 0) {
            $this->error('A manual override needs both --currency and a positive --rate.');

            return self::FAILURE;
        }

        $this->write($currency, 'NGN', $date, $rateType, $rate, $this->option('source') ?: 'Manual override');

        $this->info("Recorded {$currency}/NGN = {$rate} ({$rateType}) for {$date}.");

        return self::SUCCESS;
    }

    private function fetchFeed(string $url, string $date, string $rateType): int
    {
        $wanted = $this->option('currency')
            ? [strtoupper((string) $this->option('currency'))]
            : (array) config('measures.cbn_rate_currencies', []);

        try {
            $response = Http::timeout(20)->acceptJson()->get($url, ['date' => $date]);
        } catch (\Throwable $exception) {
            $this->error('CBN rate feed unreachable: '.$exception->getMessage().'. No rates were recorded.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('CBN rate feed returned HTTP '.$response->status().'. No rates were recorded.');

            return self::FAILURE;
        }

        $rates = $this->extractRates($response->json());
        $written = 0;

        foreach ($wanted as $currency) {
            $rate = $rates[strtoupper($currency)] ?? null;

            if ($rate === null || $rate <= 0) {
                $this->warn("Feed carried no usable rate for {$currency}; skipped.");

                continue;
            }

            $this->write(strtoupper($currency), 'NGN', $date, $rateType, (float) $rate, $url);
            $written++;
        }

        $this->info("Recorded {$written} rate(s) for {$date}.");

        return self::SUCCESS;
    }

    /**
     * Pull currency => rate out of whatever shape the feed returns.
     *
     * Deliberately conservative: anything that is not a positive number under a
     * three-letter key is ignored rather than coerced.
     *
     * @return array<string, float>
     */
    private function extractRates(mixed $payload): array
    {
        $rates = [];

        $walk = function ($node) use (&$walk, &$rates) {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if (is_string($key) && preg_match('/^[A-Za-z]{3}$/', $key) && is_numeric($value) && (float) $value > 0) {
                    $rates[strtoupper($key)] = (float) $value;

                    continue;
                }

                if (is_array($value)) {
                    // A row-shaped feed: {"currency": "USD", "rate": 1650.25}
                    $currency = $value['currency'] ?? $value['code'] ?? null;
                    $rate = $value['rate'] ?? $value['buying_rate'] ?? $value['central_rate'] ?? null;

                    if (is_string($currency) && is_numeric($rate) && (float) $rate > 0) {
                        $rates[strtoupper($currency)] = (float) $rate;

                        continue;
                    }

                    $walk($value);
                }
            }
        };

        $walk($payload);

        return $rates;
    }

    private function write(string $from, string $to, string $date, string $rateType, float $rate, ?string $source): void
    {
        // Platform rates are untenanted by design — what CBN published is the
        // same fact for every organisation on the deployment.
        TenantContext::bypass(function () use ($from, $to, $date, $rateType, $rate, $source) {
            $existing = FxRate::withoutGlobalScopes()
                ->whereNull('organization_id')
                ->where('from_currency', $from)
                ->where('to_currency', $to)
                ->where('rate_date', $date)
                ->where('rate_type', $rateType)
                ->first();

            $previous = $existing?->rate;

            $fxRate = $existing ?? new FxRate([
                'organization_id' => null,
                'from_currency' => $from,
                'to_currency' => $to,
                'rate_date' => $date,
                'rate_type' => $rateType,
            ]);

            $fxRate->fill([
                'rate' => $rate,
                'source' => $source,
                'captured_at' => now(),
            ])->save();

            if ($previous !== null && (float) $previous === $rate) {
                return;
            }

            $action = $previous === null ? 'fx_rate_recorded' : 'fx_rate_amended';
            $reason = "{$from}/{$to} {$rateType} for {$date} from ".($source ?: 'unspecified source');

            // risk_audit_trail is tenant-scoped and a platform rate belongs to
            // no tenant, so an untenanted run records to the application log
            // instead of attributing the change to an arbitrary organisation.
            if (TenantContext::hasOrganization()) {
                AuditTrailService::record($fxRate, $action, 'rate', $previous, $rate, $reason);

                return;
            }

            Log::info('FX rate '.$action, [
                'fx_rate_id' => $fxRate->id,
                'pair' => "{$from}/{$to}",
                'rate_type' => $rateType,
                'rate_date' => $date,
                'previous' => $previous,
                'rate' => $rate,
                'source' => $source,
                'actor' => auth()->id(),
            ]);
        }, 'fx:fetch-cbn-rates writes platform-wide rates');
    }
}
