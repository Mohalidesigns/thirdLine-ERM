<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\LadderLevel;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Process;
use App\Services\Bcms\Ai\BcmsLlmClient;
use Illuminate\Support\Carbon;

/**
 * The Exercise Programme Advisor — Blueprint §12 capability 7.
 *
 * "These six Tier-1 processes have not had a functional test in eighteen
 * months; propose this 2027 calendar."
 *
 * THE EVIDENCE IS COMPUTED, THE PROSE IS NOT. Which processes are under-tested,
 * how long since each was last exercised and at what rung, and which regulatory
 * cadences are unmet are all questions this module can answer exactly — so it
 * does, in `gaps()`, with no model involved. A bank can run the advisor with AI
 * switched off entirely and still get the list; what the model adds is the
 * proposed programme's shape and the sentence explaining it.
 *
 * That split is deliberate and it is the difference between an AI feature a
 * risk function will sign off and one they will not. Nothing a regulator sees
 * is a model's arithmetic.
 *
 * IT PROPOSES DEFINITIONS IN DRAFT AND NOTHING ELSE. No occurrence is
 * generated, no programme is approved, and every proposed definition is marked
 * `ai_generated` at the point a human accepts it. Standing rule 4.
 */
class ProgrammeAdvisor
{
    /** A Tier-1 process untested above a walkthrough for longer than this is a gap. */
    public const STALE_MONTHS = 18;

    public function __construct(
        private readonly BcmsLlmClient $llm,
        private readonly LadderAdvisor $ladder,
    ) {}

    public function available(ExerciseProgramme $programme): bool
    {
        return $this->llm->available(BcmsLlmClient::PROGRAMME_ADVISOR, $programme->organization_id);
    }

    public function unavailableReason(ExerciseProgramme $programme): ?string
    {
        return $this->llm->unavailableReason(BcmsLlmClient::PROGRAMME_ADVISOR, $programme->organization_id);
    }

    /**
     * The evidence, computed. Available with AI switched off.
     *
     * @return array<string, mixed>
     */
    public function gaps(ExerciseProgramme $programme): array
    {
        $processes = Process::query()
            ->where('status', 'active')
            ->whereNotNull('criticality_tier')
            ->where('criticality_tier', '<=', 2)
            ->orderByRaw('COALESCE(criticality_tier, 99)')
            ->orderBy('code')
            ->get();

        $matrix = $this->ladder->coverageMatrix($processes);
        $stale = [];
        $never = [];

        foreach ($matrix as $row) {
            if ($row['never_exercised']) {
                $never[] = $row;

                continue;
            }

            $lastMeaningful = null;

            foreach ($row['levels'] as $level => $entry) {
                $case = LadderLevel::tryFrom($level);

                if ($case !== null && $case->isAtOrAbove(LadderLevel::Drill) && ($entry['last_at'] ?? null) !== null) {
                    $lastMeaningful = max($lastMeaningful, $entry['last_at']);
                }
            }

            if ($lastMeaningful === null || Carbon::parse($lastMeaningful)->lt(now()->subMonths(self::STALE_MONTHS))) {
                $stale[] = $row + ['last_meaningful_test' => $lastMeaningful];
            }
        }

        $unmet = $this->unmetCadences($programme);

        return [
            'never_exercised' => $never,
            'stale' => $stale,
            'unmet_cadences' => $unmet,
            'coverage' => $matrix,
            // The sentence the screen leads with, computed rather than written
            // by a model — so it is the same whether or not AI is switched on.
            'headline' => $this->headline($never, $stale, $unmet),
        ];
    }

