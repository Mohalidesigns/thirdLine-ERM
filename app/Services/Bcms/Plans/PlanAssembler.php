<?php

namespace App\Services\Bcms\Plans;

use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanSection;
use App\Support\Bcms\PlanBinding;
use App\Support\Bcms\PlanTemplates;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds a plan's sections from a template, and re-renders the bound ones.
 *
 * ASSEMBLY IS IDEMPOTENT AND NEVER DESTRUCTIVE. Running it again re-resolves
 * every binding, restamps `last_verified_at`, clears `needs_review` and leaves
 * every word a human wrote exactly where it was. A "regenerate" that discarded
 * the recovery procedure somebody spent a week writing would be used once.
 *
 * `is_overridden` IS THE HUMAN'S CLAIM ON A BOUND SECTION. A bound section
 * renders live data; if somebody edits its body, the edit is kept and the
 * section is marked overridden, and from then on assembly refreshes its
 * fingerprint — so drift is still detected and still flagged — but does not
 * touch the body. The alternative is either losing their work or never telling
 * them the source moved, and both are worse.
 */
class PlanAssembler
{
    public function __construct(private readonly SourceResolver $resolver) {}

    /**
     * Create a plan's sections from a template.
     *
     * @throws InvalidArgumentException when the template key is unknown
     */
    public function applyTemplate(Plan $plan, string $templateKey, ?int $userId = null): Plan
    {
        $template = PlanTemplates::find($templateKey);

        if ($template === null) {
            throw new InvalidArgumentException("Unknown plan template '{$templateKey}'.");
        }

        $this->assertEditable($plan);

        DB::transaction(function () use ($plan, $template, $userId) {
            $order = 0;

            foreach ($template['sections'] as $section) {
                $order += 10;

                // `firstOrNew`, not `create`: applying a template to a plan
                // that already has that section keeps the section. Templates
                // are applied more than once in practice — a plan built from
                // the departmental template and then given the payments
                // sections is a real thing a BC officer does.
                $row = PlanSection::query()->firstOrNew([
                    'plan_id' => $plan->getKey(),
                    'section_key' => $section['key'],
                ]);

                if ($row->exists) {
                    continue;
                }

                $row->fill([
                    'organization_id' => $plan->organization_id,
                    'title' => $section['title'],
                    'body' => $section['binding'] === null ? $section['guidance'] : null,
                    'sort_order' => $order,
                    'source_binding' => $section['binding'],
                ])->save();
            }

            $plan->forceFill([
                'iso_clause_ref' => $plan->iso_clause_ref ?? $template['clause'],
                'updated_by' => $userId ?? auth()->id(),
            ])->save();
        });

        return $plan->refresh();
    }

    /**
     * Re-render every bound section, and return what was resolved.
     *
     * @return array<string, array<string, mixed>> section key => resolved payload
     */
    public function assemble(Plan $plan, ?int $userId = null): array
    {
        // Refreshing clears `needs_review`, and on an approved version that
        // would let somebody dismiss a drift flag by pressing a button instead
        // of superseding the plan. An approved plan that has drifted needs a v2,
        // not a restamp.
        $this->assertEditable($plan);

        $resolved = [];

        foreach ($this->boundSections($plan) as $pair) {
            $resolved[$pair['section']->section_key] = $this->refresh($plan, $pair['section'], $pair['binding']);
        }

        if ($resolved !== []) {
            $plan->forceFill(['updated_by' => $userId ?? auth()->id()])->save();
        }

        return $resolved;
    }

    /**
     * Render one bound section without writing anything.
     *
     * The builder screen previews a binding before it is saved, and a preview
     * that stamped `last_verified_at` would let somebody clear a drift flag by
     * looking at it.
     *
     * @return array<string, mixed>
     */
    public function preview(Plan $plan, PlanBinding $binding): array
    {
        return $this->resolver->resolve($plan, $binding);
    }

