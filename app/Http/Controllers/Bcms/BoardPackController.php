<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Services\Bcms\Reports\BoardPackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The resilience board pack — `docs/bcms/screens/board-pack-preview.md`.
 *
 * READ/EXPORT SPLIT, DELIBERATELY. A `bcms.report.view` holder sees the full
 * preview; only `bcms.report.export` may generate the branded document —
 * matching `evidence-pack-export.md`'s own split, for the same reason.
 */
class BoardPackController extends Controller
{
    public function __construct(private readonly BoardPackService $boardPack) {}

    public function index(Request $request)
    {
        Gate::authorize('bcms.report.view');

        $year = (int) ($request->input('year') ?? now()->year);

        return Inertia::render('Bcms/Reports/BoardPack', [
            'year' => $year,
            'preview' => $this->boardPack->stampedPreview($year),
            'can' => ['export' => $request->user()?->can('bcms.report.export') ?? false],
        ]);
    }

    public function export(Request $request): Response
    {
        Gate::authorize('bcms.report.export');

        $request->validate(['format' => 'required|string|in:pdf,pptx']);

        $year = (int) ($request->input('year') ?? now()->year);
        $format = (string) $request->input('format');

        if ($format === 'pptx') {
            $bytes = $this->boardPack->generatePptx($year, $request->user());
            $contentType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
            $filename = "board-pack-{$year}.pptx";
        } else {
            $bytes = $this->boardPack->generatePdf($year, $request->user());
            $contentType = 'application/pdf';
            $filename = "board-pack-{$year}.pdf";
        }

        return response($bytes, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
