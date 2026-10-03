<?php

namespace Tests\Feature\Bcms;

use App\Support\Bcms\DirectoryHostGuard;
use App\Support\Http\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * BCMS Phase 2C, gate 2 blocking defect 1 (SSRF + secret exfiltration) —
 * adversarial coverage of `App\Support\Bcms\DirectoryHostGuard` itself,
 * directly, rather than only through `EntraGraphClient`/the connector form.
 *
 * NO `RefreshDatabase`. `DirectoryHostGuard` and `OutboundUrlGuard` are pure
 * static checks over a URL string and `config()`; nothing here touches a
 * table.
 *
 * THIS FILE'S OWN TESTS RESOLVE REAL DNS, DELIBERATELY. `WebhookTest::
 * setUp()` documents this codebase's own rule that a test must not depend on
 * DNS resolving, and bypasses it there by adding the fixture host to
 * `webhooks.allowed_hosts`. That bypass is NOT available here: `OutboundUrlGuard
 * ::assertSafe()`'s own allowlist check returns BEFORE its https/DNS checks
 * run at all (`app/Support/Http/OutboundUrlGuard.php`), so adding a Microsoft
 * FQDN to `webhooks.allowed_hosts` for this file would silently wave an
 * `http://` URL to that same host through elsewhere in the suite — proven
 * empirically while writing this file: it broke `Phase2cScreensTest::
 * an_http_token_base_url_is_refused_at_save()`. The six Microsoft hosts and
 * `example.com` (IANA-reserved for exactly this kind of test, permanently
 * resolvable) are the only hosts this file resolves, and each is a single,
 * fast, well-known lookup — not the O(pages) exposure a full `runFull()`
 * against `EntraGraphClient` would carry if it depended on the same thing.
 */
class Phase2cDirectoryHostGuardTest extends TestCase
{
    #[Test]
    public function plain_http_is_refused_even_to_an_allowed_host(): void
    {
        $this->expectException(RuntimeException::class);

        DirectoryHostGuard::assertAllowed('http://login.microsoftonline.com/tenant/oauth2/v2.0/token');
    }

    #[Test]
    public function a_lookalike_subdomain_suffix_is_refused(): void
    {
        // `login.microsoftonline.com` is a LABEL of this host, not the host
        // itself — a suffix/substring check would be fooled by this; an
        // exact-string allowlist match is not.
        $this->assertFalse(DirectoryHostGuard::isAllowed(
            'https://login.microsoftonline.com.attacker.test/tenant/oauth2/v2.0/token',
        ));
    }

    #[Test]
    public function a_fragment_naming_the_real_host_does_not_fool_the_check(): void
    {
        $this->assertFalse(DirectoryHostGuard::isAllowed(
            'https://attacker.test/tenant#login.microsoftonline.com',
        ));
    }

    #[Test]
    public function userinfo_tricks_are_refused_in_both_directions(): void
    {
        // The real host as USERINFO in front of the attacker's own host —
        // and the reverse, in case a future refactor swaps which side of
        // `assertSafe()`'s credential check runs first.
        $this->assertFalse(DirectoryHostGuard::isAllowed('https://login.microsoftonline.com@attacker.test/tenant'));
        $this->assertFalse(DirectoryHostGuard::isAllowed('https://attacker.test@login.microsoftonline.com/tenant'));
    }

    #[Test]
    public function an_ip_literal_is_refused_even_when_it_would_be_publicly_routable(): void
    {
        // A real, publicly-routable Microsoft Azure address space entry —
        // refused purely because it is not one of the six named FQDNs, which
        // is the point: the allowlist is closed on NAME, not on whether the
        // general SSRF check would have waved the address through.
        $this->assertFalse(DirectoryHostGuard::isAllowed('https://20.190.128.1/tenant'));
    }

    #[Test]
    public function a_trailing_dot_on_an_otherwise_valid_host_is_refused(): void
    {
        // `login.microsoftonline.com.` (a fully-qualified DNS name with its
        // trailing root dot) is a different STRING from the allowlist entry
        // even though it names the identical host to a resolver — the guard
        // fails closed on the byte-for-byte mismatch rather than normalising
        // it, which is the safe direction to be wrong in.
        $this->assertFalse(DirectoryHostGuard::isAllowed('https://login.microsoftonline.com./tenant'));
    }

