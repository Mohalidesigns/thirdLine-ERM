<?php

namespace App\Services\Bcms\Identity;

use App\Contracts\Bcms\DirectoryClient;
use App\Contracts\Bcms\ReportsDirectoryFetchStats;
use App\Exceptions\Bcms\DirectorySyncException;
use App\Models\Bcms\IdentityConnector;
use App\Support\Bcms\DirectoryAttributeMap;
use App\Support\Bcms\DirectoryHostGuard;
use App\Support\Bcms\DirectoryUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * `DirectoryClient` over Microsoft Graph — the app-only, read-only client
 * credentials flow of ADR 0018 §3.1.
 *
 * `Http`, NOT A GRAPH SDK (ADR 0018 §4). That is what puts `Http::fake()` and
 * `Http::preventStrayRequests()` in charge of every test, and it is the same
 * shape `HttpChannel` already went through three rounds of a gate on.
 *
 * GET ONLY. `Phase2cReadOnlyGuardTest` asserts no `Http::post|put|patch|delete`
 * under `app/Services/Bcms/Identity` targets a Graph host — the single POST
 * this class makes is the token request, allowlisted by URL in that test. This
 * class has no method that could write to the directory even if it wanted to;
 * `DirectoryClient` declares none.
 *
 * NO PROVIDER MESSAGE EVER REACHES A CALLER. Every failure this class raises
 * is a {@see DirectorySyncException} carrying only `errorClass`/`errorCode` —
 * a closed, bounded vocabulary, never `$e->getMessage()` or a Graph
 * `error.message` (ADR 0018 §2.3, §3.1).
 */
class EntraGraphClient implements DirectoryClient, ReportsDirectoryFetchStats
{
    /**
     * Graph's page size ceiling. Real paging is proven against
     * `FakeDirectoryClient` (ADR 0018 §6, criterion 1's note): "paging is
     * proven by lowering $top, not by inflating the fixture" describes the
     * test fixture, not this constant, which stays at the ceiling Graph
     * actually allows.
     */
    protected int $perPage = 999;

    /**
     * READ TIMEOUT AND CONNECT TIMEOUT ARE DIFFERENT FAULTS AND GET DIFFERENT
     * NUMBERS. A Graph page of 999 users with `$expand=manager` is a real
     * piece of work and thirty seconds is not generous; a TCP connect that
     * has not completed in ten is a network fault, and waiting the read
     * timeout for it just multiplies the outage by the number of pages. Both
     * are set explicitly rather than left to the client's defaults, because
     * "no timeout" is what turns a provider hang into a worker this job's
     * 3,600-second budget holds hostage.
     */
    protected int $timeoutSeconds = 30;

    protected int $connectTimeoutSeconds = 10;

    /**
     * Attempts per HTTP request, INCLUDING the first — so two retries, never
     * more. Graph's own guidance is to honour `Retry-After`; the cap is ours,
     * because `tries = 1` on the job means this class is the only retry there
     * is and an uncapped one would sit inside a worker until the queue killed
     * it with nothing written.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * The longest this class will sleep for one `Retry-After`. Graph has been
     * known to return minutes; a worker asleep for four of them inside a
     * 3,600-second budget it also has to finish a directory read in is worse
     * than a partial run the operator can see and re-trigger.
     */
    private const MAX_RETRY_AFTER_SECONDS = 60;

    /**
     * A page budget, so a provider that returns a self-referential
     * `@odata.nextLink` cannot spin a worker until the queue timeout with
     * nothing to show for it. 200 pages at `$top=999` is ~200,000 directory
     * objects — four times the 50,000-contact tenant ceiling in NFR §14.
     */
    private const MAX_PAGES = 200;

    /**
     * A budget for the per-user manager fallback of §3.1. Each one is a
     * separate round trip; unbounded, a directory where `$expand` omits the
     * edge for everybody turns one paged read into 5,000 sequential requests.
     * Past the budget the run fails with a bounded code rather than quietly
     * shipping a roster with the manager edges missing — a flat call tree that
     * nobody was told about is the failure ADR 0018 §2.1 exists to prevent.
     */
    private const MAX_MANAGER_FALLBACKS = 1000;

