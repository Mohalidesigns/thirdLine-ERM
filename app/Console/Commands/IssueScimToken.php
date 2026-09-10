<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ScimToken;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

class IssueScimToken extends Command
{
    protected $signature = 'scim:token
                            {organization : Organization id the token provisions into}
                            {--name=Directory sync : A label so tokens can be told apart}
                            {--days= : Expire the token after this many days}';

    protected $description = 'Issue a SCIM 2.0 bearer token for an organization';

    public function handle(): int
    {
        $organizationId = (int) $this->argument('organization');

        $organization = Organization::query()->find($organizationId);

        if (! $organization) {
            $this->components->error("No organization with id {$organizationId}.");

            return self::FAILURE;
        }

        $expiresAt = $this->option('days') ? now()->addDays((int) $this->option('days')) : null;

        [$token, $plaintext] = TenantContext::actingAs($organizationId, fn () => ScimToken::issue(
            $organizationId,
            (string) $this->option('name'),
            null,
            $expiresAt
        ));

        $this->newLine();
        $this->components->info("SCIM token issued for {$organization->name}.");
        $this->newLine();
        $this->line('  <options=bold>'.$plaintext.'</>');
        $this->newLine();
        $this->components->warn('Copy this now — only its hash is stored, so it cannot be shown again.');

        $this->table(['Field', 'Value'], [
            ['Token id', $token->id],
            ['Organization', $organization->name.' (#'.$organizationId.')'],
            ['Name', $token->name],
            ['Expires', $expiresAt?->toDateTimeString() ?? 'never'],
            ['Base URL', rtrim((string) config('app.url'), '/').'/scim/v2'],
        ]);

        if (! config('sso.scim.enabled')) {
            $this->components->warn('SCIM_ENABLED is false, so the endpoints will return 404 until it is set.');
        }

        return self::SUCCESS;
    }
}
