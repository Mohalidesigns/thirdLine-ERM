# NDPA personal-data register — BCMS module

**Owner:** compliance-analyst · **Opened:** BCMS Phase 0 · **Populated as personal data enters the system**

Definition of Done, item 8: personal data touched → an entry here with lawful
basis, retention and residency. This register is opened in Phase 0 because the
schema that holds the data is created in Phase 0, and a register written after
the fact is a register written to match what was built.

**Why this module needs one at all.** Staff contact data held so that people can
be reached in an emergency is personal data under the NDPA 2023. It includes
personal mobile numbers, WhatsApp handles, next-of-kin details and, in a
roll-call, location. This is more sensitive than most of what the platform
already holds, and the safeguards are in the schema rather than in a policy
document.

---

## 1. `bcms_contacts` — the emergency roster

| | |
|---|---|
| **Purpose** | To reach a named person during an emergency, an exercise, or a check that we can still reach them. Nothing else. |
| **Data subjects** | Employees, contractors, security and facilities staff, and their next of kin |
| **Categories** | Name, employee id, job title, business unit, site, corporate email, **personal mobile**, secondary mobile, **WhatsApp handle**, Teams/Slack id, push token, **next of kin**, preferred language, last known location |
| **Lawful basis — corporate channels** | NDPA s.25 — necessary for the performance of the employment contract and for the controller's legitimate interest in the safety of its workforce. Corporate email and the bank-administered Teams identity are work channels for a work purpose and need no separate consent. |
| **Lawful basis — personal channels** | **Consent**, recorded per contact (`consent_status`, `consent_captured_at`, `consent_withdrawn_at`). A personal mobile number, a personal WhatsApp handle and next-of-kin details are given voluntarily. |
| **Lawful basis — life safety** | Where the alert is life-safety traffic, NDPA's vital-interests basis applies and `ContactResolver::canReach()` will use a personal channel **despite a withdrawal**. The exception is deliberately narrow: `is_life_safety` traffic only, and every such send is recorded on `bcms_notification_deliveries`. |
| **Source** | `source` on every row: `ad`, `entra`, `scim`, `hris`, `manual`, `self_service`. A **personal** mobile is never directory-sourced; it arrives through the self-service emergency profile. |
| **Retention** | For the duration of employment plus 90 days, to cover an exit that overlaps an open incident. Then erased, not anonymised — an anonymised phone number is still a phone number. ~~Enforced by a Phase 2C job.~~ **CORRECTED 2026-09-17: Phase 2C has closed and built no purge job.** Nothing in the product deletes anything; see §7.5 and §10 for the command that is owed and who owns it. |
| **Residency** | af-south-1 or on-prem (Blueprint §14). The demo cloud region is seeded as South Africa deliberately, so residency is a visible question rather than a hidden one. |
| **DSAR** | Export of a contact and its delivery history, per subject. ~~Phase 2C.~~ **CORRECTED 2026-09-17: not built at Phase 2C** — that phase shipped eight routes and none is an export. The commitment, and the two extra keys it now needs, are restated at §7.6. |
| **Access control** | `bcms.contact.view` to read; `bcms.contact.manage` to edit **someone else's**; `bcms.myprofile.manage` to edit your own. `bcms.contact.export` is a separate permission held only by the CRO, because a bulk export of staff mobile numbers is an NDPA event, not a reporting one. |
| **Written back to a directory?** | **Never.** Standing rule 3: AD/Entra access is read-only, over LDAPS, with credentials in a secret store. `ad_synced_at` records a read; there is no column that could record a write and there will not be one. Checked at review. |

### Two design decisions this register forced

**Consent removes channels, not people.** A contact who has withdrawn consent for
personal-phone contact stays in every audience and is still counted in a
roll-call, because they remain reachable on a corporate email. Filtering them out
of the audience would silently understate a headcount — which in an evacuation
means somebody is not looked for. `ContactResolver::channelsFor()` is where the
withdrawal takes effect, and `AudienceResolver` deliberately does not consult
consent at all.

**`user_id` is nullable.** A security guard, a cleaner and a contractor all need
to be reachable in an evacuation and none of them has a platform login. A
contacts table that required a user row would exclude exactly the people a fire
drill is about.

## 2. `bcms_alert_recipients` and `bcms_notification_deliveries`

| | |
|---|---|
| **Purpose** | To evidence who was told, on what channel, and whether it arrived — the artefact an examiner asks for after an incident |
| **Categories** | Contact reference, the resolved channel addresses, a name snapshot, delivery status, acknowledgement, the person's **response** ("I need help") |
| **Lawful basis** | Legal obligation and legitimate interest — ISO 22301 8.4.3 and the CBN incident-reporting expectation both require the record |
| **Retention** | 7 years for incident-linked traffic, aligned to the banking record retention the client already applies. **12 months** for exercise and reminder traffic: a drill reminder from four years ago evidences nothing and is a standing pool of personal data. |
| **Snapshot rather than reference** | `resolved_channels` and `contact_name_snapshot` are written at dispatch. This is a data-protection trade-off taken deliberately: it duplicates personal data, and the alternative — resolving the audience rule again at read time — would tell an examiner who *would* be told today rather than who *was* told. The audit requirement wins, and the retention schedule is what bounds it. |
| **`raw_response`** | Provider payloads. Excluded from the audit log by `BcmsAuditable::auditExcluded()`, because a gateway response can echo the message body and the recipient number into a second table with a different retention. |

## 3. `bcms_exercise_participants` and `bcms_training_records`

| | |
|---|---|
| **Purpose** | Evidence of attendance (clause 7.3) and of competence (clause 7.2) |
| **Categories** | User reference, attendance status, check-in time and **method** (including `geo`), assessment score |
| **Lawful basis** | Performance of the employment contract; legal obligation for the mandatory records |
| **Retention** | 3 years after the record's `next_due_date`, or the certification cycle where the client is certified |
| **Note** | `check_in_method = geo` records that a location was used, not the location itself. A stored assembly-point coordinate per person per drill would be movement data with no continuity purpose. |

## 4. `bcms_contacts.latitude` / `.longitude` and `geo_last_known`

| | |
|---|---|
| **Purpose** | Geographic audience targeting — "everyone within 25km of the affected site" |
| **Status at G0** | **Columns exist and nothing writes them.** They are created in Phase 0 only because adding them in Phase 7 would be a structural migration (standing rule 2). |
| **Before anything writes them** | Phase 2C must add: an explicit, separate consent for location; a statement of granularity (a site association, not a live position); and a retention of days rather than years. Continuous location tracking of staff is **out of scope for this product** and is not a feature to be added quietly. |

---

## 5. Channel gateways — the processors Phase 7 configures

**Opened at Phase 7 (EMNS), and it is the first section of this register about
somebody other than us.** Sections 1 to 4 describe personal data this platform
holds. This one describes personal data this platform *hands to other
companies*, several of them outside Nigeria. `config/bcms-gateways.php` is what
made that concrete: until it existed, open question 3 below was hypothetical.

**What is true today, checked rather than assumed.** No gateway is configured in
any environment. `ChannelRegistry::defaultMap()` resolves every one of the eight
channels to a Phase 0 recording mock, and each real adapter's `isConfigured()`
returns false until credentials are present; the `BCMS_*` variables named in
`config/bcms-gateways.php` appear in **no other file in the repository** — not
`.env.example`, not a deployment document. **No personal data has left this
platform through any processor below.** This section describes the transfer that
begins on the day an operator sets two environment variables, which is the only
honest moment to write it — a register written after the first dispatch is a
register written to match what already happened.

### 5.0 The application log as a sink — stated as a rule, not as a count

**Two earlier statements in this register were false, and the same commit
falsified both.** One stood here; the other closed the Phase 7 handoff's
verification line, struck and annotated at the foot of this file. They read
*"nothing in `Emns` or `Notification` logs anything at all"* and *"three `Log::`
calls exist in `app/Services/Bcms`, none in `Emns` or `Notification`"* — both
written as a first-person independent confirmation. The fix for Gate 2 advisory 11 then moved `$e->getMessage()` out of
the `failed_reason` column and into `Log::warning()` in
`Notification/Channels/` — a **relocation, not a remediation**: those messages
carried staff email addresses out of Symfony Mailer's `RfcComplianceException`
(whose message *is* the offending address) and destination MSISDNs and API keys
out of Guzzle's `ConnectException` handler context, where an aggregator puts both
in the query string. Gate 2 is right that a stale first-person verification is
worse than a silent one, because an examiner reads *"confirmed independently"* as
work product.

**So this section no longer states a count.** Both false statements were counts,
each true for about as long as it took to commit, and a third would have the same
lifetime. What is stated instead is the rule the adapters are held to and the
fields each declared sink emits — a form the next fix cannot quietly falsify,
because breaking it means changing a named field rather than adding a line.

**The rule.** No BCMS channel adapter may write to the application log any value
that is, or is derived from, a recipient address, a message body, a provider
payload or a credential — **including by way of an exception message or an
exception's handler context**. The application log has no declared retention and
no declared residency in this product, and a deployment that wires Sentry or a
log aggregator turns it into an eleventh processor that section 5.2 does not
list.

**The declared sinks.** Three, all in `app/Services/Bcms/Notification/Channels/`,
each emitting a fixed field set. This is a *declared, non-personal* sink, which
is a different and weaker statement than "nothing is logged" — the honest version
says what is logged.

| Call site | Message | Fields, and nothing else |
|---|---|---|
| `HttpChannel::send()`, gateway did not answer | `BCMS gateway unreachable` | `provider` — the channel's own provider string (`termii`, `whatsapp-cloud`, …); `exception` — `get_class($e)`, always `Illuminate\Http\Client\ConnectionException`; `curl_errno` — a small integer or null, read from Guzzle's handler context via `HttpChannel::curlErrno()` and typed-checked with `is_int()`, from libcurl's fixed vocabulary (6 host not resolved, 7 refused, 28 timed out, 35 TLS) |
| `HttpChannel::send()`, any other adapter throwable | `BCMS gateway error` | `provider`; `exception` — `get_class($e)` only. No message, no code, no context |
| `SmtpEmailChannel::send()`, transport failure | `BCMS mail transport error` | `transport` — `config('mail.default')` (`smtp`, `ses`, `log`, …); `exception` — `get_class($e)` (`RfcComplianceException`, `TransportException`, `UnexpectedResponseException`); `smtp_code` — the numeric SMTP reply code Symfony attaches structurally (535, 550, 421), or **null** where the transport never set one, `?: null` rather than a `0` standing in for "not reported" |

**No address, no number, no URL, no query string, no credential, no body.** The
handler context's `url` and `error` keys are the two that would carry a
destination and a host, and `curlErrno()` reads neither. `get_class()` and a
protocol reply code are both closed vocabularies with no capacity to carry an
identifier, and they are what separates *"the gateway is down"* (7, 28) from
*"our config points at the wrong host"* (6) and *"our credential is wrong"* (535)
from *"that mailbox does not exist"* (550), at 3am, without naming the host or
the mailbox.

**How this was checked, and how to re-check it.** Read field by field on
**2026-09-12**, on `integration/tprm-bcms`, against
`app/Services/Bcms/Notification/Channels/HttpChannel.php` (the two
`Log::warning` calls and `curlErrno()`) and
`SmtpEmailChannel.php`. The check to repeat is not this paragraph: it is
`rg 'Log::|logger\(' app/Services/Bcms/Notification/` and then reading every
array literal it returns against the rule above. An adapter added next month
that logs `$e->getMessage()` would violate the rule while leaving any stated
count intact, which is the whole reason the count is gone.

**Two sinks outside the adapters that this section does not clear.** Elsewhere in
BCMS, `Listeners/Bcms/MaterialiseReminderLadder` and
`Models/Bcms/Concerns/BcmsAuditable` both log `$e->getMessage()` verbatim. Their
exceptions come from a reminder-ladder build and a failed audit-row write, not
from a gateway, so neither handles a recipient address or a message body — but
a database driver's exception text can echo a column value (a duplicate-key
violation names the key), and **nobody has assessed what those two can emit.**
That is recorded as **unassessed**, not as cleared. The owner is
`backend-engineer` with `code-reviewer` at the next BCMS gate; it is not a Phase
7 EMNS finding and it is not in this register's five sections of scope.

The exposure this section is actually about is what is **transmitted** to a
processor and what is **persisted** in the delivery and recipient rows — sections
5.2 and 6.

### 5.1 What every gateway receives, and what none of them does

