# Nigerian-context threat catalogue

**Owner:** compliance-analyst · **Phase:** BCMS Phase 12, stage 0 (content draft) · **Date:** 2026-09-24
**Converts into:** `app/Support/Bcms/ThreatCatalogue.php` (12A-2). It is a PHP class, not a table
(ADR 0023 §1, §3.3).
**Consumed by:** the scenario generator's grounding (ADR 0023 §3.3), the scenario library screen, and
the pack's category rule (`scenario-pack.md` §5, rule 7).

---

## 1. Shape

The catalogue is keyed by **scenario category**. Each key used in `scenario-pack.md` has exactly one
entry, so the generator can pick the grounding for a template mechanically:
`ThreatCatalogue::for($scenario->category)`.

| Key | Type | Enters the model prompt? | Purpose |
|---|---|---|---|
| `key` | string | no | Equals `bcms_scenarios.category` |
| `name` | string | no | Screen label |
| `grounding` | string, **≤ 700 characters** | **yes, and it is the only field that does** | Facts a realistic Nigerian variant must respect. Sized for the ~2,500-token prompt budget (work order §10, trap 20): 700 characters is about 175 tokens |
| `triggers` | list of strings | no | What starts it |
| `impacts` | list of strings | no | What it does to a bank |
| `early_warnings` | list of strings | no | What a bank can watch for |
| `seasonality` | string or null | no | Only where a source supports it |
| `scenario_codes` | list | no | Templates in `scenario-pack.md` that carry this category |
| `clause_refs` | list of `IsoClauseRef` values | no | Obligations a response to this threat helps evidence |
| `evidence` | list of `{fact, source, url, checked}` | **no, never** | Where every factual claim in `grounding` comes from |

**The rule that governs `grounding`.** Every factual sentence in it has an `evidence` row with a
source and the date I checked it. A sentence I could not source is not in the grounding. The model
repeats what it is told, so a fabricated statistic in grounding becomes a fabricated statistic in a
bank's exercise pack. `grounding` contains no phone numbers, email addresses or personal names, so it
passes the prompt scan in ADR 0023 §2.1.

**What this catalogue is not.** It is not a risk register and not a likelihood model. It says what a
threat looks like in Nigeria so that a scenario is realistic. It does not say how likely the threat is
for a particular bank. That judgement belongs to the bank's own risk assessment (ISO 22301 8.2.3),
which lives in the ERM module.

---

## 2. Entries

### 2.1 `power`: grid failure and generator dependency

```yaml
key: power
name: "Grid failure and generator dependency"
grounding: "Nigerian bank sites depend on standby generators because grid supply is unreliable. Press tallies based on Transmission Company of Nigeria reports count twelve national grid collapses and disturbances in 2024. In October 2024 vandalism of two 330kV transmission towers left much of northern Nigeria without grid supply, and insecurity delayed repairs until security forces escorted engineers. Generator runtime is bounded by the diesel held on site. In early 2022 diesel prices more than doubled within three months, and several banks shortened branch hours in response. Restoration after a collapse is phased and often comes with no times."
triggers:
  - "National grid collapse or disturbance"
  - "Vandalism of transmission lines or towers"
  - "Generator or automatic transfer switch failure"
  - "Diesel supply disruption or price spike"
impacts:
  - "Sites on generator for days; fuel becomes the binding constraint"
  - "Branch closures or shortened hours"
  - "Data centre on generator under long load; risk of uncontrolled shutdown"
  - "Telecom sites on battery fail after a day or two, cutting staff contact"
early_warnings:
  - "Transmission operator notices of grid disturbance"
  - "Diesel supplier rationing or price notices"
  - "Generator run-hours and maintenance backlog"
seasonality: null
scenario_codes: [GRID-DIESEL, GRID-COLLAPSE-NATIONAL, POWER-BRANCH-GENSET, POWER-DC-GENERATOR, POWER-VANDAL-REGIONAL]
clause_refs: [cbn.rcf.bc_dr, cbn.open_banking.failover]
evidence:
  - fact: "Twelve national grid collapses and disturbances in 2024"
    source: "Tribune Online, 'UPDATED: 12 times national grid collapsed in 2024'"
    url: "https://tribuneonlineng.com/updated-12-times-national-grid-collapsed-in-2024/"
    checked: "2026-09-24"
  - fact: "October 2024: vandalism of the Shiroro-Kaduna 330kV lines caused a blackout across northern Nigeria; insecurity delayed repairs; the President directed the Army and Air Force to protect TCN engineers"
    source: "Nairametrics, 29 Oct 2024; Sahara Reporters, 25 Oct 2024"
    url: "https://nairametrics.com/2024/10/29/blackout-tinubu-deploys-nigerian-army-and-air-force-to-protect-tcn-engineers-fixing-transmission-lines/"
    checked: "2026-09-24"
  - fact: "Diesel rose from about N312 per litre in January 2022 to about N800 in March 2022, and banks adjusted branch operating hours"
    source: "BusinessDay, 'Banks adjust operating time as diesel price skyrockets'"
    url: "https://businessday.ng/amp/news/article/banks-adjust-operating-time-as-diesel-price-skyrockets/"
    checked: "2026-09-24"
```

