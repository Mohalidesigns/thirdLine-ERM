<?php

namespace App\Jobs;

use App\Models\Rcsa\RcsaExportJob;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Rcsa\RcsaExportService;
use App\Services\Rcsa\RcsaWorkbookWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use ThirdLine\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * Builds a large RCSA export off the request cycle (§10.2).
 *
 * TENANCY IS EXPLICIT. A queue worker has no session, so the global
 * organization scope is inert here and every read would otherwise cross
 * tenants. The export row itself is what says which tenant to bind — the same
 * rule GenerateReportJob follows, and the reason it is worth repeating is that
 * getting it wrong on THIS job would put one bank's risk profile in another
 * bank's download.
 *
 * THE ARTIFACT IS STORED, NOT REGENERATED. Following the link returns the file
 * that was built, not a fresh query — an extract a board or a regulator has
 * seen must not change underneath the link that produced it.
 *
 * A FAILURE IS RECORDED, NOT SWALLOWED. `failed()` writes the reason onto the
 * row, because an export the user is waiting for that simply never arrives is
 * indistinguishable from one still running.
 */
class GenerateRcsaExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public int $exportId) {}

    public function handle(RcsaExportService $exports, RcsaWorkbookWriter $writer): void
    {
        // withoutGlobalScopes: the worker has no tenant bound yet, and this row
        // is what tells us which one to bind.
        $export = RcsaExportJob::withoutGlobalScopes()->find($this->exportId);

        if ($export === null) {
            logger()->warning('GenerateRcsaExportJob: export row disappeared', ['export_id' => $this->exportId]);

            return;
        }

        TenantContext::set((int) $export->organization_id);

        $export->forceFill(['status' => RcsaExportJob::PROCESSING])->save();

        $user = User::withoutGlobalScopes()->find($export->user_id);

        $lines = $exports->lines((array) $export->filters, $user);

        $contents = $writer->bulkExport(
            lines: $lines,
            filters: (array) $export->filters,
            exportedBy: $user?->name,
        );

        $path = sprintf(
            'rcsa/exports/%d/rcsa-export-%d-%s.xlsx',
            $export->organization_id,
            $export->id,
            now()->format('Ymd-His'),
        );

        Storage::disk('local')->put($path, $contents);

        $export->forceFill([
            'status' => RcsaExportJob::READY,
            'file_path' => $path,
            'row_count' => $lines->count(),
        ])->save();

        $this->notify($export, $user);
    }

    public function failed(?Throwable $e): void
    {
        $export = RcsaExportJob::withoutGlobalScopes()->find($this->exportId);

        $export?->forceFill([
            'status' => RcsaExportJob::FAILED,
            'failure_reason' => $e?->getMessage(),
        ])->save();
    }

    /**
     * The in-app notification of §10.2, carrying the signed link.
     *
     * SIGNED AND EXPIRING, and still behind the permission and the tenant check
     * at the other end. The signature stops the URL being guessed or enumerated;
     * it is not authorisation, and a link forwarded to somebody without
     * `rcsa_export.bulk` still gets nothing.
     */
    private function notify(RcsaExportJob $export, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        $url = URL::temporarySignedRoute(
            'rcsa.exports.download',
            $export->expires_at ?? now()->addHours(RcsaExportService::LINK_TTL_HOURS),
            ['export' => $export->id],
            absolute: false,
        );

        NotificationService::send(
            organizationId: (int) $export->organization_id,
            userId: (int) $user->id,
            type: 'rcsa.export.ready',
            subject: 'Your RCSA export is ready',
            body: sprintf(
                '%d rows. The link expires %s.',
                $export->row_count,
                $export->expires_at?->diffForHumans() ?? 'in 48 hours',
            ),
            metadata: ['export_id' => $export->id],
            actionUrl: $url,
            priority: 'medium',
            category: 'report',
        );
    }
}
