<?php

namespace App\Services\LossEvents;

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\KriDepartmentContact;
use App\Models\LossEventReturn;
use App\Services\LossEvents\LossEventReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

class LossEventReturnController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private LossEventReturnService $returns,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = TenantContext::organizationId();

        $currentPeriod = $request->query('period', now()->format('Y-m'));

        // Ensure records exist for the selected period
        $allReturns = $this->returns->ensureReturnsForPeriod($orgId, $currentPeriod);

        // Build last 12 months for period selector
        $periods = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $periods[] = [
                'code' => $cursor->format('Y-m'),
                'label' => $cursor->format('F Y'),
            ];
            $cursor = $cursor->copy()->subMonth();
        }

        // Identify champion's department(s)
        $championUnitIds = KriDepartmentContact::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->pluck('business_unit_id')
            ->filter()
            ->all();

        if (empty($championUnitIds) && $user->business_unit_id) {
            $championUnitIds = [$user->business_unit_id];
        }

        $isChampionOnly = $user->hasRole(['risk-champion', 'risk-champions'])
            && ! $user->hasRole([
                'super-admin', 'chief-risk-officer', 'risk-manager', 'risk-owner',
                'risk-analyst', 'compliance-officer', 'loss-event-manager',
            ]);

        $myReturns = $allReturns->whereIn('business_unit_id', $championUnitIds)->values();
        $scopedReturns = $isChampionOnly ? $myReturns : $allReturns;

        // Stats
        $stats = [
            'total_departments' => $scopedReturns->count(),
            'submitted' => $scopedReturns->where('status', 'submitted')->count(),
            'loss_reported' => $scopedReturns->where('status', 'submitted')->where('has_loss', true)->count(),
            'nil_reported' => $scopedReturns->where('status', 'submitted')->where('has_loss', false)->count(),
            'pending' => $scopedReturns->where('status', 'pending')->count(),
        ];

        $canRemind = $user->can('loss_event.send_reminder')
            || $user->can('loss_event.approve')
            || $user->hasRole(['super-admin', 'risk-manager', 'chief-risk-officer', 'loss-event-manager']);

        return Inertia::render('LossEvents/Returns', [
            'returns' => $scopedReturns,
            'myReturns' => $myReturns,
            'periods' => $periods,
            'currentPeriod' => $currentPeriod,
            'currentPeriodLabel' => Carbon::createFromFormat('Y-m', $currentPeriod)->format('F Y'),
            'stats' => $stats,
            'isChampionOnly' => $isChampionOnly,
            'can' => [
                'create_event' => $user->can('loss_event.create'),
                'submit_return' => $user->can('loss_event.submit_return') || $user->can('loss_event.create'),
                'send_reminder' => $canRemind,
            ],
        ]);
    }

    public function submit(Request $request, LossEventReturn $return): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'has_loss' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->returns->submit($return, $user, $validated['has_loss'], $validated['comment'] ?? null);

        $unitName = $return->businessUnit?->name ?? 'Department';
        $statusMsg = $validated['has_loss']
            ? "Monthly loss return submitted for {$unitName} with loss event(s) recorded."
            : "Nil return ('no loss was recorded') submitted successfully for {$unitName}.";

        return back()->with('success', $statusMsg);
    }

    public function sendReminders(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'period_code' => ['nullable', 'string', 'max:20'],
            'business_unit_id' => ['nullable', 'exists:business_units,id'],
        ]);

        $periodCode = $validated['period_code'] ?: now()->format('Y-m');
        $orgId = TenantContext::organizationId();

        $result = $this->returns->sendReminders($orgId, $periodCode, $validated['business_unit_id'] ?? null, $user);

        return back()->with('success', "Reminders sent to {$result['reminded_departments']} pending department(s) ({$result['recipients_count']} notification(s) dispatched).");
    }
}
