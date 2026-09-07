<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\ScreeningDecision;
use App\Http\Controllers\Controller;
use App\Models\Tprm\SanctionsList;
use App\Models\Tprm\ScreeningCheck;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Screening\SanctionsEscalation;
use App\Services\Tprm\Screening\ScreeningDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The screening queue and match resolution — FR-DDL-05, FR-MON-05.
 *
 * THE CONSEQUENCE IS ON THE SCREEN BEFORE THE DECISION IS MADE. Confirming a
 * true match suspends every engagement with the vendor, forces its residual
 * score to the maximum, notifies the AML function and starts a 24-hour STR
 * clock. A reviewer who learns that afterwards has been ambushed by their own
 * tool; the resolution screen states it first, with the count of engagements
 * that will stop.
 */
class ScreeningController extends Controller
{
    public function __construct(
        private readonly ScreeningDispatcher $dispatcher,
        private readonly SanctionsEscalation $escalation,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.screening.view');

        $pending = ScreeningMatch::query()
            ->pending()
            ->with(['check'])
            ->orderByDesc('match_score')
            ->limit(200)
            ->get();

        return Inertia::render('Tprm/Screening/Index', [
            'pending' => $pending->map(fn (ScreeningMatch $match) => $this->matchPayload($match))->values(),
            'summary' => [
                'pending' => ScreeningMatch::query()->pending()->count(),
                'true_matches' => ScreeningMatch::query()->trueMatches()->count(),
                'checks_30d' => ScreeningCheck::query()->where('run_at', '>=', now()->subDays(30))->count(),
                'never_screened' => ThirdParty::query()
                    ->where('status', 'active')->whereNull('last_screened_at')->count(),
            ],
            'lists' => SanctionsList::query()->active()->get()
                ->map(fn (SanctionsList $list) => $list->healthReport())->values(),
            'can' => [
                'decide' => $request->user()->can('tprm.screening.decide'),
            ],
        ]);
    }

    /** Screen a third party and its directors and UBOs on demand. */
    public function screen(Request $request, ThirdParty $thirdParty)
    {
        Gate::authorize('tprm.screening.decide');

        $result = $this->dispatcher->screenThirdParty($thirdParty, $request->user()->id);

        if ($result['failed'] !== []) {
            return back()->with('error', sprintf(
                '%d check(s) ran and %d match(es) were found, but some providers could not be searched: %s. A '
                .'failed search is not a clear search.',
                $result['checks'],
                $result['matches'],
                implode('; ', $result['failed']),
            ));
        }

        return back()->with('success', sprintf(
            '%d check(s) run across the entity and its directors, %d match(es) to review.',
            $result['checks'],
            $result['matches'],
        ));
    }

    /**
     * Resolve a match — the decision AC-08 hangs off.
     */
    public function decide(Request $request, ScreeningMatch $match)
    {
        Gate::authorize('tprm.screening.decide');

        $validated = $request->validate([
            'decision' => 'required|in:true_match,false_positive,possible',
            // Required on a dismissal too. "Different date of birth, no
            // connection to the entity" is what makes a dismissal reviewable
            // by an examiner who cannot re-run the search as it was.
            'rationale' => 'required|string|min:20|max:2000',
        ]);

        $result = $this->escalation->decide(
            $match,
            ScreeningDecision::from($validated['decision']),
            $validated['rationale'],
            $request->user(),
        );

        if (! $result['decided']) {
            return back()->withInput()->with('error', $result['reason']);
        }

        if (! $result['escalated']) {
            return back()->with('success', 'The decision was recorded.');
        }

        return back()->with('success', sprintf(
            'Confirmed. %d engagement(s) suspended, the vendor blacklisted, its residual risk forced to the '
            .'maximum, and a suspicious transaction report is due within 24 hours under CBN AML/CFT Reg. 38.',
            $result['engagements_suspended'],
        ));
    }

    /**
     * The screening history for one third party — the Reg. 35 retrieval path.
     */
    public function history(Request $request, ThirdParty $thirdParty)
    {
        Gate::authorize('tprm.screening.view');

        $checks = ScreeningCheck::query()
            ->forThirdParty($thirdParty->getKey())
            ->with('matches')
            ->orderByDesc('run_at')
            ->get();

        return Inertia::render('Tprm/Screening/History', [
            'thirdParty' => [
                'id' => $thirdParty->getKey(),
                'legal_name' => $thirdParty->legal_name,
                'url' => route('tprm.third-parties.show', $thirdParty),
                'last_screened_at' => $thirdParty->last_screened_at?->toDateString(),
            ],
            'status' => $this->dispatcher->statusFor($thirdParty),
            'checks' => $checks->map(fn (ScreeningCheck $check) => [
                'id' => $check->getKey(),
                'provider' => $check->provider,
                'subject_type' => $check->subject_type,
                'subject_name' => $check->subject_type === ScreeningCheck::SUBJECT_OWNERSHIP
                    ? \App\Models\Tprm\Ownership::query()->whereKey($check->subject_id)->value('holder_name')
                    : $thirdParty->legal_name,
                'run_at' => $check->run_at?->toDayDateTimeString(),
                'status' => $check->status,
                'matches' => $check->matches->count(),
                // Reg. 35: five years, retrievable within 48 hours. The
                // provider's own answer, not our summary of it.
                'retainable' => $check->isRetainable(),
                'raw_response' => $check->raw_response,
            ])->values(),
            'retention' => [
                'years' => ScreeningCheck::RETENTION_YEARS,
                'citation' => 'CBN AML/CFT Regulations 2022, Reg. 35',
            ],
            'can' => [
                'decide' => $request->user()->can('tprm.screening.decide'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function matchPayload(ScreeningMatch $match): array
    {
        $check = $match->check;
        $thirdPartyId = $check?->thirdPartyId();
        $thirdParty = $thirdPartyId === null ? null : ThirdParty::query()->find($thirdPartyId);

        return [
            'id' => $match->getKey(),
            'list_name' => $match->list_name,
            'matched_name' => $match->matched_name,
            'score' => $match->match_score === null ? null : (float) $match->match_score,
            'details' => $match->entity_details,
            'provider' => $check?->provider,
            'subject_type' => $check?->subject_type,
            'subject_name' => $check?->subject_type === ScreeningCheck::SUBJECT_OWNERSHIP
                ? \App\Models\Tprm\Ownership::query()->whereKey($check->subject_id)->value('holder_name')
                : $thirdParty?->legal_name,
            'third_party' => $thirdParty?->legal_name,
            'third_party_id' => $thirdPartyId,
            'run_at' => $check?->run_at?->toDayDateTimeString(),
            // What confirming will do, stated BEFORE the decision. A reviewer
            // who learns this afterwards has been ambushed by their own tool.
            'consequence' => $thirdPartyId === null ? null : [
                'engagements' => \App\Models\Tprm\Engagement::query()
                    ->where('third_party_id', $thirdPartyId)
                    ->whereNotIn('status', ['terminated', 'archived'])
                    ->count(),
                'note' => 'Confirming a true match suspends every engagement with this third party, '
                    .'blacklists it, forces its residual risk to the maximum, notifies the AML function and '
                    .'opens a suspicious transaction report task due within 24 hours (CBN AML/CFT Reg. 38).',
            ],
        ];
    }
}
