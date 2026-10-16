<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use App\Services\Bcms\Notification\Channels\WebhookChannel;
use App\Support\Chat\ChatText;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alert text is typed by a person and goes to Slack and Teams webhooks. It
 * must arrive as text, not as a channel ping, a mention, a link or HTML.
 * See App\Support\Chat\ChatText, the one escaping helper for every chat post.
 */
class WebhookChannelEscapingTest extends TestCase
{
    private function recipient(): Recipient
    {
        return new Recipient(contactId: 1, name: 'Test Recipient', teamsId: 'teams-1', slackId: 'U0SLACK1');
    }

    private function message(): RenderedMessage
    {
        return new RenderedMessage(body: '<!channel> & <b>bold</b> [x](https://evil.test)', subject: '<!here> & <i>');
    }

    #[Test]
    public function a_slack_alert_arrives_escaped(): void
    {
        Http::fake();

        $channel = new WebhookChannel(ChannelKey::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
        $channel->send($this->recipient(), $this->message());

        Http::assertSent(function ($request) {
            $text = $request['text'];

            return $text === "*&lt;!here&gt; &amp; &lt;i&gt;*\n&lt;!channel&gt; &amp; &lt;b&gt;bold&lt;/b&gt; [x](https://evil.test)"
                && ! str_contains($text, '<!channel>')
                && ! str_contains($text, '<!here>');
        });
    }

    #[Test]
    public function a_teams_alert_arrives_escaped_with_markdown_neutralised(): void
    {
        Http::fake();

        $channel = new WebhookChannel(ChannelKey::Teams, ['webhook_url' => 'https://example.webhook.office.com/webhookb2/x']);
        $channel->send($this->recipient(), $this->message());

        Http::assertSent(function ($request) {
            return $request['text'] === '&lt;!channel&gt; &amp; &lt;b&gt;bold&lt;/b&gt; \[x\](https://evil.test)'
                && $request['title'] === '&lt;!here&gt; &amp; &lt;i&gt;'
                && ! str_contains($request['text'], '<b>')
                // `summary` is plain text and stays as the author wrote it.
                && $request['summary'] === '<!here> & <i>';
        });
    }

    #[Test]
    public function the_exercise_prefix_and_plain_text_are_unchanged(): void
    {
        Http::fake();

        $channel = new WebhookChannel(ChannelKey::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
        $channel->send($this->recipient(), new RenderedMessage(body: 'Evacuate now.', subject: null, isSimulation: true));

        Http::assertSent(fn ($request) => $request['text'] === 'THIS IS AN EXERCISE. Evacuate now.');
    }

    #[Test]
    public function the_helper_picks_the_dialect_from_the_webhook_host(): void
    {
        $this->assertSame('slack', ChatText::flavourOf('https://hooks.slack.com/services/a'));
        $this->assertSame('teams', ChatText::flavourOf('https://x.webhook.office.com/webhookb2/a'));
        $this->assertSame('teams', ChatText::flavourOf('https://prod-01.westeurope.logic.azure.com/workflows/a'));
        $this->assertSame('generic', ChatText::flavourOf('https://notslack.com/hook'));
        $this->assertSame('generic', ChatText::flavourOf('https://hooks.slack.com.evil.test/hook'));
        $this->assertSame('generic', ChatText::flavourOf('not a url'));

        // Case, a trailing root dot and a port do not change the host.
        $this->assertSame('slack', ChatText::flavourOf('https://HOOKS.Slack.COM/services/a'));
        $this->assertSame('slack', ChatText::flavourOf('https://hooks.slack.com./services/a'));
        $this->assertSame('slack', ChatText::flavourOf('https://hooks.slack.com:443/services/a'));
        $this->assertSame('teams', ChatText::flavourOf('https://x.webhook.office.com./webhookb2/a'));
        $this->assertSame('teams', ChatText::flavourOf('https://X.Webhook.Office.COM:8443/webhookb2/a'));

        // Look-alikes and URL tricks are not the product they imitate.
        $this->assertSame('generic', ChatText::flavourOf('https://evil-office.com/hook'));
        $this->assertSame('generic', ChatText::flavourOf('https://evil-slack.com/hook'));
        $this->assertSame('generic', ChatText::flavourOf('https://hooks.slack.com@evil.test/hook'));
        $this->assertSame('generic', ChatText::flavourOf('https://evil.test/hooks.slack.com'));
        $this->assertSame('generic', ChatText::flavourOf('https://evil.test/?u=x.office.com'));

        $this->assertSame('&lt;!channel&gt; &amp;', ChatText::forUrl('https://chat.example.test/h', '<!channel> &'));
        $this->assertSame('', ChatText::slack(null));
    }
}
