<?php

namespace App\Services\Tprm\Access;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\AccessLevel;
use App\Enums\Tprm\ConnectionStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Connection;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Engagement;
use App\Services\Tprm\Findings\FindingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The connection and access-grant register — FR-ACC-01 through FR-ACC-05.
 *
 * CLOSURE AND REVOCATION BOTH REQUIRE EVIDENCE, AND THAT IS THE ENTIRE POINT
 * OF PUTTING THEM BEHIND A SERVICE. A status column anybody can set to
 * `closed` records an intention; the examiner's question is whether the tunnel
 * is gone, and only the attached document answers it. Every competitor product
 * that has this register has it as a dropdown.
 *
 * The one exception is a connection that was never opened. A `requested` or
 * `approved` connection has no path to tear down and no evidence to give, so
 * it closes on a reason instead — see `close()`.
 */
class AccessService
{
    /**
     * The engagement states that should not have live access against them.
     *
     * FR-ACC-03 words this as "terminated, suspended or expired". This
     * lifecycle has no `suspended` and no `expired` — a suspension here is a
     * `monitoring_exception` and an expiry is a property of the CONTRACT, not
     * of the engagement — so the report reads the two states that really mean
     * the relationship is over, and picks up expiry from the contract date
     * separately. Mapping the spec's three words onto three invented statuses
     * would have produced a report that was always empty in two of its
     * sections.
     *
     * @var list<string>
     */
    public const DISCONTINUED_STATUSES = [
        'terminated',
        'archived',
    ];

    public function __construct(private readonly FindingService $findings) {}

    /* ------------------------------------------------------------------ */
    /*  Connections — FR-ACC-01 */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordConnection(Engagement $engagement, array $attributes, ?int $userId = null): Connection
    {
        return Connection::create([
            'organization_id' => $engagement->organization_id,
            'engagement_id' => $engagement->getKey(),
            'created_by' => $userId,
        ] + $attributes);
    }

    public function approveConnection(Connection $connection, int $approverId): Connection
    {
        $connection->forceFill([
            'status' => ConnectionStatus::Approved->value,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ])->save();

        return $connection;
    }

    public function activateConnection(Connection $connection): Connection
    {
        if ($connection->approved_at === null) {
            throw new InvalidArgumentException(
                'A connection cannot be activated before it is approved. The approval is what an examiner asks '
                .'for when they find a live path into the network (FR-ACC-01).'
            );
        }

        $connection->forceFill(['status' => ConnectionStatus::Active->value])->save();

        return $connection;
    }

    /**
     * Close a connection — the half of AC-10 that concerns paths.
     *
     * A connection that reached `active` needs a closure evidence document. One
     * that never did needs a reason, because there is nothing to evidence and
     * demanding a document would teach people to attach an empty file.
     *
     * @throws \InvalidArgumentException
     */
    public function close(
        Connection $connection,
        ?int $evidenceDocumentId = null,
        ?string $reason = null,
        ?int $userId = null,
    ): Connection {
        $wasOpened = in_array($connection->status, [ConnectionStatus::Active, ConnectionStatus::Suspended], true);

        if ($wasOpened && $evidenceDocumentId === null) {
            throw new InvalidArgumentException(sprintf(
                'Closing "%s" needs evidence that the path is gone. %s',
                $connection->name,
                $connection->type->closureEvidenceHint(),
            ));
        }

        if (! $wasOpened && $evidenceDocumentId === null && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException(
                'A connection that was never activated closes on a reason — say why it is being withdrawn.'
            );
        }

        $connection->forceFill([
            'status' => ConnectionStatus::Closed->value,
            'closed_at' => now(),
            'closure_evidence_document_id' => $evidenceDocumentId,
        ])->save();

        /*
         * The reason goes to the audit log rather than to a column. There is
         * no `closure_reason` on the table, and the honest choice between
         * adding one and dropping the text is neither: the append-only log is
         * where a withdrawal that produced no artefact belongs, next to the
         * status change it explains.
         */
        if ($reason !== null && trim($reason) !== '') {
            AuditLog::create([
                'organization_id' => $connection->organization_id,
                'auditable_type' => Connection::class,
                'auditable_id' => $connection->getKey(),
                'event' => 'connection_closed_without_evidence',
                'actor_type' => $userId !== null ? 'user' : 'system',
                'actor_id' => $userId,
                'before' => null,
                'after' => ['reason' => $reason, 'name' => $connection->name],
            ]);
        }

        return $connection;
    }

    /* ------------------------------------------------------------------ */
    /*  Access grants — FR-ACC-02 */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function grantAccess(Engagement $engagement, array $attributes, ?int $userId = null): AccessGrant
    {
        return AccessGrant::create([
            'organization_id' => $engagement->organization_id,
            'engagement_id' => $engagement->getKey(),
            'created_by' => $userId,
        ] + $attributes);
    }

