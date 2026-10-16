# ADR 0028 — A TPRM audit row written by a vendor says `portal`, every vendor write says so, and no reader re-spells a sealed value

**Status:** Accepted (amended 2026-10-14) · **Date:** 2026-10-05 · **Phase:** post-go-live hardening (`fix/post-golive-hardening`), TPRM Phase 0 / Phase 8 (`tp_audit_logs`, the vendor portal) · **Author:** architect
**Requested by:** the coordinator, post-go-live defect 3 (`actor_type`: `'portal'` against `'portal_user'`)
**Consumers:** backend-engineer **(lead: model constants, one trait change, four portal writers, the §3.1 payload sites, three guard tests)** · qa-engineer · code-reviewer · reliability-engineer (one read-only VPS check) · compliance-analyst (what the TPRM trail can say about a vendor on a supervisory export, §7; a `portal` row identifies the vendor by `actor_id` and never by a sealed email, §2) · TPRM track (the booked chain verifier and supervisory export read this contract)

> **Amended 2026-10-14, before this ADR ever shipped.** code-reviewer rejected the
> 2026-10-05 text. Its §2 put the vendor's email into `tp_audit_logs.actor_label`. That field
> is in `AuditLog::HASHED_FIELDS`, and ADR 0025 forbids re-sealing. On production that email
> could therefore **never be erased**, not even under an NDPA erasure request. Before this
> ADR, the trait rows for `Incident`, `Document`, `ThirdParty`, `Assessment` and `Finding`
> held no vendor email at all. The ADR would have added one to every such row a vendor
> caused. **The user decided:** a `portal` row's `actor_label` is **always `NULL`**. The
> vendor is identified only by `actor_id` → `tp_portal_users`. An erasure request is met by
> pseudonymising that `tp_portal_users` row, and the sealed trail is left alone. The
> amendment also settles reviewer advisories A2 (§2, the `user` row's label claim), A3 (§3,
> `portal_signed_in` / `portal_signed_out`) and A4 (§7, synchronous side effects). Sections
> changed: this note, the header, §2, §3, §4.2, §6, §7, the alternatives table, "does not
> do" and the implementation notes. §1, §5, the hash recipe, `HASHED_FIELDS` and the schema
> are unchanged.
>
> **Extended the same day, at the coordinator's request.** `before` and `after` are sealed
> too. `PortalAuthService` writes `portal_mfa_code_send_failed` with
> `after = ['email' => $user->email]`, and that payload is just as un-erasable as a label.
> The id-only decision therefore applies to **payloads** as well as to `actor_label`:
> **from now on, no `tp_audit_logs` row about a vendor carries the vendor's email anywhere**
> (§2, §3.1, §6.7). Rows already sealed keep what they hold.

## Context

### Two spellings, and only one of them is ever written

| Where | Says |
|---|---|
| `AuditLog::ACTOR_TYPES` (line 78) and the model docblock (line 29) | `user`, `portal_user`, `system` |
| `2026_09_06_110009` comment on the column (line 300) | `user \| portal_user \| system` |
| TPRM TRD, `tp_audit_logs` data model | `actor_type (user, portal_user, system)` |
| **Every writer:** `PortalIncidentService:105`, `VendorIdentityService:168`, `TrustProfileService:294`, `PortalAuthService:494` | **`portal`** |
| `2026_10_03_100001` `EXCLUDED` (line 76) | `'user', 'system' or 'portal'` |
| `PortalAuditUserAgentTest:115` | asserts `'portal'` |

**Nothing writes `portal_user`.** I grepped `app/`, `database/` and `tests/` on 2026-10-05.
**Nothing reads `ACTOR_TYPES` either**: its only occurrence is its declaration.

### Every reader

There are **no application readers** of `tp_audit_logs.actor_type`. This repeats ADR 0025
§2a's finding for the payload, and I re-checked it for this column. No presenter,
controller, grid, filter, export, board pack, regulator pack, AI prompt or portal screen
reads `tp_audit_logs` at all. `TprmAuditable::auditLogs()` is used only by tests. Every
application reference to `Tprm\AuditLog` is a `create()`. The references are the four
portal writers, `TprmAuditable`, `IntakeService`, `EvidenceService`, `AccessService`,
`ClauseAnalyzer`, `NotificationDraftService` and `ObligationClockService`. BCMS's
`DrService` and `BoardPackService` write `Bcms\AuditLog`, which is a different table. The
readers that exist are tests and the morph-rewrite migration's documentation. The readers
that *will* exist are the TPRM chain verifier and the supervisory export, both booked by
ADR 0025.

