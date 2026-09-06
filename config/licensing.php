<?php

/*
|--------------------------------------------------------------------------
| Licensing — application overrides
|--------------------------------------------------------------------------
|
| The licensing cluster lives in thirdline/platform, which ships the full
| config. Laravel merges this file over the package's, so only what is
| specific to this application belongs here.
|
*/

return [

    // What a licence seat is counted from. The package has no default; see
    // ThirdLine\Platform\Licensing\LicensingConfig for why guessing would
    // under-report seat usage to the licence server rather than fail.
    'user_model' => App\Models\User::class,

];
