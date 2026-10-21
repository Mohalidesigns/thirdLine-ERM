<?php

namespace App\Support\Chat;

/**
 * The one place user-controlled text is made safe before it goes into a chat
 * card (Slack, Microsoft Teams, or a webhook whose flavour we cannot tell).
 *
 * WHY THIS EXISTS. A risk title is typed by an ordinary user. Posted raw into
 * Slack mrkdwn, `<!channel>`, `<!here>` or `<@U123>` is not text, it is a
 * mention that pings a whole channel or a named person, and `<https://x|Click
 * here>` is a link with a label the sender chose. Posted raw into a Teams
 * MessageCard, `<b>`/`<a>` render as HTML and `[text](url)` as a link.
 * Escaping at the boundary, rather than in each caller, means a new sender
 * cannot forget it.
 *
 * EVERY CHAT POST IN THE APP GOES THROUGH HERE:
 *  - App\Console\Commands\SendMyResponsibilitiesDigest (my:digest)
 *  - App\Services\Bcms\Notification\Channels\WebhookChannel (BCMS Teams and
 *    Slack alerts, which is also what roll-calls and exercise traffic use)
 * There is no other sender: KRI breach notifications go by mail and in-app,
 * not chat.
 *
 * Escape the DATA, not the template: the `*bold*` wrapper a caller puts around
 * a name is the caller's own markup and must be added AFTER escaping.
 */
final class ChatText
{
    /** Slack: mrkdwn only treats these three characters as control (api.slack.com/reference/surfaces/formatting#escaping). */
    public static function slack(?string $text): string
    {
        return strtr((string) $text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    /**
     * Teams MessageCard text/title: a markdown dialect that also renders a
     * subset of HTML. HTML-escape first (so `<b>` and `<at>` mentions are
     * inert), then backslash-escape the markdown that would build a link,
     * emphasis or code span out of someone else's words.
     */
    public static function teams(?string $text): string
    {
        $escaped = self::slack($text);

        return preg_replace('/([\\\\`*_~\[\]])/', '\\\\$1', $escaped) ?? $escaped;
    }

    /**
     * Neutral escaping for a webhook whose product we cannot identify: only
     * the three characters every chat format treats as markup. No markdown
     * backslashes, because Slack would print them.
     */
    public static function generic(?string $text): string
    {
        return self::slack($text);
    }

    /** Escape for the chat product the webhook URL points at. */
    public static function forUrl(string $webhookUrl, ?string $text): string
    {
        return match (self::flavourOf($webhookUrl)) {
            'slack' => self::slack($text),
            'teams' => self::teams($text),
            default => self::generic($text),
        };
    }

    /** @return 'slack'|'teams'|'generic' */
    public static function flavourOf(string $webhookUrl): string
    {
        // Lower-case and without the trailing root dot: `x.office.com.` is the
        // same host as `x.office.com`.
        $host = rtrim(strtolower((string) parse_url($webhookUrl, PHP_URL_HOST)), '.');

        if ($host === 'slack.com' || str_ends_with($host, '.slack.com')) {
            return 'slack';
        }

        foreach (['office.com', 'office365.com', 'logic.azure.com', 'powerplatform.com', 'microsoft.com'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return 'teams';
            }
        }

        return 'generic';
    }
}