    /**
     * Senior-management approval — CBN Cyber Appendix III §1.3.
     *
     * The framework's word is "senior management" and this method does not
     * try to enforce that from a role name. Which titles count is a policy
     * decision per bank, the permission that reaches this method is where it
     * belongs, and a hardcoded list here would be wrong in every second tenant.
     * What the module DOES guarantee is that somebody is named and dated.
     */
    public function approveGrant(AccessGrant $grant, int $approverId): AccessGrant
    {
        $grant->forceFill([
            'status' => AccessGrantStatus::Active->value,
            'approver_id' => $approverId,
            'approved_at' => now(),
        ])->save();

        return $grant;
    }

    /**
     * Revoke a grant — the half of AC-10 that concerns people.
     *
     * Evidence is required with NO exception for grants that never activated,
     * unlike a connection. A requested-but-unapproved grant is the one case
     * where nothing was ever issued, and the honest action there is to delete
     * the request rather than to record a revocation that did not happen.
     *
     * @throws \InvalidArgumentException
     */
    public function revokeGrant(AccessGrant $grant, int $evidenceDocumentId, ?int $userId = null): AccessGrant
    {
        if ($grant->status === AccessGrantStatus::Revoked) {
            return $grant;
        }

        $grant->forceFill([
            'status' => AccessGrantStatus::Revoked->value,
            'revoked_at' => now(),
            'revoked_by' => $userId,
            'revocation_evidence_document_id' => $evidenceDocumentId,
        ])->save();

        return $grant;
    }

    /* ------------------------------------------------------------------ */
    /*  FR-ACC-05: expiry sweep */
    /* ------------------------------------------------------------------ */

    /**
     * Mark grants past their valid-to date and raise the Critical finding.
     *
     * THE FINDING IS THE POINT, NOT THE STATUS. A grant quietly flipping to
     * `expired` in a table nobody opens changes nothing; FR-ACC-05 calls an
     * expired-but-active grant Critical because the credential is still on the
     * vendor's laptop. The severity does not vary with access level — a
     * read-only login somebody forgot about is exactly how the interesting
     * breaches start.
     *
     * @return array{expired: int, findings: int}
     */
    public function expireDueGrants(?Carbon $asOf = null, ?int $userId = null): array
    {
        $asOf ??= Carbon::now();

        /** @var Collection<int, AccessGrant> $due */
        $due = AccessGrant::query()
            ->overdue($asOf)
            ->with('engagement')
            ->get();

        $expired = 0;
        $raised = 0;

        foreach ($due as $grant) {
            $engagement = $grant->engagement;

            if ($engagement === null) {
                continue;
            }

            if ($grant->status !== AccessGrantStatus::Expired) {
                $grant->forceFill(['status' => AccessGrantStatus::Expired->value])->save();
                $expired++;
            }

            $finding = $this->findings->raise(
                $engagement,
                'monitoring',
                FindingSeverity::Critical,
                sprintf('Expired access still live: %s on %s', $grant->grantee_name, $grant->system_name),
                [
                    'source_id' => $grant->getKey(),
                    'description' => sprintf(
                        '%s holds %s access to %s. The grant expired on %s and has not been revoked, so the '
                        ."credential should be assumed to still work.\n\nClose this by revoking the grant with "
                        .'evidence that the account was disabled (FR-ACC-05).',
                        $grant->grantee_name,
                        strtolower($grant->access_level->label()),
                        $grant->system_name,
                        $grant->valid_to?->toFormattedDateString() ?? 'an unrecorded date',
                    ),
                    'regulatory_citation' => 'CBN Risk-Based Cybersecurity Framework, Appendix III §1.3',
                ],
                $userId,
            );

            if ($finding->wasRecentlyCreated) {
                $raised++;
            }
        }

        return ['expired' => $expired, 'findings' => $raised];
    }

    /* ------------------------------------------------------------------ */
    /*  FR-ACC-03: the reconciliation report */
    /* ------------------------------------------------------------------ */

