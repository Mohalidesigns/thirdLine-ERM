<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | This product is multi-tenant, so bootstrap/providers.php registers
    | ThirdLine\Platform\Tenancy\TenancyServiceProvider — the opt-in half of
    | the platform package. The one thing the package cannot know is which
    | class represents a tenant here.
    |
    */

    'tenancy' => [
        'organization_model' => App\Models\Organization::class,
    ],

];
