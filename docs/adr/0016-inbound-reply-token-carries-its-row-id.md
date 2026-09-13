# ADR 0016 — The inbound reply token carries its own row id, and the ceiling is priced on what a request costs when it is *accepted*

**Status:** Accepted · **Date:** 2026-09-12 · **Phase:** BCMS Phase 7 (EMNS) · **Author:** architect
**Requested by:** `code-reviewer`, gate 2 rejection of Phase 7 · **Broadcast to:** BCMS track,
platform track, compliance (one correction named in §6), TPRM track (no action — recorded because
the `tprm-portal` middleware group is cited as the precedent in §4)

## Context

`InboundResponseHandler::recipientForToken()` resolves a sixteen-hex token from an SMS reply by
**cursoring every open recipient and computing an HMAC for each row until one matches**
(`app/Services/Bcms/Emns/InboundResponseHandler.php:283-292`, against
`AlertDispatcher::tokenFor()` at `app/Services/Bcms/Emns/AlertDispatcher.php:231-238`).
`CascadeEngine::nodeForToken()` (`app/Services/Bcms/CallTrees/CascadeEngine.php:483-506`) is the
**same construction against a second table** — one defect, two call sites, and any fix that
addresses one leaves the next engineer to re-derive the argument for the other.

Two properties make this worse than an ordinary linear scan.

**It is unindexable by construction.** The token is *derived* from the primary key rather than
stored, so there is no column to look up and no index that could exist. The docblock at
`AlertDispatcher.php:227` states the reasoning — *"the schema is frozen, the value is derivable"* —
and that reasoning was sound when the alternative on the table was a column. It is not the only
alternative, which is what this ADR establishes.

**`OrganizationScope` is inert on a webhook.** There is no resolved tenant on an unauthenticated
gateway callback, so the scan is not bounded to the alert's tenant; it is **every tenant's** open
recipients. `routes/web.php:2741` already records that this is *"the second route in the product
where that matters"*. This is the third.

Against the phase's own acceptance criteria — **1,000 people answering inside 60 seconds** (AC 1)
and **10,000 recipients in a dispatch** (AC 2) — a scan over a thousand open recipients is on the
order of 10⁶ model hydrations and HMACs per minute for a single alert, with every live alert in
every tenant in the denominator.

**Why it became blocking rather than advisory.** The remediation for a *different* gate-2 defect
raised this endpoint's rate limit from `600/min` and `3000/min` to a shared
`config('bcms-gateways.webhook_rate_limit_per_minute')` of **10,000/min**, and defended the raise
on the reasoning that *"HMAC+timestamp verification rejects an unauthenticated caller cheaply
before business logic"*. Gate 2 tested that reasoning and it fails at both ends:

| End | What was true when gate 2 rejected | Now |
|---|---|---|
| **Rejection** | `StartSession` sat at position 2 of the resolved stack and the throttle at position 7, with `config/session.php:62` defaulting the driver to `database`. Every request the limiter was about to reject had **already written a session row to MariaDB** — up to 10,000 per minute per `{provider}+ip` bucket, from an endpoint carrying no credential. | **Closed** — see the verification below. |
| **Acceptance** | Worse than rejection. An *accepted* request runs the cross-tenant scan above. The ceiling meant to bound abuse of this endpoint instead permits ten times more of the module's single most expensive path. | **Open. This ADR.** |

**The rejection half was closed while this ADR was being written, and it was closed properly.**
Verified independently, 2026-09-12: `bootstrap/app.php:179` declares
`$middleware->group('bcms-webhook', [])` — an **empty** group — registered through the `then:`
closure at `bootstrap/app.php:45` in the same shape as `tprm-portal`, so the three gateway routes
in `routes/bcms-webhooks.php` never meet `web`'s auto-wrap at all. No `EncryptCookies`, no
`StartSession`, no `ValidateCsrfToken`. The two browser-facing cascade routes
(`bcms.cascade.ack`, `.store`), which a person opens from a link and which genuinely need a
session, kept the full `web` stack. That is the right split and not the minimum fix.

