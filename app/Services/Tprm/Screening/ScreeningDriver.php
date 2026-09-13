<?php

namespace App\Services\Tprm\Screening;

/**
 * One sanctions, PEP or adverse-media provider.
 *
 * A driver SEARCHES and REPORTS. It never decides: `ScreeningMatch::decision`
 * is a person's determination, and a driver able to set it could suspend every
 * engagement with a vendor on a common surname.
 *
 * `available()` IS PART OF THE CONTRACT because most of these need credentials
 * a tenant may not have bought. A driver that threw when unconfigured would
 * take down the screening screen; one that answers "not available, and here is
 * why" lets the console show a row that says so.
 */
interface ScreeningDriver
{
    /** The stable key stored on `tp_screening_checks.provider`. */
    public function key(): string;

    public function name(): string;

    /**
     * The lists this driver searches, for the check's `list_types`.
     *
     * @return list<string>
     */
    public function listTypes(): array;

    /**
     * Whether this driver can run right now, and why not.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function availability(): array;

    /**
     * Search for a name.
     *
     * @param  array<string, mixed>  $context  country, date of birth, entity type — whatever the subject carries
     */
    public function search(string $name, array $context = []): ScreeningResult;
}
