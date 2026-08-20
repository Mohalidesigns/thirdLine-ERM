<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the one rule that matters most in a product sold to banks: a number
 * shown to a customer must be a number something computed.
 *
 * WHY THIS TEST WAS REWRITTEN. The original version detected exactly one
 * spelling of fabrication — a call to an RNG:
 *
 *     '/\b(mt_rand|rand|random_int|shuffle|str_shuffle|array_rand|uniqid)\s*\(/'
 *
 * An audit in August 2026 then found eleven fabricated user-facing figures in
 * the product, and EVERY ONE OF THEM PASSED THIS TEST, because not one of them
 * used an RNG. They were hardcoded constants. Among them:
 *
 *   - resources/views/risk/reports/board.blade.php rendered
 *     `:value="($capitalAdequacyRatio ?? 15.2) . '%'"` with `subtitle="Min: 10%"`
 *     in a GREEN tile, on the same screen as the controller's own honest
 *     narrative "No ICAAP assessment is on record for the current period".
 *   - `?? '3.2/5'` for a risk profile score, `?? 72` for appetite utilisation,
 *     `?? 78` for control effectiveness, and eight more across a regulatory
 *     panel, one of which was the constant 70 for every tenant on the platform
 *     against a module that does not exist.
 *   - QuantificationController sliced one ICAAP column by 0.3 / 0.25 / 0.25 /
 *     0.2 and presented the slices as four distinct risk types, and shipped
 *     five stress scenarios with hardcoded CAR drops driving a Pass/Fail
 *     verdict independent of the bank's balance sheet.
 *   - `$risk->residual_rating ?? 'critical'` — asserting a severity the system
 *     had never assessed.
 *
 * The class docblock claimed to guard against "a number shown to a bank that
 * nothing computed". It guarded against one narrow spelling of that. The rules
 * below add the spellings that actually shipped.
 *
 * DESIGN CONSTRAINT: LOW FALSE POSITIVES. A guard that cries wolf gets its
 * allowlist widened until it is decorative. Every rule here was measured
 * against the whole tree before being enabled, and each one is deliberately
 * narrow enough that a hit is almost certainly a real defect. Where a broader
 * rule would have been more thorough but noisy, the narrower rule was chosen
 * and the limitation is stated in the rule's own comment rather than hidden.
 * The allowlists are capped by a meta-test, mirroring RouteAuthorizationTest.
 */
class NoFabricatedNumbersTest extends TestCase
{
    /**
     * Directories whose output reaches a user.
     */
    private const SEARCH_PATHS = [
        'app/Http/Controllers',
        'app/Services',
        'app/Jobs',
        'app/Livewire',
        'app/Grids',
        'resources/views',
    ];

    /**
     * Files permitted to call an RNG, and why.
     */
    private const RNG_ALLOWLIST = [
        // Poisson and Box-Muller variates. Seeded through Random\Randomizer so
        // a VaR figure stays reproducible; the seed is stored on the run.
        'app/Services/MonteCarloService.php',
    ];

    private const RNG_PATTERN = '/\b(mt_rand|rand|random_int|shuffle|str_shuffle|array_rand|uniqid)\s*\(/';

    /**
     * RULE 1 — a non-zero numeric literal standing in for a missing metric.
     *
     * Matches `?? <number>` inside a Blade echo ({{ }}, {!! !!}) or a component
     * attribute binding (:value=, :score=, …) — the two places a figure reaches
     * a screen.
     *
     * `?? 0` is PERMITTED. A count of zero is a true statement about an empty
     * set; "0 open issues" is correct, not fabricated. A non-zero literal is
     * different in kind: nothing produces 15.2 or 72 or 78 except a developer
     * typing it, and it renders as though a reading was taken.
     *
     * Form default values are permitted via the `old(` exclusion below: a
     * settings form pre-filling an input with a suggested starting value is
     * offering an INPUT, not asserting a RESULT. `old('cbn_min_car', $settings
     * ->cbn_min_car ?? 10.0)` is a sensible default in a field the user is
     * about to edit; the same literal on a KPI tile is a lie.
     */
    private const NUMERIC_FALLBACK_PATTERN =
        '/(\{\{|\{!!|:[A-Za-z_][A-Za-z0-9_-]*=")[^}"]*\?\?\s*-?\d+(\.\d+)?/';