The surviving pre-throttle cost was checked rather than taken on trust, because *"rejection is
cheap"* has already been wrong once in this phase: the group's only middleware is the route's own
`feature:bcms`, and `App\Http\Middleware\EnsureFeatureEnabled` reads
`config("features.bcms")` and nothing else — **no database, no cache, no session.** A rejected
request now costs a router dispatch, one config array read and the throttle itself, at any ceiling.

So the ceiling question reduces cleanly to its second half, which is the half this ADR owns: **what
volume of *accepted* replies can this endpoint absorb** — and that depends entirely on §1.

The fix for the symmetry — the life-safety roll-call route had been given the *tighter* of the two
old limits — was correct and is preserved below. The number attached to it was not.

## Decision

### 1. The token carries its own row id. No column, no migration.

| | |
|---|---|
| **Decision** | The token format becomes `{prefix}-{decimal id}-{16 hex tag}`. The handler **parses the id, fetches that one row by primary key, and then verifies the tag**. The tag is the existing HMAC, unchanged. `bcms_alert_recipients` and `bcms_call_tree_test_nodes` gain **no column**. |
| **Schema impact** | **None.** BCMS Phase 7's structural-change count stays at **0**. |
| **Prefixes** | `r` for an alert recipient (`AlertDispatcher`), `c` for a call-tree test node (`CascadeEngine`). |

**Why not the stored, indexed column**, which gate 2 offered as the other shape and which would
also be O(1):

1. **It is a post-freeze structural migration on two tables, and a cheaper option exists.** The
   freeze is not a ban on columns; it is a rule that a column must be the thing that is actually
   needed. Something else is sufficient here, and it carries less state.
2. **It stores a secret.** Today a database read yields nothing usable without `app.key`: the token
   is recomputable only by someone holding the application key. A `reply_token` column makes every
   live token readable from a table the module's own NDPA register already treats as sensitive
   evidence. That is a security **regression** traded for an index, and it is the decisive
   argument rather than a tie-breaker.
3. **It adds a writer, a backfill and a uniqueness story** — a value minted on insert by an
   observer, a collision policy, and a backfill for existing rows — where the derivation has none
   of those. More moving parts for the same complexity class.

**Why the id in the token is not a weakening.** The tag is still an HMAC over the id under
`app.key` and is still compared with `hash_equals`, so possession of the id grants nothing. What is
newly exposed is an **ordinal**: a recipient row id, visible in a message body, from which an
observer can infer roughly how many alert recipients exist product-wide. That is an information
leak, it is real, and it is smaller than the one option A introduces by storing the secret itself.
It is recorded here rather than discovered later.

**The prefix is not decoration.** Today an alert-reply token and a cascade token are both bare
sixteen-hex strings and `extractToken()`'s `/\b([0-9a-f]{16})\b/i` cannot tell them apart — a
cascade token posted to the reply endpoint is distinguished only by a scan that fails. With a
prefix, a token presented to the wrong handler is rejected **by shape**, before any lookup. Two
token namespaces sharing one indistinguishable format was a latent defect; closing it costs one
character.

### 2. Nothing is live, so this is the last cheap moment to change the format

Verified 2026-09-12, and this is the load-bearing fact under §1:

| Check | Result |
|---|---|
| `BCMS_*` in `.env`, `.env.example`, `.env.testing` | **Not one variable set, in any of the three.** |
| `config/bcms-gateways.php` | Every `api_key` and every endpoint without a literal default resolves to `null`; `voice`, `push` and `ussd` providers are the placeholder strings `voice-tts`, `push-gateway`, `ussd-aggregator` |
| The NDPA register's own §5.2 | All ten processors recorded **"Not configured"** or **"Not chosen"** |
| Anything that persists a token | **Nothing.** Both tokens are derived at send time and stored in no column, no cache and no queue payload |