### A vendor's model writes are recorded as `system`

`TprmAuditable::writeAuditRow()` sets
`'actor_type' => auth()->user() !== null ? 'user' : 'system'`. A vendor authenticates on
the **`tprm-portal`** guard (`config/auth.php:58`). That guard has its own session, so on a
portal request `auth()->user()`, which uses the default `web` guard, is `null`. Every
audited model a vendor writes through the portal is therefore recorded as **`system`**:

- `PortalIncidentService:46` `Incident::create(...)` writes a `created` row by `system`, then
  an explicit `incident_reported_by_vendor` row by `portal`, for the same act;
- `VendorIdentityService:126` `$thirdParty->forceFill(...)->save()` (a `ThirdParty` update);
- `PortalEvidenceService` → `EvidenceService::store(..., 'portal')`, which creates a
  `Document`.

The model docblock gives the reason `actor_type` exists: *"A vendor's action recorded as a
colleague's would misrepresent the record on exactly the export where it matters most."*
Recording it as the platform's own action misrepresents it as well. **This is the same
field, and the same question of how writers align**, so it is decided here and not
booked.

### The constraint

`actor_type` is in `HASHED_FIELDS`, and the chain is sealed (ADR 0025). **No existing row
is ever rewritten**, by this ADR or by anyone.

## Decision

### 1. The canonical value is `portal`

| Constant on `App\Models\Tprm\AuditLog` | Value |
|---|---|
| `ACTOR_USER` | `'user'` |
| `ACTOR_PORTAL` | `'portal'` |
| `ACTOR_SYSTEM` | `'system'` |
| `ACTOR_TYPES` | `[self::ACTOR_USER, self::ACTOR_PORTAL, self::ACTOR_SYSTEM]` |

**Why `portal` and not `portal_user`.** The chain makes the stored spelling permanent. Every
row any environment holds says `portal`, and no code path has ever written `portal_user`.

- **Choosing `portal`** changes one constant and two comments. Every historic row is already
  canonical, and no reader ever needs to know about a second spelling.
- **Choosing `portal_user`** would leave the estate with two spellings **for ever**. Every
  future reader (the verifier, the supervisory export, any filter) would carry an alias
  rule, and anyone who forgot it would show a vendor's sign-in as an unknown actor.

The extra precision of `portal_user` buys nothing. §2 defines what `actor_id` means for a
`portal` row.

**This deviates from the TRD.** The TRD's `tp_audit_logs` data model says `portal_user`. The
repository wins and the deviation is written down here, as ADR 0007 established for BCMS.
The model docblock and the `2026_09_06_110009` column comment are corrected to `user |
portal | system` and cite this ADR. **The migration change is comment-only.** No code in that
migration changes, and it has already run everywhere.

### 2. The actor contract, for rows written from now on

| `actor_type` | `actor_id` | `actor_label` |
|---|---|---|
| `user` | `users.id` | **Trait rows** (`TprmAuditable`): the user's name, through `AuditText::clip(…, 200)`. **Explicit writers:** see the A2 note below. The label is not guaranteed for them today. |
| `portal` | `tp_portal_users.id` **of the vendor**: the authenticated one, or the one just authenticated or signing out (§3, A3). `null` **only before authentication** | **`NULL`, always** |
| `system` | `null` | `null` |

- **Why a `portal` row carries no label (amended 2026-10-14).** `actor_label` is in
  `HASHED_FIELDS`, and the chain is sealed (ADR 0025 §3). Anything written there can never
  be changed or removed without breaking the chain. That is the point of the chain, and it is
  why personal data must not go there. A vendor's email in that field could not be erased
  under an NDPA erasure request. A **`tp_portal_users.id` is a pseudonymous key**. It
  identifies a person only through the `tp_portal_users` row, and that row is **not** sealed.
  An erasure request is therefore met by pseudonymising that row's identifying columns. Every
  sealed `portal` row then still says *which* vendor account acted, and no longer says *who*
  it was. The trail is never touched. The detailed pseudonymisation
  procedure belongs to the TPRM track and compliance-analyst. This ADR fixes only the trail's
  side of it.