    #[Test]
    public function mixed_case_on_an_allowed_host_is_still_allowed(): void
    {
        $this->assertTrue(DirectoryHostGuard::isAllowed('HTTPS://LOGIN.MICROSOFTONLINE.COM/tenant/oauth2/v2.0/token'));
        $this->assertTrue(DirectoryHostGuard::isAllowed('https://GRAPH.MICROSOFT.COM/v1.0/users'));
    }

    #[Test]
    public function a_non_default_port_on_an_allowed_host_is_still_allowed(): void
    {
        // The allowlist checks the host, not the authority as a whole — a
        // port carries no SSRF risk here because the host must still resolve
        // to an address DNS actually hands back for that name, which is not
        // attacker-controlled for a real Microsoft FQDN.
        $this->assertTrue(DirectoryHostGuard::isAllowed('https://login.microsoftonline.com:8443/tenant'));
    }

    /**
     * THE CENTRAL CASE THIS DEFECT IS ABOUT. `example.com` is IANA-reserved
     * for documentation and testing (RFC 2606), permanently resolvable, and
     * a perfectly ordinary public https host — exactly the shape
     * `OutboundUrlGuard::assertSafe()`'s general SSRF hygiene (scheme,
     * no credentials, publicly-routable resolved address) was always going
     * to wave through, because none of those checks has any way to know
     * this host is not Microsoft's. Proving `OutboundUrlGuard::isSafe()`
     * returns true for it BEFORE proving `DirectoryHostGuard` still refuses
     * it is what makes this test worth more than the private-address/
     * unresolvable-host cases above: those would already have been caught
     * by the general check alone, so a mutant that deleted `DirectoryHostGuard`'s
     * own allowlist entirely (leaving only `OutboundUrlGuard::assertSafe()`)
     * would still pass every one of them. It would not pass this one.
     */
    #[Test]
    public function an_attackers_own_real_public_https_host_passes_the_general_check_but_is_refused_by_the_allowlist(): void
    {
        $attackerControllable = 'https://example.com/v1.0/users';

        $this->assertTrue(
            OutboundUrlGuard::isSafe($attackerControllable),
            'This case only proves something if the GENERAL SSRF check alone would have allowed it through.',
        );

        $this->assertFalse(
            DirectoryHostGuard::isAllowed($attackerControllable),
            'A real, resolvable, public https host that is not one of the six Microsoft FQDNs must still be refused.',
        );

        try {
            DirectoryHostGuard::assertAllowed($attackerControllable);
            $this->fail('DirectoryHostGuard::assertAllowed() must throw for a non-Microsoft public host.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('example.com', $e->getMessage());
            $this->assertStringContainsString('not one of the Microsoft identity/Graph hosts', $e->getMessage());
        }
    }

    #[Test]
    public function every_configured_allowed_host_is_itself_allowed(): void
    {
        foreach (config('bcms.identity.allowed_hosts', []) as $host) {
            $this->assertTrue(
                DirectoryHostGuard::isAllowed("https://{$host}/v1.0/users"),
                "{$host} is on the connector's own allowlist and must be allowed.",
            );
        }
    }

    #[Test]
    public function the_allowlist_is_not_reachable_from_a_config_file_a_tenant_could_publish(): void
    {
        // ADR 0018 §2.3/§5: NOT TENANT-EDITABLE is the point of this list —
        // widening it is a code change, not a form field. `config:publish`
        // vendor-publishes a package's own config; this asserts the identity
        // allowlist is not sitting in one that a tenant's own deployment
        // tooling could copy out and edit, the same guarantee `config/tprm.
        // php` documents for `engine_version` (development standard, "the
        // database gap" sibling concern).
        $this->assertFileExists(config_path('bcms.php'));
        $this->assertStringNotContainsString(
            'allowed_hosts',
            (string) file_get_contents(app_path('Http/Requests/Bcms/UpdateIdentityConnectorRequest.php')),
            'The connector form request must never accept an allowed_hosts field from a tenant.',
        );
    }
}