So **no token in any format has ever reached a handset**, and changing the format invalidates
nothing and requires no dual-read window. The day one channel is configured, a token in flight is a
token sitting in a person's inbox during an emergency, and the format acquires a compatibility
problem that has to be carried for the length of the longest alert window. **The window in which
this change is free closes when procurement picks an SMS vendor**, which makes this urgent rather
than merely permitted.

### 3. Verification stays constant-time in the secret, and that has to be engineered, not assumed

Fetching a row *before* verifying the HMAC is exactly where a lookup becomes an oracle. Three
leaks are possible and all three are closed explicitly:

| Leak | Closed by |
|---|---|
| **Timing** — "no such row" returns after one indexed `SELECT`; "wrong tag" returns after a `SELECT` plus an HMAC plus a compare | **Always compute the HMAC and always call `hash_equals`.** When no row is found, compute the tag for a fixed sentinel id and compare it against the presented tag, then discard the result. Both paths do one `SELECT`, one `hash_hmac`, one `hash_equals`. |
| **Response** — an unknown id, a wrong tag and a token for a closed alert answering differently | One outcome, one response. All three resolve to `null` and are recorded as **unmatched**, exactly as the scan does today. Status code, body and headers identical. |
| **Scope** — a row fetched by id that is outside the handler's open window | The open-window predicate is applied **in the fetch query**, not after it, so an out-of-window row is indistinguishable from a missing one and takes the sentinel path. |

`hash_equals` on the tag is retained and is what keeps the tag itself unrecoverable a character at
a time. What the sentinel path protects is **existence**, not the secret — an attacker who can
distinguish "recipient 48213 is open" from "no such recipient" can count the people in a live
alert, and a rate limit is not an answer to that.

**Each handler's eligibility rules are preserved exactly.** `InboundResponseHandler` resolves
recipients of alerts dispatched within two days and not yet acknowledged; `CascadeEngine` resolves
nodes of cascades that are still open, **including nodes that have already answered**, because a
second tap on a link must not report a dead link. This change alters **how a row is found and never
which rows are eligible.** A fix that quietly narrows either scope is a worse defect than the one
it replaces, and it would present as an acknowledgement that vanished.

### 4. The ceiling is priced on what an *accepted* request costs, and it is staged

The ceiling was never the defect; the cost model under it was. The rule, stated so the next raise
has something to be checked against:

> **The ceiling on an unauthenticated endpoint is the lesser of what its acceptance criterion
> demands with headroom, and what the most expensive thing an accepted request can cause will
> survive.** Before §1 lands, the second term is small and dominates. After it lands, the first
> term dominates and the raise is justified.

With the pre-throttle session write gone, the **rejection** side no longer enters this arithmetic
at all — it is a router dispatch and a config read, and it does not bound anything. Every number
below is therefore priced on the **accepted** path alone, which is the honest baseline and was not
available when gate 2 wrote its objection.

| Stage | `alert_reply` | `provider_status` | `cascade_inbound` | Why |
|---|---|---|---|---|
| **Now, until §1 lands** | **600/min** | **600/min** | **60/min** (unchanged) | An accepted request still runs a cross-tenant scan with an HMAC per row |
| **After §1 lands and its tests pass** | **2,000/min** | **10,000/min** | **600/min** | Derived below |

600 is the number the *more* dangerous of the two EMNS routes already carried before the
remediation, so the interim is a regression on neither. The single shared key is kept at this
stage, which preserves the symmetry fix in full: the life-safety route is never given the tighter
limit. `bcms/cascade-inbound` keeps its bare `throttle:60,1` for now — it reaches
`CascadeEngine::nodeForToken()`, the *second* instance of the same scan, and 60/min is the only
number on this endpoint that was never too generous for it.

**Say plainly what the interim costs, because it is not a resting state.** At 600/min against a
scan over a thousand open recipients, the accepted path is still hundreds of thousands of model
hydrations and HMACs per minute, and **AC 1 — a thousand people answering inside sixty seconds —
is not met at any interim ceiling.** No number bounds a scan into acceptability; only §1 does. The
interim is damage limitation with a countdown attached, and the countdown is §1.