- **Rows written before this ADR** have `portal` with `actor_id` and `actor_label` both
  `null`. The vendor is identified in the payload (`after.email`,
  `after.reported_by_email`) or by the auditable (`PortalUser`). That stays true, and §4
  tells readers to use it. **Those historic payload emails are sealed and stay.** Production
  has run TPRM since go-live, so they exist there. compliance-analyst records them in the
  NDPA register as un-erasable.
- **No vendor email in a payload either (extended 2026-10-14).** `before` and `after` are
  in `HASHED_FIELDS` just as `actor_label` is. So the same reasoning applies: **no
  `tp_audit_logs` row written from now on carries a vendor's email in `before` or
  `after`.** That covers rows written by a vendor, and rows written about one, such as
  `portal_invited` by an internal user. A payload that needs to name the vendor carries
  `portal_user_id` (or, for an invitation, relies on `auditable_id`, the
  `tp_portal_invitations` row). It never carries the address. §3.1 lists the sites.
- **Pre-authentication portal events** (`PortalAuthService`: failed sign-in, lockout, code
  sent) keep `actor_id` `null`. Nobody has proven they are that vendor yet, so naming one as
  the actor would attribute an attempt to its target. The subject is the auditable.
- **A2: what the `user` row really guarantees.** The statement "the label is the user's
  name" is true **only for trait rows**. The explicit writers do not meet it today:
  - `AccessService:147`, `IntakeService:152`, `ClauseAnalyzer:177` and
    `EvidenceService:170` write `actor_type`/`actor_id` and **no `actor_label`**;
  - `NotificationDraftService:305` writes `ACTOR_USER` with
    `actor_id => $after['approved_by'] ?? $after['submitted_by'] ?? null`, so a `user` row
    **can have a null `actor_id`**.

  Readers (§4) must therefore treat a `user` row's label as optional, and must not assume a
  `user` row has a non-null id. Aligning those five writers (a label for the four, and
  `ACTOR_SYSTEM` or a guaranteed id for `NotificationDraftService`) is **booked to the TPRM
  track as a follow-up**. It is not changed here: this ADR is about the vendor, and those
  writers are not reachable from a portal route.

### 3. How each writer aligns

| Writer | Change |
|---|---|
| `TprmAuditable::writeAuditRow()` (`resolveAuditActor()`) | Resolve the actor **portal first**. If `Auth::guard('tprm-portal')->hasUser()`: `ACTOR_PORTAL`, the portal user's id, and **`null`** for the label. **Amended 2026-10-14:** `TprmAuditable.php:168`, `AuditText::clip($portalUser?->email, 200)`, becomes `null`. Else if `auth()->user()`: `ACTOR_USER`, as today. Else `ACTOR_SYSTEM`. **`hasUser()`, not `user()`.** It reads only what the portal middleware already resolved on this request. It never starts a session lookup in a queue worker, a console command or a migration, and those stay `system`. **Portal first** because the error the docblock names (a vendor recorded as a colleague) is the one that must never happen. |
| `PortalIncidentService::auditReceipt`, `VendorIdentityService::audit`, `TrustProfileService::audit` | `ACTOR_PORTAL`, with `actor_id => $user->getKey()` and **`actor_label => null`**. Each one already receives the authenticated `PortalUser`. I checked every caller in `TprmPortal\*Controller`. **Amended 2026-10-14:** the label sites `PortalIncidentService.php:108`, `TrustProfileService.php:297` and `VendorIdentityService.php:171` (`AuditText::clip($user->email, 200)`) become `null`. If that leaves an `AuditText` import unused in a file, remove it. Do not remove it where `AuditText::userAgent()` or another use remains. |
| `PortalAuthService::audit`, pre-authentication events (failed sign-in, `portal_account_locked`, code sent) | `$userId !== null ? ACTOR_USER : ACTOR_PORTAL`, `actor_id` `null`, `actor_label` `null` (§2). |
| `PortalAuthService`, **`portal_signed_in`** (line 430) and **`portal_signed_out`** (line 447): **A3, decided** | **They carry the portal user's id:** `ACTOR_PORTAL`, `actor_id => $user->getKey()`, `actor_label => null`. Today both write `actor_id` `null`, which is wrong. **`portal_signed_in`** is written after `Auth::guard('tprm-portal')->login($user)` and after the MFA marker is set, so the vendor has proven who they are by then. **`portal_signed_out`** captures `$user` from the guard *before* `logout()`, so it is the vendor who was authenticated on this request. In both cases the auditable is that same `PortalUser`, so naming it as the actor attributes nothing new. The pre-authentication rule in §2 exists to stop an *attempt* being pinned on its *target*, and neither of these is an attempt. **Implementation:** give `PortalAuthService::audit` an explicit `?int $portalUserId = null` parameter (or an equivalent second private method) that sets `actor_type = ACTOR_PORTAL` and `actor_id = $portalUserId`. Pass it from those two call sites only. **Do not** derive it from `Auth::guard('tprm-portal')->hasUser()` inside `audit()`: at `portal_signed_out` the guard is already logged out, and at the pre-authentication sites the guard must never be the source. |
| `IntakeService`, `EvidenceService::logAccess`, `AccessService`, `ClauseAnalyzer`, `NotificationDraftService`, `ObligationClockService` | Literals become constants. **No change in behaviour.** None of them is reachable from a portal route. `logAccess` has one caller, the internal `Tprm\DocumentController:185`. Their missing labels and `NotificationDraftService`'s possibly-null `user` id are booked (§2, A2). They are not fixed here. |

