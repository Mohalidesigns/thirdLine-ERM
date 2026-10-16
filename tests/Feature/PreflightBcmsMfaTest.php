<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BCMS Phase 7 criterion 13: alert dispatch needs a second factor, and the
 * `mfa` middleware passes everybody while features.mfa_totp is off. The deploy
 * gate is what enforces it. Asserted on THIS check's row, not the exit code -
 * preflight reports many checks and others fail in a test environment.
 */
class PreflightBcmsMfaTest extends TestCase
{
    use RefreshDatabase;

    private string $output = '';

    private function row(): string
    {
        Artisan::call('app:preflight', ['--allow-local' => true]);

        $this->output = Artisan::output();
        preg_match('/BCMS dispatch MFA\s*\|\s*(PASS|WARN|FAIL)/', $this->output, $m);

        return $m[1] ?? 'MISSING';
    }

    #[Test]
    public function it_fails_the_deploy_with_bcms_on_and_mfa_off(): void
    {
        Config::set('features.bcms', true);
        Config::set('features.mfa_totp', false);

        $this->assertSame('FAIL', $this->row());
        $this->assertStringContainsString('FEATURE_MFA_TOTP=true', $this->output);
    }

    #[Test]
    public function it_passes_with_bcms_on_and_mfa_on(): void
    {
        Config::set('features.bcms', true);
        Config::set('features.mfa_totp', true);

        $this->assertSame('PASS', $this->row());
    }

    #[Test]
    public function it_passes_with_bcms_off_whatever_mfa_is(): void
    {
        Config::set('features.bcms', false);

        foreach ([false, true] as $mfa) {
            Config::set('features.mfa_totp', $mfa);

            $this->assertSame('PASS', $this->row());
        }
    }

    private function secretsRow(): string
    {
        // Isolate from whatever the developer's .env enables.
        Config::set('bcms-gateways.whatsapp', []);
        Config::set('bcms-gateways.voice', []);
        Config::set('bcms-gateways.ussd', []);

        Artisan::call('app:preflight', ['--allow-local' => true]);
        preg_match('/BCMS gateway webhook secrets\s*\|\s*(PASS|WARN|FAIL)/', Artisan::output(), $m);

        return $m[1] ?? 'MISSING';
    }

    #[Test]
    public function it_warns_not_fails_when_an_enabled_gateway_has_no_webhook_secret(): void
    {
        Config::set('features.bcms', true);
        Config::set('bcms-gateways.sms', [['name' => 'termii', 'api_key' => 'live-key']]);
        Config::set('bcms-gateways.webhook_secrets.termii', null);

        $this->assertSame('WARN', $this->secretsRow());

        Config::set('bcms-gateways.webhook_secrets.termii', 'a-secret');
        $this->assertSame('PASS', $this->secretsRow());
    }

    #[Test]
    public function the_webhook_secret_warning_is_silent_when_bcms_is_off_or_no_gateway_is_enabled(): void
    {
        Config::set('bcms-gateways.sms', [['name' => 'termii', 'api_key' => 'live-key']]);
        Config::set('bcms-gateways.webhook_secrets.termii', null);
        Config::set('features.bcms', false);
        $this->assertSame('PASS', $this->secretsRow());

        Config::set('features.bcms', true);
        Config::set('bcms-gateways.sms', [['name' => 'termii', 'api_key' => null]]);
        $this->assertSame('PASS', $this->secretsRow());
    }

    #[Test]
    public function a_second_run_in_the_same_process_does_not_carry_the_first_runs_rows(): void
    {
        Config::set('features.bcms', true);
        Config::set('features.mfa_totp', false);
        Artisan::call('app:preflight', ['--allow-local' => true]);
        $first = Artisan::output();
        $this->assertStringContainsString('FAIL', $first);

        Config::set('features.mfa_totp', true);
        Artisan::call('app:preflight', ['--allow-local' => true]);
        $second = Artisan::output();

        $this->assertSame(1, preg_match_all('/BCMS dispatch MFA/', $second), 'Each check appears once, not twice.');
        $this->assertMatchesRegularExpression('/BCMS dispatch MFA\s*\|\s*PASS/', $second);
    }
}
