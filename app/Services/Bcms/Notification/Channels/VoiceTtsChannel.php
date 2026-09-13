<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\Response;

/**
 * An automated voice call reading the alert aloud.
 *
 * IT IS THE CHANNEL THAT WAKES SOMEBODY UP. An SMS at 3am is a notification; a
 * ringing phone is an interruption, and for a life-safety cascade that
 * difference is the whole reason this channel exists. It is also offline-capable
 * (`ChannelKey::offlineCapable()`) because it needs GSM and not data.
 *
 * THE BODY IS RE-PHRASED FOR SPEECH BEFORE IT IS SENT. A message written for a
 * screen reads badly aloud: "RTO 4h" becomes "arr tee oh four aitch", a URL
 * becomes forty seconds of nonsense, and "THIS IS AN EXERCISE." delivered flat
 * at the start is the one sentence that must be unmistakable. Rendering happens
 * before the adapter (frozen interface, rule: rendering is not the adapter's
 * job) — but the speech-specific cleanup that no other channel wants is here,
 * because it is a property of speaking rather than of the message.
 */
class VoiceTtsChannel extends HttpChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct(ChannelKey::Voice, $config);
    }

    public function provider(): string
    {
        return (string) ($this->config['name'] ?? 'voice-tts');
    }

    /** @return list<string> */
    protected function requiredConfig(): array
    {
        return ['endpoint', 'api_key', 'caller_id'];
    }

    protected function perform(string $address, Recipient $to, RenderedMessage $message): Response
    {
        return $this->http()
            ->withToken((string) $this->config['api_key'])
            ->post((string) $this->config['endpoint'], [
                'to' => $address,
                'from' => $this->config['caller_id'],
                'text' => $this->forSpeech($message),
                'voice' => $this->config['voice'] ?? 'en-NG-Standard-A',
                // Repeat, because somebody answering a call at 3am misses the
                // first sentence. Two passes is what a real cascade does.
                'repeat' => (int) ($this->config['repeat'] ?? 2),
            ]);
    }

    protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            // GATE 2, THIRD ROUND (advisory 11, one adapter along). This used
            // to return the provider's own `message` field verbatim as
            // `failed_reason` — a voice gateway's rejection text can name the
            // dialled number the same way an SMS gateway's does ("Invalid
            // destination 2348031234567"), and `redact()` cannot reach a value
            // inside free text. See `HttpChannel::classifyProviderError()` for
            // exactly what is matched and what is not; either way the string
            // itself never reaches `failed_reason`, and the same field is
            // nulled out of `raw_response` below so the number cannot
            // reappear there instead.
            $reason = data_get($body, 'message');
            $category = is_string($reason) && $reason !== '' ? $this->classifyProviderError($reason) : null;

            return DeliveryReceipt::failed(
                $this->provider(),
                match (true) {
                    $category !== null => 'Voice gateway rejected the call ('.$category.'); HTTP '.$response->status().'.',
                    is_string($reason) && $reason !== '' => 'Voice gateway rejected the call (reason not classified); HTTP '.$response->status().'.',
                    default => 'Voice gateway returned HTTP '.$response->status().'.',
                },
                $this->safeResponse($response, ['message']),
            );
        }

        $callId = data_get($body, $this->config['response']['call_id'] ?? 'call_id');

        if (! is_string($callId) || $callId === '') {
            return DeliveryReceipt::failed(
                $this->provider(),
                'Voice gateway accepted the call but returned no call id, so the outcome cannot be tracked.',
                $this->safeResponse($response),
            );
        }

        // SENT, NOT DELIVERED. The call has been placed; whether a human
        // answered arrives later on the status webhook. Recording it as
        // delivered here would tell a crisis manager somebody picked up.
        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: $callId,
            costMinor: is_numeric($this->config['cost_minor'] ?? null) ? (int) $this->config['cost_minor'] : null,
            currency: $this->config['currency'] ?? 'NGN',
            raw: $this->safeResponse($response),
        );
    }

    /**
     * The text a machine will read out.
     *
     * Kept deliberately conservative: strip what speaks badly, expand the few
     * abbreviations this module actually emits, and leave the operator's words
     * otherwise alone. Rewriting an emergency instruction to sound better is
     * not something an adapter gets to do.
     */
    private function forSpeech(RenderedMessage $message): string
    {
        $body = $message->wireBody();

        // URLs are unspeakable and the alert already carries the instruction.
        $body = preg_replace('#https?://\S+#i', 'the link in your message', $body) ?? $body;

        $body = strtr($body, [
            'RTO' => 'recovery time objective',
            'RPO' => 'recovery point objective',
            'MTPD' => 'maximum tolerable period of disruption',
            'BCP' => 'business continuity plan',
            'ETA' => 'estimated time of arrival',
            '&' => ' and ',
        ]);

        // A pause after the exercise prefix, so it is not run into the
        // instruction that follows it.
        $body = str_replace(RenderedMessage::EXERCISE_PREFIX, RenderedMessage::EXERCISE_PREFIX.' ... ', $body);

        return trim(preg_replace('/\s+/', ' ', $body) ?? $body);
    }
}
