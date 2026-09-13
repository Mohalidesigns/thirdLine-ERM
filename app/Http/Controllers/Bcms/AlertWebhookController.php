<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Services\Bcms\Emns\InboundResponseHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What providers and people post back: delivery receipts, and replies.
 *
 * UNAUTHENTICATED BY NECESSITY. A gateway posting a delivery receipt has no
 * session and never will, and neither does a person replying "SAFE" from a
 * feature phone. What stands in place of a login is a per-provider shared
 * secret compared with `hash_equals`, a rate limit, and the rule that the body
 * may never name a recipient — a reply carries a token this system minted and
 * a receipt carries a message id this system stored.
 *
 * THE TWO ROUTES DO NOT TREAT AN UNCONFIGURED SECRET THE SAME WAY, AND THAT IS
 * DELIBERATE, NOT AN OVERSIGHT.
 *
 * `status()` accepts an unsigned callback when no secret is configured for
 * that provider. Some Nigerian aggregators do not sign callbacks at all, and
 * the worst an unsigned status callback can do is advance a delivery row this
 * system already created, using a provider message id this system already
 * stored, one step forward through queued -> sent -> delivered -> read. It
 * cannot invent a row, cannot name a recipient, and `handleStatusReceipt`
 * refuses to walk the record backwards. Refusing an unsigned status callback
 * outright would mean a customer on a non-signing aggregator gets no delivery
 * evidence at all, which is worse than accepting a callback whose only power
 * is to confirm what this system already believes happened.
 *
 * `reply()` REFUSES AN UNSIGNED CALLBACK OUTRIGHT, EVEN WHEN NO SECRET IS
 * CONFIGURED. A reply can mark a person's roll-call status, including SAFE —
 * "a false safe stops a search" (`InboundResponseHandler`). Before Phase 7
 * hardened this route it had no signature check of any kind: a bare
 * `POST /bcms/alert-reply {"from":"<any number>","body":"safe"}` could reach
 * every tenant's open recipients (the platform cannot resolve a tenant before
 * a reply is matched, so `OrganizationScope` is guaranteed inert here — see
 * `InboundResponseHandler`), and if the last nine digits of `from` matched
 * exactly one open recipient anywhere in the product, that person was marked
 * safe with no credential, no token and no tenant boundary at all. Until a
 * provider's signing scheme is configured for a given route segment, `reply()`
 * for that provider is refused with 403 rather than accepted — a missed
 * acknowledgement can be retried by phone or entered by an operator by hand; a
 * false SAFE during a live evacuation cannot be taken back. If an aggregator
 * genuinely cannot sign, the answer is not to accept its callbacks unsigned —
 * it is an allowlisted source IP, mutual TLS, or a per-provider secret placed
 * in the URL path instead of a header, decided when that aggregator's contract
 * is actually signed. No channel is live yet, so this is the cheapest possible
 * moment to hold that line rather than default it away.
 *
 * IT ALWAYS ANSWERS 200 FOR AN UNMATCHED MESSAGE (once past the signature
 * check). A gateway that receives an error retries, and a retried unmatched
 * delivery receipt is a loop that bills the customer. The response body says
 * whether anything matched; the status code says only that we received it.
 */
class AlertWebhookController extends Controller
{
    /**
     * A signed callback older than this is refused, not merely logged. Gate 2
     * defect 1: nothing in the original signed material could ever expire, so
     * a captured request — replayed by whoever intercepted it, or replayed by
     * the aggregator itself retrying a call it believes failed — stayed valid
     * indefinitely. Five minutes is generous for clock skew between here and a
     * Nigerian aggregator and still short enough that a captured request is
     * useless by the time anyone could do anything with it.
     */
    private const SIGNATURE_WINDOW_SECONDS = 300;

    public function __construct(private InboundResponseHandler $handler) {}

