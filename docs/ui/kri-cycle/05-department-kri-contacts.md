# 05. Department KRI contacts (settings)

**Page:** new `resources/js/Pages/Kri/Settings/Contacts.jsx` · **Routes:** `GET /risk/kri/settings/contacts` (`risk.kri.contacts.index`), `GET .../contacts/users?q=` (JSON user search), `POST .../contacts` (`risk.kri.contacts.store`), `DELETE .../contacts/{contact}` (`risk.kri.contacts.destroy`); all `permission:kri.manage_department_contacts` · Read `00-common.md` first.

## 1. Purpose and the user

**Who:** a Risk Officer or CRO (`kri.manage_department_contacts`) setting up the cycle, usually once, then when people join, leave or move. Also the person who finds out from screen 02/03/04 that a department has no champion and arrives here to fix it.

**First ten seconds:** which departments have no risk champion, and which contacts are a problem (inactive, disabled in the directory, from a different directory department, missing the permission to complete a return). Then: add or remove someone.

**Reminder of what this screen does not do:** choosing a champion grants nothing. A champion also needs the `kri.submit_return` permission (the `risk-owner` role). The page says so (3.1).

## 2. Props used (ADR §16)

`{departments: [{id, code, name, parent_id, contacts: {risk_champion: CContact[], team_lead: CContact[], unit_head: CContact[], group_head: CContact[]}, has_champion}], roles: [{value, label}]}`

`CContact = {contact_id, user_id, name, email, is_active, has_submit_permission, directory: {synced, department, account_enabled, last_synced_at}|null, department_mismatch}`

User search (JSON, `?q=`, min 2 characters, max 20): `[{id, name, email, is_active, home_unit: {id,name}|null, has_submit_permission, directory: ...|null}]`.

Add: `POST` with a body the ADR does not spell out; this spec assumes `{business_unit_id, user_id, role}` (matching the table `kri_department_contacts`). Remove: `DELETE .../{contact}` by `contact_id`.

## 3. Layout

### 3.1 Header
`PageHeader`: title **Department KRI Contacts**; subtitle "Who receives each department's monthly KRI request and its reminders"; breadcrumbs **KRI Monitoring** → **Department Contacts**.

Directly below, a `role="note"` info strip: "Choosing a risk champion doesn't give them access. To complete a return they also need the **Submit KRI return** permission, which the Risk Owner role has. People who lack it are flagged below." and a second sentence: "Contacts are chosen from users in ERM. The directory department, where we have it, is a hint only; it is never applied automatically."

### 3.2 Toolbar
- **Find a department** (search; name or code; client-side).
- Checkbox filters (real checkboxes, labelled): **Only departments with no risk champion**; **Only departments with a flagged contact**.
- A live count "Showing {n} of {N} departments" (`role="status"`, debounced).
- Departments in name order, each child department indented under its parent when `parent_id` matches a listed department (indent by depth, `pl-6` per level, capped at 3 levels). Orphans (parent not in the list) sit at top level.

### 3.3 Department list: accordion
Each department is a disclosure item. **Header** (a `<button>` with `aria-expanded`, `aria-controls`; the full row is the hit area):
- Name and code.
- Counts per role as text chips: "Champions 2 · Team leads 1 · Unit heads 0 · Group heads 0" (lengths of the four arrays).
- Warnings (icon + words, amber): "No risk champion" when `has_champion = false`; "{n} flagged" when any contact in the department has a flag (3.5).

**Body**, four role groups in ladder order (use `roles` for labels and order):

Each group: `h3` with the role label, a one-line explanation, the contact list, and an **Add {role label}** button (`btn-secondary`, 36 px desktop, 44 px mobile).
- Role help text (what each tier means, derived from the ADR ladder): Risk champion: "Receives the monthly request and every reminder. Completes the return." Team lead: "Added from the second reminder." Unit head: "Added from the third reminder." Group head: "Added from the fourth reminder."
- Contact row: name (`font-medium`), email (`text-xs text-gray-600`), then flags (3.5), then **Remove** (icon-and-text button "Remove", visually the text "Remove" with the accessible name "Remove {name} as {role label} for {department.name}").
- Empty group: "Nobody set." For risk champion: "Nobody set. The monthly request will reach nobody." in amber with icon.

