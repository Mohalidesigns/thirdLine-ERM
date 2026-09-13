<?php

namespace App\Services\Rcsa;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaSystem;
use App\Models\User;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Support\Str;

/**
 * Turns what somebody typed into a spreadsheet into what the system means.
 *
 * This is the difference between a bulk upload a bank will use and one they
 * will abandon after the first attempt. The raw file contains "  retail
 * banking ", "Moderate", "IT Risk", "31/03/2026" and a business unit spelled
 * "Retail Bankng"; none of that is wrong in any way a person would recognise,
 * and rejecting all of it row by row teaches the user that the feature does not
 * work.
 *
 * IT RESOLVES, IT DOES NOT DECIDE. Everything here produces a normalised value
 * plus, where a lookup was involved, a confidence note the validator turns into
 * a warning. Nothing is silently corrected without the preview screen being
 * able to say so — a fuzzy match that quietly attached sixty risks to the wrong
 * business unit would be far worse than sixty rejected rows.
 *
 * The lookup tables are loaded ONCE and held. A 1,000-row file resolving a
 * business unit per row would otherwise be 1,000 queries, and §12's acceptance
 * criterion is that such a file validates in under a minute.
 */
class RcsaImportNormaliser
{
    /**
     * How close a fuzzy match must be before it is offered at all.
     *
     * Similarity is percentage of characters shared. 85 tolerates a
     * transposition and a missing letter in a short name ("Retail Bankng" →
     * "Retail Banking" scores 96) while refusing to guess between "Treasury"
     * and "Trade Finance", which score 46.
     */
    private const FUZZY_THRESHOLD = 85.0;

    /** @var array<string, int>|null Lower-cased unit name AND code => id. */
    private ?array $units = null;

    /** @var array<string, list<array{id: int, business_unit_id: int|null, parent_id: int|null}>>|null */
    private ?array $processes = null;

    /** @var array<string, int>|null */
    private ?array $systems = null;

    /** @var array<string, int>|null Lower-cased email AND name => id. */
    private ?array $users = null;

    /**
     * Normalise one raw row into the shape the validator and publisher expect.
     *
     * @param  array<string, string|null>  $raw  Keyed by the field names in RcsaTemplateWriter::COLUMNS.
     * @return array{values: array<string, mixed>, notes: list<array{field: string, message: string}>}
     */
    public function normalise(array $raw): array
    {
        $notes = [];

        $text = fn (?string $value) => $this->collapse($value);

        /* --- Placement ------------------------------------------------- */

        $unitName = $text($raw['business_unit'] ?? null);
        [$unitId, $unitNote] = $this->resolveUnit($unitName);

        if ($unitNote !== null) {
            $notes[] = ['field' => 'business_unit', 'message' => $unitNote];
        }

        $processName = $text($raw['process'] ?? null);
        [$processId, $processNote] = $this->resolveProcess($processName, $unitId, parentId: null);

        if ($processNote !== null) {
            $notes[] = ['field' => 'process', 'message' => $processNote];
        }

        $subProcessName = $text($raw['sub_process'] ?? null);
        [$subProcessId, $subNote] = $this->resolveProcess($subProcessName, $unitId, parentId: $processId);

        if ($subNote !== null) {
            $notes[] = ['field' => 'sub_process', 'message' => $subNote];
        }

        /* --- Systems, a semicolon-separated list ----------------------- */

        $systemIds = [];
        $unknownSystems = [];

        foreach ($this->splitList($raw['system'] ?? null) as $name) {
            $id = $this->systems()[Str::lower($name)] ?? null;

            if ($id === null) {
                $unknownSystems[] = $name;

                continue;
            }

            $systemIds[] = $id;
        }

        if ($unknownSystems !== []) {
            $notes[] = [
                'field' => 'system',
                'message' => 'Not in the system register, so not linked: '.implode(', ', $unknownSystems).'.',
            ];
        }

        /* --- Vocabularies ---------------------------------------------- */

        $category = $this->canonical($text($raw['risk_category'] ?? null), Template::RISK_CATEGORIES);
        $controlType = $this->canonical($text($raw['control_type'] ?? null), RcsaRegisterControl::TYPES);
        $frequency = $this->canonical($text($raw['control_frequency'] ?? null), RcsaRegisterControl::FREQUENCIES);

        /* --- Control owner --------------------------------------------- */

        $ownerLabel = $text($raw['control_owner'] ?? null);
        $ownerId = $ownerLabel === null ? null : ($this->users()[Str::lower($ownerLabel)] ?? null);

        if ($ownerLabel !== null && $ownerId === null) {
            // A warning, not an error, and the plan is explicit about it: an
            // unresolved owner becomes unassigned. Rejecting the row would
            // lose a good risk statement over a misspelled colleague.
            $notes[] = [
                'field' => 'control_owner',
                'message' => "\"{$ownerLabel}\" is not an active user, so the control will be unassigned.",
            ];
        }

        $values = [
            'risk_no' => $text($raw['risk_no'] ?? null),
            'business_unit_id' => $unitId,
            'business_unit' => $unitName,
            'process_id' => $processId,
            'process' => $processName,
            'sub_process_id' => $subProcessId,
            'sub_process' => $subProcessName,
            'system_ids' => $systemIds,
            'potential_risk' => $text($raw['potential_risk'] ?? null),
            'risk_driver' => $text($raw['risk_driver'] ?? null),
            'risk_category' => $category,
            'secondary_categories' => $this->splitList($raw['secondary_categories'] ?? null),
            'existing_control' => $text($raw['existing_control'] ?? null),
            'control_type' => $controlType,
            'control_frequency' => $frequency,
            'control_owner_id' => $ownerId,
            'control_owner' => $ownerLabel,
        ];

        return ['values' => $values, 'notes' => $notes];
    }

