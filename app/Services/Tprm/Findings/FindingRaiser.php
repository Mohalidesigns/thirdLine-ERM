<?php

namespace App\Services\Tprm\Findings;

use App\Enums\Tprm\ComplianceLevel;
use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\Soc2Exception;
use App\Services\Tprm\Contracts\ClauseResolver;
use App\Services\Tprm\Evidence\ScopeMatcher;

/**
 * The four stubs from Phases 2, 3 and 4, wired to real findings.
 *
 * Until now each of those phases produced a gap with nowhere to go: a
 * non-compliant answer sat in an assessment, a SOC 2 exception sat in
 * `tp_soc2_exceptions`, a clause gap sat on a report, a scope mismatch sat in
 * a banner. Each was correct and none of them was owned by anybody or moved
 * any score.
 *
 * EVERY RAISE IS IDEMPOTENT ON ITS SOURCE, and that matters more here than
 * anywhere else in the module. Re-scoring an assessment, re-running a clause
 * analysis or re-confirming a corrected SOC 2 extraction are all ordinary
 * things to do twice, and a register that duplicated its findings on the
 * second run would double the residual score for a reason nobody could see.
 * `FindingService::raise()` carries that guarantee; this class only decides
 * what to raise.
 *
 * SEVERITY IS PROPOSED FROM THE SOURCE'S OWN JUDGEMENT WHERE IT HAS ONE. A
 * question carries `risk_weight` and `is_critical`; a clause carries
 * `is_blocking`; a SOC 2 exception carries whatever the auditor said. Where
 * none of them has a view, the default is Medium rather than High — a tool
 * that opens at High gets its severities edited down until nobody reads them.
 */
class FindingRaiser
{
    public function __construct(private readonly FindingService $findings) {}

    /**
     * Findings from a scored assessment's non-compliant answers — FR-ASM-09.
     *
     * Partial answers raise findings too, at a lower severity. A control that
     * half works is a gap; treating only outright failures as findings is how
     * a register ends up showing three findings against a vendor whose
     * assessment scored 0.55.
     *
     * @return \Illuminate\Support\Collection<int, Finding>
     */
    public function fromAssessment(Assessment $assessment, ?int $userId = null)
    {
        $engagement = $assessment->engagement;

        if ($engagement === null) {
            return collect();
        }

        $responses = AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->whereIn('compliance', [
                ComplianceLevel::NonCompliant->value,
                ComplianceLevel::Partial->value,
            ])
            ->with('question.controlMaps')
            ->get();

        $raised = collect();

        foreach ($responses as $response) {
            $question = $response->question;

            if ($question === null) {
                continue;
            }

            $severity = $this->assessmentSeverity($response, $question);

            $raised->push($this->findings->raise(
                $engagement,
                'assessment',
                $severity,
                sprintf('%s — %s', $question->code, $this->shorten($question->text)),
                [
                    'source_id' => $question->getKey(),
                    'description' => sprintf(
                        "The vendor answered this question as %s in %s.\n\nQuestion: %s%s",
                        strtolower($response->compliance->label()),
                        $assessment->cycle_label ?? 'the assessment',
                        $question->text,
                        filled($response->vendor_comment) ? "\n\nVendor's comment: ".$response->vendor_comment : '',
                    ),
                    'control_refs' => $question->controlMaps
                        ->map(fn ($map) => $map->framework.' '.$map->control_id)
                        ->values()->all(),
                    'identified_at' => $assessment->validated_at ?? now(),
                ],
                $userId,
            ));
        }

        return $raised;
    }

    /**
     * A finding per SOC 2 Section 4 exception — FR-EVD-05.
     *
     * The proposal set Phase 3 generated and could not apply. The severity is
     * the auditor's where they gave one and Medium otherwise: a single
     * exception in a sample of forty is not automatically a critical failure,
     * and a tool that says it is gets ignored.
     *
     * @return \Illuminate\Support\Collection<int, Finding>
     */
    public function fromSoc2(Soc2Detail $soc2, ?int $userId = null)
    {
        $soc2->loadMissing(['document', 'exceptions']);

        $engagement = $this->engagementFor($soc2->document);

        if ($engagement === null) {
            return collect();
        }

        return $soc2->exceptions->map(fn (Soc2Exception $exception) => $this->findings->raise(
            $engagement,
            'evidence',
            FindingSeverity::tryFrom(strtolower((string) $exception->severity_assessment)) ?? FindingSeverity::Medium,
            'SOC 2 exception: '.($exception->control_reference ?: 'unreferenced control'),
            [
                'source_id' => $exception->getKey(),
                'description' => sprintf(
                    "%s\n\nPopulation: %s. Exceptions noted: %s.%s",
                    $exception->description,
                    $exception->population ?: 'not stated',
                    $exception->exceptions_noted ?: 'not stated',
                    filled($exception->management_response)
                        ? "\n\nManagement's response: ".$exception->management_response
                        : '',
                ),
                'control_refs' => array_values(array_filter([$exception->control_reference])),
                'regulatory_citation' => sprintf(
                    'Section 4 of the SOC 2 report for the period ending %s',
                    $soc2->period_end?->toDateString() ?? 'not stated',
                ),
            ],
            $userId,
        ));
    }

