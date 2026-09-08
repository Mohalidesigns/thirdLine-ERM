<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\StrategyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreBcmsStrategyRequest;
use App\Models\Bcms\Process;
use App\Models\Bcms\Strategy;
use App\Presenters\Bcms\StrategyRegisterPresenter;
use App\Services\Bcms\Strategy\GapAnalysisService;
use App\Services\Bcms\Strategy\StrategyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The continuity strategy register — ISO 22331.
 *
 * The rules live in `StrategyService`: one selected strategy per process, a
 * rationale for the two strategy types a regulator asks about, and approval only
 * of what was selected. This turns their exceptions into flash messages, because
 * somebody who clicks Approve on an unselected option should be told why rather
 * than shown a 403 that suggests their account is wrong.
 */
class StrategyController extends Controller
{
    public function __construct(
        private StrategyService $strategies,
        private GapAnalysisService $gaps,
        private StrategyRegisterPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.strategy.view');

        return Inertia::render('Bcms/Strategy/Index', $this->presenter->present(
            $request->user(),
            $request->filled('tier') ? (int) $request->integer('tier') : 1,
        ));
    }

    public function gapAnalysis(Request $request): Response
    {
        Gate::authorize('bcms.strategy.view');

        return Inertia::render('Bcms/Strategy/Gap', [
            'analysis' => $this->gaps->analyse(
                $request->user(),
                $request->filled('tier') ? (int) $request->integer('tier') : 1,
            ),
            'max_tier' => $request->filled('tier') ? (int) $request->integer('tier') : 1,
            'can' => [
                'manage' => $request->user()?->can('bcms.strategy.manage') === true,
                'export' => $request->user()?->can('bcms.report.export') === true,
            ],
        ]);
    }

    /**
     * The gap table as CSV, for the board pack.
     *
     * STREAMED, and the rows are the same array the screen renders. A separate
     * export query is how an export and a screen come to disagree, and the
     * disagreement is only ever found by the person presenting the pack.
     */
    public function exportGapAnalysis(Request $request): StreamedResponse
    {
        Gate::authorize('bcms.report.export');

        $analysis = $this->gaps->analyse(
            $request->user(),
            $request->filled('tier') ? (int) $request->integer('tier') : 1,
        );

        $columns = [
            'code' => 'Process code',
            'name' => 'Process',
            'tier' => 'Tier',
            'business_unit' => 'Business unit',
            'rto_required_hours' => 'RTO required (h)',
            'rto_achievable_hours' => 'RTO achievable (h)',
            'shortfall_hours' => 'Shortfall (h)',
            'strategy_label' => 'Selected strategy',
            'approval_status' => 'Strategy status',
            'cost_estimate_minor' => 'Cost (minor units)',
            'currency' => 'Currency',
            'reason' => 'Note',
        ];

        return response()->streamDownload(function () use ($analysis, $columns): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_values($columns));

            foreach ($analysis['rows'] as $row) {
                fputcsv($out, array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)));
            }

            fclose($out);
        }, 'bcms-strategy-gap-analysis-'.now()->toDateString().'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function show(Request $request, Process $process): Response
    {
        Gate::authorize('bcms.strategy.view');

        return Inertia::render('Bcms/Strategy/Compare', [
            'process' => [
                'id' => $process->getKey(),
                'uuid' => $process->uuid,
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
            ],
            'required' => $this->strategies->requiredRtoAssessment((int) $process->getKey())?->only([
                'id', 'rto_hours', 'mtpd_hours', 'rpo_minutes', 'approved_at',
            ]),
            'options' => $this->strategies->compare($process),
            'strategy_types' => StrategyType::options(),
            'can' => [
                'manage' => $request->user()?->can('bcms.strategy.manage') === true,
                'approve' => $request->user()?->can('bcms.strategy.approve') === true,
            ],
        ]);
    }

    public function store(StoreBcmsStrategyRequest $request): RedirectResponse
    {
        $process = $request->process();

        if ($process === null) {
            return back()->with('error', 'That process is not one this organisation holds.');
        }

        try {
            $this->strategies->propose(
                $process,
                StrategyType::from($request->string('strategy_type')->toString()),
                $request->safe()->except(['process_id', 'strategy_type']),
                $request->user()?->getKey(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Strategy option recorded.');
    }

    public function update(StoreBcmsStrategyRequest $request, Strategy $strategy): RedirectResponse
    {
        if ($strategy->approval_status === 'approved') {
            return back()->with('error', 'An approved strategy cannot be edited. Propose a new option instead.');
        }

        $strategy->update($request->safe()->except(['process_id']) + [
            'updated_by' => $request->user()?->getKey(),
        ]);

        $this->strategies->reassess($strategy, $request->user()?->getKey());

        return back()->with('success', 'Strategy updated.');
    }

    public function select(Request $request, Strategy $strategy): RedirectResponse
    {
        Gate::authorize('bcms.strategy.approve');

        $data = $request->validate(['selection_rationale' => ['nullable', 'string', 'max:5000']]);

        try {
            $this->strategies->select($strategy, $data['selection_rationale'] ?? null, $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Strategy selected for this process.');
    }

    public function approve(Request $request, Strategy $strategy): RedirectResponse
    {
        Gate::authorize('bcms.strategy.approve');

        try {
            $this->strategies->approve($strategy, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Strategy approved.');
    }

    public function reject(Request $request, Strategy $strategy): RedirectResponse
    {
        Gate::authorize('bcms.strategy.approve');

        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        $this->strategies->reject($strategy, $data['reason'], $request->user()?->getKey());

        return back()->with('success', 'Strategy rejected.');
    }

    public function reassess(Request $request, Strategy $strategy): RedirectResponse
    {
        Gate::authorize('bcms.strategy.manage');

        $updated = $this->strategies->reassess($strategy, $request->user()?->getKey());

        return back()->with(
            'success',
            $updated->gap_vs_required_hours === null
                ? 'Reassessed. There is still no gap to compute — either the process has no approved BIA or this '
                    .'option has no achievable recovery time.'
                : 'Reassessed against the current approved BIA.'
        );
    }
}
