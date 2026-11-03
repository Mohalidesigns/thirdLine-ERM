# 09. Email: monthly KRI request

**Mailable:** `App\Mail\KriReturnRequested(KriReturnNoticeRecipient)` (ADR §17), sent from `SendKriReturnNoticeMail` on the first working day of the month to the department's **risk champions** (tier 1) · Read `00-common.md` first.

## 1. Purpose and the reader

**Who:** a risk champion in a department: a first-line employee who is not a risk specialist, reading on a phone, probably in Gmail or Outlook mobile, possibly on a slow link, once a month.

**What they must know in five seconds, from the inbox preview alone:** which department, which month's readings, and when they're due. **What they must be able to do:** tap one button and land in their return.

**This is the only request email.** A return gets exactly one (idempotency key `request:r{id}`; ADR §5). It is not a reminder; reminders are manual and are screen 10.

## 2. Data the template may use (ADR §17; nothing else)

From the return and the recipient row: recipient `name`; `contact_role` (the person's lowest-tier role in this department); department name; period label (the month collected); `due_on`; the KRIs due (code, name); the deep link to `risk.kri.my-return` with `{department}/{period}`. The ADR lists no sender name, no reply address and no counts beyond the KRI list. The template invents none.

## 3. Envelope

| Part | Content |
|---|---|
| **Subject** | `{Department}: KRI readings for {Period} due {Weekday D Mon}`. Example: `Internal Audit: KRI readings for October 2026 due Mon 9 Nov`. Under about 70 characters where the department name allows; the department comes first because it is what the reader filters on. Not "Action required" or other alarm words; this is routine. |
| **Preheader** (hidden preview text, about 90 characters) | `{N} KRIs to complete in Complete my KRIs. Save a draft any time; nothing counts until you submit.` (N = the number of KRIs listed.) |
| **From / Reply-To** | From the application's mail configuration. The ADR does not decide whether replies are monitored (gap 1), so the template carries no "reply to this email" instruction. |
| **Parts** | `text/html` and a `text/plain` alternative with the same content in the same order. |

## 4. Body, in order (single column, 600 px max)

1. **Header band** (Navy `#1A365D`, white text, 16 px bold): the application name as **text** (no logo image). 
2. **Greeting:** "Hello {name},"
3. **Lead (one sentence, 18 px):** "Your KRI readings for **{Period}** are due on **{Weekday D Month YYYY}**." Then one line: "This is the monthly request for **{Department}**."
4. **Primary button:** **Complete my KRIs** (bulletproof table-cell button, Navy `#1A365D` background, white 16 px bold text, 14 px / 24 px padding, so at least 44 px tall) linking to the deep link. Directly under it, the plain URL in small text: "Or copy this link: {url}" (for clients that block buttons and for the plain-text part).
5. **What's in it:** heading "KRIs to complete ({N})". A two-column list: **Code** (mono) and **Indicator** (name). Cap at 25 rows; beyond that "…and {N − 25} more in your return." If the list is somehow empty: "No KRIs are currently due. Open your return to check." (The cycle never sends a request for a return with no lines, so this is a defensive line.)
6. **Three short facts** (a plain list, no icons):
   - "Enter each KRI's value. You can **save a draft** and come back; nothing counts until you press **Submit return**."
   - "If a value falls in the red band you'll be asked for the root cause, an action point and a date."
   - "You can reopen and correct a submitted return until the month is closed." 
7. **Why you got this (alert-fatigue rule: a visible reason):** "You're receiving this because you are the **{role label}** for {Department}. If that's wrong, tell your Risk Office so the department's contacts can be updated." Role label from `contact_role` (Risk champion).
8. **Footer** (12 px, `#4B5563`): "You'll be asked to sign in." and the application name. No unsubscribe link: this is an operational message tied to a role, not marketing. (Opting out would be changing the role in Department KRI Contacts.)

**One clear action.** One button. No secondary links in the body other than the plain-URL fallback. No tracking pixel or tracked links (delivery isn't tracked beyond "handed to the mail server", ADR §5, and a pixel is a bandwidth cost and a privacy question).

## 5. Link behaviour
- Opens `risk.kri.my-return/{department}/{period}`. Not signed in: sign-in first, then the intended URL (framework redirect). The destination handles every state in screen 07 (not a champion, return not open, already submitted).
- The link's period segment pins the return even if opened weeks later.

## 6. Visual spec (email-safe)

| Item | Value |
|---|---|
| Layout | Table-based, `role="presentation"`, `width="100%"` with a 600 px inner table; no CSS grid/flex |
| Fonts | `Arial, Helvetica, sans-serif` (web fonts like Figtree don't load reliably and cost bytes); mono for codes: `'Courier New', monospace` |
| Body | 16 px / 24 px, `#2D3748` on `#FFFFFF` (about 10:1) |
| Secondary text | `#4B5563` (about 7.6:1), minimum 12 px |
| Links | Navy `#1A365D`, underlined |
| Button | `#1A365D` background, `#FFFFFF` text (about 12:1) |
| Header band | `#1A365D` with white text |
| Gold `#D4AF37` | Not used for text; allowed only as a thin 3 px rule under the header band (decorative) |
| Dark mode | `<meta name="color-scheme" content="light dark">` with explicit `bgcolor` attributes so clients that invert still keep the button text legible; the button's text and background are a pair set together |
| Images | None. Zero image requests, so nothing is blocked and nothing downloads on a slow link |
| Size | Under 20 KB of HTML (Gmail clips messages near 102 KB) |

## 7. Accessibility (email)
- `<html lang="en">`; a real `<title>` equal to the subject; headings as `h1`/`h2` inside the table cells (one `h1`: the lead sentence).
- Descriptive link text ("Complete my KRIs", never "click here"). The raw URL appears once as text.
- Reading order equals visual order; layout tables carry `role="presentation"`; the KRI list is a real two-column data table with `<th scope="col">`.
- No information in colour alone (nothing here is colour-coded). Contrast as in section 6.
- Text is real text at a minimum of 12 px, scalable (no fixed-height cells that clip enlarged text).
- Plain-text part is complete, with the URL on its own line.

## 8. States and edge cases

| Case | Behaviour |
|---|---|
| **Normal** | As above. |
| **Long department name** | Wraps in the subject (client truncates) and the body; no fixed widths. |
| **Many KRIs** | Capped list at 25 with the "and N more" line. |
| **Zero KRIs** | Defensive sentence (section 4, item 5). |
| **Recipient with several roles** | Deduplicated (ADR §2): one email, role shown is the lowest tier. |
| **Inactive user / no email** | No email is sent; the row is `skipped` (screen 04 history shows why). |
| **Department with no champion** | No email exists; the cycle records `no_recipients` and the dashboard shows a configuration gap (03/04). |
| **Transport problem** | No email; per-recipient `failed` outcome (screen 04). |
| **`MAIL_MAILER=log`** | The email is written to the log, not sent; the cycle command warns (ADR §12). Not a state the email can display. |
| **Link opened by someone without the permission** | They reach screen 07's 403 or "not set up" state. |
| **Opened a month later** | The link still opens that month's return. |
| **Mail client blocks images** | No images exist. |

## 9. Low bandwidth
About 10 to 15 KB of HTML, no remote assets, no web font, no tracking. On a 100 kbps link the message downloads in about 1 to 1.5 s and renders fully; the plain-text alternative is under 3 KB. The deep link goes to screen 07, which is designed for a slow link (07 section 8).

## 10. The numbers in the email

| Figure | Source |
|---|---|
| Period (month collected) | `period.label` of the return's reporting period (the **previous** month, ADR §0) |
| Due date | `kri_returns.due_on` |
| KRIs listed and N | The return's lines (`kri_return_lines`), code and name |
| Role label | `kri_return_notice_recipients.contact_role` |
| Department | `business_units.name` |

No count is shown that isn't one of these. (A "{x} of {N} already have a value" figure is deliberately **not** in the request: nothing is entered yet at the opening.)

## 11. Testing the template
Render with the log mailer and in a mail catcher; check Gmail (web and Android), Outlook mobile and a plain-text-only client. Check the subject at three department-name lengths and a 30-KRI return. Include a dark-mode pass. The automated suite uses `Mail::fake()`, which cannot check any of this, so it is a manual step in the live test (brief section 5).

## 12. Known gaps
1. **Sender identity and reply handling** are undecided (From name, Reply-To, whether replies are read). The template says nothing about replying until that is settled.
2. **The ADR names no "who to contact" line.** The wording "tell your Risk Office" is generic on purpose; if the bank has a mailbox, add it here.
