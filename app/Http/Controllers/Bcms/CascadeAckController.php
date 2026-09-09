<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\CascadeOutcome;
use App\Http\Controllers\Controller;
use App\Models\Bcms\CallTreeTestNode;
use App\Services\Bcms\CallTrees\CascadeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Acknowledging a cascade without being logged in.
 *
 * THE PERSON ANSWERING A CALL TREE IS NOT AT A DESK. They are a branch teller
 * with a feature phone at 03:00, and requiring them to log in to a GRC platform
 * to say "received" is requiring them not to answer. Criterion 3 asks for three
 * routes in — the app, a web link and an SMS reply — and two of them arrive with
 * no session.
 *
 * TENANCY HAS TO BE SET BY HAND HERE. `OrganizationScope` is INERT when nothing
 * has resolved a tenant, and its docblock's reassurance that "HTTP requests can
 * never reach a controller untenanted" is untrue of exactly two routes in this
 * product: the signed ICS feed (Phase 4) and this one. Without the explicit
 * `TenantContext::set()` below, a later query in the same request would see
 * every organisation's rows. Phase 4 learned this; this is the second instance
 * and the pattern is deliberately identical.
 *
 * THE LINK IS SIGNED AND THE TOKEN IS AN HMAC OF THE NODE ID. A sequential id
 * in a URL would let anybody acknowledge on anybody's behalf, which would make
 * the timing evidence worthless. `hash_equals` compares them, so the token
 * cannot be recovered one character at a time.
 */
class CascadeAckController extends Controller
{
    public function __construct(private CascadeEngine $engine) {}

    /** The page the link in the message opens. */
    public function show(Request $request, string $token): Response
    {
        $node = $this->resolve($token);

        if ($node === null) {
            return Inertia::render('Bcms/CallTrees/Acknowledge', [
                'ok' => false,
                'headline' => 'This acknowledgement link is no longer live.',
                'detail' => 'The cascade it belongs to has finished, or the link has expired. Nothing '
                    .'further is needed.',
            ]);
        }

        // Already answered — by this person on another device, by an SMS reply,
        // or by somebody recording it for them. It is not an error and must not
        // read as one: a second tap on the link in a message is what people
        // actually do when they are not sure the first one worked.
        if ($node->outcome !== CascadeOutcome::Pending) {
            return Inertia::render('Bcms/CallTrees/Acknowledge', [
                'ok' => true,
                'done' => true,
                'headline' => 'Acknowledged. Thank you.',
                'detail' => 'Your response was timed and recorded. Now contact everybody below you in '
                    .'the tree.',
                'name' => $node->contact_name_snapshot,
            ]);
        }

        $test = $node->test;
        $tree = $test === null ? null : $test->callTree;
        $treeName = $tree === null ? 'call tree' : $tree->name;

        return Inertia::render('Bcms/CallTrees/Acknowledge', [
            'ok' => true,
            'token' => $token,
            'headline' => 'THIS IS AN EXERCISE — '.$treeName.' cascade test',
            'detail' => 'Confirm you received this. Then contact everybody below you in the tree.',
            'name' => $node->contact_name_snapshot,
            'role' => $node->role_label_snapshot,
            'contacted_at' => $node->contacted_at?->toIso8601String(),
        ]);
    }

    /**
     * The button on it.
     *
     * POST THEN REDIRECT, never POST then render. Rendering from the POST left
     * the browser on a URL that re-submits when the page is refreshed — and a
     * refresh is exactly what somebody does when they are not sure a tap
     * registered. The redirect also means the "thank you" state has a URL of
     * its own that can be revisited, which the same link in the same message
     * already is.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $node = $this->resolve($token);

        if ($node !== null && $node->outcome === CascadeOutcome::Pending) {
            $this->engine->acknowledge($node, via: CascadeEngine::CHANNEL_WEB);
        }

        return redirect()->route('bcms.cascade.ack', $token);
    }

    /**
     * An inbound reply from a gateway.
     *
     * BUILT NOW, VERIFIED AT INTEGRATION. Criterion 3 marks USSD as
     * `[verify at integration]` because the aggregator is wired in Phase 12;
     * the handler and the cascade record are what Phase 6 owes, and they are
     * here. Phase 7's real adapters post to this same route with the same
     * shape.
     *
     * The token is matched inside the body rather than requiring it to be the
     * whole message, because people reply "OK 3f2a" and "3f2a received" and
     * both are a person confirming they are alive.
     */
    public function inbound(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1000'],
            'channel' => ['nullable', 'string', 'max:20'],
        ]);

        $token = $this->extractToken($data['body']);
        $node = $token === null ? null : $this->resolve($token);

        if ($node === null) {
            // 200, not 404. A gateway that receives an error retries, and a
            // retried unmatched reply is a loop that costs money.
            return response()->json([
                'matched' => false,
                'note' => 'No live cascade node matched this reply.',
            ]);
        }

        // "WRONG" is the response-accuracy path of Blueprint §6.3: somebody
        // answered but did the wrong thing. It still counts as reached.
        $correct = ! str_contains(strtolower($data['body']), 'wrong');

        $this->engine->acknowledge(
            $node,
            via: $data['channel'] ?? 'sms',
            correctAction: $correct,
            note: 'Inbound reply from '.($data['from'] ?? 'an unrecorded number').': '.$data['body'],
        );

        return response()->json(['matched' => true, 'outcome' => $node->refresh()->outcome?->value]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Find the node this token addresses, whatever state it is in.
     *
     * It deliberately does NOT filter out an already-answered node: the caller
     * has to tell "we have your answer" apart from "this link is dead", and
     * collapsing the two here would show somebody who tapped twice an error
     * message about an expired link.
     */
    private function resolve(string $token): ?CallTreeTestNode
    {
        $node = $this->engine->nodeForToken($token);

        if ($node === null) {
            return null;
        }

        // See the class docblock. This is not optional and it is not a
        // performance measure.
        TenantContext::set((int) $node->organization_id);

        return $node;
    }

    private function extractToken(string $body): ?string
    {
        // Sixteen lowercase hex characters, anywhere in the message.
        if (preg_match('/\b([0-9a-f]{16})\b/i', $body, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
