# Phase 7 — EMNS: the real channel adapters

**Branch:** `feature/bcms-module` · **Schema changes: 0 tables, 0 columns** — no ADR
**Track C, weeks 7–11.** Depends on Phase 0 (the frozen `NotificationChannel`
interface), Phase 2C (**not built** — see §2) and Phase 6 (the call tree as an
audience type).

---

## 1. What this phase is

Phase 7 owns **every real channel adapter in the platform**. Phase 5's reminder
dispatcher and Phase 6's cascade engine both run on what is here, and neither
changed a line — which is what ADR 0004 froze the interface for in Week 1.

Eight adapters, a three-gateway SMS pool with health-based failover, the alert
lifecycle with dual approval and simulation, a roll-call that answers "is
everybody out", manager escalation, two-way replies in five languages, an
immutable evidence export, and four screens.

**Zero schema changes.** Phase 0 specified `bcms_alerts`,
`bcms_alert_recipients`, `bcms_alert_templates` and
`bcms_notification_deliveries` well enough that nothing was missing — including
`second_approved_by`, `is_simulation`, `estimated_cost_minor` and
`escalated_to_contact_id`, all of which were needed and all of which were
already there. The freeze now reads 15 → 8 → 5 → 1 → 0 → 1 → **0**.

## 2. Phase 2C is still not built, and again this did not wait

Same answer as Phase 6. `ContactResolver` and the `AudienceRule` grammar were
frozen at G0 and both exist; what 2C owes is the roster at real scale and the
verification campaign. Every targeting mode the phase brief lists —
org node, site, role, call tree, exercise participants, saved group,
geo-radius — was already implemented by `AudienceResolver` in Phase 0 and is
used unchanged.

## 3. The decisions

**A real adapter with no credentials falls back to the mock.** This is the most
important line in `ChannelRegistry`. Nigerian sender-ID registration, WhatsApp
template approval and USSD short-code allocation run four to ten weeks and will
not clear together. Without the fallback, naming the real class the day a
contract is signed would break every dispatch until the credentials landed — so
an operator would name it late, and the switchover would be a big-bang change
during a live quarter. With it, "enabled but not yet credentialed" behaves
exactly as the day before, and the console reports the channel as
`awaiting_credentials` rather than silently green.

**Three SMS gateways, one adapter class.** Termii, Africa's Talking and Infobip
differ in the name of the field the message goes in and the shape of the JSON
that comes back, and in nothing else that matters. Three classes would be three
places for the never-throw and never-retry rules to drift, and the third would
get one wrong. The differences are a payload map in `config/bcms-gateways.php`.

**Both failover attempts are on the delivery record.** An audit showing only the
successful send answers "did the message arrive" and not "how close did we come
to it not arriving" — and the second question is the one that gets a gateway
replaced. `rawResponse.attempts` carries the whole chain.

**Life safety is a separate queue, not a priority.** Standing rule 6, and the
reason criterion 12 holds: `bcms-lifesafety` has its own Horizon supervisor, so
it drains while `bcms-sync` is backed up with ten thousand jobs. A "high
priority" flag on a shared queue is still behind whatever the worker is doing.

**Dual approval has two thresholds and either trips it.** A critical alert to
nine people and an advisory to nine thousand both deserve a second pair of eyes,
for different reasons. The thresholds are **deployment-wide config**, not tenant
settings: "how loud is too loud to send unchecked" is a property of the
product's duty of care, not a number a customer should raise to nine thousand on
a Friday. What the tenant controls is the switch —
`bcms_settings.require_dual_approval_for_live`. A simulation never needs
approval, because requiring a signature to run a drill is how operators learn to
route around the control.

**A simulation writes the whole record and calls nobody.** It has to write it:
the point of a simulation is that the operator sees exactly what a live dispatch
would produce — the funnel, the per-recipient record, the cost. A simulation
that produced no evidence would train people on a screen they will never see
again. The rows say `simulation` in the provider column so an examiner cannot
mistake one for a real send.

**Escalation climbs away from the person, never back to them.** They have
already had the alert on every channel they have; a seventh copy is what teaches
somebody to ignore the sixth — the same argument Phase 5 made about T-2. The
manager gets **one message naming everybody they are missing**, not one per
person: a manager with nine unaccounted-for staff must not get nine texts while
trying to find them. A simulation escalates the rows and wakes nobody.

**The roll-call never merges "silent" with "unreachable".** Somebody whose phone
rang and who has not answered may be carrying a colleague down a stairwell.
Somebody whose number was wrong was never called. Both are unaccounted for, they
need different people to act, and one bucket sends the fire warden to the wrong
floor.

**MFA is on dispatch and deliberately not on compose or approve** (criterion
13). Live dispatch ships in this phase: from today a stolen session can put a
sentence on twelve thousand phones. But an operator drafting under stress should
hit the second factor once, at the moment it matters, rather than three times
while a building is being evacuated.

