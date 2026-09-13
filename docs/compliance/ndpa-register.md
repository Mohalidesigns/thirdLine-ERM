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
| **Retention** | For the duration of employment plus 90 days, to cover an exit that overlaps an open incident. Then erased, not anonymised — an anonymised phone number is still a phone number. Enforced by a Phase 2C job. |
| **Residency** | af-south-1 or on-prem (Blueprint §14). The demo cloud region is seeded as South Africa deliberately, so residency is a visible question rather than a hidden one. |
| **DSAR** | Export of a contact and its delivery history, per subject. Phase 2C. |
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