    /**
     * Ask a model to shape a year's programme around the computed gaps.
     *
     * @return array{ok: bool, reason: ?string, proposals: list<array<string, mixed>>, rationale: ?string}
     */
    public function advise(ExerciseProgramme $programme): array
    {
        $gaps = $this->gaps($programme);

        $reason = $this->unavailableReason($programme);

        if ($reason !== null) {
            return ['ok' => false, 'reason' => $reason, 'proposals' => [], 'rationale' => null];
        }

        $types = ExerciseType::query()
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'ladder_level', 'default_frequency_per_year', 'cadence_clause_ref']);

        $result = $this->llm->json(
            BcmsLlmClient::PROGRAMME_ADVISOR,
            $this->prompt($programme, $gaps, $types),
            ['programme_id' => $programme->getKey()],
            $programme->organization_id,
        );

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'proposals' => [], 'rationale' => null];
        }

        $byCode = $types->keyBy('code');
        $proposals = [];

        foreach ((array) ($result['data']['definitions'] ?? []) as $proposed) {
            $type = $byCode->get((string) ($proposed['exercise_type_code'] ?? ''));

            // A proposal naming a type this tenant does not have is dropped
            // rather than created against a guess. A model inventing an
            // exercise type is exactly the failure standing rule 4 is about.
            if ($type === null) {
                continue;
            }

            $proposals[] = [
                'exercise_type_id' => $type->id,
                'exercise_type_code' => $type->code,
                'exercise_type_name' => $type->name,
                'name' => (string) ($proposed['name'] ?? $type->name),
                'frequency_per_year' => max(1, min(52, (int) ($proposed['frequency_per_year'] ?? $type->default_frequency_per_year))),
                'process_codes' => array_values(array_map('strval', (array) ($proposed['process_codes'] ?? []))),
                'why' => (string) ($proposed['why'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'proposals' => $proposals,
            'rationale' => isset($result['data']['rationale']) ? (string) $result['data']['rationale'] : null,
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Regulatory cadences the programme does not currently meet.
     *
     * @return list<array<string, mixed>>
     */
    private function unmetCadences(ExerciseProgramme $programme): array
    {
        $declared = $programme->definitions()
            ->where('status', '!=', 'retired')
            ->with('exerciseType:id,code,name,cadence_clause_ref,default_frequency_per_year')
            ->get();

        $byClause = [];

        foreach ($declared as $definition) {
            $clause = $definition->exerciseType?->cadence_clause_ref;

            if (blank($clause)) {
                continue;
            }

            $byClause[$clause] = ($byClause[$clause] ?? 0) + (int) $definition->frequency_per_year;
        }

        $unmet = [];

        foreach (ExerciseType::query()->whereNotNull('cadence_clause_ref')->where('is_active', true)->get() as $type) {
            $clause = (string) $type->cadence_clause_ref;
            $have = $byClause[$clause] ?? 0;
            $need = (int) $type->default_frequency_per_year;

            if ($have >= $need) {
                continue;
            }

            $unmet[] = [
                'clause' => $clause,
                'exercise_type_code' => $type->code,
                'exercise_type_name' => $type->name,
                'required_per_year' => $need,
                'declared_per_year' => $have,
                'shortfall' => $need - $have,
            ];
        }

        return $unmet;
    }

    /**
     * @param  list<array<string, mixed>>  $never
     * @param  list<array<string, mixed>>  $stale
     * @param  list<array<string, mixed>>  $unmet
     */
    private function headline(array $never, array $stale, array $unmet): string
    {
        $parts = [];

        if ($never !== []) {
            $parts[] = count($never).' critical processes have never been exercised';
        }

        if ($stale !== []) {
            $parts[] = count($stale).' have not been tested above a walkthrough in '.self::STALE_MONTHS.' months';
        }

        if ($unmet !== []) {
            $parts[] = count($unmet).' regulatory cadences are not met by the current programme';
        }

        return $parts === []
            ? 'Every critical process has been exercised recently and every regulatory cadence is declared. '
                .'There is nothing this advisor would add.'
            : ucfirst(implode('; ', $parts)).'.';
    }

    /** @param array<string, mixed> $gaps */
    private function prompt(ExerciseProgramme $programme, array $gaps, mixed $types): string
    {
        $context = json_encode([
            'year' => $programme->year,
            'never_exercised' => array_map(fn (array $r) => [
                'code' => $r['code'], 'name' => $r['name'], 'tier' => $r['tier'],
            ], $gaps['never_exercised']),
            'stale' => array_map(fn (array $r) => [
                'code' => $r['code'], 'name' => $r['name'], 'tier' => $r['tier'],
                'highest_proven' => $r['highest_proven'], 'last_meaningful_test' => $r['last_meaningful_test'] ?? null,
            ], $gaps['stale']),
            'unmet_cadences' => $gaps['unmet_cadences'],
            'available_exercise_types' => $types,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        You are helping a Nigerian bank plan its {$programme->year} business continuity exercise programme,
        working to ISO 22301 clause 8.5 and ISO 22398.

        The gaps below were computed from the bank's own records — which processes have never been
        exercised, which have not been tested meaningfully in eighteen months, and which regulatory
        cadences the declared programme does not meet. Do not recalculate them and do not contradict
        them.

        Propose a set of exercise definitions that closes those gaps. Respect the ISO 22398 ladder: a
        process that has never had a tabletop should get a tabletop before a functional exercise, not
        instead of one. Prefer fewer, better-targeted exercises to a full calendar nobody will run —
        a programme a bank abandons in March is worth less than three exercises it completes.

        Use only `exercise_type_code` values from `available_exercise_types`. Reference processes by the
        `code` values given.

        Return JSON of the form:
        {"rationale": "...",
         "definitions": [{"exercise_type_code": "...", "name": "...", "frequency_per_year": 2,
                          "process_codes": ["..."], "why": "..."}]}

        CONTEXT:
        {$context}
        PROMPT;
    }
}
