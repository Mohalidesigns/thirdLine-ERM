<?php

namespace App\Jobs;

use App\Models\GeneratedReport;
use App\Models\Organization;
use App\Models\User;
use App\Services\BoardPackAssembler;
use App\Services\ReportDataService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\TenantContext;
use ThirdLine\Reporting\DocumentRenderer;
use Throwable;

/**
 * Renders a report off the request cycle and files the bytes.
 *
 * A board pack walks the whole register — every risk, control, indicator, loss
 * event, issue, treatment plan and filing deadline — and then lays that out
 * across a paginated PDF. Doing that inside a web request means a bank with a
 * real register waits behind a spinner until PHP's execution limit kills it.
 *
 * Two things matter here beyond moving the work:
 *
 *  1. **The artifact is stored, not regenerated.** Downloading yesterday's pack
 *     returns yesterday's pack. Previously the download route re-ran the query,
 *     so the document you fetched changed as the data moved underneath it —
 *     which for anything a board or a regulator has seen is indefensible.
 *
 *  2. **Tenancy is explicit.** A queue worker has no session, so the global
 *     tenant scope is inert here. Every read is bound to the organization
 *     recorded on the report row via TenantContext::set().
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Report types this job knows how to build.
     */
    public const TYPES = ['board_pack', 'executive', 'regulatory', 'risk_register'];

    public function __construct(
        public int $reportId,
    ) {}

    public function handle(
        DocumentRenderer $renderer,
        BoardPackAssembler $assembler,
        ReportDataService $reportData,
    ): void {
        // withoutGlobalScopes: the worker has no tenant bound yet, and the row
        // itself is what tells us which tenant to bind.
        $report = GeneratedReport::withoutGlobalScopes()->find($this->reportId);

        if (! $report) {
            Log::warning('GenerateReportJob: report row disappeared', ['report_id' => $this->reportId]);

            return;
        }

        TenantContext::set($report->organization_id);

        $report->update([
            'status' => 'processing',
            'progress_pct' => 10,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $organization = Organization::findOrFail($report->organization_id);
            $requestedBy = $report->generated_by ? User::find($report->generated_by) : null;

            $parameters = (array) ($report->parameters ?? []);
            $format = $renderer->normalise($parameters['format'] ?? 'pdf');
            $asAt = isset($parameters['as_at'])
                ? CarbonImmutable::parse($parameters['as_at'])
                : CarbonImmutable::now();

            $report->update(['progress_pct' => 30]);

            $rendered = match ($report->report_type) {
                'board_pack' => $this->buildBoardPack($assembler, $organization, $asAt, $requestedBy, $report),
                default => $this->buildStandardReport(
                    $renderer, $reportData, $report, $organization, $asAt, $requestedBy, $format
                ),
            };

            $report->update(['progress_pct' => 75]);

            $disk = config('filesystems.default', 'local');
            $fileName = $this->fileName($report, $rendered['extension']);
            $path = sprintf('reports/%d/%s', $report->organization_id, $fileName);

            Storage::disk($disk)->put($path, $rendered['content']);

            $report->update([
                'status' => 'completed',
                'progress_pct' => 100,
                'format' => $rendered['extension'],
                'disk' => $disk,
                'file_path' => $path,
                'file_name' => $fileName,
                'mime_type' => $rendered['mime'],
                'size_bytes' => strlen($rendered['content']),
                'period_as_at' => $asAt->toDateString(),
                'completed_at' => now(),
                // The stored artifact is the download; re-running the old route
                // would produce a different document.
                'download_route' => null,
            ]);
        } catch (Throwable $e) {
            // Record the failure on the row so the user sees why, rather than a
            // report that sits at "processing" forever.
            $report->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 1000),
                'completed_at' => now(),
            ]);

            Log::error('GenerateReportJob failed', [
                'report_id' => $report->id,
                'organization_id' => $report->organization_id,
                'report_type' => $report->report_type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Marks the row failed when every retry is exhausted, so a report never
     * sits at "processing" because the worker died.
     */
    public function failed(Throwable $e): void
    {
        $report = GeneratedReport::withoutGlobalScopes()->find($this->reportId);

        $report?->update([
            'status' => 'failed',
            'error_message' => Str::limit($e->getMessage(), 1000),
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array{content: string, mime: string, extension: string}
     */
    private function buildBoardPack(
        BoardPackAssembler $assembler,
        Organization $organization,
        CarbonImmutable $asAt,
        ?User $requestedBy,
        GeneratedReport $report,
    ): array {
        $version = (int) ($report->version ?: 1);

        $built = $assembler->build($organization, $asAt, $requestedBy, $version);

        return [
            'content' => $built['content'],
            'mime' => $built['mime'],
            'extension' => $built['extension'],
        ];
    }

    /**
     * @return array{content: string, mime: string, extension: string}
     */
    private function buildStandardReport(
        DocumentRenderer $renderer,
        ReportDataService $reportData,
        GeneratedReport $report,
        Organization $organization,
        CarbonImmutable $asAt,
        ?User $requestedBy,
        string $format,
    ): array {
        $payload = $reportData->payload(
            $report->report_type,
            $organization,
            $asAt,
            (array) ($report->parameters ?? [])
        );

        $payload['organization'] = $organization;
        $payload['generatedBy'] = $requestedBy?->name;
        $payload['generatedAt'] = CarbonImmutable::now();
        $payload['periodAsAt'] = $asAt;

        return $renderer->render($payload['view'], $payload, $format);
    }

    private function fileName(GeneratedReport $report, string $extension): string
    {
        return sprintf(
            '%s-v%d-%s.%s',
            Str::slug($report->name ?: $report->report_type),
            (int) ($report->version ?: 1),
            now()->format('Ymd-His'),
            $extension
        );
    }
}
