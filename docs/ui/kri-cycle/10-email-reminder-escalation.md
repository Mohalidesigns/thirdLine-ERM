# 10. Email: reminder and escalation (tiered)

**Mailable:** `App\Mail\KriReturnReminder(KriReturnNoticeRecipient)` (ADR §17), sent by `SendKriReturnNoticeMail` after a Risk Officer presses Send reminder on screen 04. Recipients are resolved **at send time** from the department's contacts for the notice's tier · Read `00-common.md` and `09-email-monthly-request.md` first (the visual spec, accessibility rules and sizes in 09 section 6 to 7 apply unchanged).

## 1. Purpose and the readers

There is **one template with four voices**, chosen by the **recipient's own role** (`contact_role`, the lowest-tier role that person holds in the department) and informed by the reminder's tier (the notice's `tier`, which is `min(sequence, 4)`).

| Reader | Tier at which they start receiving | What they need |
|---|---|---|
| **Risk champion** | 1 (every reminder) | The job: complete and submit. One button into the return. |
| **Team lead** | 2 | Know the return is late and that the champion has been reminded; ability to follow up. |
| **Unit head** | 3 | Know it's late, how many times it's been chased, that it's reached them. |
| **Group head** | 4 and later | Same, plainly: this is the highest level; the return is overdue (if it is) and who to ask. |

A reminder is **manual**: a person chose to send it. The email says that a reminder was sent, never "automatic".

**Alert-fatigue rules (the product's biggest risk):** one email per person per notice, however many roles they hold; a visible reason for receiving it; one action; no repeat of the whole monthly request. A champion who receives reminder 2 also appears in tier 2's cumulative recipients, so the champion's voice remains the "do the work" voice.

## 2. Data the template may use (ADR §17 plus the notice and recipient rows)

Department; period label; the KRIs due (code, name); `due_on`; days overdue (reminder only; calculated at send time); the deep link; recipient `name`; recipient `contact_role`; the notice's `sequence` (reminder number) and `tier`. Nothing else. The ADR does not list the sender's name, a count of completed KRIs, or other recipients' names, so none appears (gap 2).

## 3. Envelope

Subject variants, chosen by state at send time (the first match):

| State | Subject |
|---|---|
| Past due (`days_overdue` > 0) | `Overdue: {Department} KRI return for {Period} ({N} days)` |
| Not yet past due | `Reminder: {Department} KRI return for {Period} is due {Weekday D Mon}` |

"({N} days)" uses "1 day" singular. Under about 70 characters where possible. No exclamation marks, no capital-letter shouting; "Overdue" is the only strong word and it is factual.

**Preheader:** champion: `Complete and submit it in Complete my KRIs. Reminder {sequence}.` Other tiers: `Reminder {sequence}: the return has not been submitted. You are receiving this as {role label}.`

Same From/Reply-To caveat as 09.

## 4. Body, in order

Common to all voices:

1. **Header band** (as 09).
2. **Greeting** "Hello {name},".
3. **Status lead (18 px):** two forms.
   - Past due: "The KRI return for **{Department}** for **{Period}** has not been submitted. It was due on **{Weekday D Month YYYY}** and is now **{N} day{s} overdue**."
   - Not yet due: "The KRI return for **{Department}** for **{Period}** has not been submitted. It is due on **{Weekday D Month YYYY}**."
4. **Reminder marker (one line, 14 px):** "This is reminder **{sequence}**." and, from reminder 2: "Earlier reminders went to {the roles reached before}." generated from the tier table (below), not from names.
5. **Voice block** (differs by `contact_role`; copy below).
6. **Primary button** (see section 5 for the target) and the plain URL fallback.
7. **KRIs in the return ({N}):** the same Code / Indicator table as 09, capped at 25 with "…and {N − 25} more".
8. **Why you got this:** "You're receiving this because you are the **{role label}** for {Department}. A reminder has been sent by the Risk Office." (Never names the sender; see gap 2.) A person holding more than one role: "You are listed as {lowest role label}" only (ADR dedup).
9. **Who else was reminded**, at role level only, so nobody guesses: "This reminder went to: {tier words}." (From the 00-common tier table: "risk champions and team leads", ...).
10. **Footer** as 09.

### 4.1 Voice blocks

| `contact_role` | Block |
|---|---|
| **Risk champion** | "Please complete the return and press **Submit return**. You can save a draft and carry on later; nothing counts until you submit. If a value is in the red band you'll be asked for the root cause, an action point and a date. If you can't complete it, tell your team lead." |
| **Team lead** | "The department's risk champions have been asked to complete it. There's nothing for you to enter. If it is stuck, you may want to follow up with them or check who should be covering." |
| **Unit head** | "This return has now been reminded {sequence} time(s) and has reached unit-head level. The risk champions and team leads have been asked to complete it. There's nothing for you to enter." |
| **Group head** | "This return has been reminded {sequence} time(s), the highest escalation level. Risk champions, team leads and unit heads have been asked to complete it. There's nothing for you to enter." |

Tone: factual, no blame, no instruction to chase a named person (the product knows roles, not whom to blame). "There's nothing for you to enter" is true for non-champions and prevents a pointless click.

## 5. Link target (this decides whether the button works)

ADR §17 gives one deep link: `risk.kri.my-return` with `{department}/{period}`. That route needs `kri.submit_return` and requires the user to be a **risk champion of that exact department** (ADR §10, `championUnitIdsFor`, no descendant expansion). A team lead, unit head or group head is none of those, so for them the ADR's link ends on a 403 or the "not set up" state of screen 07. That is a broken action in the very email meant to escalate.

**Specified behaviour:**
- Risk champion: button **Complete my KRIs** → `risk.kri.my-return/{department}/{period}`.
- Team lead, unit head, group head: button **View the return** → the read-only return `risk.kri.returns.show` for this return **if the recipient holds `kri.view` and the department is in their scope** (ADR §10 gives that view `kri.view` + `unitIdsFor`). If the template cannot establish that, it **omits the button** and the plain-URL line, and the voice block carries the only call to action (which is "nothing to enter"). It never links to a page the recipient will be refused.
- This needs the backend to give the mailable the return id (it has the notice → return) and to evaluate permission at send time. **This is the top gap** (section 10).

## 6. States and edge cases

| Case | Behaviour |
|---|---|
| **Reminder 1, past due** | Lead "now N days overdue"; champion voice. |
| **Reminder 1, not yet due** | "is due on"; champion voice. |
| **Reminder 5 or more** | `tier` = 4; marker "This is reminder 5."; the "earlier reminders" line says "all four levels". |
| **Recipient holds several roles** | One email, lowest role's voice; "listed as {role}". |
| **Return submitted between queueing and sending** | Backend question (gap 3). Sending a "not submitted" reminder for a submitted return would be wrong. Recommended: the job re-checks `status` and marks the recipient `skipped`. Until confirmed, the template must not render for a submitted return. |
| **Department had no champion** | A champion-voice email can't exist; tier 2+ recipients still get theirs. The screen 04 confirmation warns the Risk Officer. |
| **Inactive / no email** | Skipped, not sent (visible on screen 04). |
| **Days overdue = 0 on the due date** | Treated as "not yet past due" (the return is overdue only after `due_on`). |
| **Long names / many KRIs** | As 09. |
| **Opened after the return is submitted** | The link shows the submitted return (screen 07 or the read-only view). |

## 7. Accessibility, visual, low bandwidth
All as 09 sections 6, 7 and 9. Additionally: the days-overdue emphasis is bold text plus the word "overdue", never colour alone (no red text is required; if red `#B91C1C` is used for "overdue" it is paired with the word and meets about 6:1 on white). Subject and preheader are written to be understood without the body, for people who read only the notification. Size stays under 20 KB, no images.

## 8. The numbers in the email

| Figure | Source |
|---|---|
| Days overdue | Calendar days after `kri_returns.due_on` in Africa/Lagos, calculated when the email is rendered (ADR §17 "the days overdue (reminder only)") |
| Reminder number | `kri_return_notices.sequence` |
| Roles reached | `kri_return_notices.tier` mapped through the 00-common table |
| KRI list and N | `kri_return_lines` of the return |
| Due date, period, department | `kri_returns.due_on`, the period label, `business_units.name` |
| Role label | `kri_return_notice_recipients.contact_role` |

## 9. Testing
As 09 section 11. Add one render per voice (four), per subject variant (two), and reminder 5. The live test (brief section 5, step 6) confirms the champion voice and the outcome in screen 04's history.

## 10. Known gaps (also in the handoff)
1. **Non-champion link target** (section 5). Blocker for tiers 2 to 4: the ADR's single `my-return` link is a 403 for them.
2. **No sender name, no completed-count, no other-recipient names** in the ADR's content list. The email says "the Risk Office" and role-level reach. An optional "{x} of {N} KRIs have a value" line would help the champion voice; it needs the backend to supply it.
3. **Submitted-in-the-meantime check** at send time is not stated in the ADR for the reminder job (it is stated for the endpoint: outcome `submitted`).
