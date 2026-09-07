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
