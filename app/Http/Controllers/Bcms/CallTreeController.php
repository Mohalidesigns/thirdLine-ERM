<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\ChannelKey;
use App\Http\Controllers\Controller;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Presenters\Bcms\CallTreePresenter;
use App\Services\Bcms\CallTrees\CallTreeRemediation;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\TreeHealthService;
use App\Services\Bcms\CallTrees\TreeProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Call trees: the dashboard, the designer and the version chain.
 *
 * THE DASHBOARD IS THE LANDING SCREEN AND NOT A LIST OF TREES. A department
 * head opening this module does not want an inventory; they want to know which
 * of their cascades would fail today. Staleness, orphaned nodes and missing
 * deputies are the three answers, and they are above the fold.
 *
 * Presentation is the presenter's (development standard §1) and every route
 * carries its own permission (§2). Editing is `bcms.calltree.manage`; seeing is
 * `bcms.calltree.view`; firing a cascade at two hundred people is
 * `bcms.calltree.test`, which is deliberately a third permission — dispatching
 * to a whole department is a different authority from drawing the tree.
 */
class CallTreeController extends Controller
{
    public function __construct(
        private CallTreeService $trees,
        private TreeProposalService $proposals,
        private TreeHealthService $health,
        private CallTreeRemediation $remediation,
        private CallTreePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.calltree.view');

        return Inertia::render('Bcms/CallTrees/Index', [
            'dashboard' => $this->health->dashboard($request->user()),
            'units' => $this->proposals->generatableUnits()
                ->map(fn (BusinessUnit $u) => ['id' => (int) $u->getKey(), 'name' => $u->name, 'code' => $u->code])
                ->all(),
            'types' => array_map(
                fn (CallTreeType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'generatable' => $t->followsManagerChain(),
                    'review_days' => $t->defaultReviewDays(),
                ],
                CallTreeType::cases(),
            ),
            'modes' => array_map(
                fn (CascadeMode $m) => ['value' => $m->value, 'label' => $m->label()],
                CascadeMode::cases(),
            ),
            'scope_note' => app(\App\Support\Rcsa\RcsaScope::class)->describe($request->user()),
            'can' => $this->abilities($request),
        ]);
    }

    /** The designer. */
    public function show(Request $request, CallTree $call_tree): Response
    {
        Gate::authorize('bcms.calltree.view');

        return Inertia::render('Bcms/CallTrees/Designer', array_merge(
            $this->presenter->designer($call_tree, $request->user()),
            ['can' => $this->abilities($request)],
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'tree_type' => ['required', 'string', 'in:'.implode(',', array_column(CallTreeType::cases(), 'value'))],
            // Existence is checked against the tenant's own units, not with a
            // bare `exists:` (development standard §4) — a bare rule would let
            // a tree be hung off another organisation's department.
            'business_unit_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'review_frequency_days' => ['nullable', 'integer', 'min:7', 'max:1095'],
            'activation_authority_user_id' => ['nullable', 'integer'],
        ]);

        $this->assertUnitInTenant($data['business_unit_id'] ?? null);

        $tree = $this->trees->create($data, (int) $request->user()?->getKey());

        return redirect()->route('bcms.call-trees.show', $tree)
            ->with('success', 'Call tree created. Add the activation authority at tier 0 to begin.');
    }

