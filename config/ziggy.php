<?php

/*
| Ziggy exposes named routes to the browser so React can call route().
| Framework and machine-to-machine routes have no business in that map: a
| Horizon or SCIM route name is reconnaissance material on a risk register
| and nothing in resources/js links to any of them.
*/
return [
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
