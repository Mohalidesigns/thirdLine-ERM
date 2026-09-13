<?php

namespace App\Services\Workflow;

use Illuminate\Support\Facades\Log;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Decides whether an edge may be taken.
 *
 * Same principle as FormulaEvaluator (WP-04): NO eval(). Expressions parse to
 * an AST that is interpreted against an explicit variable bag, so a condition
 * stored by a configurer cannot reach a class, a constant or a superglobal —
 * there is no syntax in the language that names one. A workflow condition is
 * user-authored content on a multi-tenant platform; treating it as code would
 * be remote execution with extra steps.
 *
 * VARIABLES AVAILABLE TO A CONDITION
 *   outcome    the decision just taken: 'approve', 'reject', 'return', …
 *   context    the instance context bag (arrays reachable by dot path via get())
 *   subject    a flat array of the subject's attributes
 *   task       {node_code, assignee_id, overdue}
 *   escalated  whether the instance has ever breached an SLA
 *
 * Written as, e.g.
 *   outcome == 'approve'
 *   outcome == 'approve' and get(subject, 'net_loss_kobo') > 5000000000
 *   get(context, 'requires_board') == true
 */
class ConditionEvaluator
{
    private ExpressionLanguage $language;

    public function __construct()
    {
        $this->language = new ExpressionLanguage;
        $this->registerFunctions();
    }

    /**
     * Evaluate a condition to a boolean.
     *
     * An empty condition is TRUE: an edge with no `when` is unconditional, and
     * writing that as "no condition" rather than "the condition true" is what
     * makes a default branch legible in the designer.
     *
     * A condition that throws is FALSE, logged with the expression. Failing
     * closed is the only safe direction: a malformed condition that evaluated
     * true would advance an approval nobody granted.
     *
     * @param  array<string, mixed>  $variables
     */
    public function evaluate(?string $expression, array $variables): bool
    {
        $expression = trim((string) $expression);

        if ($expression === '') {
            return true;
        }

        try {
            return (bool) $this->language->evaluate($expression, $this->defaults($variables));
        } catch (SyntaxError|\Throwable $e) {
            Log::warning('Workflow condition could not be evaluated; treating it as false.', [
                'expression' => $expression,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Evaluate an expression to its raw value rather than to a boolean.
     *
     * Used by assignee routing, where the answer is a user id or a role name
     * and casting it to a bool would turn "chief-risk-officer" into true.
     *
     * @param  array<string, mixed>  $variables
     */
    public function value(?string $expression, array $variables): mixed
    {
        $expression = trim((string) $expression);

        if ($expression === '') {
            return null;
        }

        try {
            return $this->language->evaluate($expression, $this->defaults($variables));
        } catch (SyntaxError|\Throwable $e) {
            Log::warning('Workflow expression could not be evaluated.', [
                'expression' => $expression,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse-check an expression without running it, for the designer's
     * pre-publish validation.
     *
     * @return string|null the error message, or null when the expression parses
     */
    public function syntaxError(?string $expression): ?string
    {
        $expression = trim((string) $expression);

        if ($expression === '') {
            return null;
        }

        try {
            $this->language->parse($expression, ['outcome', 'context', 'subject', 'task', 'escalated']);

            return null;
        } catch (SyntaxError $e) {
            return $e->getMessage();
        }
    }

    /** @param array<string, mixed> $variables */
    private function defaults(array $variables): array
    {
        return array_merge([
            'outcome' => null,
            'context' => [],
            'subject' => [],
            'task' => [],
            'escalated' => false,
        ], $variables);
    }

    private function registerFunctions(): void
    {
        // get(bag, 'a.b.c', default) — dot-path access, because the alternative
        // is teaching configurers that context['a']['b'] explodes when 'a' is
        // absent, and a condition that explodes is a condition that fails
        // closed and silently blocks an approval.
        $this->language->register(
            'get',
            fn ($bag, $path, $default = 'null') => sprintf('data_get(%s, %s, %s)', $bag, $path, $default),
            fn ($_, $bag, $path, $default = null) => data_get($bag, $path, $default)
        );

        $this->language->register(
            'empty_value',
            fn ($value) => sprintf('(blank(%s))', $value),
            fn ($_, $value) => blank($value)
        );

        $this->language->register(
            'in_list',
            fn ($needle, $list) => sprintf('in_array(%s, (array) %s, true)', $needle, $list),
            fn ($_, $needle, $list) => in_array($needle, (array) $list, false)
        );
    }
}
