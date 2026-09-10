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

- **A digit keyword matched inside the acknowledgement token.** `not_on_site`
  was matched on the bare string `'3'` with `str_contains`, and the token is
  sixteen hex characters — roughly two in three contain a 3. So a person
  replying **"SAFE 8a3f…" was recorded as NOT ON SITE**: a false negative in a
  roll-call, which is the direction that leaves somebody in a building. The
  token is now stripped before interpretation and keypad codes match only the
  whole message. Found by writing the test against the real token format rather
  than a tidy fixture.
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