    public function update(Request $request, CallTree $call_tree): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'review_frequency_days' => ['sometimes', 'integer', 'min:7', 'max:1095'],
            'activation_authority_user_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->trees->update($call_tree, $data, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Call tree updated.');
    }

    public function approve(Request $request, CallTree $call_tree): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        try {
            $this->trees->approve($call_tree, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Call tree approved. It is now immutable — edit it by creating the next version.');
    }

    public function supersede(Request $request, CallTree $call_tree): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $data = $request->validate(['version' => ['nullable', 'string', 'max:20']]);

        try {
            $next = $this->trees->supersede($call_tree, $data['version'] ?? null, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['version' => $e->getMessage()]);
        }

        return redirect()->route('bcms.call-trees.show', $next)
            ->with('success', 'Version '.$next->version.' created as a draft. Version '.$call_tree->version.' is archived and unchanged.');
    }

    /** A review that changed nothing still restarts the clock. */
    public function review(Request $request, CallTree $call_tree): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $this->trees->markReviewed($call_tree, (int) $request->user()?->getKey());

        return back()->with('success', 'Review recorded.');
    }

    public function versions(Request $request, CallTree $call_tree): JsonResponse
    {
        Gate::authorize('bcms.calltree.view');

        return response()->json(['versions' => $this->presenter->versions($call_tree)]);
    }

    /* ------------------------------------------------------------------ */
    /*  Generate from the directory */
    /* ------------------------------------------------------------------ */

    /** What the generated tree would look like — nothing is written. */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $unit = $this->unitOrFail((int) $request->integer('business_unit_id'));
        $type = CallTreeType::tryFrom((string) $request->string('tree_type')) ?? CallTreeType::Department;

        try {
            $plan = $this->proposals->preview($unit, $type);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['tree_type' => $e->getMessage()]);
        }

        return response()->json([
            'plan' => $plan,
            'coverage' => $this->proposals->chainCoverage($unit),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $data = $request->validate([
            'business_unit_id' => ['required', 'integer'],
            'tree_type' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $unit = $this->unitOrFail((int) $data['business_unit_id']);
        $type = CallTreeType::tryFrom((string) ($data['tree_type'] ?? '')) ?? CallTreeType::Department;

        try {
            $tree = $this->proposals->generate($unit, $type, $data['name'] ?? null, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['business_unit_id' => $e->getMessage()]);
        }

        return redirect()->route('bcms.call-trees.show', $tree)->with(
            'success',
            'Generated from the reporting chain as a draft. Check it before approving — the directory '
            .'says who reports to whom, not who rings whom at three in the morning.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Nodes */
    /* ------------------------------------------------------------------ */

    public function storeNode(Request $request, CallTree $call_tree): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $data = $request->validate([
            'contact_id' => ['required', 'integer'],
            'parent_node_id' => ['nullable', 'integer'],
            'role_label' => ['nullable', 'string', 'max:150'],
            'is_must_reach' => ['nullable', 'boolean'],
            'primary_channel' => ['nullable', 'string', 'in:'.implode(',', array_column(ChannelKey::cases(), 'value'))],
            'secondary_channel' => ['nullable', 'string', 'in:'.implode(',', array_column(ChannelKey::cases(), 'value'))],
            'expected_response_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->trees->assertEditable($call_tree);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['contact_id' => $e->getMessage()]);
        }

        $contact = $this->contactOrFail((int) $data['contact_id']);
        $parent = $this->nodeOrNull($call_tree, $data['parent_node_id'] ?? null);

        CallTreeNode::query()->create([
            'organization_id' => $call_tree->organization_id,
            'call_tree_id' => $call_tree->getKey(),
            'parent_node_id' => $parent?->getKey(),
            'tier' => $parent === null ? 0 : min(3, (int) $parent->tier + 1),
            'contact_id' => $contact->getKey(),
            'user_id' => $contact->user_id,
            'role_label' => $data['role_label'] ?? $contact->title,
            'is_must_reach' => (bool) ($data['is_must_reach'] ?? false),
            'primary_channel' => $data['primary_channel'] ?? null,
            'secondary_channel' => $data['secondary_channel'] ?? null,
            'expected_response_minutes' => $data['expected_response_minutes'] ?? 15,
            'sequence' => (int) CallTreeNode::query()
                ->where('call_tree_id', $call_tree->getKey())
                ->where('parent_node_id', $parent?->getKey())
                ->count(),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', $contact->full_name.' added to the tree.');
    }

    public function updateNode(Request $request, CallTree $call_tree, CallTreeNode $node): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $this->assertNodeBelongs($call_tree, $node);

        $data = $request->validate([
            'role_label' => ['nullable', 'string', 'max:150'],
            'is_must_reach' => ['nullable', 'boolean'],
            'primary_channel' => ['nullable', 'string'],
            'secondary_channel' => ['nullable', 'string'],
            'expected_response_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->trees->assertEditable($call_tree);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['role_label' => $e->getMessage()]);
        }

        $node->update($data);

        return back()->with('success', 'Node updated.');
    }

    public function destroyNode(Request $request, CallTree $call_tree, CallTreeNode $node): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $this->assertNodeBelongs($call_tree, $node);

        try {
            $this->trees->assertEditable($call_tree);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['node' => $e->getMessage()]);
        }

        $orphaned = $node->children()->count();

        if ($orphaned > 0) {
            throw ValidationException::withMessages([
                'node' => 'This node has '.$orphaned.' '.($orphaned === 1 ? 'person' : 'people')
                    .' below it. Re-parent them first — deleting this node would silently detach a branch.',
            ]);
        }

        $node->delete();

        return back()->with('success', 'Node removed.');
    }

    public function reparentNode(Request $request, CallTree $call_tree, CallTreeNode $node): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $this->assertNodeBelongs($call_tree, $node);

        $data = $request->validate(['parent_node_id' => ['nullable', 'integer']]);
        $parent = $this->nodeOrNull($call_tree, $data['parent_node_id'] ?? null);

        try {
            $this->remediation->reparent($node, $parent, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['parent_node_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Branch moved.');
    }

    public function assignDeputy(Request $request, CallTree $call_tree, CallTreeNode $node): RedirectResponse
    {
        Gate::authorize('bcms.calltree.manage');

        $this->assertNodeBelongs($call_tree, $node);

        $data = $request->validate(['deputy_contact_id' => ['required', 'integer']]);
        $deputy = $this->contactOrFail((int) $data['deputy_contact_id']);

        try {
            $this->remediation->assignDeputy($node, $deputy, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['deputy_contact_id' => $e->getMessage()]);
        }

        return back()->with('success', $deputy->full_name.' is now the deputy for this node.');
    }

    /** The designer's person picker. */
    public function candidates(Request $request, CallTree $call_tree): JsonResponse
    {
        Gate::authorize('bcms.calltree.view');

        return response()->json([
            'contacts' => $this->trees->candidateContacts($call_tree, $request->string('q')->toString())
                ->map(fn (Contact $c) => [
                    'id' => (int) $c->getKey(),
                    'name' => $c->full_name,
                    'title' => $c->title,
                    'has_mobile' => filled($c->mobile_primary),
                    'consent_withdrawn' => $c->consent_status === 'withdrawn',
                ])->all(),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => $user?->can('bcms.calltree.manage') === true,
            'test' => $user?->can('bcms.calltree.test') === true,
            'contacts' => $user?->can('bcms.contact.manage') === true,
            'findings' => $user?->can('bcms.finding.manage') === true,
        ];
    }

    private function assertNodeBelongs(CallTree $tree, CallTreeNode $node): void
    {
        abort_unless((int) $node->call_tree_id === (int) $tree->getKey(), 404);
    }

    private function nodeOrNull(CallTree $tree, mixed $id): ?CallTreeNode
    {
        if ($id === null || $id === '') {
            return null;
        }

        $node = CallTreeNode::query()
            ->where('call_tree_id', $tree->getKey())
            ->find((int) $id);

        if ($node === null) {
            throw ValidationException::withMessages([
                'parent_node_id' => 'That parent is not on this tree.',
            ]);
        }

        return $node;
    }

    /**
     * A tenant-scoped lookup rather than a bare `exists:` rule.
     *
     * `OrganizationScope` has already removed every other tenant's rows, so a
     * miss here is a genuine 404 rather than a cross-tenant read.
     */
    private function contactOrFail(int $id): Contact
    {
        $contact = Contact::query()->find($id);

        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => 'That person is not on the contact roster.']);
        }

        return $contact;
    }

    private function unitOrFail(int $id): BusinessUnit
    {
        $unit = BusinessUnit::query()->find($id);

        if ($unit === null) {
            throw ValidationException::withMessages(['business_unit_id' => 'That business unit does not exist.']);
        }

        return $unit;
    }

    private function assertUnitInTenant(mixed $id): void
    {
        if ($id !== null && $id !== '') {
            $this->unitOrFail((int) $id);
        }
    }
}