**The derivation of the post-fix numbers, because "10,000" was a number and not an argument.**

- `alert_reply` serves **AC 1: 1,000 people answering inside 60 seconds**. Doubling it to
  **2,000** covers gateway retries and a person who replies on two channels. 10,000 on this route
  was eight thousand requests per minute of headroom nothing asked for.
- `provider_status` serves **AC 2: 10,000 recipients in a dispatch**, one delivery receipt each,
  worst case arriving inside one minute. **10,000** is that criterion, and it is the one place the
  number was already right.

So the two routes get **two keys**, and the guard against the original defect moves from *"they
share a constant"* to the invariant that actually mattered: **the life-safety route's ceiling is
never below AC 1's demand.** A shared constant was a blunt way of stating an invariant; stating the
invariant and asserting it is better, and it is what lets the two numbers differ for a reason.

**The cost check behind those numbers, and its honest limit.** After §1 the per-request cost stops
being a scan and becomes one indexed `SELECT`, one `hash_hmac`, one `hash_equals`, and then the
**write the handler performs** — the acknowledgement update and whatever audit row follows it. That
write is then the dominant term, and 10,000/min on `provider_status` is a sustained write rate, not
a read rate. **No throughput figure is asserted here, because none has been measured.** The number
is justified by AC 2's demand; whether the write survives it is an NFR question owned by
`reliability-engineer`, and it is the one thing in this section that could move a number after
measurement rather than after argument.

**The precondition this section used to carry is now satisfied, and it stays written down.** This
ADR was drafted while the CSRF fix was in flight, and it made the raise conditional: *if the
minimum fix were taken — a CSRF exception with the routes remaining in `web` — the ceilings would
stay at 600/min permanently regardless of §1, because a limiter positioned behind a database write
cannot bound that write.* The thin group landed instead (see the Context), so the condition is
**met** and the raise is available.

It is recorded rather than deleted because it is the standing rule, not a note about one week: **a
ceiling is only a ceiling on the work that happens after it.** The day anything is added ahead of
the throttle in the `bcms-webhook` group, the numbers in this section stop being justified and
revert to the interim row until it is removed. §5 is what that rule looks like as a check.

### 5. `throttle` runs before anything that writes — and that is now a test, not an intention

The shape has landed (Context). What has **not** landed is anything that keeps it, and an empty
middleware group is one careless append away from not being empty. The property, stated as the
property rather than as the group:

> In the `bcms-webhook` group, nothing that touches a database, a cache or a session may run
> **ahead of** the route's throttle.

`qa-engineer` owns a guard test asserting it, and it must assert the **resolved** stack — the thing
that actually runs — rather than the group array, because the two differ and it was the resolved
stack that carried the defect. Resolve each of `bcms.cascade.inbound`, `bcms.alerts.reply` and
`bcms.alerts.provider-status` through the router and assert that `StartSession` (and any subclass
of it), `EncryptCookies`, `ShareErrorsFromSession`, `ValidateCsrfToken` and `ResolveTenant` appear
in **none** of the three, and that everything preceding `ThrottleRequests` is free of I/O —
`feature:bcms` reads `config('features.bcms')` and is the only thing that qualifies today.

The same test should assert the complement, because the split is the point and half a split is a
regression: `bcms.cascade.ack` and `bcms.cascade.ack.store` **keep** `StartSession` and
`ValidateCsrfToken`. A person opening a link from a message has a browser, a session and a real
CSRF token, and stripping those from the browser-facing half to make one test simpler would hand a
stranger somebody's acknowledgement.

### 6. One statement in the NDPA register becomes false, and this ADR does not fix it

`docs/compliance/ndpa-register.md` §5.1 describes the callback token as *"sixteen hex characters,
minted per recipient by `AlertDispatcher::tokenFor()`, opaque and not derived from any personal
identifier"*. Under §1 the first clause is wrong — the token is a prefix, a decimal row id and
sixteen hex characters. The last clause stays true: a row id is not a personal identifier.