| | |
|---|---|
| **Transmitted to every gateway** | The recipient's address for that channel — a mobile number, a WhatsApp handle, a push token, a Teams or Slack id, an email address — and the rendered message body |
| **Also transmitted where the channel carries it** | The acknowledgement callback token (see 5.1.1 below — **its format is changing under ADR 0016**), the locale (`en`, `ha`, `yo`, `ig`, `pcm`), the response option labels, and the sender ID / caller ID / short code, which are ours and not personal data |
| **Never transmitted** | Employee id, job title, business unit, site, next-of-kin details, consent state, location, any platform database id, or any other contact's details. Checked adapter by adapter. |
| **The body is organisational text, not personal data — by default** | `TemplateRenderer::variablesFor()` substitutes only `alert_title`, `severity` and `organisation`. There is no `{{name}}` placeholder and the recipient's name is not interpolated. **An operator typing free text into an alert can put a person's name in it** ("Musa is unaccounted for at Ikeja"), and nothing prevents that; it is a training and template point, not an adapter defect. |
| **Lawful basis is the channel's basis, not the gateway's** | A processor does not change why we hold the data. Corporate email, Teams and Slack ride §1's contract / legitimate-interest basis; personal mobile, personal WhatsApp and personal push ride §1's **consent**; a life-safety dispatch rides vital interests. What the processor adds is a **second, separate question** — §29 (a written processing agreement) and, where it is outside Nigeria, §41 (a basis for the transfer itself). A lawful basis for holding a number is not a lawful basis for sending it to Croatia. |

#### 5.1.1 The acknowledgement token — what it is, and what it will be

**Two states, and this register distinguishes them deliberately rather than
writing a present-tense verification of code that has not changed yet.**

| | |
|---|---|
| **In the tree today (checked 2026-09-12)** | `AlertDispatcher::tokenFor()` returns **sixteen hex characters**: the first 16 of `hash_hmac('sha256', 'bcms-alert-'.$id, config('app.key'))`. `CascadeEngine` mints the same shape against a second table. Derived, never stored — no column, no cache, no queue payload holds it. |
| **As committed by ADR 0016 §1, implementation in flight** | A composite: `{prefix}-{decimal row id}-{16 hex tag}` — `r-48213-9f2c1ab77d0e4b31` for an alert recipient, `c-…` for a call-tree test node. ~24 characters in three parts. **The HMAC tag is unchanged**: same input, same key, same 16 hex characters. `backend-engineer` is implementing it; this row is the state the phase is committing to, not a verification of the current tree. |

**What does not change, and it is the part that matters most here.** The token is
still **not derived from any personal identifier** — no name, no number, no
email, no employee id enters the HMAC input in either format, and a database row
id is not a personal identifier. The architect was explicit on this point in ADR
0016 §1 and this register agrees. Possession of the token still grants nothing
without `app.key`, and the tag is still compared with `hash_equals`.

**What is new, and is registered rather than left to be inferred from the
format.** The composite token puts an **internal row ordinal into a message body
that travels to a handset over SMS, WhatsApp or USSD.** That is not personal data
and this register does not claim it is. It is an enumerable identifier leaving
the platform: an observer holding one token learns roughly how many alert
recipient rows exist product-wide, and — absent the constant-time defences ADR
0016 §3 requires — could probe whether a given ordinal is currently open, which
is a count of the people in a live alert. ADR 0016 records this trade
explicitly, and takes it in preference to the alternative (a stored `reply_token`
column), on the ground that storing the secret in a table this register already
treats as sensitive evidence is the larger exposure. **This register agrees with
that ranking** and records the residual: an ordinal in an outbound message is a
disclosure to whoever holds the handset, and the control that keeps it from
becoming an enumeration oracle is ADR 0016 §3's sentinel path, not the token
format.

**Why the change is free right now, and why that window is closing.** ADR 0016 §2
records — and section 5.2 of this register independently shows — that **no
`BCMS_*` variable is set in `.env`, `.env.example` or `.env.testing`, every
processor is "Not configured" or "Not chosen", and nothing persists a token
anywhere.** So **no token in any format has ever reached a handset**, there is no
compatibility burden and no dual-read window. The day procurement picks an SMS
vendor, a token in flight is a token sitting in somebody's inbox during an
emergency, and the format acquires a compatibility problem for the length of the
longest alert window.

### 5.2 The processors

One row per processor actually configured in `config/bcms-gateways.php`, in file
order. **A cell reading "UNKNOWN" means nobody has established the fact, not that
it was assessed and found acceptable** — and each one carries the name of who has
to answer it in 5.3.

| # | Processor | Config key | Personal data it receives | Establishment and processing residency | NDPA §41 position | Status |
|---|---|---|---|---|---|---|
| 5.2.1 | **Termii** (Termii Webtech Ltd / Termii Inc) | `sms[0]` | Mobile number (personal or corporate), rendered SMS body | **UNKNOWN.** The default endpoint `api.ng.termii.com` is a Nigeria-labelled hostname, which is evidence of a Nigerian *endpoint* and not of a Nigerian *storage location*. Termii's published data-protection policy commits to the NDPR and the GDPR and names two entities (a Nigerian company and "Termii Inc"); it states no hosting region. | Cannot be settled until residency is. If processing and storage are wholly in Nigeria, §41 does not engage and §29 still does. If any part is not, a §41 instrument is required. | Not configured |
| 5.2.2 | **Africa's Talking** | `sms[1]` | Mobile number, rendered SMS body | Africa's Talking is a pan-African messaging platform of **Kenyan origin** with Nigerian operations; which legal entity contracts, and where the API platform stores message data, is **UNKNOWN** — the published privacy notice describes vendor assessments and data-protection commitments but names no storage location. | **Assume §41 applies** until a contract says otherwise. Kenya has a Data Protection Act 2019 and an Office of the Data Protection Commissioner, which is material to an adequacy argument under §42 — but the NDPC has made **no adequacy determination for any country** (see 5.4), so the instrument has to be contractual. | Not configured |
| 5.2.3 | **Infobip** | `sms[2]` | Mobile number, rendered SMS body | **EU, probably — and the system does not record which.** Infobip's published position is that customer data is stored and processed in its **EU data centres unless the customer instructs otherwise**. Infobip issues a per-account base URL that encodes the data centre, and `BCMS_SMS_INFOBIP_ENDPOINT` **has no default**: the processing region is chosen by whoever pastes a URL into an environment variable, and no field anywhere records which region that was. | §41 applies on the EU reading. The NDPR-era Whitelist that would once have answered this **no longer has legal effect** (5.4), so an approved transfer instrument is required rather than an adequacy assumption. | Not configured |
| 5.2.4 | **Meta Platforms** (WhatsApp Cloud API, `graph.facebook.com`) | `whatsapp` | WhatsApp number (a personal handle — §1 consent channel), rendered body as free text or as template parameters, template name, locale | **Outside Nigeria, unavoidably.** Meta's Local Storage feature for Cloud API confines stored message content to a chosen region, and the supported regions are Australia, Indonesia, India, Japan, Singapore, South Korea, Germany, Switzerland and the United Kingdom. **There is no African region.** Even where Local Storage is used, message content is in Meta data centres outside the chosen region for a data-in-use period of up to 60 minutes. | §41 applies and cannot be engineered away. The practical question is whether **Meta's standard data-transfer terms** satisfy §41 for a Nigerian bank, or whether WhatsApp is not used for roster traffic at all. A bank will not negotiate bespoke clauses with Meta; this is a decision, not a paperwork exercise. **The DPO must take it before a WhatsApp template is approved**, because template approval is what makes the channel usable. | Not configured |
| 5.2.5 | **Voice / TTS vendor** | `voice` | Mobile number, the spoken text, caller id | **VENDOR NOT CHOSEN.** `BCMS_VOICE_PROVIDER` defaults to the string `voice-tts`, which is a placeholder and not a company. Endpoint and key have no defaults. | Cannot be assessed. A voice vendor additionally holds call-detail records and may hold **call audio**, which this register has no visibility of; that question goes into the selection criteria, not after the contract. | Not chosen |
| 5.2.6 | **Push gateway** | `push` | **Push token** (a device identifier, and personal data), title, body, response option labels, callback token | **VENDOR NOT CHOSEN.** `BCMS_PUSH_PROVIDER` defaults to the placeholder `push-gateway`. The adapter is written for either FCM or a self-hosted Web Push relay, and those are opposite answers: FCM is **Google LLC, United States** and a §41 transfer; a self-hosted relay inside the bank is no transfer at all. | Depends entirely on which is chosen. **This is the cheapest §41 problem on the list to avoid** — a self-hosted relay removes it — and that should be said out loud before procurement picks the convenient option. | Not chosen |
| 5.2.7 | **USSD aggregator** | `ussd` | Mobile number, short code, truncated body, callback token — **and, on the inbound leg, the reply text the person keys in** | **VENDOR NOT CHOSEN.** `BCMS_USSD_PROVIDER` defaults to the placeholder `ussd-aggregator`. A Nigerian short code is allocated to a Nigerian aggregator, so in-country processing is the likely answer — **likely is not established.** | Probably no §41 issue; §29 applies regardless, and the DPA must cover the **inbound** direction, which is the only channel where the aggregator sees what the person said. Wiring completes at Phase 12; the DPA must precede it. | Not chosen |
| 5.2.8 | **Microsoft** (Teams incoming webhook) | `teams` | The alert subject and body, plus `bcms_recipient` — the person's Teams id | **The customer's Microsoft 365 tenant, wherever that is.** Teams chat and channel messages live in the tenant's data location. Microsoft's local data residency geos include **South Africa**; they do **not** include Nigeria. So a Nigerian bank's Teams data is already outside Nigeria, by a decision taken long before this module. | §41 applies, and it applies to the bank's whole Microsoft 365 estate rather than to BCMS. **BCMS must not be the first place this is written down** — if the bank has a §41 position for Microsoft 365, this inherits it; if it does not, that is a finding about the bank, and the DPO should be told it was found here. | Not configured |
| 5.2.9 | **Slack** (Salesforce, incoming webhook) | `slack` | The alert subject and body, plus `bcms_recipient` — the person's Slack id | **United States by default.** Slack stores data in the US unless the customer has bought data residency; the available residency regions are Sydney, Tokyo, Frankfurt, Paris, Singapore, and more recently Switzerland, Sweden, the UAE and Brazil. **None is in Africa.** | §41 applies. Same inheritance point as Teams: this is a bank-level Slack decision, surfaced here. | Not configured |
| 5.2.10 | **The mail transport** | *not in this file* — `config('mail.default')`, used by `SmtpEmailChannel` | Recipient **name** and corporate email address, the full message body, and an `X-BCMS-Message-Id` header | **UNKNOWN, and deployment-specific by design.** The adapter deliberately has no mail configuration of its own; it uses whatever the deployment has. That is SES in some AWS region, a bank's own Exchange relay, or the `log` driver on a demo box — three completely different answers, one of which is not a transfer and one of which is. | Cannot be stated generically. **It must be recorded per deployment** at go-live, in this register, naming the transport and its region. Email is also the only channel that transmits the person's **name** alongside their address. | Not configured; "no SMTP" is a recorded go-live gap |

**The processor my brief did not list, and why it is here.** 5.2.10 is not in
`config/bcms-gateways.php` and it is a processor all the same: `SmtpEmailChannel`
sends staff names, corporate addresses and full message bodies through whatever
mailer the deployment holds. A processor register that enumerated only the file
would have missed the channel most likely to go live first — it is the one with
no Nigerian paperwork in front of it.

**One webhook, many people.** `teams.webhook_url` and `slack.webhook_url` are
single URLs, while `WebhookChannel::perform()` attaches each recipient's own
identifier to the payload as `bcms_recipient`. Whatever channel that webhook
points at therefore receives, one message per person, the identity of every
individual alerted. Inside the bank that is an **onward disclosure** of who was
notified and who was not — a purpose-limitation question for the DPO rather than
a defect, and it should be answered before the URL is pasted in, because the
answer is "point it at a restricted crisis-team channel", not "point it at
#general".

### 5.3 Who must answer what, before any channel is switched on

| What is unknown | Who answers it | What it blocks |
|---|---|---|
| Termii's, Africa's Talking's and Infobip's processing and storage locations | **The client's DPO**, from a signed DPA obtained through the TPRM engagement record for that vendor — not from a website | Configuring any SMS gateway |
| Whether Meta's standard transfer terms satisfy §41 for this bank | **The client's DPO and legal function**; it is a yes/no with a real "no" branch | WhatsApp template approval and the whole WhatsApp channel |
| Which voice, push and USSD vendors are being bought | **The client's procurement**, then back to this register for a completed row | Those three channels; also the Phase 12 USSD wiring |
| Whether the bank has an existing §41 position for Microsoft 365 and Slack | **The client's DPO** | Teams and Slack channels |
| Which mail transport and region each deployment uses | **The deploying party**, recorded here per environment | Email — the channel most likely to go live first |
| A §29 written processing agreement with every processor above, covering the inbound direction where it exists | **The client's DPO**, one per vendor | Every channel. §29 is not conditional on the vendor being foreign. |
| Whether the **consent notice** names the transfer | **The client's DPO**, when Phase 2C builds the capture screen | Reliance on consent under §43 for any cross-border personal channel |

**On that last row, checked rather than assumed:** `consent_status` is *read* by
`ContactResolver` and by the call-tree services, and is *written* today only by
seeders and tests. **No screen and no controller in this repository captures
it** — there is no emergency-profile page; Phase 2C owns it. Which means no
consent held today could possibly be informed about a transfer to Ireland or
California, and **consent under §43(1)(a) is therefore not currently available as
a transfer basis**, whatever the column says. When Phase 2C builds that screen,
the notice must name the channels, the processors and the countries, or the
consent it captures will not carry the transfer.

