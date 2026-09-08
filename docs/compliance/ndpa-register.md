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
   Nigeria — which most WhatsApp and push providers do. This is an NDPA s.41
   question per provider and it belongs in the TPRM engagement record for that
   provider, not here.

## HANDOFF

**Phase:** P0 — Foundations & schema freeze
**Agent:** compliance-analyst
**Status:** complete for Phase 0 — this register is reopened by every phase that touches personal data, and Phase 2C owns the largest addition
**Delivered:** this file
**Contracts touched:** none
**Assumptions made:** consent as the basis for personal channels; 12-month retention for exercise-linked delivery records; the vital-interests exception scoped to `is_life_safety` traffic only
**Known gaps:** the three open questions above; the retention jobs themselves are Phase 2C
**Next agent:** integrations-engineer at Phase 2C, before the first directory sync writes a real person's number
