<?php

namespace App\Http\Controllers;

use App\Http\Requests\Process\StoreProcessRequest;
use App\Http\Requests\Process\UpdateProcessRequest;
use App\Models\BusinessProcess;
use App\Presenters\ProcessPresenter;
use App\Services\ProcessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * Business Process & Sub-Process Module controller (ADR 0028 Phase 2).
 *
 * Thin controller (Standard §1): authorises via BusinessProcessPolicy, delegates
 * domain logic to ProcessService and view shaping to ProcessPresenter.
 */
class ProcessController extends Controller
{
    public function __construct(
        private readonly ProcessService $service,
        private readonly ProcessPresenter $presenter,
    ) {}

    public function dashboard(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', BusinessProcess::class);

        $user = $request->user();

        return Inertia::render(
            'Processes/Dashboard',
            $this->presenter->dashboard($user->organization, $user)
        );
    }

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', BusinessProcess::class);

        return Inertia::render(
            'Processes/Index',
            $this->presenter->catalogue($request, $request->user())
        );
    }

    public function catalogue(Request $request): InertiaResponse
    {
        return $this->index($request);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', BusinessProcess::class);

        $context = [
            'default_level' => $request->query('level') === 'sub_process' ? 'sub_process' : 'process',
            'default_parent_id' => $request->filled('parent_id') ? (int) $request->query('parent_id') : null,
            'default_entity_id' => $request->filled('entity_id') ? (int) $request->query('entity_id') : null,
        ];

        return Inertia::render(
            'Processes/Create',
            $this->presenter->formOptions($request->user()->organization, null, $context)
        );
    }

    public function store(StoreProcessRequest $request): RedirectResponse
    {
        $process = $this->asValidationError(fn () => $this->service->create($request->validated(), $request->user()));

        return redirect()
            ->route('risk.processes.show', $process)
            ->with('success', "Process {$process->code} — {$process->name} created successfully.");
    }

    public function show(Request $request, BusinessProcess $process): InertiaResponse
    {
        Gate::authorize('view', $process);

        return Inertia::render(
            'Processes/Show',
            $this->presenter->show($process, $request->user())
        );
    }

    public function edit(Request $request, BusinessProcess $process): InertiaResponse
    {
        Gate::authorize('update', $process);

        return Inertia::render(
            'Processes/Edit',
            $this->presenter->formOptions($request->user()->organization, $process)
        );
    }

    public function update(UpdateProcessRequest $request, BusinessProcess $process): RedirectResponse
    {
        $updated = $this->asValidationError(fn () => $this->service->update($process, $request->validated(), $request->user()));

        return redirect()
            ->route('risk.processes.show', $updated)
            ->with('success', "Process {$updated->code} — {$updated->name} updated successfully.");
    }

    public function toggleActive(Request $request, BusinessProcess $process): RedirectResponse
    {
        Gate::authorize('update', $process);

        if ($process->is_active) {
            $this->service->deactivate($process, $request->user());
            $message = "Process {$process->code} has been deactivated.";
        } else {
            $this->service->reactivate($process, $request->user());
            $message = "Process {$process->code} has been reactivated.";
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
        Gate::authorize('viewAny', BusinessProcess::class);

        ['headers' => $headers, 'rows' => $rows] = $this->presenter->exportRows($request);
        $filename = 'process_catalogue_'.now()->format('Y-m-d').'.csv';

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
        Gate::authorize('viewAny', BusinessProcess::class);

        ['headers' => $headers, 'rows' => $rows] = $this->presenter->exportRows($request);
        $user = $request->user();

        $content = $renderer->pdf('reports.pdf.tprm-operational-report', [
            'title' => 'Business Process Catalogue',
            'subtitle' => 'APQC PCF-aligned ERM process hierarchy (Process Group -> Process -> Sub-Process) and risk linkage.',
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

        $filename = 'process_catalogue_'.now()->format('Y-m-d').'.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * ProcessService enforces hierarchy rules a request cannot see in isolation —
     * e.g. an update that omits `level` on an existing sub-process, or a process that
     * gains children between form load and submit. Those rules throw
     * InvalidArgumentException; surface them as a form error, not a 500.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function asValidationError(callable $write): mixed
    {
        try {
            return $write();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['parent_id' => $e->getMessage()]);
        }
    }
}