    /**
     * A person replying by SMS, WhatsApp or USSD.
     *
     * `{provider}` names which gateway is posting, the same way `status()`
     * already does, so each aggregator gets its own URL and its own secret.
     * Signature verification is mandatory here: see the class docblock for
     * why this route fails closed when a secret is not configured, unlike
     * `status()`.
     */
    public function reply(Request $request, string $provider): JsonResponse
    {
        if (! $this->signatureOk($request, $provider, failClosedWhenUnconfigured: true)) {
            return response()->json(['error' => 'Signature mismatch.'], 403);
        }

        /*
         * `$request->validate()` validates `$this->all()`, which is the
         * parsed body UNIONED WITH THE QUERY STRING — but the signature in
         * `signatureOk()` covers the body alone. A signed
         * `{"body":"received"}` plus an unsigned `?from=<any number>` passed
         * `validate()` and handed `handleReply()` an attacker-chosen `from`
         * with a genuine signature on record. `signedBody()` (below) reads the
         * body explicitly by content type instead of through `$request->all()`
         * or an assumed `json()`; `signatureOk()` additionally refuses any
         * request carrying a query string at all, so this is belt and braces
         * rather than the only guard. See `signedBody()` for GATE 2 ADVISORY
         * 10 — why JSON cannot be assumed here.
         */
        $data = validator($this->signedBody($request), [
            'from' => ['nullable', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1000'],
            'channel' => ['nullable', 'string', 'max:20'],
        ])->validate();

        $recipient = $this->handler->handleReply(
            $data['body'],
            $data['from'] ?? null,
            $data['channel'] ?? 'sms',
        );

        if ($recipient === null) {
            return response()->json([
                'matched' => false,
                'note' => 'No live alert recipient matched this reply.',
            ]);
        }

        return response()->json([
            'matched' => true,
            'response' => $recipient->response_value,
            'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(),
        ]);
    }

    /**
     * A gateway reporting what happened to a message.
     *
     * Accepts an unsigned callback when no secret is configured for
     * `$provider` — see the class docblock for why that is defensible here
     * and is not extended to `reply()`.
     */
    public function status(Request $request, string $provider): JsonResponse
    {
        if (! $this->signatureOk($request, $provider, failClosedWhenUnconfigured: false)) {
            return response()->json(['error' => 'Signature mismatch.'], 403);
        }

        // Body only, same reasoning as `reply()` above — the signature never
        // covered the query string, so the payload handed downstream must not
        // either. Same GATE 2 ADVISORY 10 content-type handling as `reply()`:
        // read via `signedBody()` rather than assuming JSON.
        $delivery = $this->handler->handleStatusReceipt($provider, $this->signedBody($request));

        return response()->json([
            'matched' => $delivery !== null,
            'status' => $delivery?->status->value,
        ]);
    }

    /**
     * The signed body, read by the bag that matches how it actually arrived.
     *
     * GATE 2 ADVISORY 10. Both routes used to read `$request->json()->all()`
     * unconditionally. Twilio, Africa's Talking and most Nigerian SMS/USSD
     * aggregators post `application/x-www-form-urlencoded`, not JSON. The
     * signature still verifies for a form-encoded callback — `signatureOk()`
     * signs `getContent()`, the raw body, regardless of content type — so the
     * request would pass the signature check and then fail `required:body`
     * on an empty array, reading as a signing problem when it is a parsing
     * one. No channel is live yet, so nothing has hit this; the first
     * aggregator contract is the wrong moment to find out.
     *
     * Widening to `$request->all()` is not the fix — that is the exact
     * query-string admission GATE 2 DEFECT 1 (below) closed, since `all()` is
     * the parsed body unioned with the query string. Instead the body is read
     * from the bag Symfony already parsed it into for that content type:
     * `$request->request` for form-encoded (the parsed POST-body bag, which
     * never contains query parameters — those live only in `$request->query`
     * and are never consulted here), `$request->json()` otherwise. Both
     * routes go through this one method so the two content types cannot
     * silently drift into different rules.
     *
     * @return array<string, mixed>
     */
    private function signedBody(Request $request): array
    {
        $contentType = (string) $request->headers->get('Content-Type', '');

        $isFormEncoded = str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data');

        return $isFormEncoded ? $request->request->all() : $request->json()->all();
    }

    /**
     * Compared in constant time so the secret cannot be recovered a character
     * at a time.
     *
     * GATE 2 DEFECT 1, THREE FIXES IN ONE GATE:
     *
     * 1. `$provider` MUST NAME A REAL GATEWAY. Previously an unrecognised
     *    segment fell through to "no secret configured for this provider",
     *    which `status()` then treats as an unsigned-but-acceptable callback.
     *    That let a caller pick any string at all for `{provider}` and land
     *    on the permissive branch. Checked against
     *    `config('bcms-gateways.webhook_secrets')`'s keys — the same list
     *    `handleStatusReceipt`'s new provider-bound query trusts — before
     *    either branch runs, so an unknown provider is refused regardless of
     *    which route called this.
     *
     * 2. NO QUERY STRING, EVER, ON EITHER ROUTE. The signature was computed
     *    over `$request->getContent()` — the body alone — while the caller
     *    read `$request->all()`, which is the body UNIONED WITH THE QUERY
     *    STRING. A signed `{"body":"received"}` plus an unsigned
     *    `?from=<any number>` therefore carried a valid signature into a
     *    handler that trusted `from`. Rather than fold the query string into
     *    the signed material (and inherit every ambiguity about
     *    canonicalisation and proxy rewriting that comes with signing a URI),
     *    a request carrying one on these two routes is refused outright — a
     *    legitimate gateway callback here has never needed one.
     *
     * 3. THE TIMESTAMP IS PART OF THE SIGNED MATERIAL, NOT A HEADER READ AND
     *    IGNORED. Nothing previously signed could expire, so a captured
     *    request — sniffed, logged by a misconfigured proxy, or simply
     *    replayed by an aggregator retrying a call it believes failed —
     *    stayed valid forever. Binding the timestamp into the HMAC means a
     *    caller cannot bump the header without invalidating the signature,
     *    and `SIGNATURE_WINDOW_SECONDS` bounds how long a captured request
     *    stays useful.
     *
     * @param  bool  $failClosedWhenUnconfigured  What happens when
     *                                            `config('bcms-gateways.webhook_secrets.'.$provider)` is empty.
     *                                            `status()` passes `false` (accept — see class docblock).
     *                                            `reply()` passes `true` (refuse — see class docblock). This is the
     *                                            one branch point between the two routes' trust models; it is a
     *                                            parameter rather than two copies of this method so they cannot
     *                                            silently drift apart.
     */
    private function signatureOk(Request $request, string $provider, bool $failClosedWhenUnconfigured): bool
    {
        $secrets = config('bcms-gateways.webhook_secrets', []);

        if (! is_array($secrets) || ! array_key_exists($provider, $secrets)) {
            return false;
        }

        // Never on these two routes — see reasoning (2) above. Checked before
        // the secret-configured branch so it applies even to a provider that
        // has no secret yet.
        if ($request->getQueryString() !== null && $request->getQueryString() !== '') {
            return false;
        }

        $secret = $secrets[$provider];

        if (! is_string($secret) || $secret === '') {
            return ! $failClosedWhenUnconfigured;
        }

        $presented = (string) ($request->header('X-BCMS-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? '');

        if ($presented === '') {
            return false;
        }

        $timestamp = $request->header('X-BCMS-Timestamp');

        if (! is_string($timestamp) || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::SIGNATURE_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $presented)
            || hash_equals('sha256='.$expected, $presented);
    }
}
