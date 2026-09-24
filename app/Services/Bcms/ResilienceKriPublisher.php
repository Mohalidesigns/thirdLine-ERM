<?php

namespace App\Services\Bcms;

use App\Models\KeyRiskIndicator;
use App\Services\KriMeasureBridge;
use App\Support\Bcms\ResilienceKris;
use Carbon\CarbonImmutable;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Adopts the seventeen resilience KRI definitions into the platform KRI
 * register, one tenant at a time — ADR 0021 §2.
 *
 * THE PRECEDENT IS TPRM'S `KriPublisher`, FOLLOWED NOT RE-INVENTED: `adopt()`
 * creates whatever is missing by `kri_code` and is safe to call repeatedly —
 * every call after the first writes nothing for a code that already exists,
 * so a threshold, name or owner a tenant has since tuned is never overwritten.
 *
 * NO LINK TABLE. TPRM needed `tp_kri_links` because its internal metric code
 * (`critical_assessed_within_cadence`) and the register's `kri_code`
 * (`TPRM-01`) are two vocabularies. BCMS's own `kri_code` (`BCMS-*`) IS the
 * single identity on both sides, so the join is `kri_code` directly.
 *
 * THIS CLASS WRITES EXACTLY ONE MEASUREMENT ITSELF: `BCMS-VENDOR-ATTEST`, via
 * `recordVendorAttestation()`, called from supplier resilience. Every other
 * code's reading is filed by whichever phase actually computes it, through
 * `KriMeasureBridge::recordMeasurement()` — never by setting `current_value`
 * directly, which is exactly the mistake Phase 6's `mirrorKris()` made before
 * this phase's remediation.
 */
class ResilienceKriPublisher
{
    public function __construct(private readonly KriMeasureBridge $bridge) {}

