<?php

namespace App\Services;

use App\Models\Measure;
use App\Models\MeasureValue;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Evaluates a threshold expression against the measure engine.
 *
 * WP-04 TASK 5. The point is limits that move when their denominator moves:
 *
 *     "0.5% of qualifying capital"   0.005 * @measure('capital.total_qualifying')
 *
 *     "50m naira, in 2026 money"     50000000 * @cpi_index('2026-01')
 *
 * Nigerian inflation is why this is not a nice-to-have. A fixed naira band set
 * in 2022 classifies an ordinary 2026 transaction as a severe breach, and the
 * platform-wide symptom is that everything is High and nobody trusts the RAG
 * column any more. Re-baselining fixes the numbers; effective-dated bands
 * (measure_thresholds.supersedes_id) keep last year's breaches meaning what
 * they meant last year.
 *
 * NO eval(). Expressions run through symfony/expression-language, which parses
 * to an AST and interprets it against an explicit list of registered functions
 * — an expression cannot reach a class, a constant or a superglobal, because
 * there is no syntax in the language that names one.
 *
 * THE @ PREFIX. The work package writes calls as @measure(...) for legibility
 * in a JSON band definition. ExpressionLanguage has no such sigil, so it is
 * stripped before parsing. Both spellings are accepted; @ is canonical in
 * stored bands.
 */
class FormulaEvaluator
{
    private ExpressionLanguage $language;

    /** @var array<string, mixed> resolution context for the current evaluation */
    private array $context = [];

    public function __construct(private CurrencyService $currency, private PeriodService $periods)
    {
        // No cache adapter: expressions are short, few, and evaluated at period
        // close rather than per request. A cache keyed on the expression string
        // would also outlive the data the expression reads.
        $this->language = new ExpressionLanguage;
        $this->registerFunctions();
    }

    /**
     * Evaluate an expression to a number.
     *
     * @param  array{organization_id?:int, object_id?:int|null, period_id?:int|null, date?:string|null}  $context
     * @param  array<string, mixed>  $variables  named values the expression may
     *                                           reference directly, e.g. the
     *                                           `inherent` and `effectiveness`
     *                                           a scoring profile's residual
     *                                           formula is written against.
     *                                           Distinct from $context, which
     *                                           tells the registered functions
     *                                           where to look things up rather
     *                                           than supplying values itself.
     *
     * @throws FormulaEvaluationException
     */
    public function evaluate(string $expression, array $context = [], array $variables = []): float
    {
        $this->context = $context;

        try {
            $result = $this->language->evaluate(self::normalise($expression), $variables);
        } catch (SyntaxError $error) {
            throw new FormulaEvaluationException(
                "Threshold expression is not valid: {$expression} — {$error->getMessage()}", 0, $error
            );
        } catch (FormulaEvaluationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new FormulaEvaluationException(
                "Threshold expression failed: {$expression} — {$error->getMessage()}", 0, $error
            );
        } finally {
            $this->context = [];
        }

        if (! is_numeric($result)) {
            throw new FormulaEvaluationException(
                "Threshold expression did not produce a number: {$expression} produced ".get_debug_type($result).'.'
            );
        }

        return (float) $result;
    }

    /**
     * Whether an expression can be evaluated right now, without throwing.
     *
     * Used by the re-baselining job to skip limits whose inputs have not been
     * entered for the period yet.
     */
    public function canEvaluate(string $expression, array $context = [], array $variables = []): bool
    {
        try {
            $this->evaluate($expression, $context, $variables);

            return true;
        } catch (FormulaEvaluationException) {
            return false;
        }
    }

    /** Strip the @ sigil the stored form uses. */
    public static function normalise(string $expression): string
    {
        return preg_replace('/@([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', '$1(', $expression) ?? $expression;
    }

    /** Whether a string looks like an expression rather than a plain number. */
    public static function looksLikeExpression(mixed $value): bool
    {
        return is_string($value) && ! is_numeric($value) && trim($value) !== '';
    }

    /* ------------------------------------------------------------------ */
    /*  The exposed vocabulary */
    /* ------------------------------------------------------------------ */

