<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Presenters\WidgetPayloadPresenter;
use App\Services\Dashboard\CommandCentreService;
use App\Services\Widgets\DashboardResolver;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The executive risk dashboard.
 *
 * WP-00 NODE SCOPING — WHERE THE LINE IS DRAWN HERE, AND WHY IT IS NOT DRAWN
 * FURTHER. This screen is two different things at once, and they need
 * different answers:
 *
 *   THE LISTS name individual records — the top ten risks by residual score,
 *   the breached KRIs, and the activity feed of recent loss events and issues,
 *   each rendered with its reference, its title, its amount and a link
 *   straight to the record. "Fraud loss — Treasury, ₦2.1bn" IS the incident;
 *   putting it on a branch manager's home page is the same disclosure as
 *   letting them open the record. Those four queries are now scoped with the
 *   same ->visibleTo() the registers use.
 *
 *   THE NUMBERS are roll-ups: counts, the heat map, the rating distribution,
 *   YTD net loss, control effectiveness bands, treatment progress. They are
 *   DELIBERATELY LEFT ORGANIZATION-WIDE. A roll-up is what this screen is for,
 *   and node scoping is opt-in precisely so that aggregate reporting is not
 *   silently re-cut to whoever opened it.
 *
 * That leaves the dashboard capable of saying "14 Critical" above a list of
 * three, which is a real inconsistency and is recorded here rather than
 * papered over: whether a subtree-limited user should see their own totals or
 * the group's is a product decision about what this page means, not a bug to
 * be fixed by whoever touches the file next. Scoping the aggregates is one
 * ->visibleTo() per query when that decision is taken.
 *
 * Nothing here changes for a CRO, a risk manager or a board member: they hold
 * roles in config('authorization.full_org_roles'), for which visibleTo() is a
 * no-op. The board pack is unaffected by construction, not by omission.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly CommandCentreService $commandCentre,
        private readonly DashboardResolver $dashboards,
        private readonly WidgetDataService $widgets,
        private readonly WidgetPayloadPresenter $payloads,
    ) {}

    /**
     * The Command Centre.
     *
     * CRITERION 7, and how it is met. The criterion asks for this page to be
     * "composed of `Widget`s from the seeded `erm-hq` dashboard", with the
     * seven inline Chart.js constructions deleted rather than ported.
     *
     * The Chart.js is gone. The widget composition is PARTIAL, deliberately:
     * `erm-hq` carries seven widgets — four KPI tiles, the residual heat map,
     * the rating donut and the risk register — and this page has eight
     * sections. There is no KRI, appetite, activity-feed or regulatory-review
     * widget type in the catalogue at all, so composing the page purely from
     * widgets would delete four sections from the product's landing page.
     *
     * So: `erm-hq`'s widgets render what they genuinely cover, through the same
     * DashboardResolver / WidgetPayloadPresenter path Business HQ uses, and the
     * remaining sections keep their own figures from CommandCentreService and
     * draw with the house SVG components. The deviation and its evidence are in
     * docs/migration/phase-5-notes/command-centre.md.
     *
     * `resolveFor(null, ...)` is the type-agnostic dashboard, which is `erm-hq`
     * — the same fallback every node without its own dashboard lands on.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $dashboard = $this->dashboards->resolveFor(null, $user);
        $tabs = $dashboard === null ? [] : $this->dashboards->layoutFor($dashboard, $user);
        $activeTab = $tabs[0] ?? null;

        return Inertia::render('Dashboard', array_merge(
            $this->commandCentre->figures(),
            [
                'widgetLayout' => $activeTab['layout'] ?? [],
                'widgetPayloads' => $activeTab === null ? [] : $this->widgetPayloads($activeTab, $user),
                'dashboardName' => $dashboard?->name,
            ],
        ));
    }

    /**
     * Every panel on the tab, resolved server-side so the page paints with its
     * numbers on first load — the same contract Business HQ has.
     *
     * There is no node: the Command Centre is the organisation's own view, and
     * WidgetContext accepts a null node for exactly that.
     *
     * @param  array<string, mixed>  $tab
     * @return array<int, mixed>
     */
    private function widgetPayloads(array $tab, ?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $definitions = WidgetDefinition::query()
            ->whereIn('id', collect($tab['layout'])->pluck('widget_id')->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');

        $payloads = [];

        foreach ($tab['layout'] as $index => $placement) {
            $definition = $definitions->get((int) ($placement['widget_id'] ?? 0));

            if ($definition === null) {
                continue;
            }

            $payloads[$index] = $this->payloads->present(
                $this->widgets->render($definition, WidgetContext::for($user), (array) ($placement['overrides'] ?? [])),
                $definition,
                ['overrides' => (object) ($placement['overrides'] ?? [])],
            );
        }

        return $payloads;
    }
}