### 3.4 Add contact dialog (`Modal`, `maxWidth="lg"`)
Opened by **Add {role label}**. Title **Add {role label} for {department.name}**. Content:
1. **Search** (Headless UI `Combobox`; label "Find a person"; help "Search by name or email. Type at least 2 characters."). `role="combobox"`, popup `role="listbox"`, options `role="option"` with `aria-selected`.
2. Option layout: name (bold), email, then a second line of facts:
   - "Home department: {home_unit.name}" (or "No home department"). If `home_unit.id === department.id`, instead: "Member of this department".
   - "Directory: {directory.department}" when `directory` is set, else "Not in the directory".
   - Tags (text and icon): "Inactive: emails will be skipped" when `is_active` is false; "Can't complete returns yet" when this role is risk champion and `has_submit_permission` is false; "Already a {role label} here" (option disabled) when `user_id` is already in this group.
   The picker does **not** state a mismatch verdict, because that rule belongs to the server (`department_mismatch` exists only on saved contacts); it shows the raw directory department so the admin judges.
3. **Add** is by selecting an option: selection posts at once (single-click add; there is no separate Add button) and the dialog stays open with a `role="status"` line "Added {name} as {role label}." The list behind it updates (Inertia redirect back with `preserveState` and `preserveScroll`). **Done** closes. This supports adding several champions without reopening.
4. Search states:
   - Idle (< 2 characters): "Type at least 2 characters to search."
   - Loading: spinner and `aria-busy="true"` on the listbox; previous results stay dimmed until the new ones arrive.
   - Results: shown; announced "{n} people found" (polite, debounced).
   - 20 results: add "Showing the first 20. Type more to narrow the list."
   - No results: "No one matches '{q}'. Only users who exist in ERM can be chosen."
   - Search failure (network/5xx): inline `role="alert"` "Search failed." + **Try again**.
   - 419/401: the common session modal text, inside the dialog.
   - **No directory data** (every result has `directory: null`): once, a `role="note"`: "No directory information is available for these people. You can still choose them." (Same wording when the directory feature is off.)

### 3.5 Contact flags (all text plus icon, none blocks saving)

| Flag | When | Words | Why it matters |
|---|---|---|---|
| Inactive in ERM | `is_active = false` | "Account inactive. Emails to this person are skipped." | The only status (with no email) that actually stops delivery (ADR §9) |
| Disabled in directory | `directory.account_enabled === false` | "Disabled in the directory. They still receive emails until their ERM account is made inactive." | Display flag only; delivery rule is ERM's |
| Directory department differs | `department_mismatch = true` | "Directory says: {directory.department}. This department is {department.name}. Shown as a hint; nothing is changed." | Flag, never applied (decision 0.5) |
| Can't complete returns | role is Risk champion and `has_submit_permission = false` | "Doesn't have the Submit KRI return permission, so they can't open Complete my KRIs." | A configured champion who can't act |
| Not in directory | `directory` null | "Not in the directory" in grey, no icon, not counted as flagged | Information, not a fault |
| Directory age | `directory.last_synced_at` | "Directory data from {date}" in `text-xs text-gray-600` | So a stale hint is visible |

"Flagged" (for the filter and the header count) means: inactive, disabled in directory, mismatch, or can't complete returns. Not-in-directory is not flagged.

### 3.6 Remove confirmation (`ConfirmDialog`, `variant="warning"`)
- Title **Remove {name}?**
- Message: "{name} will no longer be a {role label} for {department.name}. They stop receiving its KRI emails{, and can no longer open its return in Complete my KRIs — champions only}. The change is recorded in the audit trail. Emails already sent are not affected." The bracketed clause appears for the risk champion role.
- If this is the department's only risk champion: add "{department.name} will have no risk champion, so its monthly request will reach nobody until you add one."
- Buttons **Cancel** (focused first) and **Remove**.

## 4. States

| State | Behaviour |
|---|---|
| **Loading** | Inertia progress; synchronous props. |
| **Empty: no departments** | `EmptyState` "No departments yet". "Departments come from your organisation's business units, which are set up outside this screen." |
| **Empty: nobody configured at all** | Every `has_champion` false: strip "No department has a risk champion yet. Open a department, choose **Add Risk champion** and pick a person." Departments list still shown, all marked. |
| **No filter match** | `role="status"` "No department matches your filters." + **Clear filters**. |
| **Department with no contacts** | Four "Nobody set." groups. |
| **Partial** | Some roles filled. |
| **Not configured (directory)** | If no contact on the page and no search result has directory data, the directory notes above appear; nothing else changes. |
| **Add: validation (422)** | Inside the dialog `role="alert"` with the server message (for example already a contact in that role, or user not found). The dialog stays open. |
| **Add / remove: save failure** | Dialog: `role="alert"` "Couldn't save. Nothing was changed. Try again." Removal: the `ConfirmDialog` closes and a page-level `role="alert"` banner shows the same sentence with a **Try again** button. After a network error the page first reloads `only: ['departments']` to check what actually happened. |
| **Expired session** | Common modal. The in-progress selection is lost harmlessly (a single click). |
| **Permission denied** | 403 page. |
| **Degraded network** | Section 8. |
| **Concurrent edit** | Another admin removed the contact first: DELETE answers 404/lifecycle; show "That contact was already removed." and reload `departments`. |

