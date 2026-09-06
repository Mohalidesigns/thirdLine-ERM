<?php

namespace Tests\Feature\Integrations;

use App\Models\ApiToken;
use App\Models\Connector;
use App\Models\JobRun;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The four integration screens (migration Phase 6.7).
 *
 * Three of the four had the same defect in different clothes: **a form
 * offering a fixed list and a validator accepting anything**. A webhook could
 * subscribe to an event nobody publishes, and a token could be issued with a
 * scope that does not exist. Neither is dangerous — both fail closed — and
 * both are silent, which is worse than either: the integrator sees a healthy
 * subscription with an empty delivery log, or a token that refuses the request
 * it was issued for, and nothing anywhere says why.
 *
 * The fourth change is the connector's URL, checked when it is saved rather
 * than only when it is fetched.
 */
class IntegrationScreenTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);

        // OutboundUrlGuard RESOLVES DNS, deliberately: a hostname that resolves
        // to 127.0.0.1 is the obvious way around a name-based blocklist. In a
        // test that means a .test hostname blocks until the resolver gives up,
        // which is a minute per assertion. Allowlisting the fixture hosts skips
        // the lookup for them and leaves the guard fully exercised where it
        // matters — 169.254.169.254 is an address, not a name, so the
        // link-local case below still goes through the real check.
        config()->set('webhooks.allowed_hosts', [
            'receiver.example.test', 'feed.example.test', 'theirs.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Webhooks */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function webhookPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ours',
            'url' => 'https://receiver.example.test/hook',
            'events' => ['risk.created'],
            'is_active' => true,
        ], $overrides);
    }

    #[Test]
    public function a_webhook_cannot_subscribe_to_an_event_nobody_publishes(): void
    {
        $this->post(route('admin.webhooks.store'), $this->webhookPayload([
            'events' => ['risk.exploded'],
        ]))->assertSessionHasErrors('events.0');

        $this->assertDatabaseMissing('webhook_subscriptions', ['name' => 'Ours']);
    }

    #[Test]
    public function the_form_offers_exactly_the_events_the_validator_accepts(): void
    {
        $this->get(route('admin.webhooks.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Webhooks/Index')
                ->has('events')
                ->where('events', fn ($events) => collect($events)->flatten()->contains('risk.created'))
            );

        $this->post(route('admin.webhooks.store'), $this->webhookPayload())
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_webhook_url_that_could_not_be_reached_safely_is_refused_at_the_field(): void
    {
        // The guard ran here before too, but as a flash message. It is a field
        // error now, which is where the person typing it is looking.
        $this->post(route('admin.webhooks.store'), $this->webhookPayload([
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]))->assertSessionHasErrors('url');
    }

    #[Test]
    public function the_signing_secret_is_shown_once_and_never_listed(): void
    {
        $this->post(route('admin.webhooks.store'), $this->webhookPayload())->assertSessionHasNoErrors();

        // Once, on the request that follows creating it. `from()` because the
        // controller redirects back, and a test POST has no referer to go back
        // to unless one is given.
        $this->from(route('admin.webhooks.index'))
            ->followingRedirects()
            ->post(route('admin.webhooks.store'), $this->webhookPayload(['name' => 'Second']))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Webhooks/Index')
                ->where('revealedSecret', fn ($secret) => filled($secret))
            );

        // Never in the list.
        $this->get(route('admin.webhooks.index'))
            ->assertInertia(fn ($page) => $page
                ->where('revealedSecret', null)
                ->where('subscriptions', fn ($subscriptions) => collect($subscriptions)
                    ->every(fn ($webhook) => ! array_key_exists('secret', (array) $webhook)))
            );
    }

    /* ------------------------------------------------------------------ */
    /*  API tokens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_token_cannot_be_issued_with_a_scope_that_does_not_exist(): void
    {
        $this->post(route('admin.api-tokens.store'), [
            'name' => 'Integration',
            'scopes' => ['risk.summon'],
        ])->assertSessionHasErrors('scopes.0');
    }

    #[Test]
    public function a_personal_token_may_still_ask_for_everything_it_could_do_anyway(): void
    {
        // `*` on a personal token means "whatever I can do", which is what the
        // person could do by logging in.
        $this->post(route('admin.api-tokens.store'), [
            'name' => 'Mine',
            'scopes' => ['*'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['*'], ApiToken::where('name', 'Mine')->firstOrFail()->abilities);
    }

    #[Test]
    public function a_machine_token_may_not(): void
    {
        // It acts as nobody, so its scopes are the whole of its authority —
        // ApiToken::assertMachineScopesAreExplicit(), which the controller
        // catches so the operator gets told rather than a 500.
        $this->post(route('admin.api-tokens.store'), [
            'name' => 'Robot',
            'token_type' => 'client_credentials',
            'scopes' => ['*'],
        ])->assertSessionHas('error');

        // Sanctum's table: ApiToken extends PersonalAccessToken.
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Robot']);
    }

    #[Test]
    public function the_plaintext_is_shown_once_and_never_listed(): void
    {
        $this->from(route('admin.api-tokens.index'))
            ->followingRedirects()
            ->post(route('admin.api-tokens.store'), ['name' => 'Shown once', 'scopes' => ['*']])
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ApiTokens/Index')
                ->where('revealedToken', fn ($token) => filled($token))
            );

        $this->get(route('admin.api-tokens.index'))
            ->assertInertia(fn ($page) => $page
                ->where('revealedToken', null)
                ->where('tokens', fn ($tokens) => collect($tokens)
                    ->every(fn ($token) => ! array_key_exists('token', (array) $token)))
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Connectors */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_connector_url_is_checked_when_it_is_saved_not_only_when_it_is_fetched(): void
    {
        // RestConnector has always called OutboundUrlGuard before fetching, so
        // this was never reachable — what was missing is being told now rather
        // than by a failed run recorded as though the source were down.
        $this->post(route('admin.connectors.store'), [
            'type' => 'rest',
            'name' => 'Metadata',
            'config' => ['url' => 'http://169.254.169.254/latest/meta-data/'],
        ])->assertSessionHasErrors('config.url');

        $this->assertDatabaseMissing('connectors', ['name' => 'Metadata']);
    }

    #[Test]
    public function saving_a_connector_does_not_wipe_credentials_it_was_never_shown(): void
    {
        $connector = Connector::create([
            'organization_id' => $this->organization->id,
            'type' => 'rest',
            'name' => 'Feed',
            'config' => ['url' => 'https://feed.example.test/items'],
            'credentials' => ['auth_type' => 'bearer', 'token' => 'secret-value'],
            'is_active' => true,
            'created_by' => $this->actor->id,
        ]);

        $this->put(route('admin.connectors.update', $connector->id), [
            'name' => 'Feed renamed',
            'config' => ['url' => 'https://feed.example.test/items'],
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('secret-value', $connector->fresh()->credentials['token']);
    }

    #[Test]
    public function the_connector_screen_never_sends_credentials_to_the_browser(): void
    {
        $connector = Connector::create([
            'organization_id' => $this->organization->id,
            'type' => 'rest',
            'name' => 'Feed',
            'config' => ['url' => 'https://feed.example.test/items'],
            'credentials' => ['auth_type' => 'bearer', 'token' => 'secret-value'],
            'is_active' => true,
            'created_by' => $this->actor->id,
        ]);

        $this->get(route('admin.connectors.show', $connector->id))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Connectors/Show')
                ->where('connector.has_credentials', true)
                ->where('connector', fn ($c) => ! array_key_exists('credentials', (array) $c))
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Jobs */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function without_admin_queues_you_see_only_the_jobs_you_started(): void
    {
        // A job label names the record it is about — "Board pack, Q2 2026" — so
        // a full list would be a list of what everybody is working on.
        $other = User::create([
            'name' => 'Somebody else',
            'email' => 'somebody-else@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $other->assignRole('risk-analyst');
        $other->givePermissionTo('job.view');

        JobRun::create([
            'organization_id' => $this->organization->id,
            'job_class' => 'App\\Jobs\\Whatever',
            'label' => 'Board pack, Q2 2026',
            'status' => 'completed',
            'created_by' => $this->actor->id,
        ]);

        $this->actingAs($other)->get(route('admin.jobs.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Jobs/Index')
                ->where('canSeeAll', false)
                ->has('runs.data', 0)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function another_institutions_integrations_are_not_reachable(): void
    {
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB9',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $theirWebhook = WebhookSubscription::withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'name' => 'Theirs',
            'url' => 'https://theirs.example.test/hook',
            'secret' => 'x',
            'events' => ['risk.created'],
            'is_active' => true,
        ]);

        $theirConnector = Connector::withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'type' => 'rest',
            'name' => 'Theirs',
            'config' => ['url' => 'https://theirs.example.test/items'],
            'is_active' => true,
        ]);

        // A subscription is a standing instruction to send an organisation's
        // data to a URL; a connector holds credentials to somebody else's
        // system. Neither is another institution's business.
        $this->get(route('admin.webhooks.deliveries', $theirWebhook->id))->assertNotFound();
        $this->delete(route('admin.webhooks.destroy', $theirWebhook->id))->assertNotFound();
        $this->get(route('admin.connectors.show', $theirConnector->id))->assertNotFound();
    }
}
