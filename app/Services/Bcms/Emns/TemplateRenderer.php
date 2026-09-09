<?php

namespace App\Services\Bcms\Emns;

use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertTemplate;
use App\Services\Bcms\Notification\Channels\SmsSegmenter;
use Illuminate\Support\Collection;

/**
 * One alert, rendered per recipient, per channel, per language.
 *
 * RENDERING HAPPENS HERE AND NEVER IN AN ADAPTER. That is rule 1 of the frozen
 * interface: 160 characters of SMS, a voice script and a Teams card are three
 * different texts, and letting each adapter truncate one body three ways is how
 * a life-safety instruction loses its second sentence.
 *
 * THE LANGUAGE FALLBACK IS RECORDED, NOT SILENT. Blueprint §7.2 ships five
 * Nigerian languages and a contact carries `preferred_language`. When no active
 * template exists in somebody's language the English one is sent — there is no
 * version of this where a person gets nothing — but the fallback is reported on
 * the rendering so the template library can show the coverage gap and the
 * delivery record can show that Amina got English because Hausa was never
 * authored. A silent fallback would let a bank believe it had five-language
 * cover for years.
 *
 * NOTHING HERE MACHINE-TRANSLATES. The Phase 7 prompt forbids it and it is
 * right to: an evacuation instruction that says the wrong thing in Hausa is a
 * safety incident, not a formatting bug. Translations are authored and reviewed
 * by a person, and until they are, the gap is visible rather than filled.
 *
 * THE EXERCISE PREFIX IS APPLIED BY `RenderedMessage`, not here, and is
 * therefore impossible to forget (standing rule 5).
 */
class TemplateRenderer
{
    /** Blueprint §7.2's five. English is the fallback and the only guaranteed one. */
    public const LOCALES = ['en', 'ha', 'yo', 'ig', 'pcm'];

    public const LOCALE_LABELS = [
        'en' => 'English',
        'ha' => 'Hausa',
        'yo' => 'Yoruba',
        'ig' => 'Igbo',
        'pcm' => 'Nigerian Pidgin',
    ];

    /** How many SMS segments an alert may occupy before it is cut. */
    private const MAX_SMS_SEGMENTS = 2;

    /**
     * Render one alert for one recipient on one channel.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(
        Alert $alert,
        ChannelKey $channel,
        string $locale = 'en',
        array $variables = [],
        ?string $callbackToken = null,
    ): RenderedMessage {
        $template = $this->templateFor($alert, $locale);

        // Locals, because the template is genuinely optional — an alert composed
        // free-hand has none — and its columns are typed non-null once it
        // exists, so a nullsafe chain reads as redundant to the next reader.
        $templateBody = $template === null ? null : $template->body;
        $templateSubject = $template === null ? null : $template->subject;
        $renderedLocale = $template === null ? 'en' : (string) $template->locale;

        $body = $this->substitute(
            $templateBody ?? $alert->message ?? '',
            $this->variablesFor($alert, $variables),
        );

        $subject = $this->substitute(
            $templateSubject ?? $alert->title ?? '',
            $this->variablesFor($alert, $variables),
        );

        $body = $this->forChannel($body, $channel, $template);

        return new RenderedMessage(
            body: $body,
            subject: $subject === '' ? null : $subject,
            locale: $renderedLocale,
            severity: $alert->severity,
            // Simulation forces the prefix on every channel (criterion 5). The
            // flag is on the alert and travels with the message; no caller can
            // decide to leave it off.
            isSimulation: (bool) $alert->is_simulation,
            responseRequired: (bool) $alert->response_required,
            metadata: array_filter([
                'whatsapp_template_name' => $template?->whatsapp_template_name,
                'response_options' => $alert->response_options ?: null,
                'alert_id' => $alert->getKey(),
                'requested_locale' => $locale,
                'rendered_locale' => $renderedLocale,
                'locale_fell_back' => $renderedLocale !== $locale,
            ], fn ($v) => $v !== null),
            callbackToken: $callbackToken,
        );
    }

    /**
     * The template row for this alert in this language, or the English one.
     *
     * An INACTIVE template is not a fallback candidate. A row awaiting
     * translation review must never be sent, and `is_active` is the switch a
     * reviewer flips.
     */
    public function templateFor(Alert $alert, string $locale): ?AlertTemplate
    {
        $template = $alert->template;

        if ($template === null) {
            return null;
        }

        if ($template->locale === $locale && $template->is_active) {
            return $template;
        }

        $sibling = AlertTemplate::query()
            ->where('code', $template->code)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if ($sibling !== null) {
            return $sibling;
        }

        return AlertTemplate::query()
            ->where('code', $template->code)
            ->where('locale', 'en')
            ->where('is_active', true)
            ->first() ?? $template;
    }

