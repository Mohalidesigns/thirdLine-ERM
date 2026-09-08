<?php

namespace App\Services\Bcms\CallTrees;

use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Services\Bcms\ContactResolver;
use App\Services\Bcms\Notification\ChannelRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Running a cascade: initiate, dispatch, escalate, acknowledge, complete.
 *
 * TIME IS THE MEASUREMENT, so everything is timestamped at the moment it
 * happens rather than derived afterwards. "How long did tier 2 take" is the
 * question a regulator asks, and it cannot be answered from a tree that only
 * records who eventually answered.
 *
 * EVERY NODE IS SNAPSHOT AT INITIATION, not as it is reached. The live map has
 * to show the whole tree from second one — a map that grows as people answer
 * cannot show you the branch that never lit up, and that branch is the entire
 * point. The snapshot columns also mean a tree repaired on Tuesday does not
 * rewrite Monday's failure.
 *
 * ONE ATTEMPT IS ONE PERSON, NOT ONE CHANNEL. Attempt 1 is the primary on their
 * best available channel; attempt 2 is the deputy. Multi-channel failover
 * within one attempt belongs to the EMNS dispatcher (Phase 7) and modelling it
 * here would make `attempts` mean two different things on the same column —
 * and `attempts >= 2` is how the scorecard knows the deputy was needed.
 *
 * THE WRITE-AHEAD ROW IS THE TEST NODE (standing rule 8, ADR 0013). It is
 * written with `contacted_at` and an incremented `attempts` BEFORE
 * `NotificationChannel::send()` is called, so a worker that dies mid-dispatch
 * leaves a row saying an attempt was made rather than a silence saying none was.
 *
 * A TEST IS ALWAYS A SIMULATION. `RenderedMessage::$isSimulation` is true on
 * every message this class composes, which forces the "THIS IS AN EXERCISE"
 * prefix (standing rule 5). There is no argument that reaches this class for
 * turning it off; a real activation goes through Phase 10, not through here.
 */
class CascadeEngine
{
    /** Recorded in `channel_used` when a person, not a gateway, made contact. */
    public const CHANNEL_MANUAL = 'manual';

    /** An acknowledgement that arrived through the app's own bell. */
    public const CHANNEL_IN_APP = 'in_app';

    /** An acknowledgement from the signed web link in the message. */
    public const CHANNEL_WEB = 'web_link';

    /**
     * Downstream counts, per tree, for the life of one dispatch run.
     *
     * `structure()` walks every node and its relations. Calling it once per
     * message composed turns a two-hundred-person cascade into two hundred tree
     * walks, and the number it returns cannot change while a cascade is running
     * — an approved tree is immutable.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $structureCache = [];

    public function __construct(
        private CallTreeService $trees,
        private ContactResolver $contacts,
        private ChannelRegistry $channels,
        private CascadeScorecard $scorecard,
        private BrokenBranchAnalyser $branches,
    ) {}

    /** @return array<string, mixed> */
    private function structureOf(CallTree $tree): array
    {
        return $this->structureCache[(int) $tree->getKey()] ??= $this->trees->structure($tree);
    }

    /* ------------------------------------------------------------------ */
    /*  Starting */
    /* ------------------------------------------------------------------ */

    /**
     * Create a test against a tree. Nothing is dispatched until `initiate()`.
     *
     * Booking and firing are separate on purpose: a test scheduled on the
     * calendar exists for ten days before it runs, and the facilitator needs
     * something to open in the meantime.
     */
    public function schedule(
        CallTree $tree,
        CascadeMode $mode = CascadeMode::Hybrid,
        bool $announced = true,
        ?ExerciseOccurrence $occurrence = null,
        ?int $userId = null,
    ): CallTreeTest {
        if ($tree->status !== CallTreeStatus::Approved) {
            throw new InvalidArgumentException(
                'Only an approved call tree can be tested. Testing a draft measures a cascade nobody '
                .'has agreed to run.'
            );
        }

        $test = CallTreeTest::query()->create([
            'organization_id' => $tree->organization_id,
            'call_tree_id' => $tree->getKey(),
            'occurrence_id' => $occurrence?->getKey(),
            'mode' => $mode->value,
            'announced' => $announced,
            'iso_clause_ref' => 'ISO22301:8.4.3',
            'created_by' => $userId ?? auth()->id(),
        ]);

        if (! $announced) {
            // Criterion 7. The suppression is a decision somebody made and it
            // is written down where an examiner asking "why did these forty
            // people get no notice" finds the answer beside the test.
            $test->recordAudit('call_tree_test.unannounced', [
                'reason' => 'Unannounced cascade: the T-10 reminder ladder is suppressed and the '
                    .'occurrence is hidden from participants. Only the programme owner, the '
                    .'definition owner and the facilitator can see it.',
                'occurrence_id' => $occurrence?->getKey(),
            ]);
        }

        return $test;
    }

