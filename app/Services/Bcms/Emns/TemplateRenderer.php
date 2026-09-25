<?php

namespace App\Services\Bcms\Emns;

use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Exceptions\Bcms\TemplateNotActiveException;
use App\Exceptions\Bcms\UnresolvedTemplateVariableException;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\Site;
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
 * EVERY CHANNEL SUBSTITUTES FROM THE SAME VARIABLE SET, IN ONE PLACE.
 * `channel_renderings` gives SMS and voice their own, shorter text — but that
 * text carries the same `{{placeholders}}` the primary body does, and used to
 * be swapped in raw, after substitution had already run against the primary
 * body and finished. An `EVACUATE` SMS reached a phone reading
 * "EVACUATE {{site_name}} NOW … Assemble at {{assembly_point}}."
 * `substituteChannelOverride()` now substitutes the override itself, from
 * the identical variable map `variablesFor()` built for the body — one
 * substitution path, not one per channel — and does so BEFORE
 * `shapeForChannel()` truncates anything (item 2: checking after truncation
 * let a placeholder past the cut vanish silently, or one straddling it
 * leave a literal `{{...` on the wire).
 *
 * FAIL CLOSED, NOT SILENTLY STRIPPED. A placeholder nobody supplied used to be
 * deleted rather than left literal — "Assemble at {{assembly_point}}" became
 * the coherent-but-wrong "Assemble at". Coherent-looking is the more dangerous
 * failure: nobody double-checks a sentence that reads fine. `render()` now
 * refuses — `UnresolvedTemplateVariableException`, naming the variable — for
 * ANY channel with ANY unfilled placeholder left after substitution. Never a
 * literal `{{…}}` on the wire, and never a silently incomplete sentence
 * either.
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

    /** @var array<int, array<string, mixed>> */
    private array $derivedVariableCache = [];

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

        // AN ALERT WITH A `template_id` MUST RENDER FROM AN ACTIVE TEMPLATE
        // OR NOT AT ALL. `templateFor()` returning null here means every
        // active candidate — this locale, every sibling locale, the English
        // fallback — was checked and none exists; falling back to
        // `$alert->message` at that point would silently send free text
        // nobody wrote (an alert composed from a template usually has none)
        // in place of a template an admin may have deactivated for a
        // reason. An alert with NO template at all (`template_id === null`)
        // is the genuinely different, legitimate case this does not touch.
        if ($template === null && $alert->template_id !== null) {
            // Avoids `?->code ?? ...`: the same nullsafe-feeding-`??` shape
            // PHPStan misjudges elsewhere in this class (see `Incident`'s
            // docblock) — written this way rather than ignored again.
            $code = $alert->template === null ? (string) $alert->template_id : $alert->template->code;

            throw new TemplateNotActiveException($code);
        }

        // Locals, because the template is genuinely optional — an alert composed
        // free-hand has none — and its columns are typed non-null once it
        // exists, so a nullsafe chain reads as redundant to the next reader.
        $templateBody = $template === null ? null : $template->body;
        $templateSubject = $template === null ? null : $template->subject;
        $renderedLocale = $template === null ? 'en' : (string) $template->locale;

        // Computed ONCE and reused for the body, the subject AND the
        // channel-specific override below — "the same variable set the
        // primary body uses" is the whole point, not three independent reads.
        $resolved = $this->variablesFor($alert, $variables);

        $body = $this->substitute($templateBody ?? $alert->message ?? '', $resolved);
        $subject = $this->substitute($templateSubject ?? $alert->title ?? '', $resolved);

        // THE CHANNEL OVERRIDE IS SUBSTITUTED, but NOT YET SHAPED (no SMS
        // truncation), before the fail-closed check runs. Checking after
        // truncation was itself a defect: a placeholder past the cut point
        // silently vanished with the truncated tail, and one straddling the
        // cut left a literal `{{...` on the wire — both worse than refusing
        // outright. `assertFullyResolved()` now runs on the full,
        // untruncated channel body, for every channel, before anything is
        // cut to fit a segment budget.
        $body = $this->substituteChannelOverride($body, $channel, $template, $resolved);

        $this->assertFullyResolved($body, $channel, $renderedLocale);
        $this->assertFullyResolved($subject, $channel, $renderedLocale);

        $body = $this->shapeForChannel($body, $channel);

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
     * The template row for this alert in this language, or the English one
     * — or null if NOTHING active exists for this template's code, in any
     * locale.
     *
     * An INACTIVE template is not a fallback candidate, in its own locale or
     * any other's. A row awaiting translation review, or one an admin has
     * since withdrawn, must never be sent — `is_active` is the switch either
     * kind of change flips, and this method does not distinguish between
     * them (see `TemplateNotActiveException`'s docblock for why the message
     * that reaches an operator is neutral about which one happened).
     *
     * RETURNING THE ORIGINAL, INACTIVE `$template` AS A LAST RESORT WAS THE
     * DEFECT (item 9). It let a template deactivated after an alert was
     * already composed keep rendering — right up until somebody re-composed
     * the alert — because `render()` had no way to tell "genuinely no
     * template" (the free-text case) apart from "had one, but it is
     * withdrawn now". Returning null for both and letting `render()` draw
     * that distinction from `$alert->template_id` is what closes the gap.
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
            ->first();
    }

    /**
     * The channel-specific text, substituted — but not yet shaped or
     * truncated. `channel_renderings` on the template wins where an author
     * has written one — a voice script phrased for speech, an email with
     * more detail than a text message. Where they have not, the primary
     * body stands as the channel's text.
     *
     * THE OVERRIDE IS SUBSTITUTED TOO, from the SAME `$variables` the primary
     * body just used. This was the original defect: the override used to
     * replace `$body` verbatim, after substitution had already happened, so
     * an SMS/voice override's own `{{placeholders}}` never saw a value.
     *
     * @param  array<string, mixed>  $variables
     */
    private function substituteChannelOverride(string $body, ChannelKey $channel, ?AlertTemplate $template, array $variables): string
    {
        $renderings = $template === null ? [] : (array) $template->channel_renderings;
        $override = data_get($renderings, $channel->value);

        return is_string($override) && $override !== '' ? $this->substitute($override, $variables) : $body;
    }

    /**
     * Channel-specific SHAPE ONLY — truncation, never substitution. Called
     * AFTER `assertFullyResolved()`, deliberately: truncating first was
     * itself a defect (item 2) — a placeholder past the cut point vanished
     * silently with the truncated tail, and one straddling the cut point
     * left a literal `{{...` on the wire. Shaping a body already proven
     * free of unfilled placeholders cannot reintroduce one.
     */
    private function shapeForChannel(string $body, ChannelKey $channel): string
    {
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
            // A value that is not scalar (and not null) cannot be
            // substituted in safely — an array or object landing in a
            // template belongs on `FactRegistry`'s TPRM-side warning, not
            // here, but the same rule applies: skip it rather than guess a
            // string form for it. `null` IS substituted, as an empty string
            // — an explicit "nothing to say here" is different from a
            // variable nobody supplied at all, and only the latter should
            // fail closed below.
            if ($value !== null && ! is_scalar($value)) {
                continue;
            }

            $text = str_replace(['{{'.$key.'}}', '{{ '.$key.' }}'], (string) $value, $text);
        }

        // A placeholder nobody supplied a value for is LEFT IN PLACE, not
        // silently removed — `assertFullyResolved()` below is what turns that
        // into a refusal rather than a wrong number being sent. See the class
        // docblock: "fail closed, not silently stripped".
        return trim($text);
    }

    /**
     * Refuses to hand back a rendering that still carries an unfilled
     * `{{variable}}`. Called for every channel's body and subject — the one
     * place this is checked, so no caller has to remember to.
     */
    private function assertFullyResolved(string $text, ChannelKey $channel, string $locale): void
    {
        if (preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $matches) === 0) {
            return;
        }

        throw UnresolvedTemplateVariableException::forVariables(
            array_values(array_unique($matches[1])),
            $channel,
            $locale,
        );
    }

    /**
     * @param  array<string, mixed>  $extra  Supplied by the caller and always wins over anything derived below.
     * @return array<string, mixed>
     */
    private function variablesFor(Alert $alert, array $extra): array
    {
        return array_merge([
            'alert_title' => $alert->title,
            'severity' => $alert->severity->value,
            'organisation' => config('app.name'),
        ], $this->alertDerivedVariables($alert), $extra);
    }

    /**
     * What an Alert row and the compose/release flow genuinely carry —
     * derived, not invented, and never a per-recipient value (per-recipient
     * variables are a separate, deliberately unbuilt piece of work; see the
     * booked "EMNS per-recipient-variables" ADR).
     *
     * `bcms_alerts` has no `site_name`, `assembly_point` or similar free-text
     * column — there is nowhere to store one, and this method does not
     * invent one. What it DOES read, because the alert already carries it:
     *
     *   - `site_name` / `location` — the audience rule's site, when and only
     *     when the whole positive audience names EXACTLY one
     *     (`siteNameFromAudienceRule()`'s own docblock has the shape and the
     *     life-safety reason it is this narrow), or the linked incident's or
     *     exercise occurrence's site, when the audience rule names none.
     *   - `incident_reference` / `incident_title` — the linked incident, for
     *     an alert composed from the crisis room.
     *   - `exercise_name` / `scheduled_date` / `location` / `days_remaining`
     *     — the linked exercise occurrence and its definition. `days_remaining`
     *     is computed from `scheduled_date`, not fabricated.
     *
     * A GENUINE GAP, NOT PAPERED OVER. `assembly_point`, `bridge`,
     * `convene_by`, `tree_name`, `service_name`, `workaround`, `next_update`
     * and several others are referenced by shipped templates and are not
     * derivable from anything an alert carries today. Composing one of those
     * templates now fails closed at release with the missing variable named,
     * rather than sending broken text — see this fix's HANDOFF for the full
     * list and the schema question it raises for `architect`.
     *
     * MEMOISED PER ALERT, PER RENDERER INSTANCE. `render()` runs once per
     * (recipient, channel) pair — thousands of times for one large dispatch —
     * and every one of those calls, within one queued chunk, shares the same
     * `TemplateRenderer` instance. Without this, a 200-recipient chunk on
     * three channels would run the site/incident/occurrence lookups up to 600
     * times for values that cannot change mid-dispatch.
     *
     * @return array<string, mixed>
     */
    private function alertDerivedVariables(Alert $alert): array
    {
        $id = (int) $alert->getKey();

        if (array_key_exists($id, $this->derivedVariableCache)) {
            return $this->derivedVariableCache[$id];
        }

        return $this->derivedVariableCache[$id] = $this->computeAlertDerivedVariables($alert);
    }

    /** @return array<string, mixed> */
    private function computeAlertDerivedVariables(Alert $alert): array
    {
        $vars = [];

        // BOTH `?->` ARE LOAD-BEARING. `site_id` is nullable on both
        // `bcms_incidents` and `bcms_exercise_occurrences`, and null is the
        // default — an IT-only incident or an exercise with no site
        // genuinely has none. PHPStan (via Larastan's relation-type
        // inference, which reads the `site(): BelongsTo` method's `@return
        // BelongsTo<Site, $this>` rather than the `@property-read ?Site
        // $site` on `Incident`/`ExerciseOccurrence`) believes the second
        // `?->` is redundant; it is not. Verified directly: `$a?->b->c` with
        // `$a` non-null and `$a->b` null throws "Attempt to read property
        // on null" — PHP's nullsafe chain only short-circuits at the
        // operator whose OWN left-hand side is null, not for every later
        // access in the same chain. Dropping either `?->` here was shipped
        // once and crashed `estimate()`/`release()`/`sendOne()` on the
        // first incident- or exercise-linked alert with no site attached —
        // see this fix's HANDOFF. Do not remove either guard.
        $siteName = $this->siteNameFromAudienceRule($alert->audience_rule)
            ?? $alert->incident?->site?->name // @phpstan-ignore nullsafe.neverNull
            ?? $alert->occurrence?->site?->name;

        if ($siteName !== null) {
            $vars['site_name'] = $siteName;
        }

        if ($alert->incident !== null) {
            $vars['incident_reference'] = $alert->incident->reference;
            $vars['incident_title'] = $alert->incident->title;
        }

        if ($alert->occurrence !== null) {
            $vars['exercise_name'] = $alert->occurrence->definition?->name;

            $vars['location'] = $alert->occurrence->location ?? $siteName;

            if ($alert->occurrence->scheduled_date !== null) {
                $vars['scheduled_date'] = $alert->occurrence->scheduled_date->toFormattedDateString();
                $vars['days_remaining'] = max(0, (int) now()->startOfDay()
                    ->diffInDays($alert->occurrence->scheduled_date->copy()->startOfDay(), false));
            }
        }

        return array_filter($vars, fn ($v) => $v !== null);
    }

    /**
     * The audience's site — ONLY when the WHOLE positive audience is
     * unambiguously exactly one site. `site_name` names the place people
     * are told to do something about (evacuate it, return to it); getting
     * that wrong is not a formatting defect.
     *
     * GATE 2 DEFECT, LIFE-SAFETY SEVERITY. The previous version walked every
     * `rules` array it found, including a `none_of` node's — and `none_of`'s
     * children are EXCLUDED from the audience (`AudienceRule`, ADR 0003:
     * "`none_of` is applied last and can only remove"). For
     * `all_of[org_node Lagos, none_of[site Ikeja]]` — "everyone in Lagos
     * EXCEPT Ikeja branch" — that walk collected Ikeja's id anyway, and an
     * `EVACUATE` alert rendered "EVACUATE Ikeja Branch NOW" naming the one
     * site the audience rule explicitly excluded.
     *
     * THE SHAPE IS DELIBERATELY NARROW, not a general "every site anywhere
     * in the tree": a bare `site` leaf naming exactly one id, or an `all_of`
     * whose direct children include exactly one `site` leaf naming exactly
     * one id (an AND — "this site AND these other conditions" — can only
     * narrow, never widen, so the named site is still correct). `any_of` —
     * anywhere, even nested inside an `all_of` — disqualifies derivation
     * outright: `any_of[site A, org_node Finance]` is a WIDER audience than
     * "site A alone", and naming A for it would misname who is actually
     * being addressed. Two site leaves, zero site leaves, or an `any_of`
     * of any kind: this returns null, and a template needing `site_name`
     * fails closed instead of guessing — the operator supplies it directly
     * (ADR 0024, booked, will make that possible from the compose screen).
     *
     * @param  array<string, mixed>|null  $rule
     */
    private function siteNameFromAudienceRule(?array $rule): ?string
    {
        $id = $rule === null ? null : $this->soleSiteId($rule);

        return $id === null ? null : Site::query()->find($id)?->name;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function soleSiteId(array $rule): ?int
    {
        $type = $rule['type'] ?? null;

        if ($type === 'site') {
            return $this->soleId((array) ($rule['ids'] ?? []));
        }

        if ($type !== 'all_of') {
            // `any_of` (a wider audience), `none_of` (an exclusion, never a
            // target), a bare `org_node`/`role`/etc — none of these name
            // exactly one site on their own.
            return null;
        }

        $siteChildren = [];

        foreach ((array) ($rule['rules'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            // An `any_of` NESTED inside this `all_of` still disqualifies
            // the whole branch — "this site AND (A or B)" does not make A
            // or B the site, it makes the audience conditional on one of
            // them, which is not "exactly one site" either.
            if (($child['type'] ?? null) === 'any_of') {
                return null;
            }

            if (($child['type'] ?? null) === 'site') {
                $siteChildren[] = $child;
            }

            // `none_of`, `org_node`, `role`, `call_tree`,
            // `occurrence_participants`, `saved_group`, `geo`, a nested
            // `all_of` with no site of its own: further restrictions, not
            // disqualifying and not site-naming.
        }

        return count($siteChildren) === 1 ? $this->soleId((array) ($siteChildren[0]['ids'] ?? [])) : null;
    }

    /** The one id in a `site` leaf's `ids`, or null if it names zero or more than one. */
    private function soleId(array $ids): ?int
    {
        $unique = array_values(array_unique(array_map('intval', $ids)));

        return count($unique) === 1 ? $unique[0] : null;
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
