<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tprm\BoardPack;
use App\Services\Tprm\Reporting\BoardPackService;
use App\Services\Tprm\Reporting\ReportProvenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The Board and Risk Committee pack — FR-RPT-05.
 *
 * PREPARING, EDITING AND SIGNING ARE THREE ROUTES BEHIND TWO PERMISSIONS.
 * `tprm.report.view` reads a pack; `tprm.report.export` prepares and produces
 * one; **signing off is `tprm.admin`**, because it is the act of standing
 * behind the numbers in front of a board committee and not a reporting task.
 *
 * THE SERVICE'S REFUSALS SURFACE AS FLASH MESSAGES, NOT AS 500s. "This pack is
 * signed off and its figures are frozen" is something a preparer needs to
 * read and act on, and a stack trace does not tell them to open a new period.
 */
class BoardPackController extends Controller
{
    public function __construct(private readonly BoardPackService $packs) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $packs = BoardPack::query()
            ->with(['preparer:id,name', 'signatory:id,name', 'narrativeEditor:id,name'])
            ->orderByDesc('as_at')
            ->get();

        return Inertia::render('Tprm/Reports/BoardPacks', [
            'packs' => $packs->map(fn (BoardPack $pack) => $this->summaryPayload($pack)),
            'trend' => $this->packs->trend(),
            'can' => [
                'prepare' => $request->user()->can('tprm.report.export'),
                'sign_off' => $request->user()->can('tprm.admin'),
            ],
        ]);
    }

    public function show(Request $request, BoardPack $boardPack)
    {
        Gate::authorize('tprm.report.view');

        $boardPack->loadMissing(['preparer:id,name', 'signatory:id,name', 'narrativeEditor:id,name']);

        return Inertia::render('Tprm/Reports/BoardPack', [
            'pack' => $this->summaryPayload($boardPack) + [
                'figures' => $boardPack->figures,
                'narrative' => $boardPack->narrative,
            ],
            'can' => [
                'prepare' => $request->user()->can('tprm.report.export'),
                'sign_off' => $request->user()->can('tprm.admin'),
            ],
        ]);
    }

    public function prepare(Request $request)
    {
        Gate::authorize('tprm.report.export');

        $validated = $request->validate([
            'period_label' => ['required', 'string', 'max:40'],
            'as_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        try {
            $pack = $this->packs->prepare(
                $validated['period_label'],
                CarbonImmutable::parse($validated['as_at']),
                $request->user()->id,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('tprm.reports.board-packs.show', $pack)
            ->with('success', "The {$pack->period_label} pack has been prepared.");
    }

    public function updateNarrative(Request $request, BoardPack $boardPack)
    {
        Gate::authorize('tprm.report.export');

        $validated = $request->validate([
            'narrative' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $this->packs->editNarrative($boardPack, $validated['narrative'], $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The narrative has been saved as your own.');
    }

    public function transition(Request $request, BoardPack $boardPack)
    {
        $validated = $request->validate([
            'to' => ['required', Rule::in([BoardPack::STATUS_IN_REVIEW, BoardPack::STATUS_SIGNED_OFF])],
        ]);

        // Signing off is the one act that freezes a set of numbers in front of
        // a board committee, and it does not sit with whoever can run a report.
        Gate::authorize($validated['to'] === BoardPack::STATUS_SIGNED_OFF
            ? 'tprm.admin'
            : 'tprm.report.export');

        try {
            $validated['to'] === BoardPack::STATUS_SIGNED_OFF
                ? $this->packs->signOff($boardPack, $request->user()->id)
                : $this->packs->submitForReview($boardPack, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $validated['to'] === BoardPack::STATUS_SIGNED_OFF
            ? 'The pack is signed off. Its figures are now frozen.'
            : 'The pack has been submitted for review.');
    }

    public function export(Request $request, BoardPack $boardPack, DocumentRenderer $renderer)
    {
        Gate::authorize('tprm.report.export');

        abort_if($boardPack->figures === null, 422, 'This pack has not been prepared and carries no figures.');

        $provenance = new ReportProvenance(
            title: 'Third-Party Risk — Board and Risk Committee pack',
            asAt: CarbonImmutable::parse($boardPack->as_at),
            preparedBy: $boardPack->preparer?->name,
            reviewedBy: $boardPack->signatory?->name,
            filters: [
                'Period' => $boardPack->period_label,
                'Status' => $this->statusLabel($boardPack),
                'Narrative' => $boardPack->narrativeProvenance(),
            ],
            rowCount: (int) ($boardPack->figures['portfolio']['total'] ?? 0),
            versions: ['Scoring engine version' => (string) $boardPack->engine_version],
            authority: 'FR-RPT-05',
        );

        $pdf = $renderer->pdf('reports.pdf.tprm-board-pack', [
            'title' => 'Third-Party Risk',
            'subtitle' => 'Board and Risk Committee pack — '.$boardPack->period_label,
            'organization' => $request->user()->organization,
            'periodLabel' => $boardPack->period_label,
            'periodAsAt' => CarbonImmutable::parse($boardPack->as_at),
            'generatedBy' => $request->user()->name,
            'preparedBy' => $provenance->preparedBy,
            'reviewedBy' => $provenance->reviewedBy,
            'reviewRequired' => true,
            'provenance' => $provenance->filterProvenance(),
            'version' => $boardPack->engine_version,
            'pack' => $boardPack,
            'figures' => $boardPack->figures,
            'narrative' => $boardPack->narrative,
        ]);

        $filename = 'tprm-board-pack-'.str($boardPack->period_label)->slug().'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryPayload(BoardPack $pack): array
    {
        return [
            'uuid' => $pack->uuid,
            'period_label' => $pack->period_label,
            'as_at' => $pack->as_at->toDateString(),
            'status' => $pack->status,
            'status_label' => $this->statusLabel($pack),
            'prepared_by' => $pack->preparer?->name,
            'prepared_at' => $pack->prepared_at?->toDayDateTimeString(),
            'signed_off_by' => $pack->signatory?->name,
            'signed_off_at' => $pack->signed_off_at?->toDayDateTimeString(),
            'narrative_provenance' => $pack->narrativeProvenance(),
            'engine_version' => $pack->engine_version,
            'editable' => $pack->isEditable(),
            'url' => route('tprm.reports.board-packs.show', $pack),
        ];
    }

    private function statusLabel(BoardPack $pack): string
    {
        return match ($pack->status) {
            BoardPack::STATUS_SIGNED_OFF => 'Signed off — figures frozen',
            BoardPack::STATUS_IN_REVIEW => 'In review',
            default => 'Draft',
        };
    }
}
