<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\Aar;
use App\Services\Bcms\Ai\BcmsLlmClient;
use InvalidArgumentException;

/**
 * The AAR first draft — Blueprint §12 capability 4.
 *
 * THREE EXPLICIT REFUSALS BEYOND CRITERION 7 (clause map refinement 11):
 *
 *  1. It may not set `outcome` — that is `App\Enums\Bcms\ExerciseOutcome`,
 *     a judgement about whether the exercise passed, and this method never
 *     touches the occurrence at all.
 *  2. It may not call `FindingService::raise()`. It PROPOSES findings in its
 *     return value; a human decides whether to raise each one, through the
 *     existing `bcms.findings.store` route, exactly as a hand-typed one
 *     would be.
 *  3. Everything it produces carries `ai_generated = true` — on the AAR here,
 *     and on any finding a human later accepts from its suggestions (the
 *     caller's job, since raising is the caller's act).
 *
 * IT NEVER OVERWRITES A HUMAN'S ANSWER. `summary`, `what_worked` and
 * `what_failed` are only filled where blank — the same rule `BiaAiDrafter`
 * follows and for the same reason.
 */
class AarAiDrafter
{
    public function __construct(private BcmsLlmClient $llm) {}

    public function available(Aar $aar): bool
    {
        return $this->llm->available(BcmsLlmClient::AAR_SYNTHESIS, $aar->organization_id);
    }

    public function unavailableReason(Aar $aar): ?string
    {
        return $this->llm->unavailableReason(BcmsLlmClient::AAR_SYNTHESIS, $aar->organization_id);
    }

    /**
     * @return array{ok: bool, reason: ?string, filled: list<string>, suggested_findings: list<array<string, mixed>>}
     */
    public function draft(Aar $aar): array
    {
        // Gate 1 re-gate defect 6: this drafter runs exercise AI synthesis
        // under the always-on `AAR_SYNTHESIS` capability. A post-incident
        // review's own AI capability is `post_incident_learning`, OFF by
        // default (`config('bcms.ai.capabilities.post_incident_learning')`)
        // — no `PirAiDrafter` has been built. Calling this drafter on a PIR
        // silently ran exercise-context synthesis (`$this->context()` reads
        // `$aar->occurrence`, always null for a PIR) under a capability flag
        // the deployment never turned on for incident data.
        if ($aar->isPostIncident()) {
            throw new InvalidArgumentException(
                'AI drafting for a post-incident review runs under a separate capability '
                .'(post_incident_learning), which is not enabled in this deployment.'
            );
        }

        if ($aar->status === 'final') {
            throw new InvalidArgumentException('A final report cannot be redrafted. Reopen it first.');
        }

        $context = $this->context($aar);

        $result = $this->llm->json(
            BcmsLlmClient::AAR_SYNTHESIS,
            $this->prompt($context),
            $context,
            $aar->organization_id,
        );

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'filled' => [], 'suggested_findings' => []];
        }

        return $this->apply($aar, $result['data']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, reason: ?string, filled: list<string>, suggested_findings: list<array<string, mixed>>}
     */
    private function apply(Aar $aar, array $data): array
    {
        $filled = [];
        $attributes = [];

        foreach (['summary', 'what_worked', 'what_failed'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '' && trim((string) $aar->{$field}) === '') {
                $attributes[$field] = $value;
                $filled[] = $field;
            }
        }

        $suggestions = array_values(array_filter(array_map(function ($row) {
            if (! is_array($row) || trim((string) ($row['description'] ?? '')) === '') {
                return null;
            }

            return [
                'description' => (string) $row['description'],
                'classification' => in_array($row['classification'] ?? null, ['observation', 'improvement', 'nonconformity'], true)
                    ? $row['classification']
                    : 'observation',
                'root_cause' => $row['root_cause'] ?? null,
            ];
        }, (array) ($data['suggested_findings'] ?? []))));

        $attributes['ai_generated'] = true;
        $attributes['ai_draft_generated_at'] = now();

        $aar->forceFill($attributes)->save();

        return ['ok' => true, 'reason' => null, 'filled' => $filled, 'suggested_findings' => $suggestions];
    }

    /** @return array<string, mixed> */
    private function context(Aar $aar): array
    {
        $occurrence = $aar->occurrence;
        $definition = $occurrence?->definition;

        $timeline = $occurrence?->timeline()->orderBy('logged_at')->get() ?? collect();
        $scores = $occurrence?->scores()->get() ?? collect();

        return [
            'exercise' => $definition?->name,
            'exercise_type' => $definition?->exerciseType?->name,
            'outcome' => $occurrence?->outcome?->value,
            'timeline' => $timeline->map(fn ($t) => [
                'at' => $t->logged_at->toIso8601String(),
                'type' => $t->entry_type,
                'content' => $t->content,
            ])->all(),
            'scores' => $scores->map(fn ($s) => [
                'objective' => $s->objective_text,
                'score' => $s->score,
                'commentary' => $s->commentary,
            ])->all(),
            'quantitative_results' => $aar->quantitative_results,
        ];
    }

    /** @param array<string, mixed> $context */
    private function prompt(array $context): string
    {
        return <<<PROMPT
        You are drafting an after-action report for a Nigerian bank's business continuity exercise, under ISO
        22398 and ISO 22301 clause 8.5. Answer with JSON only.

        The exercise record:
        {$this->encode($context)}

        Return this shape:
        {
          "summary": "a short factual summary of what happened",
          "what_worked": "what worked, drawn from the timeline and scores",
          "what_failed": "what did not work, drawn from low scores and timeline entries",
          "suggested_findings": [ { "description": "...", "classification": "observation|improvement|nonconformity", "root_cause": "..." } ]
        }

        Do not state an outcome (pass/fail) — that is a human judgement. Do not invent numbers not present in
        the record. A suggested finding is a proposal only; it is not raised until a human accepts it.
        PROMPT;
    }

    /** @param array<string, mixed> $context */
    private function encode(array $context): string
    {
        return json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
