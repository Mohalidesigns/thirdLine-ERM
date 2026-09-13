<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\UpdateProgrammeSettingsRequest;
use App\Models\Tprm\TprmSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The per-tenant facts the module cannot ship a default for — and the screen
 * that was missing for them.
 *
 * TWO GO-LIVE GAPS CLOSE HERE, and they are the same gap wearing two hats.
 * Phase 9 shipped `tp_settings.shareholders_funds_minor` with no way to enter
 * it, so the CBN 0.01% materiality test could not run and loss-only incidents
 * reported as `undetermined` — honest, and useless. Phase 10 then needed the
 * institution's LEI, country and competent authority for DORA RT.01.01, with
 * the same problem. A column with no screen is a column that is always null.
 *
 * NOTHING HERE HAS A DEFAULT AND NOTHING IS INFERRED. Shareholders' funds
 * cannot be guessed from anything this product holds; an LEI is issued by a
 * registration authority and is not derivable from an RC number. The screen
 * shows what is unset and what that costs — an unset funds figure is labelled
 * with the incident test it disables, not with a red asterisk.
 *
 * `tprm.admin` GATES IT. The funds figure moves the threshold at which an
 * incident becomes reportable to the Central Bank; that is a programme
 * administration decision, not a reporting one.
 */
class ProgrammeSettingsController extends Controller
{
    public function edit(Request $request)
    {
        Gate::authorize('tprm.admin');

        $settings = TprmSetting::forOrganization((int) TenantContext::organizationId());

        return Inertia::render('Tprm/Settings/Programme', [
            'settings' => [
                'lei' => $settings->lei,
                'country' => $settings->country,
                'competent_authority' => $settings->competent_authority,
                'reporting_currency' => $settings->reporting_currency,
                // Presented in major units. The column is minor units like
                // every other money column in the module; the conversion
                // happens in one place, here and in the request.
                'shareholders_funds' => $settings->shareholders_funds_minor === null
                    ? null
                    : round($settings->shareholders_funds_minor / 100, 2),
                'shareholders_funds_currency' => $settings->shareholders_funds_currency,
                'shareholders_funds_as_at' => $settings->shareholders_funds_as_at?->toDateString(),
                'regulatory_contact_name' => $settings->regulatory_contact_name,
                'regulatory_contact_title' => $settings->regulatory_contact_title,
            ],
            'consequences' => [
                'materiality' => [
                    'available' => $settings->hasMaterialityBasis(),
                    'threshold' => $settings->cbnMaterialityThresholdMinor() === null
                        ? null
                        : round($settings->cbnMaterialityThresholdMinor() / 100, 2),
                    'percentage' => (float) config('tprm.clocks.cbn_materiality_pct_of_shareholders_funds'),
                    'age_months' => $settings->ageInMonths(),
                ],
                'register_of_information' => [
                    'missing' => collect([
                        'LEI' => $settings->lei,
                        'Country' => $settings->country,
                        'Competent authority' => $settings->competent_authority,
                        'Reporting currency' => $settings->reporting_currency,
                    ])->filter(fn ($value) => blank($value))->keys()->values(),
                ],
            ],
            'updatedBy' => $settings->updater?->name,
            'updatedAt' => $settings->updated_at?->toDayDateTimeString(),
        ]);
    }

    public function update(UpdateProgrammeSettingsRequest $request)
    {
        $settings = TprmSetting::forOrganization((int) TenantContext::organizationId());

        $settings->fill($request->settingsPayload() + ['updated_by' => $request->user()->id])->save();

        return back()->with('success', 'TPRM programme settings saved.');
    }
}
