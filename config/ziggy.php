<?php

/*
| Ziggy exposes named routes to the browser so React can call route().
| Framework and machine-to-machine routes have no business in that map: a
| Horizon or SCIM route name is reconnaissance material on a risk register
| and nothing in resources/js links to any of them.
*/
return [
    /*
    | The vendor portal gets its OWN group, and its root view emits only that
    | group. Without it every portal page would ship the complete internal
    | route map to a vendor's browser — every admin path, every export, every
    | tenant-settings endpoint — which is reconnaissance material handed to
    | exactly the population AC-14 exists to keep out. The names alone are a
    | map of the register.
    */
    'groups' => [
        'tprm-portal' => ['tprm-portal.*'],
    ],

    'except' => [
        'horizon.*',
        'livewire.*',
        'api.*',
        'scim.*',
        'scramble.*',
        'sanctum.*',
        'ignition.*',
        'telescope.*',
    ],
];
