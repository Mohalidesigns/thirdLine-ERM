<?php

namespace App\Exceptions\Tprm;

use App\Models\Tprm\BusinessFunction;
use RuntimeException;

/**
 * Raised when an intake names a business function a bank may not outsource.
 *
 * AC-01: the attempt is blocked, the citation is displayed, the event is
 * audited, AND NO ENGAGEMENT ROW IS CREATED. That last clause is why this is
 * an exception thrown inside the transaction rather than a validation rule: a
 * validator that runs before the write can be bypassed by any other code path
 * that creates an engagement, and the prohibition has to hold on all of them.
 *
 * Carries the offending functions so the error panel can name each one with
 * its own citation. The citations differ by licence type — a payment service
 * bank and a commercial bank are not told the same thing — so they travel from
 * the row rather than from a constant here.
 */
class ProhibitedOutsourcingException extends RuntimeException
{
    /**
     * @param  \Illuminate\Support\Collection<int, BusinessFunction>  $functions
     */
    public function __construct(public readonly \Illuminate\Support\Collection $functions)
    {
        $names = $functions->pluck('name')->implode(', ');

        parent::__construct(
            "This service cannot be outsourced: {$names}. ".
            'The intake has not been created.'
        );
    }

    /**
     * The per-function detail the error panel renders — name and citation
     * together, because a block with no reason is a block a user works around.
     *
     * @return list<array{function: string, code: string, citation: string}>
     */
    public function details(): array
    {
        return $this->functions->map(fn (BusinessFunction $function) => [
            'function' => $function->name,
            'code' => $function->function_code,
            'citation' => $function->prohibition_citation
                ?? 'This function is recorded as prohibited for outsourcing by your organisation.',
        ])->values()->all();
    }
}
