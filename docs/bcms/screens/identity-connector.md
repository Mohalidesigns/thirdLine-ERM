# Screen spec — `Bcms/Settings/Identity` (the Entra connector)

**Route:** `GET bcms/settings/identity` → `bcms.settings.identity`, `PUT bcms/settings/identity` →
`bcms.settings.identity.update`, `POST bcms/settings/identity/test` → `bcms.settings.identity.test`,
`POST bcms/settings/identity/sync` → `bcms.settings.identity.sync` — all four
`middleware('permission:bcms.identity.manage')`. Registered **before** the wildcard section route
in the existing `settings` group (ADR 0018 §5, work order §5) — `identity` is a second area beside
`settings`, not a new nav item, not a new `ModuleSections` entry.

**Reads:** `docs/adr/0018-bcms-phase-2c-is-entra-only.md` §2.2, §3, §5, §7;
`docs/bcms/phase-2c-entra-work-order.md` §3–§8. This document does not restate the schema; it
specifies what renders it, in what state, and what a person who is not a developer needs to see
before they hand a bank's whole staff directory to a screen.

**Siblings read before writing this:** `resources/js/Pages/Bcms/Settings.jsx` (the one BCMS
settings page today — plain sectioned `<form>`s, `useForm`, the `error()` inline-helper pattern,
`recentlySuccessful` for the save confirmation — this screen is its second tab/adjacent page, same
voice, same component shapes, no new primitive invented for a save button). `docs/tprm/screens/
ai-settings.md` (the three-tone banner convention — `red-50`/`900` blocked, `amber-50`/`900`
attention-needed, `emerald-50`/`900` confirmed-working — and the rule that a disabled control
states its reason as visible text, not only in a tooltip). `resources/js/Pages/Bcms/CallTrees/
Live.jsx` (the 5-second JSON poll that starts only while a background process is running and stops
the moment it is not — this screen's "sync in progress" indicator borrows that shape, not
`useJobProgress`, because the run this screen tracks lives in `bcms_identity_sync_runs`, not the
ERM-core `JobRun` table `useJobProgress` polls).

---

## 1. Purpose and the user

A `bcms.identity.manage` holder — in practice the same chief-risk-officer/super-admin persona who
already reaches `Settings.jsx` — opens this screen in one of three circumstances: first-time setup
of the Entra connector, routine health-checking ("did last night's sync run?"), or troubleshooting
after someone reports a call tree with the wrong manager. In the first ten seconds they must be able
to tell: **is a connector configured at all**, and if so, **is it healthy** (last run succeeded,
credential not about to expire) or **is something wrong** (last run failed, secret expires this
month, no run has ever completed). This is not a screen anyone outside the bank ever sees, and it
holds a client secret — the plainest-language rules for the vendor portal do not apply, but the
same honesty about defaults does: the connector is created **disabled** (`is_active` defaults to
`false`, ADR 0018 §2.2 item 5) and nothing syncs until this screen's own Test connection has been
run, and the screen says so before anyone can miss it.

This screen never shows a directory value it did not read moments ago through **Test connection**,
and it never shows a roster figure the sync did not actually write — every number here has a
named source (§8), and where a fact is a projection of the future (the secret-expiry warning) it is
labelled as input the admin typed, not something Graph reported.

## 2. Layout

Single-column `AppLayout` (matching `Settings.jsx`), `PageHeader` title "Identity sync (Microsoft
Entra ID)", subtitle: "Reads your organisation's Entra ID directory to keep the contact roster and
call trees current. Read-only — nothing is ever written back to Entra." `PageHeader` action: a
`btn-secondary` link back to `bcms.settings.index` labelled "BCMS settings".

**Above the fold, in this order:**

1. **No-connector banner** (`slate-50`, not alarm-coloured — this is the expected first state, not
   a fault), shown when no `IdentityConnector` row exists for this organisation: "No identity
   connector is configured. Fill in the fields below and save, then run **Test connection** before
   the first sync — nothing syncs until a connector is both saved and tested." The form below
   renders with empty fields; **Test connection** and **Sync now** are both disabled (native
   `disabled`, with the reason stated beside them: "Save the connector first.").