    /**
     * Blade lines permitted to carry a non-zero numeric fallback, and why.
     *
     * Every entry here is a LAYOUT COORDINATE, not a metric — a default canvas
     * position or grid cell size for an element the user has not placed yet.
     * Nothing on these lines is presented to anyone as a measurement.
     */
    private const NUMERIC_FALLBACK_ALLOWLIST = [
        // Workflow designer: default x/y for a node dropped without coordinates.
        'resources/views/livewire/admin/workflow-designer.blade.php:256',
        'resources/views/livewire/admin/workflow-designer.blade.php:258',
        // Dashboard builder: default GridStack width/height and per-widget minima.
        'resources/views/livewire/widgets/dashboard-builder.blade.php:123',
        'resources/views/livewire/widgets/dashboard-builder.blade.php:124',
    ];

    /**
     * RULE 2 — a risk rating invented for a risk that was never rated.
     *
     * Scoped deliberately to the four RATING fields. These are the fields that
     * carry a formal risk classification, so a fallback on one of them asserts
     * a position the organisation never took: an unrated risk rendering as a
     * confident "Medium" in the register, or — as shipped — a completely
     * unassessed risk appearing in "Top Risk Increasers" as a Low -> Medium
     * deterioration.
     *
     * NOT scoped to `priority`, `severity` or `impact_level`. A wider rule
     * matched 35 sites, most of them a badge picking a default colour, and a
     * rule that flags 35 things nobody intends to change is a rule that gets
     * disabled. Those remain worth fixing; they are not worth failing CI over,
     * and pretending otherwise would make this guard weaker, not stronger.
     *
     * The fix is `?? 'unrated'`: x-risk-badge renders anything it does not
     * recognise in neutral grey, so an unrated risk reads as unrated.
     */
    private const RATING_FALLBACK_PATTERN =
        "/(residual_rating|inherent_rating|previous_rating|current_rating)\s*\?\?\s*'(critical|high|medium|low)'/i";

    /**
     * RULE 3 — the shape of the ICAAP defect: a magic decimal applied to a
     * capital, RWA or ratio quantity.
     *
     * This is the hardest rule to write without noise, because legitimate
     * arithmetic on the same variables is everywhere: `/ 100` converts kobo to
     * naira, `* 100` turns a ratio into a percentage, and both are correct.
     *
     * So the rule is narrow on purpose. It matches only a DECIMAL literal
     * (a digit, a point, a digit — so `/ 100` and `* 100` cannot match)
     * multiplying, dividing or being subtracted from a variable whose name
     * carries a financial meaning. That is precisely the shape of what shipped:
     *
     *     round(($icaap->pillar2a_other_kobo ?? 0) / 100 * 0.3, 2)
     *     round($totalCapital * 0.08, 2)
     *     max($capitalAdequacyRatio - 3.5, 0)
     *
     * It will not catch a fabricated integer, and it will not catch a magic
     * constant assigned to a variable first and used later. Stating that
     * plainly is better than implying coverage this rule does not have.
     *
     * The financial keyword is matched after either `$` or `->`, so a property
     * carries it as well as a variable, and the surrounding character class is
     * `\w*` rather than `[A-Za-z_]*` so that a DIGIT inside an identifier does
     * not break the match. Both details were found by probing the rule against
     * the defect it exists to catch:
     *
     *     round(($icaap->pillar2a_other_kobo ?? 0) / 100 * 0.3, 2)
     *
     * An `[A-Za-z_]*` prefix stops dead at the `2` in `pillar2a`, so the rule
     * silently passed the single most important line it was written for. A
     * guard is only worth what it has been shown to catch — this one was
     * re-probed after the fix.
     *
     * The bounded `[^;]{0,40}?` window between the keyword and the literal is
     * what lets `?? 0) / 100` sit in between; `/ 100` cannot itself match
     * because the literal must contain a decimal point.
     */
    private const MAGIC_FINANCIAL_CONSTANT_PATTERN =
        '/(?:\$|->)\w*(capital|car|rwa|kobo|naira|shortfall|exposure|adequacy)\w*\b[^;]{0,40}?[-*\/]\s*\d+\.\d+/i';

