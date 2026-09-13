<?php

namespace App\Services\Tprm\Reporting;

use App\Enums\Tprm\RiskTier;
use App\Models\BusinessUnit;
use Illuminate\Http\Request;

/**
 * The filtered view a register export was taken from — the second half of
 * AC-13.
 *
 * The criterion is that the export reconciles ROW FOR ROW to the filtered UI
 * view. The only way to hold that over time is for the screen and the export
 * to be the same query with the same arguments, so the filters are parsed once
 * here, out of the request, and handed to the builder by both. A controller
 * that read `$request->tier` for the screen and `$request->input('tier')` for
 * the export would agree today and drift the first time a filter is added.
 *
 * UNKNOWN VALUES ARE DROPPED, NOT PASSED THROUGH. A tier of `banana` becomes
 * no tier filter and the provenance says "All tiers" — the alternative is an
 * export that silently returns nothing and a preparer who believes the
 * institution has no critical vendors.
 */
final class RegisterFilters
{
    private function __construct(
        public readonly ?RiskTier $tier = null,
        public readonly ?int $businessUnitId = null,
        public readonly bool $criticalOnly = false,
        public readonly bool $includeInactive = false,
        public readonly ?string $search = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            tier: RiskTier::tryFrom((string) $request->query('tier', '')),
            businessUnitId: self::positiveInt($request->query('business_unit')),
            criticalOnly: $request->boolean('critical_only'),
            includeInactive: $request->boolean('include_inactive'),
            search: self::trimmed($request->query('search')),
        );
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * The humanised block that goes on the cover page and the spreadsheet
     * stamp. Every filter appears, including the ones that are not set — a
     * reader checking a row count against the screen needs to know that
     * inactive engagements were excluded, and "not mentioned" does not say so.
     *
     * @return array<string, string>
     */
    public function provenance(): array
    {
        return [
            'Tier' => $this->tier?->label() ?? 'All tiers',
            'Business unit' => $this->businessUnitLabel(),
            'Critical functions only' => $this->criticalOnly ? 'Yes' : 'No',
            'Population' => $this->includeInactive
                ? 'All engagements, including terminated and archived'
                : 'Live engagements only',
            'Text search' => $this->search ?? 'None',
        ];
    }

    /**
     * @return array<string, mixed> the shape the screen posts back, so a
     *                              download link can carry exactly the view on screen
     */
    public function toQuery(): array
    {
        return array_filter([
            'tier' => $this->tier?->value,
            'business_unit' => $this->businessUnitId,
            'critical_only' => $this->criticalOnly ? 1 : null,
            'include_inactive' => $this->includeInactive ? 1 : null,
            'search' => $this->search,
        ], fn ($value) => $value !== null);
    }

    private function businessUnitLabel(): string
    {
        if ($this->businessUnitId === null) {
            return 'All business units';
        }

        return BusinessUnit::query()->whereKey($this->businessUnitId)->value('name')
            ?? 'Business unit #'.$this->businessUnitId;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function trimmed(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