    /**
     * Live access against engagements that should not have any.
     *
     * THIS IS "REVOKE THE CREDENTIALS OF DISCONTINUED PROVIDERS" MADE
     * TESTABLE, and it is the report an examiner will ask for by name. Three
     * populations, kept separate because they are three different failures:
     *
     *   `discontinued`  the engagement is terminated or archived and access is
     *                   still live — the control has failed
     *   `expired_contract`  the engagement is nominally live but every contract
     *                   behind it has run out, which is the same exposure
     *                   wearing a healthier status
     *   `overdue`       the grant's own end date has passed — the clock ran out
     *   `open_ended`    a live grant with no end date at all, which is worse
     *                   than overdue because no clock was ever set
     *
     * Rolling them together would produce one long list where the genuinely
     * alarming rows sit below the merely untidy ones.
     *
     * @return array{
     *     discontinued: list<array<string, mixed>>,
     *     expired_contract: list<array<string, mixed>>,
     *     overdue: list<array<string, mixed>>,
     *     open_ended: list<array<string, mixed>>,
     *     open_connections: list<array<string, mixed>>,
     *     generated_at: string,
     * }
     */
    public function reconciliation(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $dead = self::DISCONTINUED_STATUSES;

        /** @var Collection<int, AccessGrant> $grants */
        $grants = AccessGrant::query()
            ->live()
            ->with(['engagement.thirdParty'])
            ->get();

        $lapsed = $this->engagementsWithNoLiveContract(
            $grants->pluck('engagement_id')->unique()->filter()->all(),
            $asOf,
        );

        $discontinued = [];
        $expiredContract = [];
        $overdue = [];
        $openEnded = [];

        foreach ($grants as $grant) {
            $engagement = $grant->engagement;

            if ($engagement !== null && in_array($engagement->status->value, $dead, true)) {
                $discontinued[] = $this->grantRow($grant) + ['engagement_status' => $engagement->status->value];

                continue;
            }

            if (in_array((int) $grant->engagement_id, $lapsed, true)) {
                $expiredContract[] = $this->grantRow($grant) + [
                    'engagement_status' => $engagement?->status->value,
                ];

                continue;
            }

            if ($grant->isOverdue($asOf)) {
                $overdue[] = $this->grantRow($grant);

                continue;
            }

            if ($grant->isOpenEnded()) {
                $openEnded[] = $this->grantRow($grant);
            }
        }

        /** @var Collection<int, Connection> $connections */
        $connections = Connection::query()
            ->open()
            ->whereHas('engagement', fn ($q) => $q->whereIn('status', $dead))
            ->with(['engagement.thirdParty'])
            ->get();

        return [
            'discontinued' => $discontinued,
            'expired_contract' => $expiredContract,
            'overdue' => $overdue,
            'open_ended' => $openEnded,
            'open_connections' => $connections->map(fn (Connection $c): array => [
                'id' => $c->getKey(),
                'name' => $c->name,
                'type' => $c->type->label(),
                'status' => $c->status->value,
                'endpoint' => $c->endpoint,
                'engagement_uuid' => $c->engagement?->uuid,
                'engagement_name' => $c->engagement?->name,
                'engagement_status' => $c->engagement?->status->value,
                'third_party' => $c->engagement?->thirdParty?->legal_name,
            ])->values()->all(),
            'generated_at' => $asOf->toIso8601String(),
        ];
    }

    /**
     * Of the given engagements, those with no contract still in force.
     *
     * An engagement with NO contract at all is not lapsed — it is a different
     * problem, which the activation guard already refuses at a point where
     * somebody can act on it. This asks only whether a contract that existed
     * has run out.
     *
     * @param  list<int>  $engagementIds
     * @return list<int>
     */
    private function engagementsWithNoLiveContract(array $engagementIds, Carbon $asOf): array
    {
        if ($engagementIds === []) {
            return [];
        }

        $withContracts = Contract::query()
            ->whereIn('engagement_id', $engagementIds)
            ->pluck('engagement_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();

        $withLiveContract = Contract::query()
            ->whereIn('engagement_id', $engagementIds)
            ->where('status', Contract::STATUS_EXECUTED)
            ->where(fn ($q) => $q
                ->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', $asOf->toDateString()))
            ->pluck('engagement_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();

        return $withContracts->diff($withLiveContract)->values()->all();
    }

    /** @return array<string, mixed> */
    private function grantRow(AccessGrant $grant): array
    {
        return [
            'id' => $grant->getKey(),
            'grantee_name' => $grant->grantee_name,
            'grantee_email' => $grant->grantee_email,
            'system_name' => $grant->system_name,
            'access_level' => $grant->access_level->value,
            'access_level_label' => $grant->access_level->label(),
            'is_privileged' => $grant->access_level->isPrivileged(),
            'status' => $grant->status->value,
            'valid_to' => $grant->valid_to?->toDateString(),
            'engagement_uuid' => $grant->engagement?->uuid,
            'engagement_name' => $grant->engagement?->name,
            'third_party' => $grant->engagement?->thirdParty?->legal_name,
        ];
    }

    /**
     * Privileged access is the row a reconciliation reader should see first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function sortByExposure(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $order = [
                AccessLevel::Admin->value => 0,
                AccessLevel::Privileged->value => 1,
                AccessLevel::Write->value => 2,
                AccessLevel::Read->value => 3,
            ];

            return ($order[$a['access_level'] ?? ''] ?? 9) <=> ($order[$b['access_level'] ?? ''] ?? 9);
        });

        return $rows;
    }

    /**
     * Close everything on an engagement in one act — the offboarding path.
     *
     * Used by the offboarding checklist rather than by a bulk button on the
     * register, because it takes ONE evidence document for the lot and that is
     * only honest when the evidence really is one artefact: an access review
     * report showing every account disabled.
     */
    public function closeAll(Engagement $engagement, int $evidenceDocumentId, ?int $userId = null): int
    {
        return DB::transaction(function () use ($engagement, $evidenceDocumentId, $userId): int {
            $closed = 0;

            $connections = Connection::query()->where('engagement_id', $engagement->getKey())->open()->get();

            foreach ($connections as $connection) {
                $this->close($connection, $evidenceDocumentId, null, $userId);
                $closed++;
            }

            $grants = AccessGrant::query()->where('engagement_id', $engagement->getKey())->live()->get();

            foreach ($grants as $grant) {
                $this->revokeGrant($grant, $evidenceDocumentId, $userId);
                $closed++;
            }

            return $closed;
        });
    }
}
