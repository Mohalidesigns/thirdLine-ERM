<?php

namespace App\Services\Bcms\Plans;

use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanSection;
use App\Services\Bcms\Ai\BcmsLlmClient;
use App\Support\Bcms\PlanBinding;
use InvalidArgumentException;

/**
 * AI plan drafting and refresh — Blueprint §12 capability 3.
 *
 * IT WRITES PROSE, NEVER DATA (standing rule 4, and the sharper version of it).
 * The bound sections are already correct — they are the BIA, the strategies,
 * the call tree — and a model has nothing to add to a recovery time objective
 * except doubt. What a BC officer actually spends a week on is the narrative:
 * the activation criteria, the recovery procedure, the return to normal. That
 * is what this drafts, and it drafts it FROM the bound data, so the words and
 * the tables in the same document say the same thing.
 *
 * IT LANDS IN DRAFT AND CANNOT SELF-APPROVE. `ai_generated` is stamped on every
 * section it writes and on the plan; approval requires a human who is not the
 * author, and `PlanService::approve()` enforces that whatever wrote the words.
 *
 * IT NEVER OVERWRITES WHAT A HUMAN WROTE. Only sections still holding their
 * template guidance, or empty, are filled. A section somebody has edited is
 * left alone and, where the model had something to say about it, the suggestion
 * is returned as a proposal rather than applied — which is also what the
 * "material change" path does: it FLAGS the affected sections and proposes the
 * edit, it does not make it.
 */
class PlanAiDrafter
{
    public function __construct(
        private readonly BcmsLlmClient $llm,
        private readonly PlanAssembler $assembler,
    ) {}

    public function available(Plan $plan): bool
    {
        return $this->llm->available(BcmsLlmClient::PLAN_DRAFT, $plan->organization_id);
    }

    public function unavailableReason(Plan $plan): ?string
    {
        return $this->llm->unavailableReason(BcmsLlmClient::PLAN_DRAFT, $plan->organization_id);
    }