### 5.4 The §41 framework this section is written against

| Source | What it says | Where it bites here |
|---|---|---|
| **NDPA 2023 §41** | Personal data may not be transferred out of Nigeria unless the recipient is subject to a law, **binding corporate rules, contractual clauses, code of conduct or certification mechanism** affording an adequate level of protection, **or** a §43 condition applies | Every row in 5.2 outside Nigeria |
| **NDPA 2023 §42** | The Commission may determine that a jurisdiction's protection is adequate | Relevant but currently empty-handed — see the next row |
| **NDPA 2023 §43** | Derogations: informed consent not withdrawn; necessity for a contract with the data subject; the data subject's sole benefit where consent is impracticable; public interest; legal claims; **vital interests where the subject cannot give consent** | The vital-interests derogation is real and is **narrow**: it covers a life-safety dispatch to someone who cannot be asked. **It does not cover a drill reminder, an exercise invitation or a quarterly contactability check**, and a module whose whole thesis is a testing rhythm will send far more of those than of the other kind. Vital interests cannot be the standing basis for this module's traffic. |
| **NDPA 2023 §29** | A controller engaging a processor (or a processor engaging another) must do so under a **written agreement** binding it to assist with data-subject rights, implement security measures, evidence compliance and **notify when a further processor is engaged** | All ten rows, foreign or not. This is the obligation TPRM already cites as `NDPA §29(2)` in `SubprocessorDeclarationService` |
| **NDPC GAID 2025** (in force **19 September 2025**) | The operational directive for §§41–43. The **NDPR-era Whitelist no longer has legal effect**; adequacy is assessed against factors (enforced data-protection law, an independent supervisory authority, effective remedies) rather than a published country list; **SCCs, BCRs and certifications may be used, subject to the Commission's approval** | It removes the shortcut. Nobody may write "the EU is whitelisted" in this register. The exact article and schedule numbering of the GAID should be pinned by the client's counsel before it is quoted in a filing — this register cites the instrument and its effect, not a numbered article it has not read in the original |

**Clause codes.** Everything in this section maps to the three NDPA codes already
published in `App\Enums\Bcms\IsoClauseRef` — `ndpa.lawful_basis`,
`ndpa.retention`, `ndpa.residency`. **No new code is added by this section.** A
fourth, for the §29 processing-agreement obligation, would be worth having
(`ndpa.processor_agreement`); it is **not** published here, because a code that
exists in a document but not in the enum and its seeded `bcms_clause_refs` row is
a code that will be claimed as satisfied by something nobody built. If it is
wanted, it is an enum case, a seeder row and a line in `Phase0FoundationsTest`,
owned by backend-engineer — not a paragraph here.

## 6. What is persisted from a dispatch, and for how long

Section 2 covers `bcms_alert_recipients` and `bcms_notification_deliveries` as
records. This adds the columns Phase 7 started filling with material that came
from outside the platform.

| Column | What actually lands in it | Assessment |
|---|---|---|
| `bcms_notification_deliveries.address` | The destination address for that message: a personal mobile number, a WhatsApp handle, a push token, a Teams or Slack id, an email address. Written **before** the provider is called (standing rule 8), so it exists even for a send that failed. | A second copy of the roster's most sensitive column, one row per person **per channel per alert**. Justified by the evidence requirement in §2 and bounded only by retention |
| `bcms_notification_deliveries.raw_response` | The provider's response body, after `HttpChannel::safeResponse()`. **That redaction covers credentials only** — `api_key`, `apikey`, `token`, `secret`, `password`, `authorization`, `access_token` — and **not personal data**. A gateway that echoes the destination number in its acknowledgement (Africa's Talking returns a `Recipients` array; the configured cost and id paths read from it) therefore writes that number into this column too. Simulation rows hold the **rendered message body verbatim** under `would_have_sent`. | The least controlled content in the schema, and the column with the weakest reason to be kept. Already excluded from the audit trail by `BcmsAuditable::auditExcluded()` — checked: `['updated_at', 'push_token', 'raw_response']` |
| `bcms_alert_recipients.response_text` | **What the person typed, verbatim**, written at `RollCallService::record()`. Up to 1,000 characters from a provider webhook, 500 from the in-app path. | See below — this is the item on this page that needs the most careful reading |
| `bcms_notification_deliveries.failed_reason` | On the three paths advisory 11 fixed, a **fixed English string** and nothing derived from an exception: `'Gateway unreachable.'`, `'Gateway error.'`, `'Mail transport error.'`. **But not on every path.** `SmsGatewayChannel::reasonFrom()` takes the **provider's own error string verbatim** out of the response body (`data_get($body, config('…response.error'))`), and `WebPushChannel`, `WebhookChannel` and `UssdChannel` write `'… returned HTTP {status}.'` | The fixed strings are clean. `reasonFrom()` is **a residual finding, opened here on 2026-09-12 and not remediated**: a gateway that answers "Invalid recipient 2348…" writes that number into a column §6.1 proposes to keep for 7 years, through a path `safeResponse()`'s credential redaction does not cover because it is not a known key in a structured array. It is the same defect shape as advisory 11, one adapter further along. Owner: `backend-engineer`, at the Phase 7 re-gate or Phase 8 — **not closed by this pass and not to be read as closed** |

**`response_text` is not just a status.** The roll-call vocabulary the parser
looks for includes *injured*, *hurt*, *trapped* and their Hausa, Yoruba, Igbo and
Pidgin equivalents, and the design deliberately keeps the raw text whenever the
parser is unsure, "for a human to read". That is the right engineering decision
and it has a consequence this register has to state: **a free-text field that
collects "I am injured" is collecting health data**, which the NDPA treats as
sensitive personal data with stricter conditions, and one that collects "Musa is
trapped on the third floor" is collecting personal data about **someone who is
not the sender**. Neither is a reason to stop keeping the text — an evacuation
where nobody can read what a person actually said is worse. They are reasons for
a short retention, a tight read permission and a line in the privacy notice.

**The inbound `from` number is processed and not stored.** Checked end to end:
`AlertWebhookController::reply()` validates `from` (max 32 characters), passes it
to `InboundResponseHandler::handleReply()`, which uses the last nine digits to
match exactly one open recipient, and then **discards it** — no column receives
it and nothing logs it. Processing is still processing under the NDPA, so it is
registered; but it is registered as a transient match key, not as a stored
identifier, and the register should not imply otherwise.

### 6.1 Retention — proposed, and not yet enforced by anything

| Item | Proposed retention | Reasoning |
|---|---|---|
| `address` | Inherits §2: **7 years** incident-linked, **12 months** exercise and reminder traffic | It is part of the "who was told, on what channel" record an examiner asks for |
| `response_text` and `response_value` | Inherits §2's clock, with a **shorter floor for exercise traffic**: 12 months | The evidential fact is *that* somebody answered and *what status* was recorded. The verbatim text earns its keep during and shortly after the event, not in year six of a drill archive |
| `raw_response` | **90 days from the delivery's terminal status, then nulled** — deliberately *not* §2's 7 years | Its only operational purpose is diagnosing a dispatch. The evidence — status, provider, provider message id, the four timestamps, failure reason, cost — is in typed columns beside it and survives. Keeping a 7-year archive of unredacted provider payloads to evidence something already evidenced elsewhere is retention without a purpose. **This divergence from §2 is intentional and needs the DPO's agreement**, because it means an examiner in year three sees the delivery record but not the gateway's raw wording of it |
| Simulation `raw_response` | Same 90 days | A simulation's `would_have_sent` body is training material, not evidence of a dispatch — nothing was dispatched |

**None of this is enforced.** Checked across `app/`: there is **no BCMS retention
or purge command of any kind** — the only pruning command in the product is
`PruneLlmUsageEvents`, which is TPRM's. §1 and §2 name a Phase 2C job; that job
does not exist yet. Until it does, every retention figure in this register is a
statement of intent, and the honest description of the current state is
**indefinite retention**. That is tolerable only while no channel is configured
and the data is seeded. **It must be closed before the first real gateway goes
live**, and the owner is backend-engineer with reliability-engineer for the
schedule, at Phase 2C.

### 6.2 One thing found while writing this, for the architect and the DPO

The Phase 7 alert evidence export (`AlertController::evidence()`) is gated on
**`bcms.report.export`** and its CSV includes an `Address` column and a
`Response text` column — every alerted person's mobile number and their verbatim
reply, in one file. Section 1 of this register deliberately put bulk contact
export behind its **own** permission, `bcms.contact.export`, held only by the
CRO, "because a bulk export of staff mobile numbers is an NDPA event, not a
reporting one". A reporting permission now produces the same material by a
different door.

This is recorded as an observation, not as a defect of Phase 7's dispatch
engine: the export is a genuine ISO 22301 8.4.3 evidence artefact and it needs
those columns to be evidence at all. The question is who holds the permission,
and whether the address column should be masked for anyone below the CRO. The
architect owns the permission decision; the DPO owns whether masked evidence is
still evidence.

### 6.3 Sufficiency — could an examiner be shown this in one click?

**No, and not yet for a good reason.** Asked "show me every processor that
receives your staff's personal data, on what basis, and where they process it",
the answer today is this document, assembled by a human from a config file. That
is acceptable while nothing is configured. It stops being acceptable on the day a
channel goes live.

**What would make it one click** is not a new BCMS screen. Each gateway in 5.2 is
a third party: it belongs in the **TPRM register** as a third party with an
engagement, a DPA, a residency attribute and a §41 instrument attached, and the
BCMS channel configuration should point at that engagement rather than describing
the vendor again. TPRM already models exactly this — `SubprocessorDeclarationService`
carries the sub-processor disclosure gate citing `DORA Art. 30(2)(a); NDPA
§29(2)` — and it is the module with the register exports an examiner already
asks for. **BCMS's continuity gateways are TPRM's fourth parties**; answering the
question twice in two modules is how the two answers come to disagree. That is a
cross-module contract for the architect to place in a later phase, and it is
recorded here so that it is placed deliberately rather than discovered during an
examination.

### 6.4 Sources

