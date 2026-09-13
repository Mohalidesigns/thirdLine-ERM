<?php

namespace App\Services\Bcms\Bia;

use App\Enums\Bcms\BiaAssessmentStatus;
use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Models\Bcms\BiaAssessment;
use App\Services\Bcms\Ai\BcmsLlmClient;
use InvalidArgumentException;

/**
 * The BIA first draft — Blueprint §12 capability 1, and the first AI capability
 * in the module.
 *
 * WHAT IT PRODUCES IS A DRAFT AND NOTHING ELSE (standing rule 4). It sets
 * `ai_generated`, stamps `ai_drafted_at`, writes `ai_reasoning`, and leaves the
 * assessment in `in_progress`. It cannot submit and it cannot approve. Approval
 * requires a human who is not the assessor, and `approved_by` is the record that
 * one existed.
 *
 * IT SHOWS ITS WORKING OR ITS NUMBERS ARE WORTHLESS. `ai_reasoning` carries a
 * sentence per proposed value. A recovery time objective a model produced and
 * cannot justify is worse than a blank field: the blank field gets filled in by
 * somebody who thought about it, and the confident number gets accepted.
 *
 * THE CHALLENGE QUESTIONS ARE THE POINT AS MUCH AS THE NUMBERS. "You have
 * claimed a two-hour RTO but your only recovery route is a manual workaround
 * rated at four hours — which is wrong?" is worth more than a proposed figure,
 * because it makes the assessor look at the contradiction rather than at a
 * suggestion they can accept without reading.
 *
 * IT NEVER OVERWRITES A HUMAN'S ANSWER. Only fields the assessor has left empty
 * are filled; anything already recorded is kept and, where the model disagrees,
 * the disagreement goes into the challenge questions instead. A drafter that
 * quietly replaced a considered number would be the worst thing in the module.
 */
class BiaAiDrafter
{
    public function __construct(
        private BcmsLlmClient $llm,
        private MtpdDeriver $deriver,
    ) {}

    public function available(BiaAssessment $assessment): bool
    {
        return $this->llm->available(BcmsLlmClient::BIA_DRAFT, $assessment->organization_id);
    }

    public function unavailableReason(BiaAssessment $assessment): ?string
    {
        return $this->llm->unavailableReason(BcmsLlmClient::BIA_DRAFT, $assessment->organization_id);
    }