    /**
     * The canonical spelling of a value, through the alias map.
     *
     * Case-insensitive exact match first, then the alias map (which is where
     * defect D1's `Moderate → Medium` lives), then nothing. Returning the
     * original on no match would push an unapproved category into the register
     * and defeat the validator; returning null lets the validator say so.
     *
     * @param  list<string>  $vocabulary
     */
    public function canonical(?string $value, array $vocabulary): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $needle = Str::lower($value);

        foreach ($vocabulary as $candidate) {
            if (Str::lower($candidate) === $needle) {
                return $candidate;
            }
        }

        $aliased = Template::IMPORT_ALIASES[$needle] ?? null;

        if ($aliased === null) {
            return null;
        }

        // The alias map is shared across vocabularies — `Medium` is an impact
        // rating, not a control type — so an alias only counts when it lands
        // inside the vocabulary being asked about.
        foreach ($vocabulary as $candidate) {
            if (Str::lower($candidate) === Str::lower($aliased)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A date in any of the three shapes a Nigerian bank's spreadsheets contain.
     *
     * `dd/mm/yyyy` is checked BEFORE `mm/dd/yyyy`, and no attempt is made at
     * the latter: 03/04/2026 is unambiguous to nobody, and silently choosing
     * one reading would put action-plan target dates a month out with no way to
     * tell. Excel serials are the third shape — a date cell read through a
     * plain reader arrives as 46023.
     *
     * Unused by the universe import, which has no date column; it is here for
     * the assessment import of §10.4 and is tested now so that it is right when
     * that arrives.
     */
    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 100000) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $text = trim((string) $value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'j/n/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $text);

            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Lookups */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: int|null, 1: string|null} id, and a note if it was fuzzy or missing.
     */
    private function resolveUnit(?string $name): array
    {
        if ($name === null) {
            return [null, null];
        }

        $exact = $this->units()[Str::lower($name)] ?? null;

        if ($exact !== null) {
            return [$exact, null];
        }

        [$id, $matched, $score] = $this->closest($name, array_keys($this->units()));

        if ($id === null) {
            return [null, null];
        }

        return [
            $this->units()[$matched],
            sprintf('"%s" was matched to "%s" (%d%% similar). Check this is the unit you meant.', $name, $matched, (int) $score),
        ];
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveProcess(?string $name, ?int $unitId, ?int $parentId): array
    {
        if ($name === null) {
            return [null, null];
        }

        $candidates = $this->processes()[Str::lower($name)] ?? [];

        if ($candidates === []) {
            return [null, null];
        }

        // Narrow by the placement we already know: a sub-process must sit under
        // the named parent, and a process under the named unit. Without this, a
        // bank running "Reconciliation" in six units attaches every risk to
        // whichever one happens to have the lowest id.
        $narrowed = array_values(array_filter(
            $candidates,
            function (array $process) use ($unitId, $parentId) {
                if ($parentId !== null) {
                    return $process['parent_id'] === $parentId;
                }

                return $process['parent_id'] === null
                    && ($unitId === null || $process['business_unit_id'] === $unitId);
            }
        ));

        if (count($narrowed) === 1) {
            return [$narrowed[0]['id'], null];
        }

        if ($narrowed === []) {
            return [null, sprintf('"%s" exists, but not under the process and business unit named on this row.', $name)];
        }

        return [
            $narrowed[0]['id'],
            sprintf('"%s" names %d processes in this business unit; the first was used.', $name, count($narrowed)),
        ];
    }

    /**
     * The closest candidate above the threshold, or nulls.
     *
     * @param  list<string>  $candidates
     * @return array{0: string|null, 1: string|null, 2: float}
     */
    private function closest(string $needle, array $candidates): array
    {
        $best = null;
        $bestScore = 0.0;
        $lower = Str::lower($needle);

        foreach ($candidates as $candidate) {
            similar_text($lower, $candidate, $score);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= self::FUZZY_THRESHOLD
            ? [$best, $best, $bestScore]
            : [null, null, 0.0];
    }

    /** @return array<string, int> */
    private function units(): array
    {
        if ($this->units !== null) {
            return $this->units;
        }

        $this->units = [];

        foreach (BusinessUnit::query()->where('is_active', true)->get(['id', 'name', 'code']) as $unit) {
            $this->units[Str::lower((string) $unit->name)] = (int) $unit->id;

            if (filled($unit->code)) {
                // The code is a legitimate way to name a unit in a spreadsheet,
                // and it is what the risk numbers are built from.
                $this->units[Str::lower((string) $unit->code)] = (int) $unit->id;
            }
        }

        return $this->units;
    }

    /** @return array<string, list<array{id: int, business_unit_id: int|null, parent_id: int|null}>> */
    private function processes(): array
    {
        if ($this->processes !== null) {
            return $this->processes;
        }

        $this->processes = [];

        foreach (BusinessProcess::query()->where('is_active', true)->get(['id', 'name', 'business_unit_id', 'parent_id']) as $process) {
            $this->processes[Str::lower((string) $process->name)][] = [
                'id' => (int) $process->id,
                'business_unit_id' => $process->business_unit_id !== null ? (int) $process->business_unit_id : null,
                'parent_id' => $process->parent_id !== null ? (int) $process->parent_id : null,
            ];
        }

        return $this->processes;
    }

    /** @return array<string, int> */
    private function systems(): array
    {
        if ($this->systems !== null) {
            return $this->systems;
        }

        $this->systems = [];

        foreach (RcsaSystem::query()->where('is_active', true)->get(['id', 'name', 'code']) as $system) {
            $this->systems[Str::lower((string) $system->name)] = (int) $system->id;

            if (filled($system->code)) {
                $this->systems[Str::lower((string) $system->code)] = (int) $system->id;
            }
        }

        return $this->systems;
    }

    /** @return array<string, int> */
    private function users(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        $this->users = [];

        foreach (User::query()->where('is_active', true)->get(['id', 'name', 'email']) as $user) {
            $this->users[Str::lower((string) $user->email)] = (int) $user->id;
            $this->users[Str::lower((string) $user->name)] = (int) $user->id;
        }

        return $this->users;
    }

    /* ------------------------------------------------------------------ */
    /*  Text */
    /* ------------------------------------------------------------------ */

    /** Trim, collapse runs of whitespace, and treat an empty string as absent. */
    private function collapse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Non-breaking spaces arrive from anything pasted out of a web page or
        // a Word document and are invisible in Excel; left in place they make
        // an exact-match lookup fail for a value that looks identical on screen.
        $clean = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? '');

        return $clean === '' ? null : $clean;
    }

    /**
     * A semicolon- or comma-separated cell as a list.
     *
     * @return list<string>
     */
    private function splitList(?string $value): array
    {
        $clean = $this->collapse($value);

        if ($clean === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $part) => trim($part),
            preg_split('/[;,]/', $clean) ?: []
        ), fn (string $part) => $part !== ''));
    }
}
