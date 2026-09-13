<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the defect found live in the dev tenant on 2026-09-13 — first in TPRM
 * (the Intake Queue, `9e9f1de`), then again the same day in BCMS (the BIA
 * workspace's Distribute/Submit/Approve buttons, the programme's
 * Approve/Activate, and a finding's "add corrective action").
 *
 * `IntakeController` shipped the Intake Queue's numeric `id` for `Engagement`,
 * which route-binds on its `uuid` (`App\Models\Tprm\Concerns\HasTprmUuid`),
 * and `Index.jsx` built `tryRoute('tprm.intake.approve', engagementId)` from
 * it — `POST /risk/tprm/intake/8/approve` 404s, verified over HTTP against a
 * logged-in session, because the model resolves nothing at key `8`. The same
 * shape turned up at eighteen more TPRM sites across eight screens, and then
 * at a dozen more once BCMS was swept: `Bia/Workspace.jsx`, `Bia/Campaigns.jsx`,
 * `Programme/Index.jsx` and `Findings/Index.jsx` all built an action URL from
 * a numeric `id` against a model that route-binds on `uuid`
 * (`App\Models\Bcms\Concerns\HasBcmsUuid`). Nothing tested any of these routes
 * over HTTP; every existing test built its own URL from `route()`, which
 * resolves the model's own route key regardless of what the screen does.
 *
 * THE RULE. A React page under `resources/js/Pages/Tprm` or
 * `resources/js/Pages/Bcms` must not build a `tprm.*`/`bcms.*` action URL from
 * a bare numeric-looking identifier — `<expr>.id`, a bare `id`, or a
 * variable/prop ending in `Id` — because a route named here may bind to a
 * model that carries `HasTprmUuid`/`HasBcmsUuid` and route-keys on `uuid`
 * instead, and the two look identical in a prop until somebody posts to the
 * URL. The fix, at every site fixed alongside this test, was a server-built
 * URL in the controller or presenter (`'approve_url' => route('tprm.intake.
 * approve', $engagement)`): one home for the URL means a route-key change can
 * never break the screen again.
 *
 * THE SECOND SHAPE — INDIRECTION. `no_screen_assigns_a_numeric_id_to_an_id_
 * named_prop()` below catches a numeric id assigned to an `Id`-suffixed prop
 * — `assessmentId={assessment.id}` — rather than tracing where that prop is
 * later spent. `NUMERIC_ARG_PATTERN` on its own DOES match this shape
 * (`\w*Id\b` matches `assessmentId` wherever it appears, including as a bare
 * argument to `tryRoute()`), so it is not true that a per-line direct-call
 * check cannot see it — the actual reason the first BCMS sweep missed
 * `Bia/Workspace.jsx`'s `impacts_url`/`dependencies_url` sites is that
 * `SEARCH_PATHS` did not yet include `resources/js/Pages/Bcms` at all when
 * that sweep ran. This second check earns its place anyway: it flags the
 * indirection at the point of ASSIGNMENT, one line closer to the mistake, and
 * it would have caught the same two sites even if `tryRoute('bcms.bia.
 * impacts.store', assessmentId)` had been written on a line `CALL_PATTERN`
 * could not parse (a multi-line call, for instance) — see the note beside
 * `CALL_PATTERN` for the blind spot this test suite actually has.
 *
 * THE THIRD SHAPE — THE NON-LITERAL ROUTE NAME. `CALL_PATTERN` only reads a
 * route name that is a string literal sitting directly inside `route(`/
 * `tryRoute(`. Gate 2, 2026-09-13: `Programme/Index.jsx` spent
 * `bcms.programme.approve`/`.activate` through a local `post(name, arg) =>
 * router.post(tryRoute(name, arg), ...)` helper, and the pre-fix numeric
 * `post('bcms.programme.approve', programme.id)` call sites were invisible to
 * `CALL_PATTERN` for exactly this reason: the string literal `CALL_PATTERN`
 * needs to see is one call away, inside the helper, not at the site that
 * actually supplies the numeric id. Fixed by removing that helper entirely
 * (`maturity_assess_url` now ships from `ProgrammePresenter`, matching every
 * other action on the screen) rather than allowlisting it — but the same
 * shape is genuinely benign at a handful of other sites (a helper whose id
 * argument defaults to a `uuid`, a ternary between two uuid-keyed route
 * names, a template-literal route name with no id argument at all), so
 * `no_screen_names_a_route_with_a_non_literal_first_argument()` below flags
 * every one of them and `NON_LITERAL_ROUTE_CALL_ALLOWLIST` is where each
 * survivor is reasoned against the argument it actually spends.
 *
 * THE ALLOWLISTS are not "reviewed and safe" in general. Each is narrowly the
 * sites checked, one by one against the model each bound parameter resolves
 * to (or, for the non-literal-name allowlist, against the argument the call
 * actually spends), to confirm the shape is safe. A route moves off
 * `NUMERIC_KEY_ROUTE_ALLOWLIST` the moment its model gains
 * `HasTprmUuid`/`HasBcmsUuid`, and nothing here would catch that on its own;
 * that is why growing either list is gated by a meta-test, mirroring
 * `RouteAuthorizationTest` and `NoFabricatedNumbersTest`.
 */
class ModuleActionUrlRouteKeyTest extends TestCase
{
    /** @var list<string> */
    private const SEARCH_PATHS = ['resources/js/Pages/Tprm', 'resources/js/Pages/Bcms'];

    /**
     * A `route(`/`tryRoute(` call naming a `tprm.*` or `bcms.*` route, with
     * its second argument captured separately. Non-greedy up to the first
     * `)`, which is safe here because every site this test has ever found is
     * either a bare identifier or a two-element array literal — neither
     * contains a `)` of its own.
     *
     * Scoped per LINE, mirroring `NoFabricatedNumbersTest`: every site this
     * test exists to catch — and every site it caught during the sweeps that
     * added it — was a single-line call.
     *
     * THE BLIND SPOT THIS PATTERN ACTUALLY HAS is not the id argument — this
     * class's second check reads the id argument on its own line, and
     * `NUMERIC_ARG_PATTERN` below already matches an `Id`-suffixed bare
     * identifier wherever it appears. It is the ROUTE NAME: this pattern only
     * fires where `'tprm.foo'`/`'bcms.foo'` is a string literal sitting
     * directly inside the call. A route name reached through a local helper,
     * a variable, or a ternary is invisible to it — see
     * `no_screen_names_a_route_with_a_non_literal_first_argument()` below,
     * which exists because of exactly that gap (Gate 2, 2026-09-13).
     */
    private const CALL_PATTERN = '/\b(?:route|tryRoute)\(\s*\'((?:tprm|bcms)\.[a-zA-Z0-9_.\-]+)\'\s*,\s*(.*?)\)/';

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
     * A JSX prop named `somethingId` assigned a bare `.id` (or optional-chained
     * `?.id`) expression — `assessmentId={assessment.id}`,
     * `findingId={f.id}`, `selectedId={selected?.id}` — the indirection
     * shape: the route call that eventually spends this prop may be on a
     * different line, or in a different component entirely, so this checks
     * the ASSIGNMENT rather than trying to trace the variable forward. The
     * `\??` before the final `.id` is what lets this match `selected?.id` as
     * well as `assessment.id` — both spell the same identifier, and a screen
     * gains nothing in safety by writing the optional-chained form.
     */
    private const INDIRECTION_PATTERN = '/\b[A-Za-z_][A-Za-z0-9_]*Id=\{[a-zA-Z_][a-zA-Z0-9_.]*\??\.id\}/';

    /**
     * A `route(`/`tryRoute(` call whose FIRST argument is not a string
     * literal — a bare variable (a local `post(name, arg)` helper), a
     * template literal (`` `bcms.${section.key}.index` ``), or a ternary
     * between two route names. Captures everything from the opening `(` up
     * to the first comma or closing `)`, then the test checks whether the
     * captured text, trimmed, starts with a quote.
     */
    private const NON_LITERAL_CALL_PATTERN = '/\b(?:route|tryRoute)\(\s*([^,)\n]*)/';

    /**
     * Route names checked against the model their bound parameter resolves
     * to, and confirmed to key on a plain numeric `id`. Each comment names
     * the model and, where it is not obvious, why.
     *
     * @var list<string>
     */
    private const NUMERIC_KEY_ROUTE_ALLOWLIST = [
        // -- TPRM (checked 2026-09-13 against HasTprmUuid) --------------
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

        // -- BCMS (checked 2026-09-13 against HasBcmsUuid) ---------------
        // ReadinessTask — no HasBcmsUuid.
        'bcms.readiness-tasks.complete',
        'bcms.readiness-tasks.override',
        // AlertTemplate — no HasBcmsUuid.
        'bcms.alert-templates.activate',
        // Dependency — no HasBcmsUuid. The route's FIRST element,
        // `assessment.uuid`, is correct (BiaAssessment carries HasBcmsUuid);
        // this entry covers the second, `d.id`, only.
        'bcms.bia.dependencies.destroy',
        // PlanSection — no HasBcmsUuid. First element `plan.uuid` is correct
        // (Plan carries HasBcmsUuid); this covers `section.id` only.
        'bcms.plans.sections.update',
        // CallTreeTestNode — no HasBcmsUuid. First element `test.uuid` is
        // correct (CallTreeTest carries HasBcmsUuid); this covers the node
        // parameter only, spelled `test_node_id` at these four call sites.
        'bcms.call-tree-tests.nodes.ack',
        'bcms.call-tree-tests.nodes.failure',
        'bcms.call-tree-tests.nodes.fix-contact',
        'bcms.call-tree-tests.nodes.finding',
        // CallTreeNode — no HasBcmsUuid. First element `tree.uuid` is correct
        // (CallTree carries HasBcmsUuid); this covers `selected.id` only.
        'bcms.call-trees.nodes.deputy',
        'bcms.call-trees.nodes.reparent',
        // `{type}/{id}` here is NOT Laravel route-model binding at all:
        // `DependencyController::impactOf()` takes a plain `int $id` and
        // resolves it with `$type->modelClass()::query()->find($id)`, which
        // is always a primary-key lookup regardless of the target model's
        // `getRouteKeyName()` override. `Application` DOES carry
        // HasBcmsUuid, but that only changes what `{$model}->getRouteKey()`
        // returns for URL generation, not what `find()` looks up — so `s.id`
        // (the target's actual primary key, shipped as `id` in `options()`)
        // is correct here even though it would not be for a bound route.
        'bcms.dependencies.impact-of',
    ];

    /**
     * Mirrors the discipline of `NUMERIC_KEY_ROUTE_ALLOWLIST`. Every site
     * this pattern has ever found in an action call (`Bia/Workspace.jsx`'s
     * `assessmentId={assessment.id}` for the impact-cell editor and the
     * add-dependency form) was fixed rather than allowlisted. The one entry
     * below is not an action id at all:
     *
     * - `CallTrees/Designer.jsx:144` — `selectedId={selected?.id}` is passed
     *   to `<CascadeTree>` purely to highlight which node is currently
     *   selected in the tree UI. It is never read back into a `route()`/
     *   `tryRoute()` call anywhere — the node actions on this screen
     *   (`bcms.call-trees.nodes.deputy`/`.reparent`) spend `selected.id`
     *   directly, not this prop, and are separately allowlisted in
     *   `NUMERIC_KEY_ROUTE_ALLOWLIST` because `CallTreeNode` has no
     *   `HasBcmsUuid`.
     *
     * A future entry needs the same per-prop check against the model the id
     * is eventually spent on (or, as here, a check that it is never spent on
     * a route at all), not just a passing build — see the meta-test below.
     *
     * Keyed on file + the MATCHED EXPRESSION, never a line number: a line
     * number drifts the moment anything above it in the file changes, and an
     * allowlist keyed on one silently stops matching the offender it was
     * meant to cover — the entry would still "pass" by no longer being
     * compared against anything.
     *
     * @var list<string>
     */
    private const INDIRECTION_ALLOWLIST = [
        'resources/js/Pages/Bcms/CallTrees/Designer.jsx — selectedId={selected?.id}',
    ];

    /**
     * `route(`/`tryRoute(` calls whose route name is not a string literal,
     * checked against the argument each actually spends:
     *
     * - `Plans/Show.jsx:55` — `const post = (name, args = plan.uuid, data =
     *   {}) => router.post(tryRoute(name, args), ...)`. Every call site in
     *   that file (`post('bcms.plans.assemble')`, `.acknowledge`,
     *   `.bundle.generate`, `.ai-draft`, `.submit-review`) omits the second
     *   argument, so `args` always resolves to its default, `plan.uuid` —
     *   `Plan` carries `HasBcmsUuid`. Never a numeric id in practice.
     * - `CallTrees/Designer.jsx:260` and `CallTrees/Index.jsx:204` — a
     *   ternary between two literal route names,
     *   `t.completed_at ? 'bcms.call-tree-tests.show' :
     *   'bcms.call-tree-tests.live'`. Both branches bind `CallTreeTest`,
     *   which carries `HasBcmsUuid`, and the second argument in both cases is
     *   `t.uuid`/`test.uuid`. Safe in either branch.
     * - `Home.jsx:86` and `Section.jsx:39` — a template-literal route name,
     *   `` `bcms.${section.key}.index` ``/`` `bcms.${other.key}.index` ``,
     *   with NO second argument at all: a plain navigation link to a
     *   section's own index page. There is no id to spend.
     *
     * `Programme/Index.jsx`'s `post(name, arg) => tryRoute(name, arg)` is
     * deliberately NOT on this list — it was the Gate 2 defect this pattern
     * exists to catch, and was removed rather than allowlisted (see the class
     * docblock).
     *
     * Keyed on file + the CAPTURED EXPRESSION (`NON_LITERAL_CALL_PATTERN`'s
     * own capture group), for the same reason `INDIRECTION_ALLOWLIST` is not
     * keyed on a line number.
     *
     * @var list<string>
     */
    private const NON_LITERAL_CALL_ALLOWLIST = [
        'resources/js/Pages/Bcms/Plans/Show.jsx — name',
        "resources/js/Pages/Bcms/CallTrees/Designer.jsx — t.completed_at ? 'bcms.call-tree-tests.show' : 'bcms.call-tree-tests.live'",
        "resources/js/Pages/Bcms/CallTrees/Index.jsx — test.completed ? 'bcms.call-tree-tests.show' : 'bcms.call-tree-tests.live'",
        'resources/js/Pages/Bcms/Home.jsx — `bcms.${section.key}.index`',
        'resources/js/Pages/Bcms/Section.jsx — `bcms.${other.key}.index`',
    ];

    #[Test]
    public function no_screen_builds_an_action_url_from_a_bare_numeric_identifier(): void
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
            "A screen builds an action route from a bare numeric identifier.\n\n"
            .implode("\n", $offenders)
            ."\n\nThis is the shape of the Intake Queue defect found 2026-09-13 (TPRM, `9e9f1de`) and its BCMS "
            .'twin the same day: the model this route binds to route-keys on its `uuid` (HasTprmUuid / '
            ."HasBcmsUuid), and the numeric `id` a prop otherwise carries 404s against it.\n\n"
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
            43,
            self::NUMERIC_KEY_ROUTE_ALLOWLIST,
            'A route name was added to the numeric-key allowlist. The thirty-one TPRM entries were checked on '
            .'2026-09-13 against the model(s) its bound route parameter(s) resolve to (see the original commit, '
            .'9e9f1de); the twelve BCMS entries were checked the same day against app/Models/Bcms/Concerns/'
            .'HasBcmsUuid.php and the relevant model file(s). A new entry needs the same check, not just a '
            .'passing build.'
        );
    }

    /**
     * Catches the shape a screen assigning a numeric id to an `Id`-suffixed
     * prop takes — one line closer to the mistake than tracing the prop to
     * wherever it is eventually spent. See the class docblock for why this
     * check earns its place alongside `CALL_PATTERN` rather than duplicating
     * it.
     */
    #[Test]
    public function no_screen_assigns_a_numeric_id_to_an_id_named_prop(): void
    {
        $offenders = [];

        foreach ($this->jsxFiles() as $file) {
            $relative = $this->relativePath($file);

            foreach (file($file) as $index => $line) {
                if (! preg_match_all(self::INDIRECTION_PATTERN, $line, $matches)) {
                    continue;
                }

                foreach ($matches[0] as $matched) {
                    $entry = $relative.' — '.trim($matched);

                    if (in_array($entry, self::INDIRECTION_ALLOWLIST, true)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s:%d — %s', $relative, $index + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A screen assigns a numeric `.id` to a prop named `somethingId`.\n\n"
            .implode("\n", $offenders)
            ."\n\nThe prop is very likely about to be handed to route()/tryRoute() for a `tprm.*`/`bcms.*` "
            .'action on a model that route-keys on `uuid` — this is exactly how `Bia/Workspace.jsx` fed '
            .'`bcms.bia.impacts.store` and `bcms.bia.dependencies.store` a 404-bound id on 2026-09-13. Ship '
            .'the URL itself as the prop instead (`impactsUrl`, not `assessmentId`), built with route() from '
            .'the bound model in the controller or presenter. If the id is genuinely safe (the model has no '
            .'uuid route key, or the prop is never spent on a route), add `\'<file> — <matched expression>\'` '
            .'to INDIRECTION_ALLOWLIST — the same value this test compares against, not a line number — and '
            .'raise the count in the meta-test below.'
        );
    }

    /** Mirrors the discipline above, for INDIRECTION_ALLOWLIST specifically. */
    #[Test]
    public function the_indirection_allowlist_has_not_grown_without_review(): void
    {
        $this->assertCount(
            1,
            self::INDIRECTION_ALLOWLIST,
            'An entry was added to the indirection allowlist. The one existing entry '
            .'(CallTrees/Designer.jsx\'s selectedId, checked 2026-09-13) is UI selection state never spent on '
            .'a route, not an action id — every OTHER site this pattern has ever found was a genuine defect '
            .'and was fixed rather than allowlisted. A new entry needs a specific check that the id is never '
            .'spent on a route, or is spent against a model with no uuid route key, named in a comment, not '
            .'just a passing build.'
        );
    }

    /**
     * Catches the Gate 2 defect directly: a route name reached through
     * anything other than a string literal — a local helper, a bare
     * variable, a ternary, a template literal — is invisible to
     * `CALL_PATTERN`, which is exactly what let
     * `post('bcms.programme.approve', programme.id)` hide behind
     * `Programme/Index.jsx`'s own `post(name, arg)` wrapper. This does not
     * care whether the call spends an id at all: the point is that nobody
     * can tell, from this test alone, what model a non-literal route name
     * resolves against, so every one has to be read by hand and is either
     * fixed or allowlisted with the reasoning recorded.
     */
    #[Test]
    public function no_screen_names_a_route_with_a_non_literal_first_argument(): void
    {
        $offenders = [];

        foreach ($this->jsxFiles() as $file) {
            $relative = $this->relativePath($file);

            foreach (file($file) as $index => $line) {
                if (! preg_match_all(self::NON_LITERAL_CALL_PATTERN, $line, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $captured) {
                    $captured = trim($captured);

                    if ($captured === '' || $captured[0] === "'" || $captured[0] === '"') {
                        // A string literal (or nothing at all, e.g. a
                        // zero-argument `tryRoute()` this codebase does not
                        // have) — already covered by CALL_PATTERN above.
                        continue;
                    }

                    $entry = $relative.' — '.$captured;

                    if (in_array($entry, self::NON_LITERAL_CALL_ALLOWLIST, true)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s:%d — %s', $relative, $index + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A screen names a route()/tryRoute() call with something other than a string literal.\n\n"
            .implode("\n", $offenders)
            ."\n\nThis is invisible to the direct-call check above: it can only read a route name that sits "
            .'literally inside the call, and a helper, a bare variable or a ternary hides it — this is the '
            ."exact shape that let Programme/Index.jsx's numeric `post('bcms.programme.approve', programme."
            .'id)` calls go untested until Gate 2 caught them by hand on 2026-09-13. Read what model the '
            .'route name(s) this call can produce actually bind to, and what argument is actually spent. If '
            .'it is genuinely safe, add `\'<file> — <captured expression>\'` to NON_LITERAL_CALL_ALLOWLIST — '
            .'the same value this test compares against — with a comment naming what was checked, and raise '
            .'the count in the meta-test below. Prefer removing the indirection instead: a server-built URL '
            .'in the controller or presenter cannot hide a numeric id the way a route-name variable can.'
        );
    }

    /** Mirrors the discipline above, for NON_LITERAL_CALL_ALLOWLIST specifically. */
    #[Test]
    public function the_non_literal_call_allowlist_has_not_grown_without_review(): void
    {
        $this->assertCount(
            5,
            self::NON_LITERAL_CALL_ALLOWLIST,
            'An entry was added to the non-literal-route-name allowlist. Each of the current five was checked '
            .'on 2026-09-13 against the model(s) its route(s) bind to and the argument it actually spends — '
            .'see the doc-comment on NON_LITERAL_CALL_ALLOWLIST. A new entry needs the same check, not just a '
            .'passing build, and removing the indirection (a server-built URL) is almost always the better fix.'
        );
    }

    /** @return list<string> */
    private function jsxFiles(): array
    {
        $files = [];

        foreach (self::SEARCH_PATHS as $path) {
            $absolute = base_path($path);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['jsx', 'js'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function relativePath(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }
}
