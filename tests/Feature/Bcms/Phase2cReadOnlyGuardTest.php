<?php

namespace Tests\Feature\Bcms;

use App\Services\Bcms\Identity\ChangeApplier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0018 §3.2: "the reviewer confirms by inspection" is not an enforcement
 * mechanism. This is one of the three that is.
 *
 * A GREP, NOT A MOCK ASSERTION. Scanning the source under
 * `app/Services/Bcms/Identity` finds every `Http::post|put|patch|delete` call
 * in the files that could make one, rather than trusting that a future
 * change routes through whatever a unit test happens to exercise. The single
 * POST this namespace makes — the OAuth2 token request — is allowlisted by
 * shape (`Http::asForm()->...->post($tokenUrl, ...)` against a URL built from
 * `token_base_url`), and nothing else may appear.
 */
class Phase2cReadOnlyGuardTest extends TestCase
{
    #[Test]
    public function no_identity_service_writes_to_a_graph_host(): void
    {
        $directory = app_path('Services/Bcms/Identity');
        $this->assertDirectoryExists($directory);

        $offenders = [];

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $contents = file_get_contents($file) ?: '';
            $lines = explode("\n", $contents);

            foreach ($lines as $index => $line) {
                if (! preg_match('/Http::(post|put|patch|delete)\s*\(/', $line)
                    && ! preg_match('/->(post|put|patch|delete)\s*\(/', $line)) {
                    continue;
                }

                // The one allowlisted shape: a POST built against a token
                // endpoint variable, never a literal Graph host and never any
                // other verb anywhere in this namespace.
                $isTokenPost = str_contains($line, '->post($tokenUrl') && str_contains($file, 'EntraGraphClient.php');

                if ($isTokenPost) {
                    continue;
                }

                $offenders[] = basename($file).':'.($index + 1).' — '.trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A write-shaped HTTP call was found under app/Services/Bcms/Identity outside the allowlisted \n"
            ."token request. Standing rule 3: this product never writes back to a directory.\n\n"
            .implode("\n", $offenders),
        );
    }

    #[Test]
    public function the_directory_client_interface_declares_no_write_method(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(\App\Contracts\Bcms\DirectoryClient::class))->getMethods(),
        );

        foreach ($methods as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(create|update|delete|write|patch|put|post|set)/i',
                $method,
                "DirectoryClient::{$method}() reads like a write. Standing rule 3 is non-negotiable.",
            );
        }
    }

    #[Test]
    public function the_write_allowlist_and_never_write_list_are_disjoint_and_match_the_adr(): void
    {
        $this->assertEmpty(
            array_intersect(ChangeApplier::WRITE_ALLOWLIST, ChangeApplier::NEVER_WRITE),
            'A field cannot be both writable and never-written.',
        );

        $this->assertSame(
            ['full_name', 'employee_id', 'title', 'business_unit_id', 'site_id', 'email', 'mobile_primary',
                'manager_contact_id', 'manager_user_id', 'is_active', 'ad_object_guid', 'ad_synced_at', 'source'],
            ChangeApplier::WRITE_ALLOWLIST,
            'The write allowlist drifted from ADR 0018 §3.4 — that list is load-bearing, not decorative.',
        );

        $this->assertSame(
            ['whatsapp', 'mobile_secondary', 'next_of_kin', 'channel_preferences', 'preferred_language',
                'consent_status', 'consent_captured_at', 'consent_withdrawn_at', 'verification_status',
                'last_verified_at', 'latitude', 'longitude', 'geo_last_known', 'user_id'],
            ChangeApplier::NEVER_WRITE,
            'The never-write list drifted from ADR 0018 §3.4.',
        );
    }
}
