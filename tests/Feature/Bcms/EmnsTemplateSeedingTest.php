<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\ContactSource;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Presenters\Bcms\EmnsPresenter;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\TemplateRenderer;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The two shipped Nigerian Pidgin templates (`EVACUATE`/pcm and
 * `ROLLCALL`/pcm) go out INACTIVE, pending a native-speaker review — same
 * reasoning `AlertTemplateController`'s docblock already gives for every
 * non-English row, just not previously applied to the two Phase 0 shipped
 * ("Phase 0 ships English and Nigerian Pidgin for the life-safety templates")
 * as though they had already been reviewed.
 *
 * `BcmsReferenceSeeder::seedAlertTemplates()`'s OWN promise
 * ("idempotent by natural key... adds the new rows without duplicating or
 * resetting the old ones") used to not cover `is_active`: every re-seed set
 * it to `true` unconditionally, silently re-activating a Pidgin template an
 * admin had deliberately withdrawn, and just as silently re-activating an
 * unreviewed one nobody had touched. This is now a create-time default only.
 */
class EmnsTemplateSeedingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS',
            'name' => 'Operations', 'is_active' => true,
        ]);

        $this->operator = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Crisis Manager',
            'email' => 'crisis@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_shipped_pidgin_templates_seed_inactive(): void
    {
        $evacuatePcm = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'pcm')->firstOrFail();
        $rollcallPcm = AlertTemplate::query()->where('code', 'ROLLCALL')->where('locale', 'pcm')->firstOrFail();

        $this->assertFalse((bool) $evacuatePcm->is_active, 'EVACUATE/pcm must ship inactive, pending review.');
        $this->assertFalse((bool) $rollcallPcm->is_active, 'ROLLCALL/pcm must ship inactive, pending review.');

        // The English siblings are unaffected — only Pidgin is held back.
        $evacuateEn = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();
        $this->assertTrue((bool) $evacuateEn->is_active);
    }

    #[Test]
    public function reseeding_does_not_flip_an_admins_activation_either_way(): void
    {
        // An admin reviews the Pidgin wording and switches it on.
        $evacuatePcm = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'pcm')->firstOrFail();
        $evacuatePcm->update(['is_active' => true]);

        // An admin, separately, withdraws an English template that shipped
        // active — the opposite direction, so this proves "create-time
        // default only" rather than "always force true".
        $itOutageEn = AlertTemplate::query()->where('code', 'ITOUTAGE')->where('locale', 'en')->firstOrFail();
        $itOutageEn->update(['is_active' => false]);

        $this->seed(BcmsReferenceSeeder::class);

        $this->assertTrue(
            (bool) $evacuatePcm->refresh()->is_active,
            'Re-seeding must not silently re-deactivate a template an admin reviewed and switched on.',
        );
        $this->assertFalse(
            (bool) $itOutageEn->refresh()->is_active,
            'Re-seeding must not silently re-activate a template an admin deliberately withdrew.',
        );

        // Content is still refreshed on every run — only `is_active` is
        // pinned. `updateOrCreate`'s original promise, still kept.
        $this->assertSame('IT service outage', $itOutageEn->name);
    }

    #[Test]
    public function an_inactive_template_is_excluded_from_the_compose_picker(): void
    {
        $crisisConvene = AlertTemplate::query()->where('code', 'CRISISCONVENE')->where('locale', 'en')->firstOrFail();

        $before = app(EmnsPresenter::class)->console($this->operator);
        $this->assertContains('CRISISCONVENE', array_column($before['templates'], 'code'));

        $crisisConvene->update(['is_active' => false]);

        $after = app(EmnsPresenter::class)->console($this->operator);
        $this->assertNotContains(
            'CRISISCONVENE',
            array_column($after['templates'], 'code'),
            'A deactivated template must disappear from the compose picker immediately.',
        );

        // The Pidgin rows were never offered in the first place — the
        // picker only ever lists English cards (per-recipient locale is
        // resolved at render time), so this is the same rule seen from the
        // other side: they were already excluded before this fix.
        $this->assertNotContains('pcm', array_column($before['templates'], 'locale') ?: []);
    }

    #[Test]
    public function an_inactive_template_cannot_be_composed(): void
    {
        $evacuatePcm = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'pcm')->firstOrFail();
        $this->assertFalse((bool) $evacuatePcm->is_active);

        // NEUTRAL WORDING (item 9): never "awaiting review" — a row an admin
        // deliberately withdrew was never awaiting anything, and this same
        // message covers both cases.
        $this->assertThrows(
            fn () => app(AlertService::class)->compose([
                'organization_id' => $this->organization->id,
                'title' => 'Comot now',
                'message' => 'x',
                'template_id' => $evacuatePcm->getKey(),
                'severity' => $evacuatePcm->severity->value,
                'channels' => ['sms'],
                'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
            ], $this->operator->id),
            InvalidArgumentException::class,
            'This template is not active.',
        );

        $this->assertSame(0, Alert::query()->count(), 'Nothing composed means nothing to release either.');
    }

    /**
     * ITEM 9, PERMANENT REGRESSION TEST. Compose-time refusal only stops an
     * operator picking an inactive template directly; it does nothing about
     * one deactivated AFTER an alert already exists in draft.
     * `TemplateRenderer::templateFor()` used to fall back to the very
     * (inactive) template it had just rejected as a candidate (`?? $template`),
     * so a withdrawn template kept rendering right up until somebody
     * re-composed the alert. This proves the render-time half: a template
     * that was ACTIVE at compose and is deactivated before release must
     * refuse at release too, with the same neutral message.
     */
    #[Test]
    public function a_template_deactivated_after_compose_is_refused_at_release(): void
    {
        $this->contact('Amina');

        $rollcall = AlertTemplate::query()->where('code', 'ROLLCALL')->where('locale', 'en')->firstOrFail();
        $this->assertTrue((bool) $rollcall->is_active, 'Sanity: active at compose time.');

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Are you safe?',
            'message' => 'x',
            'template_id' => $rollcall->getKey(),
            'severity' => $rollcall->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ], $this->operator->id);

        // Withdrawn between compose and release — the exact gap item 9 closes.
        $rollcall->update(['is_active' => false]);

        // ROLLCALL is life-safety severity and trips dual approval on its
        // own; clear it so the refusal under test is the inactive-template
        // one, not the unrelated approval gate.
        $second = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Second Authoriser',
            'email' => 'second-deactivated@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        app(AlertService::class)->approve($alert, $this->operator);
        $alert = app(AlertService::class)->approve($alert->refresh(), $second);

        $this->assertThrows(
            fn () => app(AlertService::class)->release($alert, $this->operator->id),
            InvalidArgumentException::class,
            'This template is not active',
        );

        $this->assertSame('approved', $alert->refresh()->status, 'A refused release must not move the alert forward.');
        $this->assertSame(0, AlertRecipient::query()->where('alert_id', $alert->getKey())->count());
    }

    #[Test]
    public function the_store_route_refuses_an_inactive_template_with_a_named_validation_error(): void
    {
        $this->actingAs($this->operator);
        $this->operator->givePermissionTo(Permission::findOrCreate('bcms.alert.compose', 'web'));

        $evacuatePcm = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'pcm')->firstOrFail();

        $response = $this->post(route('bcms.alerts.store'), [
            'title' => 'Comot now',
            'template_id' => $evacuatePcm->getKey(),
            'severity' => $evacuatePcm->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'org_node', 'id' => $this->unit->id, 'include_descendants' => true],
        ]);

        $response->assertSessionHasErrors('template_id');
        $this->assertSame(0, Alert::query()->count());
    }

    #[Test]
    public function a_pidgin_preferring_recipient_falls_back_to_english_while_pidgin_is_inactive(): void
    {
        $site = \App\Models\Bcms\Site::create([
            'organization_id' => $this->organization->id, 'code' => 'HQ', 'name' => 'Lagos HQ',
        ]);

        $evacuateEn = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'en')->firstOrFail();
        $evacuatePcm = AlertTemplate::query()->where('code', 'EVACUATE')->where('locale', 'pcm')->firstOrFail();
        $this->assertFalse((bool) $evacuatePcm->is_active, 'Sanity: the fallback under test is real, not vacuous.');

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Evacuate now',
            'message' => 'x',
            'template_id' => $evacuateEn->getKey(),
            'severity' => $evacuateEn->severity->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'site', 'ids' => [$site->id]],
        ], $this->operator->id);

        $renderer = app(TemplateRenderer::class);

        // A recipient who prefers Pidgin does not go unreached, and does not
        // receive a half-reviewed translation either — they get the English
        // wording, and the rendering records that the fallback happened.
        $resolved = $renderer->templateFor($alert, 'pcm');
        $this->assertSame('en', $resolved->locale, 'Falls back to English while Pidgin is inactive.');

        $message = $renderer->render($alert, ChannelKey::Sms, 'pcm', ['assembly_point' => 'Car Park B']);

        $this->assertSame('en', $message->locale);
        $this->assertTrue($message->metadata['locale_fell_back']);
        $this->assertStringContainsString('EVACUATE Lagos HQ NOW', $message->body);
        $this->assertStringNotContainsString('Comot', $message->body, 'Must not be the withheld Pidgin wording.');
        $this->assertStringNotContainsString('{{', $message->body);
    }

    private function contact(string $name): Contact
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'organization_id' => $this->organization->id, 'name' => $name,
            'email' => 'p'.$n.'@khb.test', 'password' => bcrypt('secret'), 'is_active' => false,
        ]);

        return Contact::query()->create([
            'organization_id' => $this->organization->id, 'user_id' => $user->id,
            'source' => ContactSource::Manual->value, 'full_name' => $name,
            'employee_id' => 'E-'.$n, 'business_unit_id' => $this->unit->id,
            'email' => 'contact'.$n.'@khb.test',
            'mobile_primary' => '+2348000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'pcm', 'consent_status' => 'granted',
            'verification_status' => 'verified', 'last_verified_at' => now(), 'is_active' => true,
        ]);
    }
}
