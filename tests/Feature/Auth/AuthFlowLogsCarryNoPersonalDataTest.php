<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\SsoController;
use App\Models\Organization;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * NDPA: the auth flow must not write a data subject's email address to the log.
 *
 * PREVIOUS BEHAVIOUR: production has no SMTP transport configured, so
 * PasswordResetLinkController's catch block fired on EVERY reset request and
 * wrote `['email' => $user->email]` (and an exception message that mail
 * transports routinely build from the recipient) to laravel.log. The log file
 * therefore became a growing list of staff email addresses.
 *
 * The user-facing answer must stay the anti-enumeration answer: identical
 * whether or not the address exists, and whether or not the mail was sent.
 */
class AuthFlowLogsCarryNoPersonalDataTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS = 'If that email exists, a reset link has been sent.';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
        $this->logged = [];
        Log::listen(function ($event) {
            $this->logged[] = [
                'level' => $event->level,
                'message' => (string) $event->message,
                'context' => $event->context,
            ];
        });
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function a_failed_reset_mail_logs_the_user_id_and_never_the_email_address(): void
    {
        $user = $this->user('jane.okafor@example.test');

        // The shape of a real transport error: the recipient is echoed in the message.
        Mail::shouldReceive('raw')->once()->andThrow(
            new RuntimeException('Expected response code "250" but got "550": <jane.okafor@example.test> mailbox unavailable.', 550)
        );

        $this->post('/forgot-password', ['email' => 'jane.okafor@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', self::STATUS);

        $entries = array_values(array_filter(
            $this->logged,
            fn ($e) => str_contains(strtolower($e['message']), 'password reset'),
        ));
        $this->assertCount(1, $entries, 'The failure should be logged exactly once.');

        $entry = $entries[0];
        $this->assertSame($user->id, $entry['context']['user_id'] ?? null);
        $this->assertSame($user->organization_id, $entry['context']['organization_id'] ?? null);
        $this->assertSame(RuntimeException::class, $entry['context']['exception'] ?? null);

        // Not in the message, not in the context, not anywhere in anything logged.
        $everything = json_encode($this->logged, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('jane.okafor', $everything);
        $this->assertStringNotContainsStringIgnoringCase('example.test', $everything);
        $this->assertStringNotContainsString($user->name, $everything);
    }

    #[Test]
    public function the_response_is_identical_for_an_existing_and_an_unknown_address_and_a_failing_mailer(): void
    {
        $this->user('known@example.test');

        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('transport down'));

        $known = $this->post('/forgot-password', ['email' => 'known@example.test']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody-here@example.test']);

        $known->assertRedirect()->assertSessionHas('status', self::STATUS)->assertSessionHasNoErrors();
        $unknown->assertRedirect()->assertSessionHas('status', self::STATUS)->assertSessionHasNoErrors();

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
    }

    #[Test]
    public function an_sso_integration_failure_logs_the_exception_class_and_status_but_not_its_message(): void
    {
        // An IdP error body can echo the subject's email; Guzzle copies a slice of it into the message.
        $exception = new ClientException(
            'Client error: `POST https://idp.example.test/token` resulted in a `400 Bad Request` response: '
            .'{"error":"invalid_grant","error_description":"No account for jane.okafor@example.test"}',
            new PsrRequest('POST', 'https://idp.example.test/token'),
            new PsrResponse(400),
        );

        $method = new ReflectionMethod(SsoController::class, 'genericFailure');
        $method->setAccessible(true);
        $response = $method->invoke(app(SsoController::class), 'acme', $exception);

        $this->assertSame(route('login'), $response->getTargetUrl());

        $everything = json_encode($this->logged, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('SSO failed', $everything);
        $this->assertStringContainsString('acme', $everything);
        $this->assertStringContainsString('ClientException', $everything);
        $this->assertStringNotContainsStringIgnoringCase('jane.okafor', $everything);
        $this->assertStringNotContainsString('No account for', $everything);
    }

    private function user(string $email): User
    {
        $org = Organization::create([
            'name' => 'Reset Bank PLC',
            'short_name' => 'RSTB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($org->id);

        return User::create([
            'organization_id' => $org->id,
            'name' => 'Jane Okafor',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }
}