    private int $pagesFetched = 0;

    private int $objectsRead = 0;

    private int $managerFallbacks = 0;

    public function users(IdentityConnector $connector, ?string $filter = null): iterable
    {
        $this->pagesFetched = 0;
        $this->objectsRead = 0;
        $this->managerFallbacks = 0;

        $query = $this->baseQuery($connector, $filter);

        $url = rtrim($connector->graph_base_url, '/').'/users?'.http_build_query($query);

        while ($url !== null) {
            $this->guardPageBudget();

            $response = $this->get($connector, $url);
            $body = $this->decode($response);

            $this->pagesFetched++;

            foreach ((array) ($body['value'] ?? []) as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $this->objectsRead++;

                yield $this->toDirectoryUser($connector, $raw);
            }

            $url = is_string($body['@odata.nextLink'] ?? null) ? $body['@odata.nextLink'] : null;
        }
    }

    /**
     * A DELTA ENTRY IS NEVER A COMPLETE USER (ADR 0018 §3.1; gate 2 rejection
     * #3, blocking defect 1). Graph's `/users/delta` sends only the
     * properties that changed since the last delta query — an entry for an
     * object whose enable/disable state did not change carries no
     * `accountEnabled` key at all, which `DirectoryUser::fromGraphAttributes()`
     * now reports as `null` ("not reported"), never coerced to `true`. This
     * method's own job stops at translating the raw payload faithfully,
     * `@removed` tombstones included — it never skips a `value` entry and
     * never fills in a property Graph did not send; `ChangeDetector` is
     * where "what does an unreported or tombstoned object mean" is decided.
     *
     * @return array{users: iterable<int, DirectoryUser>, delta_link: ?string}
     */
    public function delta(IdentityConnector $connector, ?string $deltaLink = null): array
    {
        $this->pagesFetched = 0;
        $this->objectsRead = 0;
        $this->managerFallbacks = 0;

        $url = $deltaLink ?? rtrim($connector->graph_base_url, '/').'/users/delta?'.http_build_query([
            '$select' => implode(',', DirectoryAttributeMap::selectFields()),
        ]);

        $users = [];
        $newDeltaLink = $deltaLink;

        while ($url !== null) {
            $this->guardPageBudget();

            $response = $this->get($connector, $url);
            $body = $this->decode($response);

            $this->pagesFetched++;

            foreach ((array) ($body['value'] ?? []) as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $this->objectsRead++;

                // A `@removed` TOMBSTONE, NOT AN ORDINARY USER (gate 2
                // rejection #3, blocking defect 1c). Graph reports a deleted
                // or out-of-scope object as `{"id": "...", "@removed":
                // {"reason": "changed"|"deleted"}}` with none of its other
                // properties present — handing that straight to
                // `fromGraphAttributes()` produced an object with no
                // `accountEnabled` key, which the old unconditional-`true`
                // default read as "still enabled", so the departure was
                // never staged at all. Both of Graph's own reasons —
                // `changed` (soft-deleted / moved out of the connector's
                // filter) and `deleted` (hard-deleted) — mean the same thing
                // for this module: the account is gone from the LIVE
                // directory. Forcing `accountEnabled` onto the raw payload
                // here, before it ever reaches `DirectoryUser`, is what lets
                // `ChangeDetector` treat it as the ordinary "account
                // disabled" leaver signal it already knows how to stage —
                // nothing downstream needs to know a tombstone was involved.
                if (is_array($raw['@removed'] ?? null)) {
                    $raw['accountEnabled'] = false;
                }

                // Delta carries attribute changes only — it does NOT carry
                // relationships (ADR 0018 §3.1). The manager edge is never
                // re-resolved here; a caller that needs it schedules a full
                // reconciliation instead.
                $users[] = DirectoryUser::fromGraphAttributes($raw, managerObjectId: null);
            }

            $next = $body['@odata.nextLink'] ?? null;
            $newDeltaLink = $body['@odata.deltaLink'] ?? $newDeltaLink;
            $url = is_string($next) ? $next : null;
        }

        return ['users' => $users, 'delta_link' => is_string($newDeltaLink) ? $newDeltaLink : null];
    }

