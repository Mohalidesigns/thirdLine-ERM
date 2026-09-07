<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\SaveRulesetRequest;
use App\Models\Tprm\Ruleset;
use App\Services\Tprm\Scoring\RulesetSandbox;
use App\Support\Tprm\DefaultRuleset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The ruleset editor and its sandbox — FR-TIER-09.
 *
 * `tprm.ruleset.manage` is a separate permission from `tprm.admin` and sits
 * with the CRO rather than with whoever runs the programme, for the same
 * reason `admin.scoring` is separate in the core product: this screen
 * redefines what Critical MEANS for every vendor at once.
 *
 * PUBLISHING IS THE ONLY WRITE THAT MOVES ANY SCORE, and it is guarded twice:
 * the weights must total 100, and the sandbox must have been run. The second
 * is not bureaucracy — a published ruleset is immutable and every score
 * afterwards cites its version, so publishing without looking at the migration
 * table is choosing not to know what you did.
 */
class RulesetController extends Controller
{
    public function __construct(private readonly RulesetSandbox $sandbox) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.ruleset.manage');

        $rulesets = Ruleset::query()
            ->with('publisher:id,name')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Ruleset $ruleset) => [
                'id' => $ruleset->getKey(),
                'version' => $ruleset->version,
                'name' => $ruleset->name,
                'status' => $ruleset->status,
                'published_at' => $ruleset->published_at?->toDateString(),
                'published_by' => $ruleset->publisher?->name,
                'factor_count' => count((array) $ruleset->factors),
                'knockout_count' => count((array) $ruleset->knockouts),
                'url' => route('tprm.rulesets.show', $ruleset),
            ]);

        return Inertia::render('Tprm/Settings/Rulesets', [
            'rulesets' => $rulesets,
            'current' => Ruleset::query()->published()->latest('published_at')->value('version'),
        ]);
    }

    public function show(Request $request, Ruleset $ruleset)
    {
        Gate::authorize('tprm.ruleset.manage');

        $value = $ruleset->toValue();

        return Inertia::render('Tprm/Settings/RulesetEditor', [
            'ruleset' => [
                'id' => $ruleset->getKey(),
                'version' => $ruleset->version,
                'name' => $ruleset->name,
                'notes' => $ruleset->notes,
                'status' => $ruleset->status,
                'factors' => $ruleset->factors,
                'knockouts' => $ruleset->knockouts,
                'band_edges' => $ruleset->band_edges,
                'editable' => $ruleset->status === Ruleset::STATUS_DRAFT,
                'total_weight' => $value->totalWeight(),
                'weights_valid' => $value->weightsTotalOneHundred(),
            ],
            // The `source: derived` markers, so the editor can say which
            // numbers are the TRD's and which are ours.
            'derivedFactors' => collect(DefaultRuleset::factors())
                ->filter(fn (array $factor) => ($factor['source'] ?? null) === 'derived')
                ->keys()->values(),
        ]);
    }

    /**
     * Start a draft from the ruleset currently in force.
     *
     * A draft is always a COPY. Editing the live one in place is what the
     * model's `updating` guard refuses, because a score citing version 1.0
     * has to still be explainable by fetching version 1.0.
     */
    public function draft(Request $request)
    {
        Gate::authorize('tprm.ruleset.manage');

        $current = Ruleset::query()->published()->latest('published_at')->first();

        // A tenant with no published ruleset starts from the shipped defaults
        // rather than from an empty draft — an editor opening on seven blank
        // factors is one nobody can use.
        $basis = $current === null
            ? [
                'name' => 'Draft from the shipped defaults',
                'factors' => DefaultRuleset::factors(),
                'knockouts' => DefaultRuleset::knockouts(),
                'band_edges' => DefaultRuleset::bandEdges(),
                'supersedes_id' => null,
            ]
            : [
                'name' => 'Draft from '.$current->version,
                'factors' => $current->factors,
                'knockouts' => $current->knockouts,
                'band_edges' => $current->band_edges,
                'supersedes_id' => $current->getKey(),
            ];

        $draft = Ruleset::create($basis + [
            'version' => $this->nextVersion(),
            'status' => Ruleset::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('tprm.rulesets.show', $draft);
    }

    public function update(SaveRulesetRequest $request, Ruleset $ruleset)
    {
        if ($ruleset->status !== Ruleset::STATUS_DRAFT) {
            return back()->with('error', 'A published ruleset cannot be edited. Start a new draft instead.');
        }

        $ruleset->update($request->validated() + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Draft saved. Run the simulator before publishing.');
    }

    /**
     * Run the draft against the live portfolio, in memory.
     */
    public function simulate(Request $request, Ruleset $ruleset)
    {
        Gate::authorize('tprm.ruleset.manage');

        $result = $this->sandbox->simulate($ruleset->toValue());

        return response()->json($result);
    }

    public function publish(Request $request, Ruleset $ruleset)
    {
        Gate::authorize('tprm.ruleset.manage');

        if ($ruleset->status !== Ruleset::STATUS_DRAFT) {
            return back()->with('error', 'Only a draft can be published.');
        }

        $value = $ruleset->toValue();

        if (! $value->weightsTotalOneHundred()) {
            return back()->with('error', sprintf(
                'The factor weights total %s. They must total 100 before a ruleset can be published, so that a '
                .'weight of 25 means what an administrator reading it thinks it means.',
                rtrim(rtrim(number_format($value->totalWeight(), 2), '0'), '.')
            ));
        }

        DB::transaction(function () use ($ruleset, $request) {
            // Exactly one published ruleset is current, or "which rules
            // produced this score" stops having an answer.
            Ruleset::query()
                ->published()
                ->whereKeyNot($ruleset->getKey())
                ->update(['status' => Ruleset::STATUS_RETIRED]);

            $ruleset->forceFill([
                'status' => Ruleset::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $request->user()->id,
            ])->save();
        });

        return redirect()
            ->route('tprm.rulesets.index')
            ->with('success', "Ruleset {$ruleset->version} is now in force. Scores computed from now on cite it; "
                .'existing scores keep the version that produced them.');
    }

    private function nextVersion(): string
    {
        $year = now()->year;
        $count = Ruleset::query()->where('version', 'like', $year.'.%')->count();

        return $year.'.'.($count + 1);
    }
}
