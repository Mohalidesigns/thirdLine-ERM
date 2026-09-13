<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\RaciRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreBcmsProcessRequest;
use App\Models\Bcms\Process;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\Bcms\ProcessCatalogueService;
use App\Services\Bcms\RaciService;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The process catalogue — the substrate every phase downstream binds to.
 *
 * ORG SCOPING IS APPLIED THROUGH THE SHARED TRAIT, not written here
 * (Orchestration §5). `visibleTo()` delegates to the product's existing
 * `RcsaScope` engine and adds the one BCMS difference: a process with no
 * business unit belongs to the organisation and is visible to everyone.
 * Acceptance criterion 5 tests both directions.
 */
class ProcessController extends Controller
{
    public function __construct(
        private ProcessCatalogueService $catalogue,
        private RaciService $raci,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.process.view');

        $filters = $request->only(['search', 'tier', 'critical', 'unit', 'gap']);

        $query = Process::query()
            ->visibleTo($request->user())
            ->with(['businessUnit:id,code,name', 'owner:id,name', 'parent:id,code,name'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
            ))
            ->when($filters['tier'] ?? null, fn ($q, $tier) => $q->where('criticality_tier', $tier))
            ->when(($filters['critical'] ?? null) === 'yes', fn ($q) => $q->where('is_critical_service', true))
            ->when($filters['unit'] ?? null, fn ($q, $unit) => $q->where('business_unit_id', $unit))
            ->orderBy('code');

        $gaps = $this->raci->processGaps();

        if (($filters['gap'] ?? null) === 'no_accountable') {
            $query->whereIn('id', array_column($gaps['without_accountable'], 'id'));
        }

        $processes = $query->paginate(50)->withQueryString();

        $raci = \App\Models\Bcms\RaciAssignment::query()
            ->where('assignable_type', 'bcms_process')
            ->whereIn('assignable_id', $processes->pluck('id'))
            ->with('user:id,name,is_active')
            ->get()
            ->groupBy('assignable_id');

        return Inertia::render('Bcms/Processes/Index', [
            'processes' => $processes->through(fn (Process $p) => [
                'id' => $p->getKey(),
                'uuid' => $p->uuid,
                'code' => $p->code,
                'name' => $p->name,
                'description' => $p->description,
                'unit' => $p->businessUnit?->name,
                'parent' => $p->parent?->code,
                'owner' => $p->owner?->name,
                'category' => $p->category,
                'tier' => $p->criticality_tier,
                'is_critical_service' => (bool) $p->is_critical_service,
                'critical_service_justification' => $p->critical_service_justification,
                'regulatory_flags' => $p->regulatory_flags ?? [],
                'raci' => ($raci->get($p->getKey()) ?? collect())->map(fn ($r) => [
                    'role' => $r->raci_role->value,
                    'user_id' => $r->user_id,
                    'name' => $r->user?->name,
                    'active' => (bool) ($r->user->is_active ?? false),
                ])->values()->all(),
            ]),
            'filters' => $filters,
            'gaps' => $gaps,
            'business_units' => BusinessUnit::query()->orderBy('name')->get(['id', 'code', 'name']),
            'source_processes' => BusinessProcess::query()->orderBy('code')->get(['id', 'code', 'name']),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'raci_roles' => array_map(fn (RaciRole $r) => [
                'value' => $r->value, 'label' => $r->label(), 'description' => $r->description(),
            ], RaciRole::cases()),
            'columns' => ProcessCatalogueService::HEADERS,
            'can' => ['manage' => $request->user()?->can('bcms.process.manage') === true],
        ]);
    }

    public function store(StoreBcmsProcessRequest $request): RedirectResponse
    {
        $process = Process::query()->create($request->validated() + [
            'status' => 'active',
            'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
            'created_by' => $request->user()?->getKey(),
        ]);

        return back()->with('success', "Process {$process->code} added.");
    }

    public function update(StoreBcmsProcessRequest $request, Process $process): RedirectResponse
    {
        $process->update($request->validated() + ['updated_by' => $request->user()?->getKey()]);

        return back()->with('success', "Process {$process->code} updated.");
    }

    public function assignRaci(Request $request, Process $process): RedirectResponse
    {
        Gate::authorize('bcms.process.manage');

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'raci_role' => ['required', 'in:R,A,C,I'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        $this->raci->assign($process, (int) $data['user_id'], RaciRole::from($data['raci_role']), $data['note'] ?? null);

        return back()->with('success', 'RACI updated.');
    }

    public function removeRaci(Request $request, Process $process): RedirectResponse
    {
        Gate::authorize('bcms.process.manage');

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'raci_role' => ['required', 'in:R,A,C,I'],
        ]);

        $this->raci->unassign($process, (int) $data['user_id'], RaciRole::from($data['raci_role']));

        return back()->with('success', 'RACI updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Import and export */
    /* ------------------------------------------------------------------ */

    /**
     * Validate a workbook and report what WOULD happen. Writes nothing.
     *
     * The dry run is a separate endpoint from the import on purpose: a customer
     * uploading two hundred rows needs to see the six errors before anything is
     * written, and an import that half-succeeds leaves them reconciling by hand.
     */
    public function dryRun(Request $request, FileUploadService $uploads): RedirectResponse
    {
        Gate::authorize('bcms.process.manage');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240']]);

        $path = $request->file('file')->getRealPath();
        $preview = $this->catalogue->dryRun($path);

        return back()->with('bcms_import_preview', [
            'valid' => $preview['valid'],
            'invalid' => $preview['invalid'],
            'errors' => array_slice($preview['errors'], 0, 200),
            'total_errors' => count($preview['errors']),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.process.manage');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240']]);

        try {
            $result = $this->catalogue->import($request->file('file')->getRealPath(), $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$result['created']} process(es) created, {$result['updated']} updated.");
    }

    /**
     * Export the catalogue as CSV.
     *
     * The same columns the importer reads, in the same order, with the same
     * headers — the realistic workflow is export, edit in Excel, re-import, and
     * a round trip that drops a column silently deletes whatever was in it.
     */
    public function export(): StreamedResponse
    {
        Gate::authorize('bcms.process.view');

        $rows = $this->catalogue->exportRows();
        $headers = ProcessCatalogueService::HEADERS;

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'bcms-process-catalogue-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