    /**
     * Lines permitted to apply a decimal constant to a financial quantity.
     *
     * The bar for this list is that the constant is FIXED BY REGULATION and
     * cited, not chosen by a developer. "It looked about right" is the thing
     * this rule exists to stop.
     */
    private const FINANCIAL_CONSTANT_ALLOWLIST = [
        // RWA = 12.5 x capital charge. 12.5 is 1/0.08, the reciprocal of the 8%
        // minimum capital ratio, and is applied identically in CBN's Guidelines
        // on Regulatory Capital (September 2021) to derive operational RWA from
        // the Pillar 1 charge. Not a judgement call: the multiplier is defined
        // by the ratio itself, and the variable is named for what it produces.
        'app/Services/RegulatoryReportService.php:$rwaEquivalent = $var999 * 12.5;',
    ];

    #[Test]
    public function no_random_number_generator_appears_in_user_facing_code(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::SEARCH_PATHS) as $file) {
            $relative = $this->relativePath($file);

            if (in_array($relative, self::RNG_ALLOWLIST, true)) {
                continue;
            }

            foreach ($this->codeLines($file) as $number => $line) {
                if (preg_match(self::RNG_PATTERN, $line)) {
                    $offenders[] = sprintf('%s:%d — %s', $relative, $number, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Random number generation found in code that produces user-facing figures.\n\n"
            .implode("\n", $offenders)
            ."\n\nIf a number is not computed from real data, it does not ship. A simulation "
            .'engine that genuinely needs an RNG must use a seedable generator, persist its '
            .'seed, and be added to the allowlist in this test and in scripts/check-no-rng.sh.'
        );
    }

    #[Test]
    public function no_metric_falls_back_to_a_hardcoded_number(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $relative = $this->relativePath($file);

            foreach ($this->codeLines($file) as $number => $line) {
                if (! preg_match(self::NUMERIC_FALLBACK_PATTERN, $line)) {
                    continue;
                }

                // `?? 0` is a true statement about an empty set, not a fabrication.
                if (! preg_match('/\?\?\s*-?(?!0(?![\d.]))\d+(\.\d+)?/', $line)) {
                    continue;
                }

                // A form pre-filling an editable input offers a default; it does
                // not assert a reading.
                if (str_contains($line, 'old(')) {
                    continue;
                }

                if (in_array("{$relative}:{$number}", self::NUMERIC_FALLBACK_ALLOWLIST, true)) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d — %s', $relative, $number, trim($line));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A metric falls back to a hardcoded number when its real value is missing.\n\n"
            .implode("\n", $offenders)
            ."\n\nThis is how the Board report came to print a green \"Capital Adequacy 15.2%\" "
            ."tile on the same screen as the sentence \"No ICAAP assessment is on record\".\n\n"
            .'Render the absence instead: <x-kpi-card :unavailable="$value === null" '
            .'unavailableLabel="No ICAAP on record" />. If the figure is genuinely a count, '
            .'`?? 0` is permitted and this rule will not fire.'
        );
    }

    #[Test]
    public function no_risk_rating_is_invented_for_an_unrated_risk(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::SEARCH_PATHS) as $file) {
            $relative = $this->relativePath($file);

            foreach ($this->codeLines($file) as $number => $line) {
                if (preg_match(self::RATING_FALLBACK_PATTERN, $line)) {
                    $offenders[] = sprintf('%s:%d — %s', $relative, $number, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A risk rating is being invented where the organisation recorded none.\n\n"
            .implode("\n", $offenders)
            ."\n\nAn unrated risk that renders as \"Medium\" is the platform asserting a "
            ."position nobody took. Use `?? 'unrated'` — x-risk-badge renders an "
            .'unrecognised value in neutral grey, which is exactly the honest presentation.'
        );
    }

    #[Test]
    public function no_capital_figure_is_derived_from_a_magic_constant(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::SEARCH_PATHS) as $file) {
            $relative = $this->relativePath($file);

            foreach ($this->codeLines($file) as $number => $line) {
                if (! preg_match(self::MAGIC_FINANCIAL_CONSTANT_PATTERN, $line)) {
                    continue;
                }

                // Keyed on the statement rather than the line number, so that
                // an unrelated edit above it does not silently re-open the hole.
                if (in_array($relative.':'.trim($line), self::FINANCIAL_CONSTANT_ALLOWLIST, true)) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d — %s', $relative, $number, trim($line));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A capital or ratio figure is derived from a hardcoded multiplier.\n\n"
            .implode("\n", $offenders)
            ."\n\nThe ICAAP screen shipped `pillar2a_other_kobo / 100 * 0.3` presented as "
            ."concentration risk, and `max(\$capitalAdequacyRatio - 3.5, 0)` as a stress "
            ."result. Nobody computed those constants and nothing in the schema held them.\n\n"
            .'A capital figure must come from stored capital and RWA, from a bound simulation '
            .'run, or from tenant configuration — never from a literal in a controller.'
        );
    }

    #[Test]
    public function the_allowlisted_simulation_engine_uses_a_seedable_generator(): void
    {
        // Allowlisting a file is only defensible while that file remains
        // reproducible. If MonteCarloService ever drops back to a global
        // mt_rand() stream, the allowlist entry becomes a hole.
        $source = file_get_contents(base_path('app/Services/MonteCarloService.php'));

        $this->assertStringContainsString(
            'Random\Randomizer',
            $source,
            'The allowlisted simulation engine must use an explicitly seeded Randomizer.'
        );
        $this->assertStringNotContainsString(
            'mt_rand(',
            preg_replace('/\/\*.*?\*\//s', '', $source),
            'The allowlisted simulation engine must not call the global mt_rand() stream.'
        );
    }

    #[Test]
    public function no_hardcoded_model_performance_metrics_remain(): void
    {
        // The Predictive screen used to publish accuracy 87.3, precision 84.1,
        // recall 89.7, f1 86.8 and auc_roc 0.912 — all constants, none measured.
        // There is no model behind that screen now, so none of these keys
        // should exist anywhere in the application code.
        $banned = ['auc_roc', 'model_version', 'training_samples', 'f1_score'];
        $offenders = [];

        foreach ($this->phpFilesUnder(['app', 'resources/views']) as $file) {
            $contents = file_get_contents($file);
            $relative = $this->relativePath($file);

            foreach ($banned as $key) {
                if (str_contains($contents, $key)) {
                    $offenders[] = "{$relative} references '{$key}'";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Hardcoded model-performance metrics found.\n\n".implode("\n", $offenders)
        );
    }

    /**
     * Meta-test, mirroring RouteAuthorizationTest.
     *
     * The failure mode for a guard like this is not that someone disables it —
     * it is that someone silences a true positive by appending to an allowlist.
     * Growing either list is therefore a deliberate act that breaks the build
     * and has to be justified in the same commit.
     */
    #[Test]
    public function the_allowlists_have_not_grown(): void
    {
        $this->assertCount(
            1,
            self::RNG_ALLOWLIST,
            'A file was added to the RNG allowlist. A user-facing figure produced by a '
            .'random number generator is not a figure. If a new simulation engine genuinely '
            .'needs one, it must be seeded and reproducible — say so here and raise this count.'
        );

        $this->assertCount(
            4,
            self::NUMERIC_FALLBACK_ALLOWLIST,
            'A line was added to the numeric-fallback allowlist. Every current entry is a '
            .'layout coordinate, not a metric. If a genuine metric needs a non-zero default, '
            .'it almost certainly needs an explicit not-assessed state instead.'
        );

        $this->assertCount(
            1,
            self::FINANCIAL_CONSTANT_ALLOWLIST,
            'A statement was added to the financial-constant allowlist. The bar is that the '
            .'constant is fixed by regulation and cited in the entry itself — as 12.5 is, '
            .'being the reciprocal of the 8% minimum capital ratio. A constant chosen because '
            .'it produced a plausible-looking number is the defect this rule exists to catch.'
        );
    }

    /**
     * File lines, 1-indexed, with comment-only lines removed.
     *
     * Comments matter here: several of the fixes made in August 2026 deliberately
     * QUOTE the defect they removed, so that the next reader understands what was
     * wrong. QuantificationController's docblock, for instance, records that the
     * fabricated fallback was built from `$totalCapital * 0.08`. Scanning raw
     * file contents would flag those explanations as the very thing they explain.
     *
     * @return array<int, string>
     */
    private function codeLines(string $file): array
    {
        $lines = [];

        foreach (file($file) as $index => $line) {
            // Line comments and docblock bodies, PHP and Blade alike.
            if (preg_match('/^\s*(\/\/|\*|#|\/\*|\{\{--)/', $line)) {
                continue;
            }

            $lines[$index + 1] = $line;
        }

        return $lines;
    }

    private function relativePath(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        return array_values(array_filter(
            $this->phpFilesUnder(['resources/views']),
            fn (string $file) => str_ends_with($file, '.blade.php')
        ));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFilesUnder(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
