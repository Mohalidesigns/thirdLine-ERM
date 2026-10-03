<?php

namespace App\Exceptions\Bcms;

use App\Enums\Bcms\ChannelKey;

/**
 * A rendered alert still carries an unfilled `{{variable}}` after
 * substitution.
 *
 * FAIL CLOSED, NAMING WHAT IS MISSING. Before this exception existed,
 * `TemplateRenderer::substitute()` silently deleted a placeholder nobody
 * supplied a value for — "Assemble at {{assembly_point}}" became "Assemble
 * at", a coherent-looking sentence with the one instruction that mattered
 * removed. Coherent-looking is worse than obviously broken for a life-safety
 * message: nobody double-checks a sentence that reads fine. This exception is
 * the opposite policy — the message never leaves with a gap in it, silent or
 * literal — and it names the exact variable(s) so the operator (or, for the
 * dispatcher's own defensive catch, the audit trail) can say why.
 *
 * THROWN FROM `TemplateRenderer::render()` ITSELF, not from a caller
 * re-checking its output. That is what makes it "one substitution path for
 * every channel" — SMS, voice, email, push and in-app all go through the same
 * check, at the point the text is finished, rather than each caller
 * re-implementing its own leftover-brace scan.
 */
class UnresolvedTemplateVariableException extends AlertRenderingRefusedException
{
    /** @param list<string> $variables */
    private function __construct(public readonly array $variables, public readonly ChannelKey $channel, public readonly string $locale, string $message)
    {
        parent::__construct($message);
    }

    /** @param list<string> $variables */
    public static function forVariables(array $variables, ChannelKey $channel, string $locale): self
    {
        $names = implode(', ', $variables);

        return new self($variables, $channel, $locale, sprintf(
            'This alert cannot be rendered for %s (%s): %s still %s no value. '
            .'Supply %s before this alert can be released or sent.',
            $channel->label(),
            $locale,
            count($variables) === 1 ? 'the variable "'.$names.'"' : 'the variables "'.$names.'"',
            count($variables) === 1 ? 'has' : 'have',
            count($variables) === 1 ? 'it' : 'them',
        ));
    }

    /** A fixed, grep-friendly string for `NotificationDelivery::failed_reason` (item 5). */
    public function failedReason(): string
    {
        return 'Template variable(s) not supplied: '.implode(', ', $this->variables);
    }
}
