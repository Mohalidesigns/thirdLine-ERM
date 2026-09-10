<?php

namespace App\Contracts\Bcms;

use App\Enums\Bcms\AlertSeverity;

/**
 * A message, already rendered for one channel and one language. Frozen at G0
 * (ADR 0004).
 *
 * RENDERING HAPPENS BEFORE THE ADAPTER, NOT INSIDE IT. 160 characters of SMS,
 * a voice TTS script and a Teams adaptive card are three different texts;
 * letting each adapter truncate one body three ways is how a life-safety
 * instruction loses its second sentence.
 *
 * `isSimulation` IS CARRIED ON THE MESSAGE and the prefix is applied here
 * rather than by whoever composes one. Standing rule 5 requires a mandatory
 * "THIS IS AN EXERCISE" prefix on exercise traffic, and a rule enforced by
 * every caller remembering it is a rule that will be forgotten once, at the
 * worst possible moment.
 */
final readonly class RenderedMessage
{
    public const EXERCISE_PREFIX = 'THIS IS AN EXERCISE. ';

    /**
     * @param  array<string, mixed>  $metadata  channel-specific extras: a WhatsApp
     *                                          template name, a voice speed, a
     *                                          Teams card payload.
     */
    public function __construct(
        public string $body,
        public ?string $subject = null,
        public string $locale = 'en',
        public AlertSeverity $severity = AlertSeverity::Advisory,
        public bool $isSimulation = false,
        public bool $responseRequired = false,
        public array $metadata = [],
        public ?string $callbackToken = null,
    ) {}

    /**
     * The text as it must go on the wire.
     *
     * The prefix is idempotent: composing a simulation message whose body
     * already carries the prefix does not stack two of them.
     */
    public function wireBody(): string
    {
        if (! $this->isSimulation) {
            return $this->body;
        }

        if (str_starts_with($this->body, self::EXERCISE_PREFIX)) {
            return $this->body;
        }

        return self::EXERCISE_PREFIX.$this->body;
    }

    public function wireSubject(): ?string
    {
        if ($this->subject === null) {
            return null;
        }

        if (! $this->isSimulation || str_starts_with($this->subject, self::EXERCISE_PREFIX)) {
            return $this->subject;
        }

        return self::EXERCISE_PREFIX.$this->subject;
    }
}