## 4. What was refused

**No Hausa, Yoruba or Igbo emergency copy was written.** The phase brief says
not to machine-translate emergency messaging without review and it is right: an
evacuation instruction that says the wrong thing is a safety incident, not a
formatting bug. What was built instead is the whole mechanism — per-locale
templates, per-channel renderings, SMS segmentation — plus:

- a **coverage grid** on the template library with three states per cell (live,
  authored-but-awaiting-review, never authored), so the gap is visible;
- a **recorded fallback**: a contact whose language has no active template gets
  English and the delivery says `locale_fell_back`, because a silent fallback
  would let a bank believe it had five-language cover for years;
- a **new translation is never active on creation** — it waits for a reviewer
  who reads the language.

No placeholder rows exist, deliberately. A row containing English text labelled
"Hausa" is one `is_active` flip away from being sent.

**There is no "everyone" audience.** The G0 grammar has no such leaf and
`bcms_alerts.audience_rule` is `NOT NULL`: a rule that resolves to nobody
resolves to nobody and never falls back to everybody. Fail closed. The
whole-organisation audience is named explicitly as the root org node with
descendants.

## 5. Defects found

- **GATE 2, THIRD ROUND, ADVISORY 11 — ONE ADAPTER SHORT, THEN FIVE MORE
  CHECKED.** Rounds 1 and 2 closed the exception-message leak into the
  application log; they did not touch `failed_reason`/`raw_response` on
  `bcms_notification_deliveries` itself, which is a *different* sink — one
  §6.1 of the NDPA register proposes retaining for **seven years** and
  exporting to a regulator. `SmsGatewayChannel::reasonFrom()` took the
  provider's own error string verbatim out of the response body
  (`config('bcms-gateways.sms.*.response.error')` — `message` for Termii,
  `SMSMessageData.Message` for Africa's Talking, `requestError.serviceException.text`
  for Infobip) and returned it as `failed_reason`. Nigerian gateways routinely
  echo the recipient in that string — "Invalid recipient 2348031234567", "DND
  active for 234803…" — so an MSISDN landed on the regulator-facing record.
  `redact()` cannot reach it: the number is a *value* inside free text, not a
  keyed field, and `redact()` only matches keys.
  **Auditing the other eight adapters for the same shape found two more,
  neither on the list handed over:** `VoiceTtsChannel::interpret()` returned
  the provider's `message` field verbatim on a rejected call, same defect,
  same reasoning (a dialled number can appear in a voice gateway's rejection
  text exactly as an MSISDN can in an SMS gateway's); and
  `WhatsAppCloudChannel::interpret()` appended Meta's `error.message` to
  `failed_reason` — some Meta Cloud API errors (e.g. "Recipient phone number
  not in allowed list") name the destination directly. `UssdChannel`,
  `WebPushChannel` and `WebhookChannel` were checked and are clean: every
  `DeliveryReceipt::failed()` reason in those three is either a fixed string
  or built only from `$response->status()`, never from response-body content.
  `FailoverSmsChannel` needed no separate fix: it only relays each gateway's
  own `failedReason` into its aggregated reason and `rawResponse.attempts`, so
  it inherits the fix. `MockChannel`'s two failure strings are fixed literals
  — confirmed, not assumed.
  **`raw_response` carried the same string a second way and would have kept
  doing so even after `failed_reason` was fixed** — `safeResponse()`'s
  `redact()` only nulls known secret *keys*, so the provider's `message` /
  `error` *value*, MSISDN included, would have passed straight through into
  the JSON column sitting beside the now-fixed `failed_reason`, on the same
  retained, exported row. Fixed together: `safeResponse()` takes an optional
  list of dot-paths whose *value* (not key) is provider free text, and nulls
  each one to `[see failed_reason category]` before the body is stored.
  For the `failed_reason` string itself, the fix is classification, not
  scrubbing — `HttpChannel::classifyProviderError()` matches the provider's
  text against a short, closed, documented list of category labels (see its
  docblock for exactly what is and is not caught) and returns one label or
  `null`; the method never returns any part of its input, so a keyword it
  does not recognise costs a diagnosis ("reason not classified" plus the HTTP
  status), not a leak. Meta's own numeric `error.code` is kept as-is in
  `failed_reason` alongside the category — it is Meta's bounded protocol
  field, the same reasoning as the SMTP reply code in blocking defect 2 below.
  Verified by constructing each shape directly (Termii/Africa's Talking-style
  flat and nested JSON, and Meta's `error.message`/`error.code`) and asserting
  neither the classifier nor `safeResponse()`'s redacted body ever contains
  the seeded MSISDN.
  **Two more sites were reported by the compliance-analyst as unassessed
  rather than cleared, and are out of scope for this file:**
  `MaterialiseReminderLadder` and `BcmsAuditable` both log `$e->getMessage()`
  verbatim. Neither handles an address or a body directly, but
  `BcmsAuditable::writeBcmsAuditRow()` passes a model's `$before`/`$after`
  attribute arrays into `AuditLog::create()`, and Laravel's `QueryException`
  appends the fully bound SQL to `getMessage()` — so a DB-level failure while
  auditing a contact-bearing BCMS model would echo that contact's fields into
  `Log::error()`. That is the same defect shape as this round, arguably with
  a more direct path to personal data than the reminder-ladder listener,
  which writes only schedule/readiness rows. Both were then fixed in the
  same pass after all: each now logs the exception class and `getCode()` /
  the SQLSTATE only, never `getMessage()`, and
  `tests/Feature/Bcms/Phase7AuditFailureLoggingTest.php` holds that closed
  (Gate 1, 2026-09-12, corrected this sentence — an earlier draft recorded
  them as reported-not-fixed).
- **GATE 2, SECOND ROUND, BLOCKING DEFECT 2.** Advisory 11's first-round fix
  moved `$e->getMessage()` out of `failed_reason` (a 7-year regulator-facing
  column) and into `Log::warning()`, in `HttpChannel::send()` and
  `SmtpEmailChannel::send()`. That relocated the personal data rather than
  removing it: Guzzle's `ConnectException` message and `getHandlerContext()`
  both carry the full request URI — on an aggregator that puts the API key and
  destination MSISDN in the query string, that is a credential and a phone
  number — and Symfony Mailer's `RfcComplianceException` message *is* the
  envelope recipient's address. The application log has no declared retention
  or residency, and a deployment that wires Sentry or a log aggregator turns
  it into an eleventh processor nobody registered.
  Fixed by logging structured, bounded fields instead of any exception
  message: `get_class($e)` plus provider/transport, plus — where the
  underlying library already exposes one — a numeric protocol code rather
  than parsed prose. For `HttpChannel`, that is the wrapped
  `GuzzleHttp\Exception\ConnectException`'s `getHandlerContext()['errno']`
  (a bare libcurl integer: 6 = could not resolve host, 7 = could not connect,
  28 = timed out, 35 = SSL — never the context's `url`/`error` strings). For
  `SmtpEmailChannel`, that is `$e->getCode()`, which Symfony's `SmtpTransport`
  and `EsmtpTransport` already set to the numeric SMTP reply code (550 for a
  rejected recipient, 535/504 for an auth failure) independently of the
  message text — confirmed by constructing both exception types directly and
  reading `getCode()` without ever calling `getMessage()`. Exactly what now
  reaches the log from these two files, field by field, is in the handoff
  report for this round; the NDPA register is being corrected to match by the
  compliance-analyst.
- **GATE 2 ADVISORY 10, DECIDED.** `AlertWebhookController::reply()` and
  `status()` read `$request->json()->all()` unconditionally. Twilio, Africa's
  Talking and most Nigerian aggregators post
  `application/x-www-form-urlencoded`; the signature still verifies for them
  (it is computed over `getContent()`, the raw body, regardless of content
  type), so a form-encoded callback would pass the signature check and then
  422 on `required:body` — reading as a signing problem when it is a parsing
  one. Fixed rather than deferred: both routes now go through one private
  `signedBody()` that reads `$request->request` (Symfony's parsed-POST-body
  bag — never the query string, which lives only in `$request->query`) for a
  form-encoded `Content-Type`, and `$request->json()` otherwise, so the two
  content types cannot silently drift into different rules and
  `$request->all()` — the exact query-string admission Gate 2 defect 1 closed
  — is never called.
  **Testing note for whoever writes the permanent test:** `Illuminate\Http\
  Request::create()` (and therefore the plain `$this->post($uri, $data)` test
  helper) puts `$data` straight into the request bag and leaves `getContent()`
  empty — it does not serialise `$data` into a raw body the way a real
  form-encoded POST arrives. A test asserting the form-encoded path must pass
  **both** the parsed array as `$parameters` (so `$request->request` is
  populated the way production's `$_POST` would be) **and** the matching
  `http_build_query($data)` string as the explicit raw `$content` (so
  `getContent()` — what the HMAC actually covers — matches what was signed);
  `$this->call('POST', $uri, $data, [], [], $server, http_build_query($data))`
  does this correctly, confirmed against this fix. Passing only one or the
  other test-passes for the wrong reason: content-only leaves the request bag
  empty and still 422s; parameters-only leaves `getContent()` empty and the
  signature verifies against the wrong (empty) string.

- **CORRECTED PER GATE 2 ADVISORY 13.** This entry previously claimed that a
  digit keyword matched *inside* the acknowledgement token, and that a person
  replying "SAFE 8a3f…" was recorded as NOT ON SITE. That was never how
  `interpret()` matched — `MENU_CODES` (the keypad codes, including `'3'` for
  not-on-site) is compared with `array_key_exists()` against the **whole**
  normalised message, never with `str_contains`, so free-text "SAFE" plus a
  token was never at risk of colliding with a bare "3". The actual defect was
  in the token strip that now runs first in `interpret()`: without it, a
  USSD-style reply of `"1 <token>"` where the token contains hex digits fails
  to exact-match any `MENU_CODES` key or any keyword below, once the token
  itself is part of the compared string, and falls through to `null` — a
  **dropped** acknowledgement, not a misfiled one. Both are bad for a
  roll-call, but a dropped answer reads as "never responded" while a misfiled
  one reads as a false negative, and they call for different mitigations. The
  token is now stripped before interpretation and keypad codes match only the
  whole message. Found by writing the test against the real token format
  rather than a tidy fixture.
- **`EscalationService` took two dependencies it never used.** Harmless today;
  the kind of thing that makes the next person think escalation renders its own
  templates.
- Phase 6's `manager_user_id` chain resolves a manager to a **contact**, never a
  user, because a user has a login and a contact has a phone number. Getting
  this wrong would have been a silent "never query users for a channel"
  violation of the G0 contract.

## 6. Deviations from the brief

**No load test at 10,000 recipients.** Criterion 2 asks for one and the
reliability-engineer role is not something available here. What is tested is the
shape that makes it achievable — the HTTP request materialises the list and
queues chunks and does no sending, verified against
`config('bcms.nfr.dispatch_queue_seconds')` at 1,200 recipients. **The real
load test belongs to Phase 12 (Gate G3), and criterion 2 should be treated as
outstanding until then.**

**No 1,000-recipient wall-clock test either** (criterion 1). The same reason:
against recording mocks it would measure PHP, not delivery. Criterion 1's
functional half — reach, acknowledgement, escalation, per-recipient export — is
tested; its timing half is a G3 item.

**The live dashboard polls**, as in Phase 6. This product broadcasts over the
log driver and ships on-prem.

## 7. Known gaps handed forward

- **Every channel is still a mock in practice**, because no credentials exist.
  That is the correct state and the console says so on every screen. Nothing
  further can be verified end-to-end until the paperwork lands — the commercial
  lead time is the blocker, not the code.
- **USSD end-to-end is `[verify at integration]`** at the W14 window. The flow,
  the registration call and the inbound handler are built; Phase 12 wires the
  aggregator.
- **Provider signature verification is per-vendor and only scaffolded.** The
  status webhook compares an HMAC when a secret is configured and accepts the
  callback when one is not, because some Nigerian aggregators do not sign at
  all — refusing those would mean no delivery receipts and therefore no
  evidence. Each vendor's real scheme arrives with its contract.
- **Cost estimates depend on configured rates.** No gateway reports a price
  before the send, so the estimate uses `cost_minor` per gateway and segment
  count. Criterion 9's 5% holds because the estimate walks the same path the
  dispatch does; it will drift if somebody sets the rate wrong, which is why
  the spend screen shows priced and unpriced counts separately.
- **Digital signage and the PA hook are not built** — the brief defers them and
  no interface was stubbed, because an interface nobody implements is a
  liability.
- **The AI alert composer is still off** (`bcms.ai.capabilities.alert_composer`),
  like every other AI capability. Phase 12.

## 8. A MariaDB testing trap, for whoever forces a `QueryException` next

Gate 1 needed a genuine `QueryException` for a defect-verification test and reached
for a schema change inside the test to provoke one. On MariaDB 10.4 this does not
do what it does on SQLite: **DDL implicitly commits.** `RefreshDatabase` wraps each
test in a transaction and rolls it back at teardown, but a `Schema::table(...)` /
`ALTER TABLE` run inside that transaction commits immediately and is *not* undone
by the rollback — the transaction wrapper only ever covered DML. The column
altered that way is left mutated for every test that runs afterwards against the
same database, on this run and any later one that reuses it without a fresh
migration.

That is what happened here: forcing the exception this way corrupted a column in
`risk_test_bcms7`, silently, until it was traced back and the database was rebuilt
with `migrate:fresh`. **Do not force a `QueryException` with a schema change inside
a `RefreshDatabase` test on MariaDB.** Provoke it a different way — a constraint
violation on existing DML (a duplicate on a unique index, a foreign key pointing
nowhere, a `NOT NULL` violation), or a mocked/partial connection — none of which
commit outside the transaction. If a schema-level trigger is genuinely unavoidable,
run it against a database nobody else's tests share and rebuild that database
afterwards rather than trusting the rollback to have undone it.
