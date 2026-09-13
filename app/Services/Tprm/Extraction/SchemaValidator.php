<?php

namespace App\Services\Tprm\Extraction;

/**
 * Response validation — TRD §12.1's "schema validation with one retry then
 * manual fallback".
 *
 * Deliberately small. This is not a JSON Schema implementation and does not
 * want to be: what it has to catch is a model returning a string where a list
 * belongs, a date as "January 2025", or an enum value it invented. Those are
 * the failures that reach a database column and become a wrong answer on a
 * regulatory return.
 *
 * AN UNEXPECTED FIELD IS NOT AN ERROR. A model that returns something extra
 * has not made a mistake worth discarding a whole extraction for; the extra
 * key is simply dropped by `normalise()`. A model that returns the WRONG TYPE
 * for a field we asked for has, because something downstream will read it.
 */
class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $schema
     * @return list<string> the failures, empty when valid
     */
    public function validate(array $payload, array $schema): array
    {
        $errors = [];

        foreach ($schema as $field => $spec) {
            $required = (bool) ($spec['required'] ?? false);

            if (! array_key_exists($field, $payload)) {
                if ($required) {
                    $errors[] = "The field `{$field}` is missing.";
                }

                continue;
            }

            $value = $payload[$field];

            // Null is always acceptable: "the document does not state this" is
            // the answer every prompt explicitly asks for.
            if ($value === null) {
                continue;
            }

            $error = $this->checkType($field, $value, $spec);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Defect (a), phase-11a-ai-contract.md §6.1 / ADR 0015 §7: `"Security"` is
     * discarded twice over capitalisation because `in_array(…, true)` in
     * `checkType()` above is strict and the model does not reliably return
     * the exact lower-snake form the schema demands.
     *
     * CALLED BY `ExtractionDispatcher` BEFORE `validate()`, NEVER AFTER.
     * `normalise()` on each extractor runs after validation and reshapes into
     * the PERSISTED payload; coercing there and validating its output would
     * validate a different document from the one the model returned, and an
     * error message would name a field the model never sent.
     *
     * ONLY TOUCHES A FIELD WHOSE `in` LIST IS ENTIRELY LOWER-SNAKE. A field
     * whose allowed values are not in that shape (there are none today, but a
     * future one might be) is left alone rather than guessed at.
     *
     * A candidate is trimmed, lower-cased, and internal runs of spaces and
     * hyphens folded to a single underscore. If the RESULT matches exactly one
     * allowed value it is substituted; otherwise the ORIGINAL is left
     * untouched so `validate()` reports the real value the model returned, not
     * a mangled one.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function coerce(array $payload, array $schema): array
    {
        foreach ($schema as $field => $spec) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null) {
                continue;
            }

            $allowed = $spec['in'] ?? null;

            if (! is_array($allowed) || $allowed === [] || ! $this->isAllLowerSnake($allowed)) {
                continue;
            }

            $value = $payload[$field];

            $payload[$field] = is_array($value)
                ? array_map(fn ($entry) => $this->coerceScalar($entry, $allowed), $value)
                : $this->coerceScalar($value, $allowed);
        }

        return $payload;
    }

    /**
     * @param  list<mixed>  $allowed
     */
    private function coerceScalar(mixed $value, array $allowed): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $folded = preg_replace('/[\s\-]+/', '_', trim(mb_strtolower($value)));

        return in_array($folded, $allowed, true) ? $folded : $value;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function isAllLowerSnake(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value) || $value === '' || preg_match('/^[a-z0-9]+(_[a-z0-9]+)*$/', $value) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function checkType(string $field, mixed $value, array $spec): ?string
    {
        $type = (string) ($spec['type'] ?? 'string');

        $ok = match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value),
            'list' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'date' => is_string($value) && $this->looksLikeDate($value),
            default => true,
        };

        if (! $ok) {
            return "The field `{$field}` should be a {$type} but was ".gettype($value).'.';
        }

        if (isset($spec['in']) && is_array($spec['in'])) {
            $values = is_array($value) ? $value : [$value];

            foreach ($values as $entry) {
                if (! in_array($entry, $spec['in'], true)) {
                    return "The field `{$field}` contains `".(is_scalar($entry) ? (string) $entry : gettype($entry))
                        .'` which is not one of: '.implode(', ', array_map('strval', $spec['in'])).'.';
                }
            }
        }

        return null;
    }

    /**
     * ISO dates only.
     *
     * "January 2025" is rejected rather than parsed, because a date this
     * module treats as a fact — the end of a SOC 2's audited period, a
     * certificate's expiry — must not be inferred from an ambiguous string. A
     * report period that starts on the wrong day silently changes which
     * assessments the evidence is allowed to support.
     */
    private function looksLikeDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