    public function managerObjectId(IdentityConnector $connector, string $objectId): ?string
    {
        $url = rtrim($connector->graph_base_url, '/')."/users/{$objectId}/manager?".http_build_query(['$select' => 'id']);

        // A 404 from this endpoint means "no manager", not a failure — the
        // trap that would otherwise mark every executive's run as partial
        // (ADR 0018 §3.1).
        $response = $this->send($connector, $url, nullOn404: true);

        if ($response === null) {
            return null;
        }

        $id = $response->json('id');

        return is_string($id) ? $id : null;
    }

    /**
     * @return array{ok: bool, scopes: list<string>, sample_count: int, error_class: ?string, error_code: ?string}
     */
    public function testConnection(IdentityConnector $connector): array
    {
        try {
            $token = $this->token($connector, forceFresh: true);
        } catch (DirectorySyncException $e) {
            return ['ok' => false, 'scopes' => [], 'sample_count' => 0, 'error_class' => $e->errorClass, 'error_code' => $e->errorCode];
        }

        $url = rtrim($connector->graph_base_url, '/').'/users?'.http_build_query([
            '$select' => implode(',', DirectoryAttributeMap::selectFields()),
            '$top' => 1,
        ]);

        try {
            $response = $this->get($connector, $url);
        } catch (DirectorySyncException $e) {
            return ['ok' => false, 'scopes' => $token['scopes'], 'sample_count' => 0, 'error_class' => $e->errorClass, 'error_code' => $e->errorCode];
        }

        $body = $this->decode($response);

        return [
            'ok' => true,
            'scopes' => $token['scopes'],
            'sample_count' => count((array) ($body['value'] ?? [])),
            'error_class' => null,
            'error_code' => null,
        ];
    }

