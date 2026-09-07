<?php

namespace App\Services\Tprm\Extraction;

/**
 * One model call's outcome.
 *
 * `unavailable` and `failed` are different states and the difference reaches
 * the user. Unavailable means the module is working as configured — AI is off,
 * or the endpoint is down — and the screen says so calmly and offers the
 * manual form. Failed means it was on, it ran, and what came back was
 * unusable, which is worth a support conversation.
 */
class LlmResult
{
    /**
     * @param  array<mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $promptVersion,
        public readonly ?string $model = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly int $durationMs = 0,
        public readonly ?string $message = null,
        public readonly bool $wasAvailable = true,
    ) {}

    public static function unavailable(string $promptVersion, string $message): self
    {
        return new self([], $promptVersion, message: $message, wasAvailable: false);
    }

    public static function failed(string $promptVersion, ?string $model, string $message): self
    {
        return new self([], $promptVersion, model: $model, message: $message);
    }

    public function succeeded(): bool
    {
        return $this->data !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'succeeded' => $this->succeeded(),
            'available' => $this->wasAvailable,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'duration_ms' => $this->durationMs,
            'message' => $this->message,
        ];
    }
}
