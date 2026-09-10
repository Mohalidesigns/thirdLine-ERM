<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\PciResponsibility;
use App\Services\Tprm\Contracts\PciMatrixBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PCI DSS 12.8.5 responsibility matrix — FR-CTR-08.
 *
 * The export is the deliverable: a QSA asks for this at every assessment and
 * almost nobody has it, so it gets assembled in a spreadsheet the week before
 * from memory. The export marks the rows nobody has confirmed rather than
 * presenting a vendor's unreviewed opinion as the agreed position — a matrix
 * that hid that distinction would be worse than none, because a QSA relies
 * on it.
 */
class PciMatrixController extends Controller
{
    public function __construct(private readonly PciMatrixBuilder $builder) {}

    public function show(Request $request, Engagement $engagement)
    {
        Gate::authorize('view', $engagement);

        $this->builder->ensureRows($engagement);

        return Inertia::render('Tprm/Pci/Matrix', [
            'matrix' => $this->builder->matrix($engagement),
            'engagementUrl' => route('tprm.engagements.show', $engagement),
            'can' => [
                'manage' => $request->user()->can('tprm.contract.manage'),
            ],
        ]);
    }

    /**
     * Pull the vendor's own view from its latest validated assessment.
     *
     * Confirmed rows are left alone, and the flash says so: a later assessment
     * must not overwrite an agreed position with the vendor's own view, or a
     * vendor could reassign a duty by answering a questionnaire differently
     * next year.
     */
    public function prepopulate(Request $request, Engagement $engagement)
    {
        Gate::authorize('tprm.contract.manage');

        $this->builder->ensureRows($engagement);
        $result = $this->builder->prepopulateFromAssessment($engagement);

        if ($result['source_assessment'] === null) {
            return back()->with('info', 'No validated assessment supplies SSRM ownership answers for this '
                .'engagement yet, so there is nothing to pre-populate from. Every row can be set by hand.');
        }

        return back()->with('success', sprintf(
            '%d row(s) pre-populated from the vendor\'s answers and %d confirmed row(s) left as they are. '
            .'Every pre-populated row is the VENDOR\'s view until somebody here confirms it.',
            $result['proposed'],
            $result['skipped_confirmed'],
        ));
    }

    public function confirm(Request $request, Engagement $engagement, PciResponsibility $row)
    {
        Gate::authorize('tprm.contract.manage');
        abort_unless($row->engagement_id === $engagement->getKey(), 404);

        $validated = $request->validate([
            'responsibility' => 'required|in:'.implode(',', PciResponsibility::RESPONSIBILITIES),
            'notes' => 'nullable|string|max:2000',
        ]);

        $this->builder->confirm(
            $row,
            $validated['responsibility'],
            $validated['notes'] ?? null,
            $request->user()->id,
        );

        return back()->with('success', 'The responsibility was confirmed.');
    }

    /**
     * The CSV a QSA takes away.
     *
     * Streamed rather than built in memory, and it carries the confirmation
     * state per row — the column that stops the document being read as
     * agreement it has not had.
     */
    public function export(Request $request, Engagement $engagement): StreamedResponse
    {
        Gate::authorize('view', $engagement);

        $matrix = $this->builder->matrix($engagement);
        $filename = 'pci-responsibility-matrix-'.$engagement->reference.'.csv';

        return response()->streamDownload(function () use ($matrix, $engagement) {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['PCI DSS v4.0.1 responsibility matrix (requirement 12.8.5)']);
            fputcsv($out, ['Engagement', $engagement->reference.' — '.$engagement->name]);
            fputcsv($out, ['Produced', now()->toDayDateTimeString()]);
            fputcsv($out, [
                'Rows confirmed',
                sprintf('%d of %d', $matrix['confirmed'], $matrix['total']),
            ]);

            if (! $matrix['export_ready']) {
                fputcsv($out, [
                    'Note',
                    'Rows marked "Not confirmed" below have not been agreed with the provider. Where the source '
                    .'is the provider\'s own assessment answers, they are the provider\'s view of who is '
                    .'responsible and not an agreed position.',
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Requirement', 'Description', 'Responsibility', 'Source', 'Confirmed', 'Confirmed by', 'Notes']);

            foreach ($matrix['rows'] as $row) {
                fputcsv($out, [
                    $row['requirement'],
                    $row['description'],
                    $row['responsibility_label'],
                    $row['source'] === PciResponsibility::SOURCE_CAIQ
                        ? 'Provider\'s assessment answers'
                        : 'Recorded here',
                    $row['confirmed'] ? 'Confirmed '.$row['confirmed_at'] : 'Not confirmed',
                    $row['confirmed_by'] ?? '',
                    $row['notes'] ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
