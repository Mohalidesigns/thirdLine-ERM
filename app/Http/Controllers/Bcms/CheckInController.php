<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\CheckInByCodeRequest;
use App\Models\Bcms\ExerciseParticipant;
use App\Services\Bcms\Exercises\CheckInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Checking in without being logged in — the QR/SMS surface (qr-checkin spec,
 * screen B).
 *
 * TENANCY IS SET BY HAND HERE, exactly as `CascadeAckController` does and for
 * the same reason: `OrganizationScope` is inert until a tenant has resolved,
 * and this route (like that one) reaches a controller with no session at all.
 *
 * POST THEN REDIRECT, never render from the POST — a refresh on the "thank
 * you" URL must never re-submit, matching `CascadeAckController` exactly.
 *
 * THE PAGE NEVER REVEALS WHICH EXERCISE A TOKEN BELONGS TO. An unknown or
 * stale token gets the same "not recognised" message regardless of why it
 * failed, so probing tokens teaches an attacker nothing.
 */
class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIn) {}

    public function show(string $token): Response
    {
        $participant = $this->resolveToken($token);

        return $this->render($participant, $token);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $participant = $this->resolveToken($token);

        if ($participant !== null && $participant->checked_in_at === null) {
            try {
                // Advisory 6 (Gate 2 review #3): a logged-in facilitator
                // opening a copied link used to be recorded with
                // `recorded_by: 'self'` — indistinguishable from the
                // participant's own scan — even though the request carried
                // a real, authenticated actor. `$request->user()` is null
                // for the ordinary case (no session at an assembly point),
                // so `CheckInService::checkIn()`'s own null-to-'self'
                // fallback still applies then; only a signed-in relay now
                // names its actor.
                $this->checkIn->checkIn($participant, 'qr', $request->user());
            } catch (InvalidArgumentException) {
                // Frozen (Gate 2 defect 2) — the redirect below still lands on
                // `show()`, whose own `render()` already answers "closed" for
                // an ended occurrence. Nothing further to say on a page that,
                // by this class's own rule, never explains why a check-in did
                // not take.
            }
        }

        return redirect()->route('bcms.check-in.show', $token);
    }

    /** The short-code entry form — no token in the URL at all. */
    public function codeForm(): Response
    {
        return Inertia::render('Bcms/Exercises/CheckInCode', [
            'code_form_url' => route('bcms.check-in.code.store'),
        ]);
    }

    /**
     * Advisory 12 (Gate 2): redirects on success, exactly like `store()` —
     * this used to render `CheckIn` directly from the POST, which is the one
     * thing the class docblock's own "post then redirect" rule exists to
     * forbid: a refresh of that response would have resubmitted the code.
     * The not-recognised branch still renders `CheckInCode` directly, because
     * that is not a successful submission to protect from a refresh — it is
     * the same form, again, with an error.
     */
    public function codeStore(CheckInByCodeRequest $request): Response|RedirectResponse
    {
        $participant = $this->checkIn->participantForShortCode($request->string('code')->toString());

        if ($participant === null) {
            return Inertia::render('Bcms/Exercises/CheckInCode', [
                'error' => 'That code was not recognised. Check the digits and try again, or ask the marshal.',
                'code_form_url' => route('bcms.check-in.code.store'),
            ]);
        }

        TenantContext::set((int) $participant->organization_id);

        if ($participant->checked_in_at === null) {
            try {
                // Advisory 7a (Gate 2 review #3): this branch is a web
                // submission of the short-code FORM, not an SMS reply — no
                // inbound SMS parser feeds this controller at all (see
                // `CheckInService::TOKEN_PATTERN`'s own docblock). Recording
                // 'sms' here claimed a channel that never happened;
                // `check_in_method` is a plain `string(20)` column with no
                // enum constraint at the database or the model, so 'code'
                // is free to use and reads honestly. Advisory 6, same
                // reasoning as `store()` above: pass the actor along when
                // there is one.
                $this->checkIn->checkIn($participant, 'code', $request->user());
            } catch (InvalidArgumentException) {
                // Frozen (Gate 2 defect 2) — as in store() above.
            }
        }

        return redirect()->route('bcms.check-in.show', $this->checkIn->tokenFor($participant));
    }

    /**
     * Gate 1 defect, this pass (docs/bcms/screens/qr-checkin.md §3, the
     * "Rate-limited" state): a throttled request on any of the four
     * check-in routes used to fall through to Laravel's default 429 page —
     * no product shell, reached by someone standing at an assembly point
     * with nothing but a phone. Called from bootstrap/app.php's
     * `ThrottleRequestsException` handler, which has a route NAME and (for
     * the token pair) the raw `{token}` path segment but never a resolved
     * `ExerciseParticipant` — the throttle middleware trips before this
     * controller, and therefore before `resolveToken()`, ever runs.
     *
     * For the short-code form this reuses the same
     * `['error' => ..., 'code_form_url' => route(...)]` shape `codeStore()`'s
     * own not-recognised branch builds — nothing is resolved there either
     * way, so there was never anything to over-run.
     *
     * BLOCKING DEFECT 1 (Gate 2 review #3), for the token pair: this used to
     * call `resolveToken()`/`render()` exactly as an un-throttled request
     * would, so a request that had already tripped the ceiling still ran
     * the participant lookup and the occurrence/site/definition/user loads
     * (~5 queries) the ceiling exists to stop — and answered a valid token
     * with `ok: true`, the participant's NAME, exercise, site and a working
     * `check_in_url`, a guessed one with `ok: false`, distinguishing valid
     * from invalid at unlimited speed once over the limit. That is exactly
     * what `qr-checkin.md` §3(B)'s "Unknown or expired token" rule says this
     * page must never do ("the page never reveals which exercise a token
     * *does* belong to, which would leak information to someone probing
     * tokens") and it violates ADR 0016 §4 — "a ceiling is only a ceiling on
     * the work that happens after it." The token is now NEVER resolved
     * here: a valid token and a made-up one get IDENTICAL props, the exact
     * "not recognised" shape `render()` already answers with for a null
     * participant, which `CheckIn.jsx` already mounts correctly (`exercise`
     * absent falls back to `headline`; `name`/`site`/`check_in_url` unused
     * when absent).
     */
    public function renderThrottled(string $routeName, ?string $token = null): Response
    {
        $error = 'Too many attempts — wait a moment and try again.';

        if ($routeName === 'bcms.check-in.code' || $routeName === 'bcms.check-in.code.store') {
            return Inertia::render('Bcms/Exercises/CheckInCode', [
                'error' => $error,
                'code_form_url' => route('bcms.check-in.code.store'),
            ]);
        }

        // `$token` is deliberately unread below (defect 1, above) — kept as
        // a parameter only because `bootstrap/app.php`'s exception handler
        // passes it, and this method's signature is that handler's contract.
        return Inertia::render('Bcms/Exercises/CheckIn', [
            'ok' => false,
            'headline' => $error,
            'detail' => null,
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function resolveToken(string $token): ?ExerciseParticipant
    {
        $participant = $this->checkIn->participantForToken($token);

        if ($participant === null) {
            return null;
        }

        // See the class docblock. Not a performance measure.
        TenantContext::set((int) $participant->organization_id);

        return $participant;
    }

    /**
     * `$token` is only ever the same value already sitting in this page's own
     * URL (or, from the short-code path, a value derived from the same HMAC
     * `CheckInService::tokenFor()` the QR path already exposes) — building
     * `check_in_url` from it is not a new disclosure. It is what the "I'm
     * here" button below needs to `POST /bcms/check-in/{token}` without this
     * unauthenticated page reconstructing that URL by hand (Advisory 13).
     */
    private function render(?ExerciseParticipant $participant, ?string $token = null): Response
    {
        if ($participant === null) {
            return Inertia::render('Bcms/Exercises/CheckIn', [
                'ok' => false,
                'headline' => 'This check-in link is not recognised.',
                'detail' => 'Please see the exercise marshal, or use the short code printed alongside the QR code.',
            ]);
        }

        $occurrence = $participant->occurrence;

        if ($occurrence !== null && $occurrence->actual_end !== null) {
            return Inertia::render('Bcms/Exercises/CheckIn', [
                'ok' => false,
                'headline' => "This exercise's check-in has closed.",
                'detail' => null,
            ]);
        }

        if ($participant->checked_in_at !== null) {
            return Inertia::render('Bcms/Exercises/CheckIn', [
                'ok' => true,
                'done' => true,
                'headline' => 'Recorded. You can close this page.',
                'name' => $participant->user?->name,
            ]);
        }

        $site = null;

        if ($occurrence !== null) {
            // Kept nullsafe on `site` deliberately: `site_id` is a nullable
            // column, so an occurrence genuinely can have none. Larastan
            // resolves this chain (reached through `$participant->
            // occurrence`) as never-null and flags the nullsafe as
            // redundant — the same construct passes uneventfully in
            // ExecutionController/ReadinessController/AarExportService/
            // CalendarService, where `$occurrence` is a directly-typed
            // parameter rather than a relation result, so this looks like a
            // narrowing quirk specific to the chained-relation shape rather
            // than a real guarantee. Runtime correctness wins over the lint.
            // @phpstan-ignore-next-line nullsafe.neverNull (Larastan mis-resolves `site` as never-null through this chained-relation shape; `site_id` is genuinely nullable — see the comment above)
            $site = $occurrence->site?->name ?? $occurrence->location;
        }

        return Inertia::render('Bcms/Exercises/CheckIn', [
            'ok' => true,
            'headline' => 'THIS IS AN EXERCISE',
            'exercise' => $occurrence?->definition?->name,
            'site' => $site,
            'name' => $participant->user?->name,
            'check_in_url' => route('bcms.check-in.store', $token),
        ]);
    }
}
