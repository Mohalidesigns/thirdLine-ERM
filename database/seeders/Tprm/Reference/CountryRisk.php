<?php

namespace Database\Seeders\Tprm\Reference;

/**
 * Country reference data for the GEO factor.
 *
 * WHAT IS SHIPPED AND WHY.
 *
 * `region` and the GEO score that follows from it. ECOWAS membership is public
 * record; "Nigeria" versus "not Nigeria" is not a judgement; and the scores map
 * straight onto TRD §7.2's own GEO options (domestic 0.1, ECOWAS 0.3,
 * elsewhere 0.6 with a recorded basis). Shipping these means the factor works
 * on day one without a client configuring 200 countries.
 *
 * `supervisory_access_impeded` is NOT shipped for any country, on purpose. It
 * asserts that a jurisdiction obstructs a Nigerian supervisor — a political
 * determination that changes over time and that would appear in a client's
 * risk register as our opinion of a sovereign state. The column exists, the
 * scoring reads it, and the tenant's risk function fills it in.
 *
 * The list below is the ECOWAS fifteen plus the jurisdictions a Nigerian bank
 * most commonly processes in. It is deliberately not an attempt at all 249 ISO
 * 3166 entries: a country absent from this table scores from the vendor's
 * answer to A3 rather than from a row nobody has reviewed.
 */
class CountryRisk
{
    /** GEO scores by region, matching TRD §7.2's option scores. */
    public const REGION_SCORES = [
        'domestic' => 0.1,
        'ecowas' => 0.3,
        'africa_other' => 0.6,
        'international' => 0.6,
    ];

    /**
     * @return list<array{code: string, name: string, region: string}>
     */
    public static function countries(): array
    {
        $rows = [];

        foreach (['NG' => 'Nigeria'] as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name, 'region' => 'domestic'];
        }

        // The ECOWAS fifteen, less Nigeria. Membership is public record.
        $ecowas = [
            'BJ' => 'Benin', 'BF' => 'Burkina Faso', 'CV' => 'Cabo Verde', 'CI' => "Côte d'Ivoire",
            'GM' => 'The Gambia', 'GH' => 'Ghana', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau',
            'LR' => 'Liberia', 'ML' => 'Mali', 'NE' => 'Niger', 'SN' => 'Senegal',
            'SL' => 'Sierra Leone', 'TG' => 'Togo',
        ];

        foreach ($ecowas as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name, 'region' => 'ecowas'];
        }

        // Other African jurisdictions a Nigerian bank commonly processes in.
        $africa = ['ZA' => 'South Africa', 'KE' => 'Kenya', 'EG' => 'Egypt', 'MA' => 'Morocco', 'MU' => 'Mauritius', 'RW' => 'Rwanda'];

        foreach ($africa as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name, 'region' => 'africa_other'];
        }

        // The common international processing and hosting locations.
        $international = [
            'GB' => 'United Kingdom', 'US' => 'United States', 'IE' => 'Ireland', 'DE' => 'Germany',
            'NL' => 'Netherlands', 'FR' => 'France', 'AE' => 'United Arab Emirates', 'IN' => 'India',
            'SG' => 'Singapore', 'CN' => 'China', 'CA' => 'Canada', 'AU' => 'Australia',
        ];

        foreach ($international as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name, 'region' => 'international'];
        }

        return $rows;
    }
}