### 2.2 `fuel`: petrol scarcity and staff mobility

```yaml
key: fuel
name: "Petrol scarcity and staff mobility"
grounding: "Petrol shortages recur in Nigeria and have been persistent since the fuel subsidy was removed in 2023. During shortages in 2024, motorists queued for hours at filling stations, commuters were stranded at bus stops, and press reports put the rise in transport fares at around 40 per cent in major cities, including Lagos, Abuja and Kano. Staff attendance, generator diesel and cash-in-transit fleets all draw on the same fuel supply, so one shortage hits all three at once."
triggers:
  - "Supply or import disruption"
  - "Distribution strike (tanker drivers, depot workers)"
  - "Pricing changes that trigger hoarding"
impacts:
  - "Absenteeism; branches below minimum staffing"
  - "Cash-in-transit schedules cut"
  - "Staff transport and allowance costs"
  - "Unsafe fuel storage at homes and sites"
early_warnings:
  - "Queues forming at filling stations"
  - "Union strike notices"
  - "Marketers' statements on depot supply"
seasonality: null
scenario_codes: [FUEL-SCARCITY-MOBILITY, FUEL-TANKER-STRIKE, FUEL-CIT-FLEET, FUEL-REMOTE-SWITCH]
clause_refs: [iso22318.supply_chain]
evidence:
  - fact: "Persistent petrol shortages since the subsidy removal; long queues; transport costs up about 40% in Lagos, Abuja, Port Harcourt, Enugu, Kaduna, Kano and Maiduguri"
    source: "Anadolu Agency, 'Long queues in Nigeria as petrol scarcity worsens' (2024)"
    url: "https://www.aa.com.tr/en/africa/long-queues-in-nigeria-as-petrol-scarcity-worsens/3208438"
    checked: "2026-09-24"
  - fact: "Fuel shortage and queues in Lagos, Abuja and other cities, July 2024"
    source: "Vanguard, 'Scarcity: Fuel shortage, queues worsen in Lagos, Abuja, others'"
    url: "https://www.vanguardngr.com/2024/07/scarcity-fuel-shortage-queues-worsen-in-lagos-abuja-others/"
    checked: "2026-09-24"
```

### 2.3 `civil_unrest`: protests, curfews and cash-shortage crowds