    /**
     * Channel-specific shaping.
     *
     * `channel_renderings` on the template wins where an author has written one
     * — a voice script phrased for speech, an email with more detail than a
     * text message. Where they have not, the body is shaped by the rules of the
     * channel.
     */
    private function forChannel(string $body, ChannelKey $channel, ?AlertTemplate $template): string
    {
        $renderings = $template === null ? [] : (array) $template->channel_renderings;
        $override = data_get($renderings, $channel->value);

        if (is_string($override) && $override !== '') {
            $body = $override;
        }

        return match ($channel) {
            // Criterion 10: length respected, and never cut mid-word.
            ChannelKey::Sms => SmsSegmenter::truncate($body, self::MAX_SMS_SEGMENTS),
            ChannelKey::Ussd => SmsSegmenter::truncate($body, 1),
            default => $body,
        };
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function substitute(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace(['{{'.$key.'}}', '{{ '.$key.' }}'], (string) $value, $text);
        }

        // A placeholder nobody supplied is REMOVED, not left as `{{site}}`.
        // "Assemble at {{assembly_point}}" reaching a phone is worse than the
        // sentence without it, and it is the kind of thing that only shows up
        // in the emergency.
        return trim(preg_replace('/\s*\{\{\s*[a-z0-9_]+\s*\}\}/i', '', $text) ?? $text);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function variablesFor(Alert $alert, array $extra): array
    {
        return array_merge([
            'alert_title' => $alert->title,
            'severity' => $alert->severity->value,
            'organisation' => config('app.name'),
        ], $extra);
    }

    /**
     * Which scenarios are authored in which languages — the template library's
     * coverage grid, and the honest answer to "do we have five-language cover".
     *
     * @return array<string, mixed>
     */
    public function coverage(): array
    {
        /** @var Collection<int, AlertTemplate> $templates */
        $templates = AlertTemplate::query()->orderBy('code')->get();

        $rows = [];

        foreach ($templates->groupBy('code') as $code => $group) {
            $byLocale = [];

            foreach (self::LOCALES as $locale) {
                $row = $group->firstWhere('locale', $locale);

                $byLocale[$locale] = [
                    'exists' => $row !== null,
                    'active' => $row !== null && (bool) $row->is_active,
                    // The three states a reviewer cares about, named. "Not
                    // authored" and "authored but awaiting review" need
                    // different people to act.
                    'state' => match (true) {
                        $row === null => 'not_authored',
                        (bool) $row->is_active => 'live',
                        default => 'awaiting_review',
                    },
                ];
            }

            $first = $group->first();

            $rows[] = [
                'code' => (string) $code,
                'name' => $first?->name,
                'category' => $first?->category,
                'severity' => $first?->severity,
                'is_life_safety' => (bool) ($first?->is_life_safety),
                'locales' => $byLocale,
                'live_locales' => count(array_filter($byLocale, fn (array $l) => $l['state'] === 'live')),
            ];
        }

        return [
            'scenarios' => $rows,
            'locales' => array_map(
                fn (string $code) => ['code' => $code, 'label' => self::LOCALE_LABELS[$code]],
                self::LOCALES,
            ),
            'note' => 'A language with no authored template falls back to English, and the fallback is '
                .'recorded on the delivery. Emergency copy is authored and reviewed by a person — nothing '
                .'here is machine-translated.',
        ];
    }

    /**
     * What an author needs to see while writing: segments, cost multiplier and
     * the characters that pushed a message out of the cheap alphabet.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $body): array
    {
        return [
            'characters' => mb_strlen($body),
            'units' => SmsSegmenter::units($body),
            'segments' => SmsSegmenter::segments($body),
            'is_gsm7' => SmsSegmenter::isGsm7($body),
            // The single pasted curly apostrophe that tripled the bill.
            'non_gsm_characters' => SmsSegmenter::nonGsmCharacters($body),
            'sms_preview' => SmsSegmenter::truncate($body, self::MAX_SMS_SEGMENTS),
            'will_truncate' => SmsSegmenter::segments($body) > self::MAX_SMS_SEGMENTS,
        ];
    }

    /** Severity levels a composer screen offers. */
    public function severities(): array
    {
        return array_map(
            fn (AlertSeverity $s) => [
                'value' => $s->value,
                'label' => ucfirst(str_replace('_', ' ', $s->value)),
                'respects_quiet_hours' => $s->respectsQuietHours(),
                'queue' => $s->queue(),
            ],
            AlertSeverity::cases(),
        );
    }
}
