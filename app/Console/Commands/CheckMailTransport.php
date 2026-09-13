<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prove the mail transport before a vendor discovers it is broken.
 *
 * THE PORTAL CANNOT BE SIGNED INTO WITHOUT WORKING MAIL. MFA is mandatory by
 * construction — `PortalAuthService` creates a guard session only inside
 * `completeMfa()` — and the second factor is an emailed code. So a mail
 * transport that is misconfigured, blocked or rejecting credentials is not a
 * degraded feature, it is a closed front door, and it fails at the moment a
 * vendor is trying to get in rather than at deploy time.
 *
 * `--send` IS OPT-IN AND NAMES ITS RECIPIENT. Connecting to a mail server is
 * a diagnostic; putting a message in somebody's inbox is not, and a command
 * that quietly emailed the configured from-address on every run would be a
 * command nobody could safely put in a deploy script.
 *
 * The command NEVER PRINTS THE PASSWORD, and prints the username only masked.
 * Deploy logs are read by more people than the credential is issued to.
 */
class CheckMailTransport extends Command
{
    protected $signature = 'mail:check {--send= : Send a real test message to this address}';

    protected $description = 'Check the configured mail transport, and optionally send a test message.';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $config = (array) config('mail.mailers.'.$mailer, []);

        $this->line('Mailer:    '.$mailer);

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER is `log`. Mail is written to the log and NOBODY RECEIVES IT.');
            $this->warn('The vendor portal cannot be signed into in this configuration: the second factor');
            $this->warn('is an emailed code, and no vendor will ever be sent one.');

            return self::FAILURE;
        }

        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 0);

        $this->line('Host:      '.($host ?: '(none)').':'.$port);
        /*
         * The EFFECTIVE scheme, not the configured one. Laravel 12 reads
         * `MAIL_SCHEME` and falls back to `smtps` on port 465 and `smtp`
         * otherwise (MailManager::createSmtpTransport). `MAIL_ENCRYPTION` has
         * not been read since Laravel 10 and is inert wherever it survives in
         * a .env — printing it, or printing "(none)", sends somebody hunting
         * a TLS problem that the port fallback already solved.
         */
        $scheme = $config['scheme'] ?: ($port === 465 ? 'smtps' : 'smtp');
        $this->line('Scheme:    '.$scheme.($config['scheme'] ? '' : ' (derived from the port)'));
        $this->line('Username:  '.$this->mask((string) ($config['username'] ?? '')));
        $this->line('From:      '.(string) config('mail.from.address'));

        if ($host === '' || $port === 0) {
            $this->error('No host or port configured for this mailer.');

            return self::FAILURE;
        }

        /*
         * Reachability is checked separately from authentication, because the
         * two failures have completely different owners. A blocked port is a
         * network or hosting question; a rejected credential is a mailbox
         * question. A single "could not send mail" sends somebody to the
         * wrong person.
         */
        $this->line('');
        $this->line('Opening a socket to '.$host.':'.$port.' ...');

        $socket = @fsockopen($host, $port, $errno, $errstr, 8);

        if ($socket === false) {
            $this->error('UNREACHABLE: '.$errstr.' (errno '.$errno.')');
            $this->line('');
            $this->warn('This is a network result, not a credential result. Outbound SMTP is blocked on many');
            $this->warn('home, office and cloud networks by default. Check from the host that will actually');
            $this->warn('run the application before changing any mail setting.');

            return self::FAILURE;
        }

        fclose($socket);
        $this->info('Reachable.');

        $recipient = $this->option('send');

        if (! $recipient) {
            $this->line('');
            $this->line('Transport not exercised. Re-run with --send=you@example.com to send a real message.');

            return self::SUCCESS;
        }

        $this->line('Sending a test message to '.$recipient.' ...');

        try {
            Mail::raw(
                'This is a transport test from '.config('app.name').".\n\n"
                ."If you received it, the mail configuration works and the vendor portal can send its\n"
                .'sign-in codes.',
                fn ($message) => $message->to($recipient)->subject('Mail transport test')
            );
        } catch (Throwable $exception) {
            $this->error('SEND FAILED: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent. Check the inbox — a message accepted by the server can still be rejected later.');

        return self::SUCCESS;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '(none)';
        }

        [$local, $domain] = array_pad(explode('@', $value, 2), 2, null);

        $shown = mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2));

        return $domain === null ? $shown : $shown.'@'.$domain;
    }
}
