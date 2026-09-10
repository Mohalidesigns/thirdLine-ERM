<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | Used only when TenancyServiceProvider is registered. The provider is
    | OPT-IN: a single-tenant application registers PlatformServiceProvider and
    | never loads the global scope, the tenant resolver or the bypass audit.
    |
    | organization_model is the class that represents a tenant. There is no
    | default — see ThirdLine\Platform\Tenancy\TenancyConfig for why guessing
    | would be worse than failing.
    |
    */

    'tenancy' => [
        'organization_model' => null,
    ],

];
