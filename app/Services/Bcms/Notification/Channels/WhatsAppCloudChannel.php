<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\Response;

/**
 * WhatsApp Business API (Meta Cloud API). Blueprint §7.2 point 3: in Nigeria
 * WhatsApp read rates beat SMS and email, which makes this the channel most
 * likely to actually reach somebody — and the one with the most restrictions.
 *
 * OUTSIDE A 24-HOUR CUSTOMER-SERVICE WINDOW, META ONLY ACCEPTS AN APPROVED
 * TEMPLATE. A free-text emergency message to somebody who has not messaged you
 * today is rejected. That is not a detail this adapter can paper over: an
 * evacuation notice sent as free text at 3am would be refused by Meta, and the
 * only honest thing to do is send the approved template and put the operator's
 * words in its variables. `whatsapp_template_name` is on
 * `bcms_alert_templates` for exactly this, and an alert whose template has no
 * WABA name cannot go out on WhatsApp — reported as a failed receipt with a
 * reason a human can act on, not as a silent skip.
 *
 * THE TEMPLATE MUST BE PRE-APPROVED BY META BEFORE AN EMERGENCY, which takes
 * days to weeks. Orchestration §9 has this starting in Week 1 for that reason.
 */
class WhatsAppCloudChannel extends HttpChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct(ChannelKey::WhatsApp, $config);
    }

    public function provider(): string
    {
        return 'whatsapp-cloud';
    }

    /** @return list<string> */
    protected function requiredConfig(): array
    {
        return ['phone_number_id', 'access_token'];
    }

    protected function perform(string $address, Recipient $to, RenderedMessage $message): Response
    {
        $templateName = $message->metadata['whatsapp_template_name'] ?? null;

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->msisdn($address),
        ];

        if (is_string($templateName) && $templateName !== '') {
            $payload['type'] = 'template';
            $payload['template'] = [
                'name' => $templateName,
                'language' => ['code' => $this->metaLocale($message->locale)],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $message->wireBody()]],
                ]],
            ];
        } else {
            // Only valid inside the 24-hour window. Sent anyway rather than
            // refused locally: Meta is the authority on whether the window is
            // open, and guessing wrong here would drop a message it would have
            // accepted.
            $payload['type'] = 'text';
            $payload['text'] = ['preview_url' => false, 'body' => $message->wireBody()];
        }

        return $this->http()
            ->withToken((string) $this->config['access_token'])
            ->post(
                rtrim((string) ($this->config['base_url'] ?? 'https://graph.facebook.com/v21.0'), '/')
                .'/'.$this->config['phone_number_id'].'/messages',
                $payload,
            );
    }

    protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            // GATE 2, THIRD ROUND (advisory 11, one adapter along). This used
            // to append Meta's own `error.message` verbatim to `failed_reason`
            // — some Meta Cloud API errors (e.g. "Recipient phone number not
            // in allowed list") echo the destination MSISDN in that text, and
            // `redact()` cannot reach a value inside free text. `error.code`
            // is kept: it is Meta's own numeric error code, a bounded field
            // with no capacity to carry an address, same reasoning as the SMTP
            // reply code elsewhere in this file's siblings. `error.message`
            // itself is classified (see `HttpChannel::classifyProviderError()`
            // for exactly what that catches) rather than embedded, and is
            // nulled out of `raw_response` below so it cannot reappear there.
            $error = data_get($body, 'error.message');
            $code = data_get($body, 'error.code');
            $category = is_string($error) && $error !== '' ? $this->classifyProviderError($error) : null;

            $reason = match (true) {
                $code !== null && $category !== null => 'WhatsApp rejected the message (code '.$code.', '.$category.').',
                $code !== null => 'WhatsApp rejected the message (code '.$code.').',
                $category !== null => 'WhatsApp rejected the message ('.$category.').',
                is_string($error) && $error !== '' => 'WhatsApp rejected the message (reason not classified).',
                default => 'WhatsApp returned HTTP '.$response->status().'.',
            };

            return DeliveryReceipt::failed(
                $this->provider(),
                $reason,
                $this->safeResponse($response, ['error.message']),
            );
        }

        $messageId = data_get($body, 'messages.0.id');

        if (! is_string($messageId) || $messageId === '') {
            return DeliveryReceipt::failed(
                $this->provider(),
                'WhatsApp accepted the message but returned no id, so delivery and read receipts cannot be matched.',
                $this->safeResponse($response),
            );
        }

        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: $messageId,
            costMinor: is_numeric($this->config['cost_minor'] ?? null) ? (int) $this->config['cost_minor'] : null,
            currency: $this->config['currency'] ?? 'NGN',
            raw: $this->safeResponse($response),
        );
    }

    /** Meta wants digits with a country code and no leading plus or spaces. */
    private function msisdn(string $address): string
    {
        return ltrim(preg_replace('/[^0-9+]/', '', $address) ?? $address, '+');
    }

    /**
     * Nigerian Pidgin has no Meta locale. It falls back to English rather than
     * failing the send — the body is already Pidgin; only the template's own
     * language label changes.
     */
    private function metaLocale(string $locale): string
    {
        return match ($locale) {
            'ha' => 'ha',
            'yo' => 'yo',
            'ig' => 'ig',
            default => 'en',
        };
    }
}
