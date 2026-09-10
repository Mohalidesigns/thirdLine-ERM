<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\AlertTemplate;
use App\Services\Bcms\Emns\TemplateRenderer;
use App\Services\Bcms\Notification\ProviderHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The template library, and the provider-health and spend screens.
 *
 * THE LIBRARY LEADS WITH THE COVERAGE GRID, not with a list of templates. The
 * question it exists to answer is "which scenarios can we send in which
 * languages", and a bank that believes it has five-language cover and does not
 * will find out during an evacuation.
 *
 * ACTIVATING A TRANSLATION IS ITS OWN ACTION. A row awaiting review is inert —
 * `TemplateRenderer` will not send it and falls back to English — and flipping
 * `is_active` is what a reviewer does when the wording has been checked by
 * somebody who speaks the language. Nothing in this module machine-translates
 * emergency copy.
 */
class AlertTemplateController extends Controller
{
    public function __construct(
        private TemplateRenderer $renderer,
        private ProviderHealth $health,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.alert.view');

        return Inertia::render('Bcms/Emns/Templates', [
            'coverage' => $this->renderer->coverage(),
            'templates' => AlertTemplate::query()->orderBy('code')->orderBy('locale')->get()
                ->map(fn (AlertTemplate $t) => [
                    'id' => (int) $t->getKey(),
                    'code' => $t->code,
                    'name' => $t->name,
                    'category' => $t->category,
                    'locale' => $t->locale,
                    'severity' => $t->severity,
                    'subject' => $t->subject,
                    'body' => $t->body,
                    'is_active' => (bool) $t->is_active,
                    'is_life_safety' => (bool) $t->is_life_safety,
                    'is_system_default' => (bool) $t->is_system_default,
                    'requires_dual_approval' => (bool) $t->requires_dual_approval,
                    'whatsapp_template_name' => $t->whatsapp_template_name,
                    'channel_renderings' => $t->channel_renderings,
                    // What this body costs to send and why — the pasted curly
                    // apostrophe that tripled the bill is visible here.
                    'sms' => $this->renderer->inspect((string) $t->body),
                ])->all(),
            'severities' => $this->renderer->severities(),
            'can' => ['manage' => $request->user()?->can('bcms.alert.template.manage') === true],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.alert.template.manage');

        $data = $this->validated($request);

        AlertTemplate::query()->create(array_merge($data, [
            // A NEW TRANSLATION IS NEVER LIVE ON CREATION. It waits for a
            // reviewer who reads the language, because an evacuation
            // instruction that says the wrong thing in Hausa is a safety
            // incident rather than a formatting bug.
            'is_active' => $data['locale'] === 'en',
            'created_by' => $request->user()?->getKey(),
        ]));

        return back()->with(
            'success',
            $data['locale'] === 'en'
                ? 'Template created.'
                : 'Template created and held for review. It will not be sent until somebody who reads '
                    .ucfirst(TemplateRenderer::LOCALE_LABELS[$data['locale']] ?? $data['locale']).' activates it.',
        );
    }

    public function update(Request $request, AlertTemplate $template): RedirectResponse
    {
        Gate::authorize('bcms.alert.template.manage');

        if ($template->is_system_default) {
            throw ValidationException::withMessages([
                'body' => 'This is a shipped template. Copy it rather than editing it — a customer\'s '
                    .'edits would be overwritten by the next reference seed.',
            ]);
        }

        $template->update(array_merge($this->validated($request, partial: true), [
            'updated_by' => $request->user()?->getKey(),
        ]));

        return back()->with('success', 'Template updated.');
    }

    /** A reviewer who reads the language signs it off. */
    public function activate(Request $request, AlertTemplate $template): RedirectResponse
    {
        Gate::authorize('bcms.alert.template.manage');

        $template->update(['is_active' => ! $template->is_active, 'updated_by' => $request->user()?->getKey()]);

        $template->recordAudit($template->is_active ? 'template.activated' : 'template.deactivated', [
            'code' => $template->code,
            'locale' => $template->locale,
        ]);

        return back()->with('success', $template->is_active ? 'Template is live.' : 'Template withdrawn.');
    }

    /** Live SMS segment/cost feedback while somebody types. */
    public function inspect(Request $request): JsonResponse
    {
        Gate::authorize('bcms.alert.view');

        return response()->json($this->renderer->inspect((string) $request->string('body')));
    }

    public function providers(Request $request): Response
    {
        Gate::authorize('bcms.alert.view');

        return Inertia::render('Bcms/Emns/Providers', [
            'providers' => $this->health->dashboard(),
            'spend' => $this->health->spend(),
            'channels' => app(\App\Services\Bcms\Notification\ChannelRegistry::class)->states(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$rule, 'string', 'max:40'],
            'name' => [$rule, 'string', 'max:150'],
            'locale' => [$rule, 'string', 'in:'.implode(',', TemplateRenderer::LOCALES)],
            'body' => [$rule, 'string', 'max:4000'],
            'subject' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:40'],
            'severity' => ['nullable', 'string'],
            'is_life_safety' => ['nullable', 'boolean'],
            'requires_dual_approval' => ['nullable', 'boolean'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:120'],
            'channel_renderings' => ['nullable', 'array'],
        ]);
    }
}
