# BCMS exercise scenario pack: 44 system templates

**Owner:** compliance-analyst · **Phase:** BCMS Phase 12, stage 0 (content draft) · **Date:** 2026-09-24
**Converts into:** `database/seeders/Bcms/Reference/Scenarios.php` (12A-2), seeded by
`BcmsReferenceSeeder::seedScenarios()` into `bcms_scenarios` system rows (`organization_id = null`).
**Governed by:** ADR 0023 §3.2 (output contract) and §3.5 (content), work order §9.

This pack is content only. It does not touch code. It is drafted here because `Scenarios.php` and the
seeder sit in a tree that holds uncommitted Phase 9–11 work (work order §1.4).

---

## 1. Shape: one YAML block per scenario, and one key per PHP array key

Every block below maps onto one element of `Scenarios::all()`. The keys are the PHP keys the seeder
already reads, plus one new key, `injects`.

| YAML key | PHP key | Column on `bcms_scenarios` | Note |
|---|---|---|---|
| `code` | `code` | `code` | Natural key. The seeder runs `updateOrCreate(['organization_id' => null, 'code' => …])` |
| `name` | `name` | `name` | ≤ 200 |
| `category` | `category` | `category` | **Must be a key in the threat catalogue** (`threat-catalogue.md`). The generator's grounding matches on it (ADR 0023 §3.3) |
| `ladder_min` | `ladder_min` | `ladder_level_min` | A `LadderLevel` value. The PHP form is `LadderLevel::Tabletop->value` |
| `summary` | `summary` | `summary` | ≤ 600 |
| `narrative` | `narrative` | `narrative` | ≤ 1,500 |
| `objectives` | `objectives` | `suggested_objectives` | 2 to 6, each ≤ 300 |
| `drivers` | `drivers` | `regulatory_drivers` | `IsoClauseRef` values only (§4) |
| `injects` | **`injects` (new)** | `suggested_injects` | 4 to 10. **One seeder line is needed:** `'suggested_injects' => $scenario['injects']` |

Each inject has exactly the shape ADR 0023 §3.2 fixes for a generated variant. A shipped template and a
generated variant therefore pass the same validator:

| Inject key | Limit | Where it goes when applied to an occurrence |
|---|---|---|
| `sequence` | 1, 2, 3 … | `bcms_exercise_injects.sequence` |
| `release_offset_minutes` | Starts at 0, never decreases, within the ladder bound (§3) | `.release_offset_minutes` |
| `delivery_channel` | The vocabulary in §2 | `.delivery_channel` (`string(20)`) |
| `title` | ≤ 250 | `.title` |
| `content` | ≤ 400 | `.content` |
| `expected_response` | ≤ 300 | No column. ADR 0023 §3.2 folds it into `content` under a heading when the inject is applied |

**Existing rows keep their text.** For the ten scenarios already in `Scenarios.php`, this pack gives
only `code` and `injects`, plus one category change (§6). Every other field stays exactly as it is in
the PHP file, so nothing has to be transcribed twice.

## 2. `delivery_channel`: a vocabulary the codebase does not have yet

ADR 0023 §3.2 says that `delivery_channel` "comes from the vocabulary the execution workspace already
accepts" and that the validator rejects anything else. **No such vocabulary exists.** I checked on
2026-09-24. `bcms_exercise_injects.delivery_channel` is a free `string(20)`. No Form Request, enum,
presenter or screen constrains it or reads it, and Phase 9 releases injects in-app only
(`phase-9-notes.md` §4). So `ScenarioDraftValidator` currently has nothing to validate against.

This pack uses the following closed list. **The architect has to ratify it (or rename it) before 12A-2**,
and it should then become an enum or a constant that both the validator and the seeder test read:

| Value | Meaning |
|---|---|
| `in_room` | The facilitator reads it out or hands it over in the room |
| `sim_phone` | A role-player telephones a participant |
| `sim_email` | A simulated email is shown or forwarded to participants |
| `sim_sms` | A simulated text message, or an EMNS alert shown as part of the exercise |
| `sim_media` | A simulated news item, social media post or journalist enquiry |
| `sim_system` | A simulated monitoring alert, dashboard reading or system error |

