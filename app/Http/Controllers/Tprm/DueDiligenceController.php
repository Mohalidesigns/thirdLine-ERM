<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\DueDiligenceChecklist;
use App\Models\Tprm\DueDiligenceItem;
use App\Models\Tprm\Engagement;
use App\Services\Tprm\DueDiligence\DueDiligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The due diligence checklist — FR-DDL-02, FR-DDL-08, FR-DDL-09.
 *
 * THE BLOCKERS ARE NAMED ON THE PAGE, not discovered on submit. A screen with
 * a disabled "complete" button and a tooltip teaches a user to look for
 * whoever can override it; a panel listing the four mandatory items still open,
 * each with its own close-or-waive action, teaches them what to do.
 */
class DueDiligenceController extends Controller
{
    public function __construct(private readonly DueDiligenceService $service) {}

    public function show(Request $request, Engagement $engagement)
    {
        Gate::authorize('view', $engagement);

        $checklist = DueDiligenceChecklist::query()
            ->where('engagement_id', $engagement->getKey())
            ->with(['items.owner:id,name', 'items.evidence:id,uuid,title', 'items.waiverApprover:id,name'])
            ->latest('id')
            ->first();

        return Inertia::render('Tprm/DueDiligence/Show', [
            'engagement' => [
                'id' => $engagement->getKey(),
                'reference' => $engagement->reference,
                'name' => $engagement->name,
                'tier' => $engagement->effectiveTier()?->value,
                'tier_label' => $engagement->effectiveTier()?->label(),
                'url' => route('tprm.engagements.show', $engagement),
            ],
            'checklist' => $checklist === null ? null : [
                'id' => $checklist->getKey(),
                'status' => $checklist->status,
                'template_code' => $checklist->template_code,
                // The tier the checklist was scoped against, which may differ
                // from the engagement's tier today — and saying so is the
                // point, because "we did the due diligence the tier required"
                // is a claim about the tier in force at the time.
                'tier_at_generation' => $checklist->tier_at_generation,
                'completed_at' => $checklist->completed_at?->toDateString(),
                'progress' => $checklist->progress(),
                'can_complete' => $checklist->canComplete(),
                'items' => $checklist->items->map(fn (DueDiligenceItem $item) => [
                    'id' => $item->getKey(),
                    'code' => $item->code,
                    'title' => $item->title,
                    'category' => $item->category,
                    'item_type' => $item->item_type,
                    'is_mandatory' => $item->is_mandatory,
                    'status' => $item->status,
                    'settled' => $item->isSettled(),
                    'owner' => $item->owner?->name,
                    'due_date' => $item->due_date?->toDateString(),
                    'evidence' => $item->evidence === null ? null : [
                        'uuid' => $item->evidence->uuid,
                        'title' => $item->evidence->title,
                    ],
                    'waiver' => $item->status === DueDiligenceItem::STATUS_WAIVED ? [
                        'reason' => $item->waiver_reason,
                        'approver' => $item->waiverApprover?->name,
                        'expires_at' => $item->waiver_expires_at?->toDateString(),
                        // A lapsed waiver blocks again, and the screen has to
                        // say so — otherwise it reads as settled.
                        'lapsed' => $item->waiverHasLapsed(),
                    ] : null,
                ])->values(),
            ],
            'can' => [
                'manage' => $request->user()->can('tprm.edit'),
                'waive' => $request->user()->can('tprm.waiver.approve'),
            ],
        ]);
    }

    public function generate(Request $request, Engagement $engagement)
    {
        Gate::authorize('update', $engagement);

        $checklist = $this->service->generate($engagement, $request->user()->id);

        return back()->with('success', sprintf(
            'The checklist for a %s engagement has %d item(s), %d of them mandatory.',
            $checklist->tier_at_generation ?? 'untiered',
            $checklist->progress()['total'],
            $checklist->progress()['mandatory'],
        ));
    }

    public function completeItem(Request $request, DueDiligenceItem $item)
    {
        Gate::authorize('tprm.edit');

        $validated = $request->validate(['evidence_document_id' => 'nullable|integer']);

        $result = $this->service->complete(
            $item,
            $validated['evidence_document_id'] ?? null,
            $request->user()->id,
        );

        return $result['completed']
            ? back()->with('success', 'Recorded.')
            : back()->with('error', $result['reason']);
    }

    public function waiveItem(Request $request, DueDiligenceItem $item)
    {
        Gate::authorize('tprm.waiver.approve');

        $validated = $request->validate([
            'reason' => 'required|string|min:20|max:2000',
            'expires_at' => 'required|date|after:today',
        ]);

        $result = $this->service->waive(
            $item,
            $validated['reason'],
            $request->user()->id,
            Carbon::parse($validated['expires_at']),
            $request->user()->id,
        );

        return $result['waived']
            ? back()->with('success', 'Waived. It reopens by itself when the waiver expires.')
            : back()->with('error', $result['reason']);
    }

    public function complete(Request $request, DueDiligenceChecklist $checklist)
    {
        Gate::authorize('tprm.edit');

        $result = $this->service->completeChecklist($checklist, $request->user()->id);

        return $result['completed']
            ? back()->with('success', 'Due diligence completed.')
            : back()->with('error', $result['reason']);
    }
}
