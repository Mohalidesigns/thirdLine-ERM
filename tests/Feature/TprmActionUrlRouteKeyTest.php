<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the defect found live in the dev tenant on 2026-09-13.
 *
 * `IntakeController` shipped the Intake Queue's numeric `id` for `Engagement`,
 * which route-binds on its `uuid` (`App\Models\Tprm\Concerns\HasTprmUuid`),
 * and `Index.jsx` built `tryRoute('tprm.intake.approve', engagementId)` from
 * it — `POST /risk/tprm/intake/8/approve` 404s, verified over HTTP against a
 * logged-in session, because the model resolves nothing at key `8`. Every
 * submitted intake was un-approvable from the UI, and nothing tested
 * `tprm.intake.approve` or `.reject` over HTTP. A sweep of the rest of the
 * module found the same shape at eighteen more sites, across eight screens.
 *
 * THE RULE. A React page under `resources/js/Pages/Tprm` must not build a
 * `tprm.*` action URL from a bare numeric-looking identifier — `<expr>.id`,
 * a bare `id`, or a variable/prop ending in `Id` — because fourteen of this
 * module's models carry `HasTprmUuid` and route-bind on `uuid` instead, and
 * the two look identical in a prop until somebody posts to the URL. The fix,
 * at every site fixed alongside this test, was a server-built URL in the
 * controller or presenter (`'approve_url' => route('tprm.intake.approve',
 * $engagement)`): one home for the URL means a route-key change can never
 * break the screen again.
 *
 * THE ALLOWLIST is not "reviewed and safe" in general. It is narrowly the
 * route names checked, one by one against the model each bound parameter
 * resolves to, to confirm that model route-keys on its plain numeric `id` —
 * see the comment on each entry. A route moves off this list the moment its
 * model gains `HasTprmUuid`, and nothing here would catch that on its own;
 * that is why growing the list is gated by the meta-test below, mirroring
 * `RouteAuthorizationTest` and `NoFabricatedNumbersTest`.
 */
class TprmActionUrlRouteKeyTest extends TestCase
{
    private const SEARCH_PATH = 'resources/js/Pages/Tprm';

    /**
     * A `route(`/`tryRoute(` call naming a `tprm.*` route, with its second
     * argument captured separately. Non-greedy up to the first `)`, which is
     * safe here because every site this test has ever found is either a bare
     * identifier or a two-element array literal — neither contains a `)` of
     * its own.
     *
     * Scoped per LINE, mirroring `NoFabricatedNumbersTest`: every site this
     * test exists to catch — and every site it caught during the sweep that
     * added it — was a single-line call.
     */
    private const CALL_PATTERN = '/\b(?:route|tryRoute)\(\s*\'(tprm\.[a-zA-Z0-9_.\-]+)\'\s*,\s*(.*?)\)/';

    /**
     * A numeric-looking identifier inside a route's argument: `.id`, a bare
     * `id`, a `fooId`-style variable, or a snake_case foreign key such as
     * `row.engagement_id` — the codebase's dominant id spelling in props, and
     * one `\b` cannot see because `_` is a word character (Gate 2, 2026-09-13:
     * `MonitoringController` already ships `finding_id` and `assessment_id`
     * for two uuid-keyed models). Deliberately NOT matching `.uuid` — `uuid`
     * does not end in `Id` (capital I), does not end in `_id`, and does not
     * contain the substring `.id`, so a correctly-fixed site never trips this
     * rule.
     */
    private const NUMERIC_ARG_PATTERN = '/\.id\b|\bid\b|\b[A-Za-z_][A-Za-z0-9_]*Id\b|_id\b/';