**Why every value except `in_room` starts `sim_`.** An inject value of plain `sms` would, the day
someone wires EMNS delivery of injects (Phase 9's deferred item), read as an instruction to send a real
SMS. The prefix says in the data itself that the channel is simulated. An inject that should really go
through EMNS is an EMNS simulation alert, which carries its own `is_simulation` flag and exercise
prefix. It is not an inject channel.

## 3. Offsets are bounded by the ladder level's default duration

The generator's brief carries its own `duration_minutes` (60 to 480), which is checked at generation
time. A system template has no duration column. So that a template applied through the default exercise
type never schedules an inject after the exercise has ended, every offset here stays inside the default
duration of the `ExerciseTypes` rung it starts at:

| `ladder_min` | Shortest default duration on that rung (`ExerciseTypes.php`) | Largest offset used in this pack |
|---|---|---|
| `orientation` | 60 (ORIENT) | ≤ 50 (no orientation templates ship) |
| `tabletop` | 180 (TABLETOP, PANDEMIC) | ≤ 170 |
| `walkthrough` | 120 (WALKTHRU, SUPPLIER) | ≤ 110 |
| `drill` | 45 (FIREDRILL) | ≤ 40 |
| `functional` | 240 (CYBER, CRISISSIM) | ≤ 230 |
| `full_scale` | 600 (FULLSCALE) | none ship at this rung (§7) |

Scenario time is compressed. An inject 60 minutes into a tabletop may say "Day 3, 09:00". The
`content` states the scenario time wherever it differs from the exercise clock.

## 4. Drivers: what a scenario helps evidence, never a claim that a rule requires the scenario

`drivers` holds only published `IsoClauseRef` values. A driver means *"running this scenario produces
evidence relevant to this obligation"*. It does not mean that the regulator requires this scenario.
Where no obligation is honestly engaged, the list is empty, as the existing `FLOOD-LAGOS` row's already
is. The Nigerian obligations cited, and their sources, are:

- `cbn.rcf.incident_response`: CBN Risk-Based Cybersecurity Framework for DMBs and PSBs (2024,
  effective 1 July 2024). Cyber incidents are reported to the CBN within 24 hours
  ([CBN](https://www.cbn.gov.ng/Out/2024/BSD/CBN%20Risk-Based%20Cybersecurity%20Framework%20for%20DMBs%20and%20PSBs_2024.pdf);
  [Mondaq overview](https://www.mondaq.com/nigeria/security/1518574/overview-of-the-cbn-risk-based-cybersecurity-framework-and-guidelines-for-deposit-money-banks-and-payment-service-banks)).
  **The 24-hour objective appears only in cyber scenarios.** No CBN reporting clock is asserted for
  robbery, unrest or outages. Those scenarios say "the regulatory notification procedure", because I
  did not verify a clock for them.
- `cbn.rcf.cyber_drills`: the same framework requires participation in NigFinCERT and industry cyber
  exercises (same sources).
- `cbn.open_banking.failover`, `.threshold`, `.dr_test`: CBN Operational Guidelines for Open Banking,
  as already cited in `ClauseRefs.php`. These are used only where the scenario actually exercises
  failover of API services.
- `ndpa.breach_notification`: NDPA 2023 s.40, 72 hours from awareness (already published at Phase 10).

## 5. Rules the 12A-2 seeder test should assert, one per line

1. There are at least 42 rows. **This pack has 44.**
2. Every row has 4 to 10 injects and 2 to 6 objectives.
3. `sequence` runs 1..n without gaps. `release_offset_minutes` starts at 0 and never decreases.
4. Every offset is within the §3 bound for the row's `ladder_min`.
5. Every `delivery_channel` is in the §2 list.
6. Every `drivers` entry is a case of `IsoClauseRef`.
7. Every `category` is a key of `ThreatCatalogue`.
8. Every string is within its §1 length.
9. `code` is unique, and none of the ten existing codes has changed.
10. No row contains an E.164 number, an email address or a personal name. The same scan the guardrail
    suite runs over prompts (ADR 0023 §2.1) runs here, because every row is grounding for a prompt.
11. `ai_generated = false` on every system row. A person wrote these.

## 6. The ten existing templates: the one change beyond adding injects

- **`FIBRE-CUT` moves from category `system` to `connectivity`.** The generator grounds a variant on
  the threat-catalogue entry that matches the category (ADR 0023 §3.3). Under `system`, a fibre-cut
  variant would be grounded on the core-platform entry and would be told nothing about vandalised
  metro fibre or shared last-mile routes. Nothing in `app/`, `tests/` or `database/` reads a BCMS
  scenario's category or code (checked 2026-09-24), so the change is safe.

## 7. Coverage

| Blueprint §12 family | Category | Templates | Count |
|---|---|---|---|
| F1 National grid collapse plus generator fuel shortage | `power` | GRID-DIESEL, GRID-COLLAPSE-NATIONAL, POWER-BRANCH-GENSET, POWER-DC-GENERATOR, POWER-VANDAL-REGIONAL | 5 |
| F2 Fuel scarcity affecting staff mobility | `fuel` | FUEL-SCARCITY-MOBILITY, FUEL-TANKER-STRIKE, FUEL-CIT-FLEET, FUEL-REMOTE-SWITCH | 4 |
| F3 Civil unrest near a branch cluster | `civil_unrest` | UNREST-CURFEW, UNREST-BRANCH-CLUSTER, PROTEST-ANNOUNCED-DAYS, UNREST-CASH-CROWDS, UNREST-LOCKDOWN-DRILL | 5 |
| F4 Submarine cable cut degrading connectivity | `connectivity` | FIBRE-CUT, CABLE-SUBSEA-MULTI, CLOUD-UNREACHABLE, FIBRE-VANDAL-METRO, CONNECT-FAILOVER-OB | 5 |
| F5 Ransomware on core banking | `cyber` | RANSOMWARE, INSIDER-FRAUD, RANSOMWARE-CORE, CYBER-DDOS, CYBER-EXFIL-EXTORTION, CYBER-MSP-COMPROMISE | 6 |
| F6 Flooding in Lagos/Bayelsa | `flood` | FLOOD-LAGOS, FLOOD-BAYELSA, FLOOD-LAGOS-FLASH, FLOOD-DC-BASEMENT, FLOOD-CORRIDOR | 5 |
| F7 Armed robbery at a branch | `security` | ROBBERY-BRANCH, ROBBERY-MULTI-BRANCH, ROBBERY-CIT, KIDNAP-STAFF | 4 |
| F8 Mobile-network outage during month-end | `telecom` | TELCO-MONTH-END, TELCO-SMS-OTP, TELCO-USSD-CHANNEL, TELCO-EMNS-DEGRADED | 4 |
| Outside the eight | `system`, `supplier`, `pandemic`, `fire` | CORE-OUTAGE, DC-LOSS, SUPPLIER-FAIL, SUPPLIER-SWITCH, PANDEMIC, FIRE-BRANCH | 6 |
| **Total** | | **10 existing (injects added) + 34 new** | **44** |

**By ladder rung:** tabletop 23, walkthrough 8, drill 6, functional 7, orientation 0, full-scale 0.
**Injects:** 272 in all. The ten existing rows gain 61, and the 34 new rows carry 211.
There is no full-scale template on purpose. `ladder_min` is the rung where a scenario *starts* being
useful, and each functional template can be run at full scale once the programme has earned it. A
template that *starts* at full scale would invite a bank to schedule one with no tabletop behind it,
which is the ladder violation the engine warns against.

**Grounding rule for every row.** No real bank's name, branding or staff appear anywhere. Places are
real (Lagos, Yenagoa, Lokoja, Kano) because a scenario library that avoids naming Nigerian places is
not locally grounded. Branches are labelled B1, Y1 and so on. Numbers inside a scenario are scenario
facts, not claims about the world. Claims about the world are in the threat catalogue, with sources.

---

## 8. The ten existing templates: injects added

### 8.1 GRID-DIESEL (F1, tabletop)

```yaml
code: GRID-DIESEL
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Grid supply lost across the state"
    content: "06:40. Grid supply is lost across the state. Head office and four branches switch to generator. The distribution company gives no restoration time."
    expected_response: "Facilities confirms every site's generator status and fuel level within 30 minutes and reports to the incident lead."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_phone
    title: "Fuel supplier cannot confirm a delivery"
    content: "The contracted diesel supplier calls: a distribution problem means no delivery can be confirmed for 48 hours. Head office holds about 30 hours of fuel; branches B2 and B4 hold under 12."
    expected_response: "The team invokes the fuel-rationing decision in the plan and asks procurement to contact the alternate supplier."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: sim_system
    title: "Network equipment at branch B4 on battery"
    content: "Monitoring shows branch B4 network equipment on battery: the generator has tripped and restarted twice. Battery runtime is about 4 hours."
    expected_response: "IT confirms which B4 services fail when the battery is exhausted, and the branch manager is told the cut-off time."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: sim_email
    title: "Alternate supplier quotes at a premium"
    content: "The alternate supplier can deliver to head office tomorrow at a price well above contract. It cannot reach the branches."
    expected_response: "A named authority approves or rejects the spend inside the plan's delegated limit, and the decision is logged."
  - sequence: 5
    release_offset_minutes: 100
    delivery_channel: in_room
    title: "Decide which branches close"
    content: "Scenario time 18:00, day 1. B2 and B4 will run dry before any delivery. Customers are already queuing at B2 for cash."
    expected_response: "The team decides which sites power down, in what order and what customers are told, using the prioritised-activity list."
  - sequence: 6
    release_offset_minutes: 130
    delivery_channel: sim_media
    title: "Complaints on social media"
    content: "Posts claim the bank has shut branches without notice. A journalist asks whether the bank is running out of cash."
    expected_response: "The approved holding statement is issued through the named spokesperson. Nobody else comments."
  - sequence: 7
    release_offset_minutes: 155
    delivery_channel: in_room
    title: "Day 2: grid partly restored"
    content: "Scenario time 09:00, day 2. Grid supply returns to head office only. Branches remain on generator."
    expected_response: "The team sets the criteria for reopening branches and the order in which they return."
```

### 8.2 FIBRE-CUT (F4, walkthrough): category changes to `connectivity`

```yaml
code: FIBRE-CUT
category: connectivity   # was: system (see section 6)
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Primary link down"
    content: "09:15. Monitoring reports the primary fibre link to the data centre down. Branch teller sessions start timing out."
    expected_response: "The network team attempts failover to the secondary path, following the runbook step."
  - sequence: 2
    release_offset_minutes: 10
    delivery_channel: sim_system
    title: "Secondary path also down"
    content: "The secondary path fails at 09:17. The carrier says both routes share the last two kilometres, which road works have cut."
    expected_response: "The shared route is recorded as a single point of failure, and the team moves to the VSAT fallback step."
  - sequence: 3
    release_offset_minutes: 25
    delivery_channel: sim_system
    title: "VSAT carrying five per cent"
    content: "The VSAT fallback is up but carries about five per cent of normal throughput. Teller, ATM and mobile traffic compete for it."
    expected_response: "The team applies the traffic-priority list in the plan and names the services that are held."
  - sequence: 4
    release_offset_minutes: 45
    delivery_channel: sim_phone
    title: "Branch manager asks what to do"
    content: "A branch manager calls: queues are growing, and staff do not know whether to switch to manual procedures."
    expected_response: "The branch is walked through the documented manual-service procedure, and any gap in the plan step is noted."
  - sequence: 5
    release_offset_minutes: 70
    delivery_channel: sim_phone
    title: "Carrier gives a repair estimate"
    content: "The carrier estimates 6 to 8 hours to splice both cables."
    expected_response: "The team decides whether to invoke the DR site now or wait, against the RTO of the affected services, and logs the reason."
  - sequence: 6
    release_offset_minutes: 95
    delivery_channel: in_room
    title: "Walkthrough close"
    content: "Review each step walked. Which steps could not be executed as written?"
    expected_response: "Every step that could not be followed is recorded as a finding with an owner."
```

### 8.3 RANSOMWARE (F5, tabletop)

```yaml
code: RANSOMWARE
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Overnight job fails"
    content: "02:10. An overnight batch job fails with file-access errors. The on-call engineer restarts it and it fails again."
    expected_response: "On-call escalates under the incident procedure instead of retrying again."
  - sequence: 2
    release_offset_minutes: 25
    delivery_channel: sim_system
    title: "Encryption confirmed"
    content: "Scenario time 07:00. File shares and three application servers are encrypted, with a ransom note on each."
    expected_response: "The cyber incident plan is invoked, affected systems are isolated and the crisis team convenes."
  - sequence: 3
    release_offset_minutes: 50
    delivery_channel: sim_email
    title: "Two backups will not mount"
    content: "The backup catalogue lists the last four nightly backups as complete. Two of them will not mount."
    expected_response: "The team finds the most recent restore point verified as clean, and does not restore from an unverified one."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: in_room
    title: "Last verified-clean restore point is nine days old"
    content: "The newest backup that can be verified clean is nine days old."
    expected_response: "The team decides what data has to be re-created and how, and names who confirms it."
  - sequence: 5
    release_offset_minutes: 95
    delivery_channel: in_room
    title: "The CBN reporting clock"
    content: "Five hours have passed since detection."
    expected_response: "The CBN incident report is drafted and approved inside the 24-hour window, and the NigFinCERT notification is prepared."
  - sequence: 6
    release_offset_minutes: 120
    delivery_channel: sim_phone
    title: "A journalist calls"
    content: "Scenario time 11:00. A caller who says he is a journalist asks whether customer data was stolen."
    expected_response: "The call goes to the spokesperson. The holding statement confirms nothing that has not been established."
  - sequence: 7
    release_offset_minutes: 145
    delivery_channel: in_room
    title: "Was personal data read?"
    content: "Forensics finds evidence that a customer file share was read before it was encrypted."
    expected_response: "The DPO assesses whether this is a personal data breach. If it is, the NDPC 72-hour clock runs from the moment of awareness."
```

### 8.4 CORE-OUTAGE (system, walkthrough)

```yaml
code: CORE-OUTAGE
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Core banking stops responding"
    content: "Thursday 10:20. Teller, internet banking and USSD sessions fail. The core banking dashboard shows the application tier down."
    expected_response: "IT declares an incident, and branches are told to expect manual service."
  - sequence: 2
    release_offset_minutes: 10
    delivery_channel: sim_phone
    title: "Vendor acknowledges the incident without a restoration time"
    content: "The core banking vendor confirms an incident on its side and will not commit to a restoration time."
    expected_response: "The incident lead records the vendor's position and sets the time of the next checkpoint."
  - sequence: 3
    release_offset_minutes: 25
    delivery_channel: sim_phone
    title: "Queues, and a large withdrawal demanded"
    content: "A branch manager reports long queues and a corporate customer who demands a large cash withdrawal now."
    expected_response: "The branch applies the documented offline limits, and the escalation route for amounts above them."
  - sequence: 4
    release_offset_minutes: 45
    delivery_channel: in_room
    title: "Manual transactions are piling up"
    content: "Branches have taken 140 manual transactions in the first hour."
    expected_response: "The team confirms how manual records are captured, who holds them and how they will be reconciled when service returns."
  - sequence: 5
    release_offset_minutes: 70
    delivery_channel: sim_email
    title: "Read-only access restored"
    content: "Scenario time 13:30. The vendor restores read-only access. Balance enquiries work; postings do not."
    expected_response: "The team decides which services resume in read-only mode and what customers are told."
  - sequence: 6
    release_offset_minutes: 100
    delivery_channel: in_room
    title: "Full service returns"
    content: "Scenario time 14:20. Full service returns."
    expected_response: "The walkthrough traces the reconciliation step by step and records every step that cannot be followed as written."
```

### 8.5 FLOOD-LAGOS (F6, tabletop)

```yaml
code: FLOOD-LAGOS
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Approach roads under water"
    content: "Day 1, 06:00. After two days of rain the approach roads to head office are under water. Traffic reports say key routes are closed."
    expected_response: "The incident lead decides whether head office opens, and starts the staff roll-call."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_sms
    title: "Roll-call responses"
    content: "By 07:30, 55% of head office staff have answered, and 60% of those say they cannot travel. Four people have not answered. All four live in low-lying areas."
    expected_response: "Managers follow up the people who have not answered through the call tree, and the welfare checks are logged."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: sim_phone
    title: "Water in the generator room"
    content: "Facilities reports standing water in the generator room. The generator is still running."
    expected_response: "The team decides whether to shut the generator down safely before the water reaches it, and identifies what depends on it."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: in_room
    title: "Remote working falls short"
    content: "Only 40% of staff in critical activities can work remotely. The payments team's remote-access tokens are in the office."
    expected_response: "The team names the prioritised activities that move to the alternate site, and how staff will get there."
  - sequence: 5
    release_offset_minutes: 110
    delivery_channel: sim_media
    title: "Day 2: residents told to move"
    content: "Scenario time day 2, 08:00. State emergency management advises residents of low-lying areas to move to higher ground."
    expected_response: "Welfare actions are confirmed for staff in the affected areas, including anyone who has had to leave home."
  - sequence: 6
    release_offset_minutes: 140
    delivery_channel: in_room
    title: "Day 3: water receding"
    content: "The water is going down. The building needs an electrical safety check before anyone moves back in."
    expected_response: "The team sets the criteria for returning and names who signs the building off as safe."
```

### 8.6 UNREST-CURFEW (F3, tabletop)

```yaml
code: UNREST-CURFEW
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Curfew announced"
    content: "14:00. The state government announces a curfew from 18:00. Reports describe unrest spreading in the city centre."
    expected_response: "The crisis team convenes and sets branch closing times so that staff reach home before 18:00."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_phone
    title: "Three branches closing with cash above limit"
    content: "The managers at B1, B3 and B5 report that they are closing early with vault cash above normal limits."
    expected_response: "The team decides whether the cash stays secured on site or is moved, with staff safety first."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: sim_sms
    title: "Roll-call gaps"
    content: "An EMNS roll-call has gone out. After 45 minutes, 12 staff have not answered, two of them at B3."
    expected_response: "Managers follow up everyone who has not answered, and the unaccounted list is kept current."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: sim_phone
    title: "Staff member stranded"
    content: "A cashier calls: she cannot get home before the curfew and is sheltering in a shop near B3."
    expected_response: "The team arranges a safe option for her and records the welfare decision."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: sim_media
    title: "Curfew extended"
    content: "Scenario time day 2, 07:00. The curfew is extended by 48 hours. Only essential movement is allowed."
    expected_response: "The team decides which activities continue remotely, and how the branches reopen."
  - sequence: 6
    release_offset_minutes: 120
    delivery_channel: sim_email
    title: "Customers ask when branches reopen"
    content: "Customers are asking on social media when branches will reopen."
    expected_response: "A service notice is approved and published on the agreed channels. The regulatory notification procedure is followed if the suspension reaches its threshold."
  - sequence: 7
    release_offset_minutes: 150
    delivery_channel: in_room
    title: "Curfew lifted"
    content: "The curfew is lifted."
    expected_response: "The team sets criteria for reopening each site, including a security assessment."
```

### 8.7 SUPPLIER-FAIL (supplier, walkthrough)

```yaml
code: SUPPLIER-FAIL
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Card processor down"
    content: "08:00. The card processor's services fail. Card transactions are declined at ATMs and POS terminals."
    expected_response: "An incident is raised, and the supplier escalation contacts named in the contract are called."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_email
    title: "Status page, but no restoration time"
    content: "The provider's status page acknowledges the outage. Its account team cannot give a restoration time."
    expected_response: "The team records which contractual availability and notification commitments are now breached."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: in_room
    title: "The documented workaround"
    content: "The documented workaround sends part of the card traffic to a secondary processor."
    expected_response: "The team walks each step of the workaround and confirms who can carry out each one."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: sim_phone
    title: "Provider will not share its continuity plan"
    content: "Asked whether its continuity plan has been invoked, the provider says it has but will not share any details."
    expected_response: "The team records what evidence of the provider's continuity arrangement it holds, and what it will request."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: in_room
    title: "Contractual follow-up"
    content: "Service is back. What does the contract give you now?"
    expected_response: "Service credits, the root-cause report and the exit-plan triggers are identified in the contract and assigned to owners."
```

### 8.8 DC-LOSS (system, functional)

```yaml
code: DC-LOSS
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Fire at the primary data centre"
    content: "03:00. The data centre operator reports a fire and a suppression discharge. The site has been handed to the fire service."
    expected_response: "The IT DR manager invokes DR under the authority in the runbook."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_system
    title: "Tier-1 services unreachable"
    content: "Every service hosted at the primary site is unreachable."
    expected_response: "The recovery team starts tier-1 recovery at the DR site in the documented order."
  - sequence: 3
    release_offset_minutes: 60
    delivery_channel: sim_system
    title: "A dependency missing at DR"
    content: "At DR the payments application starts but cannot reach the directory service, which was not in the recovery order."
    expected_response: "The dependency is fixed and recorded as a finding, and the recovery order is corrected."
  - sequence: 4
    release_offset_minutes: 110
    delivery_channel: in_room
    title: "RTO checkpoint"
    content: "It is 04:50. Which tier-1 systems are within their RTO?"
    expected_response: "The actual recovery time of each tier-1 system is recorded against its RTO."
  - sequence: 5
    release_offset_minutes: 160
    delivery_channel: sim_system
    title: "Transactions missing at DR"
    content: "Reconciliation finds that transactions from the last 12 minutes before the fire are missing at DR."
    expected_response: "The team establishes whether the loss is within RPO, and recovers or re-creates the missing transactions."
  - sequence: 6
    release_offset_minutes: 200
    delivery_channel: in_room
    title: "Start of business"
    content: "07:30. Branches open at 08:00."
    expected_response: "The team confirms that the MBCO is available, and that branches and customers are told what works and what does not."
```

### 8.9 PANDEMIC (pandemic, tabletop)

```yaml
code: PANDEMIC
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Week 2: absence at 25%"
    content: "A public health emergency has been declared. Absence stands at 25% and is rising."
    expected_response: "Every owner of a prioritised activity confirms its current staffing against the stated minimum."
  - sequence: 2
    release_offset_minutes: 30
    delivery_channel: sim_email
    title: "Movement restrictions"
    content: "The authorities restrict movement between states. Banks may open with reduced staff."
    expected_response: "The team confirms who is designated essential and how those staff will travel."
  - sequence: 3
    release_offset_minutes: 60
    delivery_channel: in_room
    title: "Week 3: absence at 40%"
    content: "Three critical roles now have no deputy available."
    expected_response: "Succession is exercised for each critical role, and the gaps are recorded."
  - sequence: 4
    release_offset_minutes: 90
    delivery_channel: sim_system
    title: "Remote access at capacity"
    content: "The remote-access gateway reaches capacity by 09:00."
    expected_response: "IT gives critical roles priority for remote access and records the capacity actually needed."
  - sequence: 5
    release_offset_minutes: 120
    delivery_channel: sim_phone
    title: "Two branch staff test positive"
    content: "Two members of staff at one branch test positive."
    expected_response: "The team walks through closing the branch, cleaning it and notifying contacts, while protecting the privacy of the two staff members."
  - sequence: 6
    release_offset_minutes: 150
    delivery_channel: in_room
    title: "Week 6: absence falling"
    content: "Absence has fallen to 10%, and restrictions are being eased."
    expected_response: "The team sets the criteria for returning to normal staffing."
```

### 8.10 INSIDER-FRAUD (F5, tabletop)

```yaml
code: INSIDER-FRAUD
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Reconciliation break"
    content: "A daily reconciliation shows differences nobody can explain. They trace back to a scheduled process that was changed two days ago."
    expected_response: "The anomaly is escalated to the incident lead, and the account holder is not alerted."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_system
    title: "Out-of-hours administrator login"
    content: "The logs show the change was made at 23:40 from an administrator account. It belongs to a member of staff who is on duty today."
    expected_response: "The team chooses containment that preserves the evidence and does not warn the subject."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: in_room
    title: "Outgoing payments delayed"
    content: "The altered process is also delaying outgoing payments."
    expected_response: "The process is restored from a verified configuration, and the impact on customers is assessed."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: sim_email
    title: "HR asks whether to suspend"
    content: "HR asks whether the staff member should be suspended now."
    expected_response: "The team decides how the investigation, the disciplinary process and legal advice fit together, and logs the decision."
  - sequence: 5
    release_offset_minutes: 105
    delivery_channel: in_room
    title: "Is it reportable?"
    content: "Containment is in place. Who else needs to know?"
    expected_response: "The team decides whether the event is reportable, to whom and by when, and records its reasoning."
```

---

## 9. The 34 new templates

### 9.1 GRID-COLLAPSE-NATIONAL (F1, tabletop)

```yaml
code: GRID-COLLAPSE-NATIONAL
name: "National grid collapse with generator fuel shortage"
category: power
ladder_min: tabletop
summary: "The national grid collapses, and restoration is phased over several days. Demand for diesel spikes at the same moment, so generator fuel is both scarce and expensive."
narrative: "At 11:20 on a Tuesday the national grid collapses. The transmission operator announces a phased restoration and gives no times. Every site is on generator. By 15:00 the diesel suppliers report that demand has outrun their stock and that deliveries are being rationed across customers. Head office holds 36 hours of fuel, the data centre 48 hours, and most branches between 8 and 20 hours. The exercise runs from the collapse through the first 72 hours, in compressed scenario time."
objectives:
  - "Produce a fuel-runtime position for every site within 60 minutes of the collapse"
  - "Decide which sites power down, and in what order, using the prioritised-activity list"
  - "Keep the data centre and tier-1 services running, or decide to fail over, before the fuel runs out"
  - "Issue customer and staff communication about reduced branch service within 2 hours"
drivers: [cbn.rcf.bc_dr]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Grid collapse reported"
    content: "11:20. News reports say the national grid has collapsed. The distribution company confirms supply loss across your operating states."
    expected_response: "Facilities confirms that every site has transferred to generator, and starts building the fuel position."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_phone
    title: "Diesel rationed"
    content: "Scenario time 15:00. Your main diesel supplier says it is rationing deliveries: at most one per customer per week, at a higher price."
    expected_response: "The team applies the fuel-rationing priority in the plan, and procurement calls the alternate supplier."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: in_room
    title: "Fuel runtime table"
    content: "Head office 36h, data centre 48h, branches 8 to 20h. Eleven branches have under 12h."
    expected_response: "The team ranks the branches for closure on customer impact and fuel runtime, and names who decides."
  - sequence: 4
    release_offset_minutes: 70
    delivery_channel: sim_system
    title: "Data centre generator temperature alarm"
    content: "After 9 hours at full load, the data centre generator raises a high-temperature alarm."
    expected_response: "IT and facilities decide whether to prepare a DR failover rather than risk an uncontrolled shutdown."
  - sequence: 5
    release_offset_minutes: 95
    delivery_channel: sim_media
    title: "Unstable partial restoration"
    content: "Day 2, 06:00. Supply returns to part of one region, but it is unstable and voltage swings are being reported."
    expected_response: "The team decides whether affected sites go back to grid or stay on generator to protect equipment."
  - sequence: 6
    release_offset_minutes: 125
    delivery_channel: sim_email
    title: "Consumer complaints"
    content: "Customers complain about closed branches, and a consumer group asks whether cash services will continue."
    expected_response: "An approved notice says which branches and channels are open, and it is updated on a fixed schedule."
  - sequence: 7
    release_offset_minutes: 155
    delivery_channel: in_room
    title: "Day 3: fuel delivered, grid stable"
    content: "A fuel delivery arrives and the grid has been stable for 12 hours."
    expected_response: "Stand-down criteria are agreed, and the fuel-reserve policy is reviewed as a finding."
```

### 9.2 POWER-BRANCH-GENSET (F1, drill)

```yaml
code: POWER-BRANCH-GENSET
name: "Branch generator fails during banking hours"
category: power
ladder_min: drill
summary: "Grid supply to a branch drops during banking hours and the standby generator will not start. The UPS gives about 20 minutes. Branch staff run the power-failure procedure for real."
narrative: "At 11:00 grid supply to the branch fails and the generator does not start. The UPS carries the tellers, the network rack and the CCTV recorder for about 20 minutes, and the banking hall is full. The drill tests whether the branch completes the power-failure procedure before the UPS runs out. That means securing cash, closing open transactions, either switching to manual service or closing the hall, and reporting to the regional office."
objectives:
  - "Close or suspend every open teller transaction before the UPS runs out"
  - "Secure cash and the vault within 15 minutes of the generator failure"
  - "Report to the regional office and facilities within 10 minutes"
  - "Tell customers in the hall what is happening and what service remains"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Power lost; generator will not start"
    content: "The lights drop and the UPS alarm sounds. The security guard reports that the generator will not start."
    expected_response: "The branch manager declares the power-failure procedure and assigns roles."
  - sequence: 2
    release_offset_minutes: 5
    delivery_channel: sim_system
    title: "UPS: 18 minutes left"
    content: "The UPS panel shows 18 minutes of runtime left."
    expected_response: "Tellers are told to complete or suspend their open transactions now."
  - sequence: 3
    release_offset_minutes: 10
    delivery_channel: in_room
    title: "Customer insists on a transfer"
    content: "A customer at the counter insists that a transfer is completed before the systems go down."
    expected_response: "The teller follows the procedure, completing or suspending the transaction with a record, and tells the customer which."
  - sequence: 4
    release_offset_minutes: 15
    delivery_channel: sim_phone
    title: "Technician 90 minutes away"
    content: "Facilities says the generator technician cannot arrive for 90 minutes."
    expected_response: "The manager decides whether to close the banking hall or continue manual service, and informs the regional office."
  - sequence: 5
    release_offset_minutes: 25
    delivery_channel: in_room
    title: "UPS: 3 minutes left"
    content: "The UPS panel shows 3 minutes."
    expected_response: "Every system is shut down cleanly, cash and vault are secured, and the loss of CCTV power is noted."
  - sequence: 6
    release_offset_minutes: 35
    delivery_channel: in_room
    title: "Hall closed"
    content: "The hall is closed to customers."
    expected_response: "Customers are directed to the nearest open branch, and the time of each step is recorded for the drill report."
```

### 9.3 POWER-DC-GENERATOR (F1, functional)

```yaml
code: POWER-DC-GENERATOR
name: "Data centre utility loss and generator transfer failure"
category: power
ladder_min: functional
summary: "Utility supply to the primary data centre fails, and the automatic transfer to generator does not complete. The UPS gives 15 minutes. The team has to choose between a controlled shutdown and a failover, then carry it out."
narrative: "At 14:10 utility supply to the primary data centre is lost. The automatic transfer switch fails to bring the generator onto the load, and the UPS will carry the room for about 15 minutes at current load. The facilities engineer thinks a manual transfer is possible but cannot say how long it will take. The exercise runs against the real DR arrangement for the in-scope services, in a controlled window."
objectives:
  - "The named authority takes the failover or controlled-shutdown decision within 5 minutes of the transfer failure"
  - "Fail tier-1 API services over to DR within the 30-minute threshold"
  - "Record the actual RTO and RPO of each in-scope service against its target"
  - "Carry out and time the fail-back once utility supply is stable"
drivers: [cbn.open_banking.failover, cbn.open_banking.threshold, cbn.rcf.bc_dr]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Utility supply lost; transfer switch fault"
    content: "14:10. Building management reports utility supply lost. The transfer switch shows a fault. The generator is running but is not carrying the load."
    expected_response: "The IT DR manager is called and the incident is declared."
  - sequence: 2
    release_offset_minutes: 5
    delivery_channel: sim_phone
    title: "Manual transfer uncertain"
    content: "The facilities engineer says a manual transfer might work in 10 minutes, or might take an hour."
    expected_response: "The named authority decides to fail over now, or to start a controlled shutdown while the transfer is attempted. The time of the decision is logged."
  - sequence: 3
    release_offset_minutes: 15
    delivery_channel: sim_system
    title: "UPS: 5 minutes left"
    content: "The UPS reports 5 minutes of runtime left at current load."
    expected_response: "Non-essential loads are shed to extend UPS runtime, as the runbook directs."
  - sequence: 4
    release_offset_minutes: 40
    delivery_channel: sim_system
    title: "Internet banking fails its health check at DR"
    content: "The API gateway and payments services are up at DR. Internet banking fails its health check."
    expected_response: "The team diagnoses the failure and records it, and compares the elapsed time with the 30-minute threshold."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: sim_email
    title: "A partner reports failed calls"
    content: "A fintech partner reports failed API calls between 14:20 and 14:47."
    expected_response: "The partner is contacted as the plan says, and the outage window is recorded in the regulator-facing record."
  - sequence: 6
    release_offset_minutes: 150
    delivery_channel: sim_system
    title: "Utility supply stable"
    content: "Utility supply has been stable for 30 minutes."
    expected_response: "Fail-back starts, as the runbook sets out, and is timed."
  - sequence: 7
    release_offset_minutes: 210
    delivery_channel: in_room
    title: "Evidence capture"
    content: "Services are back at the primary site."
    expected_response: "For each service the actual RTO, RPO, failover time and fail-back time are recorded, with a finding for every step that did not work as written."
```

### 9.4 POWER-VANDAL-REGIONAL (F1, tabletop)

```yaml
code: POWER-VANDAL-REGIONAL
name: "Regional power loss after transmission-line vandalism"
category: power
ladder_min: tabletop
summary: "Vandalism of high-voltage transmission lines cuts grid supply to a whole region for more than a week. Repairs need a security escort to reach the towers."
narrative: "Overnight, transmission towers on the lines feeding the northern region are vandalised, and grid supply to four states is lost. The transmission operator says repairs need security escorts and will take at least 10 days. Every branch and office in the region runs on generator, and diesel prices rise locally. From day 2, telecom sites running on batteries start to fail. The exercise covers days 1 to 10."
objectives:
  - "Produce a 10-day fuel and cost plan for the region within the first day"
  - "Decide which branches are consolidated and which stay open, and record why"
  - "Confirm that staff can still be reached as telecom sites fail"
drivers: [cbn.rcf.bc_dr]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Grid supply lost to four states"
    content: "Reports say transmission lines feeding the northern region were vandalised overnight, and four states have no grid supply."
    expected_response: "A regional crisis lead is appointed, and the generator status of every site is collected."
  - sequence: 2
    release_offset_minutes: 25
    delivery_channel: sim_email
    title: "Repair will take at least 10 days"
    content: "The transmission operator says repairs need security escorts and will take at least 10 days."
    expected_response: "The team plans for the full 10 days, not for the best case."
  - sequence: 3
    release_offset_minutes: 55
    delivery_channel: sim_phone
    title: "Local diesel price up 60%"
    content: "The regional facilities manager reports that diesel prices in the region have risen 60%."
    expected_response: "The spend is approved or branches are consolidated, and the decision is recorded with its cost."
  - sequence: 4
    release_offset_minutes: 85
    delivery_channel: sim_sms
    title: "Day 3: telecom sites failing"
    content: "Staff in two towns report that they have no mobile signal."
    expected_response: "The team uses the other contact routes in the call tree, and records who cannot be reached."
  - sequence: 5
    release_offset_minutes: 115
    delivery_channel: in_room
    title: "Day 5: consolidate branches"
    content: "Fuel for all the branches cannot be sustained to day 10."
    expected_response: "Customers are directed to the consolidated branches, and cash logistics are adjusted."
  - sequence: 6
    release_offset_minutes: 150
    delivery_channel: in_room
    title: "Day 10: supply restored"
    content: "Grid supply returns to the region."
    expected_response: "Lessons on regional fuel reserves are recorded as findings, each with an owner."
```

### 9.5 FUEL-SCARCITY-MOBILITY (F2, tabletop)

```yaml
code: FUEL-SCARCITY-MOBILITY
name: "Petrol scarcity limits staff mobility"
category: fuel
ladder_min: tabletop
summary: "A petrol shortage empties filling stations across the major cities. Transport fares double, commuters are stranded, and for two weeks a large share of staff cannot reach their workplace."
narrative: "Petrol stations in Lagos, Abuja and Kano run dry over a weekend. By Monday the queues stretch for kilometres, commercial transport fares have doubled and many commuters are stranded at bus stops. Staff who drive cannot buy fuel. The shortage lasts about two weeks. The exercise covers the first Monday and the two weeks that follow, in compressed time."
objectives:
  - "Know by 09:00 on day 1 which critical-activity staff have reached their site"
  - "Run every prioritised activity at or above its minimum staffing"
  - "Decide on staff transport support, remote working or consolidating sites, with a named cost owner"
  - "Keep the staff welfare line open for anyone stranded"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_sms
    title: "Monday 07:00: can staff get to work?"
    content: "An EMNS check asks staff whether they can reach work. 45% of branch staff say they cannot."
    expected_response: "Branch managers report each branch's staffing against its minimum."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: in_room
    title: "Six branches below minimum"
    content: "Six branches are below their minimum staffing."
    expected_response: "The team decides which branches consolidate, and moves staff in from nearby areas."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: sim_phone
    title: "Payments operations short-staffed"
    content: "The head of payments operations says half her team cannot travel."
    expected_response: "Remote working is activated for the payments team, or a staff bus is arranged. The decision and its cost are logged."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: sim_email
    title: "Staff ask for a transport allowance"
    content: "Staff representatives ask for a transport allowance while the shortage lasts."
    expected_response: "HR and the crisis team decide on a position and communicate it."
  - sequence: 5
    release_offset_minutes: 105
    delivery_channel: sim_media
    title: "Day 5: fires at roadside fuel sellers"
    content: "Reports describe fires at illegal roadside fuel sellers."
    expected_response: "Staff are reminded not to store fuel at home or at branches."
  - sequence: 6
    release_offset_minutes: 135
    delivery_channel: in_room
    title: "Week 2: absence steady at 20%"
    content: "Absence has settled at 20%."
    expected_response: "The team reviews which temporary arrangements should continue."
  - sequence: 7
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "Supply returns"
    content: "Filling stations are supplied again."
    expected_response: "Findings on remote-access capacity and site staffing minimums are recorded."
```

### 9.6 FUEL-TANKER-STRIKE (F2, tabletop)

```yaml
code: FUEL-TANKER-STRIKE
name: "Fuel tanker drivers' strike halts distribution"
category: fuel
ladder_min: tabletop
summary: "A strike by tanker drivers stops the distribution of petrol and diesel nationwide for a week. The generators, the staff transport and the cash-in-transit fleet all depend on that same supply."
narrative: "On Wednesday the tanker drivers' union starts an indefinite strike. The depots stop loading, and within two days the filling stations have sold out. The bank's diesel reserve, its staff buses and its cash-in-transit provider's fleet all draw on the same supply. Talks between the union and the government are expected to take about a week. The exercise covers days 1 to 7."
objectives:
  - "Produce a single list of fuel dependencies within 1 day, covering generators, staff transport and cash logistics"
  - "Allocate the fuel available by activity priority, with a named decision-maker"
  - "Confirm the cash-in-transit provider's own plan and what it means for branch cash"
drivers: [iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Strike announced"
    content: "News reports say tanker drivers begin an indefinite strike today and the depots have stopped loading."
    expected_response: "The crisis team convenes and asks facilities and the CIT provider for their fuel positions."
  - sequence: 2
    release_offset_minutes: 25
    delivery_channel: sim_email
    title: "CIT provider warns of cuts"
    content: "The cash-in-transit provider says that from Friday it can run only 60% of scheduled deliveries."
    expected_response: "Branch cash needs are prioritised, and the reduced schedule is agreed with the provider."
  - sequence: 3
    release_offset_minutes: 55
    delivery_channel: in_room
    title: "4,000 litres and three claims on it"
    content: "You hold 4,000 litres of diesel. The generators need 3,000 for 3 days, and the staff buses need 1,500."
    expected_response: "The fuel is allocated by activity priority, and the allocation is logged."
  - sequence: 4
    release_offset_minutes: 85
    delivery_channel: sim_phone
    title: "A branch generator runs dry"
    content: "A branch reports that its generator stopped at 13:00. Its tank was not refilled before the strike."
    expected_response: "The branch runs its power-failure procedure, and the missed stock check is recorded as a finding."
  - sequence: 5
    release_offset_minutes: 115
    delivery_channel: sim_media
    title: "Day 5: talks break down"
    content: "Talks between the union and the government have broken down."
    expected_response: "The team extends the plan to 14 days and reviews what has to change."
  - sequence: 6
    release_offset_minutes: 150
    delivery_channel: in_room
    title: "Day 7: strike ends"
    content: "The strike is called off and the depots resume loading."
    expected_response: "The return to normal deliveries is sequenced, and the fuel-reserve policy is reviewed."
```

### 9.7 FUEL-CIT-FLEET (F2, walkthrough)

```yaml
code: FUEL-CIT-FLEET
name: "Cash-in-transit fleet cannot refuel"
category: fuel
ladder_min: walkthrough
summary: "During a fuel shortage the cash-in-transit provider cannot refuel all of its vehicles. Branch cash replenishment has to be planned again around a smaller fleet."
narrative: "During a fuel shortage, the CIT provider tells you on Monday morning that it has fuel for half its fleet. Salary week starts on Thursday, when cash demand at the branches rises. The walkthrough follows the cash-management plan step by step, to test whether it works with half the vehicles."
objectives:
  - "Confirm that each step of the cash-management plan can be carried out with half the CIT fleet"
  - "Produce a revised replenishment schedule for salary week"
  - "Record what evidence the provider gives of its own continuity arrangement"
drivers: [iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Provider has fuel for half its fleet"
    content: "The CIT provider's operations manager says it has fuel for half its vehicles this week."
    expected_response: "Cash operations opens the cash-management plan at the supplier-disruption step."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: in_room
    title: "Salary-week demand forecast"
    content: "Branch cash demand on Thursday and Friday is forecast at 1.8 times a normal day."
    expected_response: "Branches are ranked by demand, and the replenishment routes are consolidated."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: sim_email
    title: "Provider proposes skipping 12 branches"
    content: "The provider proposes skipping 12 branches this week."
    expected_response: "The team decides which branches move to ATM-only service or reduced cash limits, and how customers are told."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: in_room
    title: "The plan names a contact who has left"
    content: "The plan tells you to call a contact at the provider who left last year."
    expected_response: "The out-of-date contact is recorded as a finding and corrected."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: in_room
    title: "Walkthrough close"
    content: "Review each step walked."
    expected_response: "Every step that could not be followed as written is recorded with an owner."
```

### 9.8 FUEL-REMOTE-SWITCH (F2, walkthrough)

```yaml
code: FUEL-REMOTE-SWITCH
name: "Moving critical teams to remote and nearest-site working"
category: fuel
ladder_min: walkthrough
summary: "A fuel shortage that has gone on for weeks makes commuting to head office unreliable. In one morning, critical teams switch to remote working or to the site nearest their homes."
narrative: "The shortage is in its second week. The crisis team decides that from tomorrow critical head office teams will work remotely, or from the branch nearest their homes. The walkthrough tests whether the people who would use the documented alternate-working procedure can follow it, covering access, equipment, approvals, supervision and security."
objectives:
  - "Every member of a critical team knows their working location by 17:00 on the day of the decision"
  - "Remote access and equipment are confirmed for every critical role"
  - "Segregation of duties and approval limits still hold at the new locations"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "The decision is taken"
    content: "The crisis team has decided that critical teams work remotely or from the nearest site from tomorrow."
    expected_response: "The alternate-working procedure is opened, and each team lead is given their step."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_email
    title: "A treasury dealer has no connection at home"
    content: "A treasury dealer reports that she has no reliable connection at home."
    expected_response: "The dealer is given a seat with secure access at the nearest site, and the gap is recorded."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: sim_system
    title: "40 remote-access licences short"
    content: "The licence count shows 40 fewer remote-access licences than the plan assumes."
    expected_response: "IT confirms whether licences can be added the same day, and who approves them."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: in_room
    title: "Payment approvers at two different branches"
    content: "The two approvers for high-value payments will be working from different branches."
    expected_response: "The team confirms that dual authorisation works remotely without anyone sharing credentials."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: in_room
    title: "Walkthrough close"
    content: "Review each step walked."
    expected_response: "Every step that could not be carried out as written is recorded as a finding."
```

### 9.9 UNREST-BRANCH-CLUSTER (F3, tabletop)

```yaml
code: UNREST-BRANCH-CLUSTER
name: "Civil unrest near a branch cluster"
category: civil_unrest
ladder_min: tabletop
summary: "Unrest breaks out in a commercial district with five branches inside two kilometres. The situation changes by the hour. Staff and customers are inside, and there is cash on site."
narrative: "At 10:30 a demonstration in a commercial district turns violent. Five branches are within two kilometres of it. Security contacts report shops being looted three streets from the nearest branch, and the roads out of the district are congested. Staff in the branches are asking whether to close, and several of them live on the far side of the unrest. The exercise covers 10:30 to the end of the day."
objectives:
  - "Decide within 20 minutes whether each of the five branches closes, shelters or stays open"
  - "Account for every member of staff in the district within 60 minutes"
  - "Secure cash and premises without putting staff at risk"
  - "Send staff home by safe routes or keep them sheltered, and record each decision"
drivers: [iso22361.crisis_management]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Violence reported near B2"
    content: "10:30. The regional security officer reports violence and looting three streets from branch B2."
    expected_response: "The crisis lead convenes the regional team and gets a status report from all five branches."
  - sequence: 2
    release_offset_minutes: 10
    delivery_channel: sim_media
    title: "Crowd heading towards the market"
    content: "A video posted online shows a crowd moving towards the market, where B3 and B4 are."
    expected_response: "The team decides which branches shelter in place and which close now."
  - sequence: 3
    release_offset_minutes: 25
    delivery_channel: sim_sms
    title: "UNREST alert and roll-call sent"
    content: "The UNREST alert and a roll-call have gone to staff in the district."
    expected_response: "Responses are tracked, and managers follow up anyone who has not answered."
  - sequence: 4
    release_offset_minutes: 45
    delivery_channel: sim_phone
    title: "B4 has customers inside and the doors locked"
    content: "The B4 manager has locked the doors with 20 customers inside. Some of them want to leave."
    expected_response: "The branch keeps customers sheltered until it is safe to leave, respects anyone who chooses to go, and logs the time of the decision."
  - sequence: 5
    release_offset_minutes: 75
    delivery_channel: sim_phone
    title: "Cash above limit at B2"
    content: "B2 holds cash above its limit, and the CIT provider offers to collect it."
    expected_response: "The cash stays secured in the vault. No cash moves while the unrest continues."
  - sequence: 6
    release_offset_minutes: 105
    delivery_channel: in_room
    title: "Roads reopen to the east only"
    content: "Security reports that the roads to the east are clear. The roads west are not."
    expected_response: "Staff who live east are released in groups. Everyone else stays sheltered or is escorted."
  - sequence: 7
    release_offset_minutes: 140
    delivery_channel: sim_email
    title: "Evening: one person still unaccounted for"
    content: "A cashier from B5 has not answered since 11:00."
    expected_response: "HR continues the welfare escalation through the next-of-kin procedure."
  - sequence: 8
    release_offset_minutes: 165
    delivery_channel: in_room
    title: "Tomorrow"
    content: "The district is calm tonight."
    expected_response: "The team sets the criteria for reopening each branch tomorrow, including a security assessment."
```

### 9.10 PROTEST-ANNOUNCED-DAYS (F3, tabletop)

```yaml
code: PROTEST-ANNOUNCED-DAYS
name: "Nationwide protest days announced in advance"
category: civil_unrest
ladder_min: tabletop
summary: "Organisers announce nationwide protests on set dates two weeks away. Once the protests begin, some states may impose curfews at short notice. The bank has time to prepare, and the preparation is what this scenario tests."
narrative: "Organisers announce ten days of nationwide protests starting in two weeks, and the security agencies warn of possible violence. The exercise opens at the announcement and asks the team to plan: which branches close or change their hours, how staff travel, how cash is handled, and what triggers a change of plan. It then moves to day 2 of the protests, when several state governments impose 24-hour curfews at short notice."
objectives:
  - "Produce a protest plan for each site at least 7 days before the first protest day"
  - "Define the triggers that move a site from open to reduced hours to closed"
  - "Approve the EMNS messages that will be used before they are needed"
  - "Carry out the curfew response in the affected states within 2 hours of the announcement"
drivers: [iso22361.crisis_management]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Protest dates announced"
    content: "Organisers announce ten days of nationwide protests starting in two weeks."
    expected_response: "The crisis team sets a planning timetable and names the owners."
  - sequence: 2
    release_offset_minutes: 25
    delivery_channel: in_room
    title: "Branches on the likely routes"
    content: "Twenty-two branches are on or near the likely protest routes."
    expected_response: "Each branch is given a planned posture and the trigger that changes it."
  - sequence: 3
    release_offset_minutes: 55
    delivery_channel: sim_email
    title: "Cash and ATM plan"
    content: "The CIT provider asks for your cash plan for the protest period."
    expected_response: "ATM loading, vault limits and CIT runs for the period are agreed with the provider."
  - sequence: 4
    release_offset_minutes: 80
    delivery_channel: in_room
    title: "Approve the messages in advance"
    content: "Which messages will you need, and are they approved?"
    expected_response: "The PROTEST and UNREST templates are reviewed and approved in English and Pidgin before the first protest day."
  - sequence: 5
    release_offset_minutes: 110
    delivery_channel: sim_media
    title: "Day 2: 24-hour curfews in three states"
    content: "Three state governments announce 24-hour curfews, taking effect this afternoon."
    expected_response: "Branches in those states close, staff are told through EMNS, and the roll-call runs."
  - sequence: 6
    release_offset_minutes: 140
    delivery_channel: sim_phone
    title: "Staff member detained at a checkpoint"
    content: "A member of staff travelling on bank business has been detained at a checkpoint during the curfew."
    expected_response: "Legal and HR support is activated, and the process for authorising travel is reviewed."
  - sequence: 7
    release_offset_minutes: 165
    delivery_channel: in_room
    title: "After action"
    content: "The protest period has ended."
    expected_response: "Findings on the posture triggers are recorded, each with an owner."
```

### 9.11 UNREST-CASH-CROWDS (F3, tabletop)

```yaml
code: UNREST-CASH-CROWDS
name: "Crowds and ATM vandalism during a cash shortage"
category: civil_unrest
ladder_min: tabletop
summary: "A national shortage of banknotes brings angry crowds to the branches. ATMs are damaged and staff are threatened. Service has to continue where it is safe and stop where it is not."
narrative: "A shortage of banknotes has been building for three weeks. In several cities customers wait outside the branches from dawn. On Monday, crowds in two cities damage ATMs and throw stones at a branch, and staff report threats from customers who were turned away. The exercise covers Monday and the rest of the week."
objectives:
  - "Protect staff first: decide within 15 minutes whether a threatened branch closes"
  - "Keep a status of cash and security for every branch"
  - "Keep customer communication consistent and approved"
  - "Record damage and incidents for insurance and for the regulatory record"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Crowd outside B7 since 05:00"
    content: "The B7 manager reports a crowd outside since 05:00, growing angrier."
    expected_response: "The security posture at B7 is raised, and the branch manager is given clear authority to close."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_media
    title: "An ATM smashed nearby"
    content: "A video shows an ATM being smashed at another bank's branch nearby."
    expected_response: "The team decides whether to take nearby ATMs out of service."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: sim_phone
    title: "Stones thrown at B9"
    content: "Stones have been thrown at B9 and a window is broken."
    expected_response: "B9 closes, staff shelter away from windows, and the UNREST alert goes to nearby staff."
  - sequence: 4
    release_offset_minutes: 65
    delivery_channel: sim_email
    title: "Cash allocation for the week"
    content: "Treasury has a limited allocation of notes for the week."
    expected_response: "Cash is allocated by branch security as well as by demand."
  - sequence: 5
    release_offset_minutes: 95
    delivery_channel: sim_media
    title: "Rumour that the bank is hoarding cash"
    content: "Posts claim the bank is hoarding cash."
    expected_response: "An approved statement is issued, and staff are told not to comment."
  - sequence: 6
    release_offset_minutes: 130
    delivery_channel: in_room
    title: "Tellers under strain"
    content: "Tellers at B7 have been verbally abused for three days running."
    expected_response: "Staff rotation and welfare support are arranged and recorded."
  - sequence: 7
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "End of the week"
    content: "Note supply is improving."
    expected_response: "The damage and incident records are complete, and findings are raised."
```

### 9.12 UNREST-LOCKDOWN-DRILL (F3, drill)

```yaml
code: UNREST-LOCKDOWN-DRILL
name: "Branch lockdown and shelter drill"
category: civil_unrest
ladder_min: drill
summary: "A crowd approaches a branch during banking hours. Staff lock the branch down, move staff and customers away from the glass frontage and account for everyone."
narrative: "This is a drill at one branch during banking hours. The facilitator announces that a hostile crowd is coming along the main road. Staff must secure the doors, move everyone away from the glass frontage to the agreed safe room, secure cash, report to the regional office, and account for all staff and customers on the premises. A remote roll-call runs at the same time."
objectives:
  - "Doors secured and everyone in the safe room within 5 minutes"
  - "Headcount of staff and customers reconciled within 10 minutes"
  - "Regional office informed within 5 minutes"
  - "Every member of staff answers the remote roll-call within 15 minutes"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Crowd approaching"
    content: "The security guard reports a hostile crowd coming along the main road."
    expected_response: "The branch manager calls a lockdown, and security secures the doors."
  - sequence: 2
    release_offset_minutes: 3
    delivery_channel: in_room
    title: "A customer wants to leave"
    content: "A customer insists on leaving."
    expected_response: "Staff explain the risk and record the customer's choice. Nobody is forced to stay or to go."
  - sequence: 3
    release_offset_minutes: 8
    delivery_channel: sim_sms
    title: "Roll-call alert received"
    content: "The roll-call arrives on staff phones."
    expected_response: "Every member of staff answers from the safe room."
  - sequence: 4
    release_offset_minutes: 12
    delivery_channel: in_room
    title: "Headcount one short"
    content: "The headcount is one short. A cleaner was working in the back yard."
    expected_response: "The missing person is found without anyone leaving the safe room unsafely."
  - sequence: 5
    release_offset_minutes: 25
    delivery_channel: in_room
    title: "All clear"
    content: "The facilitator declares the all clear."
    expected_response: "The branch stands down, and the timings are recorded for the drill report."
```

### 9.13 CABLE-SUBSEA-MULTI (F4, tabletop)

```yaml
code: CABLE-SUBSEA-MULTI
name: "Multiple submarine cable cuts degrade international connectivity"
category: connectivity
ladder_min: tabletop
summary: "Several submarine cables serving West Africa are cut at the same time, and international bandwidth falls sharply for weeks. Card schemes, international payments and services hosted offshore all degrade."
narrative: "At 10:00 on a Thursday several submarine cables serving West Africa fail at once, off the coast of a neighbouring country. Local carriers move traffic onto the remaining routes, which saturate. International card authorisations slow down, some services hosted offshore time out, and staff email hosted abroad is intermittent. The cable operators say repair ships have to be mobilised and the work may take weeks. Domestic transfers are largely unaffected. The exercise covers day 1 and the two weeks that follow."
objectives:
  - "Within 2 hours, list every service that depends on international connectivity"
  - "Prioritise the surviving capacity by service criticality, with the carriers' agreement"
  - "Keep domestic payments and branch service running at normal levels"
  - "Tell customers honestly about delays to international card payments and transfers"
drivers: [cbn.rcf.bc_dr, iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "International latency spikes"
    content: "10:00. Monitoring shows international latency above 900 ms, with packet loss on the offshore links."
    expected_response: "The network team raises an incident and confirms the problem with the carriers."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_media
    title: "Several cables reported cut"
    content: "News reports say several submarine cables off West Africa have been cut."
    expected_response: "The crisis team plans on the assumption of an outage lasting several weeks."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: sim_email
    title: "Card authorisations failing"
    content: "30% of international card authorisations are failing."
    expected_response: "The card team applies the degraded-service procedure and informs the scheme."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: sim_phone
    title: "Priority capacity for a price"
    content: "A carrier offers priority capacity on a surviving route, at a cost."
    expected_response: "The team decides which services get the priority capacity, and who approves the cost."
  - sequence: 5
    release_offset_minutes: 105
    delivery_channel: sim_email
    title: "Offshore-hosted email intermittent"
    content: "Staff email, which is hosted offshore, is only working intermittently."
    expected_response: "Staff communication moves to the local fallback channel named in the plan."
  - sequence: 6
    release_offset_minutes: 135
    delivery_channel: sim_media
    title: "Repair ship due in 10 days"
    content: "The cable operators say a repair ship is expected in 10 days."
    expected_response: "The plan is extended, and the customer messages are updated."
  - sequence: 7
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "Week 2 review"
    content: "Capacity is partly restored."
    expected_response: "Dependencies on offshore hosting are recorded as findings, each with an owner."
```

### 9.14 CLOUD-UNREACHABLE (F4, functional)

```yaml
code: CLOUD-UNREACHABLE
name: "Offshore-hosted services unreachable"
category: connectivity
ladder_min: functional
summary: "International links fail, and the services hosted outside Nigeria become unreachable: email, collaboration tools and a SaaS application that a prioritised activity relies on. The team has to run the local fallback for real."
narrative: "In a controlled window, the exercise simulates a loss of international connectivity by blocking routes to offshore-hosted services for the test environment and a pilot group of users. Email, the collaboration suite and one SaaS application that supports a prioritised activity become unreachable. The team switches the pilot group to the documented local fallbacks, runs the prioritised activity at its minimum level, and then restores normal service."
objectives:
  - "Switch the pilot group to local fallback communication within 30 minutes"
  - "Run the prioritised activity at its minimum level without the SaaS application for 2 hours"
  - "Confirm that the fallback holds no data that should not be held locally"
  - "Restore normal service, and reconcile any work done in fallback"
drivers: [cbn.rcf.bc_dr]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Routes to offshore services blocked"
    content: "The pilot group reports that email and the collaboration suite do not load."
    expected_response: "The service desk recognises the pattern and escalates it."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: in_room
    title: "Invoke the local fallback"
    content: "The incident lead asks for the local fallback to be invoked."
    expected_response: "The fallback channel is activated, and the pilot group is told how to use it."
  - sequence: 3
    release_offset_minutes: 50
    delivery_channel: sim_system
    title: "The SaaS application is unreachable"
    content: "The SaaS application behind a prioritised activity stops responding."
    expected_response: "The prioritised activity switches to its documented manual or local procedure."
  - sequence: 4
    release_offset_minutes: 90
    delivery_channel: sim_phone
    title: "A customer needs a document held only in the SaaS system"
    content: "A customer needs a document that is held only in the SaaS system."
    expected_response: "The team follows the procedure for records that are unavailable, and logs the impact on the customer."
  - sequence: 5
    release_offset_minutes: 150
    delivery_channel: sim_system
    title: "Routes restored"
    content: "Routes to the offshore services are restored."
    expected_response: "Normal service is restored, and the work done in fallback is reconciled."
  - sequence: 6
    release_offset_minutes: 200
    delivery_channel: in_room
    title: "Evidence capture"
    content: "Normal service has been running for 30 minutes."
    expected_response: "The switch-over and restoration times are recorded against the objectives."
```

### 9.15 FIBRE-VANDAL-METRO (F4, walkthrough)

```yaml
code: FIBRE-VANDAL-METRO
name: "Metro fibre vandalised and city branches offline"
category: connectivity
ladder_min: walkthrough
summary: "Vandals cut the carrier's metro fibre ring in a city, and twelve branches lose their primary link. Some of them have an LTE backup and some do not."
narrative: "Overnight, fibre on the carrier's metro ring is cut and cable is stolen at two points. At 07:30 twelve branches cannot connect. Seven have an LTE backup router and five do not. The carrier says its repair crews need a police escort to reach the sites. The walkthrough follows the procedure for a branch losing connectivity."
objectives:
  - "Confirm within 30 minutes which branches have a working backup link"
  - "Walk the manual-service procedure for the branches with no backup"
  - "Record the gap in backup-link coverage as a finding with an owner"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Twelve branches offline at 07:30"
    content: "Monitoring shows twelve branches with no connection."
    expected_response: "IT raises an incident and checks the status of the backup routers."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_system
    title: "Two LTE routers will not connect"
    content: "Two of the seven LTE routers have expired data plans."
    expected_response: "The expired plans are recorded as a finding, and renewal is started."
  - sequence: 3
    release_offset_minutes: 30
    delivery_channel: in_room
    title: "Five branches with no backup at all"
    content: "Five branches have no backup link."
    expected_response: "Each branch manager is walked through opening on manual service, or sending customers to the nearest connected branch."
  - sequence: 4
    release_offset_minutes: 55
    delivery_channel: sim_phone
    title: "Carrier waiting for a police escort"
    content: "The carrier says repairs are delayed until the afternoon while it waits for a police escort."
    expected_response: "The team confirms the plan for the afternoon and the messages to customers."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: in_room
    title: "Walkthrough close"
    content: "Review each step walked."
    expected_response: "Every procedure step that could not be followed as written is recorded."
```

### 9.16 CONNECT-FAILOVER-OB (F4, functional)

```yaml
code: CONNECT-FAILOVER-OB
name: "Open banking API failover after upstream link loss"
category: connectivity
ladder_min: functional
summary: "The primary site's upstream connectivity fails. Open banking API services have to fail over to the secondary site within the 30-minute threshold, and fail back afterwards."
narrative: "This scenario is built to be run as the quarterly failover exercise. In a controlled window, the upstream links at the primary site are taken down, and partners calling the open banking APIs have to be served from the secondary site. The exercise measures the failover time, the fail-back time and what partners experience. It produces the evidence record for the quarter."
objectives:
  - "Fail the open banking API services over to the secondary site within 30 minutes of the trigger"
  - "Confirm that partners can complete test calls against the secondary site"
  - "Fail back within 30 minutes once the primary is restored"
  - "Record the trigger, failover and fail-back times as the quarter's evidence"
drivers: [cbn.open_banking.failover, cbn.open_banking.threshold]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Upstream links down at the primary site"
    content: "Both upstream links at the primary site are down."
    expected_response: "The trigger time is recorded, and failover starts as the runbook directs."
  - sequence: 2
    release_offset_minutes: 10
    delivery_channel: sim_system
    title: "DNS change not propagating"
    content: "Two partners are still resolving to the primary site."
    expected_response: "The team applies the documented DNS or routing fallback, and records the delay."
  - sequence: 3
    release_offset_minutes: 30
    delivery_channel: sim_system
    title: "Threshold checkpoint"
    content: "30 minutes have passed since the trigger."
    expected_response: "The team records whether every in-scope API is being served from the secondary site."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: sim_email
    title: "Partner test calls"
    content: "The named partners are asked to run their test calls."
    expected_response: "The partners confirm their test calls succeeded, and the confirmations are kept as evidence."
  - sequence: 5
    release_offset_minutes: 120
    delivery_channel: sim_system
    title: "Primary links restored"
    content: "The upstream links at the primary site are back and stable."
    expected_response: "Fail-back starts and is timed."
  - sequence: 6
    release_offset_minutes: 170
    delivery_channel: in_room
    title: "Evidence record"
    content: "All services are back at the primary site."
    expected_response: "The failover record is completed, with the times, the issues found and the findings raised."
```

### 9.17 RANSOMWARE-CORE (F5, functional)

```yaml
code: RANSOMWARE-CORE
name: "Ransomware on core banking during end-of-day processing"
category: cyber
ladder_min: functional
summary: "Ransomware encrypts the core banking database servers during end-of-day processing. The team has to isolate the environment, choose a recovery point, and restore core banking from a clean backup at the DR site."
narrative: "At 21:40, during end-of-day processing, the core banking batch fails. Within minutes the database servers show encrypted files and a ransom note, and the DR replica has already received the encrypted changes. The most recent immutable backup was taken at 18:00. The exercise runs in an isolated recovery environment. The team must contain the attack, rebuild from known-clean images, restore from the immutable backup, re-run the day's processing, and prove the restored core is clean before it is reconnected."
objectives:
  - "Isolate the core banking environment within 30 minutes of detection"
  - "Restore core banking from the immutable backup into a clean environment, timed against the RTO"
  - "Re-create or re-post the transactions made between the backup and the attack, and reconcile them"
  - "Submit the CBN incident report within 24 hours and prepare the NigFinCERT notification"
  - "Decide on customer communication before the branches open"
drivers: [cbn.rcf.incident_response, cbn.rcf.cyber_drills, cbn.rcf.bc_dr]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "End-of-day batch fails"
    content: "21:40. The core banking end-of-day batch fails with I/O errors."
    expected_response: "Operations escalates to the security team instead of re-running the batch."
  - sequence: 2
    release_offset_minutes: 10
    delivery_channel: sim_system
    title: "Ransom note on the database servers"
    content: "Encrypted files and a ransom note are found on the core database servers."
    expected_response: "The cyber incident plan is invoked, and the core environment is isolated from the network."
  - sequence: 3
    release_offset_minutes: 25
    delivery_channel: sim_system
    title: "The DR replica is encrypted too"
    content: "The DR replica has received the encrypted changes."
    expected_response: "The team does not fail over to the replica, and moves to recovery from backup."
  - sequence: 4
    release_offset_minutes: 45
    delivery_channel: in_room
    title: "Choosing the recovery point"
    content: "The 18:00 immutable backup is verified clean. Every transaction after 18:00 will have to be re-created."
    expected_response: "The recovery point is chosen, and the owner of the reconciliation is named."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: sim_system
    title: "Restore in progress"
    content: "The restore into the clean environment is 40% complete."
    expected_response: "The restore is timed, and nothing is reconnected until a clean scan passes."
  - sequence: 6
    release_offset_minutes: 140
    delivery_channel: sim_email
    title: "Ransom demand with a deadline"
    content: "An email demands payment within 48 hours and threatens to publish data."
    expected_response: "The demand is handled through the decision process that policy and legal advice set out, and the decision is logged."
  - sequence: 7
    release_offset_minutes: 180
    delivery_channel: in_room
    title: "Branches open in 9 hours"
    content: "Branches open at 08:00."
    expected_response: "The team decides what service the branches and digital channels offer at opening, and approves the customer messages."
  - sequence: 8
    release_offset_minutes: 220
    delivery_channel: in_room
    title: "Regulatory reporting"
    content: "What must the CBN be told, and by when?"
    expected_response: "The CBN incident report is drafted and approved within 24 hours, with the clock started at the earliest known time the incident occurred and that start time recorded."
```

### 9.18 CYBER-DDOS (F5, functional)

```yaml
code: CYBER-DDOS
name: "Denial-of-service attack on digital channels in salary week"
category: cyber
ladder_min: functional
summary: "A sustained denial-of-service attack takes internet and mobile banking offline during salary week. Upstream mitigation has to be invoked, and customers served through the other channels."
narrative: "On the 26th of the month, internet and mobile banking slow down and then fail. Traffic analysis shows a volumetric attack on the public endpoints, and an extortion message arrives an hour later. The exercise tests invoking upstream mitigation with the provider, keeping branches, ATMs and USSD serving customers, and communicating while the attack goes on."
objectives:
  - "Invoke the DDoS mitigation service within 30 minutes of detection"
  - "Restore the digital channels to service, timed against the RTO"
  - "Keep customers informed through channels that are not under attack"
  - "Report the incident to the CBN within 24 hours"
drivers: [cbn.rcf.incident_response, cbn.rcf.cyber_drills]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Digital channels failing"
    content: "Internet and mobile banking response times climb and then fail."
    expected_response: "The security operations team identifies the attack pattern and raises an incident."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_phone
    title: "Mitigation provider needs authorisation"
    content: "The mitigation provider asks for authorisation to reroute traffic."
    expected_response: "The named authority approves the change, and the time is logged."
  - sequence: 3
    release_offset_minutes: 60
    delivery_channel: sim_email
    title: "Extortion email"
    content: "An email demands payment to stop the attack."
    expected_response: "The email is preserved as evidence, and the extortion policy is followed."
  - sequence: 4
    release_offset_minutes: 100
    delivery_channel: sim_media
    title: "Customers cannot reach their salaries"
    content: "Customers complain online that they cannot get to their salaries."
    expected_response: "Approved messages direct customers to branches, ATMs and USSD."
  - sequence: 5
    release_offset_minutes: 150
    delivery_channel: sim_system
    title: "The attack switches target"
    content: "The attack traffic moves to the API gateway."
    expected_response: "Mitigation is extended to cover the new target."
  - sequence: 6
    release_offset_minutes: 210
    delivery_channel: in_room
    title: "Service restored"
    content: "The attack has subsided and the channels are back."
    expected_response: "The times are recorded, and the CBN report is finalised."
```

### 9.19 CYBER-EXFIL-EXTORTION (F5, tabletop)

```yaml
code: CYBER-EXFIL-EXTORTION
name: "Customer data exfiltration and extortion"
category: cyber
ladder_min: tabletop
summary: "An attacker claims to hold customer records and posts a sample. The bank has to confirm the breach, notify the CBN and the NDPC, each on its own clock, and decide how to tell the customers affected."
narrative: "At 09:15 a message reaches the corporate email address. It claims that 200,000 customer records have been stolen and attaches a sample of 50 records with names, phone numbers and partial account numbers. It demands payment within 72 hours or the data will be published. No system is down. The exercise covers the first 72 hours."
objectives:
  - "Confirm or rule out the breach from evidence, not from the attacker's claim"
  - "Record the moment of awareness and start the NDPC 72-hour clock from it"
  - "Report to the CBN within 24 hours, counted from the earliest known time the incident occurred"
  - "Decide how to communicate with affected customers where the risk to them is high"
  - "Preserve evidence for the investigation"
drivers: [ndpa.breach_notification, cbn.rcf.incident_response]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_email
    title: "Extortion email with a sample"
    content: "09:15. An email claims 200,000 customer records were stolen, attaches a sample of 50 and demands payment within 72 hours."
    expected_response: "The email is preserved, and the cyber incident plan is invoked."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: in_room
    title: "The sample is genuine"
    content: "Checks confirm that the 50 sample records match real customers."
    expected_response: "The DPO records awareness of a personal data breach, and the time."
  - sequence: 3
    release_offset_minutes: 50
    delivery_channel: sim_system
    title: "Source identified"
    content: "The logs show a large export from a reporting server through a contractor's account."
    expected_response: "The account is disabled, and the contractor's firm is engaged under its contract."
  - sequence: 4
    release_offset_minutes: 80
    delivery_channel: in_room
    title: "Two regulators, two clocks"
    content: "Who must be told, and by when?"
    expected_response: "The CBN report and the NDPC notification are drafted, each against its own deadline."
  - sequence: 5
    release_offset_minutes: 110
    delivery_channel: sim_media
    title: "A journalist has the sample"
    content: "A journalist says she has seen the sample and asks for comment."
    expected_response: "The holding statement is issued, and nothing unconfirmed is said."
  - sequence: 6
    release_offset_minutes: 140
    delivery_channel: in_room
    title: "Telling customers"
    content: "The investigation suggests about 40,000 customers are affected."
    expected_response: "The team decides which customers are told, what they are told and how, in plain language."
  - sequence: 7
    release_offset_minutes: 165
    delivery_channel: in_room
    title: "72 hours"
    content: "72 hours have passed since awareness."
    expected_response: "The team confirms that both regulator submissions were made, and holds the evidence of them."
```

### 9.20 CYBER-MSP-COMPROMISE (F5, tabletop)

```yaml
code: CYBER-MSP-COMPROMISE
name: "Compromise through a managed service provider"
category: cyber
ladder_min: tabletop
summary: "An IT managed service provider's remote-access tool is compromised, and the attacker uses it to reach several of the provider's bank customers. The bank has to cut the provider's access without losing the services it runs."
narrative: "The managed service provider that runs your server patching and monitoring tells you its remote-management tool has been compromised. It believes attackers used the tool against several customers over the past 48 hours, but cannot yet say which ones. The provider's staff hold administrator access to 60 of your servers. The exercise covers the first 48 hours."
objectives:
  - "Remove or restrict the provider's access within 1 hour, with a named decision-maker"
  - "Identify the services the provider runs, and keep them running without its access"
  - "Hunt for signs of compromise on every system the provider could reach"
  - "Use the contract's notification, cooperation and audit rights"
drivers: [iso22318.supply_chain, cbn.rcf.incident_response]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Provider reports a compromise"
    content: "The provider's security lead reports that its remote-management tool has been compromised."
    expected_response: "The cyber incident plan is invoked, and the provider's access is assessed."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: in_room
    title: "Cutting access stops patching and monitoring"
    content: "If the provider's access is cut, patching and monitoring stop."
    expected_response: "The team decides to cut the access, and names who covers monitoring."
  - sequence: 3
    release_offset_minutes: 50
    delivery_channel: sim_system
    title: "Suspicious logins through the provider's tool"
    content: "Three servers show logins through the provider's tool at 03:00 yesterday."
    expected_response: "The affected servers are isolated and investigated."
  - sequence: 4
    release_offset_minutes: 85
    delivery_channel: sim_email
    title: "Provider refuses to share its forensic report"
    content: "The provider will not share its forensic report."
    expected_response: "The audit and cooperation clauses of the contract are invoked."
  - sequence: 5
    release_offset_minutes: 120
    delivery_channel: in_room
    title: "Regulatory report"
    content: "What must the CBN be told, and by when?"
    expected_response: "The CBN incident report is drafted within 24 hours."
  - sequence: 6
    release_offset_minutes: 155
    delivery_channel: in_room
    title: "Leave the provider or keep it"
    content: "Could you leave this provider next month if you had to?"
    expected_response: "The team records, as a finding, whether the exit plan for this provider was usable."
```

### 9.21 FLOOD-BAYELSA (F6, tabletop)

```yaml
code: FLOOD-BAYELSA
name: "Riverine flooding in Bayelsa"
category: flood
ladder_min: tabletop
summary: "River flooding submerges the communities around Yenagoa for several weeks. Branches are cut off, roads close, and many staff have to leave their homes."
narrative: "After weeks of heavy rain upstream, the rivers rise across Bayelsa and the communities around Yenagoa go under water. The main road into the state is cut in several places. Two of the bank's three Yenagoa branches have water inside. The third is dry but hard to reach. About a third of local staff have had to leave their homes, and power and telecom service come and go. The flooding is expected to last several weeks. The exercise covers weeks 1 to 4."
objectives:
  - "Account for every member of staff in the affected area within 24 hours, and record their welfare needs"
  - "Keep at least one service point open for customers in the state, or provide an alternative"
  - "Keep cash, records and equipment in flooded branches safe, or recover them"
  - "Plan the return to the flooded premises, including a safety check"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Flood warning for Bayelsa"
    content: "The authorities warn that river levels will keep rising over the coming days."
    expected_response: "The regional team reviews the flood plan, and records and equipment are moved above flood level."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_phone
    title: "Water inside branch Y1"
    content: "The Y1 manager reports water coming into the banking hall."
    expected_response: "Y1 closes, cash is secured or moved under escort, and staff leave by safe routes."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: sim_sms
    title: "Roll-call: 30% have left home"
    content: "The roll-call shows that 30% of local staff have had to leave their homes."
    expected_response: "Welfare needs are recorded, and HR support is arranged."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: sim_email
    title: "The road into the state is cut"
    content: "CIT deliveries cannot reach Yenagoa by road."
    expected_response: "Alternatives for cash logistics and customer service are agreed."
  - sequence: 5
    release_offset_minutes: 105
    delivery_channel: in_room
    title: "Week 2: risk of disease"
    content: "Health authorities warn of waterborne disease in the camps."
    expected_response: "Health guidance and support are issued to staff."
  - sequence: 6
    release_offset_minutes: 135
    delivery_channel: sim_media
    title: "Week 3: water going down slowly"
    content: "River levels are falling slowly."
    expected_response: "The team plans building inspections and a phased reopening."
  - sequence: 7
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "Week 4: return"
    content: "Y1 and Y2 are dry."
    expected_response: "The return criteria, the clean-up and the salvage of records are agreed and recorded."
```

### 9.22 FLOOD-LAGOS-FLASH (F6, drill)

```yaml
code: FLOOD-LAGOS-FLASH
name: "Flash flood during business hours: shelter and roll-call"
category: flood
ladder_min: drill
summary: "A sudden downpour floods the streets around a Lagos office in less than an hour. Staff must not leave into the water. They move to the upper floors, and a roll-call confirms that everyone is accounted for."
narrative: "This is a drill at one Lagos site. At 15:00 the facilitator announces that heavy rain has flooded the surrounding streets and water is coming onto the ground floor. Staff move ground-floor equipment and records where it is safe to do so, go up to the upper floors, stay inside, and answer the EMNS roll-call. The drill measures the time taken to relocate and the time taken to reach a complete headcount."
objectives:
  - "Ground floor cleared within 10 minutes"
  - "Every member of staff answers the roll-call within 15 minutes"
  - "No member of staff leaves the building into flood water"
  - "The unaccounted list is accurate at 15 minutes"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Water on the ground floor"
    content: "Water is coming in under the ground-floor doors."
    expected_response: "The floor warden announces the move to the upper floors."
  - sequence: 2
    release_offset_minutes: 3
    delivery_channel: sim_sms
    title: "FLOOD alert and roll-call"
    content: "The FLOOD alert and a roll-call arrive on staff phones."
    expected_response: "Staff answer the roll-call."
  - sequence: 3
    release_offset_minutes: 8
    delivery_channel: in_room
    title: "Staff want to leave before the traffic"
    content: "Some staff want to leave now to beat the traffic."
    expected_response: "Wardens stop anyone leaving, and explain why."
  - sequence: 4
    release_offset_minutes: 15
    delivery_channel: in_room
    title: "Two people unaccounted for"
    content: "The roll-call shows two people who have not answered."
    expected_response: "Wardens check the ground floor and the toilets, and confirm where both are."
  - sequence: 5
    release_offset_minutes: 30
    delivery_channel: in_room
    title: "Stand down"
    content: "The facilitator ends the drill."
    expected_response: "The timings are recorded for the drill report."
```

### 9.23 FLOOD-DC-BASEMENT (F6, functional)

```yaml
code: FLOOD-DC-BASEMENT
name: "Water ingress threatens the data centre power plant"
category: flood
ladder_min: functional
summary: "Flood water gets into the basement plant room of the primary data centre, where the switchgear and the generator fuel pumps are. The team has to decide early whether to fail over before the power plant fails."
narrative: "During a severe storm, water starts coming into the basement plant room of the primary data centre at 16:00. The pumps are running but cannot keep up. Facilities estimates the water will reach the switchgear in 2 to 3 hours. Nothing has failed yet. The exercise tests a decision to fail over before anything breaks, and carrying it out, in a controlled window."
objectives:
  - "Take the pre-emptive failover decision within 30 minutes, against agreed criteria"
  - "Fail tier-1 services over to DR before the plant room fails"
  - "Record the actual RTO and RPO of each in-scope service"
  - "Fail back safely after the inspection"
drivers: [cbn.rcf.bc_dr, cbn.open_banking.failover]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Water in the plant room"
    content: "16:00. Facilities reports water entering the basement plant room."
    expected_response: "The IT DR manager and facilities meet and apply the failover criteria."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_phone
    title: "The pumps are losing"
    content: "The water is still rising, and the pumps are falling behind."
    expected_response: "The named authority decides to fail over now, and the time is logged."
  - sequence: 3
    release_offset_minutes: 60
    delivery_channel: sim_system
    title: "Failover in progress"
    content: "Six of the nine tier-1 services are up at DR."
    expected_response: "Tier-1 recovery is timed against the RTO."
  - sequence: 4
    release_offset_minutes: 110
    delivery_channel: sim_phone
    title: "Water 20 cm below the switchgear"
    content: "The water is 20 cm below the switchgear."
    expected_response: "The remaining services are moved or shut down, in order."
  - sequence: 5
    release_offset_minutes: 170
    delivery_channel: sim_email
    title: "Water receding; inspection needed"
    content: "The water is going down. An electrical inspection is needed before the plant can be relied on."
    expected_response: "Fail-back waits for an electrical safety sign-off."
  - sequence: 6
    release_offset_minutes: 220
    delivery_channel: in_room
    title: "Evidence capture"
    content: "Services are running at DR."
    expected_response: "The times and the findings are recorded."
```

### 9.24 FLOOD-CORRIDOR (F6, tabletop)

```yaml
code: FLOOD-CORRIDOR
name: "Flooding cuts the north-south highway at the Lokoja crossing"
category: flood
ladder_min: tabletop
summary: "River flooding at the Niger-Benue confluence cuts a main highway between north and south for several days. The cash logistics, staff travel and supplier deliveries that use the route are all disrupted."
narrative: "Flooding at Lokoja puts sections of the highway under water. Traffic between north and south diverts onto longer routes, which are congested and less secure. The bank moves cash, equipment and staff along this route every week, and two suppliers deliver through it. The exercise covers days 1 to 7."
objectives:
  - "Within 4 hours, list every activity that depends on the route"
  - "Agree alternative logistics with the CIT provider and the suppliers, including a security assessment"
  - "Stop non-essential staff road travel on the diversion routes"
drivers: [iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Highway closed at Lokoja"
    content: "The highway is closed at Lokoja because of flooding."
    expected_response: "The logistics owner lists the affected cash runs, deliveries and journeys."
  - sequence: 2
    release_offset_minutes: 25
    delivery_channel: sim_phone
    title: "CIT provider proposes a longer route"
    content: "The CIT provider proposes a longer route. It passes through an area with recent reports of kidnapping."
    expected_response: "Security assesses the route, and the team decides whether to use it."
  - sequence: 3
    release_offset_minutes: 55
    delivery_channel: sim_email
    title: "Supplier delivery delayed 5 days"
    content: "A supplier of branch equipment says its delivery will be 5 days late."
    expected_response: "The team checks stock and invokes the supplier's continuity arrangement."
  - sequence: 4
    release_offset_minutes: 85
    delivery_channel: in_room
    title: "Staff due to travel for an audit"
    content: "Four internal auditors are booked to drive south tomorrow."
    expected_response: "The audit moves to remote working or is postponed, and travel rules are issued."
  - sequence: 5
    release_offset_minutes: 120
    delivery_channel: sim_media
    title: "Day 5: partial reopening"
    content: "One lane reopens under traffic control."
    expected_response: "The criteria for resuming road movements are agreed."
  - sequence: 6
    release_offset_minutes: 150
    delivery_channel: in_room
    title: "Close"
    content: "The highway is fully open."
    expected_response: "The dependencies on this route are recorded as findings."
```

### 9.25 ROBBERY-BRANCH (F7, drill)

```yaml
code: ROBBERY-BRANCH
name: "Armed robbery at a branch: staff response drill"
category: security
ladder_min: drill
summary: "A drill of the branch robbery procedure, run with role-players after liaising with the police in advance. Life comes first: comply, do not resist, observe, raise the alarm when it is safe, then protect the scene and account for everyone."
narrative: "This is a drill at one branch, run outside banking hours or with no customers present, and arranged in advance with the police and the security provider. Role-players stage an armed robbery. No real weapons are used, and no prop is ever visible from outside the building. Staff follow the procedure: comply, do not resist, raise the silent alarm only if it is safe, and note descriptions. Once the role-players leave, staff lock the doors, account for everyone, give first aid if it is needed, protect the scene and report."
objectives:
  - "Staff comply and do not resist in every interaction observed"
  - "The alarm is raised when it is safe to do so, and the time is recorded"
  - "Everyone on the premises is accounted for within 5 minutes of the robbers leaving"
  - "The scene is protected, and staff write witness notes before they talk to each other"
drivers: [iso22320.incident_response]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Robbers enter"
    content: "The role-players enter and make their demands."
    expected_response: "Staff comply and do not resist."
  - sequence: 2
    release_offset_minutes: 3
    delivery_channel: in_room
    title: "A demand to open the vault"
    content: "The role-players demand that the vault is opened."
    expected_response: "The authorised member of staff follows the procedure for a vault demand."
  - sequence: 3
    release_offset_minutes: 8
    delivery_channel: in_room
    title: "The robbers leave"
    content: "The role-players leave the building."
    expected_response: "The doors are locked, the alarm is confirmed as raised, and the time is noted."
  - sequence: 4
    release_offset_minutes: 12
    delivery_channel: in_room
    title: "Headcount and first aid"
    content: "One member of staff says she feels faint."
    expected_response: "Everyone is accounted for, and first aid is given."
  - sequence: 5
    release_offset_minutes: 18
    delivery_channel: in_room
    title: "Scene protection and witness notes"
    content: "The police are on their way."
    expected_response: "The scene is taped off, and staff write their own notes before they discuss what happened."
  - sequence: 6
    release_offset_minutes: 30
    delivery_channel: sim_phone
    title: "Report to the regional office"
    content: "The regional office calls for an update."
    expected_response: "The report is made, and the team considers sending the ROBBERY alert to nearby staff."
```

### 9.26 ROBBERY-MULTI-BRANCH (F7, tabletop)

```yaml
code: ROBBERY-MULTI-BRANCH
name: "Coordinated armed attack on bank branches in one town"
category: security
ladder_min: tabletop
summary: "An armed gang attacks several bank branches in one town at the same time, two of them yours. Police officers, customers and staff are among the casualties. The crisis response covers welfare, families, the investigation, the regulators and the media all at once."
narrative: "At 16:00 on a weekday an armed gang attacks several bank branches in one town at the same time, including two of yours. The attack lasts about an hour. Police officers and members of the public are killed. At one of your branches a customer and a security guard are injured, and one member of staff cannot be found. The exercise opens as the first reports reach head office and covers the next 72 hours."
objectives:
  - "Account for every member of staff at both branches within 2 hours"
  - "Support injured staff and their families through a single named liaison"
  - "Preserve evidence and cooperate with the police investigation"
  - "Issue approved statements, and stop staff commenting without approval"
  - "Decide when and how the branches reopen"
drivers: [iso22361.crisis_management, iso22320.incident_response]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "A branch reports gunfire"
    content: "A branch manager calls head office: there is gunfire outside."
    expected_response: "The crisis team convenes. Nobody is sent towards the town."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_sms
    title: "ROBBERY alert to nearby staff"
    content: "Staff in the town, and those travelling there, need to be warned."
    expected_response: "The ROBBERY alert goes to staff in the town and to anyone travelling there."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: sim_phone
    title: "One member of staff missing"
    content: "One member of staff cannot be found."
    expected_response: "The police and hospitals are asked, and HR prepares to contact the next of kin."
  - sequence: 4
    release_offset_minutes: 70
    delivery_channel: sim_media
    title: "Videos of casualties on social media"
    content: "Videos showing casualties are circulating online."
    expected_response: "Staff are told not to share images, and the statement is approved."
  - sequence: 5
    release_offset_minutes: 100
    delivery_channel: in_room
    title: "Families at the branch gate"
    content: "Families are gathering at the branch gate."
    expected_response: "A family liaison point is set up away from the scene."
  - sequence: 6
    release_offset_minutes: 130
    delivery_channel: sim_email
    title: "Insurers and the regulatory record"
    content: "The insurers ask for notice of the loss."
    expected_response: "The insurers are notified, and the regulatory notification procedure is followed."
  - sequence: 7
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "Day 3: whether to reopen"
    content: "The police have released one of the two branches."
    expected_response: "Reopening waits for police release, a security review and staff welfare checks."
```

### 9.27 ROBBERY-CIT (F7, tabletop)

```yaml
code: ROBBERY-CIT
name: "Cash-in-transit vehicle attacked"
category: security
ladder_min: tabletop
summary: "A cash-in-transit vehicle carrying the bank's cash is attacked on its way to a branch. The crew are injured and the cash is taken. The bank has to respond alongside the CIT provider."
narrative: "At 09:40 the CIT provider reports that its vehicle carrying cash for three of your branches was attacked on the highway. Two crew members are injured and the cash has been taken. The three branches were expecting the cash by 11:00. The exercise covers the first day and the follow-up with the provider."
objectives:
  - "Confirm the crew's welfare through the provider within 1 hour"
  - "Plan cash again for the three branches the same day"
  - "Use the incident, liability and insurance terms of the contract"
  - "Review route and schedule security with the provider"
drivers: [iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "Provider reports the attack"
    content: "09:40. The CIT provider reports that the vehicle has been attacked and two crew injured."
    expected_response: "The incident is logged, and a crisis lead is named."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: in_room
    title: "Three branches waiting for cash"
    content: "Three branches were expecting this cash by 11:00."
    expected_response: "The branches adjust their cash limits and the messages to customers."
  - sequence: 3
    release_offset_minutes: 50
    delivery_channel: sim_email
    title: "A thin incident report from the provider"
    content: "The provider's first incident report is two lines long."
    expected_response: "The reporting terms of the contract are invoked."
  - sequence: 4
    release_offset_minutes: 85
    delivery_channel: sim_media
    title: "Rumour that staff were involved"
    content: "A rumour online says someone at the bank passed the route to the robbers."
    expected_response: "Staff are told not to comment, and HR and security coordinate."
  - sequence: 5
    release_offset_minutes: 120
    delivery_channel: in_room
    title: "Route review"
    content: "How predictable were the route and the timing?"
    expected_response: "The patterns of route and timing are reviewed with the provider."
  - sequence: 6
    release_offset_minutes: 150
    delivery_channel: in_room
    title: "Close"
    content: "The branches have been resupplied."
    expected_response: "Findings on the dependency on CIT are recorded."
```

### 9.28 KIDNAP-STAFF (F7, tabletop)

```yaml
code: KIDNAP-STAFF
name: "Staff member kidnapped while travelling on duty"
category: security
ladder_min: tabletop
summary: "A member of staff travelling by road on bank business is abducted, and a ransom demand is made to the family. The bank has to manage its duty of care, support for the family, confidentiality and the police lead all at once."
narrative: "A relationship manager travelling by road between two cities to visit a customer does not arrive. That evening the family receives a call demanding a ransom, and they call the bank. The police are informed. The exercise covers the first 72 hours and tests the crisis team's response. The bank does not negotiate on its own account: its decisions follow policy, police advice and specialist advice."
objectives:
  - "Appoint a single family liaison within 2 hours of the report"
  - "Inform the police and follow their lead"
  - "Keep the case confidential: no names or details on any channel, internal or external"
  - "Review and restrict staff road travel on the affected routes within 24 hours"
drivers: [iso22361.crisis_management]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_phone
    title: "A member of staff has not arrived"
    content: "A customer calls to say the relationship manager expected at 14:00 has not arrived and cannot be reached."
    expected_response: "The last known location and the travel plan are gathered, and the crisis lead is informed."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_phone
    title: "The family receives a ransom call"
    content: "The family calls the bank. They have received a ransom demand."
    expected_response: "A family liaison is appointed, and the police are informed."
  - sequence: 3
    release_offset_minutes: 45
    delivery_channel: in_room
    title: "A colleague posts about it"
    content: "A colleague has posted about the abduction in a social media group."
    expected_response: "The author is asked to remove the post, and the KIDNAP advisory reminds staff not to share anything."
  - sequence: 4
    release_offset_minutes: 75
    delivery_channel: sim_email
    title: "Specialist advice"
    content: "The specialist adviser sends written guidance."
    expected_response: "The crisis team records the advice it received, the decisions it took and who took them."
  - sequence: 5
    release_offset_minutes: 110
    delivery_channel: sim_media
    title: "A journalist asks for comment"
    content: "A journalist asks the bank to confirm the abduction."
    expected_response: "No comment is given beyond the approved statement."
  - sequence: 6
    release_offset_minutes: 140
    delivery_channel: in_room
    title: "Travel restrictions"
    content: "Other staff are due to travel the same road this week."
    expected_response: "Road travel on the route is suspended, and alternatives are set."
  - sequence: 7
    release_offset_minutes: 165
    delivery_channel: in_room
    title: "Release"
    content: "The member of staff is released."
    expected_response: "Medical, psychological and return-to-work support is arranged."
```

### 9.29 TELCO-MONTH-END (F8, tabletop)

```yaml
code: TELCO-MONTH-END
name: "Mobile network outage during month-end"
category: telecom
ladder_min: tabletop
summary: "One of the largest mobile network operators has a nationwide outage on the 27th, in the middle of month-end salary processing. For most of the day its subscribers have no voice, SMS, USSD or mobile data."
narrative: "At 08:30 on the 27th, one of the largest mobile networks fails nationwide, and its subscribers lose voice, SMS, USSD and data. It is month-end. Salary credits are posting, customers expect alerts and one-time passwords by SMS, POS agents rely on mobile data, and many staff are on this network. The operator says a core network fault is being fixed and gives no time. Service returns in stages from 16:00. The exercise covers 08:30 to 18:00."
objectives:
  - "Estimate within 1 hour what share of customers and staff are on the failed network"
  - "Keep customers able to authenticate and transact through other channels"
  - "Reach every crisis-team member and critical-activity lead through a second channel"
  - "Handle the month-end volume of complaints and queries with approved messages"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_media
    title: "Network down nationwide"
    content: "08:30. Reports say one of the largest mobile networks is down nationwide."
    expected_response: "An incident is raised, and the share of customers and staff on that network is estimated."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_system
    title: "One-time passwords not arriving"
    content: "SMS OTP delivery to the network is failing, and failed logins have tripled."
    expected_response: "The alternative authentication in the plan is enabled for the customers affected."
  - sequence: 3
    release_offset_minutes: 40
    delivery_channel: sim_phone
    title: "Crisis-team members cannot be reached"
    content: "Two members of the crisis team are on the network and cannot be called."
    expected_response: "Their second contact channel is used. If either has none, the gap is recorded."
  - sequence: 4
    release_offset_minutes: 70
    delivery_channel: sim_email
    title: "POS agents cannot transact"
    content: "The agent network reports that POS terminals on the network cannot connect."
    expected_response: "Agents are told what to do, and what their customers will see."
  - sequence: 5
    release_offset_minutes: 100
    delivery_channel: sim_media
    title: "Salary-day complaints"
    content: "Customers complain online that they cannot get to their salaries."
    expected_response: "Approved messages explain what still works, and the branches prepare for more traffic."
  - sequence: 6
    release_offset_minutes: 130
    delivery_channel: sim_system
    title: "Service returns in stages"
    content: "16:00. The network is returning, region by region."
    expected_response: "The backlog of failed notifications and OTPs is handled without sending anything twice."
  - sequence: 7
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "Close"
    content: "Service is fully back."
    expected_response: "Findings on dependence on a single network are recorded."
```

### 9.30 TELCO-SMS-OTP (F8, walkthrough)

```yaml
code: TELCO-SMS-OTP
name: "SMS one-time-password delivery failure"
category: telecom
ladder_min: walkthrough
summary: "The SMS aggregator used for one-time passwords stops delivering, and customers cannot complete digital transactions. The team walks through the fallback procedure."
narrative: "At 12:00 the SMS aggregator used for OTPs stops delivering messages to every network, and its status page reports a platform issue. The secondary aggregator is configured but has never carried OTP traffic. The walkthrough tests the switch-over procedure and the customer communication, one step at a time."
objectives:
  - "Confirm that the switch to the secondary aggregator can be made as written, and who makes it"
  - "Confirm the alternative authentication methods and who may enable them"
  - "Record the untested secondary route as a finding if it fails"
drivers: [iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "OTP delivery at zero"
    content: "12:00. OTP delivery receipts have dropped to zero on every network."
    expected_response: "An incident is raised, and the aggregator is contacted."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: in_room
    title: "Switch to the secondary aggregator"
    content: "The incident lead asks for the switch to the secondary aggregator."
    expected_response: "The team walks each switch-over step, and who carries it out."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: sim_system
    title: "Secondary sender ID not approved on one network"
    content: "Messages through the secondary route are rejected by one network: the sender ID is not approved there."
    expected_response: "The gap is recorded, and alternative authentication is considered."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: sim_media
    title: "Customers cannot pay bills"
    content: "Customers complain online that they cannot complete payments."
    expected_response: "Approved messages are issued."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: in_room
    title: "Walkthrough close"
    content: "Review each step walked."
    expected_response: "Every step that could not be followed is recorded with an owner."
```

### 9.31 TELCO-USSD-CHANNEL (F8, walkthrough)

```yaml
code: TELCO-USSD-CHANNEL
name: "Bank USSD channel down on one mobile network"
category: telecom
ladder_min: walkthrough
summary: "A mobile network's USSD gateway fails, taking the bank's USSD banking channel down for that network's subscribers. Many of the customers affected have no other digital channel."
narrative: "At 10:00 the bank's USSD banking service stops responding on one mobile network, and the operator confirms a gateway fault. Many of the customers who bank by USSD have feature phones and no internet banking. The walkthrough follows the channel-outage procedure: detection, escalation to the operator and the aggregator, customer communication, and support from branches and agents."
objectives:
  - "Confirm that the detection and escalation steps work as written"
  - "Establish how customers without smartphones are served during the outage"
  - "Record the channel's dependence on each mobile network as a finding if it is not managed"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "USSD success rate drops to zero on one network"
    content: "10:00. USSD session success on one network has dropped to zero."
    expected_response: "An incident is raised, and the aggregator and the operator are contacted."
  - sequence: 2
    release_offset_minutes: 15
    delivery_channel: sim_phone
    title: "No restoration time from the operator"
    content: "The operator confirms a gateway fault and gives no restoration time."
    expected_response: "The team walks the customer-communication step."
  - sequence: 3
    release_offset_minutes: 35
    delivery_channel: in_room
    title: "Customers with feature phones"
    content: "About a third of the affected customers have no other digital channel."
    expected_response: "The guidance for branches and agents on serving these customers is confirmed."
  - sequence: 4
    release_offset_minutes: 60
    delivery_channel: sim_email
    title: "Complaints to the regulator's consumer desk"
    content: "The complaints team reports that complaints are reaching the regulator's consumer desk."
    expected_response: "Complaints are handled through the documented process."
  - sequence: 5
    release_offset_minutes: 90
    delivery_channel: in_room
    title: "Walkthrough close"
    content: "Review each step walked."
    expected_response: "The findings are recorded."
```

### 9.32 TELCO-EMNS-DEGRADED (F8, drill)

```yaml
code: TELCO-EMNS-DEGRADED
name: "Evacuation roll-call while one mobile network is failing"
category: telecom
ladder_min: drill
summary: "During an evacuation drill, SMS to one mobile network is delayed or failing. EMNS operators have to switch channels and still produce an accurate headcount."
narrative: "This is an evacuation drill at head office. When the roll-call goes out, the facilitator announces that SMS to one mobile network is failing, and about a third of staff are on that network. The operators have to spot the failures in the delivery record, resend by another channel (voice, WhatsApp or email) and use the assembly-point marshals to close the gap. The drill measures the time taken to reach an accurate headcount."
objectives:
  - "EMNS operators spot the delivery failures in the delivery record within 5 minutes"
  - "Staff who were not reached are contacted on a second channel within 10 minutes"
  - "An accurate headcount is produced within 20 minutes"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Evacuation begins"
    content: "The alarm sounds and the building is evacuated."
    expected_response: "Staff evacuate, and the roll-call is dispatched as a simulation."
  - sequence: 2
    release_offset_minutes: 4
    delivery_channel: sim_system
    title: "Deliveries failing on one network"
    content: "The delivery record shows SMS failures for every recipient on one network."
    expected_response: "The operator recognises the pattern and resends to the affected staff on another channel."
  - sequence: 3
    release_offset_minutes: 10
    delivery_channel: in_room
    title: "Marshals report their counts"
    content: "The assembly-point marshals report their headcounts."
    expected_response: "The marshals' counts are reconciled with the roll-call responses."
  - sequence: 4
    release_offset_minutes: 18
    delivery_channel: in_room
    title: "Three people still unaccounted for"
    content: "Three people have not answered and are not on any marshal's count."
    expected_response: "Marshals and managers find them, and nobody goes back into the building."
  - sequence: 5
    release_offset_minutes: 30
    delivery_channel: in_room
    title: "All clear"
    content: "The facilitator declares the all clear."
    expected_response: "The times and the delivery evidence are recorded for the drill report."
```

### 9.33 FIRE-BRANCH (fire, drill)

```yaml
code: FIRE-BRANCH
name: "Fire at a branch during banking hours"
category: fire
ladder_min: drill
summary: "A fire starts in a branch's back office during banking hours. Staff raise the alarm, evacuate customers and colleagues, secure cash only if it is safe, and reconcile the headcount at the assembly point."
narrative: "This is a drill at one branch. The facilitator announces smoke coming from the back office. Staff raise the alarm, call the fire service, evacuate customers first and go to the assembly point. Cash is secured only if that takes seconds and carries no risk. Otherwise it is left where it is. The marshals reconcile the headcount against the staff roster and the visitor book, and a remote roll-call runs at the same time."
objectives:
  - "The branch is clear within 3 minutes of the alarm"
  - "The headcount is reconciled against the roster and the visitor book within 10 minutes"
  - "Nobody goes back into the building"
  - "Every member of staff answers the remote roll-call within 15 minutes"
drivers: []
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: in_room
    title: "Smoke from the back office"
    content: "The facilitator announces smoke coming from the back office."
    expected_response: "The alarm is raised, and the evacuation begins."
  - sequence: 2
    release_offset_minutes: 2
    delivery_channel: in_room
    title: "A customer will not leave without their money"
    content: "A customer refuses to leave until their withdrawal is paid."
    expected_response: "Staff direct the customer out, and no cash is handled."
  - sequence: 3
    release_offset_minutes: 6
    delivery_channel: sim_sms
    title: "FIRE alert and roll-call"
    content: "The FIRE alert and the roll-call arrive on staff phones."
    expected_response: "Staff answer from the assembly point."
  - sequence: 4
    release_offset_minutes: 10
    delivery_channel: in_room
    title: "Nobody brought the visitor book"
    content: "The visitor book was left inside."
    expected_response: "The marshal uses the best list available, and records the gap as a finding."
  - sequence: 5
    release_offset_minutes: 15
    delivery_channel: in_room
    title: "Someone wants to fetch a laptop"
    content: "A member of staff wants to go back in for a laptop."
    expected_response: "The marshal stops them."
  - sequence: 6
    release_offset_minutes: 30
    delivery_channel: in_room
    title: "Fire service gives the all clear"
    content: "The fire service declares the building safe."
    expected_response: "The timings are recorded for the drill report."
```

### 9.34 SUPPLIER-SWITCH (supplier, tabletop)

```yaml
code: SUPPLIER-SWITCH
name: "Interbank payment switch outage on salary day"
category: supplier
ladder_min: tabletop
summary: "The national interbank switch used for instant transfers fails on a salary day, and instant transfers in and out stop across the industry. The bank cannot fix it. It has to manage its customers and its own obligations."
narrative: "At 09:00 on the 25th, instant interbank transfers stop across the industry. The switch operator confirms a platform incident and gives no restoration time. Customers cannot send transfers to other banks or receive them, and corporate customers' salary credits are queued. The exercise covers the day until the service returns at 17:00 and the backlog clears."
objectives:
  - "Confirm within 30 minutes that the fault is external, and say so accurately"
  - "Apply the documented handling for queued and failed transfers, with no duplicate postings"
  - "Serve customers with urgent needs through the channels that still work"
  - "Reconcile the backlog when the service returns"
drivers: [iso22318.supply_chain]
injects:
  - sequence: 1
    release_offset_minutes: 0
    delivery_channel: sim_system
    title: "Instant transfers failing"
    content: "09:00. Every outgoing instant transfer is timing out."
    expected_response: "An incident is raised, and the switch operator is contacted."
  - sequence: 2
    release_offset_minutes: 20
    delivery_channel: sim_email
    title: "The switch operator confirms an incident"
    content: "The switch operator confirms a platform incident affecting all participants."
    expected_response: "Customer messages say the fault is industry-wide and outside the bank."
  - sequence: 3
    release_offset_minutes: 50
    delivery_channel: sim_phone
    title: "A corporate customer's payroll is queued"
    content: "A corporate customer's finance director asks what has happened to today's payroll."
    expected_response: "The customer is told what will happen to the queued credits, and when."
  - sequence: 4
    release_offset_minutes: 90
    delivery_channel: sim_system
    title: "Customers keep retrying"
    content: "Customers are retrying failed transfers again and again."
    expected_response: "The duplicate-posting controls are checked."
  - sequence: 5
    release_offset_minutes: 130
    delivery_channel: sim_system
    title: "Service restored at 17:00"
    content: "17:00. The switch operator reports that service is restored."
    expected_response: "The backlog is processed and reconciled."
  - sequence: 6
    release_offset_minutes: 160
    delivery_channel: in_room
    title: "Close"
    content: "The backlog has cleared."
    expected_response: "Findings on dependence on external payment infrastructure are recorded."
```

---

## 10. Review sign-off

A shipped scenario is content a bank will run with its own staff. Before 12A-2 seeds it, a practising
BC officer should read the pack for realism. That is the same person E5 asks for, and one reading can
serve both purposes.

| Reviewer | Role | Date | Rows reviewed | Verdict |
|---|---|---|---|---|
| *(pending)* | Practising BC officer (design partner) | | | |

## HANDOFF

**Phase:** BCMS Phase 12, stage 0 (content)
**Agent:** compliance-analyst
**Status:** complete (draft, awaiting a BC-officer realism read and the architect's ruling on §2)
**Delivered:** this file. It contains 44 templates: 10 existing with 61 injects added, and 34 new with 211 injects (272 injects in all).
**Clause refs published:** none. Every driver is an existing `IsoClauseRef` case.
**Contracts touched:** proposes the `delivery_channel` vocabulary (§2), which ADR 0023 §3.2 assumes already exists and which does not. Changes `FIBRE-CUT`'s category (§6).
**Assumptions made:** offsets are bounded by the shortest default duration on each ladder rung (§3). The CBN 24-hour clock is asserted only for cyber incidents.
**Known gaps:** no full-scale or orientation template, by design (§7). No BC-officer review yet.
**Next agent:** architect (the §2 vocabulary), then backend-engineer at 12A-2 (seeder conversion, plus the §5 assertions as a test).
**Verification run:** counts, offsets and channel values checked row by row against §3 and §5. The drivers were checked against `app/Enums/Bcms/IsoClauseRef.php`. I confirmed that no code reads BCMS scenario codes or categories (`rg` over `app/`, `tests/` and `database/`).
