<?php

namespace App\Contracts\Bcms;

use App\Enums\Bcms\DeliveryStatus;

/**
 * What a channel says happened. Frozen at G0 (ADR 0004).
 *
 * THE FIELD SET IS THE COLUMN SET of `bcms_notification_deliveries`, so
 * persisting a receipt is a copy and not a translation. A translation layer is
 * where a provider's `failed_reason` quietly stops being recorded.
 *
 * `costMinor` IS NULLABLE AND MUST STAY NULLABLE. A channel that cannot price a
 * send returns null; zero is a claim that it was free, and a cost report that
 * sums nulls as zero understates a crisis dispatch by however many providers
 * did not report (development standard §5).
 */
final readonly class DeliveryReceipt
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public DeliveryStatus $status,
        public string $provider,
        public ?string $providerMessageId = null,
        public ?string $failedReason = null,
        public ?int $costMinor = null,
        public ?string $currency = null,
        public ?array $rawResponse = null,
        public ?\DateTimeImmutable $sentAt = null,
        public ?\DateTimeImmutable $deliveredAt = null,
    ) {}

    public static function sent(string $provider, string $messageId, ?int $costMinor = null, ?string $currency = null, ?array $raw = null): self
    {
        return new self(
            status: DeliveryStatus::Sent,
            provider: $provider,
            providerMessageId: $messageId,
            costMinor: $costMinor,
            currency: $currency,
            rawResponse: $raw,
            sentAt: new \DateTimeImmutable,
        );
    }

    public static function failed(string $provider, string $reason, ?array $raw = null): self
    {
        return new self(
            status: DeliveryStatus::Failed,
            provider: $provider,
            failedReason: $reason,
            rawResponse: $raw,
        );
    }

    /**
     * The receipt as `bcms_notification_deliveries` columns.
     *
     * @return array<string, mixed>
     */
    public function toDeliveryAttributes(): array
    {
        return [
            'status' => $this->status->value,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'failed_reason' => $this->failedReason,
            'cost_minor' => $this->costMinor,
            'currency' => $this->currency,
            'raw_response' => $this->rawResponse,
            'sent_at' => $this->sentAt,
            'delivered_at' => $this->deliveredAt,
        ];
    }
}