- NDPA 2023, §§25, 29, 41, 42, 43 — [Nigeria Data Protection Act 2023 (NGCERT copy)](https://cert.gov.ng/ngcert/resources/Nigeria_Data_Protection_Act_2023.pdf); §29 contents summarised in [ICTLC's provision review](https://www.ictlc.com/transitioning-from-ndpr-to-ndpc-some-good-bad-and-ugly-provisions-of-the-newly-enacted-nigeria-data-protection-act-2023/?lang=en)
- NDPC **GAID 2025**, in force 19 September 2025 — [NDPC text](https://ndpc.gov.ng/wp-content/uploads/2025/07/NDP-ACT-GAID-2025-MARCH-20TH.pdf); effect on the Whitelist, adequacy factors and SCC/BCR approval per [DLA Piper, *Nigeria: NDPC Issues GAID*](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/) and [Tech Hive Advisory on the Whitelist's status](https://www.techhiveadvisory.africa/insights/changing-trend-in-international-data-transfer-in-nigeria-reassessing-the-adequacy-of-the-whitelist-and-the-implications-for-businesses)
- Meta WhatsApp **Local Storage** regions and the 60-minute data-in-use period — [Meta for Developers, Local storage](https://developers.facebook.com/documentation/business-messaging/whatsapp/local-storage/)
- **Slack** default US storage and the list of data-residency regions — [Data residency for Slack](https://slack.com/help/articles/360035633934-Data-residency-for-Slack), [Slack, *Expanding Global Data Residency*](https://slack.com/blog/news/introducing-data-residency-for-slack)
- **Microsoft 365 / Teams** local data residency geographies (South Africa listed; Nigeria not) — [Microsoft Learn, Data Residency for Microsoft Teams](https://learn.microsoft.com/en-us/microsoft-365/enterprise/m365-dr-service-teams?view=o365-worldwide), [Advanced data residency in Microsoft 365](https://learn.microsoft.com/en-us/microsoft-365/enterprise/advanced-data-residency?view=o365-worldwide)
- **Infobip** EU-by-default storage statement — [Infobip infrastructure overview](https://www.infobip.com/docs/essentials/support/infrastructure-overview)
- **Termii** data-protection policy (NDPR/GDPR commitment, no stated hosting region) — [termii.com/legal/data-protection](https://termii.com/legal/data-protection)
- **Africa's Talking** privacy notice (vendor assessments, no stated storage location) — [africastalking.com/privacy_policy](https://africastalking.com/privacy_policy)

---

## 7. `bcms_identity_*` — the Microsoft Entra ID directory sync (Phase 2C)

**Opened at Phase 2C, before gate 1, because ADR 0018 §10 and the work order §10
both require it there.** This is the section that section 1 has been pointing at
since Phase 0: `bcms_contacts` finally has a supply, and the supply is an
automated nightly read of the bank's entire staff directory.

**What is new here that is not new anywhere else in this register.** Sections 1
to 6 describe data a human typed in or a seeder wrote. This one describes data
the product **goes and gets**, on a schedule, without a human in the loop for
the read. That changes the analysis in three ways: the collection is systematic,
it is bulk, and the data subject is not present at the moment of collection. The
mitigations are all in ADR 0018 and they are real — read-only, work attributes
only, staged rather than applied, human-reviewed — and they are enumerated below
as controls rather than claimed as reassurance.

### 7.1 The processing activity

| | |
|---|---|
| **Activity** | A scheduled, app-only, **read-only** query of the customer's Microsoft Entra ID directory over Microsoft Graph, staged into `bcms_identity_sync_changes` and applied — only after a decision is recorded — to `bcms_contacts` by `ChangeApplier` |
| **Trigger** | `bcms:sync-directory` nightly at 02:30 (full reconciliation) and `--delta` every fifteen minutes where the connector is on `nightly_plus_delta`; also a manual **Sync now** from the connector screen. A tenant with no active connector is skipped entirely; nothing runs when `features.bcms` is off |
| **Data subjects** | Every enabled user object in the directory, or in the subset an OData `$filter` selects. For a Nigerian DMB that is the whole workforce, including people who have no platform login |
| **Volume** | Whatever the directory holds. The acceptance fixture is ~200; NFR §14's tenant ceiling is 50,000 contacts |
| **Controller / processor** | The bank is the controller of both the directory and the roster. Microsoft is already its processor for the directory (see 7.7). This product is the controller's own system |
| **Tables** | `bcms_identity_connectors` (no personal data — a tenant id, a client id, an encrypted secret, URLs, a date), `bcms_identity_sync_runs` (**no personal data** — counters, statuses, a bounded `error_class`/`error_code`, timestamps; checked column by column), `bcms_identity_sync_changes` (**this is the personal-data table**) |
| **Clause codes** | `ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency` — all three already published in `App\Enums\Bcms\IsoClauseRef`. **No new clause ref is published by this section.** The continuity purpose the read serves is stamped by the artefacts it feeds, not by the read |

### 7.2 What is read from the directory, and what is deliberately never read

**Read.** `DirectoryAttributeMap::selectFields()` is the whole of it, and it is
an explicit `$select` on every Graph request — not a default projection, not
"whatever the endpoint returns":

```
id · userPrincipalName · displayName · mail · mobilePhone · businessPhones
jobTitle · department · officeLocation · employeeId · accountEnabled
```

plus `$expand=manager($select=id)` — the **manager's directory object id**,
which is a pseudonymous identifier of a second person and is registered as such.

**Of those eleven, only seven are mapped to a contact column** by
`DirectoryAttributeMap::defaults()`: `displayName → full_name`,
`employeeId → employee_id`, `jobTitle → title`, `department → business_unit_id`,
`officeLocation → site_id`, `mail → email`, `mobilePhone → mobile_primary`.
`userPrincipalName` is used only as a fallback for `subject_name` when a row has
no `displayName`; `businessPhones` is read on every request and persisted only
if a tenant maps it; `accountEnabled` drives leaver detection; `id` is the match
key. **An unmapped Graph attribute is held in memory for the duration of the run
and is never written to any column** — `before_json`/`after_json` are built from
the mapped set, not from the raw Graph object, which is the engineering
commitment ADR 0018 §2.4 made and it holds in the code.

> **Data-minimisation observation, for the DPO rather than a defect.**
> `businessPhones` and `userPrincipalName` are requested on every page whether
> or not the tenant uses them. Under GAID's "minimum necessary" test (7.9) the
> defensible position is that the `$select` should be **derived from the saved
> attribute map** rather than being a fixed superset of it. That is a small
> change to `selectFields()` and it is not a Phase 2C blocker; it is recorded so
> the DPIA has it.

**Never read, and each for a reason.** No photograph (`photo`), no
`signInActivity`, no `birthday`, no `aboutMe`/`skills`/`interests`/`schools`, no
`otherMails`, no `homePhone`, no `assignedLicenses`, no
`onPremisesSecurityIdentifier`, no `preferredLanguage` (ADR 0018 §3.4 — Entra's
is a UI locale, and the language somebody wants an evacuation instruction in is
a different fact whose misuse is a safety incident, not a formatting bug). And
**no group membership at all**: scoping by group needs `GroupMember.Read.All`,
a second read scope this phase refuses (§3.2 of the ADR). That refusal has a
privacy consequence worth stating positively — **the sync cannot learn, and
therefore cannot infer, which project, committee, union, affinity group or
distribution list a member of staff belongs to.**

**Never written, on any row, by any sync.** `ChangeApplier::NEVER_WRITE`, held
as a constant and asserted by a test to be disjoint from the write allowlist:

```
whatsapp · mobile_secondary · next_of_kin · channel_preferences · preferred_language
consent_status · consent_captured_at · consent_withdrawn_at · verification_status
last_verified_at · latitude · longitude · geo_last_known · user_id
```

**That list is the most important paragraph in this section for section 1's
sake.** Every column section 1 built the consent architecture out of — the
personal channels, the next-of-kin details, the consent state itself, the
location columns section 4 says nothing may write until Phase 2C adds a separate
location consent — is on it. A directory sync cannot create a consent, cannot
alter one, cannot add a personal mobile number and cannot write a location.
Section 4's condition is therefore **still unmet and still correctly unmet**:
Phase 2C writes nothing to `latitude`/`longitude`/`geo_last_known` and the
separate location consent it was told to add is not owed by this reduced phase.
It moves to 2D with My Emergency Profile, which is where consent capture lives.

**What actually lands in the staging table**, so that nobody has to infer it
from the ADR:

| Column | Personal data? | What is in it |
|---|---|---|
| `subject_name` (varchar 200) | **Yes** | The person's display name, on **every** row including leavers — a plain, queryable, indexed-adjacent column, not buried in json. ADR 0018 §2.4 registered `before_json`/`after_json` and did not mention this one; it is registered here |
| `directory_object_id` (varchar 64) | **Yes, pseudonymous** | The Entra object id. Not a name, but a stable unique identifier for one natural person, and it is the key a DSAR has to use for a rejected joiner (7.6) |
| `before_json` | **Yes** | The prior value of only the mapped fields that changed — in practice `full_name`, `title`, `business_unit_id`, `site_id`, `email`, `mobile_primary`, `is_active` |
| `after_json` | **Yes** | The directory's value for the same fields, plus the internal `_manager_object_id` key for a manager change. For a joiner it is the whole mapped set |
| `impact_json` | **Yes, the subject's own** | Tree name, tier, `downstream_blocked_count`, saved-group names — organisational — **and a rendered `headline` that contains the subject's own full name** (`ImpactAssessor`, e.g. *"… is a named deputy on an approved call tree"*). No third party's name appears in it |
| `apply_error_class` | No | A PHP class name |

### 7.3 Lawful basis — legitimate interest is the operative basis, and CBN is its content

**The basis is NDPA 2023 s.25(1)(b)(v) — legitimate interest — supported by
s.25(1)(b)(i), the employment contract.** Reading an employee's own work
attributes out of the employer's own directory, in order to be able to reach
them in an emergency, is squarely inside the employment relationship and inside
the data subject's reasonable expectation of processing, which is the proviso
s.25 attaches to legitimate interest. Three facts do most of the work in the
balancing test and they are all enforced in code rather than asserted: the read
is **read-only** (7.7), every attribute is a **work** attribute the employer
already holds, and **no personal channel, consent state, location or next-of-kin
detail is read or written at all** (7.2).

**Legal obligation is available and I decline to make it the primary basis, and
the distinction is not pedantry.** The CBN Risk-Based Cybersecurity Framework
for DMBs and PSBs (2024, effective 1 July 2024) requires a business continuity
plan and an incident-response plan with **responsibilities named for each team
member**, and requires incidents to be reported to CBN Banking Supervision
within 24 hours. A bank cannot discharge either without being able to reach
named people. **But I could not corroborate any CBN provision that requires a
bank to read its identity directory, or to hold an emergency contact roster in
any particular form or by any particular mechanism.** The framework requires the
capability; it does not prescribe this collection. So:

- **legal obligation under CBN supplies the *purpose*** — an accurate, reachable
  roster is not a convenience, it is how a regulated bank meets a named
  supervisory expectation, and that is what makes the legitimate interest
  weighty rather than merely commercial;
- **legitimate interest supplies the *authority to collect it this way***, and it
  is the basis that carries the documented balancing test GAID expects to see.

Writing it the other way round — "CBN requires us to sync Entra" — would be
inventing a Nigerian regulatory requirement to make a control look mandatory,
and this register does not do that.

**One further point in the bank's favour and it should be recorded, not
assumed.** The bank is not collecting anything new from the data subject. It is
**further processing data it already holds** for IT administration, for a
compatible purpose (workforce safety) within the same controller. That is a
materially easier case than a fresh collection, and the thing that keeps it
compatible is the `$select` list and the two constants, not a policy sentence.

### 7.4 Purpose limitation — emergency notification and continuity only

The stated purpose is unchanged from section 1: **to reach a named person during
an emergency, an exercise, or a check that we can still reach them. Nothing
else.** What Phase 2C adds is a set of technical controls that make the
statement checkable:

1. **The `$select` list.** Nothing outside eleven named attributes is ever
   requested (7.2).
2. **`ChangeApplier::WRITE_ALLOWLIST`** — thirteen columns, a constant, pinned by
   a test so widening it is a diff a reviewer sees.
3. **`ChangeApplier::NEVER_WRITE`** — fourteen columns, a constant, asserted
   disjoint from the allowlist.
4. **No group read** — so no inference of affiliation (7.2).
5. **Unmapped attributes are never persisted** (7.2).
6. **Nothing is applied live.** Every run writes only to the staging table;
   application is a separate, permissioned, audited decision. `auto_apply_policy`
   has two values and there is deliberately no `all`.
7. **The permission split.** `bcms.identity.manage` (hold the credential that
   reads the whole directory, configure, run a sync) is the CRO's;
   `bcms.identity.review` (decide that Musa Bello has left) is the BC
   Coordinator's.

> **Observation for the architect, the same shape as §6.2 and not a Phase 2C
> defect.** `IdentityPresenter` passes `before_json` and `after_json` to the
> review screen essentially raw (only `_manager_object_id` is stripped). A
> change to `mobile_primary` therefore renders the old and the new number, and a
> full reconciliation after a bulk HR update could put a queue of staff mobile
> numbers, names, titles and departments on one screen in front of anybody
> holding `bcms.identity.review`. This is **correct for the feature** — a
> reviewer who cannot see what changed cannot review it — and section 1
> nonetheless put bulk contact export behind its own CRO-only permission
> `bcms.contact.export` "because a bulk export of staff mobile numbers is an
> NDPA event, not a reporting one". A review queue is not an export, and a
> 200-row review queue is an export to anybody with a screenshot. The
> architect owns whether the queue masks the mobile column for a reviewer who
> is not the CRO; the DPO owns whether masked review is still review. It is the
> third door onto the same material, after §6.2's evidence CSV.

### 7.5 Retention — three clocks, one of which cannot be a clock

**Nothing here is enforced today.** As at 2026-09-17, checked across
`app/Console/Commands`: the only pruning command in the product is
`PruneLlmUsageEvents`, which is TPRM's. There is **no BCMS retention or purge
command of any kind**, so the factual retention for everything below — and for
sections 1, 2 and 6.1 — is **indefinite**. The figures are proposals with
reasons; section 10 is the command that has to exist for them to be true.

| Item | Proposed | Reasoning |
|---|---|---|
| `bcms_identity_sync_runs` | **24 months** from `finished_at` | Contains no personal data (checked column by column). This is a housekeeping figure, not an NDPA one: two years lets "how often did the roster sync fail, and when did it start failing" survive one full internal-audit and CSAT cycle plus the current one |
| Staged change rows with **no provenance role** — `decision` in {`rejected`, `superseded`}, and any row still `pending` when its run is long closed | **18 months** from `decided_at`, or from `created_at` where never decided. Then `before_json`, `after_json`, `impact_json` **nulled** and `subject_name` replaced with a non-identifying placeholder; **the row itself is kept** so the run's counters still reconcile | These rows are the review audit trail and nothing else — `ChangeApplier::baselineFor()` only ever reads rows with a non-null `applied_at`. Eighteen months is one annual cycle (ISO 22301 9.2 internal audit, CBN CSAT) plus the current one, which is the reach of "show me how you maintain your roster" |
| The **last applied** `after_json` per contact | **Purpose-bound, not time-bound: retained for the life of the contact record, and erased *with* the contact** under section 1's "employment plus 90 days" | This is the hard one and it needs stating plainly. `ChangeApplier` refuses to overwrite a field a human has edited by comparing the live value against the last applied `after_json` for that contact. **Deleting that row on a time clock re-arms the sync to silently discard a self-supplied value** — it would turn a retention improvement into a data-integrity defect on a safety-critical column. The data's purpose ends exactly when the contact record ends, so that is when it is erased |
| `bcms_identity_connectors` | Life of the connector. No soft delete and no delete route by design (ADR 0018 §2.2 point 6) | Holds no personal data. The encrypted `client_secret` is a credential and is `$hidden`; rotation is the control, not deletion |

**The retention cost of ADR 0018's schema choice, stated once and not
re-litigated.** A per-field provenance column set on `bcms_contacts` (seven
"last synced value" columns) would let the third row above collapse into the
second, and every staged row could then be purged at eighteen months without
exception. ADR 0018 §2.4 chose the staging table instead, for schema reasons
this register does not dispute. **The price is an indefinitely-held second copy
of each active contact's name, title, department, email and mobile**, and the
mitigation is the contact-linked erasure above. Recording the price is not the
same as asking for the decision back.

> **7.5.1 The third copy, and it is the sharpest finding in this section.**
> `IdentitySyncChange` uses `BcmsAuditable` and **does not override
> `auditExcluded()`**, so it inherits the base list — `updated_at`,
> `push_token`, `raw_response`. On `created`, the trait writes the row's whole
> attribute set into `bcms_audit_logs.after`. **That includes `before_json`,
> `after_json`, `impact_json` and `subject_name`.** `bcms_audit_logs` is
> append-only by design (Blueprint §14), has no purge path, and is the table
> this product treats as immutable seven-year evidence. So on the first
> production sync of a 5,000-person directory, five thousand staff names,
> titles, departments, emails and mobile numbers land in an immutable table
> with no retention and no erasure route — and section 1's "erased, not
> anonymised" commitment becomes unkeepable for those copies.
>
> **This is a duplication with no evidential gain.** ADR 0018 §2.2 point 6 says
> it itself: *"runs and change rows are the audit trail of a directory read"*.
> The staging table **is** the record. An audit row over it should record *that*
> a change was staged, decided and applied, by whom and when — the accountability
> fact — not a second copy of the payload that is already in the row it points at.
>
> **Recommended remediation, owner `backend-engineer`, gate `code-reviewer`, and
> it is one method:** `auditExcluded()` on `IdentitySyncChange` returning
> `['updated_at', 'before_json', 'after_json', 'impact_json']`. Precedent is
> exact — `raw_response` is on the base list for this identical reason
> (§2, §6). `subject_name` is left in, deliberately: an audit row that cannot
> say whose record was changed is not an audit row, and a name without the
> payload is the minimum that makes the trail readable.
>
> **Closed 2026-09-17 (Phase 2C gate-1 remediation):** `IdentitySyncChange::
> auditExcluded()` now excludes `before_json`, `after_json` and `impact_json`
> (`subject_name` kept, per the reasoning above); pinned by
> `Phase2cIdentitySyncTest::no_synced_personal_data_reaches_the_audit_log_through_a_sync_change`.

### 7.6 Residency and the §41 position

Four locations, and only one of them is this product's:

1. **The directory itself** lives in the customer's Microsoft 365 / Entra tenant
   data location. Microsoft's local data residency geographies include **South
   Africa**; they do **not** include Nigeria — the same fact §5.2.8 already
   records for Teams. **A Nigerian bank's staff directory is therefore already
   outside Nigeria before BCMS reads a byte of it**, by a decision taken long
   before this module existed. §5.2.8's inheritance point applies unchanged:
   **BCMS must not be the first place this is written down.** If the bank has a
   §41 position for its Microsoft 365 estate, this inherits it; if it does not,
   that is a finding about the bank and the DPO should be told it surfaced here.
2. **The read in flight.** `graph_base_url` is a per-connector field with **no
   default** — whoever configures the connector chooses the Graph endpoint
   (`graph.microsoft.com`, or a sovereign-cloud host), and **nothing in the
   system records which region that was.** This is the same missing field as
   §5.2.3's Infobip endpoint, for the second time. Low consequence, because the
   read moves data **towards** Nigeria rather than out of it — but the connector
   screen already displays the declared Graph scope, and displaying the endpoint
   host beside it costs one line and closes the gap.
3. **The staged copy** is in the application database, under section 1's
   af-south-1-or-on-prem commitment. **The staging table creates no new
   transfer**: the copy lands where the roster already lives.
4. **The access token** is in the cache, not the database, keyed per connector,
   for `expires_in` minus 300 seconds; never logged, never rendered, never
   persisted (checked in `EntraGraphClient::token()`). It is a credential, not
   personal data, and it is registered only so that nobody looks for it in a
   table.

**§41 position:** reading personal data *from* Microsoft *into* the bank's own
Nigerian-resident system is not an export of personal data out of Nigeria by
this controller, so §41 does not engage on the BCMS side of the boundary.
**§29 does, and it already did** — Microsoft is the bank's processor for the
directory under whatever agreement the bank already holds. What Phase 2C adds is
not a new processor but a **new grant to an existing one**, which is why no new
§29 instrument is owed. The existing agreement should nonetheless be checked to
cover an **application-permission** read of the directory by a third-party
application, which is a different thing from a user signing in; that is the
DPO's, and it is open question 11.

### 7.7 Microsoft as processor, and the app registration's read-only scope

| | |
|---|---|
| **Grant** | App-only client credentials. `POST {token_base_url}/{directory_tenant_id}/oauth2/v2.0/token`, `grant_type=client_credentials`, `scope={graph_base}/.default` |
| **The control is the registration, not the request** | `.default` grants **whatever the app registration already holds**. `EntraGraphClient::defaultScope()` says so in its own docblock. So the customer-side instruction is the control: the registration must hold `User.Read.All` — or `Directory.Read.All` where a bank's IT will only grant the broader one — and **no `*.ReadWrite.*` permission of any kind** |
| **Why that is not left as an assertion** | Three mechanisms, per ADR 0018 §3.2. (i) `App\Contracts\Bcms\DirectoryClient` **declares no write method** — there is nothing to call. (ii) `Phase2cReadOnlyGuardTest` asserts no `Http::post|put|patch|delete` anywhere under `app/Services/Bcms/Identity` targets a Graph host; the single POST is the token endpoint and is allowlisted by URL shape. (iii) `testConnection()` reads the token's own `roles` claim and returns the scopes it **actually** came back with |
| **(iii) is the NDPA-relevant one** | It is the only place in the chain where a bank discovers that its IT granted `Directory.ReadWrite.All` — on the connector screen, at configuration time, rather than in a penetration test eighteen months later. A read-only claim that cannot be falsified by the product is a claim; this one can be |
| **Credential handling** | `client_secret` is **TEXT with an `encrypted` cast** (never a json column — MariaDB's inline `json_valid()` CHECK rejects a base64 envelope, the `connectors.config` defect), `$hidden` on the model, and write-only on the screen: the field shows whether a secret is set, never its value. `credential_expires_on` is operator input, and `BcmsWatchdog` warns inside thirty days, because a lapsed secret silently freezes the roster — the failure mode this module exists to prevent, applied to itself |
| **Never written back** | Section 1's line stands and is now enforced rather than promised: `ad_synced_at` records a read; there is no column that could record a write, and the interface has no method that could perform one |

### 7.8 The audit trail

`BcmsAuditable` on all three models — actor, timestamp, before/after, IP —
plus ten explicit `recordAudit()` events, of which five concern a person's
record: `identity.change.approved`, `identity.change.rejected`,
`identity.change.auto_applied`, `contact.synced`, `contact.deactivated`. So
*"who decided that Musa Bello had left, and when"* has an answer, in an
append-only table, without a human assembling it. The duplication finding at
7.5.1 is about the **payload** those rows carry, not about their existence.

**The two log sinks in this namespace, checked field by field against §5.0's
rule on 2026-09-17:**

| Call site | Message | Fields, and nothing else |
|---|---|---|
| `DirectorySyncService::runAlreadyInFlight()` | `BCMS identity sync declined: a run is already in flight for this connector` | `organization_id`, `connector_id`, `run_id` |
| `DirectorySyncService::abort()` | `BCMS identity sync aborted while staging changes` | `organization_id`, `run_id`, `exception_class` (`$e::class`) |

No name, no address, no number, no URL, no query string, no credential.
`DirectorySyncException` carries only `errorClass`/`errorCode`;
`EntraGraphClient` reads Graph's bounded `error.code` or the bare HTTP status and
**never** `error.message`, and `curlErrno()` takes a libcurl integer out of
Guzzle's handler context and nothing else. **§5.0's rule holds in this
namespace.**

> **Two sinks §5.0 declared *unassessed* are now remediated — re-checked
> 2026-09-17 and closed.** `Models/Bcms/Concerns/BcmsAuditable` and
> `Listeners/Bcms/MaterialiseReminderLadder` both now carry a bounded,
> value-free diagnostics method that reads the SQLSTATE and the driver error
> number and deliberately never reads `getMessage()` or `errorInfo[2]` — the
> `BcmsAuditable` docblock states the reason this register would have given:
> `QueryException::formatMessage()` inlines every bound value, and the bound
> values on that path are a contact's own name, email and mobile.
> **Round 2's known gap (2) is closed.** So is **round 2's known gap (1)**:
> `SmsGatewayChannel::reasonFrom()` no longer returns the provider's prose — it
> passes it through `classifyProviderError()` to a fixed category label and
> builds `failed_reason` from `provider()`, the HTTP status and that label, and
> the same `$errorPath` is nulled out of `raw_response` so the number cannot
> reappear one JSON key over.

### 7.9 DSAR — a contact's staged rows are part of their record

**Position.** A data subject asking what the bank holds about them is entitled
to their `bcms_identity_sync_changes` rows. These are not system telemetry: they
are a dated record of what the organisation believed about that person's name,
job, department, site, email, mobile and reporting line, who decided to accept
it, and when it was applied. That is squarely within a subject access request.

**The export must be keyed on two things, and this is the part a naive
implementation gets wrong.**

- `contact_id`, for everyone who is or was a contact; **and**
- `directory_object_id`, for the case `contact_id` never gets filled —
  **a joiner whose change row was rejected has `contact_id = NULL` permanently.**
  That person exists in the staging table, was assessed by the organisation, and
  appears in **no** contacts-first query. A DSAR export built by walking from
  `bcms_contacts` outwards silently under-reports exactly the population with
  the least visibility.

**Shape.** Per subject: the contact row and its delivery history (section 1's
existing commitment), plus every staged change — `kind`, `before_json`,
`after_json`, `decision`, `decided_by`, `decided_at`, `applied_at`, and the run
with its `trigger` and `started_at`. **`impact_json` is included**: a person is
entitled to know the system recorded that they were Tier 2 on a named call tree
with twelve staff downstream of them. It is a fact about them and it is one that
could affect them.

**One third-party element.** `after_json._manager_object_id` is a pseudonymous
identifier of another natural person. Recommendation: **resolve it to the
manager's name, or omit it** — an opaque GUID in a DSAR pack is neither useful
to the subject nor safe to hand over unexplained. DPO's call.

**Status: NOT BUILT, and the commitment has now slipped a phase.** Section 1 has
said "DSAR: export of a contact and its delivery history, per subject. Phase 2C"
since Phase 0. Phase 2C has closed: its eight routes are the connector screen,
test, sync, the run list, the run detail and two decide endpoints — **none is an
export**, and `rg -i 'dsar'` across `app/` returns nothing. Section 1's cell has
been corrected rather than left pointing at a phase that is over. The work moves
to **Phase 2D or a hardening phase**, and it is open question 9.

### 7.10 The GAID 2025 re-check

The Phase 10 clause map flagged that this register was written against the Act
and should be re-checked against **NDPC GAID 2025** (in force 19 September
2025). Re-checked on **2026-09-17**, and the honest framing first:

**The GAID original could not be read.** `ndpc.gov.ng`'s published PDF did not
yield extractable text through the tooling available here (117 pages of
compressed streams). Everything below is corroborated **by effect, from
secondary legal commentary**, exactly as §5.4 was, and the article and schedule
numbering must be pinned by the client's counsel before any of it is quoted in a
filing or a DPIA.

**What re-checks clean, unchanged from §5.4:** the NDPR-era Whitelist has no
legal effect; adequacy is assessed against factors rather than a country list;
SCCs, BCRs and certifications may be used subject to the Commission's approval.

**Three things that are new to this section:**

1. **A DPIA is very likely required before the first production sync, and it is
   a go-live gate.** GAID mandates a DPIA for a listed set of activities;
   secondary sources consistently name **systematic monitoring** and **financial
   services** among them, and require the DPIA to be **vetted by a certified DPO
   before processing commences**. A nightly automated read of an entire staff
   directory, performed by a bank, is at minimum arguable on both limbs. This
   register's position is that **the client's DPO must run and have vetted a
   DPIA before the connector is switched on in production**, and that the DPIA
   has to weigh the mitigations this section enumerates — read-only, work
   attributes only, no group membership, no personal channel, no consent state,
   no location, staged rather than applied, human-reviewed, permission-split.
   Those are a strong set and the DPIA should say so. Open question 8.
2. **Retention documentation.** GAID requires retention periods to be
   documented and communicated to data subjects, and defines *minimum necessary*
   as "the least possible data essential to the fulfilment of the specified
   purpose". The `$select` list and the two `ChangeApplier` constants are that
   documentation at the data layer; 7.5 is it at the policy layer. **What fails
   the test today is not the design. It is that nothing deletes anything.**
3. **A possible six-month default, recorded as NOT corroborated and
   deliberately not relied on.** One secondary source states that GAID sets a
   default of **six months** after the purpose of processing has been achieved,
   where no other law specifies a period. **Two further sources fetched in full
   do not mention it**, and the original could not be read. If it is right it is
   materially shorter than 7.5's eighteen months, than section 1's "employment
   plus 90 days" and than section 2's twelve months for exercise traffic, and it
   would change several figures in this register. **The DPO or counsel must pin
   it against the GAID original.** It is open question 10. It is not adopted
   here, because adopting an uncorroborated number is how a register acquires a
   figure nobody can source.

### 7.11 Sufficiency — could an examiner be shown this in one click?

**Ask: "show me how your emergency roster is kept accurate, who approved each
change, and what you were permitted to read from your directory to do it."**

**Yes, in one click, and this is the best-evidenced section in this register.**
`identity/runs` lists every run with its trigger, its page and object counts,
its joiner/leaver/mover/change tallies and its outcome; `identity/runs/{run}`
shows every staged change with before → after, the call-tree impact, the
decision, the decider and the timestamp; `bcms_audit_logs` carries the same as
an append-only trail; and the connector screen's scope display answers *"what
were you permitted to read"* from the token's own claim rather than from a
policy document. A human assembles nothing.

**Two questions it cannot answer, and they are both about erasure and the
subject rather than about the examiner:**

- *"Show me everything you hold about this one person."* The DSAR export does not
  exist (7.9).
- *"Show me that you deleted it when you said you would."* Nothing deletes
  anything (7.5, section 10), and the audit-log copy could not be deleted even
  if something did (7.5.1).

**Gate position.** ADR 0018 §10's five items — lawful basis, purpose limitation,
retention for `before_json`/`after_json`, residency, DSAR — are now on the
record, which is what the gate asked for and what was blocking it. **This entry
is not a clearance of the phase.** It names three things that must have an owner
before a production sync runs against a real directory: the DPIA (7.10, the
DPO's), the purge command (section 10, backend- and reliability-engineer's), and
the audit-log duplication (7.5.1, backend-engineer's).

---

## 8. Exercise execution evidence — photographs, observer commentary and survey free text (Phase 9)

**Owed since Phase 9 and not written until now.** `docs/bcms/phase-9-aar-clause-map.md`
refinement 13 recorded that another agent held `docs/compliance/` that session
and listed three required additions, and stated that the Definition of Done's
"personal data touched → NDPA note" line was **not satisfiable by Phase 9 until
this landed**. It lands here.

### 8.1 `bcms_evidence` — photographs of identifiable staff

| | |
|---|---|
| **Purpose** | To evidence that an exercise happened, that an assembly or roll-call worked, and that a readiness task was completed — ISO 22301 8.5, stamped `iso22301.8.5.report` / `.exercise` |
| **Categories** | An image of identifiable individuals, at a named site, at a known time (`captured_at`), uploaded by a named user. **And, unintentionally, more than that**: a photograph of an assembly point can reveal a disability, a pregnancy, a religious dress, a medical device — which the NDPA treats as **sensitive personal data** under stricter conditions, collected by nobody's intention |
| **Lawful basis** | Legitimate interest in evidencing the exercise, plus the employment relationship. **Deliberately not consent** — consent from an employee standing at their own workplace fire drill is not freely given, and evidence that can be withdrawn is not evidence. Naming the wrong basis here would be worse than naming a hard one |
| **The control is at capture, not at storage** | A wide shot of a muster point evidences a muster; a portrait evidences nothing a headcount does not. The rule for the screen spec and for the facilitator curriculum is **photograph the assembly, not the faces**, and where a face is the evidence (a named warden at their post) the `caption` says why. `caption` is 255 characters of free text and it **will** name people |
| **Immutability** | `hash` is the sha256 of the bytes as stored and `locked_at` freezes the row at finalisation (ADR 0019 §2). That makes it evidence rather than an attachment — which is this register's own standard — and it does **not** make it permanent |
| **Residency** | `FileUploadService::DISK = 'local'`. The bytes sit on the application server's local disk, so they inherit section 1's af-south-1-or-on-prem commitment exactly and no more. **A deployment that later points that disk at an object store in another region moves staff photographs across a border with a one-line config change, and nothing in this product would say so.** Recorded as a residency dependency on the deployment, not as a defect |
| **Retention** | **Proposed 3 years** from the occurrence, matching section 3's training and competence clock and the certification cycle. On expiry the **file is deleted and the row is kept** — the `hash`, `caption`, `uploaded_by` and `captured_at` survive, so the AAR's evidence index still reconciles and an examiner can see that evidence existed and when it was erased. Deleting the row too would make the AAR's own index lie |
| **Security** | ADR 0019 deliberately ships **no `virus_scan_status` column**, because a column that always reads `pending` is the mock tick this module has twice refused. The product-wide "no virus scanner" go-live gap therefore applies to staff photographs as well as to TPRM documents. That is a security-of-processing point under the Act and under §29's requirement that appropriate measures be implemented, and it belongs here because this is where the measures for this data are claimed |

### 8.2 `bcms_exercise_scores.commentary` — observer and evaluator commentary naming individuals

An evaluator writes free text against a scored objective. Nothing prevents *"the
branch manager froze and did not open the runbook"*. **That is employee
performance data collected under a continuity purpose**, and it is the single
most likely artefact in Phase 9 to turn up in a disciplinary conversation it was
never collected for.

**Position: not a defect, and not to be removed.** An evaluation that cannot say
what went wrong is not an evaluation, and an exercise programme that cannot
record failure is the document-accumulation habit this product exists against.
Four controls, in descending order of reliability:

1. **Retention** on the AAR's clock (8.4), not longer.
2. **Read permission** — `bcms.exercise.evaluate` / `bcms.exercise.facilitate`
   to write, and the AAR export behind `bcms.report.export`. That export
   includes scores **with their evaluators**, which is §6.2's "different door"
   problem for a third time. The architect owns the permission boundary.
3. **Purpose-limitation copy on the evaluator's own screen**, not only in this
   register. A rule nobody reads at the moment of writing is not a control.
4. **The evaluator curriculum** (compliance-analyst's content-pack remit) teaches
   *score the process, name the role, not the person*. This is a training
   control, it will fail some of the time, and that is precisely why 1 to 3
   exist.

### 8.3 `bcms_aars.participant_feedback` — survey free text, and a published contract nothing enforces

The `bcms.aar.feedback.v1` schema published in the Phase 9 clause map §2.3 is
explicit: comments carry **role and unit, never `user_id` and never a name**,
because a post-exercise survey that attributes *"the branch manager did not know
who could activate the plan"* to a named individual is performance data under a
continuity purpose and will stop people answering honestly, which destroys the
only value the survey has.

> **Checked 2026-09-17, and this is a finding: the rule is enforced nowhere.**
> `UpdateAarRequest` validates `'participant_feedback' => ['nullable', 'array']`
> with **no nested rules**, and `AarService::update()` puts it through
> `array_intersect_key` and stores it unchanged — `quantitative_results` is
> re-derived against computed values on every read, `participant_feedback` is
> not. So a client may post
> `comments: [{ text, user_id, name, email }]` and it will be stored, returned
> by `AarController::show()`, and written into the examiner-facing pack by
> `AarExportService::build()`. **`bcms.aar.feedback.v1` is a published contract
> that nothing validates.**
>
> Remediation, owner `backend-engineer`, at the Phase 9 re-gate: nested rules on
> the form request pinning `comments.*` to `text`, `role`, `business_unit_id`,
> and a shape assertion in `AarService::update()` so a payload that arrives by
> another route is dropped rather than stored. **Closed 2026-09-17 (Phase 9
> gate-1 remediation):** `UpdateAarRequest` nests the contract's rules with
> `user_id`/`name`/`email` `prohibited` (422, not stripped), and
> `AarService::normaliseFeedback()` whitelists `text`/`role`/`business_unit` for
> every caller; pinned by two tests in `Phase9AarTest`. Note the shipped field is
> `business_unit` (a name string), not `business_unit_id` — the clause map's
> §2.3 schema should read the same.

**Retention for the free text: 12 months**, matching section 2's exercise-traffic
floor and deliberately shorter than the AAR itself. The same reasoning as
section 6's `response_text`: a free-text field asking people how a drill went
will collect health information (*"I could not manage the stairs"*) and
statements about third parties. **The distribution and the mean are the
evidence; the verbatim is the diagnosis**, and a diagnosis has a short useful
life. The AAR's finding survives; the comment that produced it does not need to.

### 8.4 Retention summary and clause codes

| Item | Proposed retention |
|---|---|
| `bcms_evidence` file bytes | 3 years from the occurrence; row retained with hash and caption |
| `bcms_exercise_scores.commentary` | With the AAR — 3 years, or the client's certification cycle |
| `participant_feedback.comments[].text` | 12 months; the distributions and means survive with the AAR |
| `bcms_exercise_participants` check-in data | Unchanged from section 3. `check_in_method = geo` still records **that** a location was used, never the location |

**No new clause ref.** Everything maps to `ndpa.lawful_basis`, `ndpa.retention`
and `ndpa.residency`; the artefacts themselves are stamped
`iso22301.8.5.report` / `.exercise` by the phase that creates them.

---

## 9. Incident, post-incident review and regulator notification records (Phase 10)

**The five register additions listed in `docs/bcms/phase-10-incident-clause-map.md`
§5, which that pass could not write.** Written here.

### 9.1 `bcms_incidents`, `bcms_incident_log`, `bcms_incident_tasks`

| | |
|---|---|
| **Purpose** | Managing a live disruption, and evidencing it afterwards to a regulator — ISO 22320 incident response, ISO 22361 crisis management, CBN incident reporting |
| **Categories** | `declared_by`, `logged_by`, `owner_id` — who declared, who logged, who owed a task. And `bcms_incident_log.content`, a `longText` free-text column written **during a live incident**, which is where the real exposure is: a decision log names the people who decided, and an incident narrative names staff |
| **The population changes here, and it is worth saying out loud** | Sections 1 to 8 are about the workforce. **An incident log is about whoever the incident happened to** — in a bank, very often a customer, by name, with their circumstances. `bcms_incidents` is the first BCMS table whose data subjects are not only staff. That changes the DSAR population, it changes the privacy notice that has to cover it, and it means an incident record can contain financial and sometimes health information about a member of the public |
| **Lawful basis** | **Legal obligation and legitimate interest**, and here — unlike section 7.3 — the legal-obligation limb is properly corroborated: the CBN Risk-Based Cybersecurity Framework requires incident reporting to CBN Banking Supervision **within 24 hours**, NDPA s.40 requires notification of a personal-data breach, and BOFIA/NDIC carry the continuity-of-operations expectation |
| **Retention** | **7 years from `closed_at`**, aligned to section 2's incident-linked line and to the ERM loss-event register the incident already bridges into through `erm_loss_event_id` — **not invented separately**. A regulator may reopen an incident years later, and an incident record that expires before its own notification record answers half a question |
| **The rule that matters most** | **Describe categories, never paste records.** A breach incident's own record must not become a second copy of the breached data. There is **no technical control for this** — `content` is free text and nothing stops a responder pasting a customer extract into it at 3am. The controls are the read permission, the screen copy and the crisis-team curriculum, and this register does not pretend otherwise |

> **Gap: `bcms_incident_log.attachments` is an un-modelled sibling of
> `bcms_evidence`.** It is a plain json column. Whatever it points at has no
> hash, no `locked_at`, no `uploaded_by` column, no kind registry and therefore
> no retention and no residency of its own. This register's own standard is that
> *an uploaded attachment is not evidence; a first-class object with an actor, a
> timestamp, an immutable state and a clause reference is* — and Phase 9 built
> exactly that table for exercises. Incident attachments did not get one.
> Recorded for the architect, alongside ADR 0019's four-kind registry, which
> notes that `incident` is deliberately **not** one of its kinds.

### 9.2 Post-incident reviews share a table with exercise AARs, and they must not share a clock

`bcms_aars` now holds two different artefacts: an exercise AAR (`occurrence_id`
set) and a post-incident review (`incident_id` set), exactly one of the two
enforced in the service layer and in a `saving` guard. Same columns, same
free-text exposure as 8.2 and 8.3 — **different subject matter and therefore a
different retention clock**:

- an exercise AAR follows section 8's **3 years**;
- **a post-incident review follows the incident's 7 years**, because it is part
  of the record of a real event a regulator may reopen.

> **Named now, at schema time, because that is the only moment it is cheap.**
> A purge command that reads `bcms_aars` on the exercise clock without first
> checking which of `occurrence_id` / `incident_id` is populated **will delete
> post-incident reviews six years into their seven-year retention.** This is the
> single most likely defect in the purge command that does not yet exist. It is
> written into section 10's specification as a named requirement rather than
> left for the author to notice.

### 9.3 `bcms_incident_notifications` and the NDPA s.40 overlap

One row per submission per regulator — `regulator` (`cbn` | `ndpc` | `other`),
`basis_clause_ref`, `kind` (`initial` | `intermediate` | `final` |
`supplementary`), `sequence`, `awareness_at`, `due_at`, `submitted_at`,
`submitted_by`, `reference`, `content_snapshot`.

**Two clocks, two regulators, two trigger events, and merging them satisfies
neither.**

| | CBN | NDPC |
|---|---|---|
| **Window** | 24 hours (`config('bcms.incident.notification_windows.cbn_hours', 24)`) | **72 hours** (`ndpa_hours`, 72) |
| **Runs from** | The incident occurring / being detected | The controller **becoming aware** that a personal-data breach occurred |
| **Phased submission** | "Update where the earlier report was incomplete" | **NDPA s.40(2) expressly permits information in phases** |
| **Basis clause ref** | `cbn.rcf.incident_response` | `ndpa.breach_notification` |

Detection and awareness-that-it-was-a-breach are **not the same moment** — an
incident detected on Monday and classified as a personal-data breach on Tuesday
owes its 72 hours from Tuesday and its 24 hours from Monday. That `awareness_at`
is a **stored, per-notification** column rather than one `reporting_due_at` on
the incident is what makes that true in the schema, and `due_at` being stored at
classification and never recomputed on read is what makes the countdown
evidence rather than a render-time artefact. ADR 0020 argued both from the
reporting side; they are recorded here as the **data-protection** reasons,
because they are the reason the NDPC clock can be evidenced at all.

**`content_snapshot`** holds what was actually submitted. For an NDPC submission
that includes the **categories and approximate number of data subjects and of
records** affected, the likely consequences, the measures taken and the DPO
contact point. That is a summary *about* people rather than a copy *of* their
data — **provided 9.1's "describe categories, never paste records" rule holds**,
which nothing enforces. Retention: **7 years with the incident.** A submission
to a regulator is the last thing in this register that should ever be deleted;
it is the artefact that discharges the obligation.

> **Gap, and it is a real s.40(3) exposure rather than a column.** NDPA s.40(3)
> requires that where a breach is likely to result in **high risk to the rights
> and freedoms of a data subject**, the controller communicates **immediately**
> to the data subject, in plain language, with mitigation steps.
> **That obligation is not modelled.** `bcms_incident_notifications.regulator`
> has three cases and none of them is "the data subjects". The product can
> evidence that the NDPC was told. **It cannot evidence that the customers were
> told.**
>
> A fourth `regulator` value would be the wrong fix — a data subject is not a
> regulator, the window is "immediately" rather than a fixed clock, and the
> audience is a resolved population rather than an addressee. The Phase 10
> clause map's own item 4 points at the right shape: *store the text and the
> audience rule, resolved through `ContactResolver`, never a free-typed customer
> list* — which is the EMNS alert record, not a notification row. **Architect's,
> and it should be sized before a bank is told the module covers s.40.**

> **Also not modelled, and smaller:** `bcms_incidents` has **no personal-data
> breach flag and no data-subject count** (checked on the model — `rg -i
> 'personal_data|breach|data_subject'` returns nothing). The NDPC reference has a
> home now (`bcms_incident_notifications.reference`, with `cbn_reference` retired
> in place), but the **flag** and the **count** do not. The Phase 10 clause map
> §2.3 requires categories and an approximate number of data subjects within 72
> hours; today both live as free text inside `content_snapshot`, so *"how many
> breaches did we report last year, and how many data subjects did they affect"*
> is not a query this system can answer. That is a board-pack and an NDPC
> audit-return question, not only an examiner's.

**Clause codes.** `ndpa.breach_notification` is **already published** in
`App\Enums\Bcms\IsoClauseRef` — Phase 10 added the one case, per its clause map
§5, and it is seeded in `Database\Seeders\Bcms\Reference\ClauseRefs`.
**This section publishes no new clause ref.** `ndpa.processor_agreement`
remains proposed and unpublished on §5.4's terms.

---

## 10. The purge command that does not exist — one specification, four sections

Sections 1, 2, 6.1, 7.5, 8.4 and 9 all state retention figures. **Nothing in the
product enforces any of them.** Re-checked 2026-09-17: the only pruning command
in `app/Console/Commands` is `PruneLlmUsageEvents`, which is TPRM's. The factual
retention of every personal-data item in this register is **indefinite**, and
that is the sentence the DPO should read first.

**One command, not four.** Four separate purge commands is four separate ways to
forget one, and the sections above share clocks and foreign keys. The
specification, for `backend-engineer` to write and `reliability-engineer` to
schedule and watchdog:

| Requirement | Why |
|---|---|
| Per-tenant loop, skipping tenants with `features.bcms` off | Every other BCMS command already does this |
| **Idempotent and safe to re-run** | A purge that double-runs must be a no-op, not a second deletion pass with different arithmetic |
| **Portable SQL only** — no CTE, no window function, no raw JSON function | MariaDB 10.4 is production. `CalendarService.php:410` is the worked example, and the product has already paid for the alternative |
| **Nulls columns where the row must survive** (7.5's staged rows, 8.1's evidence rows) rather than deleting the row | A run's counters and an AAR's evidence index must still reconcile after a purge, or the purge makes the evidence lie |
| **Checks which edge is populated on `bcms_aars`** before applying a clock | 9.2. A PIR on the exercise clock is deleted a year early and nothing says so |
| **Erases a contact's staged rows *with* the contact**, not on a time clock | 7.5. A time clock re-arms the sync to overwrite a self-supplied value |
| **Writes its own audit row per tenant per run** — how many rows nulled, how many deleted, under which clock | An erasure nobody can evidence is indistinguishable from data loss when an examiner asks *"show me that you deleted it"*. It is also what makes GAID's retention-documentation expectation answerable |
| **Cannot reach `bcms_audit_logs`** | That table is append-only by design, which is why 7.5.1 has to be fixed at the write rather than at the purge |
| Scheduled weekly, `withoutOverlapping()`, on the existing maintenance schedule | No new queue. ADR 0018 §4's reasoning about a fifth queue applies |

**Until this exists, every retention figure in this register is a statement of
intent.** That is tolerable while no gateway is configured and no connector is
active. It stops being tolerable on the day either one goes live, and Phase 2C
is the phase that makes a connector possible.

---

## Open questions for the client's DPO — Week 1 blockers

These are listed as a Week-1 non-engineering blocker in Orchestration §9 and are
not an agent's to answer.

1. **Lawful basis for holding personal mobile numbers.** Consent is the position
   this module implements. If the client's own legal function takes a
   legitimate-interest position instead, `consent_status` becomes a record of a
   notification rather than of a permission, and the life-safety exception
   becomes unnecessary. Either works; the register must say which.
2. **Retention for exercise-linked delivery records.** 12 months is proposed
   above. A client whose certification cycle is three years may want to match it.
3. **Cross-border transfer basis** if any channel provider processes outside
   Nigeria — which most WhatsApp and push providers do. This is an NDPA §41
   question per provider and it belongs in the TPRM engagement record for that
   provider, not here.
   **Superseded at Phase 7 and no longer hypothetical.** `config/bcms-gateways.php`
   now names the providers, so the question has an addressee: **section 5 above**
   enumerates all ten, and **section 5.3** lists what the DPO must answer for
   each. The part of the original wording that still holds is that the *evidence*
   belongs in the TPRM engagement record — see 6.3.
4. **Meta's standard transfer terms** — do they satisfy §41 for this bank, yes or
   no? A "no" is a real answer and it means WhatsApp carries no roster traffic
   (5.2.4).
5. **`raw_response` retention at 90 days**, which deliberately diverges from
   §2's 7-year line for incident traffic (6.1). The DPO either agrees or sets a
   different figure; what cannot stand is the current position, which is
   indefinite because nothing deletes anything yet.
6. **Verbatim `response_text`** may contain health information about the sender
   and personal data about third parties (6). Confirm the retention, the read
   permission and the privacy-notice wording.
7. **The evidence export's `Address` column** (6.2): who may hold
   `bcms.report.export`, and is masked evidence still evidence?

### Added at Phase 2C, 2026-09-17

8. **A DPIA for the Entra directory sync** (7.10 item 1). GAID mandates a DPIA
   for a listed set of activities including systematic monitoring and financial
   services, vetted by a certified DPO **before processing commences**. A
   nightly automated read of the whole staff directory by a bank is arguable on
   both limbs. **This register's position is that it is a go-live gate for the
   connector.** The DPO either runs it or records, with reasons, why it is not
   required.
9. **The DSAR export, which Phase 2C did not build** (7.9). Two decisions are
   the DPO's rather than an engineer's: whether `after_json._manager_object_id`
   is resolved to the manager's name or omitted, and whether the export is
   offered self-service or fulfilled by the CRO. The engineering requirement —
   that it is keyed on `directory_object_id` **as well as** `contact_id`, or it
   silently under-reports rejected joiners — is not a DPO question and is
   recorded as a build requirement.
10. **Does GAID set a six-month default retention** after the purpose is
    achieved where no other law specifies one (7.10 item 3)? **Not
    corroborated**, and it is load-bearing: if it does, it is shorter than
    section 1's "employment plus 90 days", section 2's twelve months and 7.5's
    eighteen, and several figures in this register move. Counsel pins it against
    the GAID original; this register will not adopt an uncorroborated number.
11. **Does the bank's existing Microsoft agreement cover an
    application-permission read of its directory by a third-party application?**
    (7.6). Not a new processor — a new grant to an existing one — so no new §29
    instrument is owed, but the existing one should be read rather than assumed.
12. **Retention for incident and post-incident records at 7 years** (9.1, 9.2),
    aligned to the ERM loss-event register rather than set independently. And
    the harder one: **an incident log can contain personal data about customers
    and members of the public**, which is a different population from the rest
    of this register and needs its own line in the privacy notice.
13. **NDPA s.40(3) — communication to data subjects — is not modelled** (9.3).
    Before any statement that this module covers s.40, the DPO should confirm
    what the bank's existing customer-breach-communication process is and
    whether it is expected to live here at all. If it is, it is a sized piece of
    work and not a column.

## HANDOFF — Phase 0 (historical)

**Phase:** P0 — Foundations & schema freeze
**Agent:** compliance-analyst
**Status:** complete for Phase 0 — this register is reopened by every phase that touches personal data, and Phase 2C owns the largest addition
**Delivered:** this file
**Contracts touched:** none
**Assumptions made:** consent as the basis for personal channels; 12-month retention for exercise-linked delivery records; the vital-interests exception scoped to `is_life_safety` traffic only
**Known gaps:** the three open questions above; the retention jobs themselves are Phase 2C
**Next agent:** integrations-engineer at Phase 2C, before the first directory sync writes a real person's number

## HANDOFF — Phase 7, round 1 (superseded 2026-09-12)

**Phase:** P7 — EMNS (multi-channel emergency notification), Gate 2 defect 6
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** `docs/compliance/ndpa-register.md` — new §5 (the ten processors, per-processor residency and §41 position, who must answer each unknown, the §41/§43/§29/GAID framework with sources), new §6 (`address`, `raw_response`, `response_text`: what lands in them, proposed retention, the fact that nothing enforces it, the evidence-export observation, the sufficiency verdict), and open questions 3–7 rewritten and extended
**Clause refs published:** none new. Everything maps to the already-published `ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency`. A fourth code for §29 processing agreements (`ndpa.processor_agreement`) is **proposed and deliberately not published** — it needs an enum case, a seeded `bcms_clause_refs` row and a `Phase0FoundationsTest` assertion first, owned by backend-engineer
**Contracts touched:** none. Documentation only; no application file read-modified, none of the six files under concurrent edit touched
**Assumptions made:** 90 days for `raw_response` and 12 months for exercise-linked `response_text` are **proposals awaiting the DPO**, not settled policy. Africa's Talking is treated as a cross-border transfer until a contract says otherwise. Teams and Slack are assumed to inherit a bank-level §41 position that may not exist
**Known gaps:** residency UNKNOWN for Termii, Africa's Talking and the mail transport; vendor not chosen for voice, push and USSD; Meta's transfer terms undecided; **no retention or purge job exists anywhere in BCMS**, so current retention is indefinite; `consent_status` has no capture screen, so §43(1)(a) consent is not available as a transfer basis today; the GAID's exact article/schedule numbering is cited by effect and should be pinned by the client's counsel before use in a filing
**Next agent:** qa-engineer to re-run the Phase 7 gate cycle. Then architect, for two items this review raised and cannot decide: the `bcms.report.export` / `bcms.contact.export` permission boundary on the evidence CSV (§6.2), and the BCMS-gateway-as-TPRM-third-party contract that turns §5 into a one-click export (§6.3)
**Verification run:** `config/bcms-gateways.php` read as authoritative — ten processors registered, one (`config('mail.default')`) not in the file. Adapters read to establish what is transmitted: `HttpChannel`, `SmsGatewayChannel`, `WhatsAppCloudChannel`, `VoiceTtsChannel`, `WebPushChannel`, `UssdChannel`, `WebhookChannel`, `SmtpEmailChannel`, `ChannelRegistry`, `TemplateRenderer`, `AlertDispatcher`, `RollCallService`, `InboundResponseHandler`, `AlertWebhookController`, `EvidenceExport`, `AlertController::evidence()`. ~~Confirmed independently that no payload, address or body is logged: three `Log::` calls exist in `app/Services/Bcms`, none in `Emns` or `Notification`.~~ **WITHDRAWN 2026-09-12.** This sentence was true when written and was falsified by the advisory-11 fix in the same commit, which added three `Log::` calls under `Notification/Channels/` carrying exception messages that could hold staff email addresses, destination MSISDNs and API keys. It is struck rather than deleted because a first-person verification statement that quietly changed its number would be the same defect a third time. The leak is fixed and the current, count-independent statement is **§5.0**. Confirmed no `BCMS_*` gateway variable appears outside `config/bcms-gateways.php`. Confirmed no BCMS retention command exists. Confirmed `consent_status` is written only by seeders and tests. Sources searched and cited in §6.4 (NDPA §§29/41/42/43, NDPC GAID 2025, Meta local storage regions, Slack residency regions, Microsoft 365 geos, Infobip, Termii, Africa's Talking). No test suite run. **Examiner walkthrough — "show me every processor that receives staff personal data, the basis and the location": still assembled by a human from a config file; acceptable only while no channel is configured, and §6.3 names what makes it one click.**

## HANDOFF

**Phase:** P7 — EMNS, Gate 2 round 2 (register corrections) · concurrent with TPRM 11a re-gate
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** `docs/compliance/ndpa-register.md` — new **§5.0** (the application log as a declared, non-personal sink: the rule, the three declared call sites with their exact fields, how it was checked and how to re-check it, and two unassessed sinks outside the adapters named as unassessed); new **§5.1.1** (the acknowledgement token, current tree vs ADR 0016's committed format, the ordinal exposure); a fourth row in **§6** for `failed_reason`; the round-1 handoff's logging sentence struck and annotated rather than silently rewritten
**Clause refs published:** none new. Everything still maps to `ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency` in `App\Enums\Bcms\IsoClauseRef`. `ndpa.processor_agreement` remains **proposed and unpublished**, on the same terms as round 1
**Contracts touched:** none. Documentation only. No application file, test or config was modified; nothing outside `docs/compliance/` was written
**Assumptions made:** that ADR 0016 is implemented as written — §5.1.1 says which half is verified in the tree and which half is committed but not yet built, and does not verify the second half in the present tense. That the `curl_errno` vocabulary is libcurl's and therefore closed, which is the code's own stated basis and is checkable against `curl.se/libcurl/c/libcurl-errors.html`
**Known gaps:** (1) `SmsGatewayChannel::reasonFrom()` still writes a provider's verbatim error string into `failed_reason`, which can echo a destination MSISDN into a column §6.1 proposes to keep for seven years — **opened, not closed**, owner `backend-engineer`. (2) `MaterialiseReminderLadder` and `BcmsAuditable` log `$e->getMessage()` verbatim and **nobody has assessed what a database driver's exception text can echo** — recorded as unassessed, not as cleared. (3) Everything round 1 listed remains open: residency UNKNOWN for Termii, Africa's Talking and the mail transport; no vendor for voice, push, USSD; Meta's transfer terms undecided; **no BCMS retention or purge job exists**, so retention is indefinite in fact
**Next agent:** `qa-engineer`, then `code-reviewer` for the Phase 7 re-gate. `backend-engineer` owns gap (1) and ADR 0016 §1's implementation; when the latter lands, §5.1.1's second row becomes verifiable in the present tense and somebody should verify it
**Verification run:** `HttpChannel.php` (both `Log::warning` arrays and `curlErrno()`) and `SmtpEmailChannel.php` read field by field, 2026-09-12 on `integration/tprm-bcms`; every field in §5.0's table matches the code, including `smtp_code`'s `?: null`. `rg 'Log::|logger\(|report\('` across `app/**/Bcms/**` to find sinks outside the adapters — four found, two named as unassessed. `SmsGatewayChannel::reasonFrom()` and the other three `DeliveryReceipt::failed()` reason strings read, which is where gap (1) came from. ADR 0016 §§1–3 and §6 read in full; `AlertDispatcher::tokenFor()` read to establish the current format is still bare 16-hex, so §5.1.1 distinguishes the two states. No test suite run; no database touched. **Examiner walkthrough — "show me what your emergency-notification system writes to its application log about a person": answerable from §5.0 in one read, by rule and by field rather than by a count that goes stale; the honest answer is a provider string, an exception class name and a bounded numeric code, plus two sinks outside the adapters that are declared unassessed rather than declared safe.**

## HANDOFF

**Phase:** P2C — BCMS identity sync (Microsoft Entra ID), gate-1 blocker per ADR 0018 §10 and `docs/bcms/phase-2c-entra-work-order.md` §10. Also discharges two register entries owed since Phase 9 and Phase 10
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** `/Users/mac/Documents/devs/laravel/grcsuite/riskerm/docs/compliance/ndpa-register.md` — new **§7** (the Entra sync: processing activity; the exact eleven Graph attributes read and the ones deliberately never read including all group membership; the `NEVER_WRITE` list and what it means for §1's consent architecture and §4's location columns; lawful basis as legitimate interest with CBN supplying the purpose and not the authority; seven purpose-limitation controls; three retention clocks one of which is purpose-bound rather than time-bound; **§7.5.1** the audit-log third copy; residency across four locations and the §41/§29 position; the app registration's read-only scope and the three mechanisms that enforce it; the audit trail and both log sinks checked field by field; the DSAR position and the `directory_object_id` key a naive export misses; the GAID 2025 re-check; the sufficiency verdict). New **§8** (Phase 9: assembly-point photographs, observer commentary, survey free text, and the finding that `bcms.aar.feedback.v1` is enforced nowhere). New **§9** (Phase 10: incident and PIR records, the customer population, the two clocks, the s.40(3) gap). New **§10** (the single purge command, specified). Two corrected cells in §1; six new open questions (8–13)
**Clause refs published:** **none.** Everything maps to `ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency` and — for §9 only — `ndpa.breach_notification`, all four already cases in `App\Enums\Bcms\IsoClauseRef` and already seeded. `ndpa.processor_agreement` remains proposed and unpublished on §5.4's terms. **Phase 2C adds no clause ref and needs none:** the directory read is a means, and the continuity artefacts it feeds carry the stamps
**Contracts touched:** none. Documentation only. No application file, migration, test, config or seeder was modified; nothing outside `docs/compliance/ndpa-register.md` and one bullet in `docs/bcms/phase-2c-notes.md` §5 was written, and neither is under concurrent edit by another agent
**Assumptions made:** (1) 18 months for non-provenance staged rows, 24 months for sync runs, 3 years for exercise photographs and evaluator commentary, 12 months for survey free text and 7 years for incident/PIR/notification records are **proposals awaiting the DPO**, not settled policy — the 7-year figures are aligned to existing lines in this register and to the ERM loss-event register rather than invented. (2) The deployment honours `FileUploadService::DISK = 'local'` on a host inside the af-south-1-or-on-prem commitment; a later move of that disk to object storage is an unregistered cross-border event and §8.1 says so. (3) `bcms_identity_sync_runs` holds no personal data — asserted from a column-by-column read, and it would be falsified by any future column carrying a provider message. (4) The CBN framework's 24-hour incident-reporting window and its BCP/IRP requirement are taken from secondary corroboration of the 2024 DMB/PSB framework, consistent with what Phase 0 and the Phase 10 clause map already recorded
**Known gaps:** (1) **No purge command exists anywhere in BCMS** — §10 specifies it; every retention figure in this register is intent, and the factual position is indefinite retention. Owner backend-engineer, schedule reliability-engineer. (2) **§7.5.1 — `IdentitySyncChange` copies `before_json`/`after_json` into the append-only audit log** through the base `auditExcluded()`; one-method fix recommended, owner backend-engineer, **should be fixed before the first production sync**, not before gate 1. (3) **The DSAR export does not exist** and the Phase 0 commitment has slipped past the phase that owned it (§7.9). (4) **§8.3 — `participant_feedback` accepts any shape**; the published `bcms.aar.feedback.v1` rule that comments carry role and unit and never a name is enforced by nothing, owner backend-engineer at the Phase 9 re-gate. (5) **§9.3 — NDPA s.40(3) data-subject communication is not modelled at all**; architect's, and it is bigger than a column. (6) **§9.1 — `bcms_incident_log.attachments` is an un-modelled sibling of `bcms_evidence`**: no hash, no lock, no uploader, no retention. (7) A DPIA is probably required before the connector goes live and is the DPO's (§7.10). (8) The GAID original could not be read through the available tooling; every GAID statement is corroborated **by effect** from secondary commentary and the article numbering must be pinned by counsel. (9) The possible six-month GAID default retention is **not corroborated** and deliberately not adopted
**Next agent:** `qa-engineer` for the **Phase 2C re-gate (gate 1)**. The gate-1 blocker this entry was raised against is discharged: ADR 0018 §10's five named items are on the record. Nothing in §7 asks qa-engineer to test a document — the two items a test could hold are §7.5.1's `auditExcluded()` override and §8.3's form-request rules, and both are named with owners rather than smuggled into this phase's criteria. After gate 1, `code-reviewer` for gate 2; `backend-engineer` owns gaps (2) and (4); `architect` owns gaps (5) and (6) and the review-queue permission observation at §7.4
**Verification run:** Read in full on `integration/bcms-remaining`, 2026-09-17: `docs/adr/0018-bcms-phase-2c-is-entra-only.md` (all sections), `docs/bcms/phase-2c-entra-work-order.md`, `database/migrations/2026_09_17_120001_create_bcms_identity_tables.php`, `app/Services/Bcms/Identity/ChangeApplier.php` (both constants read literally, not summarised), `app/Services/Bcms/Identity/EntraGraphClient.php` (the `$select`, the single POST, the token cache, `scopesFromToken()`, `classifiedFailure()`, `curlErrno()`), `app/Support/Bcms/DirectoryAttributeMap.php`, `app/Services/Bcms/Identity/ChangeDetector.php` (to establish exactly what lands in `before_json`/`after_json`/`subject_name`), `app/Services/Bcms/Identity/ImpactAssessor.php` (to establish that `impact_json`'s `headline` carries the subject's own name and no third party's), `app/Models/Bcms/IdentitySyncChange.php`, `app/Models/Bcms/Concerns/BcmsAuditable.php`, `app/Presenters/Bcms/IdentityPresenter.php`, `app/Enums/Bcms/IsoClauseRef.php`, `app/Enums/Bcms/NotificationRegulator.php`, `app/Enums/Bcms/NotificationKind.php`, the Phase 9 and Phase 10 migrations, `app/Services/FileUploadService.php` (`DISK = 'local'`), `docs/bcms/phase-9-aar-clause-map.md` §2.3 and refinement 13, `docs/bcms/phase-10-incident-clause-map.md` §§2.1, 2.3, 5, 6. **Re-checked and found closed:** round 2's known gaps (1) `SmsGatewayChannel::reasonFrom()` and (2) `BcmsAuditable` / `MaterialiseReminderLadder` `getMessage()` — both now bounded and value-free, read in the code rather than taken from a note. **Re-checked and found still true:** no BCMS purge or retention command exists (`app/Console/Commands` enumerated; `PruneLlmUsageEvents` is TPRM's); no DSAR path exists; `bcms_incidents` has no personal-data-breach flag or data-subject count. Sources searched for the regulatory statements: NDPA s.25's lawful bases including legitimate interest ([Lexology](https://www.lexology.com/library/detail.aspx?g=ae8ccbd5-8368-4fd7-bd3c-f9c826f12bf3)), GAID 2025's DPIA triggers and audit expectations ([DLA Piper](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/), [Mondaq](https://www.mondaq.com/nigeria/privacy-protection/1606106/the-nigeria-data-protection-commission-issues-the-general-application-and-implementation-directive-2025-gaid)), GAID's minimum-necessary and retention framing ([Mondaq, data minimisation and retention](https://www.mondaq.com/nigeria/privacy-protection/1764584/data-protection-data-minimisation-and-retention-keeping-only-what-you-truly-need)), the CBN Risk-Based Cybersecurity Framework for DMBs and PSBs 2024 ([CBN](https://www.cbn.gov.ng/Out/2024/BSD/CBN%20Risk-Based%20Cybersecurity%20Framework%20for%20DMBs%20and%20PSBs_2024.pdf)). **The GAID PDF at ndpc.gov.ng was fetched and could not be parsed to text**, so no GAID article number is quoted anywhere in §7.10 — the instrument and its effect are cited, as §5.4 already does. **Could not corroborate:** any CBN provision requiring a bank to read its identity directory or to hold an emergency roster in a prescribed form — which is why §7.3 makes legitimate interest the operative basis rather than legal obligation; and the six-month GAID default retention, which one source asserts and two fetched in full do not mention. No test suite run; no database touched. **Examiner walkthrough — "show me how your emergency roster is kept accurate, who approved each change, and what you were permitted to read from your directory": answerable in one click from `identity/runs` → the run → the change queue, with the token's own `roles` claim answering the permission half. The two questions that fail are the subject's, not the examiner's — "show me everything you hold about me" (no DSAR export) and "show me that you deleted it when you said you would" (nothing deletes anything, and the audit-log copy could not be deleted if it did).**
