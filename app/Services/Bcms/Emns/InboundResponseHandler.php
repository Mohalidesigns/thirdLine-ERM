<?php

namespace App\Services\Bcms\Emns;

use App\Enums\Bcms\DeliveryStatus;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\NotificationDelivery;
use Illuminate\Support\Carbon;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * What comes back: acknowledgements from people, and delivery receipts from
 * gateways.
 *
 * TWO VERY DIFFERENT INBOUND FLOWS, ONE FILE, because they arrive at the same
 * kind of endpoint and share the one rule that matters — never trust the
 * payload to say who it is about. A reply carries a token this system minted;
 * a delivery receipt carries a provider message id this system stored. Neither
 * is allowed to name a recipient directly, because a webhook is an
 * unauthenticated endpoint on the public internet and a body that could say
 * "recipient 4192 is safe" is a body that can mark a whole branch safe.
 *
 * A REPLY IS READ GENEROUSLY. People under stress send "safe", "im ok", "OK
 * 3f2a", "YES", "1". A parser that demanded an exact keyword would record a
 * person as unaccounted for while they stood outside holding a phone that had
 * already told us they were fine. The matching is deliberately loose in the
 * direction of counting somebody as SAFE only on words that clearly mean it,
 * and never inferring "needs help" from ambiguity — a false "safe" stops a
 * search, so that one is strict and everything unclear becomes a plain
 * acknowledgement with the text kept verbatim for a human to read.
 *
 * AN UNMATCHED WEBHOOK IS A 200. A gateway that receives an error retries, and
 * a retried unmatched message is a loop that costs money.
 */
class InboundResponseHandler
{
    /**
     * Words that mean "I am out and unhurt", per language. Deliberately narrow.
     *
     * @var list<string>
     */
    private const SAFE_WORDS = [
        'safe', 'ok', 'okay', 'yes', 'y', 'im safe', 'i am safe', 'am safe', 'all good',
        'lafiya',                                  // Hausa
        'alafia', 'mo wa ni alafia',               // Yoruba
        'adi mma', 'o di mma',                     // Igbo
        'i dey alright', 'i dey okay', 'i dey fine', // Nigerian Pidgin
    ];

    /**
     * Words that mean "come and get me". Erring towards catching these.
     *
     * @var list<string>
     */
    private const HELP_WORDS = [
        'help', 'need help', 'sos', 'trapped', 'injured', 'hurt', 'stuck', 'emergency',
        'taimako',                                 // Hausa
        'egbami', 'e gba mi',                      // Yoruba
        'nyere m aka', 'enyemaka',                 // Igbo
        'i need help', 'abeg help', 'i no fit comot', // Nigerian Pidgin
    ];

    /** @var list<string> */
    private const OFF_SITE_WORDS = ['not on site', 'off site', 'offsite', 'not here', 'on leave', 'away'];

    /**
     * The USSD keypad menu. THESE ARE MATCHED ONLY AS THE WHOLE MESSAGE.
     *
     * A single digit compared with `str_contains` is a live hazard, not a
     * theoretical one: the acknowledgement token is sixteen hex characters and
     * roughly two in three contain a "3", so a person replying "SAFE 8a3f…"
     * was being recorded as NOT ON SITE — a false negative in a roll-call,
     * which is the direction that gets somebody left in a building. Found by a
     * test on the real token format rather than on a tidy fixture.
     *
     * @var array<int|string, string>
     */
    private const MENU_CODES = [
        '1' => RollCallService::SAFE,
        '2' => RollCallService::NEEDS_HELP,
        '3' => RollCallService::NOT_ON_SITE,
    ];

    public function __construct(
        private RollCallService $rollCall,
        private AlertDispatcher $dispatcher,
    ) {}

    /**
     * A person replied. Returns the recipient row, or null if nothing matched.
     */
    public function handleReply(string $body, ?string $from = null, ?string $via = null): ?AlertRecipient
    {
        $token = $this->extractToken($body);
        $recipient = $token === null ? null : $this->recipientForToken($token);

        // Falling back to the sender's number, because plenty of people reply
        // "safe" without the code. It is only safe to do this when exactly one
        // live alert is waiting on that number — otherwise the answer could
        // settle the wrong alert.
        if ($recipient === null && $from !== null) {
            $recipient = $this->recipientForNumber($from);
        }

        if ($recipient === null) {
            return null;
        }

        TenantContext::set((int) $recipient->organization_id);

        return $this->rollCall->record(
            $recipient,
            $this->interpret($body),
            trim($body),
            $via,
        );
    }