The resolution order in the trait is **unchanged by the amendment**: portal first, then
user, then system. Only the label a `portal` row carries has changed.

#### 3.1 Payloads carry the portal user's id, never the email (extended 2026-10-14)

The coordinator's named site is **`PortalAuthService.php:281`, `portal_mfa_code_send_failed`**.
Its `after` changes from `['email' => $user->email]` to `['portal_user_id' => $user->getKey()]`.

The same rule binds **every** portal-related payload. I grepped `app/Services/Tprm/Portal/` on
2026-10-14. These are the sites that put an email into `before`/`after`, and each one changes
the same way: drop the email key, and add `portal_user_id` where the row is about a portal
user:

| Site | Event | Change |
|---|---|---|
| `PortalAuthService.php:64` | `portal_invited` (written by an internal user; auditable `PortalInvitation`) | Drop `email`. The invitation is the auditable. No portal user exists yet. |
| `PortalAuthService.php:137` | `portal_account_created` | `email` → `portal_user_id` |
| `PortalAuthService.php:282` | `portal_mfa_code_send_failed` | `email` → `portal_user_id` |
| `PortalAuthService.php:299` | `portal_mfa_code_sent` | `email` → `portal_user_id` (keep `expires_at`) |
| `PortalAuthService.php:383` | `portal_mfa_enrolled` | `email` → `portal_user_id` |
| `PortalAuthService.php:431` | `portal_signed_in` | `email` → `portal_user_id` |
| `PortalAuthService.php:448` | `portal_signed_out` | `email` → `portal_user_id` |
| `PortalAuthService.php:466` | `portal_account_locked` | `email` → `portal_user_id` (keep `attempts`) |
| `PortalIncidentService.php:113` | `incident_reported_by_vendor` | `reported_by_email` → `reported_by_portal_user_id` |
| `TrustProfileService.php:299` | every `portal_trust_profile_*` event (`audit()` appends it) | drop `+ ['email' => $user->email]` |
| `VendorIdentityService.php:173` | `portal_identity_claimed` (`audit()` appends it) | drop `+ ['email' => $user->email]` |

