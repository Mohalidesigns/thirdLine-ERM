<?php

namespace App\Support\Rcsa;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

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
 *
 * WHAT A USER SEES (RCSA-02, decided 2026-10-05, "cut over + label legacy").
 * Two RCSAs live side by side was §13's own design — "build alongside, not on
 * top" — and the plan's step 6 is the rule that ends it: "Flip the flag per
 * tenant; legacy routes redirect; legacy tables become read-only." The QA run
 * found the cost of the parallel period: two menu entries called "Dashboard"
 * showing different numbers, because the legacy screens read the enterprise
 * register and v2 reads the cycle tables. So, while the flag is on:
 *
 *   - BEFORE cutover the three legacy menu entries stay, relabelled so they
 *     cannot be mistaken for the cycle-based module, and each legacy screen
 *     carries a notice saying what it is and where the other module lives;
 *   - AFTER cutover the legacy entries leave the menu. The URLs stay reachable
 *     (§13 keeps the old module for an audit cycle) and the worksheet says
 *     plainly that it has closed.
 *
 * With the flag off nothing changes: the legacy module is the install's only
 * RCSA, so there is nothing to disambiguate and nothing to hide.
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
     * Whether the legacy RCSA entries are shown as the legacy module, or left
     * out of the menu — one decision, so the menu and the screens agree.
     *
     * `v2` is the per-environment flag. `cutOver` is only meaningful with it
     * on: a tenant cannot have "moved to" a module this install does not run,
     * and hiding the legacy entries then would leave no RCSA in the menu at all.
     *
     * @return array{v2: bool, cutOver: bool, cutOverAt: string|null}
     */
    public function legacyState(Organization|int|null $subject): array
    {
        $v2 = filter_var(config('features.rcsa_v2', false), FILTER_VALIDATE_BOOLEAN);
        $at = $v2 ? $this->cutOverAt($subject) : null;

        return ['v2' => $v2, 'cutOver' => $at !== null, 'cutOverAt' => $at?->toDateString()];
    }

    /**
     * The props the legacy screens render their notice from. `v2Url` is null
     * when the viewer cannot open the cycle dashboard, so the notice never
     * links to a 403.
     *
     * @return array{v2: bool, cutOver: bool, cutOverAt: string|null, v2Url: string|null}
     */
    public function legacyNotice(?User $user): array
    {
        $state = $this->legacyState($user?->organization_id);

        return $state + [
            'v2Url' => $state['v2'] && $user?->can('rcsa_assessment.view') && Route::has('rcsa.dashboard.index')
                ? route('rcsa.dashboard.index')
                : null,
        ];
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
