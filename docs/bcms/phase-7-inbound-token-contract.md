# BCMS Phase 7 — the inbound token contract

**Status:** Frozen · **Date:** 2026-09-12 · **Author:** architect · **Governed by:** ADR 0016
**Consumers:** BCMS track · **Implemented by:** `backend-engineer`, then `qa-engineer` →
`code-reviewer` (gate order unchanged; the architect does not approve the architect's ruling)

Read ADR 0016 first — it carries the *arguments*. This document carries only the *shapes*, and
exists because gate 2 rejected Phase 7 on a defect whose fix needed a decision rather than a patch.

**Schema changes: none.** Phase 7 stays at **0** structural changes.

---

## 1. Token format

```
{prefix}-{decimal row id}-{16 lowercase hex tag}
```

| Prefix | Subject | Minted by | Resolved by |
|---|---|---|---|
| `r` | `App\Models\Bcms\AlertRecipient` | `AlertDispatcher::tokenFor()` | `InboundResponseHandler::recipientForToken()` |
| `c` | `App\Models\Bcms\CallTreeTestNode` | `CascadeEngine::tokenFor()` | `CascadeEngine::nodeForToken()` |

Example: `r-48213-9f2c1ab77d0e4b31` (illustrative; the tag is sixteen hex characters).

**The tag is unchanged.** It stays
`substr(hash_hmac('sha256', 'bcms-alert-'.$id, config('app.key')), 0, 16)` for a recipient and
`substr(hash_hmac('sha256', 'bcms-cascade-'.$id, config('app.key')), 0, 16)` for a node. Same
input, same key, same length. **Do not take the opportunity to change it** — the two existing
namespace strings already prevent cross-namespace replay, and re-arguing the security margin is
work this fix does not need.

**Extraction.** `InboundResponseHandler::extractToken()`'s `/\b([0-9a-f]{16})\b/i` is replaced by a
pattern that matches the prefixed shape and **only** the prefix it is looking for:

- recipient path: `/\br-(\d{1,12})-([0-9a-f]{16})\b/i`
- cascade path: `/\bc-(\d{1,12})-([0-9a-f]{16})\b/i`

Match case-insensitively and lower-case before comparing — some gateways upcase message bodies and
a USSD keypad has no case at all. A token whose prefix belongs to the *other* namespace must be
treated as no token, not as a failed lookup: that is the latent defect the prefix closes (ADR 0016
§1), and it is worth a test of its own.

---

## 2. Resolution, and the three ways it must not become an oracle

One shape, both call sites. `InboundResponseHandler::recipientForToken()` and
`CascadeEngine::nodeForToken()` are rewritten to this and to nothing else:

1. **Parse.** If the body carries no token of this namespace's shape, take the sentinel path (step
   4) and return `null`.
2. **Fetch by primary key, with the eligibility predicate in the query.** One indexed lookup.
   The predicate is **not** applied after the fetch — an out-of-window row must be indistinguishable
   from a missing one.
3. **Always compute the tag and always compare it.** When step 2 found nothing, compute the tag for
   a fixed sentinel id and `hash_equals` it against the presented tag anyway, then discard the
   result. Both paths perform exactly one `SELECT`, one `hash_hmac` and one `hash_equals`.
4. **Return the row only if a row was found *and* the compare succeeded.** Otherwise `null`.

**No early return between steps 2 and 4.** An `if ($row === null) return null;` is the timing oracle
this section exists to prevent, and it is the single most likely way this fix gets implemented
wrongly.

**The response must not vary.** An unknown id, a wrong tag, a token for a closed alert and a
malformed token all produce the same status, the same body and the same headers, and are recorded
as **unmatched**, exactly as the scan does today.

### Eligibility predicates — copied exactly, not improved

| Call site | Predicate | Note |
|---|---|---|
| `InboundResponseHandler` | `acknowledged_at IS NULL`, and the alert `dispatched_at IS NOT NULL AND >= now()-2 days` | Unchanged from `openRecipients()` |
| `CascadeEngine` | node's test `initiated_at IS NOT NULL AND completed_at IS NULL` | Unchanged. **Nodes that have already answered still resolve** — a second tap on a link must report the answer, not a dead link. Narrowing this is a worse defect than the one being fixed. |

This change alters **how a row is found and never which rows are eligible.**
`InboundResponseHandler::openRecipients()` remains for `recipientForNumber()`, which is out of
scope (§5).

---

## 3. Rate limits

`config/bcms-gateways.php`: the single `webhook_rate_limit_per_minute` is replaced by two keys.
`AppServiceProvider::registerBcmsWebhookRateLimiters()` reads one each. Keying stays
`{provider}+ip` — that part was right.