2. **Inactive-connector banner** (`amber-50`/`900`), shown when a connector exists but
   `is_active === false`: "This connector is saved but not active. Nothing syncs — including the
   nightly schedule — until **Test connection** succeeds at least once." (A connector is never
   flipped active by this screen directly; **Test connection** succeeding is what activates it,
   per ADR 0018 §2.2 item 5 — the form states this rather than exposing a separate "Active" toggle
   a person could set without ever testing.)
3. **Secret-expiring banner** (`amber-50`/`900`, promoted to `red-50`/`900` inside 7 days), shown
   when `credential_expires_on` is not null and is within 30 days: "The app registration's client
   secret expires on **{credential_expires_on}** ({n} days). Rotate it in Entra, then use **Set /
   rotate secret** below before it lapses — a lapsed secret freezes the roster silently; nothing on
   this screen will look wrong until the next sync fails." Not shown when `credential_expires_on`
   is null; a null expiry is itself worth a small neutral note beside the secret field (§2, Secret
   sub-section) rather than a banner, since it is an input gap, not a countdown.
4. **Run-in-progress banner** (`slate-50`, informational, not alarm), shown when the latest run's
   `status === 'running'`: "A sync is running — started {started_at, relative}. This page checks
   every 5 seconds and updates when it finishes." (§3, Sync now.)
5. **Last-run-failed banner** (`red-50`/`900`), shown when the latest run's `status === 'failed'`
   and no run has succeeded since: "The last sync failed at {finished_at} — {error_class}. {n}
   consecutive failure(s). Nothing was applied to the roster." No provider message is ever shown
   here (ADR 0018 §3.3, work order §12 item 3) — `error_class` only, e.g. "Unauthorized" or
   "ServiceUnavailable", never a Graph error string.

