<?php

namespace App\Http\Controllers;

use App\Http\Requests\RiskLibrary\BulkApplyLibraryItemRequest;
use App\Http\Requests\RiskLibrary\CheckDuplicateLibraryItemRequest;
use App\Http\Requests\RiskLibrary\RetireRiskLibraryItemRequest;
use App\Http\Requests\RiskLibrary\StoreRiskLibraryItemRequest;
use App\Http\Requests\RiskLibrary\UpdateRiskLibraryItemRequest;
use App\Models\RiskLibraryItem;
use App\Presenters\RiskLibraryPresenter;
use App\Services\RiskLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * Master Risk Library controller (ADR 0028 Phase 1).
 *
 * Thin controller (Standard §1): authorises via RiskLibraryItemPolicy, delegates
 * domain work to RiskLibraryService and view shaping to RiskLibraryPresenter.
 * No DELETE action exists (ADR 0028 Decision 6).
 */
class RiskLibraryController extends Controller
{
    public function __construct(
        private readonly RiskLibraryService $service,
        private readonly RiskLibraryPresenter $presenter,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', RiskLibraryItem::class);

        return Inertia::render('RiskLibrary/Index', $this->presenter->index($request, $request->user()));
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', RiskLibraryItem::class);

        return Inertia::render('RiskLibrary/Create', $this->presenter->formOptions());
    }

    public function store(StoreRiskLibraryItemRequest $request): RedirectResponse
    {
        $item = $this->service->create($request->validated(), $request->user());

        return redirect()
            ->route('risk.library.show', $item)
            ->with('success', "Risk Library item {$item->code} (v1) created successfully.");
    }

    public function show(Request $request, RiskLibraryItem $item): InertiaResponse
    {
        Gate::authorize('view', $item);

        return Inertia::render('RiskLibrary/Show', $this->presenter->show($item, $request->user()));
    }

    public function edit(RiskLibraryItem $item): InertiaResponse|RedirectResponse
    {
        Gate::authorize('update', $item);

        if ($item->isRetired()) {
            return redirect()
                ->route('risk.library.show', $item)
                ->with('error', 'Reactivate this library item before editing.');
        }

        return Inertia::render('RiskLibrary/Edit', $this->presenter->formOptions($item));
    }

    public function update(UpdateRiskLibraryItemRequest $request, RiskLibraryItem $item): RedirectResponse
    {
        if ($item->isRetired()) {
            return redirect()
                ->route('risk.library.show', $item)
                ->with('error', 'Reactivate this library item before editing.');
        }

        $updated = $this->service->update($item, $request->validated(), $request->user());

        return redirect()
            ->route('risk.library.show', $updated)
            ->with('success', "Published {$updated->code} version v{$updated->current_version}. Existing risk instances will prompt owners to review.");
    }

    public function retire(RetireRiskLibraryItemRequest $request, RiskLibraryItem $item): RedirectResponse
    {
        if ($item->isRetired()) {
            return redirect()
                ->route('risk.library.show', $item)
                ->with('info', "{$item->code} is already retired.");
        }

        $this->service->retire($item, (string) $request->validated('retirement_reason'), $request->user());

        return redirect()
            ->route('risk.library.show', $item)
            ->with('success', "{$item->code} has been retired. Existing linked risk instances remain active.");
    }

    public function reactivate(Request $request, RiskLibraryItem $item): RedirectResponse
    {
        Gate::authorize('retire', $item);

        if ($item->isActive()) {
            return redirect()
                ->route('risk.library.show', $item)
                ->with('info', "{$item->code} is already active.");
        }

        $this->service->reactivate($item, $request->user());

        return redirect()
            ->route('risk.library.show', $item)
            ->with('success', "{$item->code} has been reactivated and is available in pickers again.");
    }

    public function duplicates(CheckDuplicateLibraryItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $matches = $this->service->findPotentialDuplicates(
            (string) ($validated['title'] ?? ''),
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            isset($validated['exclude_id']) ? (int) $validated['exclude_id'] : null,
        );

        return response()->json(['matches' => $matches]);
    }

