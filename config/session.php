<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Session cookie hardening
|--------------------------------------------------------------------------
|
| Two settings below were left at values that are safe on a laptop and wrong on
| a bank's network, and both were reachable only by remembering to set an
| environment variable that no .env.example documents:
|
|   'secure'   was env('SESSION_SECURE_COOKIE') with NO DEFAULT, i.e. null. A
|              null Secure flag means the session cookie is sent over plain HTTP.
|              On an internal network with a TLS-terminating load balancer that
|              is a session identifier travelling in clear on the last hop, and
|              any downgrade — a stray http:// link, a captive portal, an
|              attacker on the LAN forcing one plain request — hands over a live
|              authenticated session for a Chief Risk Officer.
|
|   'encrypt'  was env('SESSION_ENCRYPT', false). With the file or database
|              driver the payload sits on disk in the clear, and this
|              application's session payload is not inert: it holds the resolved
|              tenant, flashed messages containing record contents, and (once the
|              MFA rebuild lands) the pending-user identifier of a half-completed
|              sign-in.
|
| Both are now FORCED ON outside local and testing, rather than defaulted on.
| A default can be overridden by an environment variable, and the environment
| variable is exactly what nobody sets. There is no supported way to run this
| platform in production over plain HTTP — if a deployment cannot terminate TLS
| at the application, it terminates it at a proxy on the same host and the
| application still speaks HTTPS to the browser.
|
| AppServiceProvider::assertSessionCookieIsHardened() re-checks both at boot and
| refuses to start if either is off, which is what catches the case this file
| cannot: a config cache built in one environment and shipped to another.
|
| SESSION_SECURE_COOKIE and SESSION_ENCRYPT still work in local and testing, so
| a developer can reproduce production behaviour by setting them, and the test
| suite (which runs over an unencrypted synthetic request) is unaffected.
*/
$sessionIsDevelopment = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    |
    | This option determines the default session driver that is utilized for
    | incoming requests. Laravel supports a variety of storage options to
    | persist session data. Database storage is a great default choice.
    |
    | Supported: "file", "cookie", "database", "memcached",
    |            "redis", "dynamodb", "array"
    |
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Here you may specify the number of minutes that you wish the session
    | to be allowed to remain idle before it expires. If you want them
    | to expire immediately when the browser is closed then you may
    | indicate that via the expire_on_close configuration option.
    |
    */

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    |
    | This option allows you to easily specify that all of your session data
    | should be encrypted before it's stored. All encryption is performed
    | automatically by Laravel and you may use the session like normal.
    |
    */

    // Forced on outside local/testing. See the note at the top of this file:
    // the session payload carries the resolved tenant and flashed record
    // contents, and with the file or database driver it is otherwise stored in
    // the clear.
    'encrypt' => $sessionIsDevelopment
        ? env('SESSION_ENCRYPT', false)
        : true,

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    |
    | When utilizing the "file" session driver, the session files are placed
    | on disk. The default storage location is defined here; however, you
    | are free to provide another location where they should be stored.
    |
    */

    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection
    |--------------------------------------------------------------------------
    |
    | When using the "database" or "redis" session drivers, you may specify a
    | connection that should be used to manage these sessions. This should
    | correspond to a connection in your database configuration options.
    |
    */

    'connection' => env('SESSION_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Table
    |--------------------------------------------------------------------------
    |
    | When using the "database" session driver, you may specify the table to
    | be used to store sessions. Of course, a sensible default is defined
    | for you; however, you're welcome to change this to another table.
    |
    */

    'table' => env('SESSION_TABLE', 'sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    |
    | When using one of the framework's cache driven session backends, you may
    | define the cache store which should be used to store the session data
    | between requests. This must match one of your defined cache stores.
    |
    | Affects: "dynamodb", "memcached", "redis"
    |
    */

    'store' => env('SESSION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    |
    | Some session drivers must manually sweep their storage location to get
    | rid of old sessions from storage. Here are the chances that it will
    | happen on a given request. By default, the odds are 2 out of 100.
    |
    */

    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    |
    | Here you may change the name of the session cookie that is created by
    | the framework. Typically, you should not need to change this value
    | since doing so does not grant a meaningful security improvement.
    |
    */

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-session'
    ),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path
    |--------------------------------------------------------------------------
    |
    | The session cookie path determines the path for which the cookie will
    | be regarded as available. Typically, this will be the root path of
    | your application, but you're free to change this when necessary.
    |
    */

    'path' => env('SESSION_PATH', '/'),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain
    |--------------------------------------------------------------------------
    |
    | This value determines the domain and subdomains the session cookie is
    | available to. By default, the cookie will be available to the root
    | domain without subdomains. Typically, this shouldn't be changed.
    |
    */

    'domain' => env('SESSION_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies
    |--------------------------------------------------------------------------
    |
    | By setting this option to true, session cookies will only be sent back
    | to the server if the browser has a HTTPS connection. This will keep
    | the cookie from being sent to you when it can't be done securely.
    |
    */

    // Forced on outside local/testing. Previously env('SESSION_SECURE_COOKIE')
    // with no default, which is null — the session cookie was not marked Secure
    // in any environment where nobody had set the variable, so a single plain
    // HTTP request leaked a live session identifier.
    'secure' => $sessionIsDevelopment
        ? env('SESSION_SECURE_COOKIE')
        : true,

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only
    |--------------------------------------------------------------------------
    |
    | Setting this value to true will prevent JavaScript from accessing the
    | value of the cookie and the cookie will only be accessible through
    | the HTTP protocol. It's unlikely you should disable this option.
    |
    */

    'http_only' => env('SESSION_HTTP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies
    |--------------------------------------------------------------------------
    |
    | This option determines how your cookies behave when cross-site requests
    | take place, and can be used to mitigate CSRF attacks. By default, we
    | will set this value to "lax" to permit secure cross-site requests.
    |
    | See: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie#samesitesamesite-value
    |
    | Supported: "lax", "strict", "none", null
    |
    */

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    /*
    |--------------------------------------------------------------------------
    | Partitioned Cookies
    |--------------------------------------------------------------------------
    |
    | Setting this value to true will tie the cookie to the top-level site for
    | a cross-site context. Partitioned cookies are accepted by the browser
    | when flagged "secure" and the Same-Site attribute is set to "none".
    |
    */

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