    /**
     * What the plan renders RIGHT NOW, section by section, from live data.
     *
     * This is the draft view: the builder, the preview and the live viewer. An
     * approved plan is printed from `document()` instead — see the argument
     * there, which is the one that reconciles "bound sections show current
     * data" with "an approved version is immutable".
     *
     * @return list<array<string, mixed>>
     */
    public function render(Plan $plan): array
    {
        $sections = $plan->sections()->orderBy('sort_order')->orderBy('id')->get();
        $out = [];

        foreach ($sections as $section) {
            $binding = PlanBinding::fromJson($section->source_binding);

            $out[] = [
                'id' => $section->getKey(),
                'key' => $section->section_key,
                'title' => $section->title,
                'body' => $section->body,
                'sort_order' => (int) $section->sort_order,
                'is_bound' => $binding !== null,
                'is_overridden' => (bool) $section->is_overridden,
                'ai_generated' => (bool) $section->ai_generated,
                'needs_review' => (bool) $section->needs_review,
                'last_verified_at' => $section->last_verified_at?->toIso8601String(),
                'binding' => $binding?->toArray(),
                'live' => $binding === null ? null : $this->resolver->resolve($plan, $binding),
            ];
        }

        return $out;
    }

    /**
     * The document to print, download or carry offline.
     *
     * THIS IS WHERE THE PHASE'S TWO PROMISES ARE RECONCILED. Acceptance
     * criterion 2 says a bound section shows the CURRENT recovery objectives;
     * criterion 3 says an approved v1 is IMMUTABLE and stays printable. Both
     * cannot be true of one rendering, and a product that quietly picked one
     * would either print a plan that changes under an auditor or a plan that
     * lies about today.
     *
     * So they are two renderings of two different things. A DRAFT renders live,
     * because a draft is a working document and its whole purpose is to show
     * what the organisation looks like now. On approval the render is FROZEN
     * into `bcms_plans.content` — which is precisely what that column was
     * created for — and from then on the approved version prints exactly what
     * was approved, for ever. Drift is still detected against it, and what
     * drift produces is a review flag and a v2, not a silent edit.
     *
     * @return list<array<string, mixed>>
     */
    public function document(Plan $plan): array
    {
        $frozen = $plan->content['sections'] ?? null;

        if ($plan->isImmutable() && is_array($frozen) && $frozen !== []) {
            return array_values($frozen);
        }

        return $this->render($plan);
    }

    /**
     * Snapshot the current render, for storing on approval.
     *
     * `live` payloads are kept whole rather than flattened to text: the offline
     * bundle and the PDF both need the structure, and a frozen document that
     * had already been turned into prose could not be re-rendered as a table on
     * a phone.
     *
     * @return array<string, mixed>
     */
    public function freeze(Plan $plan): array
    {
        return [
            'frozen_at' => now()->toIso8601String(),
            'version' => $plan->version,
            'sections' => $this->render($plan),
        ];
    }

    /**
     * Refresh one bound section: re-resolve, restamp, clear the flag.
     *
     * @return array<string, mixed>
     */
    public function refresh(Plan $plan, PlanSection $section, PlanBinding $binding): array
    {
        $payload = $this->resolver->resolve($plan, $binding);

        $section->forceFill([
            'source_fingerprint' => $this->resolver->hash($payload),
            'last_verified_at' => now(),
            'needs_review' => false,
        ])->save();

        return $payload;
    }

    /**
     * Every bound section of a plan, paired with its parsed binding.
     *
     * A section whose stored binding no longer parses is SKIPPED, not thrown
     * on. One bad row written by an older version must not make a plan
     * unopenable during an event — the section renders as unbound, and the
     * builder screen shows it for repair.
     *
     * @return list<array{section: PlanSection, binding: PlanBinding}>
     */
    public function boundSections(Plan $plan): array
    {
        $pairs = [];

        foreach ($plan->sections()->orderBy('sort_order')->orderBy('id')->get() as $section) {
            if ($section->source_binding === null) {
                continue;
            }

            try {
                $binding = PlanBinding::fromJson($section->source_binding);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($binding !== null) {
                $pairs[] = ['section' => $section, 'binding' => $binding];
            }
        }

        return $pairs;
    }

    private function assertEditable(Plan $plan): void
    {
        if ($plan->isImmutable()) {
            throw new InvalidArgumentException(
                'An approved plan version cannot be edited. Supersede it with a new version instead — the '
                .'supersession chain is the history an auditor reads.'
            );
        }
    }
}
