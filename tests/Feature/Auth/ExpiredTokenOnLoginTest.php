<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stale CSRF token on the login form sends the user back to the form, not to
 * a dead end.
 *
 * WHY THIS EXISTS. Laravel answers an expired token with a bare
 * "419 | PAGE EXPIRED": no explanation, no link, no form. On the login screen
 * that is the worst possible place for it — the person is not signed in, so
 * there is no navigation to escape through, and the back button returns the
 * same expired page. It happens for the most ordinary reason there is: leaving
 * the tab open longer than the session lifetime and then typing a password.
 * That is exactly how it was reported.
 *
 * THREE THINGS HERE ARE EASY TO GET WRONG, and all three were, before this
 * test existed — each of them produces code or a test that quietly does
 * nothing rather than failing:
 *
 *   1. `POST /login` is UNNAMED in routes/auth.php — only the GET carries the
 *      `login` name — so a handler matching on the route name never fires on
 *      the one request that fails this way. It matches on the path.
 *   2. Handler::render() runs prepareException() BEFORE any render callback,
 *      and that turns a TokenMismatchException into an HttpException(419). A
 *      callback type-hinted on TokenMismatchException is never reached.
 *   3. The CSRF middleware skips itself under test, and the class to rebind is
 *      ValidateCsrfToken — VerifyCsrfToken is the Laravel 10 name and binding
 *      it has no effect at all. See setUp().
 */
class ExpiredTokenOnLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // VerifyCsrfToken SKIPS ITSELF UNDER TEST — `runningUnitTests()` inside
        // its handle() returns true and the token is never checked — so
        // `withMiddleware()` is not enough and a suite written without this
        // would pass while asserting nothing. Swapping in a subclass that
        // declines to skip is what makes these tests real.
        // ValidateCsrfToken, not VerifyCsrfToken: Laravel 11 renamed it and
        // the web group registers the new name. Binding the deprecated alias
        // silently does nothing, which is its own way of writing a test that
        // asserts nothing.
        $this->app->singleton(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    #[Test]
    public function an_expired_token_on_the_login_form_returns_the_user_to_the_form(): void
    {
        $response = $this->post('/login', [
            '_token' => 'a-token-from-a-page-that-was-open-too-long',
            'email' => 'someone@example.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');

        $this->assertStringContainsString(
            'expired',
            (string) session('error'),
            'The message should say what happened, not merely that something did.'
        );
    }

    #[Test]
    public function the_password_is_not_carried_back_into_the_form(): void
    {
        $this->post('/login', [
            '_token' => 'stale',
            'email' => 'someone@example.test',
            'password' => 'hunter2',
        ]);

        // The email comes back so the user does not retype it; the password
        // must not, or it would sit in the session and be re-rendered into the
        // page as old input.
        $this->assertSame('someone@example.test', session('_old_input.email'));
        $this->assertNull(session('_old_input.password'));
    }

    #[Test]
    public function the_forgot_password_form_recovers_the_same_way(): void
    {
        $this->post('/forgot-password', ['_token' => 'stale', 'email' => 'someone@example.test'])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function a_form_inside_the_application_still_gets_the_plain_419(): void
    {
        // Deliberately NOT broadened. Inside the application a 419 on a form
        // the user has filled in must not redirect somewhere that silently
        // discards their work — the blunt page at least does not pretend the
        // submission succeeded.
        $organization = Organization::create([
            'name' => 'Token Test Bank',
            'short_name' => 'TTB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Token Test User',
            'email' => 'token-test@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post('/risk/register', ['_token' => 'stale'])
            ->assertStatus(419);
    }

    #[Test]
    public function a_valid_token_still_logs_a_user_in(): void
    {
        // The guard on the guard: a handler that swallowed every 419 would
        // also make a broken login look like a working one.
        $organization = Organization::create([
            'name' => 'Token Test Bank',
            'short_name' => 'TTB2',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Real User',
            'email' => 'real-user@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $this->get('/login')->assertOk();

        $this->post('/login', [
            '_token' => csrf_token(),
            'email' => 'real-user@example.test',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }
}
