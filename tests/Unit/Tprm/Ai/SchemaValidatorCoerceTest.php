<?php

namespace Tests\Unit\Tprm\Ai;

use App\Services\Tprm\Extraction\SchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * phase-11a-ai-contract.md §6.1 / §8.8 — defect (a): `"Security"` != `"security"`.
 */
class SchemaValidatorCoerceTest extends TestCase
{
    private SchemaValidator $validator;

    /** @var array<string, array<string, mixed>> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SchemaValidator;
        $this->schema = [
            'tsc_categories' => ['type' => 'list', 'in' => ['security', 'availability', 'confidentiality', 'processing_integrity', 'privacy']],
            'report_type' => ['type' => 'string', 'in' => ['type_i', 'type_ii']],
            'subservice_method' => ['type' => 'string', 'in' => ['carve_out', 'inclusive', 'none']],
        ];
    }

    #[Test]
    public function security_capitalised_is_coerced_and_passes_validation(): void
    {
        $payload = ['tsc_categories' => ['Security']];

        $coerced = $this->validator->coerce($payload, $this->schema);

        $this->assertSame(['security'], $coerced['tsc_categories']);
        $this->assertSame([], $this->validator->validate($coerced, $this->schema));
    }

    #[Test]
    public function type_ii_with_a_space_is_coerced(): void
    {
        $coerced = $this->validator->coerce(['report_type' => 'Type II'], $this->schema);

        $this->assertSame('type_ii', $coerced['report_type']);
    }

    #[Test]
    public function carve_out_with_a_hyphen_is_coerced(): void
    {
        $coerced = $this->validator->coerce(['subservice_method' => 'Carve-Out'], $this->schema);

        $this->assertSame('carve_out', $coerced['subservice_method']);
    }

    #[Test]
    public function surrounding_whitespace_and_upper_case_are_folded(): void
    {
        $coerced = $this->validator->coerce(['tsc_categories' => [' PROCESSING INTEGRITY ']], $this->schema);

        $this->assertSame(['processing_integrity'], $coerced['tsc_categories']);
    }

    #[Test]
    public function an_unmatched_value_is_left_untouched_and_still_fails(): void
    {
        $coerced = $this->validator->coerce(['report_type' => 'Kwality'], $this->schema);

        $this->assertSame('Kwality', $coerced['report_type']);
        $this->assertNotSame([], $this->validator->validate($coerced, $this->schema));
    }

    #[Test]
    public function a_null_field_is_left_alone(): void
    {
        $coerced = $this->validator->coerce(['report_type' => null], $this->schema);

        $this->assertNull($coerced['report_type']);
    }

    #[Test]
    public function coerce_never_touches_a_field_not_in_the_schema(): void
    {
        $coerced = $this->validator->coerce(['unrelated_field' => 'Whatever Case'], $this->schema);

        $this->assertSame('Whatever Case', $coerced['unrelated_field']);
    }
}
