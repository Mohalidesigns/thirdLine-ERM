# EMNS scenario templates: 11 scenarios, English and Nigerian Pidgin

**Owner:** compliance-analyst · **Phase:** BCMS Phase 12, stage 0 (content draft) · **Date:** 2026-09-24
**Converts into:** `database/seeders/Bcms/Reference/AlertTemplates.php` (12A-2), one row per locale,
seeded by `BcmsReferenceSeeder::seedAlertTemplates()` into `bcms_alert_templates`.
**Governed by:** Blueprint §7.1 (the 11 scenarios), ADR 0023 §4.2 and "What this ADR deliberately does
not do" (no machine translation), work order §9 and owner question 3 (X2).

## 1. Coverage grid

| Code | Scenario | `en` | `pcm` | `ha` | `yo` | `ig` |
|---|---|---|---|---|---|---|
| FIRE | Fire | authored | authored, **awaiting native review** | PENDING (X2) | PENDING (X2) | PENDING (X2) |
| FLOOD | Flood | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| ROBBERY | Robbery / armed incident | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| UNREST | Civil unrest | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| FUELPOWER | Fuel / power failure | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| NETOUTAGE | Network outage | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| CYBER | Cyber incident | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| PANDEMIC | Pandemic / health | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| KIDNAP | Kidnap | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| PROTEST | Protest | authored | authored, awaiting review | PENDING | PENDING | PENDING |
| ROADBLOCK | Road blockage | authored | authored, awaiting review | PENDING | PENDING | PENDING |

**22 rows are authored here: 11 in `en` and 11 in `pcm`. 33 slots are pending.**

**Hausa, Yoruba and Igbo are not in this file, and nothing in this file should be used to produce them.**
They are external prerequisite **X2**: a qualified translator plus a native-speaker reviewer for each
language, loaded as inactive rows and activated by a human. Until then, `TemplateRenderer` falls back
to English and records the fallback on every delivery (`locale_fell_back`). That fallback is the
honest state and it is visible in `TemplateRenderer::coverage()`.

**The Pidgin rows need a human too, and I recommend they seed inactive.** ADR 0023 rejects "a
model-drafted Hausa, Yoruba, Igbo or Pidgin alert 'for human review'" because the reviewer often
cannot read it. The Pidgin below was written by compliance-analyst, which is itself a model. It was
not run through a translation pipeline, but it is model-written all the same, so the ADR's reasoning
applies. My recommendation:

- Seed `pcm` rows with `is_active = false`. They show as `awaiting_review` in the coverage grid, and
  English is used in the meantime.
- A native Nigerian Pidgin speaker reviews each row (§7) and activates it on the existing template
  screen.
- **The seeder must stop overwriting `is_active` on rows that already exist.** Today
  `seedAlertTemplates()` writes `'is_active' => true` inside `updateOrCreate`. With review-gated rows,
  a seeder re-run would either switch off a row a human has activated or switch on a row nobody has
  reviewed. `is_active` should be set only when the row is created. The owner decides this; it is a
  two-line seeder change.

The same question applies to the Pidgin `EVACUATE` and `ROLLCALL` rows already live since Phase 0. I
cannot tell from the repository who reviewed them.

---

## 2. Two defects in the shipped rendering path, and how this copy works around them

I read these in code on 2026-09-24. They are not introduced by this pack. **They are the reason the
new templates are written the way they are**, and they affect twelve rows that already ship.

### F-1. Placeholders in channel renderings are never substituted, and nothing supplies site variables

- `TemplateRenderer::render()` substitutes variables into the template **body** and **subject**.
  `forChannel()` (`TemplateRenderer.php:153-167`) then **replaces** the body with
  `channel_renderings[sms|voice|ussd]` when an author wrote one, and **does not substitute into it**.
  A `{{site_name}}` in an SMS rendering therefore reaches the handset literally, braces included.
  (`{` and `}` are GSM extension characters, so each one costs two units.)