    /**
     * Draft what is missing.
     *
     * @return array{ok: bool, reason: ?string, filled: list<string>, questions: list<string>}
     */
    public function draft(BiaAssessment $assessment): array
    {
        if (! $assessment->status->isEditable()) {
            throw new InvalidArgumentException('Only an assessment still with its assessor can be drafted.');
        }

        $context = $this->context($assessment);

        $result = $this->llm->json(
            BcmsLlmClient::BIA_DRAFT,
            $this->prompt($context),
            $context,
            $assessment->organization_id,
        );

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'filled' => [], 'questions' => []];
        }

        return $this->applyDraft($assessment, $result['data']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, reason: ?string, filled: list<string>, questions: list<string>}
     */
    private function applyDraft(BiaAssessment $assessment, array $data): array
    {
        $filled = [];
        $reasoning = is_array($assessment->ai_reasoning) ? $assessment->ai_reasoning : [];

        $numeric = [
            'mtpd_hours' => 'mtpd_hours',
            'rto_hours' => 'rto_hours',
            'rpo_minutes' => 'rpo_minutes',
        ];

        $attributes = [];

        foreach ($numeric as $key => $column) {
            // Only what the assessor has left empty. A drafter that replaced a
            // considered number would be the worst thing in the module.
            if ($assessment->{$column} !== null || ! isset($data[$key]) || ! is_numeric($data[$key])) {
                continue;
            }

            $attributes[$column] = $data[$key];
            $filled[] = $column;
            $reasoning[$column] = $this->reasonFor($data, $key);
        }

        if (blank($assessment->mbco_description) && filled($data['mbco'] ?? null)) {
            $attributes['mbco_description'] = (string) $data['mbco'];
            $filled[] = 'mbco_description';
            $reasoning['mbco_description'] = $this->reasonFor($data, 'mbco');
        }

        $questions = array_values(array_filter(
            array_map('strval', (array) ($data['challenge_questions'] ?? [])),
            fn (string $q) => trim($q) !== ''
        ));

        if ($questions !== []) {
            $reasoning['challenge_questions'] = $questions;
        }

        $narratives = $this->applyNarratives($assessment, $data);
        $filled = array_merge($filled, $narratives);

        $reasoning['drafted_at'] = now()->toIso8601String();
        $reasoning['model'] = config('services.llm.model');

        $attributes['ai_generated'] = true;
        $attributes['ai_drafted_at'] = now();
        $attributes['ai_reasoning'] = $reasoning;

        if ($assessment->status === BiaAssessmentStatus::Draft) {
            $attributes['status'] = BiaAssessmentStatus::InProgress->value;
        }

        $assessment->forceFill($attributes)->save();
        $this->deriver->apply($assessment->refresh());

        return ['ok' => true, 'reason' => null, 'filled' => $filled, 'questions' => $questions];
    }

    /**
     * Impact narratives, for cells the assessor has not written one in.
     *
     * NARRATIVES ONLY — never a severity score. A severity is the assessor's
     * judgement of their own business and it is what the MTPD is derived from;
     * a model filling it in would be a model setting the recovery objectives
     * through the back door.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function applyNarratives(BiaAssessment $assessment, array $data): array
    {
        $written = [];

        foreach ((array) ($data['impacts'] ?? []) as $row) {
            $category = ImpactCategory::tryFrom((string) ($row['category'] ?? ''));
            $horizon = ImpactHorizon::tryFrom((string) ($row['horizon'] ?? ''));
            $narrative = trim((string) ($row['narrative'] ?? ''));

            if ($category === null || $horizon === null || $narrative === '') {
                continue;
            }

            $existing = $assessment->impacts()
                ->where('impact_category', $category->value)
                ->where('horizon', $horizon->value)
                ->first();

            if ($existing !== null && filled($existing->narrative)) {
                continue;
            }

            $assessment->impacts()->updateOrCreate(
                ['impact_category' => $category->value, 'horizon' => $horizon->value],
                ['narrative' => $narrative]
            );

            $written[] = "impact:{$category->value}:{$horizon->value}";
        }

        return $written;
    }

    /** @param array<string, mixed> $data */
    private function reasonFor(array $data, string $key): string
    {
        $reasons = (array) ($data['reasoning'] ?? []);
        $reason = trim((string) ($reasons[$key] ?? ''));

        // A number with no stated reason is recorded as having none, rather
        // than dressed up with a generic sentence. The screen shows the gap.
        return $reason !== '' ? $reason : 'The model proposed this value without giving a reason.';
    }

    /** @return array<string, mixed> */
    private function context(BiaAssessment $assessment): array
    {
        $process = $assessment->process;

        $dependencies = $assessment->dependencies()->get()->map(fn ($d) => [
            'type' => $d->type()?->label(),
            'name' => $d->dependableLabel(),
            'criticality' => $d->criticality,
            'single_point_of_failure' => (bool) $d->single_point_of_failure,
            'alternative_available' => (bool) $d->alternative_available,
        ])->all();

        return [
            'process' => $process?->name,
            'description' => $process?->description,
            'business_unit' => $process?->businessUnit?->name,
            'is_critical_service' => (bool) $process?->is_critical_service,
            'regulatory_flags' => $process === null ? [] : ($process->regulatory_flags ?? []),
            'dependencies' => $dependencies,
            'already_answered' => array_filter([
                'mtpd_hours' => $assessment->mtpd_hours,
                'rto_hours' => $assessment->rto_hours,
                'rpo_minutes' => $assessment->rpo_minutes,
                'mbco' => $assessment->mbco_description,
                'workaround_available' => $assessment->workaround_available,
                'workaround_max_duration_hours' => $assessment->workaround_max_duration_hours,
            ], fn ($v) => $v !== null),
        ];
    }

    /** @param array<string, mixed> $context */
    private function prompt(array $context): string
    {
        $horizons = implode(', ', array_map(fn (ImpactHorizon $h) => $h->value, ImpactHorizon::cases()));
        $categories = implode(', ', array_map(fn (ImpactCategory $c) => $c->value, ImpactCategory::cases()));

        return <<<PROMPT
        You are assisting a Nigerian bank's business continuity team with a business impact analysis
        under ISO 22301 clause 8.2.2. Answer with JSON only.

        The process:
        {$this->encode($context)}

        Propose ONLY the values that are missing from "already_answered". Never contradict a value that
        is already there — if you disagree with one, raise it as a challenge question instead.

        Return this shape:
        {
          "mtpd_hours": number,
          "rto_hours": number,
          "rpo_minutes": number,
          "mbco": "the minimum service that must keep running, in words, not numbers",
          "reasoning": { "mtpd_hours": "why", "rto_hours": "why", "rpo_minutes": "why", "mbco": "why" },
          "impacts": [ { "category": "one of: {$categories}", "horizon": "one of: {$horizons}", "narrative": "what the impact looks like at this point" } ],
          "challenge_questions": [ "a question that makes the assessor confront a contradiction in what they have recorded" ]
        }

        Do not propose an impact severity score. That is the assessor's judgement of their own business.
        Recovery time must never exceed the maximum tolerable period of disruption.
        Where the process is flagged for open banking, note that the CBN sets a 30-minute failover threshold.
        PROMPT;
    }

    /** @param array<string, mixed> $context */
    private function encode(array $context): string
    {
        return json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
