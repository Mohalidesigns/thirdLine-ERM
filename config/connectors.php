<?php

use App\Integrations\Connectors\CsvConnector;
use App\Integrations\Connectors\RestConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | type => the class implementing App\Integrations\Contracts\Connector.
    |
    | Two ship, and they are the two that cover most of what an institution
    | actually has: a file dropped on a share, and a JSON endpoint. A customer's
    | own integration team adds a third by writing one class and one line here —
    | which is the test of whether the framework is a framework or just two
    | connectors with a shared interface.
    |
    */

    'drivers' => [
        'csv' => CsvConnector::class,
        'rest' => RestConnector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedules
    |--------------------------------------------------------------------------
    |
    | The cadences a connector may be set to. Each is swept by
    | `connectors:run --schedule=<cadence>` from routes/console.php.
    |
    */

    'schedules' => [
        'hourly' => 'Every hour',
        'daily' => 'Once a day',
        'weekly' => 'Once a week',
        'monthly' => 'Once a month',
    ],

    /*
    |--------------------------------------------------------------------------
    | First run
    |--------------------------------------------------------------------------
    |
    | A connector's first run is a DRY RUN by default. It produces a
    | reconciliation an operator reads — how many rows matched a measure, how
    | many did not, what the values would have been — before anything is
    | written. A connector that silently writes four hundred wrong numbers into
    | a capital return is worse than one that does nothing, and the first run is
    | when the field mapping is most likely to be wrong.
    |
    */

    'dry_run_first' => (bool) env('CONNECTORS_DRY_RUN_FIRST', true),

];
