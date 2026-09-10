<?php

namespace App\Policies;

use App\Models\GeneratedReport;
use App\Models\User;

/**
 * Who may generate, watch and download a report (migration Phase 5.4).
 *
 * GENERATING IS NOT DOWNLOADING, AND NEITHER IS VIEWING. The seeded set has
 * always carried `report.view`, `report.generate` and `report.export`
 * separately, and the routes distinguish them; what this policy adds is the
 * tenant boundary, which the controller enforced by hand in
 * `assertSameTenant()` at one call site and by route-model binding everywhere
 * else.
 *
 * A generated report is a FILE ON DISK containing this institution's risk
 * register, loss history and capital position. Route-model binding already
 * resolves through the tenant scope; this keeps the guarantee if that scope is
 * ever bypassed upstream, which is exactly what the hand-written check was for.
 *
 * Reach is the tenant. Gate::before grants super-admin every ability first.
 */
class GeneratedReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('report.view');
    }

    public function view(User $user, GeneratedReport $report): bool
    {
        return $user->can('report.view') && $this->sameTenant($user, $report);
    }

    /** Queue a report for assembly. */
    public function create(User $user): bool
    {
        return $user->can('report.generate');
    }

    /**
     * Take the finished file.
     *
     * ASKS FOR `report.view`, WHICH IS WHAT THE DOWNLOAD ROUTE HAS ALWAYS
     * REQUIRED. `report.export` exists and is real — it guards the seven CSV
     * export routes — and requiring it here would read plausibly, since a
     * download is a document that leaves the platform while the on-screen
     * report is a summary in a browser. But it would silently lock the
     * download for every existing role holding `report.view` without
     * `report.export`, and a port does not get to change which permission a
     * screen needs. Same call as 5.3 made on the compliance panel; if the
     * distinction is wanted, it is a product decision with a migration for
     * existing roles behind it.
     */
    public function download(User $user, GeneratedReport $report): bool
    {
        return $this->view($user, $report);
    }

    private function sameTenant(User $user, GeneratedReport $report): bool
    {
        return (int) $report->organization_id === (int) $user->organization_id;
    }
}