    private function registerFunctions(): void
    {
        // compile() is never called — the evaluator interprets. Each compiler
        // refuses rather than emitting PHP source, so no path in this class can
        // produce code to execute.
        $refuseToCompile = fn () => throw new FormulaEvaluationException(
            'Threshold expressions are interpreted, never compiled to PHP.'
        );

        $this->language->addFunction(new ExpressionFunction(
            'measure',
            $refuseToCompile,
            fn (array $_, string $code, ?int $objectId = null, ?int $periodId = null) => $this->measureValue($code, $objectId, $periodId)
        ));

        // Sugar over measure(), so a limit reads the way a policy document
        // writes it.
        $this->language->addFunction(new ExpressionFunction(
            'capital',
            $refuseToCompile,
            fn (array $_, string $code = 'total_qualifying', ?int $periodId = null) => $this->measureValue(
                str_contains($code, '.') ? $code : 'capital.'.$code, null, $periodId
            )
        ));

        $this->language->addFunction(new ExpressionFunction(
            'revenue',
            $refuseToCompile,
            fn (array $_, ?int $periodId = null) => $this->measureValue('financial.revenue', null, $periodId)
        ));

        $this->language->addFunction(new ExpressionFunction(
            'cpi_index',
            $refuseToCompile,
            fn (array $_, ?string $month = null, ?string $baseMonth = null) => $this->cpiIndex($month, $baseMonth)
        ));

        $this->language->addFunction(new ExpressionFunction(
            'fx',
            $refuseToCompile,
            fn (array $_, string $from, string $to, ?string $date = null, ?string $rateType = null) => $this->fxRate($from, $to, $date, $rateType)
        ));

        // Arithmetic helpers. ExpressionLanguage has operators but no maths
        // library, and a band bound rounded to the nearest million is a normal
        // thing for a policy to ask for.
        $this->language->addFunction(new ExpressionFunction(
            'round', $refuseToCompile,
            fn (array $_, $value, int $precision = 0) => round((float) $value, $precision)
        ));
        $this->language->addFunction(new ExpressionFunction(
            'min_of', $refuseToCompile, fn (array $_, ...$values) => min(array_map('floatval', $values))
        ));
        $this->language->addFunction(new ExpressionFunction(
            'max_of', $refuseToCompile, fn (array $_, ...$values) => max(array_map('floatval', $values))
        ));

        $this->language->addFunction(new ExpressionFunction(
            'ceil_to', $refuseToCompile,
            fn (array $_, $value, $step) => (float) $step <= 0
                ? (float) $value
                : ceil((float) $value / (float) $step) * (float) $step
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    /**
     * The actual value of a measure, for the object and period in context.
     *
     * Resolution order for the object: the explicit argument, then the object
     * the threshold belongs to, then whatever object the value happens to be
     * recorded against when the measure has exactly one — which is the shape
     * of an organisation-level figure such as qualifying capital.
     */
    private function measureValue(string $code, ?int $objectId, ?int $periodId): float
    {
        $organizationId = $this->organizationId();

        $measure = Measure::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        if ($measure === null) {
            throw new FormulaEvaluationException(
                "Threshold expression references measure '{$code}', which is not defined for this organisation."
            );
        }

        $periodId = $periodId ?? $this->periodId();

        $query = MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('period_id', $periodId)
            ->where('scenario', 'actual');

        $target = $objectId ?? ($this->context['object_id'] ?? null);

        if ($target !== null) {
            $value = (clone $query)->where('object_id', $target)->value('value');

            if ($value !== null) {
                return (float) $value;
            }
        }

        $rows = $query->limit(2)->get(['value']);

        if ($rows->count() === 1) {
            return (float) $rows->first()->value;
        }

        throw new FormulaEvaluationException(
            $rows->isEmpty()
                ? "No actual value recorded for measure '{$code}' in period {$periodId}."
                : "Measure '{$code}' has values for several objects in period {$periodId}; the expression must name one."
        );
    }

    /**
     * The consumer price index for a month, as a multiplier against a base.
     *
     * CPI is held in the measure engine as `macro.cpi_index` — one value per
     * month period — rather than in a table of its own, so that the same
     * import, approval and audit path covers it as covers every other number.
     *
     * With one argument the raw index is returned. With two, the ratio
     * index(month) / index(baseMonth) is returned, which is the form a
     * re-baselining expression wants: "50m naira in January-2022 money,
     * expressed in today's".
     */
    private function cpiIndex(?string $month, ?string $baseMonth): float
    {
        $month = $month ?? CarbonImmutable::now()->toDateString();
        $index = $this->cpiFor($month);

        if ($baseMonth === null) {
            return $index;
        }

        $base = $this->cpiFor($baseMonth);

        if ($base == 0.0) {
            throw new FormulaEvaluationException("CPI index for base month {$baseMonth} is zero.");
        }

        return $index / $base;
    }

    private function cpiFor(string $month): float
    {
        // "2026-01" is the natural way to write a month; Carbon needs a day.
        $date = preg_match('/^\d{4}-\d{2}$/', $month) ? $month.'-01' : $month;

        $period = $this->periods->resolve($date, 'month', $this->organizationId());

        return $this->measureValue('macro.cpi_index', null, $period->id);
    }

    private function fxRate(string $from, string $to, ?string $date, ?string $rateType): float
    {
        $date = $date ?? $this->contextDate();

        return $this->currency->resolveRate($from, $to, $date, $rateType, $this->organizationId())['rate'];
    }

    /* ------------------------------------------------------------------ */
    /*  Context */
    /* ------------------------------------------------------------------ */

    private function organizationId(): int
    {
        $organizationId = $this->context['organization_id'] ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            throw new FormulaEvaluationException(
                'A threshold expression cannot be evaluated without an organisation in context.'
            );
        }

        return (int) $organizationId;
    }

    private function periodId(): int
    {
        $periodId = $this->context['period_id'] ?? null;

        if ($periodId !== null) {
            return (int) $periodId;
        }

        $period = $this->periods->resolve($this->contextDate(), 'month', $this->organizationId());

        return $period->id;
    }

    private function contextDate(): string
    {
        if (! empty($this->context['date'])) {
            return (string) $this->context['date'];
        }

        if (! empty($this->context['period_id'])) {
            $end = Period::withoutGlobalScopes()->whereKey($this->context['period_id'])->value('end_date');

            if ($end !== null) {
                return CarbonImmutable::parse($end)->toDateString();
            }
        }

        return CarbonImmutable::now()->toDateString();
    }
}