## 5. Interactions
1. **Expand/collapse:** header button; Enter/Space; multiple items may be open. Open items persist across the redirect after add/remove (`useRemember` of the open set, keyed by department id).
2. **Add:** open dialog → type → arrow keys to move → Enter to select (posts) → status line → continue or **Done**.
3. **Remove:** button → warning confirm → remove.
4. **Irreversible:** removal is a hard delete of the contact row; history lives in the audit row (ADR §2). Adding back creates a new row. The confirm text says it's recorded; it does not promise undo.
5. Audit: every add and remove is recorded (ADR §13) against the business unit; the screen offers no history view of its own (it is in the audit trail).

## 6. Accessibility

- **Accordion:** header is a real `<button>` inside a heading (`h2`) with `aria-expanded` and `aria-controls`; the panel has `role="region"` and `aria-labelledby` the header id.
- **Combobox:** Headless UI's pattern. `aria-activedescendant` for the highlighted option; typing keeps focus in the input; Down arrow opens; Escape closes the list (first press) then the dialog (second); Enter selects. The disabled "Already a ..." options have `aria-disabled` and remain reachable so their reason is read.
- **Announcements:** result count (polite), "Added {name} as {role}" (polite), search error (alert).
- **Focus:** opening the dialog focuses the search input; closing returns focus to the **Add** button that opened it. After removal, focus moves to the **Add {role label}** button of the same group (the removed row's button no longer exists).
- **Labels and errors:** each input labelled; errors tied by `aria-describedby`; the flags are real text in the row, read after the name.
- **Contrast:** amber text `text-amber-800` on white or `bg-amber-50`; red `text-red-700`; secondary `text-gray-600`; flag icons are decorative.
- **Filters:** native checkboxes with visible labels.

## 7. Mobile
Accordion is single column. Role groups stack. Contact rows: name and email on top lines, flags below, **Remove** as a full-width secondary button at the bottom of the row (44 px). The dialog is full height with `Done` pinned at the bottom; the listbox scrolls inside the dialog. Search is `type="search"` with `autocomplete="off"`, `autocapitalize="none"`; the on-screen keyboard must not hide the listbox, so the dialog scrolls its content (Modal's panel already does).

## 8. Low bandwidth
- Page payload is departments × contacts: about 0.5 KB per contact; 60 departments × 4 contacts is about 120 KB, roughly 10 s at 100 kbps. Mitigation is on the client: accordion bodies render **on first expand** (the header counts use array lengths already in the payload), so the first paint is the light header list.
- User search: nothing loads until 2 characters; requests are debounced 300 ms; the in-flight request is aborted when the text changes; at most 20 results (about 6 KB); results cached by query for the session. No avatars.
- Add/remove are tiny POSTs; each shows "Saving…" and the slow-link line after 15 s. A list is never optimistically changed: the row appears or disappears only when the server's redirect returns, because a contact that appears but did not save would be worse than a delay.

## 9. The numbers on the screen

| Figure | Source | Absent |
|---|---|---|
| Role counts in a department header | Lengths of `departments[].contacts.*` | "0", with the "Nobody set" text on expand (a true count of zero contacts) |
| "No risk champion" | `departments[].has_champion` | |
| "{n} flagged" | Count of contacts matching the flag rule in 3.5 over the same payload | Not shown at 0 |
| "Showing {n} of {N}" | Client counts of the filtered/all `departments` | |
| Directory age | `directory.last_synced_at` | "Not in the directory" |

## 10. Known gaps and assumptions
1. **POST body** assumed `{business_unit_id, user_id, role}`; the ADR names only `StoreKriDepartmentContactRequest`.
2. **Search results have no mismatch verdict** (only saved contacts have `department_mismatch`), so the picker shows the raw directory department.
3. **Inactive users are selectable with a warning, not blocked.** The ADR doesn't forbid adding them and the server is the authority; blocking client-side could disagree with it. Revisit if the server rejects them.
4. **Nav for this page** sits in the "KRI Monitoring" group (`/risk/kri/...`), so it highlights correctly there. The Holidays page (06) lives at `/risk/settings/holidays`, outside that group's `prefixes`.
