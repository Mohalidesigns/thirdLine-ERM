<?php

namespace Tests\Feature\Sso;

use App\Models\Organization;
use App\Models\OrganizationSsoSetting;
use App\Support\Sso\SamlDriver;
use App\Support\Sso\SsoAuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use OneLogin\Saml2\ValidationError;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use Tests\Feature\OrganizationSsoSettingsTest;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * NDPA: a rejected SAML assertion must not write the assertion's contents to the log.
 *
 * PREVIOUS BEHAVIOUR: SamlDriver logged `getLastErrorReason()` verbatim. The
 * toolkit builds that string from the assertion itself (NameID, issuer,
 * audience), so a rejected sign-in put a data subject's email address in
 * laravel.log.
 */
class SamlRejectionLogCarriesNoPersonalDataTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'jane.doe@example.com';

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function a_rejected_assertion_logs_error_codes_and_never_the_reason_text(): void
    {
        $org = Organization::create([
            'name' => 'Alpha Bank PLC',
            'short_name' => 'ALPHA',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
        $cert = (new ReflectionClassConstant(OrganizationSsoSettingsTest::class, 'TEST_CERTIFICATE'))->getValue();
        $setting = TenantContext::actingAs($org->id, fn () => OrganizationSsoSetting::create([
            'organization_id' => $org->id,
            'enabled' => true,
            'driver' => 'saml',
            'label' => 'SAML',
            'slug' => 'alpha',
            'saml_idp_entity_id' => 'https://idp.example.test/metadata',
            'saml_idp_sso_url' => 'https://idp.example.test/sso',
            'saml_idp_x509_cert' => $cert,
        ]));

        // Unsigned, addressed to nobody we know, and quoting the data subject
        // in NameID, Issuer and Audience: every one of them a place the
        // toolkit's reason text can echo it back.
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            .' ID="_r1" Version="2.0" IssueInstant="2020-01-01T00:00:00Z" Destination="https://evil.example/acs">'
            .'<saml:Issuer>'.self::EMAIL.'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'<saml:Assertion ID="_a1" Version="2.0" IssueInstant="2020-01-01T00:00:00Z"><saml:Issuer>'.self::EMAIL.'</saml:Issuer>'
            .'<saml:Subject><saml:NameID>'.self::EMAIL.'</saml:NameID></saml:Subject>'
            .'<saml:Conditions NotBefore="2020-01-01T00:00:00Z" NotOnOrAfter="2020-01-01T00:05:00Z"><saml:AudienceRestriction>'
            .'<saml:Audience>'.self::EMAIL.'</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            .'</saml:Assertion></samlp:Response>';
        $_POST['SAMLResponse'] = base64_encode($xml);

        Log::listen(function ($event) {
            $this->logged[] = ['message' => (string) $event->message, 'context' => $event->context];
        });

        try {
            (new SamlDriver($setting))->processResponse();
            $this->fail('An unsigned assertion must be rejected.');
        } catch (SsoAuthenticationException) {
            // expected
        } finally {
            unset($_POST['SAMLResponse']);
        }

        $rejected = array_values(array_filter($this->logged, fn ($e) => $e['message'] === 'SAML assertion rejected'));
        $this->assertCount(1, $rejected, 'the rejection must still be logged, or this test proves nothing');
        $this->assertNotSame([], $rejected[0]['context']['errors'], 'error codes are kept');
        $this->assertArrayNotHasKey('reason', $rejected[0]['context']);
        // The cause an operator needs: getErrors() alone is only ever
        // 'invalid_response', so the numeric ValidationError constant and the
        // exception class are what tell a wrong audience from a bad signature.
        $this->assertArrayHasKey('validation_code', $rejected[0]['context']);
        $this->assertIsInt($rejected[0]['context']['validation_code']);
        $this->assertSame(ValidationError::class, $rejected[0]['context']['validation_exception']);
        $this->assertSame(
            ValidationError::ASSERTION_EXPIRED,
            $rejected[0]['context']['validation_code'],
            'the fixture assertion expired in 2020; the log must say so, not just invalid_response',
        );
        $this->assertStringNotContainsString(self::EMAIL, json_encode($this->logged, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('example.com', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function error_codes_that_are_not_machine_tokens_are_dropped_and_real_codes_kept(): void
    {
        $org = Organization::create([
            'name' => 'Alpha Bank PLC',
            'short_name' => 'ALPHA',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
        $setting = TenantContext::actingAs($org->id, fn () => OrganizationSsoSetting::create([
            'organization_id' => $org->id,
            'enabled' => true,
            'driver' => 'saml',
            'label' => 'SAML',
            'slug' => 'alpha',
            'saml_idp_entity_id' => 'https://idp.example.test/metadata',
            'saml_idp_sso_url' => 'https://idp.example.test/sso',
        ]));

        // Stand in for the toolkit so getErrors() can return values it never
        // returns today: the allowlist exists for the day it does.
        $auth = Mockery::mock('overload:OneLogin\\Saml2\\Auth');
        $auth->shouldReceive('processResponse')->once();
        $auth->shouldReceive('getLastErrorException')->andReturn(null);
        $auth->shouldReceive('getErrors')->andReturn([
            'invalid_response',
            self::EMAIL,
            str_repeat('a', 200),
            'has some spaces',
        ]);

        Log::listen(function ($event) {
            $this->logged[] = ['message' => (string) $event->message, 'context' => $event->context];
        });

        try {
            (new SamlDriver($setting))->processResponse();
            $this->fail('A response with errors must be rejected.');
        } catch (SsoAuthenticationException) {
            // expected
        }

        $rejected = array_values(array_filter($this->logged, fn ($e) => $e['message'] === 'SAML assertion rejected'));
        $this->assertCount(1, $rejected);
        $this->assertSame(['invalid_response'], $rejected[0]['context']['errors']);
        $this->assertArrayHasKey('validation_code', $rejected[0]['context']);
        $this->assertNull($rejected[0]['context']['validation_code'], 'null when the toolkit supplied no exception');
        $this->assertNull($rejected[0]['context']['validation_exception']);
    }
}
