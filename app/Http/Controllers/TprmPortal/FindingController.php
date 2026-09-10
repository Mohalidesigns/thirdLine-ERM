<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Models\Tprm\AssessmentMessage;
use App\Models\Tprm\Finding;
use App\Models\Tprm\PortalUser;
use App\Services\Tprm\Portal\PortalMessageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * The vendor's view of findings raised against it — FR-PRT-08.
 *
 * THE VENDOR CANNOT CLOSE A FINDING, AND THAT IS THE POINT OF THE SCREEN
 * EXISTING AT ALL. It can say what it has done and attach what it has; the
 * client verifies and closes. A portal that let a vendor mark its own finding
 * remediated would produce a register in which every finding is closed and
 * none is fixed.
 *
 * It shows only what the vendor is entitled to see: the finding, its severity,
 * its due date and the thread. Not the client's internal risk acceptance, not
 * the residual score arithmetic, not who at the bank owns it.
 */
class FindingController extends Controller
{
    public function __construct(private readonly PortalMessageService $messages) {}

    public function index(Request $request)
    {
        $user = $this->user($request);

        return Inertia::render('TprmPortal/Findings/Index', [
            'findings' => Finding::query()
                ->where('third_party_id', $user->third_party_id)
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->orderBy('target_date')
                ->get()
                ->map(fn (Finding $finding): array => [
                    'uuid' => $finding->uuid,
                    'reference' => $finding->reference,
                    'title' => $finding->title,
                    'severity' => $finding->severity->value,
                    'severity_label' => $finding->severity->label(),
                    'status' => $finding->status->value,
                    'status_label' => $finding->status->label(),
                    'target_date' => $finding->target_date?->toDateString(),
                    'overdue' => $finding->target_date?->isPast() && $finding->status->isOpen(),
                ])->values(),
        ]);
    }

    public function show(Request $request, Finding $finding)
    {
        $user = $this->user($request);

        // 404, not 403: a vendor probing references should not learn that a
        // finding with that id exists against somebody else.
        abort_unless($user->actsFor((int) $finding->third_party_id), 404);

        $this->messages->markRead(AssessmentMessage::AUTHOR_VENDOR, null, $finding);

        return Inertia::render('TprmPortal/Findings/Show', [
            'finding' => [
                'uuid' => $finding->uuid,
                'reference' => $finding->reference,
                'title' => $finding->title,
                'description' => $finding->description,
                'severity' => $finding->severity->value,
                'severity_label' => $finding->severity->label(),
                'status' => $finding->status->value,
                'status_label' => $finding->status->label(),
                'target_date' => $finding->target_date?->toDateString(),
                'regulatory_citation' => $finding->regulatory_citation,
            ],
            'thread' => $this->messages->thread(null, $finding)
                ->map(fn (AssessmentMessage $m): array => [
                    'id' => $m->getKey(),
                    'from_vendor' => $m->isFromVendor(),
                    'body' => $m->body,
                    'at' => $m->created_at?->toDayDateTimeString(),
                ])->values(),
        ]);
    }

    public function postMessage(Request $request, Finding $finding)
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        try {
            $this->messages->postFromVendor($this->user($request), $validated['body'], null, $finding);
        } catch (InvalidArgumentException $exception) {
            abort(404);
        }

        return back();
    }

    private function user(Request $request): PortalUser
    {
        /** @var PortalUser $user */
        $user = $request->user('tprm-portal');

        return $user;
    }
}
