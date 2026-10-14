<?php

namespace Tests\Feature\TprmPortal;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Portal\PortalAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * NDPA: a failed portal sign-in mail must not write the vendor's address to the log.
 *
 * PREVIOUS BEHAVIOUR: `PortalAuthService::sendEmailCode()` logged
 * `'message' => $exception->getMessage()`. Mail transports build that message
 * from the recipient ("Address ops@... rejected"), and production has no SMTP
 * transport configured, so the branch fires on every sign-in.
 */
class PortalSignInMailFailureLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_failed_sign_in_mail_logs_ids_and_the_exception_class_never_the_message_or_address(): void
    {
        config()->set('features.tprm', true);

        $bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        RiskCategory::create([
            'organization_id' => $bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $user = TenantContext::actingAs($bank->id, function () use ($bank): PortalUser {
            $vendor = ThirdParty::create([
                'organization_id' => $bank->id, 'legal_name' => 'Cloudspan Nigeria Limited',
                'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
            ]);
            $user = new PortalUser([
                'organization_id' => $bank->id, 'third_party_id' => $vendor->id,
                'email' => 'ops@cloudspan.test', 'name' => 'Vendor Operator',
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ]);
            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE, 'accepted_at' => now(),
                'mfa_method' => PortalUser::METHOD_EMAIL,
            ])->save();

            return $user;
        });

        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = ['message' => (string) $event->message, 'context' => $event->context];
        });

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Address <ops@cloudspan.test> rejected by smtp.example'));

        $result = app(PortalAuthService::class)->sendEmailCode($user);

        $this->assertFalse($result['sent']);

        $entry = collect($logged)->first(fn ($e) => $e['message'] === 'Portal sign-in code could not be sent');
        $this->assertNotNull($entry, 'the failure must still be logged, or this test proves nothing');
        $this->assertSame($user->getKey(), $entry['context']['portal_user_id']);
        $this->assertSame(\RuntimeException::class, $entry['context']['exception']);
        $this->assertArrayNotHasKey('message', $entry['context']);
        $this->assertStringNotContainsString('ops@cloudspan.test', json_encode($logged, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('smtp.example', json_encode($logged, JSON_THROW_ON_ERROR));
    }
}
