<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * Refuses outbound URLs that point back inside the network.
 *
 * WEBHOOKS AND CONNECTORS ARE SERVER-SIDE REQUEST FORGERY BY DESIGN: the
 * platform makes an HTTP request to an address somebody typed into a form. On a
 * risk platform deployed inside a bank, an unchecked one is a way for anyone who
 * can create a webhook to read whatever the application server can reach —
 * http://169.254.169.254/ for cloud credentials, http://localhost:6379 to talk
 * to Redis, http://10.0.0.5/ to probe the internal network. The response comes
 * back in the delivery log.
 *
 * The rules:
 *   - http or https only. No file://, gopher://, ftp://.
 *   - https unless the host is explicitly allowlisted — a webhook carries risk
 *     data, and over plain http it carries it in clear across the network.
 *   - the resolved address must be publicly routable. DNS is resolved HERE, and
 *     the check is on the resolved address rather than on the hostname, because
 *     a name that resolves to 127.0.0.1 is the obvious way around a
 *     name-based blocklist.
 *   - no credentials in the URL, which end up in logs.
 *
 * DNS REBINDING is not fully solved by this and saying so is more useful than
 * implying otherwise: a host may resolve to a public address here and a private
 * one when the request is actually made. Closing that needs the resolved IP
 * pinned into the connection, which Guzzle can do per-request; the allowlist
 * below is the practical mitigation until then, and `webhooks.allow_private` is
 * off by default so a local integration is a deliberate configuration choice.
 */
class OutboundUrlGuard
{
    /**
     * @throws RuntimeException with a message meant for the person who typed the URL
     */
    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('That is not a valid absolute URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Only http and https are supported; [{$scheme}] is not.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(
                'Put credentials in a header rather than in the URL — a URL ends up in logs and in the delivery history.'
            );
        }

        $host = strtolower($parts['host']);
        $allowPrivate = (bool) config('webhooks.allow_private_hosts', false);
        $allowlist = array_map('strtolower', (array) config('webhooks.allowed_hosts', []));

        if (in_array($host, $allowlist, true)) {
            return;
        }

        if ($scheme !== 'https' && ! $allowPrivate) {
            throw new RuntimeException(
                'Use https. A webhook carries risk data, and over plain http it crosses the network in clear.'
            );
        }

        foreach (self::resolve($host) as $address) {
            if (! self::isPubliclyRoutable($address) && ! $allowPrivate) {
                throw new RuntimeException(
                    "[{$host}] resolves to {$address}, which is inside this network. "
                    .'A webhook may only be sent to an address reachable from the public internet.'
                );
            }
        }
    }

    public static function isSafe(string $url): bool
    {
        try {
            self::assertSafe($url);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // Bracketed IPv6 literal.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return [trim($host, '[]')];
        }

        $addresses = [];

        foreach ((array) @dns_get_record($host, DNS_A + DNS_AAAA) as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        $addresses = array_values(array_filter($addresses));

        if ($addresses === []) {
            // A name nothing can resolve is refused rather than allowed
            // through: an unresolvable host is either a typo or a name that
            // resolves to something different from where this check runs.
            throw new RuntimeException("[{$host}] does not resolve to any address.");
        }

        return $addresses;
    }

    private static function isPubliclyRoutable(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
