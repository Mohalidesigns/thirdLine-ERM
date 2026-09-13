<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\BiaAssessmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\BiaCampaign;
use App\Models\Bcms\Process;
use App\Services\Bcms\Bia\DependencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The BIA report — the clause 8.2.2 artefact an examiner asks for.
 *
 * RANKED BY CRITICALITY, NOT BY CODE. The question the report answers is "what
 * matters most and how quickly must it come back"; alphabetical order answers a
 * different one nobody asked.
 *
 * IT REPORTS COVERAGE AS WELL AS CONTENT. A report over eleven approved
 * assessments out of fifty processes is a report about eleven processes, and
 * saying so at the top is the difference between evidence and a selective
 * extract. The gap list is part of the artefact.
 */
class BiaReportController extends Controller
{
    public function __construct(private DependencyService $dependencies) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.bia.view');

        $campaignId = $request->integer('campaign') ?: null;

        return Inertia::render('Bcms/Bia/Report', $this->build($request, $campaignId) + [
            'campaigns' => BiaCampaign::query()->orderByDesc('id')->get(['id', 'name', 'status']),
            'selected' => $campaignId,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('bcms.report.export');

        $data = $this->build($request, $request->integer('campaign') ?: null);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Code', 'Process', 'Business unit', 'Tier', 'Critical service',
                'MTPD (hours)', 'RTO (hours)', 'RPO (minutes)', 'MBCO',
                'Minimum staff', 'Workaround', 'Dependencies', 'Single points of failure',
                'Status', 'Approved on',
            ]);

            foreach ($data['rows'] as $row) {
                fputcsv($out, [
                    $row['code'], $row['name'], $row['unit'], $row['tier'],
                    $row['is_critical_service'] ? 'yes' : 'no',
                    $row['mtpd_hours'], $row['rto_hours'], $row['rpo_minutes'], $row['mbco'],
                    $row['min_staff_required'],
                    $row['workaround_available'] ? $row['workaround_max_duration_hours'] : 'none',
                    $row['dependency_count'], $row['spof_count'],
                    $row['status'], $row['approved_at'],
                ]);
            }

            // The gaps go IN the export, not beside it. A spreadsheet that
            // lists eleven processes and says nothing about the other
            // thirty-nine is one somebody will circulate as the whole picture.
            if ($data['gaps'] !== []) {
                fputcsv($out, []);
                fputcsv($out, ['Processes with no approved BIA — not represented above']);
                fputcsv($out, ['Code', 'Process', 'Business unit', 'Status']);

                foreach ($data['gaps'] as $gap) {
                    fputcsv($out, [$gap['code'], $gap['name'], $gap['unit'], $gap['status']]);
                }
            }

            fclose($out);
        }, 'bcms-bia-report-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function build(Request $request, ?int $campaignId): array
    {
        $assessments = BiaAssessment::query()
            ->where('status', BiaAssessmentStatus::Approved->value)
            ->when($campaignId, fn ($q, $v) => $q->where('campaign_id', $v))
            ->whereHas('process', fn (\Illuminate\Database\Eloquent\Builder $q) => Process::visibleQuery($q, $request->user()))
            ->with(['process.businessUnit:id,name', 'dependencies'])
            ->get();

        $rows = $assessments->map(function (BiaAssessment $a) {
            $spofs = $a->dependencies->where('single_point_of_failure', true);

            return [
                'id' => $a->getKey(),
                'code' => $a->process?->code,
                'name' => $a->process?->name,
                'unit' => $a->process?->businessUnit?->name,
                'tier' => $a->process?->criticality_tier,
                'is_critical_service' => (bool) $a->process?->is_critical_service,
                'mtpd_hours' => $a->mtpd_hours === null ? null : (float) $a->mtpd_hours,
                'rto_hours' => $a->rto_hours === null ? null : (float) $a->rto_hours,
                'rpo_minutes' => $a->rpo_minutes === null ? null : (int) $a->rpo_minutes,
                'mbco' => $a->mbco_description,
                'min_staff_required' => $a->min_staff_required,
                'workaround_available' => (bool) $a->workaround_available,
                'workaround_max_duration_hours' => $a->workaround_max_duration_hours === null ? null : (float) $a->workaround_max_duration_hours,
                'dependency_count' => $a->dependencies->count(),
                'spof_count' => $spofs->count(),
                'status' => 'approved',
                'approved_at' => $a->approved_at?->toDateString(),
                'ai_generated' => (bool) $a->ai_generated,
            ];
        })
            ->sortBy([
                fn (array $a, array $b) => ($a['tier'] ?? 9) <=> ($b['tier'] ?? 9),
                fn (array $a, array $b) => ($a['rto_hours'] ?? PHP_FLOAT_MAX) <=> ($b['rto_hours'] ?? PHP_FLOAT_MAX),
            ])
            ->values()
            ->all();

        $covered = $assessments->pluck('process_id')->filter()->unique();

        $gaps = Process::query()
            ->visibleTo($request->user())
            ->where('status', 'active')
            ->whereNotIn('id', $covered->all())
            ->with('businessUnit:id,name')
            ->orderBy('criticality_tier')
            ->orderBy('code')
            ->get()
            ->map(fn (Process $p) => [
                'id' => $p->getKey(),
                'code' => $p->code,
                'name' => $p->name,
                'unit' => $p->businessUnit?->name,
                'tier' => $p->criticality_tier,
                'status' => BiaAssessment::query()->where('process_id', $p->getKey())->exists()
                    ? 'assessment in progress'
                    : 'no assessment',
            ])
            ->all();

        $inScope = count($rows) + count($gaps);

        return [
            'rows' => $rows,
            'gaps' => $gaps,
            'coverage' => [
                'approved' => count($rows),
                'in_scope' => $inScope,
                // Null over an empty catalogue: a coverage rate with no
                // denominator is undefined, not zero.
                'rate' => $inScope === 0 ? null : round(count($rows) / $inScope * 100, 1),
            ],
            'spof_register' => $this->dependencies->spofRegister(),
            'shared' => $this->dependencies->sharedDependencies(),
            'can' => ['export' => $request->user()?->can('bcms.report.export') === true],
        ];
    }
}