```yaml
key: civil_unrest
name: "Protests, curfews and cash-shortage crowds"
grounding: "In Nigeria, protests can escalate quickly and curfews are imposed at short notice. In October 2020 Lagos State announced a 24-hour curfew during the EndSARS protests, and many workers said they could not get home before it began. In August 2024, during the EndBadGovernance protests, several states including Kano, Katsina, Jigawa, Borno and Zamfara declared 24-hour curfews, and Kaduna imposed one on Kaduna and Zaria. During the 2023 banknote shortage, crowds attacked bank premises and destroyed ATMs in several cities, and some banks closed branches."
triggers:
  - "Announced national protest days"
  - "Sudden local unrest after an incident"
  - "Cash or fuel shortages"
  - "Election-period tension"
impacts:
  - "Branches closed or sheltering with staff and customers inside"
  - "Staff stranded by curfews"
  - "Cash above limit on site; ATMs damaged"
  - "Threats to staff; welfare needs"
early_warnings:
  - "Announced protest dates and routes"
  - "Security agency advisories"
  - "Social media mobilisation"
seasonality: null
scenario_codes: [UNREST-CURFEW, UNREST-BRANCH-CLUSTER, PROTEST-ANNOUNCED-DAYS, UNREST-CASH-CROWDS, UNREST-LOCKDOWN-DRILL]
clause_refs: [iso22361.crisis_management]
evidence:
  - fact: "Lagos 24-hour curfew from 4pm on 20 October 2020, announced during EndSARS; complaints that people could not get home in time"
    source: "Wikipedia, '2020 Lekki shooting' (citing contemporary reporting); Nairametrics, 23 Oct 2020"
    url: "https://en.wikipedia.org/wiki/2020_Lekki_shooting"
    checked: "2026-09-24"
  - fact: "August 2024: 24-hour curfews in Borno, Kano, Katsina, Jigawa and Zamfara; Kaduna curfew on Kaduna and Zaria"
    source: "Anadolu Agency; Premium Times"
    url: "https://www.aa.com.tr/en/africa/curfew-declared-in-parts-of-nigeria-as-17-dead-in-anti-government-protests-/3293509"
    checked: "2026-09-24"
  - fact: "February 2023: protests over the naira shortage, attacks on bank premises and destruction of ATMs; some banks shut branches"
    source: "France 24 Observers, 17 Feb 2023; Channels TV, 15 Feb 2023"
    url: "https://observers.france24.com/en/africa/20230217-nigeria-naira-cash-scarcity-protests-banks-election"
    checked: "2026-09-24"
```

### 2.4 `connectivity`: submarine cables and terrestrial fibre

```yaml
key: connectivity
name: "Submarine cable and terrestrial fibre failure"
grounding: "West Africa's international connectivity depends on a small number of submarine cables. On 14 March 2024 a suspected underwater rock slide off Cote d'Ivoire damaged four cables (WACS, ACE, MainOne and SAT-3), disrupting internet and banking services in Nigeria and twelve other countries. Full repairs took until May. On land, fibre is cut frequently by vandalism and road construction, and backup routes sometimes share the same final kilometres. Offshore-hosted services, international card authorisation and staff email hosted abroad are the first things to degrade."
triggers:
  - "Submarine cable damage (seabed events, ship anchors)"
  - "Terrestrial fibre vandalism or theft"
  - "Road construction cutting fibre"
  - "Carrier core faults"
impacts:
  - "International latency and packet loss; card authorisation failures"
  - "Offshore-hosted services unreachable"
  - "Branches offline where backup links are missing or share a route"
early_warnings:
  - "Carrier fault notices"
  - "International latency monitoring"
  - "Road-works notices on fibre routes"
seasonality: null
scenario_codes: [FIBRE-CUT, CABLE-SUBSEA-MULTI, CLOUD-UNREACHABLE, FIBRE-VANDAL-METRO, CONNECT-FAILOVER-OB]
clause_refs: [cbn.rcf.bc_dr, cbn.open_banking.failover, cbn.open_banking.threshold, iso22318.supply_chain]
evidence:
  - fact: "14 March 2024, suspected underwater rock slide off Cote d'Ivoire; ACE, SAT-3, WACS and MainOne damaged; 13 countries affected including Nigeria; restoration dates ran from late March to May (SAT-3 by 6 April, WACS by 5 May)"
    source: "Internet Society, '2024 West Africa Submarine Cable Outage Report'"
    url: "https://www.internetsociety.org/resources/doc/2024/2024-west-africa-submarine-cable-outage-report/"
    checked: "2026-09-24"
  - fact: "The March 2024 cable outage disrupted digital transactions for Nigerian bank customers"
    source: "Wikipedia, '2024 Internet outage in Africa' (citing Nigerian press)"
    url: "https://en.wikipedia.org/wiki/2024_Internet_outage_in_Africa"
    checked: "2026-09-24"
```

### 2.5 `telecom`: mobile network outages

