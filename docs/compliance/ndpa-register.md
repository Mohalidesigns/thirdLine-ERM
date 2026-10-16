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
| **Lawful basis** | Performance of the employment contract; ~~legal obligation for the mandatory records~~ **CORRECTED 2026-09-23 (§11.4):** legitimate interest. ISO 22301 is a voluntary standard, not law. Its mandatory-record requirement (7.2 d), "retain appropriate documented information as evidence of competence") supplies the *purpose*, not a legal-obligation basis. This is §7.3's reasoning, applied a second time |
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
| `bcms_alerts.template_variables` (ADR 0024) | Values the operator types at compose time, which are rendered into the message: `assembly_point`, `bridge`, `workaround`, `next_update`, `lessons_summary` (up to 300 characters) and `additional_instructions`. They are free text typed during an incident, and **they can name people** | Held on `bcms_alerts` under §2's lawful basis and retention (7 years for incident-linked traffic, 12 months for exercise traffic). They are part of what was sent. The only other place they land is a simulation delivery's `raw_response.would_have_sent` (the rendered body, registered above under `bcms_notification_deliveries.raw_response`, with the 90-day retention proposed below, still awaiting the DPO); the alert evidence export carries no message body. They are written once at compose time with no edit route (ADR 0024 §1), so what is held is what went out. There is no new sink and no new category. The "describe roles, not people" rule from §9.1 applies to these fields and is taught, not enforced |
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

> **CORRECTED 2026-10-16 (§12).** "Match exactly one open recipient" is no longer
> true for a reply that reads as **needs help**. Since the owner ruling of
> 2026-10-16, a token-less help reply is applied to **every** open recipient the
> number fits, up to 50, across organisations. A safe, not-on-site or
> unclassified reply is still matched only when exactly one candidate exists.
> The `from` number is still not stored. §12 registers the change.

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

> **Closed in the tree 2026-10-16 (Phase 7 certification; see §12.6).**
> `AlertController::evidence()` still requires `bcms.report.export`, but
> `Address` and `Response text` are now included only when the requester also
> holds `bcms.contact.export` (`EvidenceExport::RESTRICTED_COLUMNS`). Anyone else
> gets the pack without those two columns, and the pack's preamble says they were
> withheld. In the shipped catalogue `bcms.contact.export` is the
> chief-risk-officer's, and compliance-officer is deliberately denied it. Every
> export now writes an `alert.evidence_exported` audit row. The DPO's half of
> open question 7, whether masked evidence is still evidence, is unchanged.

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

## 11. Phase 11 — supplier matching, CSAT pre-fill, exported packs, the training register

**Four processing activities Phase 11 introduced with no register entry.** Checked
against the code on `integration/bcms-remaining`, 2026-09-23. None of them adds a
table or a column (ADR 0021). Three persist nothing new at all. That is a real
mitigation, and it is also why each was easy to build without anyone asking where
the personal data goes.

### 11.1 Vendor exercise participation — matching `tp_contacts` to `bcms_contacts` by email

