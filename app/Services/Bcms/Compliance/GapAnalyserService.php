<?php

namespace App\Services\Bcms\Compliance;

use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The ISO 22301 / CBN gap analyser — Blueprint §12 capability 5,
 * phase-11-spec's own scope note, `docs/bcms/screens/
 * compliance-evidence-matrix.md`'s AI panel.
 *
 * DRAFT ONLY, AND IT CANNOT MARK A CLAUSE GREEN (criterion 9's own
 * guardrail, restated as this class's contract). It reads the SAME computed
 * matrix the screen renders — it does not run a second scoring pass — and
 * turns every red/amber MANDATORY row into a citation naming the org node
 * and the elapsed time, because "clause 8.5 is not fully met" is a generic
 * statement and "no functional exercise has been run for the three Tier-1
 * processes at Kano branch in 14 months" is a finding.
 *
 * `ai_generated = false` (B8, gate 1 code review #1, orchestrator decision).
 * THIS IS A DETERMINISTIC, TEMPLATED DRAFT, NOT AN LLM CALL, so it does not
 * claim `ai_generated = true` — that would be a false provenance label on a
 * citation this class computes from stored matrix rows with no model call
 * anywhere in the path. Routing it through `BcmsLlmClient` for a softer,
 * more examiner-readable phrasing is a legitimate follow-up, recorded as
 * DEFERRED in `docs/bcms/phase-11-notes.md` pending an owner decision — not
 * built here, and not mislabelled as already AI-authored in the meantime.
 */
class GapAnalyserService
{
    public function __construct(private readonly ClauseComplianceMatrixService $matrix) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function draftFindings(): array
    {
        $built = $this->matrix->build();

        if ($built['empty_programme'] ?? true) {
            return [];
        }

        $orgName = \App\Models\Organization::query()->find(TenantContext::organizationId())->name ?? 'This organisation';

        $rows = collect($built['sections'])->flatten(1)
            ->filter(fn (array $r) => $r['mandatory'] && in_array($r['state'], ['red', 'amber'], true));

        return $rows->map(function (array $r) use ($orgName) {
            $elapsed = $r['last_evidenced'] === null
                ? 'no artefact has ever been recorded'
                : 'the last artefact was recorded on '.$r['last_evidenced'];

            return [
                'clause_ref' => $r['code'],
                'title' => $r['title'],
                'state' => $r['state'],
                'org_node' => $orgName,
                'finding' => sprintf(
                    'Clause %s (%s) at %s: %s — %s.',
                    $r['code'], $r['title'], $orgName, $r['artefact'], $elapsed,
                ),
                // B8 (gate 1 code review #1, orchestrator decision): this
                // class is a deterministic, templated citation over computed
                // matrix rows — never an LLM call (see the class docblock).
                // `ai_generated = true` claimed provenance this class does
                // not have. Routing it through `BcmsLlmClient` for softer
                // phrasing is a legitimate follow-up, named in
                // phase-11-notes.md as DEFERRED, not built here.
                'ai_generated' => false,
            ];
        })->values()->all();
    }
}
