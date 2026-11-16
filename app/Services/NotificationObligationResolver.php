<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\IncidentNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns "something happened" into "who must be told, and by when".
 * Computes statutory notification windows (e.g. 72-hour NDPC breach notification,
 * CBN cyber and fraud incident notifications).
 */
class NotificationObligationResolver
{
    /**
     * Default notification templates per incident type.
     */
    public const DEFAULT_RULES = [
        'data_breach' => [
            [
                'regulator_name' => 'Nigeria Data Protection Commission (NDPC)',
                'regulator_code' => 'NDPC',
                'obligation_ref' => 'NDPA-S40-NOTIF',
                'obligation_title' => 'Personal Data Breach Notification to Commission',
                'legal_reference' => 'Nigeria Data Protection Act 2023, s. 40(2)',
                'offset_hours' => 72,
            ],
        ],
        'cyber' => [
            [
                'regulator_name' => 'Central Bank of Nigeria (CBN)',
                'regulator_code' => 'CBN',
                'obligation_ref' => 'CBN-CYBER-FRAMEWORK',
                'obligation_title' => 'Major Cyber Security Incident Notification',
                'legal_reference' => 'CBN Risk-Based Cybersecurity Framework §5.3',
                'offset_hours' => 72,
            ],
        ],
        'security' => [
            [
                'regulator_name' => 'Central Bank of Nigeria (CBN)',
                'regulator_code' => 'CBN',
                'obligation_ref' => 'CBN-SEC-ALERT',
                'obligation_title' => 'Critical Security Incident Alert',
                'legal_reference' => 'CBN ORMS Reporting Guidelines §4',
                'offset_hours' => 72,
            ],
        ],
        'fraud' => [
            [
                'regulator_name' => 'Central Bank of Nigeria (CBN)',
                'regulator_code' => 'CBN',
                'obligation_ref' => 'CBN-FRD-RETURNS',
                'obligation_title' => 'Attempted and Consolidated Fraud Reporting',
                'legal_reference' => 'CBN Circular on Electronic Fraud & ORMS §6',
                'offset_hours' => 48,
            ],
        ],
    ];

    /**
     * Materialise this incident's notification duties, idempotently.
     */
    public function resolve(Incident $incident): Collection
    {
        $eventAt = $incident->notificationEventAt() ?? now();
        $rules = self::DEFAULT_RULES[$incident->incident_type] ?? [];

        // If explicitly flagged reportable without standard type, generate a general regulator notification
        if ($incident->is_reportable && $rules === []) {
            $rules = [
                [
                    'regulator_name' => 'Central Bank of Nigeria (CBN)',
                    'regulator_code' => 'CBN',
                    'obligation_ref' => 'CBN-ORMS-INCIDENT',
                    'obligation_title' => 'Material Operational Risk Incident Notification',
                    'legal_reference' => 'CBN ORMS Guidelines §3.2',
                    'offset_hours' => 72,
                ],
            ];
        }

        $existing = $incident->notifications()->get()->keyBy('obligation_ref');
        $resolved = collect();

        foreach ($rules as $rule) {
            $ref = $rule['obligation_ref'];
            $dueAt = (clone $eventAt)->addHours($rule['offset_hours']);

            if ($existing->has($ref)) {
                $notification = $existing->get($ref);
                if ($notification->status === 'Pending') {
                    $notification->update(['due_at' => $dueAt]);
                }
                $resolved->push($notification);
            } else {
                $notification = $incident->notifications()->create([
                    'organization_id' => $incident->organization_id,
                    'regulator_name' => $rule['regulator_name'],
                    'regulator_code' => $rule['regulator_code'],
                    'obligation_ref' => $rule['obligation_ref'],
                    'obligation_title' => $rule['obligation_title'],
                    'legal_reference' => $rule['legal_reference'],
                    'due_at' => $dueAt,
                    'status' => 'Pending',
                    'is_required' => true,
                    'verification_status' => 'verified',
                ]);
                $resolved->push($notification);
            }
        }

        // If incident became reportable, update notification_due_at on incident to earliest due
        $earliestDue = $resolved->where('status', 'Pending')->min('due_at');
        if ($earliestDue) {
            $incident->updateQuietly([
                'is_reportable' => true,
                'notification_due_at' => $earliestDue,
            ]);
        }

        return $resolved;
    }
}
