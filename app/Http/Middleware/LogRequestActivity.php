<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every state-changing HTTP action (POST/PUT/PATCH/DELETE) that hits
 * an authenticated user's session into activity_logs. GET requests are
 * intentionally skipped — reads are noise; the audit log tracks what the
 * user did, not what they saw.
 *
 * Runs after the response so it does not extend request latency.
 */
class LogRequestActivity
{
    /**
     * Route-name prefixes we never log (polling, static, heartbeat, hot module reload).
     *
     * @var array<int, string>
     */
    private const IGNORED_ROUTE_PREFIXES = [
        'notifications.unread-count',
        'login',
        'logout',
        'password.',
        'sanctum.',
        'livewire.',
        'search.suggest',
    ];

    /**
     * URL path prefixes we skip even if unnamed.
     *
     * @var array<int, string>
     */
    private const IGNORED_PATH_PREFIXES = [
        '_debugbar',
        'telescope',
        'horizon',
        'livewire',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Fire after the response is sent so we don't add to the user's TTFB.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldLog($request, $response)) {
            return;
        }

        try {
            $routeName = optional($request->route())->getName();
            $status = $response->getStatusCode();
            $denied = in_array($status, [403, 419], true);

            ActivityLogger::log(
                event: $denied ? 'access_denied' : $this->eventFor($request, $routeName),
                subject: null,
                description: ($denied ? 'Access denied: ' : '').$this->describe($request, $routeName),
                properties: [
                    'status' => $status,
                    'params' => $this->safeRouteParameters($request),
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        // 2xx/3xx are real state changes. Among 4xx, keep the authorization-boundary
        // signals — 403 (forbidden) and 419 (CSRF/token mismatch) are privilege-probe
        // signals and belong in the audit trail — but drop 400/404/422 validation/not-found noise.
        $status = $response->getStatusCode();
        if ($status >= 400 && $status < 500 && ! in_array($status, [403, 419], true)) {
            return false;
        }

        $routeName = optional($request->route())->getName() ?? '';
        foreach (self::IGNORED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return false;
            }
        }

        $path = ltrim($request->path(), '/');
        foreach (self::IGNORED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function eventFor(Request $request, ?string $routeName): string
    {
        if ($routeName) {
            return $routeName;
        }

        return strtolower($request->method()).':'.$request->path();
    }

    private function describe(Request $request, ?string $routeName): string
    {
        $method = $request->method();

        if ($routeName) {
            return $method.' '.$routeName;
        }

        return $method.' '.$request->path();
    }

    /**
     * Route parameter IDs are useful context; full request payload is not
     * — it is noisy, potentially large, and could contain sensitive data.
     *
     * @return array<string, mixed>
     */
    private function safeRouteParameters(Request $request): array
    {
        try {
            $params = $request->route()?->parameters() ?? [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($params as $key => $value) {
            if (is_object($value) && method_exists($value, 'getKey')) {
                $out[$key] = $value->getKey();
            } elseif (is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