- **This list is the floor, not the ceiling.** compliance-analyst is listing other places
  where vendor personal data reaches a payload (for example names, or an email copied into
  a trait row's `after` through a model attribute). Any site they find follows the same rule
  without a further ADR. §6.7 is the backstop that catches what a grep misses.
- `PortalUser` is not `TprmAuditable`, so its own attribute saves write no trait rows. If that
  ever changes, `email` must be excluded from its audited attributes.
- `VendorIdentityService:124` derives `primary_domain` from the email. A domain is an
  organisation's, not a person's, so it is not caught by this rule.
- `PortalAuthService::audit`'s pre-authentication rows keep `actor_id` null (§2). The
  `portal_user_id` in their payload names the **subject**, the account the attempt was made
  against. It does not name the actor, so the rule in §2 still holds.

### 4. Readers: compare to the constants, never re-spell

There are no application readers to change. This is the rule the booked verifier and
export, and any later filter, are built to:

1. Compare `actor_type` against `AuditLog::ACTOR_*` only.
2. For `portal`, **never read `actor_label`**. It is null by contract. Resolve the vendor by
   joining `actor_id` to `tp_portal_users` at read time. Show the vendor's current
   (possibly pseudonymised) identity, not a copy taken at write time. When `actor_id` is null
   (historic rows, pre-authentication events), take the identity from the auditable or the
   payload (§2), and say "vendor (portal)" when neither names one. For `user`, treat the
   label as optional and the id as possibly null (§2, A2).
3. **A value outside `ACTOR_TYPES` is shown verbatim and flagged as unrecognised. It is
   never mapped onto a known value.** Only SQL run around the application can produce one,
   and the chain covers it. A reader that quietly turned `portal_user` into `portal` would
   tidy away the trace of exactly the write the chain exists to expose.

### 5. Nothing stored changes

- No row is rewritten, re-sealed or deleted. `HASHED_FIELDS`, `HASH_RECIPE` and the
  recipe-pin literal (`AuditLogHashRecipeTest`) do not change.
- No migration runs, and the schema is untouched. `actor_type` stays `string(20)` with no
  enum and no check constraint. A constraint is a structural change, and it would turn a
  bad spelling into a failed insert. `TprmAuditable` swallows that failure, so the audit row
  would be lost while the business write commits. ADR 0025 §2a(b) rejected the same trade.
  The guard is a test (§6.2).

### 6. Tests

1. **`tests/Unit/Tprm/AuditLogActorTypesTest.php`:**
   `assertSame(['user', 'portal', 'system'], AuditLog::ACTOR_TYPES)`, and each constant's
   literal.
2. **Guard, `tests/Unit/Tprm/AuditActorTypeLiteralsTest.php`.** Scan every PHP file under
   `app/` that imports `App\Models\Tprm\AuditLog`, or is `TprmAuditable`. Every line that
   matches `'actor_type'\s*=>` must contain **no quoted string literal** on its right-hand
   side. Writers use `AuditLog::ACTOR_*`. The failure message names this ADR. **Prove once,
   by hand, that it fails** on today's code: there are eleven sites, all literals.
3. **`tests/Feature/TprmPortal/PortalActorAttributionTest.php`**, through the real portal
   routes (amended 2026-10-14):
   - An authenticated vendor reports an incident. **Both** rows, the trait's `created` on
     `Incident` and `incident_reported_by_vendor`, have `actor_type = 'portal'`,
     `actor_id = $portalUser->id` and **`actor_label` null**. Both are `isIntact()`. This
     is the both-guards test: the vendor is on `tprm-portal`, and the default guard is
     empty.
   - **Both guards authenticated** (a `web` user and a `tprm-portal` vendor in the same
     request): the trait row is `portal`, with `actor_id = $portalUser->id` and
     **`actor_label` null**. Portal first.
   - An authenticated vendor uploads evidence. The `Document` `created` row is `portal`,
     with `actor_id = $portalUser->id` and `actor_label` null.
   - A vendor edits their identity (`VendorIdentityService`) and their trust profile
     (`TrustProfileService`). Each explicit row has `actor_id = $portalUser->id` and
     `actor_label` null.
   - An internal user creates an incident on an internal route. The row is `user`, with
     their id and name (a trait row, §2).
   - The same model saved in a console context (`Artisan::call` of a closure command, or a
     job run synchronously) gives `system`, with null id and label.
   - **No sealed email:** for every row above, no column, payload included, contains
     `$portalUser->email` (see §6.7 for the full sweep).
4. **`PortalAuditUserAgentTest`** stays as it is (`'portal'`). For a **failed sign-in** or
   **lockout** row, `assertNull($row->actor_id)` pins §2's pre-authentication rule. For
   **`portal_signed_in`** and **`portal_signed_out`** (A3), assert
   `actor_id = $portalUser->id` and `actor_label` null. These two fail on today's code.
5. **Guard: no portal row ever carries a label.** Add a feature test, or extend
   `PortalActorAttributionTest`, that drives every portal write path the suite already
   exercises: sign-in, sign-out, incident, evidence, identity, trust profile. Then assert
   `AuditLog::where('actor_type', AuditLog::ACTOR_PORTAL)->whereNotNull('actor_label')->doesntExist()`.
   It is a backstop that does not depend on knowing every writer.
6. **Guard: `AuditText` is never applied to a portal email.** Extend
   `AuditActorTypeLiteralsTest` (same file scope as §6.2) with a static scan. No line in
   those files may match `AuditText::clip\(\s*\$\w+\??->email` or, more broadly, pass any
   `->email` expression on a line that is an `'actor_label' =>` entry. The failure message
   names this ADR and the erasure reason. **Prove once, by hand, that it fails** on the
   current working tree: it must hit the four sites in §3
   (`TprmAuditable.php:168`, `PortalIncidentService.php:108`, `TrustProfileService.php:297`,
   `VendorIdentityService.php:171`).
7. **Guard: no vendor email anywhere in a portal-flow row (extended 2026-10-14).** In a
   feature test, give the portal user a distinctive address (for example
   `erasable.vendor+0028@example.test`). Drive every portal flow the suite can reach:
   invitation, account creation, MFA code sent, MFA code send failure (mail transport made
   to throw), MFA enrolment, sign-in, failed sign-in to lockout, sign-out, incident report,
   evidence upload, identity claim, and each trust-profile event. Then assert that **no**
   `tp_audit_logs` row contains the address, case-insensitively, in `actor_label`, `before`
   or `after`. Compare on the **raw stored column text**, not the decoded array, so a nested
   or re-keyed copy is caught on both engines. It must fail today: §3.1's sites all write
   it. Name this ADR and the erasure reason in the failure message.
8. **`AuditLogHashRecipeTest`** and **`AuditChainCrossEngineTest`** pass unchanged. If the
   recipe pin moves, something leaked into `chainHash()`.

### 7. Read-only check on the VPS (reliability-engineer, before deploy)

```sql
SELECT actor_type, COUNT(*) AS n,
       SUM(actor_id IS NULL) AS without_id
  FROM tp_audit_logs GROUP BY actor_type;
```

The expected result is a subset of `user`, `portal` and `system`. **Any other value, including
`portal_user`, means SQL was run around the application.** Stop and bring the rows to the
architect, without touching them. A `system` count is expected. Rows a vendor caused before
this ADR are among them, and they stay as they were sealed. The supervisory export (booked)
must say that a TPRM trail written before 2026-10-05 records vendor model writes as
`system`. That is compliance-analyst's wording to settle.

Add a second read-only check, which should return 0 before deploy and stay 0 after:

```sql
SELECT COUNT(*) FROM tp_audit_logs
 WHERE actor_type = 'portal' AND actor_label IS NOT NULL;
```

A non-zero count means the 2026-10-05 text was deployed somewhere. Stop and bring the rows
to the architect. They are sealed and are **not** to be touched. The record of them becomes
an input to the NDPA register entry.

**A4: synchronous side effects are attributed to the vendor.** The trait resolves the actor
from the request, not from the code path. So **every** audited model write that runs
synchronously inside a portal request is recorded as `portal` with that vendor's id. That
includes writes the vendor did not choose directly: a model saved by an observer, a listener
run synchronously, or a service the portal controller calls. This is the same rule the
`user` path has always followed for internal requests. Work that is **queued** runs in a
worker with no portal guard, so it is recorded as `system`. The supervisory export must not
describe a `portal` row as "an action the vendor took by hand". The wording is "a change
made in the course of the vendor's portal request". compliance-analyst settles the final
phrasing.

## Alternatives rejected

| Option | Verdict |
|---|---|
| **`portal_user` canonical; writers change; readers accept both spellings** | **Rejected.** It creates a permanent second spelling in a sealed table to match a plan document, and pushes an alias rule into every future reader. No row has ever said `portal_user`. |
| **Normalise on read** (`portal` ⇄ `portal_user` in an accessor) | **Rejected.** An accessor changes what `$row->actor_type` returns, but the digest is over the raw attribute, so the model would show one value and seal another. It would also make a raw-SQL `portal_user` look legitimate (§4.3). |
| **Rewrite historic rows to the canonical spelling** | **Forbidden** (ADR 0025 §3). It is also unnecessary, because the canonical value is the one they hold. |
| **A database enum or check constraint on `actor_type`** | **Rejected** (§5). It is a structural change after the freeze, and it would make a bad spelling lose the audit row silently. |
| **A backed enum cast (`App\Enums\Tprm\AuditActorType`) on the column** | **Rejected for now.** The model's attributes feed `chainHash()` raw, and a cast on a hashed field adds a way for the stored and sealed values to diverge, for no reader's benefit today. Constants give the single source of truth and the guard test enforces it. The booked verifier may introduce an enum for *reading*. |
| **The vendor's email in `actor_label`** (the 2026-10-05 text) | **Rejected on review, 2026-10-14, by the user's decision.** `actor_label` is sealed (`HASHED_FIELDS`, ADR 0025). A sealed email can never be erased, and it would have put an email into trait rows that held none before. `actor_id` → `tp_portal_users` gives the same attribution through a row that *can* be pseudonymised. |
| **A label that is not personal data** (for example `vendor #42`, or the third party's name) | **Rejected.** It duplicates `actor_id` in a second sealed field, and a third party's name can itself identify a sole trader. The reader joins at read time instead (§4.2). |
| **`portal_signed_in` / `portal_signed_out` with `actor_id` null, documented** (A3) | **Rejected.** The vendor is authenticated at both points, and the auditable is that same `PortalUser`. A null id would make a sign-in look like a pre-authentication attempt. |
| **Leave `TprmAuditable` as `user`/`system`, and book the vendor attribution** | **Rejected.** It is the same field and the same "writers align" question. Booking it would leave every vendor model write from today on sealed permanently as `system`. |

## What this ADR deliberately does not do

- It does not build the TPRM chain verifier or the supervisory export (ADR 0025, booked). It
  fixes the contract they read.
- It does not attribute **signed-URL document downloads** (`DocumentController:185` with a
  null user), which are recorded as `system`. That is a question about anonymous access by
  link, not about the portal. It is booked to the TPRM track if it matters for FR-EVD-03.
- It does not touch `bcms_audit_logs` (a separate table) or the ERM trail.
- It changes no stored row, no recipe and no schema.
- It does not define the `tp_portal_users` pseudonymisation procedure that an erasure
  request triggers. That is booked to the TPRM track and compliance-analyst. This ADR only
  guarantees that the trail does not stand in its way through `actor_label`.
- It does not remove the vendor emails already sealed in historic `after` payloads
  (`after.email`, `after.reported_by_email`). They cannot be erased without breaking the
  chain. compliance-analyst records them in the NDPA register. **Future payloads are
  decided**: id, not email (§3.1).
- It does not align the explicit internal writers' labels or `NotificationDraftService`'s
  null `user` id (§2, A2). That is booked to the TPRM track.

## Implementation notes (backend-engineer)

- **Sequencing.** The working tree already holds uncommitted edits to `TprmAuditable`,
  `PortalAuthService`, `PortalIncidentService`, `TrustProfileService`,
  `VendorIdentityService`, `EvidenceService` and `ClauseAnalyzer` (the `AuditText`
  hostile-input fix, by another agent). This ADR's changes are written **on top of** that
  work, in their own commit after it, and touch only the `actor_*` keys and the trait's actor
  resolution. Do not fold either change into the other.
- `Auth` is `Illuminate\Support\Facades\Auth`. The guard name is the literal
  `'tprm-portal'`. If a constant for it exists by the time you start, use it, and do not
  invent a second one.
- The portal user is resolved once per audit write. `hasUser()` adds no query.
- **Amendment, 2026-10-14.** The four label sites in §3 already hold the 2026-10-05 text in
  the working tree, uncommitted. Change them to `null` in place. Add the `portal_signed_in` /
  `portal_signed_out` id (§3, A3), the §3.1 payload changes and the three guards (§6.5,
  §6.6, §6.7) in the same ADR 0028 commit. Tests that currently assert `after.email` or
  `after.reported_by_email` on portal rows (grep `tests/` for them) change to
  `portal_user_id` / `reported_by_portal_user_id`. That is the contract changing, not the
  test being bent. Nothing has shipped, so there is no data to correct. The VPS check in §7 confirms
  that.
