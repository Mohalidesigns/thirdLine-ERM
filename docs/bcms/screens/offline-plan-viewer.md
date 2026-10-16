> **DEFERRED — not in Phase 12.** Scope change from the product owner: the offline PWA (12C, and
> everything downstream of it — the queue/sync screen and the install prompt) is deferred until a
> client deployment is being prepared. This spec is kept for when that work resumes, but nothing in
> 12C is built against it now — do not implement from this document in Phase 12. The two sibling
> specs it references (`offline-queue-and-sync.md`, `install-prompt.md`) were never written for the
> same reason; write them alongside this one when 12C is reopened, not before.

# Screen spec — the offline plan viewer (a Blade shell, not an Inertia page)

**Surface:** `GET bcms/offline` → `bcms.offline.shell` (a Blade view, `resources/views/bcms/
offline-shell.blade.php`, carrying **no props in its HTML** — ADR 0023 §6.2: "An Inertia page's
HTML carries its props, which here would be personal data inside a cached document"). Its own
JavaScript entry, `resources/js/offline/main.jsx`, a separate Vite build (`vite.config.js` gains a
new entry, work order 12C). Data arrives only via `GET bcms/offline/pack` → `bcms.offline.pack`,
`middleware('permission:bcms.offline.use')`, called from inside that JavaScript after the shell has
already rendered — **the pack is fetched, never delivered with the page.** A service worker, scoped
to `bcms/`, `Cache-Control: no-cache` on its own script, caches the shell, the JS/CSS bundle and the
pack response, and nothing else.

**Reads:** ADR 0023 §6.1 (the pack's contents and `ETag`), §6.2 (why this is not an Inertia page —
read in full before questioning the shell/SPA split), §6.5 (the honest "last synced" rule — this
document's central requirement), owner question 4 (contact scope default: every call tree the user
is a node on, plus the plan's crisis-team contacts — accepted here, not re-litigated), §7's
`bcms-lifesafety`-adjacent reasoning does not apply here (this is a read surface, not dispatch), but
its spirit — never let a slow network make the truth unreachable — is exactly why this screen exists
at all.

**Siblings read before writing this:** `qr-checkin.md` (the unauthenticated, no-chrome, plain-
language shell — the visual register this screen borrows, extended from a single action to a whole
document); `crisis-room.md` §6 (the honest "last updated {n}s ago" note on a live poll — this
screen's "last synced" stamp is that same discipline applied to a document that can be **hours**
stale, not seconds); `pir-post-incident-review.md`/`aar-builder.md` (the rule that a screen never
computes a fact it cannot source — every figure on this screen traces to the pack, never to a
guess about what "should" still be true).

---

## 1. Purpose and the user

A BC officer, crisis-team member or employee opens this **at the exact moment the network they'd
normally rely on has failed** — a flooded branch with no signal, a data allowance exhausted mid-
incident, an assembly point where two hundred phones are competing for one cell tower. They need,
in order: which plan applies to them, who is on their call tree and how to reach them, where the
assembly point is, and whether what they are looking at is actually current. In the first ten
seconds they must see **one plain fact this screen never fudges: exactly when this was last
confirmed against the server**, because a stale plan with a loud "last synced 3 days ago" banner is
useful and a stale plan that looks current is dangerous. This is not a screen anyone browses for
research — it is opened once, read under stress, and closed.

## 2. Layout

No `AppLayout`, no navigation, no header chrome beyond a single fixed top bar — matching
`qr-checkin.md`'s "no layout, no navigation, no login" register, because this shell must render
identically whether or not the network that would normally deliver the product's chrome is present
at all.

**Fixed top bar, always visible:**
- Product mark (text, not an image — nothing here depends on an asset the service worker might not
  have cached), "Offline plan".
- **The honest stamp** (§6.5, the ADR's own wording followed exactly): "Last synced: {server
  `generated_at` of the last pack **actually fetched from the network**} · {n} action(s) waiting to
  send." A response served from the worker's cache **does not** count as a sync and does not update
  this stamp — the client tracks "last network fetch" and "last cache read" as two different facts
  and only ever displays the first as "synced." **This screen never says "up to date" while
  offline.**
- A manual "Sync now" button, always present, attempting a fresh `GET bcms/offline/pack` with
  `If-None-Match` — visibly disabled with "No connection" text while `navigator.onLine === false`,
  rather than silently doing nothing on tap.

**Body, one scrollable page, sectioned (not tabbed — a person under stress should never lose their
place by tapping the wrong tab on a cracked screen):**

1. **My emergency profile.** Name, role, site, the assembly point their own site maps to.
2. **Plans I can see** (ADR 0017 visibility, unchanged — the pack computes this server-side, this
   screen renders it, never re-derives it). Each: plan name, version/approved date, and its sections
   (assembly points, crisis-team contacts) inline via `OfflineBundleBuilder::build()`'s existing
   assembler — **one truth**, the same builder the online plan screen already uses.
3. **My call trees.** For every tree this user is a node on: the tree's structure (who calls whom)
   and those nodes' contacts — phone numbers, in large legible type, each with a `tel:` link so a
   single tap dials on a phone that still has voice service even with no data (owner question 4's
   default, applied concretely).
4. **Assembly points**, pulled out as their own section even though they also appear inside each
   plan, because in an actual evacuation this is the single fact someone reads first: name, address,
   a static map reference if the online plan carries one (no live map tile fetch — see §6).

**Bottom bar** (persistent, not part of the scroll): a compact "Queue" indicator, specified fully in
`offline-queue-and-sync.md` — this screen only renders the badge and the entry point into it.

## 3. Every state

- **First open, online, pack never fetched.** Loading state: "Getting your plan ready…" with the
  stamp reading "Not yet synced." On success the stamp updates and the body populates; **this fetch
  and the ones that follow are the only source of truth this screen ever has** — nothing renders
  from an assumed default.
- **Subsequent open, offline, pack cached.** The body renders immediately from the service worker's
  cached pack; the stamp shows the **real** `generated_at` of that cached response, however old, and
  "Sync now" is disabled with "No connection." This is the screen's central promise: an old plan
  with a loud banner beats no plan (ADR 0023 §6.5).
- **First open, offline, pack never cached.** Nothing to show. "This device has no saved plan yet.
  Connect to the network once to download it, then it works offline." — never a blank page, never a
  spinner that never resolves; the empty state names exactly what is missing and what to do.
- **Online, pack fetch succeeds, ETag unchanged (`304`).** Stamp updates to "just now" even though
  the body content is byte-identical — a fresh confirmation that nothing has changed is still real
  information and is shown as such, not silently skipped.
- **Online, pack fetch fails** (server error, not a network failure). The cached copy (if any)
  keeps rendering with its own honest stamp; a small non-blocking note: "Could not check for
  updates — showing the last saved version." No error page ever replaces a plan that is still
  readable.
- **Schema mismatch** (`schema_version` in a cached pack older than this build of the shell knows
  how to render). Rather than risk rendering a plan incorrectly, the shell shows: "This saved plan
  needs an update. Connect to the network to refresh it." with whatever **can** still be rendered
  safely (the honest stamp itself, the "Sync now" button) — never a silent partial render of fields
  the new code does not recognise.
- **Permission-denied** (`bcms.offline.use` not granted — should not normally reach this shell if
  the entry link is itself permission-gated, but the pack endpoint is the actual authority): "Your
  account is not set up for offline access. Ask your administrator." Plain, not a raw 403 page,
  matching the module's public-surface tone even though this user is authenticated.
- **Logged out** (session expired while the shell stayed open, or the user explicitly logs out from
  elsewhere). The **pack and worker caches are cleared** (§6.5) and this screen reverts to the
  "no saved plan" empty state — but only after any queued, unsent actions have been warned about, a
  rule owned by `offline-queue-and-sync.md` and repeated here because it changes what this screen
  does on logout: **the plan disappears, the queue does not.**
- **Degraded network** (a connection that answers, slowly). See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Open the shell | `GET bcms.offline.shell` | None |
| Fetch/refresh the pack | `GET bcms.offline.pack`, `If-None-Match` sent when a cached `ETag` exists | None — a read, never destructive |
| Tap a phone number | `tel:{number}`, handled entirely by the device, no server round trip | None |
| Tap "Sync now" | Same as the automatic pack fetch | None |
| Record a check-in / roll-call answer / cascade ack from within this shell | Queued client-side; specified fully in `offline-queue-and-sync.md` | Per that document |

No destructive action exists on this screen. There is no edit control anywhere in it — it is a
read surface, deliberately, matching ADR 0023 §6.2's framing of the pack as "computed per request
and never stored" on the server side, and never editable on the client side either.

## 5. Accessibility (WCAG 2.1 AA)

- **The honest stamp is the first thing in DOM order**, before the body sections, so a screen
  reader announces "last synced" before anything else — matching the visual priority the top bar
  already gives it.
- **Every phone number is a real `<a href="tel:...">`**, not a styled `<span>`, so it is both
  keyboard-activatable and correctly announced as a link, not decorative text.
- **Section headings use real heading levels** (`<h2>` per numbered section in §2), enabling
  heading-based navigation exactly as `aar-builder.md` specifies for its own long document.
- **The "Sync now" button's disabled state states its reason as visible text** ("No connection"),
  never only a greyed-out control with no label change.
- **Colour is never the only signal for staleness.** The stamp is always text with a real
  timestamp; there is no colour-only "fresh/stale" indicator to fail a contrast or colour-blind
  check, because the honest rule already forces a textual statement of fact on every load.
- **Keyboard path:** top bar (product mark, not focusable) → stamp (not focusable, static text) →
  "Sync now" → body sections in document order, each contact/phone-number link individually
  reachable → bottom queue indicator (a real button, opens the queue panel per that spec).
- **Font size defaults larger than the product's usual body text** — this is a document read at
  arm's length, often one-handed, often by someone who has just been startled; `frontend-engineer`
  should treat this as a deliberate exception to the shared type scale, not an oversight to
  normalise back down.

## 6. Low bandwidth (100 kbps) and offline-first behaviour

- **The shell's own bundle is the smallest asset in the product**, by design (ADR 0023 §6.2's whole
  reason for a separate Vite entry): no Inertia runtime, no shared `AppLayout`, no chart code. The
  known risk recorded in the work order (`app-*.js` at 131 KB gzipped for the *main* app) is exactly
  what this separate entry exists to avoid paying on a screen whose entire purpose is working when
  the network cannot afford that cost.
- **The pack is the largest single fetch this screen makes**, and `ETag`/`If-None-Match` exists
  specifically so a re-sync on a slow link, when nothing has changed, costs a few hundred bytes
  instead of the whole document again (ADR 0023 §6.1).
- **No live map tiles, no image beyond what the pack itself carries as text/coordinates.** A static
  map reference (§2, item 4) is a link out to the device's own maps app via `geo:`/coordinates text,
  never an embedded tile layer — the offline shell does not attempt to cache a mapping provider's
  assets.
- **The service worker caches exactly three things**: the shell's own bundle, the manifest, and the
  most recent pack response — "nothing else: no authenticated HTML of other pages" (ADR 0023 §6.2).
  This is stated here as a screen requirement, not only a backend one, because it is what makes the
  "last synced" stamp meaningful: if the worker cached more than the pack, staleness would be a
  property of several different cached responses instead of one clear timestamp.
- **On a genuinely offline first load with a cached pack**, the screen must render fully with **zero**
  network requests beyond what the worker intercepts — this is the scenario `tests/e2e/bcms/
  offline.spec.ts` proves with `context.setOffline(true)`, and this spec's job is to ensure nothing
  in the layout assumes a live request will ever return.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| "Last synced: {time}" | The server `generated_at` of the **last pack response actually received over the network** (tracked client-side, distinct from a cache hit) — ADR 0023 §6.5 |
| "{n} action(s) waiting to send" | The IndexedDB queue's own length — `offline-queue-and-sync.md`'s source of truth, read by this bar |
| Plans, sections, assembly points, crisis contacts | `OfflineBundleBuilder::build()`, the same assembler the online plan screen uses — computed server-side, never re-derived client-side |
| Call trees and their contacts | The pack's `call_trees` array — every tree this user is a node on, with those trees' node contacts (owner question 4's accepted default) |
| Capability tokens (`chk-…`, `r-…`, `c-…`) | The pack's own open-token arrays, the user's own only — consumed by the queue, never displayed as raw text on this screen (they are credentials, not information a person reads) |

## 8. Deliberately out of scope

- **Editing any field.** This is a read surface; no plan, contact or assembly-point detail is
  editable from offline mode.
- **Push notifications.** Explicitly out of scope for the whole ADR (§ What this ADR deliberately
  does not do) — this screen never asks for notification permission and carries no badge implying
  one is coming.
- **A live map tile layer.** §6 — a `geo:`/link-out only.
- **Multi-tenant switching.** The pack is computed for the signed-in user's one tenant; there is no
  tenant picker on this shell, matching the rest of the product's session-scoped tenancy.
- **Retention/age-based wipe while logged in.** ADR 0023 §6.5 is explicit: no silent age-based wipe
  while the user stays logged in. This screen never expires its own cached pack by age; only logout
  clears it.