    /** @return array{pages: int, objects: int} */
    public function lastFetchStats(): array
    {
        return ['pages' => $this->pagesFetched, 'objects' => $this->objectsRead];
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return array<string, string|int> */
    private function baseQuery(IdentityConnector $connector, ?string $filter): array
    {
        $query = [
            '$select' => implode(',', DirectoryAttributeMap::selectFields()),
            '$top' => $this->perPage,
            // The manager edge on the same request, per ADR 0018 §3.1. A row
            // this omits it for falls back to `managerObjectId()`.
            '$expand' => 'manager($select=id)',
        ];

        $effectiveFilter = $filter ?? $connector->directory_filter;

        if ($effectiveFilter !== null && $effectiveFilter !== '') {
            $query['$filter'] = $effectiveFilter;
        }

        return $query;
    }

    private function toDirectoryUser(IdentityConnector $connector, array $raw): DirectoryUser
    {
        $managerObjectId = is_array($raw['manager'] ?? null) ? ($raw['manager']['id'] ?? null) : null;

        // `$expand=manager` omitted the edge for this row (Graph does this
        // for some accounts) — fall back to the dedicated endpoint, where a
        // 404 means "no manager" rather than a failure.
        if ($managerObjectId === null && ! array_key_exists('manager', $raw)) {
            if ($this->managerFallbacks >= self::MAX_MANAGER_FALLBACKS) {
                throw new DirectorySyncException('graph_manager_fallback_budget', (string) self::MAX_MANAGER_FALLBACKS, $this->objectsRead);
            }

            $this->managerFallbacks++;

            $managerObjectId = $this->managerObjectId($connector, (string) $raw['id']);
        }

        return DirectoryUser::fromGraphAttributes($raw, $managerObjectId);
    }

    /**
     * @return array{token: string, scopes: list<string>}
     */
    private function token(IdentityConnector $connector, bool $forceFresh = false): array
    {
        $cacheKey = $this->tokenCacheKey($connector);

        if (! $forceFresh) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['token'])) {
                return $cached;
            }
        }

        $tokenUrl = rtrim($connector->token_base_url, '/')."/{$connector->directory_tenant_id}/oauth2/v2.0/token";

        // Gate 2 blocking defect 1: the connector's `token_base_url` is a
        // form field a `bcms.identity.manage` holder typed in — one who may
        // WRITE `client_secret` but never READ it back (ADR 0018 §5).
        // Checked again here, not only at save time in
        // `UpdateIdentityConnectorRequest`, because a value that was
        // allowed when it was typed is not re-validated on every read, and
        // this is the one request that carries the secret in its body.
        try {
            DirectoryHostGuard::assertAllowed($tokenUrl);
        } catch (RuntimeException) {
            throw new DirectorySyncException('token_host_not_allowed', $this->hostFor($tokenUrl));
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                // THE ONLY POST THIS CLASS MAKES, and it is to the token endpoint,
                // never to a Graph host. `Phase2cReadOnlyGuardTest` allowlists
                // this exact URL shape.
                //
                // `withoutRedirecting()` (gate 2 rejection #3, advisory 4): the
                // config comment above `bcms.identity.allowed_hosts` claims a
                // redirect "fails the run" — untrue against Guzzle's own
                // defaults, which follow up to five redirects, and a 307/308
                // preserves the method AND body, meaning the client secret in
                // this POST would ride along to wherever `DirectoryHostGuard`
                // never got a chance to look at. This is the token request
                // specifically — the one call in this class that carries a
                // secret in its body — so no redirect is ever followed, full
                // stop; the run fails on the classified response instead.
                $response = Http::asForm()
                    ->withoutRedirecting()
                    ->timeout($this->timeoutSeconds)
                    ->connectTimeout($this->connectTimeoutSeconds)
                    ->post($tokenUrl, [
                        'grant_type' => 'client_credentials',
                        'client_id' => $connector->client_id,
                        'client_secret' => (string) $connector->client_secret,
                        'scope' => $this->defaultScope($connector),
                    ]);
            } catch (ConnectionException $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->pause($attempt * 2);

                    continue;
                }

                throw new DirectorySyncException('token_connection_error', $this->curlErrno($e));
            }

            // A throttled or briefly unavailable token endpoint is retried on
            // the same ladder as Graph itself. A 4xx is NOT: an invalid
            // client secret does not become valid on the second ask, and
            // retrying it three times a night against Entra is how an app
            // registration gets locked out.
            if (($response->status() === 429 || $response->status() >= 500) && $attempt < self::MAX_ATTEMPTS) {
                $this->pause($this->retryDelaySeconds($response, $attempt));

                continue;
            }

            break;
        }

        if (! $response->successful()) {
            throw $this->classifiedFailure($response, prefix: 'token_');
        }

        $body = (array) $response->json();
        $accessToken = $body['access_token'] ?? null;

        if (! is_string($accessToken)) {
            throw new DirectorySyncException('token_malformed_response', null);
        }

        $scopes = $this->scopesFromToken($accessToken);
        $expiresIn = is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 3600;

        $result = ['token' => $accessToken, 'scopes' => $scopes];

        Cache::put($cacheKey, $result, max(60, $expiresIn - 300));

        return $result;
    }

    /**
     * Per connector, so two tenants never share a token — and named as one
     * key rather than built at two call sites, because `send()` forgets it on
     * a 401 and a key that disagreed with the one `token()` writes would turn
     * a re-authentication into an infinite supply of stale tokens.
     */
    private function tokenCacheKey(IdentityConnector $connector): string
    {
        return "bcms:identity:token:{$connector->getKey()}";
    }

    /**
     * The scope Graph app-only client credentials asks for: the resource's
     * `.default`, which grants whatever the app registration already holds.
     * ADR 0018 §3.2: the customer-side instruction is that the registration
     * must hold `User.Read.All` (or `Directory.Read.All`) and nothing marked
     * `*.ReadWrite.*` — this class cannot enforce that on the Entra side, only
     * report what came back, which `scopesFromToken()` does.
     */
    private function defaultScope(IdentityConnector $connector): string
    {
        $resource = rtrim((string) preg_replace('#/v\d+(\.\d+)?/?$#', '', rtrim($connector->graph_base_url, '/')), '/');

        return $resource.'/.default';
    }

    /**
     * The app roles (permissions) an app-only token actually carries, read
     * from its own `roles` claim — never validated as a signature check, only
     * read for display, so the connector screen can show an over-privileged
     * registration rather than discover one in a pen test (ADR 0018 §3.2).
     *
     * @return list<string>
     */
    private function scopesFromToken(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) < 2) {
            return [];
        }

        $payload = base64_decode(strtr($segments[1], '-_', '+/').str_repeat('=', (4 - strlen($segments[1]) % 4) % 4), true);
        $claims = is_string($payload) ? json_decode($payload, true) : null;

        $roles = is_array($claims) && is_array($claims['roles'] ?? null) ? $claims['roles'] : [];

        return array_values(array_filter($roles, 'is_string'));
    }

    private function get(IdentityConnector $connector, string $url): Response
    {
        return $this->send($connector, $url)
            ?? throw new DirectorySyncException('graph_empty_response', null, $this->objectsRead);
    }

    /**
     * One Graph GET, with the three things that stop a directory read from
     * hanging a worker or dying on a blip.
     *
     * 429 AND `Retry-After` ARE HONOURED, AND CAPPED. Graph throttles a
     * five-thousand-user paged read as a matter of course; treating the first
     * 429 as a run failure is how a tenant's nightly sync becomes "partial"
     * every night. Two retries, sleeping the smaller of `Retry-After` and
     * sixty seconds, then the same bounded `graph_rate_limited` this always
     * raised — the run is still visible as partial, it just is not partial
     * because of one throttle.
     *
     * 5xx AND A DROPPED CONNECTION GET THE SAME TREATMENT, with an
     * exponential pause, because "Graph 5xx for an hour" is a real Tuesday
     * and a 503 on page four should not discard pages one to three.
     *
     * A 401 MID-RUN BUYS EXACTLY ONE RE-AUTHENTICATION. The token is cached
     * for `expires_in` minus five minutes, which is not a guarantee: a
     * connector's secret can be rotated, or consent revoked, while a paged
     * read is in flight. One forced token refresh distinguishes "the token
     * aged out under us" (recoverable, invisible to the operator) from "this
     * credential is no longer valid" (a failed run with `graph_http_401`,
     * which is what should reach the screen). It cannot loop: the flag is set
     * before the retry.
     *
     * Sleeping uses `Illuminate\Support\Sleep`, so a test proves the retry
     * ladder without the suite actually waiting for it.
     */
    private function send(IdentityConnector $connector, string $url, bool $nullOn404 = false): ?Response
    {
        // Gate 2 blocking defect 1: covers `graph_base_url` itself AND every
        // `@odata.nextLink`/stored `delta_link` this class ever follows —
        // `users()`, `delta()`, `managerObjectId()` and `testConnection()`
        // all funnel their GET through this one method, page after page, so
        // a link that has drifted off the allowlist since it was issued is
        // caught here before it is fetched, not only on a run's first
        // request.
        try {
            DirectoryHostGuard::assertAllowed($url);
        } catch (RuntimeException) {
            throw new DirectorySyncException('graph_host_not_allowed', $this->hostFor($url), $this->objectsRead);
        }

        $attempt = 0;
        $reauthenticated = false;

        while (true) {
            $attempt++;
            $token = $this->token($connector);

            try {
                $response = $this->request($token['token'])->get($url);
            } catch (ConnectionException $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->pause($attempt * 2);

                    continue;
                }

                throw new DirectorySyncException('graph_connection_error', $this->curlErrno($e), $this->objectsRead);
            }

            if ($response->successful()) {
                return $response;
            }

            if ($nullOn404 && $response->status() === 404) {
                return null;
            }

            if ($response->status() === 401 && ! $reauthenticated) {
                $reauthenticated = true;
                Cache::forget($this->tokenCacheKey($connector));

                continue;
            }

            $retryable = $response->status() === 429 || $response->status() >= 500;

            if ($retryable && $attempt < self::MAX_ATTEMPTS) {
                $this->pause($this->retryDelaySeconds($response, $attempt));

                continue;
            }

            if ($response->status() === 429) {
                // `Retry-After` is a bounded integer, never provider free text —
                // safe to carry as the error code (ADR 0018 §2.3, criterion 8).
                // Capped to `error_code`'s varchar(40) regardless: "bounded
                // integer" describes what Graph is supposed to send, not a
                // guarantee this class can rely on for a raw column write
                // (gate 2 advisory 2, same reasoning as `classifiedFailure()`
                // below).
                $retryAfter = $response->header('Retry-After');

                throw new DirectorySyncException(
                    'graph_rate_limited',
                    is_string($retryAfter) && $retryAfter !== '' ? mb_substr($retryAfter, 0, 40) : '429',
                    $this->objectsRead,
                );
            }

            throw $this->classifiedFailure($response, objectsRead: $this->objectsRead);
        }
    }

    /** Honour `Retry-After` when the provider sends one, back off when it does not. */
    private function retryDelaySeconds(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            return min((int) $retryAfter, self::MAX_RETRY_AFTER_SECONDS);
        }

        return min($attempt * 2, self::MAX_RETRY_AFTER_SECONDS);
    }

    private function pause(int $seconds): void
    {
        Sleep::for(max(1, $seconds))->seconds();
    }

    /**
     * A paged read that will not terminate is a worker that will not
     * terminate. The budget converts it into a bounded, classified failure
     * the run row can carry.
     */
    private function guardPageBudget(): void
    {
        if ($this->pagesFetched >= self::MAX_PAGES) {
            throw new DirectorySyncException('graph_page_budget_exceeded', (string) self::MAX_PAGES, $this->objectsRead);
        }
    }

    private function request(string $token): PendingRequest
    {
        // `withoutRedirecting()` (gate 2 rejection #3, advisory 4): every
        // Graph GET this class makes carries the bearer token in an
        // `Authorization` header, which Guzzle's default redirect handling
        // would happily replay against wherever a 3xx pointed — before
        // `DirectoryHostGuard` ever sees that second host, because the guard
        // only runs on the URL this class chose to fetch, not on one Guzzle
        // followed on its own. No redirect is followed at all; a page whose
        // `@odata.nextLink` genuinely needs to move host is a Graph contract
        // break this module fails the run on, not silently accommodates.
        return Http::withToken($token)
            ->withoutRedirecting()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->acceptJson();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function classifiedFailure(Response $response, string $prefix = 'graph_', int $objectsRead = 0): DirectorySyncException
    {
        $status = $response->status();

        // Graph's own bounded `error.code` (a closed vocabulary token like
        // `InvalidAuthenticationToken`) when present, otherwise the bare HTTP
        // status — never `error.message` (ADR 0018 §2.3).
        $graphCode = is_array($response->json()) ? data_get($response->json(), 'error.code') : null;

        return new DirectorySyncException(
            $prefix.'http_'.$status,
            // Capped to `bcms_identity_sync_runs.error_code`'s varchar(40) —
            // Graph's own vocabulary is normally well within that, but
            // nothing here is closed enough to trust with a raw column
            // write (`DirectorySyncService::abort()` caps `error_class` the
            // same way, for the same reason).
            is_string($graphCode) && $graphCode !== '' ? mb_substr($graphCode, 0, 40) : (string) $status,
            $objectsRead,
        );
    }

    /**
     * A bare libcurl error number and nothing else out of Guzzle's handler
     * context — the same rule `HttpChannel::curlErrno()` applies, for the
     * same reason: the handler context's `url`/`error` strings can repeat a
     * credentialed host.
     */
    /**
     * The lower-cased host of a URL this class was about to fetch, capped to
     * `error_code`'s varchar(40) — used only when {@see DirectoryHostGuard}
     * has already refused the URL, so this is reporting a host the admin
     * themselves put in the connector's own config, not provider-supplied
     * text (ADR 0018 §2.3 is about never trusting a provider's response;
     * this is not one).
     */
    private function hostFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_substr(strtolower($host), 0, 40) : null;
    }

    private function curlErrno(ConnectionException $e): ?string
    {
        $previous = $e->getPrevious();

        if (! $previous instanceof \GuzzleHttp\Exception\ConnectException) {
            return null;
        }

        $errno = $previous->getHandlerContext()['errno'] ?? null;

        return is_int($errno) ? (string) $errno : null;
    }
}
