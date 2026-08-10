<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Holds the organization_id resolved for the current request, queued job or
 * console command.
 *
 * Bound as a container singleton (see AppServiceProvider) so that anything
 * resolving it during a request — global scopes, services, jobs dispatched
 * synchronously — sees the same tenant. The static helpers on this class are
 * thin wrappers over that singleton; there is deliberately no global mutable
 * state, so parallel test processes and queued jobs cannot leak into one
 * another.
 *
 * HTTP requests always have a tenant bound: ResolveTenant aborts with 403
 * before the controller runs if the authenticated user has no organization.
 * Console and system contexts may legitimately run untenanted — in that case
 * the global scope does not filter, and callers that need cross-tenant reads
 * must say so explicitly via bypass() / Model::bypassTenancy().
 */
class TenantContext
{
    protected ?int $organizationId = null;

    /** Nesting depth of active bypass() calls. */
    protected int $bypassDepth = 0;

    public static function instance(): self
    {
        return app(self::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    public function setOrganizationId(?int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function forget(): void
    {
        $this->organizationId = null;
    }

    /* ------------------------------------------------------------------ */
    /*  Static conveniences */
    /* ------------------------------------------------------------------ */

    /**
     * The current tenant id, or null when running untenanted (console, system
     * jobs). Use this when an absent tenant is an acceptable outcome.
     */
    public static function organizationIdOrNull(): ?int
    {
        return self::instance()->getOrganizationId();
    }

    /**
     * The current tenant id, or a hard failure.
     *
     * This replaces the old "current user's org, or else org 1" idiom that was
     * spread across the controllers: there is no fallback tenant, and silently
     * defaulting to organization 1 is precisely the bug this class prevents.
     *
     * @throws RuntimeException when no tenant has been resolved
     */
    public static function organizationId(): int
    {
        $id = self::organizationIdOrNull();

        if ($id === null) {
            throw new RuntimeException(
                'No organization resolved for the current context. '
                .'Web requests must pass through the ResolveTenant middleware; '
                .'console and system contexts must set the tenant explicitly '
                .'via TenantContext::set() or opt out via TenantContext::bypass().'
            );
        }

        return $id;
    }

    public static function set(?int $organizationId): void
    {
        self::instance()->setOrganizationId($organizationId);
    }

    public static function clear(): void
    {
        self::instance()->forget();
    }

    public static function hasOrganization(): bool
    {
        return self::organizationIdOrNull() !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  Bypass */
    /* ------------------------------------------------------------------ */

    public static function isBypassed(): bool
    {
        return self::instance()->bypassDepth > 0;
    }

    /**
     * Run a callback with tenant scoping disabled.
     *
     * Every use is logged with its call site, because a cross-tenant read is
     * always worth being able to account for after the fact.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function bypass(callable $callback, string $reason = 'unspecified')
    {
        $context = self::instance();

        if ($context->bypassDepth === 0) {
            self::logBypass($reason);
        }

        $context->bypassDepth++;

        try {
            return $callback();
        } finally {
            $context->bypassDepth--;
        }
    }

    /**
     * Run a callback as a specific tenant, restoring the previous tenant after.
     * Used by system jobs that iterate organizations.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function actingAs(?int $organizationId, callable $callback)
    {
        $context = self::instance();
        $previous = $context->getOrganizationId();
        $context->setOrganizationId($organizationId);

        try {
            return $callback();
        } finally {
            $context->setOrganizationId($previous);
        }
    }

    protected static function logBypass(string $reason): void
    {
        $frame = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8))
            ->first(fn (array $f) => ($f['class'] ?? null) !== self::class);

        logger()->warning('Tenancy bypassed', [
            'reason' => $reason,
            'caller' => trim(($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? 'closure')),
            'file' => $frame['file'] ?? null,
            'line' => $frame['line'] ?? null,
            'organization_id' => self::organizationIdOrNull(),
            'user_id' => auth()->id(),
        ]);
    }
}
