<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Support\Tprm\ObligationTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clause library settings screen.
 *
 * A tenant may add its own clauses and supply model text for the shipped ones.
 * It may NOT delete a system clause, edit its citation, or turn off its
 * blocking flag — those three together are what make the clause the regulation
 * rather than an opinion about it, and a tenant able to change them could make
 * its own audit-rights gap disappear.
 *
 * MODEL TEXT SHIPS EMPTY AND THE SCREEN SAYS WHY. Drafting contract language
 * is a lawyer's job; a plausible-looking clause pasted into a real agreement
 * is a liability rather than a feature. The field is here for a tenant's legal
 * function to fill, and the gap report says so in its place until they do.
 */
class ClauseLibraryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('tprm.contract.view');

        $clauses = ClauseLibraryEntry::query()
            ->availableTo()
            ->orderBy('category')
            ->orderBy('code')
            ->get();

        return Inertia::render('Tprm/Settings/ClauseLibrary', [
            'clauses' => $clauses->map(fn (ClauseLibraryEntry $clause) => [
                'id' => $clause->getKey(),
                'code' => $clause->code,
                'title' => $clause->title,
                'category' => $clause->category,
                'regulatory_source' => $clause->regulatory_source,
                'citation' => $clause->citation,
                'is_blocking' => (bool) $clause->is_blocking,
                'is_system_owned' => (bool) $clause->is_system_owned,
                'has_model_text' => $clause->model_text !== null && $clause->model_text !== '',
                'model_text' => $clause->model_text,
                'guidance' => $clause->guidance,
                'applicability_rule' => $clause->applicability_rule,
                'applies_always' => empty($clause->applicability_rule),
                'editable_fields' => $clause->tenantEditableFields(),
                'status' => $clause->status,
                // What duties this clause puts on the register when it is
                // satisfied — the answer to "why did approving this contract
                // create four tasks".
                'obligations' => array_map(fn (array $t) => [
                    'obligor' => $t['obligor'],
                    'title' => $t['title'],
                    'frequency' => $t['frequency'],
                ], ObligationTemplates::for($clause->code)),
            ])->values(),
            'summary' => [
                'total' => $clauses->count(),
                'blocking' => $clauses->where('is_blocking', true)->count(),
                'system_owned' => $clauses->where('is_system_owned', true)->count(),
                // Stated plainly: the shipped library carries no model text,
                // and a tenant reading a gap report deserves to know that is a
                // deliberate absence rather than a bug.
                'without_model_text' => $clauses->filter(fn ($c) => blank($c->model_text))->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('tprm.contract.manage'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('tprm.contract.manage');

        $validated = $request->validate([
            'code' => 'required|string|max:40',
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:60',
            'regulatory_source' => 'nullable|string|max:120',
            'citation' => 'nullable|string|max:255',
            'is_blocking' => 'boolean',
            'model_text' => 'nullable|string',
            'guidance' => 'nullable|string',
        ]);

        ClauseLibraryEntry::create($validated + [
            'organization_id' => $request->user()->organization_id,
        ]);

        return back()->with('success', 'The clause was added to your library.');
    }

    public function update(Request $request, ClauseLibraryEntry $clause)
    {
        Gate::authorize('tprm.contract.manage');

        $editable = $clause->tenantEditableFields();

        $validated = $request->validate([
            'code' => 'sometimes|string|max:40',
            'title' => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:60',
            'regulatory_source' => 'nullable|string|max:120',
            'citation' => 'nullable|string|max:255',
            'is_blocking' => 'sometimes|boolean',
            'model_text' => 'nullable|string',
            'guidance' => 'nullable|string',
            'status' => 'sometimes|in:published,retired',
        ]);

        // Filtered rather than refused: a form that posts the whole row should
        // save the fields it may and leave the rest, not fail with an error
        // about a field the screen disabled anyway.
        $clause->fill(array_intersect_key($validated, array_flip($editable)))->save();

        $ignored = array_diff(array_keys($validated), $editable);

        return back()->with('success', $ignored === []
            ? 'The clause was updated.'
            : sprintf(
                'The clause was updated. %s could not be changed because this is a regulatory clause shipped '
                .'with the product — its wording is yours to draft, its citation is not.',
                ucfirst(implode(', ', $ignored)),
            ));
    }

    public function destroy(Request $request, ClauseLibraryEntry $clause)
    {
        Gate::authorize('tprm.contract.manage');

        if ($clause->is_system_owned) {
            return back()->with('error',
                'A regulatory clause shipped with the product cannot be deleted. Where it does not apply to a '
                .'particular engagement, waive it there with a rationale and an expiry — a waiver appears on '
                .'the override register, and a deletion would not.'
            );
        }

        $clause->delete();

        return back()->with('success', 'The clause was removed from your library.');
    }
}
