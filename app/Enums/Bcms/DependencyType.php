<?php

namespace App\Enums\Bcms;

use App\Models\Bcms\Application;
use App\Models\Bcms\DataSet;
use App\Models\Bcms\Equipment;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Models\Tprm\ThirdParty;
use App\Models\User;

/**
 * The seven things a business process can depend on (Blueprint §9.1).
 *
 * ADR 0002 is the reasoning. The short point: `bcms_dependencies.dependable_type`
 * stores a SHORT ALIAS, never `App\Models\Tprm\ThirdParty`, so a namespace
 * change does not orphan a customer's dependency rows.
 *
 * THE STORED VALUES ARE NOT THE BLUEPRINT'S NAMES, and that is deliberate.
 * Blueprint §9.1 writes `applications`, `vendors`, `sites`, `users`,
 * `equipment`, `data_sets`, `processes`; those are the case names here. What is
 * WRITTEN is `App\Support\MorphTypes`' aliases, because this application
 * enforces ONE morph map for everything — `Relation::enforceMorphMap()` takes a
 * single map and a second would silently replace the first — and that map's
 * convention is singular snake_case. `users` would have been a second alias for
 * a class that already has `user`, and `getMorphClass()` returns the first
 * match, so two aliases for one class is a coin toss written into customer
 * data. `bcms_process` is prefixed because `business_process` is already taken
 * and means the org's own process catalogue, which is a different thing
 * (ADR 0001).
 *
 * Four of the seven resolve to registers BCMS owns only because no upstream
 * module owns them yet. Those are seams, and this enum plus `MorphTypes` is
 * where they are repointed.
 */
enum DependencyType: string
{
    case Applications = 'bcms_application';
    case Vendors = 'tprm_third_party';
    case Sites = 'bcms_site';
    case Users = 'user';
    case Equipment = 'bcms_equipment';
    case DataSets = 'bcms_data_set';
    case Processes = 'bcms_process';

    /** @return class-string */
    public function modelClass(): string
    {
        return match ($this) {
            self::Applications => Application::class,
            self::Vendors => ThirdParty::class,
            self::Sites => Site::class,
            self::Users => User::class,
            self::Equipment => Equipment::class,
            self::DataSets => DataSet::class,
            self::Processes => Process::class,
        };
    }

    /**
     * The subset of the application-wide morph map that BCMS dependencies use.
     *
     * `Phase0FoundationsTest` asserts this is a subset of `MorphTypes::map()`
     * with the same classes, so the two cannot drift apart.
     *
     * @return array<string, class-string>
     */
    public static function morphMap(): array
    {
        $map = [];

        foreach (self::cases() as $case) {
            $map[$case->value] = $case->modelClass();
        }

        return $map;
    }

    public function label(): string
    {
        return match ($this) {
            self::Applications => 'Application',
            self::Vendors => 'Third party',
            self::Sites => 'Site',
            self::Users => 'Person',
            self::Equipment => 'Equipment',
            self::DataSets => 'Data set',
            self::Processes => 'Process',
        };
    }

    /**
     * Whether BCMS owns the register behind this type, or another module does.
     *
     * A screen reading a type BCMS does not own must handle the target being
     * gone — a vendor soft-deleted in TPRM, a user offboarded — rather than
     * assuming a live row.
     */
    public function isOwnedByBcms(): bool
    {
        return ! in_array($this, [self::Vendors, self::Users], true);
    }
}