    public function pickerOptions(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RiskLibraryItem::class);

        return response()->json($this->presenter->pickerOptions($request));
    }

    public function previewBulkApply(BulkApplyLibraryItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $preview = $this->service->previewBulkApply(
            array_map('intval', $validated['library_item_ids']),
            (string) $validated['target_mode'],
            array_map('intval', $validated['entity_ids'] ?? []),
            array_map('intval', $validated['process_ids'] ?? []),
        );

        return response()->json($preview);
    }

    public function bulkApply(BulkApplyLibraryItemRequest $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->service->bulkApply(
            array_map('intval', $validated['library_item_ids']),
            (string) $validated['target_mode'],
            array_map('intval', $validated['entity_ids'] ?? []),
            array_map('intval', $validated['process_ids'] ?? []),
            isset($validated['default_owner_id']) ? (int) $validated['default_owner_id'] : null,
            $request->user(),
        );

        $message = "Created {$result['created_count']} risk instance(s) ({$result['skipped_count']} already attached, skipped).";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($result + ['message' => $message], 201);
        }

        return redirect()->back()->with('success', $message);
    }

    public function export(Request $request, DocumentRenderer $renderer): StreamedResponse|Response
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        if ($format === 'pdf') {
            return $this->exportPdf($request, $renderer);
        }

        return $this->exportCsv($request);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', RiskLibraryItem::class);

        ['headers' => $headers, 'rows' => $rows] = $this->presenter->exportRows($request);
        $filename = 'risk_library_'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportPdf(Request $request, DocumentRenderer $renderer): Response
    {
        Gate::authorize('viewAny', RiskLibraryItem::class);

        ['headers' => $headers, 'rows' => $rows] = $this->presenter->exportRows($request);
        $user = $request->user();

        $content = $renderer->pdf('reports.pdf.tprm-operational-report', [
            'title' => 'Master Risk Library Catalogue',
            'subtitle' => 'ISO 31000 §6.4.2 & Basel II/III OPE25 master risk definitions (scores assessed on entity/process instances).',
            'organization' => $user->organization,
            'periodAsAt' => now(),
            'generatedBy' => $user->name,
            'preparedBy' => $user->name,
            'provenance' => [],
            'headers' => $headers,
            'rows' => $rows,
            'paper' => 'a3',
            'orientation' => 'landscape',
        ]);

        $filename = 'risk_library_'.now()->format('Y-m-d').'.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function exportItemPdf(Request $request, RiskLibraryItem $item, DocumentRenderer $renderer): Response
    {
        Gate::authorize('view', $item);

        $data = $this->presenter->show($item, $request->user());
        $user = $request->user();

        $headers = ['Version', 'Title', 'Category', 'Basel L1', 'Change Summary', 'Published By', 'Published At'];
        $rows = array_map(static fn (array $v) => [
            'v'.$v['version_number'],
            $v['title'],
            $v['category_name'] ?? '—',
            $v['basel_l1_label'] ?? '—',
            $v['change_summary'] ?? 'Initial version',
            $v['created_by_name'] ?? 'System',
            $v['created_at'] ?? '—',
        ], $data['versions']);

        $content = $renderer->pdf('reports.pdf.tprm-operational-report', [
            'title' => "{$item->code} — {$item->title} (Version History)",
            'subtitle' => "Current version v{$item->current_version} · Status: ".ucfirst($item->status),
            'organization' => $user->organization,
            'periodAsAt' => now(),
            'generatedBy' => $user->name,
            'preparedBy' => $user->name,
            'provenance' => [],
            'headers' => $headers,
            'rows' => $rows,
            'paper' => 'a4',
            'orientation' => 'landscape',
        ]);

        $filename = strtolower($item->code).'_version_history_'.now()->format('Y-m-d').'.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
