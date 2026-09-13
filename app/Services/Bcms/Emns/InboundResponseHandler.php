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
     * ADR 0016. `r-{decimal recipient id}-{16 hex tag}`, matched
     * case-insensitively (some gateways upcase message bodies, and a USSD
     * keypad has no case at all) and lower-cased before comparison. Matches
     * ONLY this namespace's prefix — a `c-…` cascade token posted here is
     * rejected by shape, with no lookup, rather than failing a scan.
     */
    private const TOKEN_PATTERN = '/\br-(\d{1,12})-([0-9a-f]{16})\b/i';

    /**
     * A row id that names no real recipient (ids are auto-increment from 1).
     * Used only to give the "no token" and "unknown id" paths something to
     * compute a tag against, so they cost exactly what a real lookup costs.
     */
    private const SENTINEL_RECIPIENT_ID = 0;

    /**
     * The USSD keypad menu. THESE ARE MATCHED ONLY AS THE WHOLE MESSAGE.
     *
     * CORRECTED PER GATE 2 ADVISORY 13 — a previous version of this comment
     * claimed a reply of "SAFE 8a3f…" was being misfiled as NOT ON SITE. That
     * was never true: `MENU_CODES` is matched with `array_key_exists()`
     * against the WHOLE normalised message below, never with `str_contains`,
     * so a free-text "SAFE" plus a token was never at risk of colliding with
     * the digit "3" here. The real hazard was in the token strip at the top
     * of `interpret()`: without it, a token containing a "3" survives into
     * the normalised string, "1 8a3f…" is not an exact match for any
     * `MENU_CODES` key OR any word below, and the reply falls through every
     * branch to `null` — a DROPPED acknowledgement, not a misfiled one. A
     * dropped USSD "1 <token>" reads as "never answered", not "reported not
     * on site"; both are bad, but they are not the same defect, and a fix
     * aimed at the wrong one would have left the token strip untested. See
     * the test on the real token format below rather than a tidy fixture.
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
        // The token is stripped FIRST — otherwise it is noise that gets
        // matched against every keyword below. ADR 0016 put a decimal row id
        // into the token ("r-3-…"), so a strip that removed only the trailing
        // sixteen hex characters would leave "r 3" behind and break a
        // whole-message MENU_CODES match exactly the way a bare token
        // containing a "3" once did (see the regression test on this class).
        // Both shapes are stripped: the current prefixed token, and the bare
        // sixteen-hex form for anything that still produces one.
        $body = preg_replace('/\b[rc]-\d{1,12}-[0-9a-f]{16}\b/i', ' ', $body) ?? $body;
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

        /*
         * GATE 2 DEFECT 2. `$provider` was resolved by the controller and
         * never used here — matching on `provider_message_id` ALONE. Before
         * this gate, a simulation's id was `'sim-'.$delivery->getKey()`, a
         * global auto-increment every simulated delivery in the product
         * shares, so an unauthenticated caller could walk sim-1..sim-N and
         * move ANY tenant's delivery row forward, stamping `delivered_at` or
         * `read_at` on a record `EvidenceExport` hands to a regulator. Binding
         * the query to `$provider` closes the cross-tenant path even for a
         * guessed id, because a caller would additionally have to name the
         * provider that actually sent that message — and it turns this into
         * an index-covered lookup on `(provider, provider_message_id)`
         * instead of a full scan on the second column alone, which is what
         * the migration actually indexes.
         */
        $delivery = NotificationDelivery::query()
            ->where('provider', $provider)
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

    /**
     * Returns the whole matched token (e.g. `r-48213-9f2c1ab77d0e4b31`), or
     * null when the body carries nothing of this namespace's shape — which
     * includes a `c-…` cascade token, rejected by shape rather than by a
     * failed lookup (ADR 0016 §1).
     */
    public function extractToken(string $body): ?string
    {
        return preg_match(self::TOKEN_PATTERN, $body, $m) === 1
            ? 'r-'.$m[1].'-'.strtolower($m[2])
            : null;
    }

    /**
     * ADR 0016 §1 and §3. One indexed fetch by primary key, with the
     * eligibility window applied INSIDE the query (`openRecipients()`), then
     * a tag comparison that runs unconditionally — never an early return
     * between the fetch and the compare.
     *
     * When no row matches the id, the tag is still computed and compared,
     * against a fixed sentinel id, and the result is discarded. That is what
     * makes "no such recipient", "wrong tag for a real recipient", "closed
     * alert" and "malformed token" indistinguishable in both timing and
     * response — an early `if ($row === null) return null;` here is exactly
     * the oracle this method exists to close.
     */
    private function recipientForToken(string $token): ?AlertRecipient
    {
        if (preg_match(self::TOKEN_PATTERN, $token, $m) !== 1) {
            return null;
        }

        $id = (int) $m[1];
        $presentedTag = strtolower($m[2]);

        $row = $this->openRecipients()->whereKey($id)->first();

        $expectedTag = $this->dispatcher->tagFor($row !== null ? (int) $row->getKey() : self::SENTINEL_RECIPIENT_ID);
        $tagMatches = hash_equals($expectedTag, $presentedTag);

        return $row !== null && $tagMatches ? $row : null;
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