    /**
     * Fire it. Snapshots the tree, then releases tier 0.
     */
    public function initiate(CallTreeTest $test, ?int $userId = null): CallTreeTest
    {
        if ($test->initiated_at !== null) {
            throw new InvalidArgumentException('This cascade has already been initiated.');
        }

        return DB::transaction(function () use ($test, $userId) {
            $structure = $this->structureOf($test->callTree);

            $this->snapshot($test, $structure['index']);

            $test->forceFill([
                'initiated_at' => now(),
                'initiated_by' => $userId ?? auth()->id(),
                'nodes_total' => count($structure['index']),
                'nodes_reached' => 0,
            ])->save();

            $test->recordAudit('call_tree_test.initiated', [
                'mode' => $test->mode->value,
                'announced' => (bool) $test->announced,
                'nodes' => count($structure['index']),
            ]);

            $this->releaseTier($test, 0);

            return $test->refresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $index
     */
    private function snapshot(CallTreeTest $test, array $index): void
    {
        $rows = [];
        $now = now();

        foreach ($index as $node) {
            $rows[] = [
                'organization_id' => $test->organization_id,
                'test_id' => $test->getKey(),
                'node_id' => $node['id'],
                'role_label_snapshot' => $node['role_label'],
                'contact_name_snapshot' => $node['name'],
                'tier_snapshot' => $node['tier'],
                'attempts' => 0,
                'outcome' => CascadeOutcome::Pending->value,
                'downstream_blocked_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            CallTreeTestNode::query()->insert($chunk);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Advancing */
    /* ------------------------------------------------------------------ */

    /**
     * Release a tier: dispatch it, or mark it awaiting a human.
     *
     * A NODE WHOSE PARENT NEVER ANSWERED IS NOT RELEASED. That is the whole
     * mechanic of a cascade and the reason a broken branch is a branch rather
     * than a person: the fourteen people under an unreachable unit lead were
     * never called, and calling them anyway would make the test pass while the
     * real cascade would have failed.
     */
    public function releaseTier(CallTreeTest $test, int $tier): int
    {
        $released = 0;

        foreach ($this->tierNodes($test, $tier) as $testNode) {
            if ($testNode->outcome !== CascadeOutcome::Pending || $testNode->contacted_at !== null) {
                continue;
            }

            if (! $this->parentReached($test, $testNode)) {
                continue;
            }

            $this->contact($test, $testNode);
            $released++;
        }

        return $released;
    }

    /**
     * One step of the clock: settle timeouts, escalate to deputies, release
     * whatever the last round unblocked.
     *
     * Called by `bcms:cascade-tick` every minute while a test is running, and
     * directly by a test that needs to advance the world by hand.
     */
    public function tick(CallTreeTest $test, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $escalated = 0;
        $timedOut = 0;

        /** @var Collection<int, CallTreeTestNode> $waiting */
        $waiting = CallTreeTestNode::query()
            ->where('test_id', $test->getKey())
            ->where('outcome', CascadeOutcome::Pending->value)
            ->whereNotNull('contacted_at')
            ->with('node.deputyContact')
            ->get();

        foreach ($waiting as $testNode) {
            $node = $testNode->node;
            $window = $node !== null ? max(1, (int) $node->expected_response_minutes) : 15;

            if ($testNode->contacted_at === null || $testNode->contacted_at->copy()->addMinutes($window)->gt($now)) {
                continue;
            }

            // The window has run out. One escalation is allowed, to the deputy,
            // and only if the deputy is somebody other than the person who has
            // just failed to answer.
            if ($testNode->attempts < 2 && $node !== null && $node->deputy_contact_id !== null) {
                $this->contactDeputy($test, $testNode, $now);
                $escalated++;

                continue;
            }

            $this->settle($testNode, CascadeOutcome::Timeout, $now,
                $node !== null && $node->deputy_contact_id === null
                    ? 'No response within '.$window.' minutes, and this node has no deputy to escalate to.'
                    : 'No response within '.$window.' minutes from the primary or the deputy.');
            $timedOut++;
        }

        $releasedTotal = 0;
        foreach ($this->tiersOf($test) as $tier) {
            $releasedTotal += $this->releaseTier($test, $tier);
        }

        return ['escalated' => $escalated, 'timed_out' => $timedOut, 'released' => $releasedTotal];
    }

    /* ------------------------------------------------------------------ */
    /*  Contacting one node */
    /* ------------------------------------------------------------------ */

    private function contact(CallTreeTest $test, CallTreeTestNode $testNode): void
    {
        $node = $testNode->node;
        $tier = $this->tierOf($testNode, $node);

        if ($test->mode->requiresHumanConfirmation($tier)) {
            // Manual: the person above them makes the call. All the system does
            // is start the clock and wait for somebody to record what happened.
            $testNode->forceFill([
                'contacted_at' => now(),
                'channel_used' => self::CHANNEL_MANUAL,
                'attempts' => 1,
            ])->save();

            return;
        }

        $contact = $node?->contact;

        if ($contact === null) {
            $this->settle($testNode, CascadeOutcome::Unrecognised, now(),
                'This node names nobody the roster can resolve.');

            return;
        }

        $this->dispatchTo($test, $testNode, $contact, attempt: 1, viaDeputy: false);
    }

    private function contactDeputy(CallTreeTest $test, CallTreeTestNode $testNode, Carbon $now): void
    {
        $node = $testNode->node;
        $deputy = $node?->deputyContact;

        if ($deputy === null) {
            $this->settle($testNode, CascadeOutcome::Timeout, $now, 'No deputy is assigned to this node.');

            return;
        }

        $tier = $this->tierOf($testNode, $node);

        $testNode->appendNote('Escalated to deputy '.$deputy->full_name.' at '.$now->toTimeString().'.');

        if ($test->mode->requiresHumanConfirmation($tier)) {
            $testNode->forceFill([
                'attempts' => 2,
                'contacted_at' => $now,
                'channel_used' => self::CHANNEL_MANUAL,
            ])->save();

            return;
        }

        $this->dispatchTo($test, $testNode, $deputy, attempt: 2, viaDeputy: true, at: $now);
    }

    /**
     * Send, having already written the row that says we were about to.
     */
    private function dispatchTo(
        CallTreeTest $test,
        CallTreeTestNode $testNode,
        Contact $contact,
        int $attempt,
        bool $viaDeputy,
        ?Carbon $at = null,
    ): void {
        $at = $at ?? now();
        $node = $testNode->node;

        $requested = $this->requestedChannels($node, $viaDeputy);

        // A cascade test is not life-safety traffic. Consent bites, and it is
        // meant to: criterion 10 exists because an exclusion nobody can see is
        // an exclusion that surprises somebody during a real emergency.
        $usable = $this->contacts->channelsFor($contact, $requested, isLifeSafety: false);

        if ($usable === []) {
            $blockedByConsent = $contact->consent_status === 'withdrawn'
                && $this->contacts->channelsFor($contact, $requested, isLifeSafety: true) !== [];

            $this->settle(
                $testNode,
                $blockedByConsent ? CascadeOutcome::ConsentBlocked : CascadeOutcome::NoChannel,
                $at,
                $blockedByConsent
                    ? $contact->full_name.' has withdrawn consent for personal-channel contact and was '
                        .'excluded from this cascade. They remain on the tree and are reachable on a '
                        .'work channel in a life-safety activation.'
                    : $contact->full_name.' has no usable address on any requested channel.',
            );

            return;
        }

        $channelKey = $usable[0];

        // Standing rule 8: the row goes first.
        $testNode->forceFill([
            'attempts' => $attempt,
            'contacted_at' => $at,
            'channel_used' => $channelKey->value,
        ])->save();

        $channel = $this->channels->for($channelKey);
        $receipt = $channel->send(
            $this->contacts->recipient($contact),
            $this->message($test, $testNode, $contact),
        );

        if ($receipt->status === DeliveryStatus::Failed) {
            $outcome = CascadeOutcome::fromFailureReason($receipt->failedReason);

            if ($attempt < 2 && $node !== null && $node->deputy_contact_id !== null) {
                // A dead number is not worth waiting fifteen minutes over.
                $testNode->appendNote($channelKey->value.' failed: '.$receipt->failedReason);
                $this->contactDeputy($test, $testNode->refresh(), $at);

                return;
            }

            $this->settle($testNode, $outcome, $at, $receipt->failedReason);
        }
    }

    /**
     * @return list<ChannelKey>
     */
    private function requestedChannels(?CallTreeNode $node, bool $viaDeputy): array
    {
        $named = [];

        if ($node !== null && ! $viaDeputy) {
            foreach ([$node->primary_channel, $node->secondary_channel] as $configured) {
                $key = is_string($configured) ? ChannelKey::tryFrom($configured) : null;

                if ($key !== null) {
                    $named[] = $key;
                }
            }
        }

        if ($named !== []) {
            return $named;
        }

        // The default order is the one a cascade actually uses: a voice call is
        // what a call tree is, SMS is what reaches a phone with no data, and
        // email is the fallback nobody answers in fifteen minutes but which
        // leaves a record.
        return [ChannelKey::Voice, ChannelKey::Sms, ChannelKey::WhatsApp, ChannelKey::Email];
    }

    private function message(CallTreeTest $test, CallTreeTestNode $testNode, Contact $contact): RenderedMessage
    {
        $tree = $test->callTree;
        $below = (int) ($this->structureOf($tree)['index'][(int) $testNode->node_id]['downstream_count'] ?? 0);

        $instruction = $below > 0
            ? ' Acknowledge, then contact the '.$below.' '.Str::plural('person', $below).' below you in the tree.'
            : ' Acknowledge to confirm you received this.';

        return new RenderedMessage(
            body: $tree->name.' cascade test. '.$contact->full_name.','.$instruction,
            subject: $tree->name.' — call tree test',
            locale: $contact->preferred_language ?: 'en',
            severity: AlertSeverity::Advisory,
            // Never configurable. A cascade test that could be sent without the
            // prefix is a cascade test that will one day panic a branch.
            isSimulation: true,
            responseRequired: true,
            metadata: ['call_tree_test_id' => (int) $test->getKey(), 'node_id' => (int) $testNode->node_id],
            callbackToken: $this->tokenFor($testNode),
        );
    }

    /**
     * The token that lets a web link or an SMS reply identify one node without
     * the responder being logged in.
     */
    public function tokenFor(CallTreeTestNode $testNode): string
    {
        return substr(hash_hmac('sha256', 'bcms-cascade-'.$testNode->getKey(), (string) config('app.key')), 0, 16);
    }

    public function nodeForToken(string $token): ?CallTreeTestNode
    {
        // Scoped to cascades that are still OPEN rather than to nodes that are
        // still pending. A node that has already answered has to keep
        // resolving, or the second tap on the link in a message — which is what
        // people do when they are not sure the first one worked — would tell
        // them the link was dead rather than that their answer was recorded.
        // A closed cascade is genuinely no longer live and stops resolving,
        // which is also what bounds this scan: hundreds of rows, not millions.
        $open = CallTreeTest::query()
            ->whereNotNull('initiated_at')
            ->whereNull('completed_at')
            ->select('id');

        // Compared in constant time, so a token cannot be recovered a character
        // at a time.
        foreach (CallTreeTestNode::query()->whereIn('test_id', $open)->cursor() as $candidate) {
            if (hash_equals($this->tokenFor($candidate), $token)) {
                return $candidate;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Answering */
    /* ------------------------------------------------------------------ */

    /**
     * Somebody responded. Records the timing, then releases whoever they unblock.
     */
    public function acknowledge(
        CallTreeTestNode $testNode,
        string $via = self::CHANNEL_IN_APP,
        bool $correctAction = true,
        ?Carbon $at = null,
        ?string $note = null,
    ): CallTreeTestNode {
        if ($testNode->outcome !== null && $testNode->outcome !== CascadeOutcome::Pending) {
            // Already settled. A duplicate acknowledgement is not an error —
            // people reply twice — and overwriting the first one would move the
            // response time the scorecard reports.
            return $testNode;
        }

        $at = $at ?? now();
        $test = $testNode->test;

        // A node acknowledged before anybody released it — somebody answered a
        // call that was never placed — still counts, and the clock runs from
        // the cascade's start rather than from a contact that never happened.
        $started = $test === null ? null : $test->initiated_at;
        $from = $testNode->contacted_at ?? $started ?? $at;

        $outcome = $correctAction
            ? ($testNode->attempts >= 2 ? CascadeOutcome::Deputy : CascadeOutcome::Reached)
            : CascadeOutcome::WrongAction;

        $testNode->forceFill([
            'acknowledged_at' => $at,
            'response_minutes' => max(0, (int) round($from->diffInMinutes($at, absolute: true))),
            'outcome' => $outcome->value,
            'channel_used' => $testNode->channel_used ?? $via,
            'attempts' => max(1, (int) $testNode->attempts),
        ])->save();

        if ($note !== null) {
            $testNode->appendNote($note);
        }

        $testNode->appendNote('Acknowledged via '.$via.'.');

        if ($test !== null) {
            $test->forceFill([
                'nodes_reached' => CallTreeTestNode::query()
                    ->where('test_id', $test->getKey())
                    ->whereIn('outcome', [
                        CascadeOutcome::Reached->value,
                        CascadeOutcome::Deputy->value,
                        CascadeOutcome::WrongAction->value,
                    ])->count(),
            ])->save();

            foreach ($this->tiersOf($test) as $tier) {
                $this->releaseTier($test, $tier);
            }
        }

        return $testNode->refresh();
    }

    /** A failure recorded by a person: "I rang, the line is dead." */
    public function recordFailure(
        CallTreeTestNode $testNode,
        CascadeOutcome $outcome,
        ?string $note = null,
        ?Carbon $at = null,
    ): CallTreeTestNode {
        if ($outcome->isReached()) {
            throw new InvalidArgumentException('Use acknowledge() to record a node that answered.');
        }

        $this->settle($testNode, $outcome, $at ?? now(), $note);

        return $testNode->refresh();
    }

    private function settle(CallTreeTestNode $testNode, CascadeOutcome $outcome, Carbon $at, ?string $note): void
    {
        $testNode->forceFill([
            'outcome' => $outcome->value,
            'contacted_at' => $testNode->contacted_at ?? $at,
            'attempts' => max(1, (int) $testNode->attempts),
        ])->save();

        if ($note !== null && $note !== '') {
            $testNode->appendNote($note);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Finishing */
    /* ------------------------------------------------------------------ */

    /**
     * Close the cascade: everything still pending is settled, everything below
     * a failure is marked blocked, and the scorecard is stored.
     */
    public function complete(CallTreeTest $test, ?int $userId = null, ?Carbon $at = null): CallTreeTest
    {
        $at = $at ?? now();

        return DB::transaction(function () use ($test, $userId, $at) {
            $this->settleOutstanding($test, $at);

            // BLUEPRINT §6.3 DEFINES THIS AS "TIER 0 INITIATION → LAST TIER 3
            // ACKNOWLEDGEMENT", not as initiation → whenever somebody clicked
            // close. A facilitator who leaves the screen open over lunch before
            // closing a cascade that finished in eleven minutes must not turn
            // it into a ninety-minute cascade in the record. Where nobody
            // acknowledged at all there is nothing to measure to, and the close
            // is the only end there is.
            $lastAck = CallTreeTestNode::query()
                ->where('test_id', $test->getKey())
                ->whereNotNull('acknowledged_at')
                ->max('acknowledged_at');

            $end = $lastAck === null ? $at : CarbonImmutable::parse($lastAck);

            $test->forceFill([
                'completed_at' => $at,
                'total_cascade_minutes' => $test->initiated_at === null
                    ? null
                    : max(0, (int) round($test->initiated_at->diffInMinutes($end, absolute: true))),
                'updated_by' => $userId ?? auth()->id(),
            ])->save();

            // The counts are written onto the node rows BEFORE the scorecard
            // is computed, so the stored card and the stored nodes agree. A
            // screen recomputing one from the other in December would find
            // them disagreeing by however many people were repaired since.
            $this->branches->persist($test->refresh());
            $this->scorecard->store($test->refresh());

            $test->recordAudit('call_tree_test.completed', [
                'completion_rate' => (string) $test->refresh()->completion_rate,
                'minutes' => $test->total_cascade_minutes,
            ]);

            return $test->refresh();
        });
    }

    public function abort(CallTreeTest $test, string $reason, ?int $userId = null): CallTreeTest
    {
        $test->recordAudit('call_tree_test.aborted', ['reason' => $reason]);

        return $this->complete($test, $userId);
    }

    /**
     * THE BROKEN-BRANCH RULE, and the one place it is decided.
     *
     * A node still pending at the end either never answered (its own failure)
     * or was never contacted because somebody above it did not (`blocked`).
     * Recording the second as a timeout would blame thirty-four people for
     * missing a call nobody placed — and would make the tree look four times
     * more broken than it is.
     */
    private function settleOutstanding(CallTreeTest $test, Carbon $at): void
    {
        /** @var Collection<int, CallTreeTestNode> $rows */
        $rows = CallTreeTestNode::query()->where('test_id', $test->getKey())->with('node')->get();

        $byNodeId = $rows->keyBy(fn (CallTreeTestNode $r) => (int) $r->node_id);

        $reachedUpstream = function (CallTreeTestNode $row) use ($byNodeId): bool {
            $depth = 0;
            $cursor = $row->node?->parent_node_id;

            while ($cursor !== null && $depth++ < 12) {
                $parent = $byNodeId->get((int) $cursor);

                if ($parent === null) {
                    return true;
                }

                if ($parent->outcome === null || ! $parent->outcome->isReached()) {
                    return false;
                }

                $cursor = $parent->node?->parent_node_id;
            }

            return true;
        };

        foreach ($rows as $row) {
            if ($row->outcome !== null && $row->outcome !== CascadeOutcome::Pending) {
                continue;
            }

            $upstreamOk = $reachedUpstream($row);

            $this->settle(
                $row,
                $upstreamOk ? CascadeOutcome::Timeout : CascadeOutcome::Blocked,
                $at,
                $upstreamOk
                    ? 'The cascade closed with no response from this node.'
                    : 'Never contacted: the branch above this node was broken.',
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, CallTreeTestNode> */
    private function tierNodes(CallTreeTest $test, int $tier): Collection
    {
        return CallTreeTestNode::query()
            ->where('test_id', $test->getKey())
            ->where('tier_snapshot', $tier)
            ->with(['node.contact', 'node.deputyContact'])
            ->orderBy('id')
            ->get();
    }

    /** @return list<int> */
    private function tiersOf(CallTreeTest $test): array
    {
        return CallTreeTestNode::query()
            ->where('test_id', $test->getKey())
            ->distinct()->orderBy('tier_snapshot')
            ->pluck('tier_snapshot')
            ->filter(fn ($t) => $t !== null)
            ->map(fn ($t) => (int) $t)
            ->values()->all();
    }

    /**
     * A root is always releasable. Anything else waits for its parent.
     */
    private function parentReached(CallTreeTest $test, CallTreeTestNode $testNode): bool
    {
        $parentNodeId = $testNode->node?->parent_node_id;

        if ($parentNodeId === null) {
            return true;
        }

        $parent = CallTreeTestNode::query()
            ->where('test_id', $test->getKey())
            ->where('node_id', $parentNodeId)
            ->first();

        return $parent === null || ($parent->outcome !== null && $parent->outcome->isReached());
    }

    /**
     * A node's tier, from the snapshot if it has one and from the live node if
     * not.
     *
     * The snapshot is the truth — it is what the tier WAS when the cascade ran
     * — and the live node is the fallback for a row written before one existed.
     */
    private function tierOf(CallTreeTestNode $testNode, ?CallTreeNode $node): int
    {
        if ($testNode->tier_snapshot !== null) {
            return (int) $testNode->tier_snapshot;
        }

        return $node === null ? 0 : (int) $node->tier;
    }

    /** Is this cascade still going? */
    public function isRunning(CallTreeTest $test): bool
    {
        return $test->initiated_at !== null && $test->completed_at === null;
    }
}