    /**
     * Draft the free-text sections of a plan from its bound data.
     *
     * @return array{ok: bool, reason: ?string, written: list<string>, proposed: array<string, string>}
     */
    public function draft(Plan $plan): array
    {
        if ($plan->isImmutable()) {
            throw new InvalidArgumentException(
                'An approved plan version cannot be drafted over. Supersede it and draft the new version.'
            );
        }

        $context = $this->context($plan);

        if ($context['free_sections'] === []) {
            return [
                'ok' => false,
                'reason' => 'This plan has no free-text sections to draft. Every section is bound to live data, '
                    .'which is already up to date.',
                'written' => [],
                'proposed' => [],
            ];
        }

        $result = $this->llm->json(
            BcmsLlmClient::PLAN_DRAFT,
            $this->prompt($context),
            ['plan_id' => $plan->getKey()],
            $plan->organization_id,
        );

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'written' => [], 'proposed' => []];
        }

        return $this->apply($plan, $result['data']);
    }

    /**
     * Propose edits for the sections that have drifted, without applying any.
     *
     * THE PROMPT ASKS FOR THIS EXPLICITLY: "on material change, flag the
     * affected plan sections and propose the edit — do not apply it." A drifted
     * section's live table has already corrected itself; what has not corrected
     * itself is the paragraph beneath it that describes the old arrangement,
     * and that paragraph is somebody's writing.
     *
     * @return array{ok: bool, reason: ?string, proposals: list<array<string, mixed>>}
     */
    public function proposeForDrift(Plan $plan): array
    {
        $drifted = $plan->sections()->where('needs_review', true)->orderBy('sort_order')->get();

        if ($drifted->isEmpty()) {
            return ['ok' => true, 'reason' => null, 'proposals' => []];
        }

        $context = $this->context($plan);
        $context['drifted_sections'] = $drifted->map(fn (PlanSection $s) => [
            'key' => $s->section_key,
            'title' => $s->title,
            'body' => $s->body,
        ])->all();

        $result = $this->llm->json(
            BcmsLlmClient::PLAN_DRAFT,
            $this->driftPrompt($context),
            ['plan_id' => $plan->getKey()],
            $plan->organization_id,
        );

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'proposals' => []];
        }

        $proposals = [];

        foreach ((array) ($result['data']['proposals'] ?? []) as $proposal) {
            $key = (string) ($proposal['section_key'] ?? '');
            $section = $drifted->firstWhere('section_key', $key);

            if ($section === null || blank($proposal['proposed_body'] ?? null)) {
                continue;
            }

            $proposals[] = [
                'section_key' => $key,
                'title' => $section->title,
                'current_body' => $section->body,
                'proposed_body' => (string) $proposal['proposed_body'],
                'reason' => (string) ($proposal['reason'] ?? ''),
            ];
        }

        return ['ok' => true, 'reason' => null, 'proposals' => $proposals];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, reason: ?string, written: list<string>, proposed: array<string, string>}
     */
    private function apply(Plan $plan, array $data): array
    {
        $written = [];
        $proposed = [];

        $sections = $plan->sections()->get()->keyBy('section_key');

        foreach ((array) ($data['sections'] ?? []) as $draft) {
            $key = (string) ($draft['section_key'] ?? '');
            $body = trim((string) ($draft['body'] ?? ''));
            $section = $sections->get($key);

            if ($section === null || $body === '' || $section->source_binding !== null) {
                continue;
            }

            // A section a human has written is not overwritten. Where the model
            // had something to say about one, it comes back as a proposal the
            // author can take or leave.
            if ($this->hasHumanContent($section)) {
                $proposed[$key] = $body;

                continue;
            }

            $section->update(['body' => $body, 'ai_generated' => true]);
            $written[] = $key;
        }

        if ($written !== []) {
            $plan->update(['ai_generated' => true]);
        }

        return [
            'ok' => true,
            'reason' => $written === [] && $proposed === []
                ? 'The model did not produce anything usable for this plan\'s sections.'
                : null,
            'written' => $written,
            'proposed' => $proposed,
        ];
    }

    /**
     * Whether a human has written this section.
     *
     * A section still holding exactly its template guidance has not been
     * written; anything else has. Comparing against the template rather than
     * checking for emptiness is what lets a freshly templated plan be drafted
     * at all, since `applyTemplate()` seeds every free section with its
     * guidance text.
     */
    private function hasHumanContent(PlanSection $section): bool
    {
        if (blank($section->body)) {
            return false;
        }

        if ($section->ai_generated) {
            return false;
        }

        return ! $this->isTemplateGuidance($section);
    }

    private function isTemplateGuidance(PlanSection $section): bool
    {
        foreach (\App\Support\Bcms\PlanTemplates::all() as $template) {
            foreach ($template['sections'] as $candidate) {
                if ($candidate['key'] === $section->section_key
                    && trim($candidate['guidance']) === trim((string) $section->body)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function context(Plan $plan): array
    {
        $free = [];
        $bound = [];

        foreach ($plan->sections()->orderBy('sort_order')->orderBy('id')->get() as $section) {
            $binding = $section->source_binding === null ? null : PlanBinding::fromJson($section->source_binding);

            if ($binding === null) {
                $free[] = [
                    'key' => $section->section_key,
                    'title' => $section->title,
                    'guidance' => $section->ai_generated || blank($section->body) ? $section->body : null,
                    'already_written' => $this->hasHumanContent($section),
                ];

                continue;
            }

            $bound[] = [
                'key' => $section->section_key,
                'title' => $section->title,
                'source' => $binding->source->value,
                // The live data, capped. A model given four hundred rows of
                // recovery objectives writes about the rows; one given the top
                // twenty writes about the organisation.
                'rows' => array_slice($this->assembler->preview($plan, $binding)['rows'], 0, 20),
            ];
        }

        return [
            'plan' => [
                'title' => $plan->title,
                'type' => $plan->plan_type->label(),
                'version' => $plan->version,
            ],
            'free_sections' => $free,
            'bound_sections' => $bound,
        ];
    }

    /** @param array<string, mixed> $context */
    private function prompt(array $context): string
    {
        return <<<PROMPT
        You are drafting a business continuity plan for a Nigerian financial institution, working to
        ISO 22301 and the Central Bank of Nigeria's expectations.

        The plan's factual sections — recovery objectives, dependencies, strategies, call trees, sites
        and vendors — are already populated from the institution's own live records and are given to you
        below as `bound_sections`. Do not restate them and do not contradict them. Your job is the prose
        sections listed in `free_sections`, written so that they are consistent with those facts.

        Write in plain British English, in the second or third person, in short paragraphs. Be specific
        and operational: somebody should be able to follow what you write at three in the morning without
        having drafted it. Where the facts below do not tell you something the section needs — a named
        role, a threshold, a location — write a clearly marked placeholder in square brackets rather than
        inventing it.

        Return JSON of the form:
        {"sections": [{"section_key": "...", "body": "..."}]}

        Only include sections whose `section_key` appears in `free_sections`.

        CONTEXT:
        {$this->json($context)}
        PROMPT;
    }

    /** @param array<string, mixed> $context */
    private function driftPrompt(array $context): string
    {
        return <<<PROMPT
        A business continuity plan's underlying data has changed since its prose was written. The bound
        sections below now show the CURRENT position. The sections in `drifted_sections` contain prose
        that may no longer match it.

        For each drifted section, decide whether its wording is now wrong or misleading given the current
        data. If it is, propose a revised body and say in one sentence what changed. If the existing
        wording is still correct, leave that section out of your answer entirely — a proposal that changes
        nothing wastes a reviewer's time and teaches them to ignore the next one.

        Do not invent facts. Do not soften a problem: if the current data shows a recovery time the plan
        can no longer meet, say so in the proposed wording.

        Return JSON of the form:
        {"proposals": [{"section_key": "...", "proposed_body": "...", "reason": "..."}]}

        CONTEXT:
        {$this->json($context)}
        PROMPT;
    }

    /** @param array<string, mixed> $context */
    private function json(array $context): string
    {
        return (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