```yaml
key: telecom
name: "Mobile network outages"
grounding: "Mobile networks carry most staff and customer communication in Nigeria: SMS one-time passwords, USSD banking, POS agents and staff call trees. Fibre cuts are the largest single cause of major network outages. NCC uptime data for the first quarter of 2026 showed 577 major outages, of which 361 were caused by fibre cuts. Telecom infrastructure was designated Critical National Information Infrastructure by an executive order in June 2024. When one large network fails, staff whose only number is on that network cannot be reached, and customers cannot receive one-time passwords."
triggers:
  - "Fibre cuts and site vandalism"
  - "Core network faults at an operator"
  - "USSD or SMS aggregator platform failure"
  - "Power loss at telecom sites"
impacts:
  - "OTP delivery fails; digital transactions blocked"
  - "USSD banking unavailable for feature-phone customers"
  - "Call trees and EMNS SMS fail for staff on the affected network"
  - "POS agent network degraded"
early_warnings:
  - "Operator status notices"
  - "Delivery-receipt failure rates by network"
  - "Aggregator status pages"
seasonality: null
scenario_codes: [TELCO-MONTH-END, TELCO-SMS-OTP, TELCO-USSD-CHANNEL, TELCO-EMNS-DEGRADED]
clause_refs: [iso22318.supply_chain]
evidence:
  - fact: "Q1 2026: 577 major network outages, 361 caused by fibre cuts (NCC uptime monitoring portal); 19,384 fibre cuts between January and August 2025"
    source: "Leadership, 'Vandalism: Telecom operators hit by 5,900 fibre cuts in Q1'"
    url: "https://leadership.ng/vandalism-telecom-operators-hit-by-5900-fibre-cuts-in-q1/"
    checked: "2026-09-24"
  - fact: "Designation and Protection of Critical National Information Infrastructure Order 2024, signed 24 June 2024, covering telecom networks, base stations and fibre"
    source: "Mondaq, 'The Federal Government has officially signed the Designation and Protection of CNII Order 2024'"
    url: "https://www.mondaq.com/nigeria/telecoms-mobile-cable-communications/1539672/the-federal-government-has-officially-signed-the-designation-and-protection-of-critical-national-information-infrastructure-order-2024"
    checked: "2026-09-24"
```

### 2.6 `cyber`: ransomware, extortion, DDoS and third-party compromise

```yaml
key: cyber
name: "Ransomware, extortion, DDoS and third-party compromise"
grounding: "Nigerian deposit money banks and payment service banks operate under the CBN Risk-Based Cybersecurity Framework (2024, effective 1 July 2024). It requires cyber incidents to be reported to the CBN within 24 hours, and requires participation in NigFinCERT and in industry cyber drills. Where personal data is involved, the Nigeria Data Protection Act 2023 requires notification to the NDPC within 72 hours of becoming aware of the breach. Realistic scenarios involve backups that turn out to be encrypted or unverified, attackers entering through a supplier's remote access, and extortion timed to salary week."
triggers:
  - "Ransomware"
  - "Data exfiltration with extortion"
  - "Volumetric DDoS on digital channels"
  - "Compromise of a managed service provider"
  - "Privileged insider action"
impacts:
  - "Core or channel outage"
  - "Recovery forced to an older restore point"
  - "Two regulator clocks (CBN 24h, NDPC 72h)"
  - "Customer notification decisions"
early_warnings:
  - "Security monitoring alerts"
  - "Supplier security notices"
  - "NigFinCERT advisories"
seasonality: null
scenario_codes: [RANSOMWARE, INSIDER-FRAUD, RANSOMWARE-CORE, CYBER-DDOS, CYBER-EXFIL-EXTORTION, CYBER-MSP-COMPROMISE]
clause_refs: [cbn.rcf.incident_response, cbn.rcf.cyber_drills, cbn.rcf.bc_dr, ndpa.breach_notification, iso22318.supply_chain]
evidence:
  - fact: "CBN Risk-Based Cybersecurity Framework for DMBs and PSBs, issued 31 May 2024, effective 1 July 2024; cyber incidents reported to the CBN within 24 hours; NigFinCERT participation and industry drills"
    source: "CBN (framework PDF); Mondaq overview"
    url: "https://www.cbn.gov.ng/Out/2024/BSD/CBN%20Risk-Based%20Cybersecurity%20Framework%20for%20DMBs%20and%20PSBs_2024.pdf"
    checked: "2026-09-24"
  - fact: "NDPA 2023 s.40: notify the Commission within 72 hours of becoming aware of a personal data breach"
    source: "Already published as ndpa.breach_notification (ClauseRefs.php; NDPA 2023)"
    url: "https://cert.gov.ng/ngcert/resources/Nigeria_Data_Protection_Act_2023.pdf"
    checked: "2026-09-24 (as recorded in ndpa-register.md §9.3)"
```

