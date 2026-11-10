<?php

namespace App\Services\Sm;

use App\Models\Sm\Scenario;
use App\Support\Kri\KriWorkingCalendar;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The `SM-{YYYY}-{NNN}` reference (ADR 0027 addendum-2, spec 02 §5.1).
 *
 * `SM-` IS FREE — verified. The product's other prefixes are `NM-`, `TPI-`,
 * `LE-`, `SIM-` and `SCN-`, and a grep for `SM-` across app/, database/, config/
 * and resources/js returns nothing. Spec 02 §5.1's insistence that it must not be
 * `SCN-` is correct and then some: Quantification owns `SCN` THREE TIMES OVER —
 * the reference prefix, the object-graph type code and the API reference prefix.
 * Two registers issuing the same prefix is how a board pack cites the wrong
 * scenario.
 *
 * TWO PRECEDENT DEFECTS THIS DOES NOT COPY. Both existing generators
 * (`Quantification\ScenarioService::nextReference()` at
 * app/Services/Quantification/ScenarioService.php:71-86, and the near-miss
 * generator at app/Http/Controllers/Risk/LossEventController.php:514-522) do two
 * things that are wrong here, and each is argued in the method that avoids it:
 *
 *   1. `now()->year` — `config/app.php` is UTC, so between 23:00 and 24:00 UTC on
 *      31 December (00:00–01:00 on 1 January in Lagos) they stamp the OLD year
 *      onto a reference the user is watching being created on New Year's Day.
 *   2. `orderByDesc(reference)` + `(int) substr($ref, -3)` — correct only while the
 *      sequence is ≤999. `SM-2026-1000` sorts BEFORE `SM-2026-999` as a string,
 *      and `substr(-3)` of it is `000`, so the 1000th scenario is followed by a
 *      second `SM-2026-001` — which the unique key then rejects FOREVER, with no
 *      path forward.
 *
 * Neither precedent has a collision rule either: they read-then-insert with no lock
 * and no retry, so two concurrent creates both get the same number and one 500s.
 * The retry lives in `ScenarioService::create()`, which is the only caller.
 *
 * THE REFERENCE IS ASSIGNED ON SAVE ONLY. Showing `SM-2026-014` before it exists
 * is displaying a figure nothing produced (standard §5), and `max + 1` means two
 * designers creating at once would both be shown a number one of them never gets.
 * The create form therefore reads "Assigned when you save" and posts no reference
 * (spec 04 §4) — and the Form Request refuses one, so a stale tab cannot smuggle
 * a provisional number in.
 */
class ReferenceGenerator
{
    /** Verified free across app/, database/, config/ and resources/js. */
    public const PREFIX = 'SM';

    /**
     * The width of the zero-padded sequence. Three is what the format shows; the
     * READER below is width-agnostic, which is the whole point of defect 2.
     */
    public const SEQUENCE_WIDTH = 3;

    /**
     * The next reference for a tenant, in the tenant's current Lagos year.
     *
     * NOT `now()->year`: see the class docblock. `KriWorkingCalendar::today()` is
     * reused rather than a fourth spelling of 'Africa/Lagos' being added to the
     * codebase, and it is Carbon-backed so `Carbon::setTestNow()` freezes it —
     * which is how the year-boundary test pins 31 December 23:30 UTC.
     */
    public function next(?int $organizationId = null): string
    {
        $organizationId ??= TenantContext::organizationId();
        $year = KriWorkingCalendar::today()->year;

        return sprintf(
            '%s-%d-%0'.self::SEQUENCE_WIDTH.'d',
            self::PREFIX,
            $year,
            $this->nextNumber($organizationId, $year)
        );
    }

    /**
     * `max(sequence) + 1`, computed in PHP over the tenant's references for one
     * year, starting at 1.
     *
     * `substr($reference, strlen($prefix))` — offset 8 for `SM-2026-` — skips the
     * fixed prefix, so it is correct AT ANY WIDTH. It needs no
     * `CAST(SUBSTRING(…))` filesort and stays inside the MariaDB 10.4 ∩ MySQL
     * 8.0.46 intersection. The plucked set is one tenant's one year of
     * heavyweight, workshop-designed scenarios; it is bounded by construction, and
     * if it ever is not, that is the ADR that introduces a counter.
     *
     * `lockForUpdate` over the tenant's rows was REFUSED: it serialises every
     * create for a tenant to defend a race that a retry resolves in microseconds,
     * and there is no sequence row to lock. A per-tenant counter table was refused
     * too — a sixth table, and §A1.1's boundary is the point of the discipline.
     */
    public function nextNumber(int $organizationId, ?int $year = null): int
    {
        $year ??= KriWorkingCalendar::today()->year;
        $prefix = sprintf('%s-%d-', self::PREFIX, $year);

        // withTrashed() IS LOAD-BEARING. A soft-deleted scenario still occupies
        // `sm_scenarios_org_reference_unique`, so excluding it would hand out a
        // reference the unique key then rejects — forever, with no path forward.
        // The sequence reads every row that holds the index, not only the visible
        // ones.
        $references = Scenario::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('reference', 'like', $prefix.'%')
            ->pluck('reference');

        $highest = 0;

        foreach ($references as $reference) {
            $sequence = (int) substr((string) $reference, strlen($prefix));

            if ($sequence > $highest) {
                $highest = $sequence;
            }
        }

        return $highest + 1;
    }

    /** Whether a reference was generated by this service rather than imported. */
    public static function isOwnedFormat(string $reference): bool
    {
        return preg_match('/^'.self::PREFIX.'-\d{4}-\d+$/', $reference) === 1;
    }
}