- Even in the body, only `alert_title`, `severity` and `organisation` are ever supplied.
  `AlertDispatcher::sendOne()` passes `[]` as `$variables` (`AlertDispatcher.php:115-121`), as does the
  estimate path (`AlertService.php:138`). `bcms_alerts` has no column to hold template variables.
  `{{site_name}}`, `{{assembly_point}}` and the rest are silently removed from the body, leaving
  sentences such as "Go to the assembly point at and wait for the roll-call".
- `organisation` is `config('app.name')`, which is the **product's** name, not the tenant's.
- **Shipped rows affected:** `EVACUATE` en and pcm (sms, voice, body), `ALLCLEAR`, `CRISISCONVENE`,
  `CALLTREEACT`, `ITOUTAGE`, `EXREMINDER`, `EXBLOCKED`, `CONTACTVERIFY`, `BCAWAREWEEK`,
  `LESSONSBULLETIN` and `NEWJOINERBC`: twelve rows across eleven codes. The life-safety one is
  `EVACUATE`, whose SMS today would read `EVACUATE {{site_name}} NOW. Nearest safe exit. No lifts.
  Assemble at {{assembly_point}}.` on a real phone. (`EXREMINDER`, `EXBLOCKED` and `CONTACTVERIFY`
  are not referenced by any code path under `app/` that I could find. They are exposed only when an
  operator sends one through EMNS.)
- **Owner:** backend-engineer, with the architect for where variables live. The fix has two parts:
  substitute into renderings as well as the body, and either give compose a way to supply template
  variables or rewrite the shipped templates without them. Until it lands, this pack uses **no
  placeholder in any channel rendering**, and only `{{alert_title}}` (which is always supplied) in
  subjects and advisory bodies.

### F-2. Truncation appends a character that is not GSM-7

`SmsSegmenter::truncate()` ends a cut message with `…` (U+2026) (`SmsSegmenter.php:127`). That
character is not in `GSM_BASIC` or `GSM_EXTENDED`, so **the cut message becomes UCS-2**. A message cut
to two GSM segments (305 characters plus the ellipsis) becomes 306 UCS-2 characters, which is **five**
segments. Cost rises and a longer concatenation is more likely to arrive out of order, which is exactly
what the cut was meant to prevent. A cut on a single USSD screen also flips encoding. **Owner:**
backend-engineer. The fix is to use three full stops, or no marker, when the body is GSM-7. Every
template here is sized so that truncation never triggers (§3).

---

## 3. How these templates are built

### 3.1 Two kinds of template

| Kind | Codes | SMS / voice | Where the specifics go |
|---|---|---|---|
| **Instruction** (life safety, or content that must be exact) | FIRE, FLOOD, ROBBERY, UNREST, CYBER, KIDNAP | **Static** `sms` and `voice` renderings with no placeholders. What they say is right at any site | `subject` carries `{{alert_title}}`, except KIDNAP (§3.4). The audience rule targets the site |
| **Advisory** (depends on *where* or *what*) | FUELPOWER, NETOUTAGE, PANDEMIC, PROTEST, ROADBLOCK | **No `sms` or `voice` rendering**, so every channel uses the substituted `body` | `body` is a short instruction plus `{{alert_title}}`, sized to be the SMS itself |

Advisory templates carry no SMS rendering precisely because of F-1: an override is not substituted, so
the only way for an SMS to say *which road* is to let the body, which is substituted, be the SMS.

### 3.2 Character budgets (GSM-7 throughout, one segment, truncation never triggers)

| Kind | Budget for the static text | Why |
|---|---|---|
| Instruction, life safety (FIRE, FLOOD, ROBBERY, UNREST) | **≤ 114** | 160 − 21 (`THIS IS AN EXERCISE. `) − 25 reserved for a reply token (`r-{id}-{16 hex}` with a space). `response_required` follows `is_life_safety` (`AlertService::compose()`), so these collect replies. **Today no SMS adapter appends the token** (`SmsGatewayChannel` sends `wireBody()` only), so the reserve is headroom, not a current cost. It is kept because ADR 0023 §4.2 budgets for one |
| Instruction, not life safety (CYBER, KIDNAP) | **≤ 139** | 160 − 21. No reply is collected |
| Advisory body | **≤ 94 plus a title of ≤ 45** | 160 − 21 − 45. **Operator rule, to be shown on the compose screen:** for these five templates, the alert title is ≤ 45 GSM-7 characters and names the place or the thing ("Ikorodu Road at Ojota", "Kano branches close 14:00"). If a title is longer, the message becomes two segments. Because the instruction comes first, the instruction still arrives |