    /**
     * Route names checked against the model their bound parameter resolves
     * to, and confirmed to key on a plain numeric `id`. Each comment names
     * the model and, where it is not obvious, why.
     *
     * @var list<string>
     */
    private const NUMERIC_KEY_ROUTE_ALLOWLIST = [
        // Ruleset — no HasTprmUuid.
        'tprm.rulesets.update',
        'tprm.rulesets.simulate',
        'tprm.rulesets.publish',
        // ClauseLibraryEntry overrides resolveRouteBinding() for tenancy
        // (system clauses with a null organization_id), not
        // getRouteKeyName() — it still keys on `id`.
        'tprm.clauses.destroy',
        'tprm.clauses.update',
        // AccessGrant / Connection — neither has HasTprmUuid.
        'tprm.access.grants.approve',
        'tprm.access.grants.revoke',
        'tprm.access.connections.close',
        // Contract — no HasTprmUuid.
        'tprm.contracts.gap-report',
        'tprm.contracts.analyse',
        'tprm.contracts.obligations.generate',
        // Contract + ClauseLibraryEntry / ContractClause — neither of the
        // second parameters has HasTprmUuid either, so the two-element array
        // these three routes are called with is sound in both positions.
        'tprm.contracts.clauses.determine',
        'tprm.contracts.clauses.review',
        'tprm.contracts.clauses.waive',
        // ImportBatch — no HasTprmUuid.
        'tprm.imports.roll-back',
        'tprm.imports.dry-run',
        'tprm.imports.commit',
        // QuestionnaireTemplate overrides resolveRouteBinding() for tenancy
        // (shipped packs with a null organization_id), not
        // getRouteKeyName() — it still keys on `id`.
        'tprm.templates.clone',
        'tprm.templates.publish',
        // NthPartyEdge — no HasTprmUuid.
        'tprm.concentration.edges.confirm',
        'tprm.concentration.edges.reject',
        // Sla — no HasTprmUuid.
        'tprm.slas.measurements.store',
        // DueDiligenceChecklist / DueDiligenceItem — neither has HasTprmUuid.
        'tprm.due-diligence.complete',
        'tprm.due-diligence.items.complete',
        'tprm.due-diligence.items.waive',
        // Alert — no HasTprmUuid. (Its parent Incident does; the route binds
        // the alert, not the incident.)
        'tprm.monitoring.alerts.acknowledge',
        'tprm.monitoring.alerts.mute',
        // MaturityScore — no HasTprmUuid. (Its parent MaturityAssessment
        // does; this route binds the individual score row.)
        'tprm.reports.maturity.score',
        // ScreeningMatch — no HasTprmUuid.
        'tprm.screening.decide',
        // Document DOES carry HasTprmUuid, and both call sites correctly
        // pass `document.uuid` as the first element — these two are
        // allowlisted for their SECOND element only, `extraction.id`:
        // DocumentExtraction has no HasTprmUuid and keys on `id`. The pattern
        // cannot express "safe in one position, n/a in the other" any more
        // precisely than per route name.
        'tprm.documents.extractions.confirm',
        'tprm.documents.extractions.reject',
    ];

    #[Test]
    public function no_tprm_screen_builds_an_action_url_from_a_bare_numeric_identifier(): void
    {
        $offenders = [];

        foreach ($this->jsxFiles() as $file) {
            $relative = $this->relativePath($file);

            foreach (file($file) as $index => $line) {
                if (! preg_match_all(self::CALL_PATTERN, $line, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    $routeName = $match[1];
                    $args = $match[2];

                    if (! preg_match(self::NUMERIC_ARG_PATTERN, $args)) {
                        continue;
                    }

                    if (in_array($routeName, self::NUMERIC_KEY_ROUTE_ALLOWLIST, true)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s:%d — %s', $relative, $index + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A TPRM screen builds an action route from a bare numeric identifier.\n\n"
            .implode("\n", $offenders)
            ."\n\nThis is the shape of the Intake Queue defect found 2026-09-13: the model this route "
            .'binds to route-keys on its `uuid` (HasTprmUuid), and the numeric `id` a prop otherwise '
            ."carries 404s against it.\n\n"
            .'Ship a server-built URL from the controller or presenter instead — `\'approve_url\' => '
            ."route('tprm.intake.approve', \$engagement)` — so one home owns the URL and a route-key "
            .'change can never break the screen again. A `uuid` prop is the fallback only where a URL '
            .'per row is impractical. If the bound model genuinely route-keys on a numeric id, add the '
            .'route name to NUMERIC_KEY_ROUTE_ALLOWLIST with a comment naming the model checked, and '
            .'raise the count in the meta-test below.'
        );
    }

    /**
     * Mirrors `RouteAuthorizationTest` and `NoFabricatedNumbersTest`: the
     * failure mode for a guard like this is not that someone disables it — it
     * is that someone silences a true positive by appending to an allowlist.
     * Growing it is therefore a deliberate act that breaks the build and has
     * to be justified in the same commit.
     */
    #[Test]
    public function the_numeric_key_route_allowlist_has_not_grown_without_review(): void
    {
        $this->assertCount(
            31,
            self::NUMERIC_KEY_ROUTE_ALLOWLIST,
            'A route name was added to the numeric-key allowlist. Each of the current thirty-one was '
            .'checked on 2026-09-13 against the model(s) its bound route parameter(s) resolve to: either '
            .'the model has no HasTprmUuid trait, or — ClauseLibraryEntry, QuestionnaireTemplate — it '
            .'overrides resolveRouteBinding() for tenancy scoping without touching getRouteKeyName(), so '
            .'it still keys on `id`. The two `documents.extractions.*` entries are the one case checked '
            .'per ARGUMENT rather than per route: `document.uuid` is correct, and only the accompanying '
            .'`extraction.id` is what the allowlist actually covers. A new entry needs the same check '
            .'against app/Models/Tprm/Concerns/HasTprmUuid.php and the model file(s), not just a passing '
            .'build.'
        );
    }

    /** @return list<string> */
    private function jsxFiles(): array
    {
        $absolute = base_path(self::SEARCH_PATH);

        if (! is_dir($absolute)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['jsx', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relativePath(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }
}