**Connection configuration** — a form section (`Settings.jsx`'s bordered-card shape), fields:

| Field | Control | Notes |
|---|---|---|
| Directory tenant ID | text input | Entra tenant GUID, plain text, not treated as a secret |
| Client (application) ID | text input | Not a secret |
| Client secret | **write-only** — see below | Never rendered, never echoed |
| Graph base URL | text input, pre-filled with the deployment default | e.g. `https://graph.microsoft.com/v1.0` |
| Token base URL | text input, pre-filled with the deployment default | e.g. `https://login.microsoftonline.com` |
| Attribute filter | text input, optional | An OData `$filter` over user attributes — the field's own hint text states plainly: "This filters by user attribute, not by group. Filtering by Entra group membership needs a wider permission this connector deliberately does not request — see Declared permissions below." (ADR 0018 §3.2) |
| Credential expires on | date input | Admin-entered, from the Entra app registration's own secret-expiry date; hint: "Entra does not report this to us — copy it from the app registration each time you rotate the secret." |
| Sync schedule | select: Nightly only / Nightly + 15-minute delta / Manual only | Hint under "Nightly + delta": "Delta catches attribute changes every 15 minutes but never re-resolves who reports to whom — the nightly full run is what keeps the org chart correct." |
| Auto-apply policy | select: Review everything / Auto-apply changes with no call-tree or audience impact | No third option exists. Hint: "A change that would break a call tree or empty a saved audience always needs a person's acknowledgement, whichever option is chosen here." |

**Secret field — write-only, "set / rotate" state:**

- If no secret is stored: a single button, "Set client secret", opening an inline reveal of one
  password-type input plus a "Save secret" / "Cancel" pair. Nothing above this state implies a
  secret already exists.
- If a secret is stored: a plain-text status line, "A client secret is set." with a "Rotate
  secret" button that opens the same inline reveal. **The value is never fetched, never
  pre-filled, and the input is always empty when opened** — there is nothing to show, by design
  (`IdentityConnector::$hidden`).
- Leaving the reveal open and saving the surrounding form **with the secret input blank** must
  leave the stored secret untouched (work order §5) — the copy beside the field states this
  explicitly: "Leave blank to keep the current secret." A blank field is never interpreted as
  "clear the secret."

**Declared Graph permissions** — a small read-only card, always shown once a connector exists:
"This connector requests **User.Read.All** only. It cannot write to your directory — there is no
code path that calls a write endpoint, and this is enforced, not merely promised (see the
read-only guard test named in the implementation)." When the latest **Test connection** result
recorded a scope list wider than requested (e.g. the app registration also grants
`Directory.Read.All`), an additional line: "Your app registration currently also grants: {scopes}.
None of these are required, and none is used to write anything." This exists so an
over-permissioned registration is visible here rather than discovered in a security review.

**Test connection** — a `btn-secondary` button, result rendered directly beneath it (not a toast,
not a modal — it must persist on screen next to the button that produced it):
- Success: `emerald-50`/`900` — "Connected. Read one sample user in {n} ms. Scopes granted:
  {scopes}." Activates the connector if it was not already active (stated inline: "This connector
  is now active.").
- Failure: `red-50`/`900` — "Could not connect — {error_class}." (never a provider message) plus,
  where the class is actionable, one plain-language line: `Unauthorized` → "Check the tenant ID,
  client ID and client secret."; `Forbidden` → "The app registration does not grant User.Read.All."
  A failed test does **not** deactivate an already-active connector — it only withholds
  activation of one that was not yet active.

**Attribute mapping** — a table, one row per mapped field, defaults from
`App\Support\Bcms\DirectoryAttributeMap` (Blueprint §8.2):

| BCMS field | Entra attribute | Source |
|---|---|---|
| Full name | `displayName` | default |
| Employee ID | `employeeId` | default |
| Title | `jobTitle` | default |
| Business unit | `department` | default |
| Site | `officeLocation` | default |
| Email | `mail` | default |
| Mobile (primary) | `mobilePhone` | default |
| Manager | `manager` (relationship, not an attribute) | default, not editable — always the manager edge |
| Active | `accountEnabled` | default, not editable — deactivation is not optional |

Each editable row's Entra-attribute cell is a text input; a row that still holds the shipped
default carries a small "default" badge (grey, text, not colour-only) beside it, which disappears
the moment the admin changes that row — mirroring the AI-settings screen's rule that a default is
named as a default, never presented as if it were entered. Manager and Active are rendered as
plain text, not inputs, with a one-line reason each ("always the reporting edge Entra returns";
"always drives deactivation — a leaver is never re-included by relabelling this field").

**Never synced** — a second, read-only table beneath the mapping, always shown, never
collapsible: `whatsapp`, `mobile_secondary`, `next_of_kin`, `channel_preferences`,
`preferred_language`, `consent_status`, `consent_captured_at`, `consent_withdrawn_at`,
`verification_status`, `last_verified_at`, `latitude`, `longitude`, `geo_last_known`, `user_id` —
one line of copy above it: "These fields are never written by any sync, even if Entra holds a
value for them. A directory listing a mobile number is not the same fact as a person agreeing to
be texted on it." This exists precisely so nobody scans the mapping table looking for a WhatsApp
row that will never appear (ADR 0018 §3.4).

**Schedule and last runs** — a table, most recent 10 `IdentitySyncRun` rows, newest first:
`trigger` (Scheduled — full / Scheduled — delta / Manual), `started_at`, `finished_at` (or "—" while
running), `status` badge (`running` slate, `success` emerald, `partial` amber, `failed` red — text
label always present, never colour-only), and the four count columns
(`joiner_count`/`leaver_count`/`mover_count`/`contact_change_count`) plus `pending_count`. A row
with `status = failed` shows `error_class` in place of the counts. Clicking a row's status badge
(for `success`/`partial`) links to `bcms.identity.runs.show` (the change-review screen, filtered to
that run) — the link text is "Review changes ({pending_count} pending)" so the destination is
named, not implied by a bare click target.

**Sync now** — a `btn-primary` button, disabled while any run has `status = running` (the run-in-
progress banner already explains why), with confirming copy beside it when a connector has never
successfully synced: "This will be the first sync. Expect the roster and every downstream call
tree to change — review the results before relying on either." Clicking posts to
`bcms.settings.identity.sync` and the page begins polling (§6).

**Roster summary** — a card, the one place this reduced phase shows what the sync produced, since
the contact directory screen itself is Phase 2D's (ADR 0018 §5):

| Figure | Meaning |
|---|---|
| Contacts by source | Count of `bcms_contacts` grouped by `source`, this organisation |
| Unmatched departments | Distinct `department` values Entra returned that could not be resolved to an existing `BusinessUnit`, with the count of contacts affected |
| No manager edge | Count of active contacts with `manager_contact_id` null and `manager_user_id` null — the flat-tree risk ADR 0018 §0 names |
| Consent not requested | Count of active contacts whose `consent_status = not_requested` — read from the KRI Phase 6 already computes off `last_verified_at`/`consent_status`, not recomputed here |

Each figure that is zero reads "0" plainly (a true zero here is a real count, not an absent
measurement) — this differs from the "never a bare 0" rule elsewhere in the product only because
every figure in this table **is** always computed, for every organisation with at least one synced
contact; the rule that a 0 must not stand in for "not measured" is preserved, not broken, because
there is no "not measured" state for a count query.

## 3. Every state

- **No connector configured.** See banner 1 above. Save is available; Test connection and Sync now
  are disabled with their reason stated. The last-runs table and roster summary render their own
  empty copy: "No runs yet." / "No contacts have been synced yet — the roster summary will appear
  once the first sync completes."
- **Connector saved, never tested.** No banner beyond the inactive-connector one; Test connection
  is enabled, Sync now stays disabled ("Test the connection first.").
- **Connector active, healthy.** No blocking banner. Last-runs table shows a recent `success`.
  Roster summary populated.
- **Secret expiring / expired.** Banner 3. The connector is not automatically deactivated by an
  expired secret — the next sync attempt will fail with `Unauthorized` and that failure is what the
  last-run-failed banner then shows; this screen does not pre-empt Entra's own rejection with a
  guess about whether the secret has actually lapsed.
- **Sync running.** Banner 4. Sync now is disabled. The last-runs table's top row shows `running`
  and updates live via the poll (§6) without a page reload.
- **Last sync failed.** Banner 5, `error_class` only. Sync now remains available (a failure does
  not lock the button) with copy: "You can try again now, or wait for the next scheduled run."
- **Loading.** Server-rendered Inertia page; no client fetch on first paint. The only asynchronous
  behaviour is the runs poll while a sync is in progress, and the Test connection / Sync now
  requests themselves.
- **Submitting (Save / Test / Sync now).** Each action's own button shows "Saving…" / "Testing…" /
  "Starting…" and is disabored for the duration of its own request only — these are three
  independent actions, not one form-wide lock, so testing the connection does not also disable
  the save button someone may be mid-edit on. (If the connector form is dirty when Test connection
  is clicked, the click first saves via the same `PUT`, then tests — stated in the button's own
  label, "Save & test connection", when the form is dirty, versus plain "Test connection" when it
  is not.)
- **Validation error.** Field-level errors render beneath the relevant control, same red-text
  pattern as `Settings.jsx`. A rejected attribute-filter value (an attempted group-id filter) shows:
  "This must be a user-attribute filter — group membership needs a permission this connector does
  not request."
- **Server/network error.** Standard product error handling; in-progress form values are not
  discarded (Inertia preserves local state on a failed visit).
- **Permission-denied.** A user without `bcms.identity.manage` never sees the "Identity sync" link
  from `Settings.jsx`. Direct navigation returns the product's standard 403.
- **Degraded network.** See §6.

## 4. Interactions

- **Save connector configuration**, `PUT bcms.settings.identity.update`, `preserveScroll: true`.
  No confirmation — every field here is reversible by changing it back, except the secret, which is
  never displayed to change back to (stated in the secret field's own copy: "You cannot see the
  current secret to compare it — rotating replaces it outright.").
- **Set / rotate secret.** Opens the inline reveal (§2); submitting sends the plaintext value once,
  over the same `PUT`, and it is never rendered again after the response. No confirmation dialog —
  reversible by rotating again, and Entra itself is the source of truth for which secret is valid.
- **Test connection**, `POST bcms.settings.identity.test`. No confirmation, no write of any kind
  to the directory (ADR 0018 §3.2) — reads one page of one user. Result renders inline (§2).
- **Sync now**, `POST bcms.settings.identity.sync`. **Not destructive by itself** — a run writes
  only to the staging table (ADR 0018 §3.3), never to `bcms_contacts` — so no confirmation is
  required on the button itself; the "first sync" advisory copy (§2) is informational, not a
  blocking confirm, because nothing it warns about is irreversible: every change it produces still
  waits at the change-review screen for a human decision.
- **No delete route for the connector, ever** (ADR 0018 §2.2 item 6). There is no "remove
  connector" action on this screen and none should be added — deactivation is not modelled here at
  all in this phase; a bank that wants to stop syncing sets the schedule to "Manual only" and
  never calls Sync now. (Backend-engineer: confirm no destroy route exists before this screen ships
  a link to one.)

## 5. Accessibility (WCAG 2.1 AA)

- **Every banner** (no-connector, inactive, secret-expiring, run-in-progress, last-run-failed)
  states its condition in text, never colour alone, and appears in DOM order before the form —
  a screen reader reaches the connector's health before its configuration fields.
- **The secret field's write-only state is announced, not just styled.** "Set client secret" /
  "Rotate secret" are the button's full accessible name (not "Set" alone) so a screen-reader user
  moving quickly through the form hears which state they are in without needing surrounding
  context.
- **Attribute mapping and never-synced tables are real `<table>`s** with a `<caption>` each ("How
  Entra fields map to BCMS fields, with the shipped defaults marked" / "Fields no sync ever
  writes, and why") and `<th scope="col">`, so both are navigable by a screen reader's table
  commands, matching the three-layer table pattern in `docs/tprm/screens/ai-settings.md`.
  Non-editable rows (Manager, Active) use `<th scope="row">` for the field name and plain `<td>`
  text for the value — never a disabled `<input>`, because there is nothing here a sighted user
  could mistake for editable either.
- **Test connection's and Sync now's disabled reasons are visible text immediately preceding the
  control**, not only `aria-describedby` — the same rule `ai-settings.md` states, for the same
  reason: several screen readers skip a disabled control's description in linear reading.
- **The last-runs table's status badges carry their state as text** (`running`/`success`/
  `partial`/`failed`), colour as a secondary cue only.
- **Keyboard path**: banners (not focusable) → BCMS-settings link → connector fields in table
  order → secret button → declared-permissions card (not focusable, informational) → Test
  connection button → its result region (a plain, non-live paragraph — a person can tab back to
  re-read it, but nothing announces itself automatically mid-typing elsewhere on the form) →
  attribute-mapping table (each editable cell an `<input>`, tab order follows row order) → schedule
  select → auto-apply select → Save button → last-runs table (each row's review link) → Sync now
  button → roster-summary table (not focusable, informational).
- **The run-in-progress live region is deliberately narrow.** Only the single top-of-table status
  badge is inside an `aria-live="polite"` region, updating on each poll tick; the rest of the
  table, and the roster summary, are not live regions — re-announcing ten rows of run history
  every five seconds is exactly the alert-fatigue failure mode the module's design rule warns
  against.
- **Contrast**: banners follow the existing three/four-tone system exactly as `ai-settings.md`
  documents (`*-900` text on `*-50` background); Gold is not used as banner or body text on this
  screen.

## 6. Low bandwidth (100 kbps)

- The page itself is one Inertia response; there is no client fetch on first load.
- **The runs poll is a small JSON document, not a full page reload** — `GET
  bcms.settings.identity.runs-status` (or equivalent, backend-engineer's naming), returning only
  the latest run's `status`, counts and `finished_at`, mirroring `CallTrees/Live.jsx`'s pattern
  exactly. It polls every 5 seconds **only while the latest run's status is `running`**, and stops
  the instant a poll returns a terminal status — a tab left open after an unattended nightly sync
  finishes does not keep polling until someone closes it.
- **On a failed poll**, the last-known run state stays on screen with a small note, "Checking for
  an update — last checked {n}s ago," rather than blanking the run-in-progress banner — an admin
  on a bad link must not conclude a running sync has vanished.
- **No chart, no image asset.** Every figure on this screen is a number or short text; there is
  nothing here dense enough to earn the house SVG convention.
- **Save, Test connection and Sync now are each a single small request** — a form post of a dozen
  scalar fields at most, or an empty-bodied POST. **Sync now does not wait for the sync to finish**
  — it returns as soon as the run row is created and the job is queued (ADR 0018 §7, "the run row
  exists before the first Graph call"), so a slow link never times out a request that is actually
  a background job running for up to an hour.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Directory tenant ID, client ID, Graph/token base URLs, attribute filter, schedule, auto-apply policy | `bcms_identity_connectors` row for this organisation |
| "A client secret is set" | `client_secret is not null` on the same row — never the value |
| `credential_expires_on` and the days-remaining figure | The same row's `credential_expires_on` (admin-entered), compared to `now()` |
| Declared Graph permissions / granted scopes | The static `User.Read.All` request, plus the `scopes` array from the most recent `testConnection()` result (`DirectoryClient::testConnection()`, ADR 0018 §4) |
| Attribute mapping rows and "default" badges | `attribute_map` on the connector row if set, else `App\Support\Bcms\DirectoryAttributeMap`'s defaults — a row shows "default" exactly when the connector's stored map has no override for that field |
| Never-synced field list | The constant allowlist on `ChangeApplier` (ADR 0018 §3.4) — read, never re-typed by this screen |
| Last-runs table rows | `bcms_identity_sync_runs`, latest 10, this connector |
| Run status colour/label | The stored `status` column — never re-derived from timestamps |
| Roster summary — contacts by source | `bcms_contacts` grouped by `source`, this organisation |
| Roster summary — unmatched departments | Distinct `department` values from the most recent run's staged/applied changes that did not resolve to a `BusinessUnit` |
| Roster summary — no manager edge | `bcms_contacts` where `is_active` and `manager_contact_id is null` and `manager_user_id is null` |
| Roster summary — consent not requested | `bcms_contacts` where `is_active` and `consent_status = 'not_requested'` |

**No number on this screen is a cost or a currency**, and none is fabricated behind a `?? 0` where
the true state is "not yet measured" — the no-connector and never-synced states are worded as
absence, never rendered as a zero (§3).

## 8. Out of scope

- **The contact directory screen and the data-confidence dashboard** — both named as Phase 2D's in
  ADR 0018 §1 and §5. This screen's roster summary is the only window onto the synced roster this
  phase provides.
- **On-prem AD/LDAPS, SCIM-into-contacts, HRIS, CSV import** — all Phase 2D or later (ADR 0018 §1).
- **Group → team/warden/crisis-role mapping rules** — Phase 2D.
- **A delete/deactivate action for the connector** — not modelled in this phase (§4).
- **A cost or licence-count figure for synced contacts** — this connector has no such concept and
  none is shown.

## HANDOFF

**Phase:** BCMS Phase 2C (reduced) — Entra ID identity sync, screens (ADR 0018 §5, work order §8)
**Agent:** ui-designer
**Status:** Complete — spec ready for implementation once the API surface lands
**Files written:**
- `docs/bcms/screens/identity-connector.md`

**Decisions this spec makes that are not restated elsewhere:**
- The connector poll for run status reuses `CallTrees/Live.jsx`'s small-JSON, stop-when-finished
  pattern rather than `useJobProgress`, because the run this screen tracks is
  `bcms_identity_sync_runs`, not the ERM-core `JobRun` table that hook is bound to.
- "Test connection" doubles as "Save & test" when the form is dirty, rather than requiring two
  separate clicks, stated in the button's own label so the action taken is never ambiguous.
- The secret-expiry banner escalates from amber to red inside 7 days; this threshold is a design
  choice, not a value the backend returns — backend-engineer should confirm whether the 30-day
  warning window and the 7-day escalation are both computed client-side from `credential_expires_on`
  or whether the run/watchdog already classifies severity, and adjust the prop shape accordingly.

**Next agent:** integrations-engineer (lead) for `app/Services/Bcms/Identity/*`, then
backend-engineer for the models/controllers/routes this spec assumes, then reliability-engineer
for the queue/schedule/watchdog, then **frontend-engineer** to build
`resources/js/Pages/Bcms/Settings/Identity.jsx` against this spec and the sibling
`docs/bcms/screens/identity-change-review.md`.

**Screens this phase still owes:** none beyond the two named in ADR 0018 §5 — this file and
`docs/bcms/screens/identity-change-review.md`, both now written.