Counts for every row are in §6. **No row uses a curly quote, an en or em dash, `…` or `₦`.**

### 3.3 Wording rules

- The instruction comes first. The first words say what to do or what is happening.
- There is one instruction per sentence, in short sentences with plain words.
- Life-safety SMS end with the reply codes the inbound parser already understands. `1` and `2` map to
  `SAFE` and `NEEDS_HELP` (`InboundResponseHandler::MENU_CODES`).
- The exercise prefix is **never** written into a template. `RenderedMessage` adds it to every
  simulation on every channel (standing rule 5), and the budgets above leave room for it.
- No person's name appears in any template, and none can be substituted in. §6 of the NDPA register
  already records that an operator can type a name into free text. For these templates, the only free
  text that reaches recipients is the title.

### 3.4 KIDNAP carries no title, on purpose

A kidnap advisory exists to stop details spreading. An operator under pressure is likely to type the
colleague's name into the title. So the KIDNAP subject is static and the template has no variables.
The composer's name for the case stays on the alert record, which is permission-controlled, and never
reaches staff phones.

### 3.5 Flags

| Code | `severity` | `life_safety` | `dual_approval` | Reasoning |
|---|---|---|---|---|
| FIRE | life_safety | yes | **yes** | Same as the shipped `EVACUATE`: an evacuation sent in error does real harm |
| FLOOD | life_safety | yes | no | "Go higher, do not walk through water" does little harm if sent in error. A delay could cost a life |
| ROBBERY | life_safety | yes | no | Speed matters, and the audience is nearby staff, not a whole bank. **Never send it to the branch under attack while the attack is under way**, because phones buzzing in a hold-up are a hazard. This rule belongs in the template guidance on screen |
| UNREST | life_safety | yes | no | "Stay inside" does little harm if sent in error |
| CYBER | critical | no | **yes** | A false cyber alarm to all staff is itself an incident. Email may be the compromised channel, so the recommended channel set is SMS and voice |
| KIDNAP | critical | no | **yes** | Confidential. A mistaken send cannot be recalled |
| FUELPOWER | urgent | no | no | Operational |
| NETOUTAGE | urgent | no | no | Operational. **It is sent by the channels that still work.** SMS to the failed network will not arrive, so the recommended channel set is email, Teams and voice |
| PANDEMIC | advisory | no | no | Respects quiet hours, which is correct for a health advisory |
| PROTEST | advisory | no | no | Usually sent days ahead. Raise the severity on the day if needed |
| ROADBLOCK | urgent | no | no | Time-sensitive |

`dual_approval` is the template's default. The tenant's `require_dual_approval_for_live` setting can
still require a second approver on anything. All eleven carry `clause: iso22301.8.4.3` (warning and
communication).

### 3.6 Seeder shape

The rows below use the existing `AlertTemplates::all()` keys (`code`, `locale`, `name`, `category`,
`severity`, `life_safety`, `dual_approval`, `subject`, `body`, `sms`, `voice`, `variables`, `clause`)
plus one recommended key, **`active`** (§1). `sms: null` and `voice: null` mean "no rendering; use the
body". The seeder's `array_filter` already drops nulls. **There is no `ussd` key.** USSD is deferred
out of Phase 12. The seeder already copies `sms` into the `ussd` rendering, and every static SMS here
fits one screen with the prefix added.

---

## 4. The 22 rows

### FIRE

