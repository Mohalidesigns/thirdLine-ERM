<?php

namespace App\Services\Tprm\Access;

use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Connection;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Waiver;
use Illuminate\Support\Collection;

/**
 * FR-ACC-04 and AC-10: an engagement cannot reach `terminated` while anything
 * is still plugged in.
 *
 * THIS IS THE CONTROL THE WHOLE ACCESS REGISTER EXISTS FOR. Every regulator in
 * scope asks a version of the same question — CBN Cyber Appendix III §1.3, DORA
 * Art. 28(8), the OCC's own "terminate access" language — and every bank
 * answers it with a spreadsheet somebody maintains by hand. Making the status
 * transition itself refuse is the difference between a policy and a control.
 *
 * The guard is DELIBERATELY BLIND TO THE CONNECTION'S TYPE. A physical-media
 * connection whose courier stopped calling years ago still blocks, because the
 * register cannot tell the difference between a path that lapsed and a path
 * nobody has looked at. Closing it takes one click and a sentence of evidence;
 * guessing on the bank's behalf takes one incident.
 *
 * An approved waiver against the specific connection or grant releases it —
 * FR-ACC-04's own escape hatch. The waiver is named in the verdict rather than
 * making the obstacle disappear, so the offboarding pack shows what was
 * accepted and by whom.
 */
class TerminationGuard
{
    public function check(Engagement $engagement): TerminationVerdict
    {
        $waived = $this->waivedSubjects($engagement);

        /** @var Collection<int, Connection> $connections */
        $connections = Connection::query()
            ->where('engagement_id', $engagement->getKey())
            ->open()
            ->orderBy('name')
            ->get();

        /** @var Collection<int, AccessGrant> $grants */
        $grants = AccessGrant::query()
            ->where('engagement_id', $engagement->getKey())
            ->live()
            ->orderBy('grantee_name')
            ->get();

        $blockers = [];
        $excepted = [];

        foreach ($connections as $connection) {
            $row = [
                'kind' => 'connection',
                'id' => $connection->getKey(),
                'label' => sprintf('%s (%s)', $connection->name, $connection->type->label()),
                'detail' => $connection->endpoint ?? $connection->type->label(),
                'status' => $connection->status->value,
                'action' => 'Close the connection and attach the evidence: '
                    .$connection->type->closureEvidenceHint(),
            ];

            if (in_array($connection->getKey(), $waived['connection'], true)) {
                $excepted[] = $row + ['excepted' => true];

                continue;
            }

            $blockers[] = $row;
        }

        foreach ($grants as $grant) {
            $row = [
                'kind' => 'access_grant',
                'id' => $grant->getKey(),
                'label' => sprintf('%s — %s', $grant->grantee_name, $grant->system_name),
                'detail' => sprintf(
                    '%s access%s',
                    $grant->access_level->label(),
                    $grant->isOverdue() ? ', expired and not revoked' : '',
                ),
                'status' => $grant->status->value,
                'action' => 'Revoke the grant and attach evidence that the account was disabled.',
            ];

            if (in_array($grant->getKey(), $waived['access_grant'], true)) {
                $excepted[] = $row + ['excepted' => true];

                continue;
            }

            $blockers[] = $row;
        }

        if ($blockers === []) {
            return TerminationVerdict::allowed($excepted);
        }

        return TerminationVerdict::refused($this->reason($blockers), $blockers, $excepted);
    }

    /**
     * The message a person reads, naming what is open.
     *
     * It NAMES THE OBSTACLES rather than counting them. "2 items are still
     * open" sends somebody hunting through a tab; "Yasin Bello still has
     * Administrative access to Core Banking" tells them who to call.
     *
     * @param  list<array<string, mixed>>  $blockers
     */
    private function reason(array $blockers): string
    {
        $named = array_slice(array_map(
            static fn (array $b): string => (string) $b['label'],
            $blockers,
        ), 0, 3);

        $suffix = count($blockers) > 3
            ? sprintf(' and %d more', count($blockers) - 3)
            : '';

        return sprintf(
            'This engagement cannot be terminated while %s still %s open: %s%s. Close each connection and revoke '
            .'each grant with evidence, or record an approved access exception (FR-ACC-04).',
            count($blockers) === 1 ? 'one path is' : count($blockers).' paths are',
            count($blockers) === 1 ? 'stands' : 'stand',
            implode('; ', $named),
            $suffix,
        );
    }

    /**
     * Connection and grant ids released by an approved, in-force waiver.
     *
     * @return array{connection: list<int>, access_grant: list<int>}
     */
    private function waivedSubjects(Engagement $engagement): array
    {
        $waivers = Waiver::query()
            ->whereIn('waivable_type', [Waiver::TYPE_CONNECTION_EXCEPTION, Waiver::TYPE_ACCESS_EXCEPTION])
            ->where('engagement_id', $engagement->getKey())
            ->inForce()
            ->get();

        return [
            'connection' => $waivers
                ->where('waivable_type', Waiver::TYPE_CONNECTION_EXCEPTION)
                ->map(static fn (Waiver $w): int => (int) $w->waivable_id)
                ->values()->all(),
            'access_grant' => $waivers
                ->where('waivable_type', Waiver::TYPE_ACCESS_EXCEPTION)
                ->map(static fn (Waiver $w): int => (int) $w->waivable_id)
                ->values()->all(),
        ];
    }
}
