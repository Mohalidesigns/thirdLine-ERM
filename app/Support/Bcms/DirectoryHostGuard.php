<?php

namespace App\Support\Bcms;

use App\Support\Http\OutboundUrlGuard;
use RuntimeException;

/**
 * The identity connector's outbound host check — ADR 0018 §2.3/§5, gate 2
 * blocking defect 1.
 *
 * TWO CHECKS, NOT ONE. `OutboundUrlGuard::assertSafe()` is the general SSRF
 * hygiene every outbound URL in the product goes through (scheme, no
 * credentials in the URL, no private/internal address) — necessary here but
 * not sufficient, because the actual attack this guards against is a
 * `bcms.identity.manage` holder (write-only on a secret, ADR 0018 §5)
 * pointing `token_base_url` at their OWN public host to have the decrypted
 * `client_secret` POSTed straight to them, or `graph_base_url` anywhere to
 * make the server fetch on their behalf — and an attacker's own server is a
 * perfectly public https host that the general check would wave through.
 * `assertAllowed()` is the closed answer underneath it: config('bcms.
 * identity.allowed_hosts') names the only hosts Microsoft's identity
 * platform and Graph actually run on, and NOT TENANT-EDITABLE is the point —
 * widening it is a code change, not a form field.
 *
 * ONE CHOKE POINT COVERS EVERY REQUEST THIS MODULE MAKES. `EntraGraphClient`
 * calls this from `token()` (the one POST, carrying the secret) and from
 * `send()` (every GET `users()`/`delta()`/`managerObjectId()`/
 * `testConnection()` make) — the same `send()` a page's `@odata.nextLink`
 * and a stored `delta_link` both funnel back through on the next iteration,
 * so a link that has drifted off the allowlist is caught before it is
 * followed, not only on the first request of a run.
 */
final class DirectoryHostGuard
{
    /** @throws RuntimeException */
    public static function assertAllowed(string $url): void
    {
        OutboundUrlGuard::assertSafe($url);

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowlist = array_map('strtolower', (array) config('bcms.identity.allowed_hosts', []));

        if ($host === '' || ! in_array($host, $allowlist, true)) {
            throw new RuntimeException(
                "[{$host}] is not one of the Microsoft identity/Graph hosts this connector may reach."
            );
        }
    }

    public static function isAllowed(string $url): bool
    {
        try {
            self::assertAllowed($url);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