| | |
|---|---|
| **Where it runs** | `SupplierResilienceController::vendorParticipation()`, a private method called from `index()` on every render of the supplier-resilience screen. **Not** in `SupplierResilienceService`, which the brief named. The service does not touch contacts |
| **What it does** | Loads **every non-deleted `tp_contacts.email` in the tenant**, keyed to `third_party_id`. Selects every `bcms_contacts` row whose `email` is in that set. For each match it loads the person's `bcms_exercise_participants` rows. **Computed on read. Nothing is written**: no link row, no column, no cache |
| **Data subjects** | Vendor personnel who are also on the bank's emergency roster: on-site contractors, outsourced service staff, and a vendor's BC coordinator invited to an exercise. These are **not the bank's employees**, which makes them a different population from §§1–3. By data-entry error, it can also catch a bank employee whose corporate address was recorded as a `tp_contacts` row |
| **Read** | `tp_contacts.email`, `third_party_id`; `bcms_contacts.email`, `full_name`; participant `role`, `invitation_status`, `attendance_status`; occurrence `scheduled_date`. Also `with(['user'])`, which eager-loads the linked platform user **and nothing reads it**. That is a dead eager load: a name and an address brought into memory for no purpose. Trivial, but it is a minimisation point, so it is recorded |
| **Rendered** | `contact_name`, `vendor_third_party_id`, `occurrence_id`, `scheduled_date`, `role`, `invitation_status`, `attendance_status`. **The email is the join key and is never rendered** |
| **Lawful basis** | Legitimate interest (s.25(1)(b)(v)): the bank's oversight of whether its critical suppliers take part in continuity exercises. That is the ISO 22318 supply-chain purpose this module stamps `iso22318.supply_chain`. **No CBN provision is claimed for this**, because none was checked in this pass |
| **Purpose limitation, s.24(1)(b)** | Two datasets collected for different purposes are combined here. `tp_contacts` is held to manage the vendor relationship. `bcms_contacts` is held to reach a person, per §1: "an emergency, an exercise, or a check that we can still reach them. Nothing else." The output is about **exercise attendance**, which is inside §1's stated purpose. On the TPRM side the address is used **only as a match key**: not rendered, not stored, not transmitted. **Position: compatible further processing, conditional on it staying a read-time match.** A persisted vendor↔contact edge, which ADR 0021 §4 declined, would be a new processing activity and would need its own entry |
| **Accuracy, s.24: three findings, owner `backend-engineer`** | (1) **Case mismatch fabricates a vendor id.** Under the default `utf8mb4_unicode_ci` collation (`config/database.php`), `whereIn('email', …)` matches `Musa@Vendor.com` to `musa@vendor.com`. But `$vendorEmails->get($contact->email)` is a **case-sensitive** PHP key lookup, so it returns null, and `(int) null` renders **`vendor_third_party_id = 0`**: a named person attributed to a vendor that does not exist. (2) **One address on two vendors' contact lists.** `pluck('third_party_id', 'email')` keeps only the last, so attribution silently goes to one of them. (3) **A bank employee mis-entered as a vendor contact** appears as a "vendor participant". (1) is the `NoFabricatedNumbersTest` family. (2) and (3) are inherent to a heuristic join, and the screen should say so |
| **Retention** | **None of its own**, because nothing is persisted. It inherits `tp_contacts` (TPRM's retention) and §1 / §3 for the BCMS rows |
| **Residency** | Application server. Inherits §1. No transfer and no processor |
| **Access** | `bcms.report.view`: risk-manager (the BC Coordinator), CRO, compliance-officer, **risk-analyst, board-member**, super-admin. **Not unit-scoped.** `Contact::query()` is called without `visibleTo()`. This is organisation-wide by design, and the consequence is that a board member sees vendor staff by name with their attendance record. That is acceptable for organisation-wide oversight roles. **The architect should confirm that `bcms.report.view` is only ever granted to organisation-wide roles**, because nothing enforces it |
| **Deliberately not done** | No stored link and no `third_party_id` on `bcms_contacts` or `bcms_exercise_participants` (ADR 0021 §4). No email rendered. Nothing written to either register |
| **Transparency** | Vendor personnel are **not covered by the bank's employee privacy notice**. Their notice is the vendor's. The §29 processing agreement in the TPRM engagement record should say that vendor personnel data is used as continuity-exercise evidence. Open question 14 |

### 11.2 The CBN CSAT workbook pre-fill

| | |
|---|---|
| **Where it runs** | `EvidencePackController::csatPrefill()` → `CsatPrefillService::fill()`. A user uploads the bank's CSAT workbook (`.xlsx`). PhpSpreadsheet loads the **whole** workbook. The service scans **every cell on every sheet** for known question labels, writes the matrix's answer into the adjacent cell, prepends a cover sheet, and streams the file back |
| **What personal data can be in it** | **This cannot be stated from the document, because the product does not hold the CSAT template (ADR 0021 §4).** It is stated instead from what a completed self-assessment return is. The upload holds whatever the bank typed in before uploading, which commonly includes the names, designations, email addresses and phone numbers of respondent and attesting officers. It also holds the **Office document properties** (creator, last-modified-by), which PhpSpreadsheet reads on load and writes back on save. The service touches only question-label cells, but it reads every cell into memory to find them |
| **What the service adds to it** | Matrix artefact sentences: clause states, counts, and at most a management review **title** ("Internal audit referenced in the {title}"). **No named individual.** I checked every artefact string in `ClauseComplianceMatrixService::sufficiency()` and the crosswalk, and none interpolates a person's name |
| **Where it is stored** | **Nowhere persistent.** The upload sits in PHP's upload temp file, which PHP deletes at the end of the request. The output is written with `tempnam()` in `sys_get_temp_dir()`, read back, and `unlink()`ed. No database row, no application-disk copy, no queue payload. **Finding, owner `backend-engineer`:** the `unlink()` is not in a `finally`. If `$writer->save()` throws, a partial copy of the bank's workbook stays in the OS temp directory until the OS clears it. One `try/finally` fixes it |
| **Deleted?** | Yes, by the request lifecycle, except on the failure path above |
| **Retention** | The lifetime of the request. The downloaded file on the user's machine falls under the bank's own records management and is outside the product |
| **Lawful basis** | The CBN Risk-Based Cybersecurity Framework's annual self-assessment, which the product maps to `cbn.rcf.csat` (ADR 0021 §4). The officers' names in the workbook are processed incidentally to completing that return |
| **Residency** | Application server. Inherits §1. No processor. PhpSpreadsheet is a library running in-process, not a service |
| **Audit: a gap** | **`csatPrefill()` writes no audit row**, unlike both packs in 11.3. So "who produced the CSAT answers the bank submitted to the CBN, and when" cannot be answered. That is an evidence-sufficiency gap as much as an NDPA one. Remediation, owner `backend-engineer`: one `pack.exported`-shaped row (`framework = cbn_csat_prefill`, matched and unmatched counts, actor). **Never the workbook content**, for 11.3's reason |
| **Security of processing** | No virus scan, which is the product-wide named go-live gap. **No size limit in the validation rule**: it is `required\|file\|mimes:xlsx`, with no `max:`. An `.xlsx` is a zip, and the whole thing is loaded into memory, so a decompression bomb reaches PhpSpreadsheet with no check. Owner `backend-engineer` / `reliability-engineer` |
| **Access** | `bcms.report.export`: risk-manager, CRO, compliance-officer, super-admin |
| **Deliberately not done** | No cell coordinate invented. No copy kept. Nothing sent anywhere |

### 11.3 Exported packs — the board pack (PDF, PPTX) and the evidence pack (PDF)

**What personal data actually travels.** I read this from `BoardPackService`,
`EvidencePackService`, `BoardPackPptxWriter`'s input, both blades under
`resources/views/reports/pdf/`, and `layout.blade.php`, rather than inferring it
from the brief.

| File | Personal data in it |
|---|---|
| **Evidence pack**, all four frameworks | The **exporter's name** (the layout prints "Generated by"), the organisation name, and one artefact sentence per clause: counts, and at most a management review title. **No individual training record, no finding text, no contact data.** The brief expected training records here. **They are not in it**: the 7.2 section reads "N assessed competency record(s) on file…", which is a count |
| **Board pack PDF** | The **exporter's name**. **Section 6 prints every open nonconformity's `reference` and its full `description` free text.** Section 9 prints the **management review approver's name**. Process names in the RTO-gap section. KRIs are aggregate. Incidents are a count only |
| **Board pack PPTX** | Slide 6 has the same nonconformity `reference — description` lines, and slide 9 the approver's name. The exporter's name is **not** in the PPTX: the writer receives a title and slides only |

**The exposure is `bcms_findings.description`.** Findings are raised from exercise
evaluations (§8.2's performance-data category) and, since Phase 10, from PIRs
(`FindingSource::Incident`), whose narratives §9.1 already records **can name staff
and customers**. The board pack prints them verbatim into a file that, once
downloaded, has no retention, no access control and no residency the product can
enforce, and it prints them for a board. That is a **wider audience than the
findings register itself**. `Finding` is `ScopedToOrgHierarchy` on its own screen,
while `BoardPackService::openNonconformities()` applies no `visibleTo()`. That is
correct for a board document, which is organisation-wide by nature. It also means the
board-pack **preview** (`bcms.report.view`) shows every unit's nonconformity text to
risk-analysts and board members.

**§9.1's rule extends here: describe categories, never paste records.** A finding
description is exactly what the board pack reprints. **No technical control exists
and this register does not pretend one does.** The controls are copy on the finding
form, the evaluator and crisis-team curricula, and open question 15.

| | |
|---|---|
| **Lawful basis** | Legitimate interest in board oversight of the continuity programme. That is the governance purpose the product maps to `cbn.governance.board_oversight`. **No specific CBN clause requiring this content is asserted**, because none was checked in this pass |
| **Access** | Generating a file needs `bcms.report.export` (risk-manager, CRO, compliance-officer, super-admin). The board-pack **preview**, which has the same content on screen, needs only `bcms.report.view`, which adds risk-analyst and board-member. The evidence pack's index, preview and export are all `bcms.report.export` |
| **Audit** | Every export writes one `bcms_audit_logs` row: `auditable_type = bcms_report_pack`, `event = pack.exported`, with `actor_id`, `actor_label`, `framework`, `period`, and either the section-state summary (evidence pack) or the `format` (board pack). **It records who took data out, when, what kind and for what period. It does not record the content**, so "which named individuals were in the board pack downloaded on 3 March" is not answerable from the log. **This register agrees that the log should be content-free.** Storing the content would put a second copy of every finding description into an append-only table with no purge path, which is §7.5.1's defect deliberately recreated |
| **Retention** | The audit row lives in `bcms_audit_logs`: append-only, and unreachable by §10's purge by design. It holds the exporter's name and nothing else personal. **The file itself leaves the product's control at the download.** Its retention is the bank's records management, and where a board pack goes next (a board portal, email) is the bank's processor, not this product's |
| **Residency** | Generated on the application server (dompdf through `DocumentRenderer`, and `ZipArchive` for the PPTX). No external renderer and no processor. Generation is synchronous only (`docs/bcms/phase-11-notes.md` §1.6), which means **no server-side copy is ever held** waiting for a signed-link download. The deferred queued path would change that, and it would need a line here before it ships |
| **Deliberately not done** | No persisted pack (no `bcms_board_packs`, ADR 0021 §4). No content in the audit log. No per-person data in the evidence pack |

### 11.4 The training compliance register — each employee's competency score and assessor

| | |
|---|---|
| **Where it runs** | `TrainingController::compliance()` → `TrainingComplianceService::complianceRows()`. One row per (assigned user × active curriculum), with assignment resolved live from roles, and `'*'` meaning every active user |
| **Data subjects** | Every active user assigned to a curriculum. Because `BC-AWARE-ALL` is `'*'`, **that is the whole active workforce** |
| **Fields per row** | Name, department, curriculum; attendance date and next due date; competency: whether assessed, **score**, pass mark, **assessor's name**, assessed date; and the source occurrence. The same page also ships **`users`, every active user's id, name and department**, for the assessor picker, plus the `departments` list |
| **Nature** | A failed assessment is deliberately kept as a valid record, rendered as "Assessed 62% by {assessor}, below the 80% pass mark". **That is employee performance data**, §8.2's category, collected for ISO 22301 7.2, and line managers will read it. It is the second artefact in this module, after §8.2's evaluator commentary, most likely to be used in a conversation it was not collected for |
| **Lawful basis** | Employment contract and legitimate interest. **Not legal obligation**: see §3's corrected cell |
| **Access rule, being implemented now** | Gate `bcms.training.view`, held by risk-owner (the Department BC Champion), risk-analyst, compliance-officer, risk-manager and the CRO. **Rows must be limited to subjects whose `users.business_unit_id` is in the viewer's unit set.** Five rules, stated so that the fix cannot land half-done: |
| | **(1) One resolver.** The unit set is `RcsaScope::unitIdsFor($viewer)`, the same one `ScopedToOrgHierarchy::visibleTo()` uses. A null result means the whole estate (`rcsa_scope.all_units`, or a system context). There is no second scoping rule |
| | **(2) The null arm inverts for people.** `visibleTo()` makes `business_unit_id IS NULL` visible to everyone, because a no-unit *row* is a group plan every branch must follow. **A no-unit *person* is not organisation-level content.** Their score must be visible **only to a whole-estate viewer**, never to every unit-scoped viewer. Copying the trait's null arm here would publish the scores of exactly the people HR has not yet placed |
| | **(3) The named-user arm.** The subject always sees their own row, which My Resilience already mirrors. **The recorded assessor sees the rows they assessed**, because they wrote that score. Nobody else is added |
| | **(4) The picker leaks unless it is scoped too.** The `users` list (name and department of every active user) and the `departments` list must be filtered to the same unit set. Otherwise a unit-scoped champion still receives the whole workforce's names and departments through the assessor picker after the rows are fixed. Somebody outside the viewer's units cannot be assessed from this screen anyway, because their row is not on it |
| | **(5) The tiles follow the rows.** `summaryTiles()` must count over the same scoped set. A tile that disagrees with the table below it is a defect in its own right, and at small unit sizes an "overdue: 1" identifies a person |
| **Audit copy: registered, not a defect** | `TrainingRecord` is `BcmsAuditable` with the base `auditExcluded()`, so every create and update copies `score`, `assessor_id` and `user_id` into `bcms_audit_logs`. **Unlike §7.5.1, this duplication has evidential gain.** A competency score that was changed, and who changed it from 62 to 80, is exactly what an examiner needs to be able to trace on a mandatory record. It is kept, and its cost is stated: **§3's three-year retention cannot reach the audit copy**, so a person's score history outlives the record for as long as the audit log exists. Open question 16 |
| **Retention** | §3 unchanged: 3 years after `next_due_date`, or the certification cycle. Failed-assessment rows run on the same clock, because they are 7.2 evidence that the process detects a competence gap. **Not enforced by anything (§10)** |
| **Residency** | Inherits §1 |
| **Export** | **None.** `TrainingController` has no export action. The only training data that leaves the system is the 7.2 counts inside the evidence pack (11.3) |
| **Deliberately not done** | No certificate upload (ADR 0021 §3), so no provider certificates carrying ID numbers or photographs. Attendance and competency are never blended into one "trained %" figure |

### 11.5 Clause codes, and what is still missing

**No new clause ref is published by this section.** Everything maps to
`ndpa.lawful_basis`, `ndpa.retention` and `ndpa.residency`. `ndpa.processor_agreement`
remains proposed and unpublished, on §5.4's terms.

**Nothing in Phase 11 deletes anything, and there is still no purge command (§10).**
Phase 11 does not make that worse: three of its four activities persist no new
personal data. The fourth, the training register, reads rows §3 already registered.
**Nothing here should be read as a promise that a purge command is coming.** §10's
specification stands, unowned by any scheduled phase.

### 11.6 Sufficiency — could an examiner be shown this in one click?

**Ask: "show me everyone who took personal data out of this system in a report this year."**

**Partly.** The evidence-pack screen's export log (`EvidencePackService::log()`) lists
both packs' `pack.exported` rows, with who, when, which framework or board year, and
for which period, and a human assembles nothing. Three limits:

- the log is **the latest 50 rows, unpaginated**, so a busy year truncates silently;
- **the CSAT pre-fill is not in it at all** (11.2);
- **this pass did not check** whether the other `bcms.report.export` doors write a
  comparable row. Those are §6.2's alert-evidence CSV, the AAR export, the BIA report,
  the plan document and the incident notification log. This register does not claim
  they do. **Update 2026-10-16:** the alert-evidence CSV now writes
  `alert.evidence_exported` (§12.6). It writes that row on the alert's own trail,
  not as a `pack.exported` row, so it does **not** appear in
  `EvidencePackService::log()`. The other four doors are still unchecked.

---

## 12. Bare-number HELP replies applied across every matching recipient (Phase 7 certification)

**Opened 2026-10-16 because of an owner ruling made that day. Code review
requires this entry before Phase 7 can be certified.** §6 registered the inbound
`from` number as a match key used to find **exactly one** open recipient. The
ruling changes that for one case: a reply that means *needs help*. I checked
this section against the code on `claude/bcms-phase-7-certification-8f875a`,
not against the ruling's wording. The code is
`InboundResponseHandler::handleReply()` with its docblock,
`recipientsForNumberHelp()`, `openRecipients()` and `interpret()`,
`RollCallService::record()`, `AlertWebhookController::reply()`,
`AlertController::evidence()` and `EvidenceExport`.

### 12.1 The processing activity

| | |
|---|---|
| **Trigger** | A **signed** inbound reply by SMS, WhatsApp or USSD on `AlertWebhookController::reply()`. The route rejects an unsigned callback with a 403 even when no secret is configured. The reply's body carries no valid `r-{id}-{tag}` token, and `interpret()` classifies it as `needs_help`, either from a keyword in `HELP_WORDS` matched as a substring in any of the five languages, or from the USSD keypad code `2` sent on its own |
| **Matching** | The sender's `from` (max 32 characters) is cut down to its digits. Anything under 7 digits is rejected. The **last nine digits** are matched as a suffix (`LIKE '%…'`) against `bcms_contacts.mobile_primary` **or** `mobile_secondary` |
| **Candidates** | `openRecipients()`: `bcms_alert_recipients` rows whose alert has `dispatched_at` set and **within the last 2 days**. That window is a fixed 48 hours in code. It is not each alert's own configured response window, and this register describes it as it is built. The query runs with **no tenant context**, because a reply cannot name its tenant until it has been matched. It therefore covers **every organisation** on the platform. A recipient who has already answered is still a candidate, because help always supersedes |
| **Cap** | `limit(50)`, with **no `orderBy`**. If more than 50 rows match, the database engine decides which 50 are written, and the rest are dropped without any record |
| **What is written, per candidate** | `TenantContext::set()` is called with **that row's own** `organization_id`. `RollCallService::record()` then re-reads the row `FOR UPDATE` and sets `response_value = needs_help`, `status = acknowledged`, `acknowledged_at` (only if the row had not answered before) and **`response_text` = the sender's reply, verbatim and trimmed** (up to 1,000 characters). An earlier `safe` or `not_on_site` is superseded. An earlier `needs_help` is left as it is, so a second help reply is a no-op |
| **Audit, per candidate** | One `alert.response` row on that alert's own `bcms_audit_logs` trail, carrying `contact` (the name snapshot), `response`, `via`, and `previous_response` / `superseded` when the reply replaced an earlier answer. **No message text and no number.** Checked: `AlertRecipient` does **not** use `BcmsAuditable`, so the `forceFill()->save()` does not copy `response_text` into the audit table by a second route |
| **What is not done** | A reply that is not a help reply, i.e. safe, not-on-site or unclassified, is **not** fanned out. It is matched only when exactly one candidate is waiting (`recipientForNumber()`, `limit(2)`), and otherwise it is unmatched, not guessed. A tokenised reply always writes exactly one row, found by its key. The `from` number is **not stored** on any path, and nothing logs it |
| **Owner ruling, and its reasoning** | The two possible errors cost different amounts. A help request that is dropped because a number fits two live alerts means a person nobody looks for. A false help flag on a second alert costs a warden a walk. The ruling therefore accepts false help flags in order to avoid missed calls for help |

### 12.2 Data categories

| Category | Status | Assessment |
|---|---|---|
| **MSISDN** (the sender's `from`) | **Used only for matching, and not newly stored.** It is compared against numbers each tenant already holds under §1, then discarded | Still processing (s.65 "processing" includes retrieval, consultation and use). It is registered as a transient match key, as §6 does |
| **Free-text reply** → `bcms_alert_recipients.response_text` | **Stored**, verbatim, on up to 50 rows | Personal data of the sender. By construction it is also likely to be **sensitive personal data**: every reply on this path matched a help word, and the help vocabulary is *injured*, *hurt*, *trapped*, *stuck* and their equivalents. NDPA s.65 lists **"health status"** as sensitive personal data, limb (d). A reply such as "trapped 3rd floor" also carries **location**. Location is ordinary personal data under the s.65 definition of personal data, not sensitive data, but in an emergency it is the most consequential field in the row. The reply can also name **a third party** ("Musa is hurt") |

**How the sensitive half is handled.** The design does not try to classify or
strip health content from the text. That is deliberate, because the text is what
a warden reads before going in. The controls are the ones §6 already asked for:
a short retention for exercise traffic (12.5), an export gate on the column
(12.6), audit rows that carry no text (12.1), and the privacy-notice line in open
question 6. **This section adds one thing to that list:** the same health text
can now be stored by **more than one controller** (12.4).

### 12.3 Purpose and lawful basis

| | |
|---|---|
| **Purpose** | Emergency roll-call and life safety: finding a person who has said they need help, during the 48 hours after an alert. Nothing else. This is §1's purpose, narrowed to its most urgent case |
| **Lawful basis, ordinary personal data** | **NDPA 2023 s.25(1)(b)(iii)**: processing necessary "to protect the vital interest of the data subject or another person". The paragraph numbering was checked against the enacted text and matches this register's existing s.25(1)(b)(i) and (b)(v) citations in §7.3 and §11.1. The brief proposed "s.25(1)(d)". **That reference does not exist in the Act**: s.25(1) has only limbs (a) consent and (b), and (b) has five sub-paragraphs. The words "or another person" matter here, because a reply that names a trapped colleague is processed for **that colleague's** vital interest too |
| **Condition, sensitive personal data** | s.25 alone is not enough. **s.30(1)** forbids processing sensitive personal data unless one of its conditions applies. The nearest is **s.30(1)(c)**: necessary to protect the vital interests of the data subject or another person, **"where the data subject is physically or legally incapable of giving consent"**. **That proviso is the weak point and this register will not paper over it.** A person who can type "help, I'm hurt" is arguably able to consent, and they chose to send it, which is close to consent in substance but not consent to *this* processing (fan-out to several controllers). For a *third party* named in the reply ("Musa is trapped") the proviso is much easier to meet. The fallback is **s.30(1)(b)**, processing necessary for the controller's obligations under employment or social security laws. Whether any Nigerian employment law places a workplace-safety duty on the bank that covers this processing has **not been checked** **[verify]**. Neither condition covers a stranger on a recycled number (12.4), because that person is not the bank's employee. **The DPO decides which s.30 condition is relied on.** Open question 17 |
| **Where it sits relative to §1** | §1 uses vital interests only for **outbound** life-safety traffic over a withdrawn personal channel. This is the **inbound** counterpart, and the same narrowness applies. §5.4's caution still holds: vital interests cannot be the standing basis for drill or reminder traffic. A help reply to an **exercise** alert is still handled on this path, but no real person needed rescue, so the vital-interests argument for an exercise reply is **weak**. For exercise traffic the honest basis is §2's legitimate interest in evidencing the drill **[verify with DPO]** |

### 12.4 The specific risk: recycled and shared numbers

**What can go wrong.** Nigerian operators recycle MSISDNs. Staff also share
handsets and list a relative's number as `mobile_secondary`. A nine-digit suffix
match can also catch two different full numbers that end in the same nine digits
(for example a foreign number and a Nigerian one). In any of these cases, a help
reply from person X can be written into **organisation B's** record of **its own
contact Y**. The result:

1. **B now holds X's free text, and that text may be health data, about someone
   who is not B's data subject in any other sense.** B has no lawful-basis record
   for X, X has no notice from B, and X does not know B holds it.
2. **The same message can sit in more than one controller's records**: A's
   (where X really is a contact) and B's. Each copy has its own retention clock,
   and X would need a DSAR to each controller to find them. The platform has no
   cross-tenant view of the fan-out, and it should not have one.
3. **B may never have messaged X's handset.** Matching checks the contact's
   mobile columns, **not the channel the alert actually used**. If B's alert
   reached Y by email or Teams, B still receives X's SMS reply because Y's
   roster row holds the number.
4. **Y is wrongly shown as needing help** on B's roll-call board. The ruling
   accepts that operational cost. It is recorded here because it is also an
   accuracy problem under s.24(1)(e).

**Why it is accepted.** The ruling's reasoning stands, and this register agrees
with how it ranks the two errors. A false help flag sends a warden on a walk and
puts a stranger's words in a record. A dropped help request leaves somebody
nobody looks for. The NDPA question is not whether the trade is right. It is
whether the exposure is **bounded** and **corrected afterwards**, and 12.5 and
12.7 answer that.

### 12.5 Mitigations, and what each one actually bounds

| Mitigation | What it bounds | What it does not bound |
|---|---|---|
| **Signed callbacks only** (`reply()` fails closed) | A forged or spoofed help reply. Without this, anyone on the internet could write "help" into 50 rows across tenants | A genuine reply from the wrong person (12.4) |
| **The 50 cap** | How many rows, and so how many controllers at most, one reply can reach. It also turns a pathological number (a switchboard, a test fixture) into a bounded write | Which 50 are chosen: there is no `orderBy`. When rows beyond 50 are dropped, nothing reports it. **Finding, owner `backend-engineer`**: write one bounded, value-free signal when the cap is reached, such as a count and no number. A live help request truncated at row 50 is the error the ruling exists to prevent, and today nobody would know it happened |
| **The 48-hour eligibility window** | The time a recycled number is dangerous for: two days after each dispatch, not the life of the roster | Nothing within those two days. During an incident that is exactly the window that matters |
| **No cross-tenant decisions** | Each row is written under its own organisation's tenant context, from that row plus the sender's message. No organisation's state is decided from another's data, and nothing belonging to A is written into B | The **matching read** does span every tenant's contact numbers. That is unavoidable before a tenant is known, and the "no cross-tenant reads" claim is true only in that narrower sense. The platform operator, as each bank's processor, makes that read. **Whether each bank's §29 agreement covers inbound routing by number across the processor's estate has not been checked [verify]** |
| **Audit rows hold no text** | The verbatim reply is not copied into append-only `bcms_audit_logs`, which §10's purge cannot reach. Only the name snapshot and the status land there | The name snapshot is Y's name, so a misattribution leaves "Y: needs_help, via sms" in B's permanent trail. That is the cost of the trail being readable, the same trade as §7.5.1's `subject_name` |
| **Export gate on `bcms.contact.export`** (12.6) | Who can take `response_text` **out** of the product | Who can read it **on screen**: the roll-call board's `help_needed` list shows `response_text` and the contact's mobile to `bcms.alert.view` holders. This is correct during an incident. It is recorded so that nobody reads the export gate as the only access control |

**No rectification path for a misattributed reply. Finding, owner
`backend-engineer`, with the architect.** Once B's operator finds that a help
flag came from a stranger, they can clear the status through the in-app respond
route (`operator: true`). They **cannot remove the stranger's words**:
`record()` keeps `response_text` when the operator supplies no text, and it can
only overwrite the text, never null it. The NDPA gives X a right to erasure and
gives B a duty of accuracy (s.24(1)(e)). Without a path to strike a
misattributed reply, with an audit row recording that it was struck, B can meet
neither. This is not a blocker for certification, because the flag can be
cleared and the evidence trail survives. It should be closed before the first
inbound gateway goes live.

### 12.6 Related Phase 7 changes registered with this entry

| Change | Where | Register effect |
|---|---|---|
| **Status callbacks store a closed failure category, not gateway prose** | `InboundResponseHandler::handleStatusReceipt()` → `failureReason()` → `HttpChannel::classifyProviderError()` | A failed delivery receipt now writes `"Gateway reported the message failed ({category})."` into `bcms_notification_deliveries.failed_reason`. The category is one of `invalid_recipient`, `dnd_blocked`, `recipient_blocked`, `insufficient_balance`, `sender_id_rejected`, `credential_rejected`, `rate_limited`, or "reason not classified". **The gateway's own text, which often echoes the destination MSISDN ("Invalid recipient 2348…"), is never stored.** This closes the inbound counterpart of the §6 `failed_reason` finding that §7.8 recorded as closed for the outbound `reasonFrom()` path. Also, an **unsigned** status callback can no longer move a delivery to `failed` at all |
| **Evidence export split** | `AlertController::evidence()`, `EvidenceExport::RESTRICTED_COLUMNS` | `Address` and `Response text` require `bcms.contact.export` in addition to `bcms.report.export`. Without it, the pack omits both columns and says so in its preamble. Closes the permission half of §6.2 |
| **Evidence export audited** | `AlertController::evidence()` | Each export writes `alert.evidence_exported` to the alert's trail with `include_contact_data`, `rows` and `exported_by`. It records **the fact and the size, never the data**, which is §11.3's content-free rule. That answers "who took this alert's addresses and replies out, and when". It is not listed in the evidence-pack export log (§11.6) |

### 12.7 Retention

`response_text` keeps §6.1's clock: **7 years** for an incident-linked alert,
**12 months** for exercise and reminder traffic. A misattributed reply held by
organisation B is on **B's** clock for **B's** alert, so the same message can be
erased from A after 12 months and kept by B for 7 years, or the other way
round. The audit rows follow `bcms_audit_logs`, which is append-only and outside
§10's purge by design. They hold no text.

**Not enforced.** §10's purge command still does not exist, so factual retention
is **indefinite**, as for everything else in this register. The fan-out
multiplies a short-retention problem across controllers. It does not create a
new one.

### 12.8 Residency and processors

There is no new transfer. The reply arrives through the inbound leg of the
SMS, WhatsApp or USSD processor already listed in §5.2. That processor sees the
text before this product does, which is why §5.2.7 asks for the §29 agreement to
cover the **inbound** direction. The stored copies stay in the application
database under §1's af-south-1-or-on-prem commitment. **Clause codes:**
`ndpa.lawful_basis`, `ndpa.retention`, `ndpa.residency`, all already published.
No new code.

### 12.9 Owner and review triggers

| | |
|---|---|
| **Owner of the ruling** | The product owner (ruling of 2026-10-16) |
| **Owner of this entry** | compliance-analyst |
| **Owner of the s.30 condition and the privacy-notice wording** | The client's DPO (open questions 6 and 17) |
| **Engineering findings** | `backend-engineer`: the silent truncation at 50 (12.5) and the missing strike path for a misattributed reply (12.5) |
| **Review this entry when** | (1) the cap, the 48-hour window, the nine-digit suffix or the help vocabulary changes; (2) fan-out is extended to any status other than `needs_help`; (3) the first inbound gateway (SMS, WhatsApp or USSD) is configured in any environment, which turns this from a design into live processing; (4) a data subject or a tenant complains that a stranger's reply landed in its records; (5) §10's purge command ships; (6) the DPO answers open question 17 |

### 12.10 Sufficiency: could an examiner be shown this in one click?

**Ask: "show me who told you they needed help during this incident, and what
they said."** **Yes.** The roll-call board's `help_needed` list shows it on
screen, and for a holder of `bcms.contact.export` the alert evidence CSV exports
it with no assembly, while the `alert.evidence_exported` row records that it was
taken out.

**Ask: "show me every place one help message was stored."** **No, by design.**
Each controller can show its own copies only. The platform operator could find
the rest by searching on the text, but no screen does that and none should. This
is the cost of 12.4, stated rather than solved.

---

## 13. `tp_audit_logs` — the TPRM audit trail's actor fields (post-go-live hardening, ADR 0028)

**Why a TPRM table is in a register titled BCMS.** TPRM has no register of its
own. `ndpa-register-platform.md` is explicitly scoped to unprefixed tables and says
`tp_*` is TPRM, so it is not the right home either. This file already crosses into
`tp_*` at §11.1. Until the module registers are folded into one product-wide
register (the end state `ndpa-register-platform.md` names), TPRM entries go here,
under their own heading. **Production runs TPRM ON**, so this is live processing,
not a design.

Checked against the code on `fix/post-golive-hardening`, 2026-10-14, including the
uncommitted working tree.

### 13.1 The processing activity

| | |
|---|---|
| **Table** | `tp_audit_logs`, created by `2026_09_06_110009_create_tprm_portal_governance_tables.php`. Model `App\Models\Tprm\AuditLog` |
| **Purpose** | An append-only, tamper-evident record of who changed what in the third-party risk programme. It covers engagements, contracts, assessments, risk acceptances, waivers, incidents, access grants and vendor portal activity. It is the trail behind the CBN register, the DORA register, the PCI 12.8 pack and the supervisory export that ADR 0025 has booked. **The actor fields exist so that a vendor's act is never shown as a colleague's, or as the platform's** (model docblock; ADR 0028) |
| **Writers** | `TprmAuditable::writeAuditRow()` on 26 audited TPRM models. Direct `AuditLog::create()` in `PortalAuthService`, `PortalIncidentService`, `VendorIdentityService`, `TrustProfileService`, `IntakeService`, `EvidenceService`, `AccessService`, `ClauseAnalyzer`, `NotificationDraftService` and `ObligationClockService` |
| **Readers** | **None in the application** (ADR 0028 §Context, re-checked). `TprmAuditable::auditLogs()` is called only by tests. No screen, grid, export, pack or prompt reads this table |
| **Data subjects** | (a) **Vendor personnel** who hold `tp_portal_users` accounts. They are not the bank's employees and are not covered by its staff privacy notice. (b) **Internal staff** (`users`). (c) **People named inside payloads**: vendor staff granted access to bank systems, sanctions-screening subjects, and anyone a vendor names in an incident description (§13.5) |
| **Mutability** | **Append-only and hash-chained.** The `updating` and `deleting` hooks throw. Each row's `hash` is `sha256(HASH_RECIPE \| previous_hash \| canonical(HASHED_FIELDS))` under recipe `tp_audit_logs/v2`. All three actor fields, `before`, `after`, `ip`, `user_agent` and `correlation_id` are in `HASHED_FIELDS`. **ADR 0025 forbids re-sealing.** No field of a written row can be changed without breaking the chain from that row onward. That is the point of the design, and it is also NDPA s.24(1)(f) and s.24(2) (integrity of personal data) working as intended |

### 13.2 The actor fields — the contract, and the state of the tree

**The contract, per the user's decision of 2026-10-14 (ADR 0028 §2, as amended 2026-10-14):**

| `actor_type` | `actor_id` | `actor_label` |
|---|---|---|
| `user` | `users.id` | the user's **name**, through `AuditText::clip(…, 200)`. This is existing practice and it is kept |
| `portal` | `tp_portal_users.id` of the authenticated vendor, or `null` before authentication | **`null`, always.** The vendor's identity is reached only through `actor_id` → `tp_portal_users` |
| `system` | `null` | `null` |

**Why the label is null for a vendor.** The design aims to meet an NDPA s.34(1)(d)
erasure request by **pseudonymising the `tp_portal_users` row** without touching a
sealed row. NDPA s.65 defines pseudonymisation as processing after which data "can
no longer be attributed to a specific data subject without the use of additional
information", where that information is kept separately. The `tp_portal_users` row
is that additional information. **The design only works if no sealed field carries
the identity directly.** §13.5 shows that today several fields still do.

**State of the tree, re-read 2026-10-14 after the amendment was built. The contract
is implemented.** Every portal writer now writes `actor_label => null`:

- `TprmAuditable::resolveAuditActor()` returns `[ACTOR_PORTAL, $portalUser?->getKey(), null]`;
- `PortalIncidentService::auditReceipt()` (line 108), `VendorIdentityService::audit()`
  (line 171), `TrustProfileService::audit()` (line 297) and
  `PortalAuthService::audit()` (line 498) all write `null`.

**No production row has ever carried a vendor email in `actor_label`.** An earlier
read of this tree found the email there, uncommitted. That never reached production
(`main` @ `056ec5a`), and the amendment removed it before commit.

**Which portal rows name the vendor in `actor_id` (ADR 0028 A3).**
`PortalAuthService::audit()` takes an explicit `$portalUserId` and writes
`actor_id => $userId ?? $portalUserId`. Only two events pass it, and both are
written after the vendor has proven who they are on this request:

- `portal_signed_in` carries the vendor's `tp_portal_users.id`;
- `portal_signed_out` carries the vendor's `tp_portal_users.id`.

**Pre-authentication events keep `actor_id` null**, so that an attempt is never
pinned on its target: `portal_account_locked` (the outcome of failed attempts; a
failed attempt below the threshold writes no trail row), `portal_mfa_code_sent`,
`portal_mfa_code_send_failed`, `portal_mfa_enrolled` and `portal_account_created`.
On those rows the account is identified by `auditable_id` → `PortalUser` and by
`after.portal_user_id`, never by the actor. `portal_invited` is written by an
internal user (`actor_type = user`). Vendor model writes through the trait, and the
three portal service writers, carry the authenticated vendor's id.

**Rows written before ADR 0028 deploys.** On those rows, model writes caused by a
vendor say `system` with null actor fields. The explicit portal events say
`portal`, also with null actor fields. In both cases the vendor's identity is
recoverable **only from the payload** (§13.5) or, for auth events, from
`auditable_id` → `tp_portal_users`.

### 13.3 What is held, field by field

| Field | Personal data? | Notes |
|---|---|---|
| `actor_id` | **Yes, pseudonymous** while the `tp_portal_users` or `users` row it points to exists unaltered. There is no foreign key, so deleting either row leaves the id dangling rather than nulling it | After the target row is pseudonymised, the id is personal data only to the extent that something else in the trail re-identifies it. §13.5 says what does |
| `actor_label` | **Yes** for `user` (a name). **None** for `portal` once the contract is implemented | Sealed. A staff member's name in this column **cannot be erased** (§13.6) |
| `ip` | **Yes.** NDPA s.65 lists "an online identifier" among the identifiers in its definition of personal data | **Not** passed through `AuditText`: the code writes `request()?->ip()` raw into `string(45)`. Written on every trait row and on every portal row. No `TrustProxies` configuration exists in `bootstrap/`, so whether this is the client's address or a proxy's depends on the deployment. **To confirm** on the VPS |
| `user_agent` | **Yes in combination** (with `ip` and `created_at` it forms a device fingerprint) | `AuditText::userAgent()`: scrubbed to valid UTF-8 and clipped to 500 characters (`90843bf`). That is an integrity control (a vendor cannot suppress their own row), not a minimisation control |
| `correlation_id` | No, unless a caller puts an identifier in the header | `AuditText::clip(…, 64)`, trait path only |
| `auditable_type` / `auditable_id` | **Yes** when the auditable is `PortalUser` (every portal auth event) or `PortalInvitation` | It points at the person's own row |
| `before` / `after` | **Yes.** See §13.5 | Sealed |
| `created_at` | Yes in combination | Time of the act, to the second |

### 13.4 Lawful basis

**NDPA s.25(1)(b)(v), legitimate interest.** A bank has a legitimate interest in
keeping an integrity-protected record of who acted on its third-party risk
programme, including acts by vendor personnel on its supplier portal. A record that
cannot attribute an act cannot evidence the oversight that the CBN and DORA
registers report. The s.25(2) tests are met as follows:

- (a) the interest does not override the subject's rights, because the record holds
  work identities and acts, not private life;
- (b) the interest is not incompatible with another basis;
- (c) a vendor's employee signing into a bank's supplier portal can reasonably expect
  their actions there to be logged. That expectation has to be **created by notice**
  (s.27), and the notice is the open question below.

**Legal obligation (s.25(1)(b)(ii)) is not claimed.** This follows §7.3's reasoning.
CBN's expectations of third-party risk management supply the *purpose*. That
includes the CBN Risk-Based Cybersecurity Framework for DMBs and PSBs (2024), which
a secondary mapping places at Part 2.4, and the outsourcing expectations the module
already cites (`ServiceReviewService`, "CBN Outsourcing Guidelines §5.2"). **I could
not retrieve the primary text of either instrument in this pass** (cbn.gov.ng
returned 403), and I have not read any CBN provision that requires *this* trail,
these fields, or a retention period for them. Claiming one would be inventing a
Nigerian requirement. Counsel can upgrade the basis if they find one.

**Consent is not the basis and should not be sought.** A vendor's employee cannot
decline the logging and still use the portal. Under s.26(2), consent made a
condition of the service would not be freely given.

**Internal staff: the same basis**, alongside the employment contract
(s.25(1)(b)(i)). This mirrors `ndpa-register-platform.md` §1.

### 13.5 Payloads that carry the vendor's email — the identity the actor contract was meant to keep out

The reviewer's finding was confirmed, and it was wider than one event. Before the
ADR 0028 amendment, every portal writer below put `$user->email` (or the invitee's
email) into a sealed `after` payload. **Every one of those historic rows defeats
§13.2's pseudonymisation on its own**, because the same vendor's `actor_id`, or
`auditable_id` → `PortalUser`, sits beside an email in the same chain.

**Fixed for new rows by the amendment, 2026-10-14. Re-read in the tree:** no portal
writer's payload carries an email any more. The left-hand column names writers by
method because line numbers move. "Historic rows" means production rows written
before the amendment deploys.

| Writer | `event` | Historic rows: payload key (sealed, permanent) | New rows: payload |
|---|---|---|---|
| `PortalAuthService::invite()` | `portal_invited` (actor `user`, auditable `PortalInvitation`) | `after.email` | `third_party_id`, `role`. **No identifier for the invitee at all**: the address is only in `tp_portal_invitations.email`, reached by `auditable_id` |
| `PortalAuthService::accept()` | `portal_account_created` | `after.email` | `portal_user_id`, `third_party_id` |
| `PortalAuthService::sendEmailCode()` | `portal_mfa_code_send_failed` | `after.email` | `portal_user_id` |
| `PortalAuthService::sendEmailCode()` | `portal_mfa_code_sent` | `after.email` | `portal_user_id`, `expires_at` |
| `PortalAuthService::confirmMfaEnrolment()` | `portal_mfa_enrolled` | `after.email` | `portal_user_id` |
| `PortalAuthService::completeMfa()` | `portal_signed_in` | `after.email`, on every sign-in by every vendor since go-live | `portal_user_id` (and `actor_id`, §13.2) |
| `PortalAuthService::logout()` | `portal_signed_out` | `after.email` | `portal_user_id` (and `actor_id`) |
| `PortalAuthService::recordFailure()` | `portal_account_locked` | `after.email` | `portal_user_id`, `attempts` |
| `PortalIncidentService::auditReceipt()` | `incident_reported_by_vendor` | `after.reported_by_email` | `after.reported_by_portal_user_id` |
| `VendorIdentityService::audit()` | identity events | `after.email` | the event's own keys; email no longer appended |
| `TrustProfileService::audit()` | trust-profile events | `after.email` | the event's own keys; email no longer appended |

**The remaining email-derived value.** `VendorIdentityService`'s `ThirdParty`
update stores `primary_domain`, which is derived from the vendor's email domain.
For a corporate domain that is organisational data. For a sole trader on a
personal-name domain, it could identify a person. This is minor and is recorded
rather than treated as a defect.

**Why the id-only position costs no evidence.** It was recommended in this
register's first version and has now been built:

- `tp_portal_users.email` is stable. No code path changes a portal user's email: I
  grepped `app/` for an assignment or an update of `email` and found only the
  internal `UserManagementController`.
- So `actor_id` / `auditable_id` → `tp_portal_users.email` gives the same answer the
  payload gives, for as long as the person has not asked to be erased. After an
  erasure, losing that answer is the purpose.
- For `portal_invited`, no portal user exists yet. The id is the
  `PortalInvitation`'s `auditable_id`, and `tp_portal_invitations.email` then joins
  the erasure scope (§13.6).
- Changing payload **content** does not change `HASH_RECIPE`, `HASHED_FIELDS` or the
  recipe pin, so `AuditLogHashRecipeTest` is unaffected by this change.

**Historic rows cannot be changed.** Every production row already written with an
email keeps it for the life of the trail (ADR 0025 §3). The owner must accept this
as residual risk R1 (§13.8).

**Payload personal data the actor contract does not reach.** These come from the
trait's full-attribute snapshot on `created`, and its changed-attributes snapshot
on `updated`. Only `password`, `remember_token`, `mfa_secret`, `credentials`,
`token_hash` and `updated_at` are excluded.

- `AccessGrant`: `grantee_name` and `grantee_email`, i.e. vendor staff given access to
  bank systems.
- `ScreeningMatch`: `matched_name`. This is a sanctions or PEP screening subject's
  name, and a screening result about an individual is the most sensitive item in
  this list.
- `Incident`: `title` and `description`, vendor free text that can name people.
- `AccessService` line 150: `connection_closed_without_evidence`, `after.reason`,
  free text.

**These are not a defect to remove.** Who was granted privileged access is itself
the evidence (PCI 12.8; access recertification). They are registered so that the
erasure position in §13.6 is not read as covering them.

### 13.6 Retention and erasure

| | |
|---|---|
| **Retention** | **The life of the trail.** No purge or prune command touches `tp_audit_logs`: `app/Console/Commands` was enumerated, and only `PruneLlmUsageEvents` and `ConfigImport` mention pruning. The only deletion path is the `organization_id` foreign key's `cascadeOnDelete()`, so deleting a tenant's organisation deletes its trail at database level, bypassing the model's `deleting` hook. **NDPA s.24(1)(d) requires retention "not longer than is necessary"**, and "for ever" is not a figure. This register does not invent one. The DPO sets it against the bank's record-retention schedule (open question 18). Any purge of a hash chain is a chain-truncation design, which needs an ADR (architect), not a `DELETE` |
| **Erasure, vendor portal user** | **Sealed rows are never altered.** An s.34(1)(d) request is met by **pseudonymising the person's own rows outside the trail**: `tp_portal_users.email` → a unique non-address such as `erased+{id}@invalid` (the `(organization_id, email)` unique index must still hold), `name` → a fixed placeholder, password and MFA cleared, status disabled. The same applies to `tp_portal_invitations.email` and to any `tp_contacts` row for the same person. `PortalUser` is **not** `TprmAuditable` (checked: it is not among the 26 models), so this pseudonymisation writes **no** trail row carrying the old email in `before`. **Any erasure tool built later must keep it that way.** **No such tool exists today.** It is a manual database operation, owned by `backend-engineer` if it is to become a feature |
| **Erasure, internal user** | Pseudonymising `users` **does not** remove the person from this trail, because `actor_label` holds their name in a sealed column. The retention position rests on s.34(2)(b): erasure is owed where the controller "has no other lawful basis to retain", and §13.4's accountability basis is that other basis for as long as the trail is needed. This is defensible, it is the same trade §11.4 records for competency scores, and it has to be **stated in the staff privacy notice** |
| **What remains after a vendor is pseudonymised** | `actor_id` and `auditable_id` (now pointing at a placeholder row); `ip`; `user_agent`; `created_at`; every **historic payload email** (§13.5 table); and, on pre-ADR-0028 rows, whatever the payload holds. **On rows written after the payload fix**, what remains is an id, a timestamp, an IP address and a user-agent string. The IP is personal data under s.65. With the user agent it can re-identify a person to anyone holding the vendor's network logs. **There is no erasure path for it** (residual R2) |
| **Restriction (s.34(1)(e))** | Satisfied structurally: no application reader exists. When the booked supervisory export is built, it must render a pseudonymised portal user as "vendor (portal), identity erased", **not** as an unknown actor. That follows ADR 0028 §4's rule against re-spelling |

### 13.7 Residency, access and DSAR

| | |
|---|---|
| **Residency** | The application database. Production runs **MySQL 8.0.46 on the VPS that serves `/var/www/thirdLine-ERM`** (CLAUDE.md, found 2026-10-03). **The VPS provider, the country it is hosted in, and where its database backups go are to be confirmed. I could not verify them and do not assert them.** If any of them is outside Nigeria, NDPA s.41 engages for this table and for every other table in the database. No processor receives this table, and no export of it exists |
| **Access** | No application screen or route reads it. Access today is database access: whoever holds the production DB credentials, plus backups. **To confirm**: who holds them at the bank |
| **DSAR (s.34(1)(a)–(b))** | **No export exists.** A response is a developer's query: `actor_type='portal' AND actor_id=?`, plus `auditable_type` = the `PortalUser` FQCN `AND auditable_id=?`, plus a text search of `before`/`after` for the person's portal-user id (new rows store it as `portal_user_id` or `reported_by_portal_user_id`) and for their email (historic rows identify the vendor only there). Keep the search to plain `LIKE`, not MySQL-8-only JSON functions (CLAUDE.md). The same gap as `ndpa-register-platform.md` §3 |
| **Clause codes** | **None new.** Substance maps to `ndpa.lawful_basis`, `ndpa.retention` and `ndpa.residency`, which are BCMS enum cases with no reach over a `tp_*` table. This is the same platform-taxonomy finding as `ndpa-register-platform.md` §2, and TPRM has none of its own either |

### 13.8 Residual risks the owner must accept, or direct to be fixed

| # | Risk | Can it be fixed? |
|---|---|---|
| **R1** | **Historic sealed payloads carry vendor emails** (eleven writers, §13.5): every invitation, account creation, MFA event, sign-in, sign-out and lockout since go-live. For any vendor who has ever signed in, pseudonymising `tp_portal_users` **does not** achieve erasure, because the email is in the chain beside their id | **No, for existing rows** (ADR 0025 forbids re-sealing). **New rows are fixed:** the ADR 0028 amendment of 2026-10-14 replaced each email with the portal-user id (§13.5). What remains for the owner to accept is the historic rows only |
| **R2** | **`ip` and `user_agent` are sealed personal data with no erasure path.** `ip` is an "online identifier" under s.65 and is stored unminimised (not truncated) | Not for sealed rows. For new rows, truncating the IP before sealing (e.g. /24 for IPv4) is possible, but it weakens the forensic value that is half the trail's purpose. **This is a DPO-and-architect decision; I do not recommend either way without them** |
| **R3** | **Internal staff names in `actor_label`** are sealed for the trail's life | Accept, with a notice (§13.6). The alternative, id-only for staff as well, is the same design as the vendor decision and could be adopted for new rows by an ADR. It has not been decided |
| **R4** | **Third-party personal data inside payloads** (§13.5: grantee name and email, screening `matched_name`, incident free text) is outside the actor contract and outside any erasure path | Accept on evidential grounds, or redesign specific models' `auditExcluded()`. The latter loses access-grant evidence, so this register advises against it for `AccessGrant` |
| **R5** | **No retention period** (§13.6). Indefinite in fact | Needs the DPO's figure, then an ADR for chain truncation |
| **R6** | ~~**The amendment is not in the tree** (§13.2). If the branch is committed and deployed as read today, vendor emails are sealed into `actor_label`~~ | **RESOLVED by ADR 0028 as amended 2026-10-14.** Re-read in the tree: all five portal `actor_label` sites write `null`, and no portal payload carries an email. Struck rather than deleted, so the record shows that it was found and closed before commit. No production row was affected |

### 13.9 Sufficiency — could an examiner be shown this in one click?

**Ask: "show me everything vendor X's staff did on your portal last quarter, and who
at the bank approved their access."** **No.** The data is held and its integrity can
be proven, but no reader, screen or export exists (ADR 0028). The answer today is a
developer's query. The booked supervisory export (ADR 0025) is what makes it one
click. That export must also carry the sentence ADR 0028 §7 leaves to this
register: **"Before 2026-10-05, vendor model writes in this trail are recorded as
`system`; vendor identity on those rows is in the event payload or the audited
record, not in the actor fields."** The date in that sentence should become the
deploy date of ADR 0028 once it is known.

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

### Added at Phase 11, 2026-09-23

14. **Vendor personnel on the roster** (11.1). Supplier staff are matched by email
    between TPRM and BCMS and shown by name with their exercise attendance, to
    board members among others. They are not the bank's employees and not covered
    by its staff privacy notice. Does each critical vendor's §29 agreement (held in
    the TPRM engagement record) say that its personnel's data is used as
    continuity-exercise evidence? If not, whose notice covers it?
15. **Should the board pack print nonconformity descriptions verbatim?** (11.3).
    Today it prints every open nonconformity's full free text, including findings
    raised from post-incident reviews that can name staff and customers, into a
    PDF and PPTX that leave the product's control on download. The alternative is
    reference, clause, severity, age and action count, without the description.
    The board may need the description; if so, that is a decision and not a
    default.
16. **Competency scores in the append-only audit log** (11.4). A person's score
    history, including failed assessments, outlives §3's three-year retention
    indefinitely, because `bcms_audit_logs` has no purge path. That is justified on
    evidential grounds. Does the DPO accept it, and does the staff privacy notice
    say it?

### Added at Phase 7 certification, 2026-10-16

17. **Which NDPA s.30(1) condition covers storing a help reply's health content?**
    (§12.3). s.25(1)(b)(iii) vital interests is a sound basis for the ordinary
    personal data. For the health content, s.30(1)(c) requires that the data
    subject be "physically or legally incapable of giving consent", and a person
    able to type a reply arguably is not. s.30(1)(b) depends on an
    employment-law duty that has not been checked. Neither condition covers a
    stranger on a recycled number, whose reply may now be stored by several
    controllers (§12.4). The DPO either names the condition relied on, with
    reasons, or tells us the ruling needs narrowing. The privacy notice should
    say that a help reply sent from a number on more than one organisation's
    roster may reach each of them.

### Added at post-go-live hardening (ADR 0028), 2026-10-14

18. **A retention period for `tp_audit_logs`** (§13.6). Today the trail is kept
    for ever and nothing can delete it except deleting the tenant. What figure does
    the bank's record-retention schedule set for third-party oversight records? Once
    there is a figure, the architect owes a chain-truncation design. No `DELETE`
    is acceptable on a hash chain.
19. **Residual risks R1–R5** (§13.8): historic sealed vendor emails, sealed
    IP and user-agent values, sealed staff names, and third-party data in payloads.
    Each needs to be accepted or directed. R2 (truncate IPs before sealing, or not)
    is a forensic-against-minimisation decision, and only the DPO can make it.
20. **The portal privacy notice.** Vendor personnel are not covered by the
    bank's staff notice. Does the supplier portal tell them, as s.27 requires, that
    their sign-ins, IP address and device string are kept in a tamper-evident log,
    for how long, and on what basis? If it does not, §13.4's reasonable-expectation
    limb rests on nothing.
21. **Where the production VPS and its backups are hosted** (§13.7). This applies
    to every table, not only this one, and should be answered once for the whole
    deployment.

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

## HANDOFF — Phase 11

**Phase:** P11 — Training & competency, supply-chain resilience, reporting & evidence (register additions; the 8.6 ruling is in `docs/bcms/phase-11-notes.md`)
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** this file. New **§11**: 11.1 vendor email matching, 11.2 CSAT pre-fill, 11.3 exported packs, 11.4 the training register with its five-part access rule, 11.5 clause codes, 11.6 sufficiency. §3's lawful-basis cell corrected. Open questions 14–16
**Clause refs published:** none
**Contracts touched:** none. Documentation only
**Assumptions made:** that a completed CSAT workbook carries officers' names and contact details. This could not be verified, because the product does not hold the template (ADR 0021 §4)
**Known gaps:** no purge command (§10, unchanged). 11.1 case-mismatch vendor id 0. 11.2 no audit row, no upload size limit, temp file left behind on a writer failure. 11.3 finding descriptions printed verbatim into board packs. 11.6 export log capped at 50 rows, with other export doors not checked
**Next agent:** `backend-engineer` (11.1, 11.2, and 11.4's scoping fix to all five rules), then `qa-engineer` and `code-reviewer`
**Verification run:** code read 2026-09-23 on `integration/bcms-remaining`, as listed in 11.1–11.4. No suite run, no database touched. Examiner walkthrough "who took personal data out in a report this year": partly one click (11.6)

## HANDOFF — post-go-live hardening, ADR 0028 (TPRM audit-trail actor fields)

**Phase:** post-go-live hardening (`fix/post-golive-hardening`). TPRM `tp_audit_logs` actor contract, ADR 0028 amendment of 2026-10-14
**Agent:** compliance-analyst
**Status:** complete. Revised the same day after the ADR 0028 amendment was built: §13.2, §13.5, §13.7 and R1 were updated, and R6 is marked resolved
**Delivered:** `docs/compliance/ndpa-register.md`, containing a new **§13** (13.1–13.9; numbered §12 when written, renumbered when Phase 7 certification's §12 merged first) and open questions 18–21. `ndpa-register-platform.md` was not touched: it is scoped to unprefixed tables and names `tp_*` as TPRM
**Clause refs published:** none. The `ndpa.*` codes are BCMS-enum-scoped, which is the same finding as `ndpa-register-platform.md` §2
**Contracts touched:** none. Documentation only
**Assumptions made:** the user's decision of 2026-10-14 (vendor `actor_label` null; identity through `actor_id` → `tp_portal_users`) is the contract, and the amended tree now implements it. Legitimate interest is the basis. No CBN clause is claimed, because the primary CBN text could not be retrieved
**Known gaps:** R1–R5 in §13.8 (R6 resolved). No erasure tool, no DSAR export, no reader, no retention period. VPS hosting country and backup location unconfirmed
**Next agent:** `qa-engineer` and `code-reviewer`, for the ADR 0028 amendment, which is now built. `architect` for R2, R3 and the chain-truncation question. The client's DPO for questions 18–21
**Verification run:** read on 2026-10-14 in the worktree: `AuditLog`, `TprmAuditable`, `AuditText`, the `tp_portal_users` and `tp_audit_logs` migrations, all four portal writers, `PortalAuthService` in full, `AccessService`, ADR 0028, `PortalActorAttributionTest`. Grepped every `actor_*` writer, every `TprmAuditable` model, every prune command, every email write in `app/`, and `TrustProxies`. NDPA 2023 ss.24, 25, 26, 34 and 65 read from the gazetted text (DataGuidance copy). The CBN 2024 framework PDF returned 403, and nothing is cited from it beyond its existence. No suite run, no database touched. **Examiner walkthrough, "show me what vendor X's staff did on your portal": not one click. There is no reader; this is the booked supervisory export (§13.9).**

## HANDOFF — Phase 7 certification (bare-number HELP fan-out)

**Phase:** P7 — EMNS, certification. This is the register entry that code review requires for the owner ruling of 2026-10-16
**Agent:** compliance-analyst
**Status:** complete
**Delivered:** this file. New **§12** (12.1 to 12.10): the fan-out of a token-less help reply across up to 50 open recipients in every tenant; data categories, including health status as s.65 sensitive data; lawful basis s.25(1)(b)(iii); the s.30(1) condition left open for the DPO; the recycled and shared number risk; mitigations, with what each one does not bound; the related Phase 7 changes (closed-category `failed_reason` on status callbacks, the `bcms.contact.export` column split, the `alert.evidence_exported` audit); retention; residency; owner and review triggers; sufficiency. Correction notes added to §6 (the "exactly one" statement), §6.2 (closed in the tree) and §11.6 (alert CSV now audited). New open question 17
**Clause refs published:** none. The entry maps to `ndpa.lawful_basis`, `ndpa.retention` and `ndpa.residency`
**Contracts touched:** none. Documentation only
**Assumptions made:** that "response window" in the ruling means the code's fixed 48 hours after `dispatched_at` (`openRecipients()`), which is what is built; the entry describes that, and not a per-alert window
**Known gaps:** **[verify]** which s.30(1) condition applies (open question 17); **[verify]** whether any Nigerian employment law supports s.30(1)(b); **[verify]** whether each bank's §29 agreement covers cross-estate inbound matching by number; **[verify with DPO]** the basis for a help reply to an exercise alert. Two engineering findings for `backend-engineer`: the cap of 50 has no `orderBy` and drops rows beyond 50 without any signal; there is no way to strike a misattributed `response_text`. The purge command (§10) still does not exist
**Next agent:** `code-reviewer`, to resume the Phase 7 certification gate
**Verification run:** read on `claude/bcms-phase-7-certification-8f875a`, 2026-10-16: `InboundResponseHandler` (whole file), `RollCallService` (whole file), `AlertWebhookController::reply()` and its class docblock, `AlertController::evidence()` and the roll-call route's gate, `EvidenceExport` (docblock, `RESTRICTED_COLUMNS`), `HttpChannel::classifyProviderError()`, `AlertRecipient` (no `BcmsAuditable`), `BcmsAuditable::recordAudit()`, `EvidencePackService::log()` (filters `pack.exported`, so it excludes the alert export), and the `RiskPermissionCatalog` grants of `bcms.contact.export` to the chief-risk-officer and not to compliance-officer. NDPA 2023 enacted text read for s.25(1) (limbs (a) and (b)(i) to (v)), s.30(1)(a) to (i) and s.65's "sensitive personal data" definition, including (d) health status ([DataGuidance copy of the Act](https://www.dataguidance.com/sites/default/files/data_protection_act_2023.pdf)). The brief's "s.25(1)(d)" does not exist and was corrected to s.25(1)(b)(iii). No suite run, no database touched. Examiner walkthrough: "who asked for help and what did they say": yes, one click. "Every place one help message was stored": no, by design (§12.10)
