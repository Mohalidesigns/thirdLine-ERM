<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Alert;
use App\Models\Tprm\Engagement;
use App\Services\Tprm\Reporting\BoardPackBuilder;
use App\Services\Tprm\Reporting\KriPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The TPRM overview — the module's front door.
 *
 * IT OPENS WITH WHAT IS WRONG, NOT WITH HOW BIG THE ESTATE IS. "214 vendors"
 * is the first number every competitor's dashboard shows and the least useful
 * one in the room; the tiles here lead with Critical engagements past their
 * assessment date, vendors that require an exit plan and have none, and
 * evidence that has already expired.
 *
 * EVERY TILE DRILLS THROUGH WITH ITS FILTER INTACT. A counter a user cannot
 * open is a number they have to take on trust, and the register already
 * supports the filters these tiles count on — so the tile links to the
 * filtered view rather than to the unfiltered list with a mental note.
 *
 * IT REUSES `BoardPackBuilder`. The overview and the board pack answer the
 * same questions at different cadences, and two implementations would drift —
 * the screen saying nine overdue assessments while the pack tabled last week
 * said seven, with nothing to explain the difference. The pack freezes its
 * copy; this one is live, and that is the only difference between them.
 */
class OverviewController extends Controller
{
    public function __construct(
        private readonly BoardPackBuilder $figures,
        private readonly KriPublisher $kris,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.view');

        $figures = $this->figures->figures();

        return Inertia::render('Tprm/Overview', [
            'portfolio' => $figures['portfolio'],
            'attention' => $this->attention($figures),
            'findings' => $figures['findings'],
            'exit' => $figures['exit_readiness'],
            'concentration' => $figures['concentration'],
            'incidents' => $figures['incidents'],
            'tiers' => $this->tierDistribution(),
            'alerts' => $this->alerts($request),
            'kris' => $request->user()->can('tprm.report.view') ? $this->kris->status() : [],
            'can' => [
                'adopt_kris' => $request->user()->can('tprm.admin'),
                'view_kris' => $request->user()->can('tprm.report.view'),
            ],
        ]);
    }

    /**
     * Adopt the nine KRIs into this tenant's register.
     *
     * `tprm.admin`, not a reporting permission: creating nine indicators in a
     * bank's risk framework is a change to that framework.
     */
    public function adoptKris(Request $request)
    {
        Gate::authorize('tprm.admin');

        $result = $this->kris->adopt($request->user()->id);

        return back()->with(
            'success',
            $result['created'] === 0
                ? 'All nine third-party KRIs were already in the register.'
                : sprintf(
                    '%d KRIs added to the register with default thresholds. They are yours to retune — a '
                    .'republish never overwrites a threshold you have changed.',
                    $result['created'],
                ),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * The tiles, in order of consequence.
     *
     * @param  array<string, mixed>  $figures
     * @return list<array<string, mixed>>
     */
    private function attention(array $figures): array
    {
        $assessments = $figures['overdue_assessments'];
        $exit = $figures['exit_readiness'];
        $evidence = $figures['expiring_evidence'];
        $findings = $figures['findings'];

        return [
            [
                'label' => 'Critical or High past their assessment date',
                'value' => $assessments['critical_or_high_overdue'],
                'tone' => $assessments['critical_or_high_overdue'] > 0 ? 'critical' : 'calm',
                'hint' => $assessments['no_cadence_set'].' more have no cadence set at all',
                'href' => route('tprm.engagements.index', ['assessment' => 'overdue']),
            ],
            [
                'label' => 'Require an exit plan and have none',
                'value' => $exit['no_plan'],
                'tone' => $exit['no_plan'] > 0 ? 'critical' : 'calm',
                'hint' => 'of '.$exit['require_a_plan'].' that require one',
                'href' => route('tprm.exit.index'),
            ],
            [
                'label' => 'Assurance evidence already expired',
                'value' => $evidence['already_expired'],
                'tone' => $evidence['already_expired'] > 0 ? 'critical' : 'calm',
                'hint' => $evidence['expiring'].' more expire within '.$evidence['horizon_days'].' days',
                'href' => route('tprm.reports.operational.show', 'evidence-expiry-forecast'),
            ],
            [
                'label' => 'Findings past their remediation date',
                'value' => $findings['overdue'],
                'tone' => $findings['overdue'] > 0 ? 'warn' : 'calm',
                'hint' => $findings['open'].' open in total',
                'href' => route('tprm.findings.index', ['status' => 'open']),
            ],
        ];
    }

    /**
     * An alert has no title of its own: the signal carries the human sentence
     * and the rule carries why it fired. Preferring the signal means the
     * stream reads as what happened rather than as which rule matched.
     *
     * Written as explicit checks rather than a `?->` chain — both foreign keys
     * are genuinely nullable, and larastan types a belongsTo as non-null
     * whether or not its key is.
     */
    private function alertTitle(Alert $alert): string
    {
        $signal = $alert->signal;

        if ($signal !== null && filled($signal->title)) {
            return (string) $signal->title;
        }

        $rule = $alert->rule;

        if ($rule !== null && filled($rule->name)) {
            return (string) $rule->name;
        }

        return 'Alert #'.$alert->getKey();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tierDistribution(): array
    {
        $live = Engagement::query()
            ->get()
            ->filter(fn (Engagement $engagement) => $engagement->status->isLive());

        $rows = [];

        foreach (RiskTier::cases() as $tier) {
            $rows[] = [
                'tier' => $tier->value,
                'label' => $tier->label(),
                'count' => $live->where('effective_tier', $tier)->count(),
                'href' => route('tprm.engagements.index', ['tier' => $tier->value]),
            ];
        }

        // Not folded into Low. An engagement nobody has tiered has not been
        // found to be low risk; it has not been looked at.
        $rows[] = [
            'tier' => null,
            'label' => 'Not tiered',
            'count' => $live->whereNull('effective_tier')->count(),
            'href' => route('tprm.engagements.index'),
        ];

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alerts(Request $request): array
    {
        if (! $request->user()->can('tprm.monitoring.view')) {
            return [];
        }

        return Alert::query()
            ->with(['thirdParty:id,legal_name', 'signal:id,title', 'rule:id,name'])
            // The model's own scope, which excludes muted alerts as well as
            // closed ones. `status = 'open'` is not a value the enum has.
            ->open()
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Alert $alert) => [
                'id' => $alert->getKey(),
                // An alert has no title of its own: the signal carries the
                // human sentence and the rule carries why it fired. Preferring
                // the signal means the stream reads as what happened rather
                // than as which rule matched.
                'title' => $this->alertTitle($alert),
                'severity' => $alert->severity?->label() ?? 'Not graded',
                'third_party' => $alert->thirdParty?->legal_name,
                'raised' => $alert->created_at->diffForHumans(),
            ])
            ->all();
    }
}
