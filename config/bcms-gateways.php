<?php

/*
|--------------------------------------------------------------------------
| BCMS notification gateways (Phase 7)
|--------------------------------------------------------------------------
|
| The credentials and quirks of every real provider, in one file, so that
| `app/Services/Bcms/Notification/Channels` holds behaviour and this holds
| facts about vendors.
|
| NOTHING HERE IS CONFIGURED BY DEFAULT, AND THAT IS THE POINT. Every adapter
| reports `isConfigured() === false` until an operator supplies credentials, and
| `ChannelRegistry` keeps returning the Phase 0 recording mock for any channel
| that is not both named in `bcms.channels` and configured here. Nigerian
| sender-ID registration, WhatsApp Business template approval and USSD
| short-code allocation run four to ten weeks and will not clear together
| (Orchestration §9), so each channel goes live on its own day by setting two
| environment variables — not by a deployment.
|
| THE SMS POOL IS ORDERED AND THE ORDER IS THE OPERATOR'S. `FailoverSmsChannel`
| tries them in this sequence, demoting any gateway that has just failed three
| times. It never removes one: if every gateway is unhealthy the message still
| goes out through the least-bad, because in a life-safety dispatch a
| probably-broken gateway beats no attempt at all.
|
*/

return [

    /*
    | Two to three Nigerian gateways. Termii and Africa's Talking are the
    | commonest; Infobip and Twilio are the international fallbacks a group
    | treasury usually already has a contract with. The payload map exists
    | because they differ only in field names.
    */
    'sms' => [
        [
            'name' => 'termii',
            'endpoint' => env('BCMS_SMS_TERMII_ENDPOINT', 'https://api.ng.termii.com/api/sms/send'),
            'api_key' => env('BCMS_SMS_TERMII_KEY'),
            // An unregistered alphanumeric sender is silently dropped by the
            // Nigerian networks: the gateway returns success and nobody's phone
            // rings. There is deliberately no default.
            'sender_id' => env('BCMS_SMS_TERMII_SENDER'),
            'auth' => 'body',
            'payload' => ['to' => 'to', 'from' => 'from', 'body' => 'sms', 'api_key' => 'api_key'],
            'extra' => ['type' => 'plain', 'channel' => 'generic'],
            'response' => ['message_id' => 'message_id', 'error' => 'message', 'cost' => 'balance'],
            'cost_minor' => env('BCMS_SMS_TERMII_COST_MINOR'),
            'currency' => 'NGN',
        ],
        [
            'name' => 'africastalking',
            'endpoint' => env('BCMS_SMS_AT_ENDPOINT', 'https://api.africastalking.com/version1/messaging'),
            'api_key' => env('BCMS_SMS_AT_KEY'),
            'sender_id' => env('BCMS_SMS_AT_SENDER'),
            'auth' => 'header',
            'payload' => ['to' => 'to', 'from' => 'from', 'body' => 'message'],
            'response' => [
                'message_id' => 'SMSMessageData.Recipients.0.messageId',
                'error' => 'SMSMessageData.Message',
                'cost' => 'SMSMessageData.Recipients.0.cost',
            ],
            'cost_minor' => env('BCMS_SMS_AT_COST_MINOR'),
            'currency' => 'NGN',
        ],
        [
            'name' => 'infobip',
            'endpoint' => env('BCMS_SMS_INFOBIP_ENDPOINT'),
            'api_key' => env('BCMS_SMS_INFOBIP_KEY'),
            'sender_id' => env('BCMS_SMS_INFOBIP_SENDER'),
            'auth' => 'header',
            'payload' => ['to' => 'destinations', 'from' => 'from', 'body' => 'text'],
            'response' => ['message_id' => 'messages.0.messageId', 'error' => 'requestError.serviceException.text'],
            'cost_minor' => env('BCMS_SMS_INFOBIP_COST_MINOR'),
            'currency' => 'NGN',
        ],
    ],

    /*
    | Meta Cloud API. Outside a 24-hour window Meta accepts only a pre-approved
    | template, which is why `bcms_alert_templates.whatsapp_template_name`
    | exists and why approval has to start in Week 1.
    */
    'whatsapp' => [
        'base_url' => env('BCMS_WHATSAPP_BASE_URL', 'https://graph.facebook.com/v21.0'),
        'phone_number_id' => env('BCMS_WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('BCMS_WHATSAPP_TOKEN'),
        'cost_minor' => env('BCMS_WHATSAPP_COST_MINOR'),
        'currency' => 'NGN',
    ],

    'voice' => [
        'name' => env('BCMS_VOICE_PROVIDER', 'voice-tts'),
        'endpoint' => env('BCMS_VOICE_ENDPOINT'),
        'api_key' => env('BCMS_VOICE_KEY'),
        'caller_id' => env('BCMS_VOICE_CALLER_ID'),
        'voice' => env('BCMS_VOICE_NAME', 'en-NG-Standard-A'),
        // Two passes: somebody answering at 3am misses the first sentence.
        'repeat' => env('BCMS_VOICE_REPEAT', 2),
        'response' => ['call_id' => 'call_id'],
        'cost_minor' => env('BCMS_VOICE_COST_MINOR'),
        'currency' => 'NGN',
    ],

    'teams' => [
        'webhook_url' => env('BCMS_TEAMS_WEBHOOK'),
    ],

    'slack' => [
        'webhook_url' => env('BCMS_SLACK_WEBHOOK'),
    ],

    'push' => [
        'name' => env('BCMS_PUSH_PROVIDER', 'push-gateway'),
        'endpoint' => env('BCMS_PUSH_ENDPOINT'),
        'api_key' => env('BCMS_PUSH_KEY'),
    ],

    /*
    | USSD is PULL: the person dials the short code and is shown the alert.
    | Registration is what makes that work. `push_enabled` is for the
    | aggregators that sell network-initiated USSD; most contracts do not
    | include it. Wiring completes in Phase 12.
    */
    'ussd' => [
        'name' => env('BCMS_USSD_PROVIDER', 'ussd-aggregator'),
        'endpoint' => env('BCMS_USSD_ENDPOINT'),
        'api_key' => env('BCMS_USSD_KEY'),
        'short_code' => env('BCMS_USSD_SHORT_CODE'),
        'session_ttl_minutes' => env('BCMS_USSD_SESSION_TTL', 120),
        'push_enabled' => env('BCMS_USSD_PUSH', false),
        'cost_minor' => env('BCMS_USSD_COST_MINOR'),
        'currency' => 'NGN',
    ],

    /*
    | Provider status webhooks. A gateway posting a delivery receipt has to
    | prove it is the gateway; each vendor signs differently and the secret is
    | per provider.
    */
    'webhook_secrets' => [
        'termii' => env('BCMS_WEBHOOK_SECRET_TERMII'),
        'africastalking' => env('BCMS_WEBHOOK_SECRET_AT'),
        'infobip' => env('BCMS_WEBHOOK_SECRET_INFOBIP'),
        'whatsapp-cloud' => env('BCMS_WEBHOOK_SECRET_WHATSAPP'),
        'voice-tts' => env('BCMS_WEBHOOK_SECRET_VOICE'),
        'ussd-aggregator' => env('BCMS_WEBHOOK_SECRET_USSD'),
    ],
];
