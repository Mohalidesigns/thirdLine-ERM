<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Services\Bcms\Reports\CsatPrefillService;
use App\Services\Bcms\Reports\EvidencePackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The regulator evidence pack — `docs/bcms/screens/evidence-pack-export.md`.
 *
 * SYNCHRONOUS ONLY, FOR NOW. The screen spec's queued path for a full-year
 * ISO 22301/CBN CSF pack (`useJobProgress`, a background job, a signed
 * download link) is deferred — named here rather than silently built partway.
 * Every pack this phase produces is generated and streamed back on the same
 * request, which is correct for the sync/queue threshold on any tenant whose
 * clause count is what this build's nineteen-plus-crosswalk rows actually
 * are; a customer whose estate makes that request time out is exactly the
 * signal that turns the deferred queue path into the next piece of work, not
 * a reason to fake one now.
 */
class EvidencePackController extends Controller
{
    public function __construct(
        private readonly EvidencePackService $packs,
        private readonly CsatPrefillService $csat,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('bcms.report.export');

        return Inertia::render('Bcms/Reports/EvidencePack', [
            'frameworks' => EvidencePackService::FRAMEWORKS,
            'log' => $this->packs->log(),
        ]);
    }

    public function preview(Request $request)
    {
        Gate::authorize('bcms.report.export');

        $request->validate(['framework' => 'required|string|in:'.implode(',', EvidencePackService::FRAMEWORKS)]);

        return response()->json($this->packs->preview((string) $request->input('framework')));
    }

    public function store(Request $request): Response
    {
        Gate::authorize('bcms.report.export');

        $request->validate([
            'framework' => 'required|string|in:'.implode(',', EvidencePackService::FRAMEWORKS),
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $framework = (string) $request->input('framework');
        $from = (string) ($request->input('from') ?? now()->startOfYear()->toDateString());
        $to = (string) ($request->input('to') ?? now()->endOfYear()->toDateString());

        $bytes = $this->packs->generate($framework, $from, $to, $request->user());

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->packs->filename($framework).'"',
        ]);
    }

    public function csatPrefill(Request $request): Response
    {
        Gate::authorize('bcms.report.export');

        // B9 (gate 1 code review #1, compliance-analyst's NDPA register §11
        // finding): the upload rule had no size limit at all. 20 MB is
        // generous for a CSAT workbook (RCSA's own template writers work
        // over workbooks an order of magnitude smaller) and still bounds
        // the request.
        $request->validate(['workbook' => 'required|file|mimes:xlsx|max:20480']);

        $result = $this->csat->fill($request->file('workbook')->getRealPath(), $request->user());

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="csat-prefilled-'.now()->format('Y').'.xlsx"',
            'X-Csat-Matched' => (string) $result['matched'],
            'X-Csat-Unmatched' => (string) count($result['unmatched']),
            // A9 (gate 1 code review #2): "found but not overwritten" is a
            // different fact from "not found at all" — the cover sheet
            // already names each one; this header mirrors the count the
            // same way X-Csat-Unmatched already does.
            'X-Csat-Occupied' => (string) count($result['occupied']),
        ]);
    }
}
