<?php

namespace App\Services\Bcms\Emns;

use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\NotificationDelivery;
use Illuminate\Support\Collection;

/**
 * The per-recipient, per-channel record an examiner asks for after a real event
 * (criterion 11).
 *
 * THIS IS THE ARTEFACT THE WHOLE MODULE IS JUDGED ON AFTER AN INCIDENT. Not the
 * dashboard, not the funnel: a bank that evacuated a branch will be asked, months
 * later, to show who was told, on what, at what time, and what came back. Every
 * row here answers that for one person on one channel.
 *
 * IT IS BUILT FROM THE STORED RECORD AND COMPUTES NOTHING. Every timestamp comes
 * from the delivery or recipient row as written at the time. An export that
 * recalculated anything would drift as the roster changed, and an evidence pack
 * that disagrees with last month's copy of itself is worse than none.
 *
 * FAILURES ARE EXPORTED AS PROMINENTLY AS SUCCESSES. The temptation with an
 * evidence pack is to show what worked; what an examiner is actually testing is
 * whether the bank knows what did not. A wrong number in this file is the bank
 * demonstrating control, not admitting fault.
 *
 * IT IS IMMUTABLE BY CONSTRUCTION, not by a flag: nothing in the module updates
 * a delivery row after its terminal status, and the inbound handler refuses to
 * move one backwards.
 */
class EvidenceExport
{
    /** @return list<string> */
    public function columns(): array
    {
        return [
            'Alert reference', 'Alert title', 'Severity', 'Simulation', 'Dispatched at (UTC)',
            'Recipient', 'Department', 'Site', 'Channel', 'Address', 'Provider',
            'Provider message id', 'Status', 'Attempts', 'Queued at (UTC)', 'Sent at (UTC)',
            'Delivered at (UTC)', 'Read at (UTC)', 'Failure reason',
            'Acknowledged at (UTC)', 'Response', 'Response text',
            'Escalated at (UTC)', 'Escalated to', 'Cost (minor units)', 'Currency',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function rows(Alert $alert): array
    {
        /** @var Collection<int, AlertRecipient> $recipients */
        $recipients = AlertRecipient::query()
            ->where('alert_id', $alert->getKey())
            ->with(['contact:id,full_name,business_unit_id,site_id', 'contact.businessUnit:id,name',
                'contact.site:id,name', 'escalatedTo:id,full_name'])
            ->get()
            ->keyBy('contact_id');

        /** @var Collection<int, NotificationDelivery> $deliveries */
        $deliveries = NotificationDelivery::query()
            ->where('alert_id', $alert->getKey())
            ->orderBy('recipient_contact_id')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($deliveries as $delivery) {
            $recipient = $recipients->get($delivery->recipient_contact_id);
            $rows[] = $this->row($alert, $recipient, $delivery);
        }

        /*
         * A PERSON WITH NO DELIVERY STILL GETS A ROW. Somebody whose contact
         * record had no usable channel was never sent anything, and leaving
         * them out of the export would make the pack say the bank reached
         * everybody it tried to reach — which is true and completely
         * misleading. Their row names the gap.
         */
        $covered = $deliveries->pluck('recipient_contact_id')->filter()->unique();

        foreach ($recipients as $contactId => $recipient) {
            if ($covered->contains($contactId)) {
                continue;
            }

            $rows[] = $this->row($alert, $recipient, null);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function row(Alert $alert, ?AlertRecipient $recipient, ?NotificationDelivery $delivery): array
    {
        // Locals, not a chain of `?->x ?? ''`. Both arguments are genuinely
        // nullable — a recipient with no delivery, a delivery whose recipient
        // row was removed — and static analysis types the relations behind them
        // as non-null, so the nullsafe chain reads as redundant and invites
        // somebody to delete the check that is actually load-bearing.
        $contact = $recipient === null ? null : $recipient->contact;
        $unit = $contact === null ? null : $contact->businessUnit;
        $site = $contact === null ? null : $contact->site;
        $escalatedTo = $recipient === null ? null : $recipient->escalatedTo;

        $status = match (true) {
            $delivery !== null => $delivery->status->value,
            $recipient !== null => $recipient->status->value,
            default => '',
        };

        return array_map('strval', [
            $alert->uuid,
            $alert->title,
            $alert->severity->value,
            $alert->is_simulation ? 'YES — simulation, nothing was dispatched externally' : 'no',
            $alert->dispatched_at?->toIso8601String() ?? '',

            $recipient === null ? '' : (string) $recipient->contact_name_snapshot,
            $unit === null ? '' : (string) $unit->name,
            $site === null ? '' : (string) $site->name,

            $delivery === null ? 'none' : $delivery->channel->value,
            $delivery === null ? '' : (string) $delivery->address,
            $delivery === null ? '' : (string) $delivery->provider,
            $delivery === null ? '' : (string) $delivery->provider_message_id,
            $status,
            $delivery === null ? '0' : (string) $delivery->attempts,

            $delivery?->created_at?->toIso8601String() ?? '',
            $delivery?->sent_at?->toIso8601String() ?? '',
            $delivery?->delivered_at?->toIso8601String() ?? '',
            $delivery?->read_at?->toIso8601String() ?? '',
            $delivery === null
                ? 'No usable channel for this contact; nothing was attempted.'
                : (string) $delivery->failed_reason,

            $recipient?->acknowledged_at?->toIso8601String() ?? '',
            $recipient === null ? '' : (string) $recipient->response_value,
            $recipient === null ? '' : (string) $recipient->response_text,
            $recipient?->escalated_at?->toIso8601String() ?? '',
            $escalatedTo === null ? '' : (string) $escalatedTo->full_name,

            // Blank, not 0. A channel that does not report cost has not told us
            // it was free.
            $delivery === null || $delivery->cost_minor === null ? '' : (string) $delivery->cost_minor,
            $delivery === null ? '' : (string) $delivery->currency,
        ]);
    }

    public function filename(Alert $alert): string
    {
        return 'bcms-alert-'.$alert->uuid.'-evidence.csv';
    }

    /**
     * The header block an examiner reads before the table — what this pack is,
     * and what it does not contain.
     *
     * @return list<list<string>>
     */
    public function preamble(Alert $alert): array
    {
        return [
            ['Business continuity — emergency notification evidence'],
            ['ISO 22301 clause', (string) ($alert->iso_clause_ref ?? '8.4.3')],
            ['Alert', (string) $alert->title],
            ['Reference', (string) $alert->uuid],
            ['Dispatched', $alert->dispatched_at?->toIso8601String() ?? 'not dispatched'],
            ['Recipients', (string) $alert->recipient_count],
            ['Exported', now()->toIso8601String()],
            ['Timestamps', 'All times are UTC.'],
            ['Note', 'One row per recipient per channel. A recipient with no reachable channel appears '
                .'once with the reason. Delivery and read timestamps are present only where the gateway '
                .'reports them — email, Teams and Slack do not.'],
            [],
        ];
    }
}
