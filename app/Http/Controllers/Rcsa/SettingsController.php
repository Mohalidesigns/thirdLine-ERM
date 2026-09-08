<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\UpdateRcsaSettingsRequest;
use App\Models\Organization;
use App\Models\Rcsa\RcsaCategoryAppetite;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaMethodology;
use App\Services\Rcsa\RcsaRetentionService;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The one screen behind §14's three open questions.
 *
 * ONE SCREEN, TWO DIFFERENT LIFETIMES, and the screen has to say which is
 * which or it will be wrong in a way nobody notices until a cycle is open:
 *
 *   - APPETITE (Q4) lives on the METHODOLOGY, and a methodology LOCKS the
 *     moment a cycle opens. That is P3's freeze and it is deliberate: "why was
 *     this risk above appetite in March" is answerable only while March's
 *     ceilings still exist. So appetite is editable between cycles and refused
 *     during one, and the screen says so rather than saving into a void.
 *   - OVERRIDE APPROVAL (Q5) and RETENTION (Q10) are TENANT settings, editable
 *     at any time. A workflow policy cannot live on the methodology for exactly
 *     the reason above — a bank would have to version its scoring engine to
 *     turn an approval step on.
 *
 * NOTHING HERE ANSWERS A QUESTION ON THE BANK'S BEHALF. Every control arrives
 * showing the shipped default, and the page says in its own words what each
 * default means, because these are the questions the plan asked them and the
 * build has been guessing at for nine phases.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly RcsaRetentionService $retention) {}

    public function index(Request $request)
    {
        // The route middleware already enforces this; repeated here because a
        // controller that depends on its route for authorisation is one action
        // away from being reachable without it.
        abort_unless($request->user()?->can('rcsa_settings.manage'), 403);

        $organization = $request->user()->organization ?? Organization::find($request->user()->organization_id);
        $methodology = $this->methodology();

        return Inertia::render('RcsaSettings/Index', [
            'methodology' => [
                'id' => $methodology?->id,
                'name' => $methodology?->name,
                'version' => $methodology?->version,
                'appetite_mode' => $methodology?->appetite_mode ?? 'single',
                'appetite_ceiling_level' => $methodology?->appetite_ceiling_level,

                // The screen disables the appetite half and explains why. It
                // does NOT hide it: a control that vanishes teaches people the
                // feature is missing, where a disabled one with a sentence
                // teaches them when it is available.
                'is_locked' => (bool) ($methodology?->is_locked ?? false),
                'locked_reason' => $methodology?->is_locked
                    ? 'This methodology is locked because a cycle has been opened against it. Appetite is '
                        .'frozen for the life of the cycle so that past assessments stay explainable. '
                        .'Close the open cycle, or publish a new methodology version, to change it.'
                    : null,
            ],

            'bands' => $methodology?->bands
                ->map(fn ($band) => ['level' => $band->level, 'label' => $band->label])
                ->values()->all() ?? [],

            'categories' => Template::RISK_CATEGORIES,

            'category_appetites' => $methodology?->categoryAppetites
                ->map(fn (RcsaCategoryAppetite $row) => [
                    'risk_category' => $row->risk_category,
                    'ceiling_level' => $row->ceiling_level,
                    'note' => $row->note,
                ])->values()->all() ?? [],

            // Which categories are leaning on the house ceiling. Empty in
            // single mode, because nothing is falling back in single mode.
            'categories_without_appetite' => $methodology?->categoriesWithoutAppetite(Template::RISK_CATEGORIES) ?? [],

            'treatment_override_approval_required' => (bool) data_get(
                $organization?->settings, 'rcsa.treatment_override_approval_required', false
            ),

            'retention' => $this->retention->policyFor($organization),

            // What a sweep would do today, so nobody sets a period without
            // seeing what it selects. The report deletes nothing.
            'retention_preview' => $organization === null ? null : $this->retention->report($organization),

            'open_cycles' => RcsaCycle::query()
                ->where('status', RcsaCycle::OPEN)
                ->get(['id', 'name'])
                ->map(fn (RcsaCycle $cycle) => ['id' => $cycle->id, 'name' => $cycle->name])
                ->values()->all(),
        ]);
    }

    public function update(UpdateRcsaSettingsRequest $request)
    {
        $organization = $request->user()->organization ?? Organization::find($request->user()->organization_id);
        $methodology = $this->methodology();
        $data = $request->validated();

        DB::transaction(function () use ($data, $organization, $methodology) {
            /* --- Q5 and Q10: tenant settings, always editable --------- */

            // MERGED, NOT REPLACED. `settings` carries `board_pack`,
            // `mfa_required_roles`, `bu_approval_required` and whatever a later
            // module adds; writing the whole array back would silently drop
            // every key this screen does not know about.
            $organization?->forceFill([
                'settings' => array_replace_recursive((array) $organization->settings, [
                    'rcsa' => [
                        'treatment_override_approval_required' => (bool) $data['treatment_override_approval_required'],
                        'retention' => [
                            'export_files_days' => $data['retention']['export_files_days'] ?? null,
                            'import_files_days' => $data['retention']['import_files_days'] ?? null,
                            'closed_cycle_years' => $data['retention']['closed_cycle_years'] ?? null,
                        ],
                    ],
                ]),
            ])->save();

            /* --- Q4: the methodology, only while it is unlocked -------- */

            if ($methodology === null || $methodology->is_locked) {
                return;
            }

            $methodology->forceFill([
                'appetite_mode' => $data['appetite_mode'],
                'appetite_ceiling_level' => $data['appetite_ceiling_level'],
            ])->save();

            // Replaced wholesale rather than diffed: the form posts the
            // complete set, a removed row means the category goes back to the
            // house ceiling, and reconciling three-way would be more code for
            // a table with at most thirteen rows.
            $methodology->categoryAppetites()->delete();

            foreach ($data['category_appetites'] ?? [] as $row) {
                $methodology->categoryAppetites()->create([
                    'risk_category' => $row['risk_category'],
                    'ceiling_level' => $row['ceiling_level'],
                    'note' => $row['note'] ?? null,
                ]);
            }
        });

        return back()->with(
            'success',
            $methodology?->is_locked
                ? 'Saved. Appetite was not changed — the methodology is locked while a cycle is open.'
                : 'RCSA settings saved.',
        );
    }

    private function methodology(): ?RcsaMethodology
    {
        return RcsaMethodology::active()?->loadMissing(['bands', 'categoryAppetites']);
    }
}