```yaml
- code: FIRE
  locale: en
  name: "Fire: leave the building"
  category: fire
  severity: life_safety
  life_safety: true
  dual_approval: true
  subject: "FIRE: {{alert_title}}"
  body: "Fire. Leave the building now by the nearest safe exit. Do not use the lifts. Do not stop to collect belongings. Close doors behind you if it is safe to do so. Go to your assembly point and wait for the roll-call. Do not go back inside until you are told it is safe. Reply 1 if you are safe or 2 if you need help."
  sms: "FIRE. Leave now by the nearest safe exit. No lifts. Go to your assembly point. Reply 1 safe, 2 need help."
  voice: "This is an emergency message. There is a fire. Leave the building now by the nearest safe exit. Do not use the lifts. Go to your assembly point and wait for the roll-call. Press 1 if you are safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: FIRE
  locale: pcm
  name: "Fire: comot for building"
  category: fire
  severity: life_safety
  life_safety: true
  dual_approval: true
  subject: "FIRE: {{alert_title}}"
  body: "Fire dey. Comot from di building now through di nearest safe door. No enter lift. No stop to carry your load. If e safe, close door behind you. Go your assembly point wait make dem count you. No go back inside until dem tell you say e don safe. Reply 1 if you dey safe or 2 if you need help."
  sms: "FIRE. Comot now through di nearest safe door. No enter lift. Go your assembly point. Reply 1 safe, 2 need help."
  voice: "Dis na emergency message. Fire dey. Comot from di building now now through di nearest safe door. No enter lift. Go your assembly point and wait make dem count you. Press 1 if you dey safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false   # awaiting native-speaker review (section 7)
```

### FLOOD

```yaml
- code: FLOOD
  locale: en
  name: "Flood: do not go through water"
  category: flood
  severity: life_safety
  life_safety: true
  dual_approval: false
  subject: "FLOOD: {{alert_title}}"
  body: "Flooding. Do not walk or drive through flood water. It can be deeper and faster than it looks, and it can hide open drains. If water is rising where you are, move to higher ground or an upper floor. If you are safe where you are, stay there until you are told it is safe to travel. Reply 1 if you are safe or 2 if you need help."
  sms: "FLOOD. Do not walk or drive through flood water. If water is rising, go higher. Reply 1 safe, 2 need help."
  voice: "This is an emergency message. There is flooding. Do not walk or drive through flood water. If water is rising where you are, move to higher ground or an upper floor. If you are safe where you are, stay there. Press 1 if you are safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: FLOOD
  locale: pcm
  name: "Flood: no waka pass water"
  category: flood
  severity: life_safety
  life_safety: true
  dual_approval: false
  subject: "FLOOD: {{alert_title}}"
  body: "Flood dey. No waka or drive pass flood water. E fit deep pass how e be, and e fit hide open gutter. If water dey rise where you dey, go higher place or upstairs. If you dey safe where you dey, stay there until dem tell you say e safe to move. Reply 1 if you dey safe or 2 if you need help."
  sms: "FLOOD. No waka or drive pass flood water. If water dey rise, go higher place. Reply 1 safe, 2 need help."
  voice: "Dis na emergency message. Flood dey. No waka or drive pass flood water. If water dey rise where you dey, go higher place or upstairs. If you dey safe where you dey, stay there. Press 1 if you dey safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### ROBBERY

```yaml
- code: ROBBERY
  locale: en
  name: "Armed attack nearby: stay inside"
  category: security
  severity: life_safety
  life_safety: true
  dual_approval: false
  subject: "DANGER: {{alert_title}}"
  body: "An armed attack has been reported near you. Stay inside. Lock the doors and keep away from windows and glass. Do not go near the area and do not travel towards it. Do not post about it or share images. Wait for further instructions. Reply 1 if you are safe or 2 if you need help."
  sms: "DANGER. Armed attack nearby. Stay inside, away from windows. Do not go near the area. Reply 1 safe, 2 need help."
  voice: "This is an emergency message. An armed attack has been reported near you. Stay inside. Lock the doors and keep away from windows. Do not go near the area. Wait for further instructions. Press 1 if you are safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: ROBBERY
  locale: pcm
  name: "Armed robbers dey near: stay inside"
  category: security
  severity: life_safety
  life_safety: true
  dual_approval: false
  subject: "DANGER: {{alert_title}}"
  body: "Armed robbers dey near you. Stay inside. Lock door and no stand near window or glass. No go near dat area and no travel go there. No post am or share picture. Wait for more instruction. Reply 1 if you dey safe or 2 if you need help."
  sms: "DANGER. Armed robbers dey near. Stay inside, far from window. No go near dat area. Reply 1 safe, 2 need help."
  voice: "Dis na emergency message. Armed robbers dey near you. Stay inside. Lock door and no stand near window. No go near dat area. Wait for more instruction. Press 1 if you dey safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### UNREST