    /**
     * A finding per contract clause gap — FR-CTR-05.
     *
     * BLOCKING CLAUSES RAISE HIGH, others Medium. A blocking gap is one that
     * stops the engagement going live, so it is not a Medium by any reading —
     * but it is not automatically Critical either, because the activation gate
     * is already refusing it and a Critical severity would double-count the
     * same fact in the residual score.
     *
     * @return \Illuminate\Support\Collection<int, Finding>
     */
    public function fromClauseGaps(Contract $contract, ?int $userId = null)
    {
        $contract->loadMissing('engagement');
        $engagement = $contract->engagement;

        if ($engagement === null) {
            return collect();
        }

        $resolution = app(ClauseResolver::class)->resolve($engagement, $contract);
        $raised = collect();

        foreach ($resolution->gaps() as $gap) {
            /** @var ClauseLibraryEntry $clause */
            $clause = $gap['clause'];
            $determination = $gap['determination'];

            // A waived gap raises no finding. The exception is already
            // recorded on the override register with an approver and an
            // expiry, and raising a finding as well would report the same
            // accepted position twice in two places with two owners.
            if ($determination?->hasLiveWaiver() === true) {
                continue;
            }

            $raised->push($this->findings->raise(
                $engagement,
                'contract',
                $clause->is_blocking ? FindingSeverity::High : FindingSeverity::Medium,
                sprintf('Contract clause missing: %s', $clause->title),
                [
                    'source_id' => $clause->getKey(),
                    'description' => sprintf(
                        '%s (%s) is %s in %s.%s%s',
                        $clause->code,
                        $clause->citation ?: 'no citation recorded',
                        $determination?->presence->value === 'partial'
                            ? 'addressed but stops short of the obligation'
                            : 'absent',
                        $contract->reference,
                        filled($clause->guidance) ? "\n\n".$clause->guidance : '',
                        $clause->is_blocking
                            ? "\n\nThis clause is a condition of activation: the engagement cannot go live "
                                .'while it is missing, unless it is waived.'
                            : '',
                    ),
                    'control_refs' => [$clause->code],
                    'regulatory_citation' => $clause->citation,
                ],
                $userId,
            ));
        }

        return $raised;
    }

    /**
     * A finding where a certificate's scope does not name the service —
     * FR-DDL-07.
     *
     * The one Phase 3 could only put in a banner. It is Medium rather than
     * High: the certificate may well cover the service in words the comparison
     * could not match, and the finding is a question put to a human, which is
     * what the reason text says.
     */
    public function fromScopeMismatch(Document $document, Engagement $engagement, ?int $userId = null): ?Finding
    {
        $check = app(ScopeMatcher::class)->check($document, $engagement);

        if (! $check['mismatch']) {
            return null;
        }

        return $this->findings->raise(
            $engagement,
            'evidence',
            FindingSeverity::Medium,
            sprintf('Certificate scope may not cover this service: %s', $this->shorten($document->title)),
            [
                'source_id' => $document->getKey(),
                'description' => $check['reason']."\n\nConfirm with the provider whether the certificate covers "
                    .'this service. If it does not, the confidence in every answer it evidences is reduced by a '
                    .'factor of '.$check['modifier'].'.',
            ],
            $userId,
        );
    }

    /* ------------------------------------------------------------------ */

    private function assessmentSeverity(AssessmentResponse $response, $question): FindingSeverity
    {
        $proposed = $question->proposedFindingSeverity();

        // A partial answer is one band below the question's own proposal: the
        // control half works, and treating it identically to an outright
        // failure would make the two answers indistinguishable in the register
        // — which is the reason a vendor stops using "partial" honestly.
        if ($response->compliance === ComplianceLevel::Partial) {
            return match ($proposed) {
                FindingSeverity::Critical => FindingSeverity::High,
                FindingSeverity::High => FindingSeverity::Medium,
                default => FindingSeverity::Low,
            };
        }

        return $proposed;
    }

    private function engagementFor(?Document $document): ?Engagement
    {
        if ($document === null || $document->owner_type !== Document::OWNER_ENGAGEMENT) {
            return null;
        }

        return Engagement::query()->find($document->owner_id);
    }

    private function shorten(string $text): string
    {
        return \Illuminate\Support\Str::limit($text, 140);
    }
}