    /**
     * Classify a free-text reply.
     *
     * Null means "they answered, but not with a status" — which is a real and
     * common outcome, and better than guessing.
     */
    public function interpret(string $body): ?string
    {
        // The token is stripped FIRST. It is sixteen hex characters of noise
        // that will otherwise be matched against every keyword below.
        $body = preg_replace('/\b[0-9a-f]{16}\b/i', ' ', $body) ?? $body;

        $normalised = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $body) ?? $body));
        $normalised = trim(preg_replace('/\s+/', ' ', $normalised) ?? $normalised);

        if ($normalised === '') {
            return null;
        }

        // A keypad reply is the WHOLE message and nothing else.
        if (array_key_exists($normalised, self::MENU_CODES)) {
            return self::MENU_CODES[$normalised];
        }

        // HELP IS CHECKED FIRST AND ON A SUBSTRING. "I am safe but Musa needs
        // help" must never be filed as safe; a false safe stops somebody
        // looking, and that asymmetry is the whole reason for the ordering.
        foreach (self::HELP_WORDS as $word) {
            if (str_contains($normalised, $word)) {
                return RollCallService::NEEDS_HELP;
            }
        }

        foreach (self::OFF_SITE_WORDS as $word) {
            if (str_contains($normalised, $word)) {
                return RollCallService::NOT_ON_SITE;
            }
        }

        // Safe is matched on WHOLE WORDS or the whole message only. A substring
        // match would read "ok" inside "broken" and mark an injured person safe.
        $words = explode(' ', $normalised);

        foreach (self::SAFE_WORDS as $phrase) {
            if ($normalised === $phrase || in_array($phrase, $words, true)) {
                return RollCallService::SAFE;
            }
        }

        return null;
    }

    /**
     * A gateway reporting what happened to a message it accepted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleStatusReceipt(string $provider, array $payload): ?NotificationDelivery
    {
        $messageId = $this->firstString($payload, ['message_id', 'messageId', 'id', 'MessageSid', 'session_id']);
        $status = $this->firstString($payload, ['status', 'state', 'MessageStatus', 'event']);

        if ($messageId === null) {
            return null;
        }

        $delivery = NotificationDelivery::query()
            ->where('provider_message_id', $messageId)
            ->first();

        if ($delivery === null) {
            return null;
        }

        TenantContext::set((int) $delivery->organization_id);

        $mapped = $this->mapStatus($status);

        if ($mapped === null) {
            return $delivery;
        }

        // A DELIVERY ONLY EVER MOVES FORWARD. Gateways deliver webhooks out of
        // order, and a late "sent" arriving after "delivered" must not walk the
        // record backwards — the audit is evidence, and evidence that moves
        // backwards is evidence nobody can rely on.
        if ($this->rank($mapped) <= $this->rank($delivery->status)) {
            return $delivery;
        }

        $delivery->forceFill(array_filter([
            'status' => $mapped->value,
            'delivered_at' => $mapped === DeliveryStatus::Delivered ? now() : $delivery->delivered_at,
            'read_at' => $mapped === DeliveryStatus::Read ? now() : $delivery->read_at,
            'failed_reason' => $mapped === DeliveryStatus::Failed
                ? ($this->firstString($payload, ['reason', 'error', 'description']) ?? 'Reported failed by the gateway.')
                : $delivery->failed_reason,
        ], fn ($v) => $v !== null))->save();

        return $delivery->refresh();
    }

    private function mapStatus(?string $status): ?DeliveryStatus
    {
        $status = mb_strtolower((string) $status);

        return match (true) {
            $status === '' => null,
            str_contains($status, 'read'), str_contains($status, 'seen') => DeliveryStatus::Read,
            str_contains($status, 'deliver') => DeliveryStatus::Delivered,
            str_contains($status, 'fail'), str_contains($status, 'reject'),
            str_contains($status, 'undeliver'), str_contains($status, 'expired') => DeliveryStatus::Failed,
            str_contains($status, 'sent'), str_contains($status, 'accept') => DeliveryStatus::Sent,
            default => null,
        };
    }

    private function rank(DeliveryStatus $status): int
    {
        return match ($status) {
            DeliveryStatus::Queued => 0,
            DeliveryStatus::Sent => 1,
            DeliveryStatus::Delivered => 2,
            DeliveryStatus::Read => 3,
            // Terminal, and above `sent` so a genuine failure can overwrite an
            // optimistic accept — but below `delivered`, because a message that
            // demonstrably arrived did not fail.
            DeliveryStatus::Failed, DeliveryStatus::Expired => 2,
        };
    }

    public function extractToken(string $body): ?string
    {
        return preg_match('/\b([0-9a-f]{16})\b/i', $body, $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * Scoped to alerts still awaiting answers, which bounds the scan and makes
     * "no longer live" a real answer rather than a lookup failure.
     */
    private function recipientForToken(string $token): ?AlertRecipient
    {
        foreach ($this->openRecipients()->cursor() as $candidate) {
            if (hash_equals($this->dispatcher->tokenFor($candidate), $token)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Match a bare reply to a number — only when it is unambiguous.
     */
    private function recipientForNumber(string $from): ?AlertRecipient
    {
        $digits = preg_replace('/\D/', '', $from) ?? $from;

        if (mb_strlen($digits) < 7) {
            return null;
        }

        $tail = mb_substr($digits, -9);

        $matches = $this->openRecipients()
            ->whereHas('contact', fn ($q) => $q->where('mobile_primary', 'like', '%'.$tail)
                ->orWhere('mobile_secondary', 'like', '%'.$tail))
            ->limit(2)
            ->get();

        // Two live alerts waiting on the same person: a bare "safe" cannot say
        // which. Recorded as unmatched rather than guessed — settling the wrong
        // alert would tell a crisis manager somebody is out of a building they
        // are still in.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<AlertRecipient> */
    private function openRecipients()
    {
        return AlertRecipient::query()
            ->whereNull('acknowledged_at')
            ->whereHas('alert', fn ($q) => $q
                ->whereNotNull('dispatched_at')
                ->where('dispatched_at', '>=', Carbon::now()->subDays(2)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