```yaml
- code: UNREST
  locale: en
  name: "Unrest nearby: stay inside"
  category: civil_unrest
  severity: life_safety
  life_safety: true
  dual_approval: false
  subject: "UNREST: {{alert_title}}"
  body: "There is unrest near you. Stay inside and lock the doors. Keep away from windows. Do not go out until you are told it is safe, and keep away from crowds. If you must move, follow your manager's instructions on the safe route. Reply 1 if you are safe or 2 if you need help."
  sms: "UNREST NEARBY. Stay inside. Lock doors. Do not go out until told it is safe. Reply 1 safe, 2 need help."
  voice: "This is an emergency message. There is unrest near you. Stay inside and lock the doors. Do not go out until you are told it is safe. Keep away from crowds. Press 1 if you are safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: UNREST
  locale: pcm
  name: "Wahala dey near: stay inside"
  category: civil_unrest
  severity: life_safety
  life_safety: true
  dual_approval: false
  subject: "WAHALA: {{alert_title}}"
  body: "Wahala dey near you. Stay inside and lock door. No stand near window. No go outside until dem tell you say e safe, and no go near crowd. If you must move, follow wetin your manager talk about di safe road. Reply 1 if you dey safe or 2 if you need help."
  sms: "WAHALA DEY NEAR. Stay inside. Lock door. No go outside until dem tell you say e safe. Reply 1 safe, 2 need help."
  voice: "Dis na emergency message. Wahala dey near you. Stay inside and lock door. No go outside until dem tell you say e safe. No go near crowd. Press 1 if you dey safe. Press 2 if you need help."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### CYBER

```yaml
- code: CYBER
  locale: en
  name: "Cyber incident: protect your access"
  category: cyber
  severity: critical
  life_safety: false
  dual_approval: true
  subject: "Cyber incident: {{alert_title}}"
  body: "We are dealing with a cyber incident. Do not open unexpected emails, attachments or links. Never share your password or any one-time code, even with someone who says they are from IT. Report anything unusual to IT security now. Do not discuss the incident outside the organisation."
  sms: "CYBER INCIDENT. Do not open unexpected emails or links. Never share passwords or codes. Report anything unusual to IT security now."
  voice: "This is an urgent security message. There is a cyber incident. Do not open unexpected emails or links. Never share your password or any code. Report anything unusual to IT security now."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: CYBER
  locale: pcm
  name: "Cyber attack: guard your access"
  category: cyber
  severity: critical
  life_safety: false
  dual_approval: true
  subject: "Cyber attack: {{alert_title}}"
  body: "We dey handle cyber attack. No open any strange email, attachment or link. No give anybody your password or any one-time code, even if dem say dem be IT. Report anything strange to IT security now. No talk about am outside di company."
  sms: "CYBER ATTACK. No open any strange email or link. No give anybody your password or code. Report anything strange to IT security now."
  voice: "Dis na urgent security message. Cyber attack dey. No open any strange email or link. No give anybody your password or any code. Report anything strange to IT security now."
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### KIDNAP