**This ADR does not edit that file.** `compliance-analyst` owns it this cycle and is already
correcting two other statements in it; this is a **third correction handed to that agent**, not a
concurrent edit to a file another gate is holding. The register should also note the ordinal
exposure recorded in §1.

## What this ADR deliberately does not do

**It does not add a column to `bcms_alert_recipients` or `bcms_call_tree_test_nodes`.** §1. The
Phase 7 structural-change count stays at **0**, and the freeze's trend — 15 → 8 → 5 → 1 → 0 — is
not broken by a defect that had a no-column answer available.

**It does not shorten, lengthen or re-key the HMAC tag.** The tag is the same sixteen hex
characters over the same input under the same key. Only the framing around it changes, which keeps
the diff reviewable and means nobody has to re-argue the security margin.

**It does not fix `recipientForNumber()`**
(`app/Services/Bcms/Emns/InboundResponseHandler.php:295-318`). Matching a bare reply to a phone
number uses `LIKE '%{tail}'` — a leading wildcard, therefore also unindexable, and also untenanted
on a webhook. It is a **real finding and it is not this one**: it is bounded by the database rather
than by PHP model hydration, it is capped at two rows by its own `limit(2)`, and it is not the path
AC 1's thousand replies take. Its fix is a stored reversed-tail or normalised-E.164 column, which
is a schema decision that must be argued on its own evidence in **Phase 8** — not smuggled in
beside this one because the file was already open.

**It does not change what a token means, what it authorises, or which rows resolve.** §3.

**It does not introduce a token version field, a dual-read window or a migration path.** §2:
nothing is live, so there is nothing to be compatible with. The `r`/`c` prefix is the extension
point if a future change ever does need one.

**It does not rule on the log-sink or channel-adapter work** running concurrently in
`app/Services/Bcms/Notification/Channels/`, and it does not touch `docs/compliance/ndpa-register.md`
(§6), `docs/tprm/` or any application file.

## Alternatives rejected, in one line each

| Alternative | Why not |
|---|---|
| A stored, indexed `reply_token` column | A post-freeze migration on two tables that also **stores the secret** a database read currently cannot recover, in exchange for a complexity class the derivation already reaches |
| Keep the scan and just lower the ceiling | Caps the abuse and leaves the legitimate load: AC 1's thousand genuine replies are exactly the traffic the scan cannot serve |
| Keep the scan and bound it by tenant | There is no tenant on a webhook — that is the defect, not a lever. Resolving one would mean trusting an unauthenticated body to name it |
| Token = id with no tag, relying on the rate limit | Turns a forgery problem into a guessing-rate problem on a life-safety acknowledgement; a wrong answer tells a crisis manager someone is out of a building they are still in |
| Verify the tag before fetching the row | That is the current design, and verifying a tag you cannot bind to a row *is* the scan |
| Fetch the row, return early when it is missing | The oracle §3 exists to close: "no such recipient" and "wrong signature" would differ by one HMAC's worth of time |
| A UUID token with a `uuid` column | A column plus a stored secret plus 36 characters in an SMS body, to replace a derivation |
| Keep one shared ceiling at 10,000 after the fix | Grants `alert_reply` eight thousand requests per minute that no criterion asks for; the invariant to preserve was "life-safety is never tighter", not "the two are equal" |
| Raise the ceiling on the strength of cheap rejection | It was not cheap — `StartSession` at position 2, throttle at position 7 — and now that it **is** cheap the argument is still wrong: a ceiling is priced on what an *accepted* request costs, and rejection cost bounds nothing |
| Treat the interim 600/min as meeting AC 1 | It does not, and no interim number could. A thousand replies a minute against a scan is the criterion the scan cannot serve at any ceiling |
| Leave `bcms/cascade-inbound` out of this ruling because gate 2 named only the EMNS routes | It reaches `CascadeEngine::nodeForToken()`, which is the same defect on a second table; fixing one and not the other leaves the argument to be re-derived |
| Fix `recipientForNumber()`'s leading-wildcard `LIKE` here too | A different defect with a different fix and its own schema question; Phase 8, with evidence |
