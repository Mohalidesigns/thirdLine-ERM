<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Issue an API token.
 *
 * Two shapes, and the difference matters:
 *
 *   --user=      a PERSONAL token. Acts as that person, and can never exceed
 *                their permissions however generous the scopes.
 *   --machine    a CLIENT CREDENTIALS token. Acts as nobody. Its scopes are the
 *                whole of its authority, which is why issuing one is a
 *                deliberate administrative act rather than something a user
 *                does for themselves.
 *
 * The alternative to the machine token — pointing a nightly connector at a
 * borrowed employee account — puts a named person's name against every
 * automated change at 3am for the next four years, and no audit trail recovers
 * from that.
 *
 * THE PLAINTEXT IS SHOWN ONCE. Only its hash is stored, so a lost token is
 * reissued rather than recovered.
 */
class IssueApiToken extends Command
{
    protected $signature = 'api:token
                            {name : What this token is for, e.g. "Nightly KRI feed"}
                            {--user= : Issue a personal token for this user id or email}
                            {--machine : Issue a client-credentials token that acts as no user}
                            {--organization= : Required with --machine}
                            {--scopes=* : Permissions this token may exercise. * grants everything its owner can do}
                            {--expires= : Days until it expires. Omit for no expiry}
                            {--rate=120 : Requests per minute}';

    protected $description = 'Issue an API token for the REST API';

    public function handle(): int
    {
        $isMachine = (bool) $this->option('machine');
        $user = $this->resolveUser();

        if (! $isMachine && $user === null) {
            $this->error('Give --user=<id|email> for a personal token, or --machine for a system one.');

            return self::FAILURE;
        }

        $organizationId = $isMachine
            ? (int) $this->option('organization')
            : $user->organization_id;

        if (! $organizationId || ! Organization::withoutGlobalScopes()->whereKey($organizationId)->exists()) {
            $this->error('A token needs a valid organization. Pass --organization=<id> with --machine.');

            return self::FAILURE;
        }

        $scopes = $this->option('scopes') ?: ['*'];

        if ($isMachine && in_array('*', $scopes, true)) {
            // A machine token has nobody behind it to narrow '*' down to, so
            // '*' really does mean everything — which is why ApiToken now
            // REFUSES it outright rather than warning about it. This branch
            // used to print a warning and offer to issue it anyway; the model
            // would now throw an InvalidArgumentException out of that
            // confirmation, giving whoever ran the command a stack trace
            // instead of an explanation. Fail here, with the fix in the text.
            $this->error('A machine token cannot hold "*".');
            $this->line('');
            $this->line('  A client_credentials token acts as nobody, so there is no user');
            $this->line('  permission behind it to narrow "*" down to — it would mean');
            $this->line('  unrestricted access to this organization\'s data, permanently.');
            $this->line('');
            $this->line('  Name the scopes it actually needs instead, for example:');
            $this->line('    --scopes=risk.view --scopes=control.view --scopes=measure.write');
            $this->line('');
            $this->line('  Permissions are finite and seeded; `php artisan permission:show` lists them.');

            return self::FAILURE;
        }

        $plain = Str::random(48);

        $token = ApiToken::create([
            'organization_id' => $organizationId,
            'tokenable_type' => $isMachine ? null : $user->getMorphClass(),
            'tokenable_id' => $isMachine ? null : $user->id,
            'name' => $this->argument('name'),
            'token_type' => $isMachine ? ApiToken::TYPE_CLIENT : ApiToken::TYPE_PERSONAL,
            'client_id' => $isMachine ? 'cid_'.Str::random(24) : null,
            'token' => hash('sha256', $plain),
            'abilities' => $scopes,
            'expires_at' => $this->option('expires') ? now()->addDays((int) $this->option('expires')) : null,
            'rate_limit_per_minute' => (int) $this->option('rate'),
        ]);

        $this->newLine();
        $this->info('Token issued.');
        $this->table(['', ''], [
            ['Name', $token->name],
            ['Type', $token->token_type],
            ['Organization', $organizationId],
            ['Acts as', $isMachine ? 'nobody (machine token)' : $user->name.' <'.$user->email.'>'],
            ['Scopes', implode(', ', $scopes)],
            ['Expires', $token->expires_at?->toDayDateTimeString() ?? 'never'],
            ['Rate limit', $token->rate_limit_per_minute.'/min'],
            ['Client id', $token->client_id ?? '—'],
        ]);

        $this->newLine();
        $this->warn('This is the only time the token is shown. Only its hash is stored.');
        $this->line('  '.$token->id.'|'.$plain);
        $this->newLine();
        $this->line('  Authorization: Bearer '.$token->id.'|'.$plain);
        $this->newLine();

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $identifier = $this->option('user');

        if (blank($identifier)) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->when(is_numeric($identifier),
                fn ($q) => $q->whereKey($identifier),
                fn ($q) => $q->where('email', $identifier),
            )
            ->first();
    }
}
