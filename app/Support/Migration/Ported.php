<?php

namespace App\Support\Migration;

use Illuminate\Support\Facades\Route;

/**
 * Which routes render through Inertia today.
 *
 * Until Phase 6 the application has two renderers, and a link has to know
 * which one is on the other end: an Inertia <Link> to a Blade page gets a
 * non-Inertia HTML response and shows it in an error modal, and a Blade
 * `wire:navigate` to an Inertia page swaps in a document whose React bundle
 * never boots. Both sidebars (NavPresenter for React, sidebar.blade.php for
 * Blade) consult this list so that a link crossing the boundary is a plain
 * full-page navigation. Grows by one entry per ported route, and shrinks to
 * nothing worth keeping when the Blade side is gone.
 */
final class Ported
{
    /** @var list<string> */
    public const ROUTES = [
        // Phase 0
        'my.index',
        'admin.license',
        // Phase 1
        'login',
        'password.request',
        'password.reset',
        'mfa.setup',
        'mfa.verify',
        'profile.edit',
        'notifications.index',
        'search.index',
        // Phase 2
        'risk.scoping.index',
        'hq.index',
        'hq.show',
        'risk.dashboards.index',
        'risk.dashboards.edit',
        'risk.controls.index',
        'risk.register.index',
        'risk.treatments.index',
        'risk.issues.index',
        'risk.loss-events.index',
        'risk.loss-events.near-misses',
        'risk.kri.index',
        'risk.kri.breaches',
        'risk.assessments.index',
        'risk.control-tests.index',
        'risk.campaigns.index',
        'risk.questionnaires.index',
        'risk.questionnaires.library',
        'risk.imports.index',
        'admin.users.index',
        'risk.emerging.index',
        'risk.reports.library',
        'risk.approvals.history',
        'risk.regulatory.circulars',
        // Phase 3 — appetite
        'risk.appetite.index',
        // Phase 3 — scoping
        'risk.scoping.dashboard',
        'risk.scoping.create',
        'risk.scoping.show',
        'risk.scoping.edit',
        // Phase 3 — risk register
        'risk.register.create',
        'risk.register.show',
        'risk.register.edit',
        // Phase 3 — assessments
        'risk.assessments.create',
        'risk.assessments.show',
        'risk.assessments.edit',
        // Phase 3 — controls and control testing
        'risk.controls.create',
        'risk.controls.show',
        'risk.controls.edit',
        'risk.control-tests.dashboard',
        'risk.control-tests.create',
        'risk.control-tests.show',
        'risk.control-tests.edit',
        // Phase 3 — treatment plans ('risk.treatments.index' is above, from
        // Phase 2's grid work)
        'risk.treatments.dashboard',
        'risk.treatments.review',
        'risk.treatments.create',
        'risk.treatments.show',
        'risk.treatments.edit',
        // Phase 4 — KRI ('risk.kri.index' and '.breaches' are above, from
        // Phase 2's grid work)
        'risk.kri.dashboard',
        'risk.kri.create',
        'risk.kri.show',
        'risk.kri.edit',
        'risk.kri.thresholds',
        // Phase 4 — issues ('risk.issues.index' is above, from Phase 2's
        // grid work)
        'risk.issues.dashboard',
        'risk.issues.create',
        'risk.issues.show',
        'risk.issues.edit',
        'risk.issues.ageing',
        'risk.issues.closure',
        // Phase 4 — loss events ('risk.loss-events.index' and '.near-misses'
        // are above, from Phase 2's grid work)
        'risk.loss-events.dashboard',
        'risk.loss-events.create',
        'risk.loss-events.show',
        'risk.loss-events.edit',
        'risk.loss-events.approvals',
        'risk.loss-events.rca',
        'risk.loss-events.reports',
        'risk.loss-events.create-near-miss',
        // Phase 4 — periods and threshold re-baselining
        'risk.periods.index',
        'risk.thresholds.rebaseline',
        // Phase 4 — campaigns and questionnaires ('risk.campaigns.index',
        // 'risk.questionnaires.index' and '.library' are above, from Phase 2's
        // grid work)
        'risk.campaigns.dashboard',
        'risk.campaigns.create',
        'risk.campaigns.show',
        'risk.campaigns.respond',
        'risk.campaigns.submission',
        'risk.questionnaires.create',
        'risk.questionnaires.show',
        'risk.questionnaires.edit',
        // Phase 4 — emerging risks ('risk.emerging.index' is above, from
        // Phase 2's grid work)
        'risk.emerging.create',
        'risk.emerging.edit',
        // Phase 5 — analysis
        'risk.analysis.heatmap',
        'risk.analysis.bowtie',
        'risk.analysis.trends',
        'risk.analysis.correlation',
        // Phase 5 — quantification reports
        'risk.quantification.reports',
        'risk.quantification.reports.capital-adequacy',
        'risk.quantification.reports.stress-testing',
        'risk.quantification.reports.risk-contribution',
        'risk.quantification.reports.regulatory-pack',
        // Phase 5 — quantification scenarios, runs and settings
        'risk.quantification.scenarios',
        'risk.quantification.create-scenario',
        'risk.quantification.edit-scenario',
        'risk.quantification.show-scenario',
        'risk.quantification.simulate',
        'risk.quantification.results',
        'risk.quantification.show-results',
        'risk.quantification.icaap',
        'risk.quantification.library',
        'risk.quantification.settings',
        // Phase 3 — RCSA
        'risk.rcsa.dashboard',
        'risk.rcsa.worksheet',
        'risk.rcsa.controls',
        'risk.rcsa.matrix',
        // Phase 3 — workflow
        'risk.approvals.dashboard',
        'risk.my-tasks.index',
        'risk.my-tasks.show',
        'risk.workflows.dashboard',
        'risk.workflows.definitions',
        'risk.workflows.show-instance',
    ];

    public static function isRoute(string $name): bool
    {
        return in_array($name, self::ROUTES, true);
    }

    /**
     * Is this URL path served by an Inertia page? Matches parameterless
     * routes only, which is every entry the Blade sidebar links to.
     */
    public static function isPath(string $path): bool
    {
        $path = '/'.trim((string) parse_url($path, PHP_URL_PATH), '/');

        return in_array($path, self::paths(), true);
    }

    /**
     * The `wire:navigate` attribute for a Blade link, or nothing when the
     * destination is an Inertia page.
     */
    public static function navigateAttribute(string $path): string
    {
        return self::isPath($path) ? '' : 'wire:navigate';
    }

    /**
     * @return list<string>
     */
    private static function paths(): array
    {
        static $paths = null;

        if ($paths !== null) {
            return $paths;
        }

        $paths = [];

        foreach (self::ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route !== null && ! str_contains($route->uri(), '{')) {
                $paths[] = '/'.trim($route->uri(), '/');
            }
        }

        return $paths;
    }
}
