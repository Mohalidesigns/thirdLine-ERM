<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Services\Bcms\Notification\Channels\SmsGatewayChannel;
use App\Services\Bcms\Notification\Channels\VoiceTtsChannel;
use App\Services\Bcms\Notification\Channels\WhatsAppCloudChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gate 2, third round, advisory 11 — permanent coverage.
 *
 * `HttpChannel::classifyProviderError()` is a closed, seven-label
 * classification of a provider's free-text failure description, and it must
 * never return any part of its input: a miss should cost a diagnosis, not a
 * leak. These tests exercise the seven documented categories plus the
 * unclassified fallback, and separately prove that a seeded MSISDN/email
 * planted in the exact free-text field each of the three rewritten adapters
 * reads (`SmsGatewayChannel`, `VoiceTtsChannel`, `WhatsAppCloudChannel`) never
 * survives into either `failed_reason` or `raw_response` — the two columns
 * retained for years and handed to a regulator.
 */
class Phase7ProviderErrorClassificationTest extends TestCase
{
    use RefreshDatabase;

    private const MSISDN = '2348031234567';

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function categories(): array
    {
        return [
            'invalid_recipient (recipient)' => ['Invalid recipient '.self::MSISDN, 'invalid_recipient'],
            'invalid_recipient (number)' => ['Invalid number supplied', 'invalid_recipient'],
            'invalid_recipient (msisdn)' => ['Invalid MSISDN format', 'invalid_recipient'],
            'dnd_blocked' => ['DND active for '.self::MSISDN, 'dnd_blocked'],
            'recipient_blocked (blacklist)' => ['Recipient is blacklisted', 'recipient_blocked'],
            'recipient_blocked (opted out)' => ['Recipient has opted out', 'recipient_blocked'],
            'insufficient_balance' => ['Insufficient balance on account', 'insufficient_balance'],
            'sender_id_rejected' => ['Sender ID not registered', 'sender_id_rejected'],
            'credential_rejected (unauthorized)' => ['Unauthorized request', 'credential_rejected'],
            'credential_rejected (invalid api)' => ['Invalid API key', 'credential_rejected'],
            'rate_limited' => ['Rate limit exceeded, try again later', 'rate_limited'],
            'unclassified fallback' => ['Something the provider has never documented', null],
        ];
    }

    #[Test]
    #[DataProvider('categories')]
    public function each_documented_wording_is_classified_and_nothing_else_reaches_failed_reason(string $providerText, ?string $expectedCategory): void
    {
        Http::fake(['sms.test/*' => Http::response(['message' => $providerText], 400)]);

        $channel = new SmsGatewayChannel([
            'endpoint' => 'https://sms.test/send', 'api_key' => 'k', 'sender_id' => 'KHB',
        ]);

        $receipt = $channel->send($this->recipient(), $this->message());

        if ($expectedCategory !== null) {
            $this->assertStringContainsString($expectedCategory, (string) $receipt->failedReason);
        } else {
            $this->assertStringContainsString('reason not classified', (string) $receipt->failedReason);
        }

        // THE WHOLE POINT: never any part of the provider's own text.
        $this->assertStringNotContainsString($providerText, (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, json_encode($receipt->rawResponse));
    }

    #[Test]
    public function sms_gateway_never_leaks_the_msisdn_from_a_configured_error_path(): void
    {
        Http::fake(['sms.test/*' => Http::response(['error' => 'Invalid recipient '.self::MSISDN], 400)]);

        $channel = new SmsGatewayChannel([
            'endpoint' => 'https://sms.test/send', 'api_key' => 'k', 'sender_id' => 'KHB',
            'response' => ['error' => 'error'],
        ]);

        $receipt = $channel->send($this->recipient(), $this->message());

        $this->assertStringNotContainsString(self::MSISDN, (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, json_encode($receipt->rawResponse));
        $this->assertSame('[see failed_reason category]', $receipt->rawResponse['body']['error'] ?? null);
    }

    #[Test]
    public function voice_tts_never_leaks_the_dialled_number_from_message(): void
    {
        Http::fake(['voice.test/*' => Http::response(['message' => 'Invalid number dialled: '.self::MSISDN], 400)]);

        $channel = new VoiceTtsChannel([
            'endpoint' => 'https://voice.test/call', 'api_key' => 'k', 'caller_id' => '234700000000',
        ]);

        $receipt = $channel->send($this->recipient(), $this->message());

        $this->assertStringContainsString('invalid_recipient', (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, json_encode($receipt->rawResponse));
        $this->assertSame('[see failed_reason category]', $receipt->rawResponse['body']['message'] ?? null);
    }

    #[Test]
    public function whatsapp_cloud_never_leaks_the_recipient_from_error_message(): void
    {
        Http::fake(['graph.test/*' => Http::response([
            'error' => ['message' => 'Recipient phone number not in allowed list: '.self::MSISDN, 'code' => 131030],
        ], 400)]);

        $channel = new WhatsAppCloudChannel([
            'phone_number_id' => '123', 'access_token' => 'tok', 'base_url' => 'https://graph.test',
        ]);

        $receipt = $channel->send($this->recipient(), $this->message());

        $this->assertStringContainsString('131030', (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, (string) $receipt->failedReason);
        $this->assertStringNotContainsString(self::MSISDN, json_encode($receipt->rawResponse));
        $this->assertSame('[see failed_reason category]', $receipt->rawResponse['body']['error']['message'] ?? null);
    }

    private function recipient(): Recipient
    {
        return new Recipient(
            contactId: 1,
            name: 'Test Recipient',
            email: 'recipient@khb.test',
            mobilePrimary: '+2348031234567',
            whatsapp: '+2348031234567',
        );
    }

    private function message(): RenderedMessage
    {
        return new RenderedMessage(body: 'Evacuate now.', subject: 'Evacuate');
    }
}