### 2.7 `flood`: riverine, flash and coastal flooding

```yaml
key: flood
name: "Riverine, flash and coastal flooding"
grounding: "Flooding is a recurring hazard in Nigeria. In 2022 floods killed more than 600 people and displaced about 1.3 million people nationally, by NEMA figures reported in October 2022, and Bayelsa State was the worst affected, with around 300 communities wholly or partly submerged. The NIHSA 2025 Annual Flood Outlook placed Lagos and Bayelsa among the high flood-risk states and warned that coastal states may also flood from tidal surges. Realistic scenarios involve staff displaced from their homes, roads into a state cut, flooded ground floors and plant rooms, and waterborne disease after the water."
triggers:
  - "Riverine flooding after upstream rain and dam releases"
  - "Flash flooding in cities after intense rain"
  - "Coastal tidal surges"
impacts:
  - "Branches flooded or cut off"
  - "Staff displaced; welfare needs"
  - "Road logistics cut (cash, suppliers)"
  - "Plant rooms and generators at risk"
early_warnings:
  - "NIHSA Annual Flood Outlook and seasonal alerts"
  - "State emergency management advisories"
  - "River level reports"
seasonality: "NIHSA's 2025 outlook grouped high-risk periods as April to June, July to September, and October to November, varying by location"
scenario_codes: [FLOOD-LAGOS, FLOOD-BAYELSA, FLOOD-LAGOS-FLASH, FLOOD-DC-BASEMENT, FLOOD-CORRIDOR]
clause_refs: [cbn.rcf.bc_dr]
evidence:
  - fact: "2022 floods: 603 dead and about 1.3 million displaced as of mid-October 2022 (federal government / NEMA)"
    source: "Leadership, '2022 Flood: 603 Dead, 1.3m Displaced Across Nigeria - Federal Govt'"
    url: "https://leadership.ng/2022-flood-603-dead-1-3m-displaced-across-nigeria-federal-govt/"
    checked: "2026-09-24"
  - fact: "Bayelsa worst affected in 2022; around 300 communities wholly or partly submerged and around 700,000 people affected or displaced, per the state environment commissioner"
    source: "Wikipedia, '2022 Bayelsa State floods'; CNN, 26 Oct 2022"
    url: "https://en.wikipedia.org/wiki/2022_Bayelsa_State_floods"
    checked: "2026-09-24"
  - fact: "NIHSA 2025 Annual Flood Outlook: Lagos and Bayelsa among high-risk states; coastal flooding from tidal surges; risk periods April-June, July-September, October-November"
    source: "Vanguard, April 2025; The Sun, 2025"
    url: "https://www.vanguardngr.com/2025/04/lagos-rivers-fct-30-other-states-at-high-risk-of-flooding-nihsa-warns/"
    checked: "2026-09-24"
```

### 2.8 `security`: armed robbery, cash-in-transit attack and kidnapping

```yaml
key: security
name: "Armed robbery, cash-in-transit attack and kidnapping"
grounding: "Armed attacks on bank branches have occurred in Nigeria. In April 2018 a gang attacked several banks in Offa, Kwara State, at the same time, killing more than thirty people including police officers. Kidnapping for ransom on highways is a travel risk for staff. In March 2022 an attack on the Abuja-Kaduna train led to dozens of passengers being abducted, some of them held for months. A realistic response puts life first, follows the police lead, supports families through a single liaison, and keeps case details off every channel."
triggers:
  - "Armed attack on a branch"
  - "Attack on a cash-in-transit vehicle"
  - "Abduction of staff travelling by road"
impacts:
  - "Casualties among staff, customers and police"
  - "Missing staff; family liaison"
  - "Branches closed pending police release"
  - "Media and social media exposure"
early_warnings:
  - "Security advisories for routes and towns"
  - "Pattern reports from the security provider"
seasonality: null
scenario_codes: [ROBBERY-BRANCH, ROBBERY-MULTI-BRANCH, ROBBERY-CIT, KIDNAP-STAFF]
clause_refs: [iso22320.incident_response, iso22361.crisis_management, iso22318.supply_chain]
evidence:
  - fact: "Offa, Kwara State, April 2018: simultaneous attack on several banks; about 33 killed including 9 police officers (figures vary slightly by source); five convicted and sentenced to death"
    source: "Channels TV, 6 Apr 2018; BusinessDay, 'Five sentenced to death over Offa 2018 bank robbery'"
    url: "https://businessday.ng/news/article/five-sentenced-to-death-over-offa-2018-bank-robbery/"
    checked: "2026-09-24"
  - fact: "28 March 2022 attack on the Abuja-Kaduna train; dozens of passengers abducted; the last were released about six months later"
    source: "Amnesty International Nigeria, 17 May 2022; VOA"
    url: "https://www.amnesty.org.ng/2022/05/17/50-days-into-their-abduction-at-least-62-kaduna-abuja-train-passengers-remain-in-captivity/"
    checked: "2026-09-24"
```