| Key | Interim (now) | After §2 lands and its tests pass | Derived from |
|---|---|---|---|
| `alert_reply_rate_limit_per_minute` | **600** | **2,000** | AC 1's 1,000 replies/minute, doubled for gateway retries and multi-channel duplicates |
| `provider_status_rate_limit_per_minute` | **600** | **10,000** | AC 2's 10,000-recipient dispatch, one receipt each, worst case inside one minute |
| `bcms/cascade-inbound` (bare `throttle:60,1`) | **60**, unchanged | **600** | Same rule; it reaches the second scan |

**The interim values ship first and the raise is a separate commit**, gated on §2's tests being
green. Shipping both together makes the raise unfalsifiable — nobody can tell afterwards whether
the endpoint survived because of the fix or in spite of the number.

**The invariant that replaces the shared constant** (ADR 0016 §4): the life-safety route's ceiling
is never below AC 1's demand. Assert it —
`alert_reply_rate_limit_per_minute >= 1000` once §2 has landed — rather than re-coupling the two
numbers, which is what stopped them differing for a reason.

---

## 4. Acceptance criteria for the re-gate

1. A reply carrying `r-{id}-{tag}` resolves the correct recipient with **one** query to
   `bcms_alert_recipients` and no full-table cursor. Assert the query count, not the wall clock.
2. The same, for `c-{id}-{tag}` and `CascadeEngine::nodeForToken()`.
3. A token with a valid id and a **wrong tag** resolves to `null`, is recorded as unmatched, and
   returns the same status and body as a token with an unknown id.
4. A token for a recipient **outside** the open window resolves to `null` and is indistinguishable
   from an unknown id.
5. A node that has **already answered** inside an open cascade still resolves (§2's table).
6. An `r-` token posted to the cascade endpoint, and a `c-` token posted to the reply endpoint, are
   treated as no token at all — rejected by shape, with no lookup.
7. **Cross-tenant probe.** A token minted for tenant A's recipient, posted to the webhook with no
   tenant resolved, resolves that recipient and **nothing of tenant B's** is read or written.
   `OrganizationScope` is inert here; the row id is the only thing that selects.
8. **No early return.** A static or reflective assertion that the resolver performs its
   `hash_equals` on every path, including the not-found path. A code-level check is acceptable and
   a timing assertion is not — a timing test on CI is a flake, not a guard.
9. Resolved-stack guard (ADR 0016 §5): `bcms.cascade.inbound`, `bcms.alerts.reply` and
   `bcms.alerts.provider-status` carry no `StartSession` (or subclass), `EncryptCookies`,
   `ShareErrorsFromSession`, `ValidateCsrfToken` or `ResolveTenant`, and nothing performing I/O
   precedes `ThrottleRequests`. **And the complement:** `bcms.cascade.ack` and
   `bcms.cascade.ack.store` still carry `StartSession` and `ValidateCsrfToken`.
10. The two rate-limit keys exist, the interim values are in force, and no code reads the deleted
    `webhook_rate_limit_per_minute`.

---

## 5. Out of scope, deliberately

- **`InboundResponseHandler::recipientForNumber()`** — the bare-reply-to-phone-number match, a
  `LIKE '%{tail}'` with a leading wildcard, therefore also unindexable and also untenanted. A real
  finding, **not this one**: it is bounded by the database rather than by PHP model hydration, it
  caps itself at two rows, and it is not the path AC 1's replies take. Its fix is a stored
  reversed-tail or normalised-E.164 column — a schema decision needing its own evidence and its own
  ADR, in **Phase 8**. Do not open it because the file is already open.
- **Any column on `bcms_alert_recipients` or `bcms_call_tree_test_nodes`.** ADR 0016 §1.
- **Any change to the HMAC tag**, its input, its key or its length. §1.
- **Any change to which rows are eligible.** §2.
- **A token version field, a dual-read window or a compatibility shim.** Nothing is live (ADR 0016
  §2); there is nothing to be compatible with, and building for a migration that cannot be needed
  is how the format acquires a second format.
- **`docs/compliance/ndpa-register.md`.** ADR 0016 §6 names the one statement that becomes false
  (§5.1, *"sixteen hex characters … not derived from any personal identifier"* — the first clause
  only). `compliance-analyst` makes that correction with the two it already owns. **Nobody else
  touches that file this cycle.**
- **The channel adapters and the log sink** in `app/Services/Bcms/Notification/Channels/`, under
  concurrent repair by another agent.
