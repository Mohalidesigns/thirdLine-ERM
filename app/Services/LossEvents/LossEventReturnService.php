<?php

namespace App\Services\LossEvents;

use App\Models\BusinessUnit;
use App\Models\KriDepartmentContact;
use App\Models\LossEvent;
use App\Models\LossEventReturn;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

class LossEventReturnService
{
    /**
     * Ensure each active department in the organization has a return record for the given month.
     *
     * @return Collection<int, LossEventReturn>
     */
    public function ensureReturnsForPeriod(int $orgId, string $periodCode): Collection
    {
        $startOfMonth = Carbon::createFromFormat('Y-m', $periodCode)->startOfMonth();
        $endOfMonth = (clone $startOfMonth)->endOfMonth();

        $units = BusinessUnit::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($units as $unit) {
            $existing = LossEventReturn::query()
                ->where('organization_id', $orgId)
                ->where('business_unit_id', $unit->id)
                ->where('period_code', $periodCode)
                ->first();

            if (! $existing) {
                // Check if any loss event was already logged for this unit in this month
                $lossEvents = LossEvent::query()
                    ->where('organization_id', $orgId)
                    ->where('business_unit_id', $unit->id)
                    ->whereBetween('date_of_loss', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                    ->get();

                $hasLoss = $lossEvents->isNotEmpty();

                LossEventReturn::create([
                    'organization_id' => $orgId,
                    'business_unit_id' => $unit->id,
                    'period_code' => $periodCode,
                    'status' => $hasLoss ? 'submitted' : 'pending',
                    'has_loss' => $hasLoss,
                    'comment' => $hasLoss ? 'Loss event(s) recorded on register' : null,
                    'submitted_by' => $hasLoss ? $lossEvents->first()->reporter_id ?? null : null,
                    'submitted_at' => $hasLoss ? $lossEvents->first()->created_at ?? now() : null,
                    'loss_events_count' => $lossEvents->count(),
                ]);
            }
        }

        return LossEventReturn::query()
            ->where('organization_id', $orgId)
            ->where('period_code', $periodCode)
            ->with(['businessUnit:id,name,code', 'submittedBy:id,name'])
            ->get();
    }

    /**
     * Submit a department's monthly loss return (with loss or nil declaration).
     */
    public function submit(LossEventReturn $return, User $user, bool $hasLoss, ?string $comment = null): LossEventReturn
    {
        $resolvedComment = trim((string) $comment);
        if ($resolvedComment === '') {
            $resolvedComment = $hasLoss ? 'Loss event recorded' : 'no loss was recorded';
        }

        $return->update([
            'status' => 'submitted',
            'has_loss' => $hasLoss,
            'comment' => $resolvedComment,
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);

        return $return->fresh(['businessUnit', 'submittedBy']);
    }

    /**
     * Automatically update the monthly return when a loss event is logged.
     */
    public function recordLossEventCreated(LossEvent $lossEvent): void
    {
        if (! $lossEvent->business_unit_id || ! $lossEvent->organization_id) {
            return;
        }

        $periodCode = Carbon::parse($lossEvent->date_of_loss ?? now())->format('Y-m');

        $return = LossEventReturn::firstOrCreate(
            [
                'organization_id' => $lossEvent->organization_id,
                'business_unit_id' => $lossEvent->business_unit_id,
                'period_code' => $periodCode,
            ],
            [
                'status' => 'pending',
                'has_loss' => false,
                'loss_events_count' => 0,
            ]
        );

        $return->update([
            'status' => 'submitted',
            'has_loss' => true,
            'loss_events_count' => $return->loss_events_count + 1,
            'comment' => "Loss event {$lossEvent->event_reference} recorded on register",
            'submitted_by' => $return->submitted_by ?: ($lossEvent->reporter_id ?: auth()->id()),
            'submitted_at' => $return->submitted_at ?: now(),
        ]);
    }

    /**
     * Send reminders to all pending departments for the given month.
     *
     * @return array{reminded_departments: int, recipients_count: int}
     */
    public function sendReminders(int $orgId, string $periodCode, ?int $onlyBuId = null, ?User $sender = null): array
    {
        $query = LossEventReturn::query()
            ->where('organization_id', $orgId)
            ->where('period_code', $periodCode)
            ->where('status', 'pending');

        if ($onlyBuId) {
            $query->where('business_unit_id', $onlyBuId);
        }

        $pendingReturns = $query->with('businessUnit')->get();

        $remindedDepts = 0;
        $totalRecipients = 0;

        $periodName = Carbon::createFromFormat('Y-m', $periodCode)->format('F Y');

        foreach ($pendingReturns as $return) {
            $unit = $return->businessUnit;
            if (! $unit) {
                continue;
            }

            // Find champions or contacts for this unit
            $contactUserIds = KriDepartmentContact::query()
                ->where('organization_id', $orgId)
                ->where('business_unit_id', $unit->id)
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->all();

            // Fallback to unit head / owner if no specific champion contact
            if (empty($contactUserIds) && $unit->manager_id) {
                $contactUserIds = [$unit->manager_id];
            }

            // If still empty, send to all champions in the tenant
            if (empty($contactUserIds)) {
                $contactUserIds = User::query()
                    ->where('organization_id', $orgId)
                    ->role(['risk-champion', 'risk-champions'])
                    ->pluck('id')
                    ->all();
            }

            $subject = "Reminder: Submit {$unit->name} Monthly Loss Return for {$periodName}";
            $body = "Please submit the monthly loss event return for {$unit->name} for {$periodName}. If no loss event occurred in your department during this month, you can submit your declaration with the comment 'no loss was recorded' so ORM can account for all departments.";
            $actionUrl = route('risk.loss-events.returns.index', ['period' => $periodCode]);

            foreach ($contactUserIds as $userId) {
                NotificationService::send(
                    $orgId,
                    (int) $userId,
                    'loss_event_return_reminder',
                    $subject,
                    $body,
                    [
                        'business_unit_id' => $unit->id,
                        'period_code' => $periodCode,
                        'return_id' => $return->id,
                    ],
                    $actionUrl,
                    'high',
                    'workflow'
                );
                $totalRecipients++;
            }

            $return->update(['reminded_at' => now()]);
            $remindedDepts++;
        }

        return [
            'reminded_departments' => $remindedDepts,
            'recipients_count' => $totalRecipients,
        ];
    }
}