```yaml
- code: KIDNAP
  locale: en
  name: "Security advisory: colleague incident"
  category: security
  severity: critical
  life_safety: false
  dual_approval: true
  subject: "Security advisory"
  body: "A security incident involves one of our colleagues. For their safety, do not post, share or discuss any details, on social media or anywhere else. Do not speak to the media. If you have any information, give it only to Security. Follow any travel instructions you receive."
  sms: "SECURITY ADVISORY. An incident involves a colleague. Do not post or share details. Do not speak to the media. Report to Security."
  voice: "This is a security message. An incident involves a colleague. Do not post or share any details. Do not speak to the media. Report any information to Security."
  variables: []
  clause: iso22301.8.4.3
  active: true

- code: KIDNAP
  locale: pcm
  name: "Security notice: colleague matter"
  category: security
  severity: critical
  life_safety: false
  dual_approval: true
  subject: "Security notice"
  body: "Security matter dey wey concern one of our colleague. Because of dia safety, no post, share or talk anything about am, for social media or anywhere. No talk to media. If you know anything, tell only Security. Follow any travel instruction wey dem give you."
  sms: "SECURITY NOTICE. Something happen to one of our colleague. No post or share anything about am. No talk to media. Report to Security."
  voice: "Dis na security message. Something happen to one of our colleague. No post or share anything about am. No talk to media. Tell Security anything wey you know."
  variables: []
  clause: iso22301.8.4.3
  active: false
```

### FUELPOWER (advisory: the body is the SMS)

```yaml
- code: FUELPOWER
  locale: en
  name: "Power and fuel update"
  category: power
  severity: urgent
  life_safety: false
  dual_approval: false
  subject: "Power and fuel update: {{alert_title}}"
  body: "POWER/FUEL UPDATE: {{alert_title}}. Check your shift with your manager before you travel."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: FUELPOWER
  locale: pcm
  name: "Light and fuel matter"
  category: power
  severity: urgent
  life_safety: false
  dual_approval: false
  subject: "Light and fuel matter: {{alert_title}}"
  body: "LIGHT AND FUEL MATTER: {{alert_title}}. Confirm your shift with your manager before you comot for house."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### NETOUTAGE (advisory)

```yaml
- code: NETOUTAGE
  locale: en
  name: "Network outage: use your backup contact method"
  category: telecom
  severity: urgent
  life_safety: false
  dual_approval: false
  subject: "Network outage: {{alert_title}}"
  body: "NETWORK OUTAGE: {{alert_title}}. If calls or SMS fail, use the backup contact method in your plan."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: NETOUTAGE
  locale: pcm
  name: "Network wahala: use your backup way"
  category: telecom
  severity: urgent
  life_safety: false
  dual_approval: false
  subject: "Network wahala: {{alert_title}}"
  body: "NETWORK WAHALA: {{alert_title}}. If call or SMS no dey go, use di backup way wey dey your plan."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### PANDEMIC (advisory)

```yaml
- code: PANDEMIC
  locale: en
  name: "Health advisory"
  category: pandemic
  severity: advisory
  life_safety: false
  dual_approval: false
  subject: "Health advisory: {{alert_title}}"
  body: "HEALTH ADVISORY: {{alert_title}}. If you have symptoms, stay home and tell your manager today."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: PANDEMIC
  locale: pcm
  name: "Health matter"
  category: pandemic
  severity: advisory
  life_safety: false
  dual_approval: false
  subject: "Health matter: {{alert_title}}"
  body: "HEALTH MATTER: {{alert_title}}. If you get symptoms, stay for house, tell your manager today."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### PROTEST (advisory)

```yaml
- code: PROTEST
  locale: en
  name: "Protest advisory: avoid the area"
  category: civil_unrest
  severity: advisory
  life_safety: false
  dual_approval: false
  subject: "Protest advisory: {{alert_title}}"
  body: "PROTEST ADVISORY: {{alert_title}}. Avoid the area. Follow your manager's travel and closing-time advice."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: PROTEST
  locale: pcm
  name: "Protest notice: no go dat area"
  category: civil_unrest
  severity: advisory
  life_safety: false
  dual_approval: false
  subject: "Protest notice: {{alert_title}}"
  body: "PROTEST NOTICE: {{alert_title}}. No go dat area. Follow wetin your manager talk."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

