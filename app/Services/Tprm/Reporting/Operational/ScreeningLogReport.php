<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Enums\Tprm\ScreeningDecision;
use App\Models\Tprm\ScreeningCheck;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * The screening log — FR-RPT-07, and the artefact CBN AML/CFT Reg. 29 asks
 * for.
 *
 * WHAT AN EXAMINER CHECKS IS THE UNDECIDED MATCHES. A screening programme is
 * judged on whether hits were adjudicated and by whom, not on how many checks
 * ran; a log showing four hundred clean runs and three matches nobody looked
 * at is a failing programme with a reassuring first column. Undecided matches
 * therefore sort to the top and are named as such.
 *
 * A CHECK THAT ERRORED IS A ROW. A provider outage that produced no result is
 * not a clean screen, and the retention rule in config/tprm.php keeps five
 * years of these precisely so the gap is visible later.
 */
class ScreeningLogReport implements OperationalReport
{
    use StatesAbsence;

    public function key(): string
    {
        return 'screening-log';
    }

    public function title(): string
    {
        return 'Screening log';
    }

    public function description(): string
    {
        return 'Sanctions, PEP and adverse-media checks, their matches, and who adjudicated each one. '
            .'Undecided matches first.';
    }

    public function permission(): string
    {
        return 'tprm.screening.view';
    }

    public function headers(): array
    {
        return [
            'Run at', 'Subject', 'Subject type', 'Provider', 'Lists', 'Check status', 'Matches',
            'Undecided matches', 'List', 'Matched name', 'Score', 'Decision', 'Decided by',
            'Decided at', 'Escalated', 'Next due',
        ];
    }

    public function rows(): array
    {
        $checks = ScreeningCheck::query()
            ->with(['matches.decider:id,name'])
            ->orderByDesc('run_at')
            ->get();

        $vendors = ThirdParty::query()->pluck('legal_name', 'id');

        $rows = [];

        foreach ($checks as $check) {
            $undecided = $check->matches->filter(
                fn (ScreeningMatch $match) => $match->decision === null
                    || $match->decision === ScreeningDecision::Pending
            );

            $base = [
                $check->run_at?->toDateTimeString() ?? 'Not recorded',
                $this->subjectName($check, $vendors),
                ucwords(str_replace('_', ' ', (string) $check->subject_type)),
                $check->provider ?: 'Not recorded',
                implode(', ', (array) ($check->list_types ?? [])) ?: 'Not recorded',
                ucwords(str_replace('_', ' ', (string) $check->status)),
                $check->matches->count(),
                $undecided->count(),
            ];

            if ($check->matches->isEmpty()) {
                // A clean run is still a row: the evidence that the check
                // happened is the point of a five-year retention rule.
                $rows[] = array_merge($base, [
                    'No match', '', '', 'No adjudication required', '', '', 'No',
                    $check->next_due_at?->toDateString() ?? 'Not scheduled',
                ]);

                continue;
            }

            foreach ($check->matches as $match) {
                $rows[] = array_merge($base, [
                    $match->list_name,
                    $match->matched_name,
                    $match->match_score === null ? '' : (float) $match->match_score,
                    $this->decisionLabel($match),
                    $this->labelOf($match->decider, 'name', 'Nobody'),
                    $match->decided_at?->toDateTimeString() ?? '',
                    $match->escalated ? 'Yes' : 'No',
                    $check->next_due_at?->toDateString() ?? 'Not scheduled',
                ]);
            }
        }

        // Undecided matches to the top: an examiner reads those first, and so
        // should whoever runs this report before an examiner does.
        usort($rows, fn (array $a, array $b) => ($b[7] <=> $a[7]) ?: strcmp((string) $b[0], (string) $a[0]));

        return $rows;
    }

    public function notes(): array
    {
        return [
            'Population' => 'Every screening check on record, one row per match and one for a clean run',
            'Ordering' => 'Checks with undecided matches first, then most recent',
            'Retention' => config('tprm.retention.screening_years').' years, per config/tprm.php',
        ];
    }

    /**
     * `pending` is the model's default, so a match nobody has looked at
     * carries a decision object rather than a null. Printing its label would
     * put "Pending" in the column an examiner scans for adjudication — the
     * same state the undecided COUNT already treats as undecided, and the two
     * must agree or the count contradicts the rows beneath it.
     */
    private function decisionLabel(ScreeningMatch $match): string
    {
        return $match->decision === null || $match->decision === ScreeningDecision::Pending
            ? 'UNDECIDED'
            : $match->decision->label();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $vendors
     */
    private function subjectName(ScreeningCheck $check, $vendors): string
    {
        if ($check->subject_type === ScreeningCheck::SUBJECT_THIRD_PARTY) {
            return $vendors[$check->subject_id] ?? 'Third party #'.$check->subject_id;
        }

        // Ownership and contact subjects are resolved lazily and rarely; the
        // id is printed rather than issuing a query per row.
        return ucwords(str_replace('_', ' ', (string) $check->subject_type)).' #'.$check->subject_id;
    }
}