    /**
     * Create any definition missing for this tenant. Idempotent on `kri_code`.
     *
     * @return array{created: int, existing: int}
     */
    public function adopt(?int $ownerId = null): array
    {
        $created = 0;
        $existing = 0;

        foreach (ResilienceKris::all() as $definition) {
            $found = KeyRiskIndicator::query()->where('kri_code', $definition['kri_code'])->first();

            if ($found !== null) {
                $existing++;

                continue;
            }

            $this->createKri($definition, $ownerId);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * The compliance matrix's and board pack's 9.1 evidence line: every
     * definition, whether or not it has been adopted for this tenant, and its
     * current reading if it has one.
     *
     * A CODE WITH NO MATCHING ROW IS "NOT LINKED", NEVER SILENTLY DROPPED —
     * that is also how a tenant discovers they broke the join by editing a
     * `kri_code` (ADR 0021 §2's own stated purpose for this line).
     *
     * @return list<array<string, mixed>>
     */
    public function status(): array
    {
        $linked = KeyRiskIndicator::query()
            ->whereIn('kri_code', ResilienceKris::codes())
            ->get()
            ->keyBy('kri_code');

        return array_map(function (array $definition) use ($linked) {
            /** @var ?KeyRiskIndicator $kri */
            $kri = $linked->get($definition['kri_code']);

            return [
                'kri_code' => $definition['kri_code'],
                'name' => $definition['name'],
                'unit' => $definition['unit'],
                'direction' => $definition['direction'],
                'target' => $definition['target'],
                'producer' => $definition['producer'],
                'linked' => $kri !== null,
                'kri_id' => $kri?->getKey(),
                'current_value' => $kri?->current_value === null ? null : (float) $kri->current_value,
                'current_status' => $kri?->current_status,
                'last_measured_at' => $kri?->last_measurement_date?->toDateString()
                    ?? $kri?->last_measurement_at?->toDateString(),
            ];
        }, ResilienceKris::all());
    }

    /**
     * The one measurement this phase writes itself — §4 of the spec.
     *
     * A NULL READING IS SKIPPED, NOT PUBLISHED AS ZERO. No BCMS-critical
     * vendor at all is a statement about an empty dependency graph, not a
     * perfect (or a failed) continuity programme.
     */
    public function recordVendorAttestation(?float $percentageCurrent, string $note, ?int $actorId = null): void
    {
        if ($percentageCurrent === null) {
            return;
        }

        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-VENDOR-ATTEST')->first();

        // A12: this used to call the full `adopt()`, which creates all
        // SEVENTEEN definitions as a side effect of recording one vendor
        // attestation — silently changing a bank's risk register from a
        // write path that has nothing to do with adopting KRIs. Creating
        // the register is `bcms:kri:adopt`'s job alone (ADR 0021 §2: "never
        // adopt silently"). This measurement writes only its own KRI if it
        // is missing, and fails clearly — logged, not thrown, since a
        // missing register must never block a vendor's attestation from
        // being recorded — if even that lookup cannot resolve.
        if ($kri === null) {
            $definition = ResilienceKris::find('BCMS-VENDOR-ATTEST');

            if ($definition === null) {
                \Illuminate\Support\Facades\Log::warning(
                    'BCMS-VENDOR-ATTEST has no definition in ResilienceKris — cannot record a measurement.'
                );

                return;
            }

            $kri = $this->createKri($definition, $actorId);
        }

        $this->bridge->recordMeasurement($kri, CarbonImmutable::now(), $percentageCurrent, [
            'entered_by' => $actorId,
            'source' => 'bcms.supplier_resilience',
            'notes' => $note,
        ]);
    }

    /**
     * `entity_id` and `risk_id` are left unset on purpose (ADR 0021 Amendment
     * 1): every resilience KRI is organisation-level by definition, so it
     * takes no node in the org graph and its widget tile shows the whole
     * tenant's figure, never a branch's.
     *
     * @param  array<string, mixed>  $definition
     */
    private function createKri(array $definition, ?int $ownerId): KeyRiskIndicator
    {
        $direction = $definition['direction'];
        $target = (float) $definition['target'];

        // Derived from the ONE target the spec gives per KRI, not a second
        // number nobody supplied. A tenant that wants a different amber band
        // edits it once adopted — adopt()'s idempotency is what keeps that
        // edit safe across a re-run.
        $bands = $direction === ResilienceKris::HIGHER_IS_WORSE
            ? $this->higherIsWorseBands($target)
            : $this->lowerIsWorseBands($target);

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => TenantContext::organizationId(),
            'kri_code' => $definition['kri_code'],
            'name' => $definition['name'],
            'kri_name' => $definition['name'],
            'description' => $definition['description'],
            'data_source' => 'BCMS — '.$definition['producer'],
            'measurement_frequency' => $definition['frequency'],
            'unit_of_measure' => $definition['unit'],
            'measurement_unit' => $definition['unit'],
            'target_value' => $target,
            'direction' => $direction,
            'threshold_direction' => $direction === ResilienceKris::HIGHER_IS_WORSE ? 'higher_worse' : 'lower_worse',
            'owner_id' => $ownerId,
            'kri_owner_id' => $ownerId,
            'is_automated' => true,
            'automation_config' => ['source' => 'bcms', 'kri_code' => $definition['kri_code']],
            'is_active' => true,
        ], $bands));
    }

    /** @return array<string, float|null> */
    private function lowerIsWorseBands(float $target): array
    {
        // A floor: at or above the target is green. Red starts fifteen
        // percentage points (or fifteen percent of the target, whichever
        // reads sensibly for a 0-100 target) below it.
        $red = max(0.0, $target - max(15.0, $target * 0.15));

        return [
            'green_threshold_min' => $target, 'green_threshold_max' => null,
            'amber_threshold_min' => $red, 'amber_threshold_max' => $target,
            'red_threshold_min' => null, 'red_threshold_max' => $red,
        ];
    }

    /** @return array<string, float|null> */
    private function higherIsWorseBands(float $target): array
    {
        // A ceiling: at or below the target is green. A count target of
        // zero (the two call-tree gap KRIs) has no room for an amber band —
        // one is already the finding — so amber collapses to the same point
        // as red for those two, which is correct, not a bug.
        $red = $target <= 0.0 ? 1.0 : $target * 1.5;

        return [
            'green_threshold_min' => null, 'green_threshold_max' => $target,
            'amber_threshold_min' => $target, 'amber_threshold_max' => $red,
            'red_threshold_min' => $red, 'red_threshold_max' => null,
        ];
    }
}
