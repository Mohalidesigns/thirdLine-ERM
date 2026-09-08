<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The two regulators a third-party incident can start a clock with — TRD
 * §6.12, AC-07.
 *
 * TWO SEPARATE CLOCKS ON ONE INCIDENT, and they are not alternatives. A vendor
 * breach touching customer personal data at a bank starts BOTH: NDPC because
 * personal data was involved, CBN because it meets the cyber-incident
 * definition. Modelling this as one "regulator" field with one deadline would
 * mean the shorter clock silently replaced the longer one, or the reverse.
 */
enum Regulator: string
{
    use EnumHelpers;

    case Ndpc = 'ndpc';

    case Cbn = 'cbn';

    public function label(): string
    {
        return match ($this) {
            self::Ndpc => 'Nigeria Data Protection Commission',
            self::Cbn => 'Central Bank of Nigeria',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Ndpc => 'NDPC',
            self::Cbn => 'CBN',
        };
    }

    /** The statutory window, in hours from becoming aware. */
    public function hours(): int
    {
        return match ($this) {
            self::Ndpc => (int) config('tprm.clocks.ndpc_breach_hours', 72),
            self::Cbn => (int) config('tprm.clocks.cbn_cyber_incident_hours', 24),
        };
    }

    public function citation(): string
    {
        return match ($this) {
            self::Ndpc => 'NDPA 2023 §40(2)',
            self::Cbn => 'CBN Risk-Based Cybersecurity Framework, Appendix I',
        };
    }

    /** Which column on `tp_incidents` carries this clock's deadline. */
    public function deadlineColumn(): string
    {
        return $this->value.'_deadline_at';
    }

    public function reportableColumn(): string
    {
        return $this->value.'_reportable';
    }

    public function reportedColumn(): string
    {
        return $this->value.'_reported_at';
    }

    /** Who at the bank the draft is addressed from, by convention. */
    public function addressee(): string
    {
        return match ($this) {
            self::Ndpc => 'The National Commissioner, Nigeria Data Protection Commission',
            self::Cbn => 'The Director, Banking Supervision, Central Bank of Nigeria',
        };
    }
}
