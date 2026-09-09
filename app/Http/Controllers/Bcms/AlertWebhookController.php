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
 * session and never will. What stands in place of a login is: a per-provider
 * shared secret compared with `hash_equals`, a rate limit, and the rule that
 * the body may never name a recipient — a reply carries a token this system
 * minted and a receipt carries a message id this system stored. A payload that
 * could say "recipient 4192 is safe" is a payload that can mark a whole branch
 * safe from the public internet.
 *
 * IT ALWAYS ANSWERS 200 FOR AN UNMATCHED MESSAGE. A gateway that receives an
 * error retries, and a retried unmatched delivery receipt is a loop that bills
 * the customer. The response body says whether anything matched; the status
 * code says only that we received it.
 *
 * THE SECRET IS OPTIONAL AND ITS ABSENCE IS LOGGED, NOT FATAL. Some Nigerian
 * aggregators do not sign callbacks at all. Refusing those outright would mean
 * a customer on that provider gets no delivery receipts and therefore no
 * evidence, which is worse than accepting an unsigned callback whose only power
 * is to advance a delivery row it can already identify.
 */
class AlertWebhookController extends Controller
{
    public function __construct(private InboundResponseHandler $handler) {}

    /** A person replying by SMS, WhatsApp or USSD. */
    public function reply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1000'],
            'channel' => ['nullable', 'string', 'max:20'],
        ]);

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

    /** A gateway reporting what happened to a message. */
    public function status(Request $request, string $provider): JsonResponse
    {
        if (! $this->signatureOk($request, $provider)) {
            return response()->json(['error' => 'Signature mismatch.'], 403);
        }

        $delivery = $this->handler->handleStatusReceipt($provider, $request->all());

        return response()->json([
            'matched' => $delivery !== null,
            'status' => $delivery?->status->value,
        ]);
    }

    /**
     * Compared in constant time so the secret cannot be recovered a character
     * at a time.
     */
    private function signatureOk(Request $request, string $provider): bool
    {
        $secret = config('bcms-gateways.webhook_secrets.'.$provider);

        if (! is_string($secret) || $secret === '') {
            return true;
        }

        $presented = (string) ($request->header('X-BCMS-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->query('signature', ''));

        if ($presented === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $presented)
            || hash_equals('sha256='.$expected, $presented);
    }
}
