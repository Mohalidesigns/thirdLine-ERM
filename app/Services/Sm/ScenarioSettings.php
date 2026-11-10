<?php

namespace App\Services\Sm;

use App\Models\Organization;
use App\Support\Kri\KriWorkingCalendar;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The tenant's Scenario Modelling settings, read from the existing
 * `organizations.settings` JSON bag (ADR 0027 addendum-3).
 *
 * WHY NOT config() ALONE. `BcmsSettings`' docblock gives the reason verbatim:
 * "config() is process-wide and a queue worker holds it across tenants, so a
 * reminder job for a Lagos tenant that read config('bcms.reminder_send_time')
 * would use whichever tenant warmed the cache." `suggestedReviewDueOn` is rendered
 * inside a request, so config-only would work today — but the same value is read
 * by Phase 4's review-cadence prompt (plan §4.4 capability 10), which is
 * SCHEDULED AND QUEUED. A setting that is right in a request and wrong in a worker
 * ships silently, which is Gate G0 criterion 4's whole subject.
 *
 * WHY NOT A NEW COLUMN OR A NEW TABLE. `organizations.settings` is already a
 * nullable JSON bag and already carries per-tenant settings of exactly this kind
 * (`reporting_currency`, `default_fx_rate_type`, written by
 * 2026_08_12_120006 — whose own docblock makes the point: "organizations.settings
 * is already a JSON bag, so this is a data change rather than a schema one"). A
 * new column is a structural change to a shipped table for one integer; an
 * `sm_settings` table on the `Bcms\Setting` pattern is the right shape for a module
 * with a dozen governed settings and an admin screen, and it is a SIXTH TABLE,
 * outside §A1.1's boundary. If Phase 5's Admin screen accumulates enough Sm
 * settings to earn one, that is an amendment naming Phase 5, and the keys move.
 *
 * WHY NOT `QuantificationSetting`. Another module's typed row, rendered and
 * validated by the Quantification settings screen. Plan §6.6 puts the PERCENTILE
 * MAPPING there because that setting belongs to the bridge; a scenario review
 * cadence does not, and writing it there would put an Sm field on a screen that
 * does not know about it.
 *
 * The shape is `CurrencyService`'s exactly (app/Services/CurrencyService.php:213
 * –248): a private `setting()` that falls back to `TenantContext::
 * organizationIdOrNull()` and json-decodes the bag, a class const default, and —
 * as `defaultRateType()` does — VALIDATION OF THE STORED VALUE BEFORE IT IS
 * ACCEPTED. The bag is untyped JSON an administrator edits, and `"twelve"` or `-3`
 * fed to `addMonths()` is a TypeError or a review date in the past.
 *
 * Methods are static, matching the addendum's own call spelling
 * (`ScenarioSettings::reviewCadenceMonths()`) and `KriWorkingCalendar::today()`
 * beside it. There is no per-instance state to hold, and the memoisation
 * CurrencyService would want is not worth it for one column read per request.
 */
class ScenarioSettings
{
    /** The key in `organizations.settings`. Frozen by addendum-3. */
    public const SETTINGS_KEY = 'sm_review_cadence_months';

    /**
     * The shipped default. Plan §4.2's cadence is annual and capability 10 in plan
     * §3.3 is the annual refresh, so 12 is the shipped value, not an invented one.
     */
    public const DEFAULT_REVIEW_CADENCE_MONTHS = 12;

    /**
     * The accepted range, frozen by addendum-3. Anything outside it — including a
     * missing key, a string, a float, a negative or zero — falls back to the
     * default rather than reaching `addMonths()`.
     */
    public const MIN_REVIEW_CADENCE_MONTHS = 1;

    public const MAX_REVIEW_CADENCE_MONTHS = 120;

    /**
     * How many months ahead a scenario's next review is suggested.
     *
     * Per tenant, falling back to config('sm.review_cadence_months') and then to
     * the const. A string "12" from the JSON bag is accepted (an administrator
     * typing into a JSON editor produces those); "twelve", 12.5, 0 and -3 are not.
     */
    public static function reviewCadenceMonths(?int $organizationId = null): int
    {
        $stored = self::setting($organizationId, self::SETTINGS_KEY);

        if (self::isUsableCadence($stored)) {
            return (int) $stored;
        }

        $configured = config('sm.review_cadence_months', self::DEFAULT_REVIEW_CADENCE_MONTHS);

        return self::isUsableCadence($configured)
            ? (int) $configured
            : self::DEFAULT_REVIEW_CADENCE_MONTHS;
    }

    /**
     * Today in Africa/Lagos plus the tenant's cadence, as a `Y-m-d` string.
     *
     * SERVER-SIDE ONLY, and computed through `KriWorkingCalendar::today()` rather
     * than `now()`: `config/app.php` sets the application timezone to UTC, so
     * `now()->toDateString()` is YESTERDAY for the first hour of a Lagos day, and
     * a suggested review date computed that way is off by one for every scenario
     * created in that hour. Reusing the helper's TIMEZONE const also avoids adding
     * a fourth spelling of 'Africa/Lagos' to the codebase (addendum-2's argument,
     * applied to addendum-3).
     *
     * Spec 01 §5.6.4's "(prop decision) the browser never computes it" is upheld:
     * no `+12 months` in JSX, because a client-side date constant is a fabricated
     * figure (standard §5) and would drift from the tenant's setting the first time
     * an administrator changed it.
     *
     * Never null — the cadence always resolves, so there is always a suggestion.
     */
    public static function suggestedReviewDueOn(?int $organizationId = null): string
    {
        return KriWorkingCalendar::today()
            ->addMonths(self::reviewCadenceMonths($organizationId))
            ->toDateString();
    }

    /**
     * Is $value an integer month count inside the frozen range?
     *
     * Strict on type: a numeric STRING is accepted because a JSON bag edited by
     * hand produces them and `"12"` means twelve, but `true`, `12.5` and `"12.5"`
     * are refused rather than silently truncated into a cadence nobody chose.
     */
    private static function isUsableCadence(mixed $value): bool
    {
        if (is_int($value)) {
            $months = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $months = (int) trim($value);
        } else {
            return false;
        }

        return $months >= self::MIN_REVIEW_CADENCE_MONTHS
            && $months <= self::MAX_REVIEW_CADENCE_MONTHS;
    }

    /**
     * One key out of the organisation's settings bag.
     *
     * Copied from CurrencyService::setting() deliberately, including the
     * `is_string()` branch: MariaDB hands a `json` column back as LONGTEXT and
     * MySQL 8 hands it back decoded, so the value is a string on one engine and an
     * array on the other. Reading it without that branch is a per-engine defect.
     */
    private static function setting(?int $organizationId, string $key): mixed
    {
        $organizationId = $organizationId ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return null;
        }

        $settings = Organization::query()->whereKey($organizationId)->value('settings');

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return is_array($settings) ? ($settings[$key] ?? null) : null;
    }
}
