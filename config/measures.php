<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Threshold re-baselining
    |--------------------------------------------------------------------------
    |
    | A formula-valued band bound is re-evaluated when a period closes. Where
    | the newly computed bound differs from the band actually in force by more
    | than the tolerance below, an approval task is raised rather than the band
    | being moved silently — a limit that moves without anybody agreeing to it
    | is not a limit.
    |
    | 0.05 means "5% away from the band in force". Nigerian headline inflation
    | has run well above that annually, so a naira band indexed to CPI trips
    | this within a year, which is the intent: the alternative is the drift that
    | makes every risk look High.
    |
    */

    'rebaseline_tolerance' => (float) env('MEASURE_REBASELINE_TOLERANCE', 0.05),

    /*
    | Where the tolerance is measured against a bound of zero, a relative
    | comparison is undefined. Anything larger than this absolute amount is
    | treated as a material move.
    */

    'rebaseline_absolute_floor' => (float) env('MEASURE_REBASELINE_ABSOLUTE_FLOOR', 0.000001),

    /*
    |--------------------------------------------------------------------------
    | CBN rate fetching
    |--------------------------------------------------------------------------
    |
    | The scheduled fetcher reads the CBN exchange-rate feed and records
    | cbn_official rates against NGN. Left blank the command does nothing and
    | says so, rather than inventing rates — a fabricated FX rate would flow
    | straight into a regulatory threshold test.
    |
    */

    'cbn_rate_url' => env('CBN_RATE_URL'),

    'cbn_rate_currencies' => array_values(array_filter(
        explode(',', (string) env('CBN_RATE_CURRENCIES', 'USD,GBP,EUR'))
    )),

];