### ROADBLOCK (advisory)

```yaml
- code: ROADBLOCK
  locale: en
  name: "Road blocked: do not use this route"
  category: transport
  severity: urgent
  life_safety: false
  dual_approval: false
  subject: "Road blocked: {{alert_title}}"
  body: "ROAD BLOCKED: {{alert_title}}. Do not use this route. Delay travel or use a route your manager confirms."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: true

- code: ROADBLOCK
  locale: pcm
  name: "Road block: no pass dat road"
  category: transport
  severity: urgent
  life_safety: false
  dual_approval: false
  subject: "Road block: {{alert_title}}"
  body: "ROAD BLOCK: {{alert_title}}. No pass dat road. Wait, or use another road wey your manager approve."
  sms: null
  voice: null
  variables: [alert_title]
  clause: iso22301.8.4.3
  active: false
```

---

## 5. Pending slots: external prerequisite X2

Each cell below is a template **not authored in this repository**. It must not be produced by any
model, including the alert composer (ADR 0023 §4.2).

| Code | `ha` Hausa | `yo` Yoruba | `ig` Igbo |
|---|---|---|---|
| FIRE | PENDING: human translator + native reviewer | PENDING | PENDING |
| FLOOD | PENDING | PENDING | PENDING |
| ROBBERY | PENDING | PENDING | PENDING |
| UNREST | PENDING | PENDING | PENDING |
| FUELPOWER | PENDING | PENDING | PENDING |
| NETOUTAGE | PENDING | PENDING | PENDING |
| CYBER | PENDING | PENDING | PENDING |
| PANDEMIC | PENDING | PENDING | PENDING |
| KIDNAP | PENDING | PENDING | PENDING |
| PROTEST | PENDING | PENDING | PENDING |
| ROADBLOCK | PENDING | PENDING | PENDING |

**Brief for the translator.** Translate from the English row, not the Pidgin one. Give a separate
SMS rendering within the same §3.2 budget, **computed for the encoding the text actually uses**. Hausa,
Yoruba and Igbo orthography often needs characters outside GSM-7 (for example Hausa hooked letters
and Yoruba and Igbo tone marks and dotted vowels). That forces UCS-2, where a single segment is 70
characters, not 160. So either the SMS is written as a separate short text within 70 − 21 = 49
characters (a reply-collecting row also needs its token reserve), or a deliberate choice is made to
use two segments, recorded on the row. `TemplateRenderer::inspect()` shows the real count, and the
reviewer signs off on the rendered text on a real handset. Rows load with `is_active = false`, and a
human activates them.

## 6. Character counts (static text, GSM-7, as written)

| Row | Rendering used for SMS | Static characters | With exercise prefix (+21) | Budget | Fits one segment incl. token reserve / title |
|---|---|---|---|---|---|
| FIRE en | `sms` | 105 | 126 | ≤ 114 | yes (126 + 25 = 151) |
| FIRE pcm | `sms` | 111 | 132 | ≤ 114 | yes (157) |
| FLOOD en | `sms` | 106 | 127 | ≤ 114 | yes (152) |
| FLOOD pcm | `sms` | 104 | 125 | ≤ 114 | yes (150) |
| ROBBERY en | `sms` | 112 | 133 | ≤ 114 | yes (158) |
| ROBBERY pcm | `sms` | 109 | 130 | ≤ 114 | yes (155) |
| UNREST en | `sms` | 103 | 124 | ≤ 114 | yes (149) |
| UNREST pcm | `sms` | 112 | 133 | ≤ 114 | yes (158) |
| CYBER en | `sms` | 131 | 152 | ≤ 139 | yes |
| CYBER pcm | `sms` | 131 | 152 | ≤ 139 | yes |
| KIDNAP en | `sms` | 129 | 150 | ≤ 139 | yes |
| KIDNAP pcm | `sms` | 132 | 153 | ≤ 139 | yes |
| FUELPOWER en | `body` | 74 + title | 95 + title | ≤ 94 | yes, with a title ≤ 45 (140) |
| FUELPOWER pcm | `body` | 89 + title | 110 + title | ≤ 94 | yes (155) |
| NETOUTAGE en | `body` | 83 + title | 104 + title | ≤ 94 | yes (149) |
| NETOUTAGE pcm | `body` | 80 + title | 101 + title | ≤ 94 | yes (146) |
| PANDEMIC en | `body` | 79 + title | 100 + title | ≤ 94 | yes (145) |
| PANDEMIC pcm | `body` | 78 + title | 99 + title | ≤ 94 | yes (144) |
| PROTEST en | `body` | 89 + title | 110 + title | ≤ 94 | yes (155) |
| PROTEST pcm | `body` | 65 + title | 86 + title | ≤ 94 | yes (131) |
| ROADBLOCK en | `body` | 89 + title | 110 + title | ≤ 94 | yes (155) |
| ROADBLOCK pcm | `body` | 83 + title | 104 + title | ≤ 94 | yes (149) |

