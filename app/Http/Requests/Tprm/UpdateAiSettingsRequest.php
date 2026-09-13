<?php

namespace App\Http\Requests\Tprm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * phase-11a-ai-contract.md §7.2. `tprm.admin`-gated route; this request only
 * validates the shape once the middleware has already confirmed the ability.
 *
 * `ai_endpoint_profile` REJECTS A URL AND ACCEPTS ONLY A CONFIGURED KEY —
 * ADR 0015 §3. A tenant administrator picks from a list; nobody types a URL
 * into the database, because a stored URL fetched server-side is an SSRF
 * with a settings screen in front of it.
 *
 * `ai_services` REJECTS A KEY FOR AN UNIMPLEMENTED SERVICE. The screen never
 * renders an interactive control for one, but the resolver enforces this
 * independently of the form (contract §8.3) — this is the form's half of
 * that belt-and-braces pair.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tprm.admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable, and null is a LEGITIMATE value: "follow the
            // deployment" (contract §3.2's tri-state).
            'ai_enabled' => ['nullable', 'boolean'],

            // Keys are checked in withValidator() below, against the union of
            // every configured service — Laravel has no Rule::in for an
            // associative array's own keys.
            'ai_services' => ['nullable', 'array'],
            'ai_services.*' => ['nullable', 'boolean'],

            'ai_endpoint_profile' => ['nullable', 'string', Rule::in(array_keys((array) config('llm.profiles', [])))],

            'ai_monthly_token_cap' => ['nullable', 'integer', 'min:0'],
            'ai_monthly_call_cap' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Keys in `ai_services` that are not a configured service, or are a
     * configured but UNIMPLEMENTED one, fail validation here — `rules()`
     * alone cannot express "reject this key" for an associative array.
     */
    protected function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $services = (array) $this->input('ai_services', []);

            if ($services === []) {
                return;
            }

            $known = array_keys((array) config('tprm.ai.services', []));
            $implemented = (array) config('tprm.ai.implemented_services', []);

            foreach (array_keys($services) as $key) {
                if (! in_array($key, $known, true)) {
                    $validator->errors()->add('ai_services', "\"{$key}\" is not a configured AI service.");

                    continue;
                }

                if (! in_array($key, $implemented, true)) {
                    $validator->errors()->add(
                        'ai_services',
                        "\"{$key}\" is not built in this release and cannot be switched on or off."
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_endpoint_profile.in' => 'This must be one of the configured profiles — a web address cannot be entered here.',
        ];
    }

    /**
     * The model payload — ONLY THE KEYS THIS REQUEST ACTUALLY SENT.
     *
     * Gate 2 advisory 7: this used to return all five keys unconditionally,
     * collapsing "the request omitted this field" and "the request sent this
     * field as null" into the same `null`, which `AiSettingsController` then
     * wrote straight onto the model — so a `PUT` that saved only the master
     * switch, or a retried request with a stale body, silently zeroed
     * `ai_endpoint_profile`, `ai_monthly_token_cap` and `ai_monthly_call_cap`
     * for every tenant that had ever set them. A monthly cap is a governance
     * control; uncapping it by omission is the opposite of what §3.2's
     * "merge, don't replace" reasoning — already applied to `ai_services` —
     * exists to prevent.
     *
     * `has()`, not `filled()`: a key present with an explicit `null` value
     * ("follow the deployment", the tri-state's own legitimate middle value)
     * must still be written, so only a key the request never mentioned at
     * all is left out of the returned array. `AiSettingsController::update()`
     * then `fill()`s the model with exactly these keys, leaving every other
     * column exactly as stored.
     *
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        $validated = $this->validated();
        $payload = [];

        foreach ([
            'ai_enabled',
            'ai_services',
            'ai_endpoint_profile',
            'ai_monthly_token_cap',
            'ai_monthly_call_cap',
        ] as $key) {
            if ($this->has($key)) {
                $payload[$key] = $validated[$key] ?? null;
            }
        }

        return $payload;
    }
}
