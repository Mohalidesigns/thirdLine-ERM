<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound host policy
    |--------------------------------------------------------------------------
    |
    | A webhook URL is typed into a form by a user, and the SERVER fetches it.
    | Left unchecked that is server-side request forgery with a delivery log
    | attached: http://169.254.169.254/ returns cloud credentials,
    | http://localhost:6379 talks to Redis, and the response is stored where the
    | person who created the subscription can read it.
    |
    | allow_private_hosts = true disables the check entirely. It exists for
    | local development and for on-premise installs whose receiver genuinely
    | lives on the internal network — which is common in a bank, and is exactly
    | why it is a deliberate switch rather than the default.
    |
    | allowed_hosts is the narrower answer: name the internal receivers you
    | trust instead of opening the whole range.
    |
    */

    'allow_private_hosts' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_HOSTS', false),

    'allowed_hosts' => array_filter(explode(',', (string) env('WEBHOOKS_ALLOWED_HOSTS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | The backoff is exponential and deliberately short at the start: most
    | failures are a receiver restarting, and a first retry an hour later turns
    | a thirty-second outage into an hour of missing events.
    |
    */

    'timeout_seconds' => (int) env('WEBHOOKS_TIMEOUT_SECONDS', 10),

    'max_attempts' => (int) env('WEBHOOKS_MAX_ATTEMPTS', 5),

    'backoff_seconds' => [10, 60, 300, 1800, 7200],

    // Truncated before storing: a receiver that answers an error with a 2 MB
    // HTML page should not put 2 MB in the delivery log, once per retry.
    'max_response_bytes' => 8192,

    /*
    |--------------------------------------------------------------------------
    | Signature
    |--------------------------------------------------------------------------
    |
    | HMAC-SHA256 over "{timestamp}.{body}", sent as X-Atheris-Signature with
    | the timestamp in X-Atheris-Timestamp. Signing the timestamp WITH the body
    | is what stops a captured payload being replayed later — a signature over
    | the body alone stays valid forever.
    |
    */

    'signature_header' => 'X-Atheris-Signature',

    'timestamp_header' => 'X-Atheris-Timestamp',

    'tolerance_seconds' => 300,

];