"Static characters" for advisory rows counts everything except `{{alert_title}}`, including the space
before it. I counted by hand, word by word. **The authoritative check is a 12A-2 test** that renders
each row through `TemplateRenderer` with `is_simulation = true` and a 45-character title, and asserts
`SmsSegmenter::segments() === 1` and `isGsm7() === true`. If my arithmetic and the test disagree, the
test is right.

## 7. Native-speaker review of the Pidgin rows

| Row | Reviewer (native Nigerian Pidgin speaker) | Date | Changes | Activated by / on |
|---|---|---|---|---|
| FIRE pcm | | | | |
| FLOOD pcm | | | | |
| ROBBERY pcm | | | | |
| UNREST pcm | | | | |
| FUELPOWER pcm | | | | |
| NETOUTAGE pcm | | | | |
| CYBER pcm | | | | |
| PANDEMIC pcm | | | | |
| KIDNAP pcm | | | | |
| PROTEST pcm | | | | |
| ROADBLOCK pcm | | | | |

**What the reviewer should check beyond the words:**

- **The voice scripts must be listened to through the configured TTS voice.** An English TTS engine
  reading "di", "dey" and "comot" may be understood, or it may not. That can only be judged by
  listening.
- **Re-count after any edit.** A reviewer's change to an SMS must stay within §3.2.

## HANDOFF

**Phase:** BCMS Phase 12, stage 0 (content)
**Agent:** compliance-analyst
**Status:** complete for `en`. `pcm` is authored and awaiting native review. `ha`, `yo` and `ig` are pending (X2).
**Delivered:** this file: 22 rows (11 × `en`, 11 × `pcm`), 33 pending slots, a translator brief, and a count table.
**Clause refs published:** none. Every row uses `iso22301.8.4.3`.
**Contracts touched:** recommends an `active` key in `AlertTemplates` and setting `is_active` only on create in `seedAlertTemplates()` (§1). No `ussd` key, because USSD is deferred.
**Assumptions made:** `dual_approval` defaults per §3.5 are my recommendation, and the tenant setting can override them. The 25-character token reserve follows ADR 0023 §4.2, although no SMS adapter appends a token today.
**Known gaps:** F-1 and F-2 (§2) are defects in shipped code that affect twelve shipped rows (eleven codes), including the life-safety `EVACUATE`. Owner: backend-engineer. Nobody has reviewed the Pidgin.
**Next agent:** backend-engineer at 12A-2 (conversion, the §6 assertion test, and F-1/F-2). The owner decides on the `active` recommendation.
**Verification run:** read `TemplateRenderer.php`, `RenderedMessage.php`, `SmsSegmenter.php`, `AlertDispatcher.php:107-121`, `AlertService.php:60-96,138`, `SmsGatewayChannel.php:56-60`, `InboundResponseHandler.php:37-106` and `BcmsReferenceSeeder.php:194-219` on 2026-09-24. Counted every SMS and advisory body by hand (§6).