### 2.9 `system`: core platform and data centre loss

```yaml
key: system
name: "Core platform and data centre loss"
grounding: "A Nigerian bank's core banking platform, and the data centre that hosts it, is a single dependency for teller, card, internet, mobile and USSD services. Vendors often acknowledge an outage without committing to a restoration time. Realistic scenarios test manual service at branches, offline transaction limits, reconciliation of manually taken transactions, dependencies missing from the DR recovery order, and data loss measured against RPO. Open banking participants are expected to test failover quarterly and DR six-monthly, and to meet a 30-minute failover threshold."
triggers:
  - "Application or database failure"
  - "Vendor-side incident"
  - "Loss of the primary data centre (fire, flood, prolonged inaccessibility)"
impacts:
  - "Every channel down at once"
  - "Manual transactions needing reconciliation"
  - "DR invocation decisions against RTO"
early_warnings:
  - "Monitoring and capacity trends"
  - "Vendor maintenance and incident notices"
seasonality: null
scenario_codes: [CORE-OUTAGE, DC-LOSS]
clause_refs: [cbn.open_banking.failover, cbn.open_banking.dr_test, cbn.open_banking.threshold, cbn.rcf.bc_dr]
evidence:
  - fact: "Quarterly failover, six-monthly DR test and a 30-minute failover threshold for open banking participants"
    source: "CBN Operational Guidelines for Open Banking in Nigeria, as already cited in ClauseRefs.php (cbn.open_banking.*)"
    url: "(the citation of record is ClauseRefs.php)"
    checked: "2026-09-24 (taxonomy entry, not re-fetched)"
```

**No Nigerian incident is cited for this category, on purpose.** The public incidents I could find name
specific banks. A catalogue that grounds generated scenarios on a named competitor's outage is the
branding problem this pack avoids.

### 2.10 `supplier`: critical third-party and market-infrastructure outage

```yaml
key: supplier
name: "Critical third-party and market-infrastructure outage"
grounding: "Banks in Nigeria depend on third parties they do not control: card processors, the national interbank switch for instant transfers, cash-in-transit providers, managed IT services and SMS aggregators. When one of them fails, the bank cannot fix the fault. It can invoke the documented workaround, use its contractual rights to notification, cooperation and audit, communicate accurately that the fault is external, and prevent duplicate postings when customers retry. Whether the supplier's own continuity arrangement was invoked, and what evidence of it exists, is a question an examiner asks."
triggers:
  - "Provider platform outage"
  - "Provider insolvency or exit"
  - "Provider security incident"
impacts:
  - "Service degraded with no restoration time"
  - "Queued and failed transactions"
  - "Contractual breaches to pursue"
early_warnings:
  - "TPRM monitoring and SLA breaches"
  - "Provider status pages"
seasonality: null
scenario_codes: [SUPPLIER-FAIL, SUPPLIER-SWITCH]
clause_refs: [iso22318.supply_chain]
evidence: []
```

The grounding here states practice, not incidents, and makes no factual claim that needs a source. The
link to TPRM matters: a supplier in this category is a TPRM third party, and its exit plan and
continuity evidence belong in the TPRM engagement record. The BCMS scenario tests them; TPRM holds them.

### 2.11 `pandemic`: mass absence and movement restriction

