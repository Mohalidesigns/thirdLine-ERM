<?php

namespace App\Support\Rcsa;

use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * §13 step 6: "Flip the flag per tenant; legacy routes redirect; legacy tables
 * become read-only."
 *
 * THE FLAG IS PER ENVIRONMENT AND CUTOVER IS PER TENANT, which are different
 * decisions and cannot share a switch. `features.rcsa_v2` says the new module
 * exists in this deployment; cutover says a particular bank has finished its
 * parallel run and stopped using the old one. A shared install running six
 * tenants cuts them over one at a time, months apart.
 *
 * SO IT LIVES IN `organizations.settings['rcsa']['cutover_at']`, beside the
 * BU-approval setting P7 put there, and it is a DATE rather than a boolean —
 * because "when did this bank cut over" is the question the retention period,
 * the rollback window and every audit conversation actually asks.
 *
 * "LEGACY TABLES BECOME READ-ONLY" CANNOT BE APPLIED LITERALLY HERE, and the
 * inventory is what established why: the legacy module has no tables of its own.
 * It reads `risks` and `controls`, which are the enterprise register that the
 * Risk Register, the KRI module, the control library and the board pack all
 * read too. Locking them would take half the product down. What is genuinely
 * legacy — and what therefore actually closes — is the one WRITE PATH: the
 * worksheet submission that files into campaign responses. After cutover it
 * refuses. The legacy READ screens stay reachable, because §13 keeps the old
 * module for at least one audit cycle and a regulator may ask what it showed.
 */
class RcsaCutover
{
    /**
     * When this tenant cut over, or null if it has not.
     */
    public function cutOverAt(Organization|int|null $subject): ?Carbon
    {
        $organization = match (true) {
            $subject instanceof Organization => $subject,
            is_int($subject) => Organization::query()->withoutGlobalScopes()->find($subject),
            default => null,
        };

        $settings = $organization?->settings;
        $value = is_array($settings) ? ($settings['rcsa']['cutover_at'] ?? null) : null;

        return blank($value) ? null : Carbon::parse((string) $value);
    }

    public function hasCutOver(Organization|int|null $subject): bool
    {
        return $this->cutOverAt($subject) !== null;
    }

    /**
     * Days since cutover, for the rollback window.
     */
    public function daysSince(Organization|int|null $subject): ?int
    {
        $at = $this->cutOverAt($subject);

        return $at === null ? null : (int) round($at->diffInDays(now()));
    }

    /**
     * Record the cutover.
     *
     * The whole `rcsa` block is merged rather than replaced, so turning on
     * cutover does not silently clear the BU-approval setting sitting beside
     * it.
     */
    public function cutOver(Organization $organization, ?Carbon $at = null): void
    {
        $this->writeRcsaSettings($organization, ['cutover_at' => ($at ?? now())->toDateTimeString()]);
    }

    /**
     * Reverse it — §13 step 7's flag flip back.
     */
    public function reverse(Organization $organization): void
    {
        $this->writeRcsaSettings($organization, ['cutover_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function writeRcsaSettings(Organization $organization, array $changes): void
    {
        $settings = (array) ($organization->settings ?? []);
        $rcsa = (array) ($settings['rcsa'] ?? []);

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($rcsa[$key]);
            } else {
                $rcsa[$key] = $value;
            }
        }

        $settings['rcsa'] = $rcsa;

        $organization->forceFill(['settings' => $settings])->save();
    }
}