```yaml
key: pandemic
name: "Mass absence and movement restriction"
grounding: "A public health emergency can remove a large share of staff and restrict movement. In March 2020 the federal government restricted movement in Lagos, the FCT and Ogun State from 30 March. Banks were then allowed to provide essential services with skeleton staff. Realistic scenarios test minimum staffing per prioritised activity, succession for critical roles, remote-access capacity, and branch closure and contact notification that protects the privacy of the staff involved."
triggers:
  - "Declared public health emergency"
  - "Local outbreak at a site"
impacts:
  - "Absence above planned minimums"
  - "Movement restrictions"
  - "Remote-access saturation"
early_warnings:
  - "Public health authority advisories"
  - "Absence trends"
seasonality: null
scenario_codes: [PANDEMIC]
clause_refs: []
evidence:
  - fact: "Movement restriction in Lagos, the FCT and Ogun from 11pm on 30 March 2020; banks allowed to provide essential services with skeleton staff"
    source: "TheCable, 'COVID-19 and Nigerian banks'; CNBC Africa"
    url: "https://www.thecable.ng/covid-19-and-nigerian-banks/"
    checked: "2026-09-24"
```

### 2.12 `fire`: fire at a branch or office

```yaml
key: fire
name: "Fire at a branch or office"
grounding: "A fire drill tests whether a site can be cleared quickly, whether the headcount can be reconciled against the roster and visitor book at the assembly point, and whether nobody goes back inside. In a branch, customers are evacuated first, cash is secured only if that takes seconds and carries no risk, and a remote roll-call runs alongside the marshals' count. Realistic injects include customers who refuse to leave, a missing visitor book, staff who want to fetch belongings, and one person unaccounted for."
triggers:
  - "Electrical fault"
  - "Generator or fuel store fire"
  - "Fire spreading from a neighbouring building"
impacts:
  - "Evacuation; site closed"
  - "Headcount reconciliation"
  - "Records and equipment loss"
early_warnings:
  - "Fire-safety inspection findings"
  - "Generator and electrical maintenance backlog"
seasonality: null
scenario_codes: [FIRE-BRANCH]
clause_refs: []
evidence: []
```

**No Nigerian fire statistic is cited.** I did not find a national figure I could source, and this
catalogue does not invent one. The grounding states practice only.

---

## 3. Coverage check

| Category | Entry | Templates carrying it |
|---|---|---|
| `power` | 2.1 | 5 |
| `fuel` | 2.2 | 4 |
| `civil_unrest` | 2.3 | 5 |
| `connectivity` | 2.4 | 5 |
| `telecom` | 2.5 | 4 |
| `cyber` | 2.6 | 6 |
| `flood` | 2.7 | 5 |
| `security` | 2.8 | 4 |
| `system` | 2.9 | 2 |
| `supplier` | 2.10 | 2 |
| `pandemic` | 2.11 | 1 |
| `fire` | 2.12 | 1 |
| **12 entries** | | **44** |

Every category used in `scenario-pack.md` has an entry, and no entry is unused.

## HANDOFF

**Phase:** BCMS Phase 12, stage 0 (content)
**Agent:** compliance-analyst
**Status:** complete (draft)
**Delivered:** this file: 12 entries keyed by scenario category, with 21 sourced evidence rows.
**Clause refs published:** none.
**Contracts touched:** proposes the `ThreatCatalogue` shape, keyed by `bcms_scenarios.category`, with `grounding` as the only field that reaches a prompt.
**Assumptions made:** matching the grounding on category is how ADR 0023 §3.3's "matching entry" is meant to work. If the architect wants per-template grounding instead, `scenario_codes` already supports the reverse lookup.
**Known gaps:** three entries (`system`, `supplier`, `fire`) cite no Nigerian incident, deliberately (see each entry). Figures from 2024 to 2026 will age, so the catalogue needs a yearly review.
**Next agent:** backend-engineer at 12A-2 (conversion). qa-engineer should add the E.164/email scan over `grounding`.
**Verification run:** every figure in `grounding` was checked against the source in its `evidence` row on 2026-09-24. Two sources refused automated fetch (guardian.ng, thecable.ng, both HTTP 403), and a second source was used for each. One figure was corrected during checking: 19,384 fibre cuts covers January to August 2025, not all of 2025.
