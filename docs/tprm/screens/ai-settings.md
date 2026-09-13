# Screen spec — `Tprm/Settings/Ai`

**Route:** `GET tprm/settings/ai` → `tprm.settings.ai` (edit) · `PUT tprm/settings/ai` →
`tprm.settings.ai.update` — both `middleware('permission:tprm.admin')`, per
`docs/tprm/phase-11a-ai-contract.md` §7. No policy; no `TprmServiceProvider::POLICIES` entry
(ADR 0015 §8) — same as `ProgrammeSettingsController`.

**Reads/writes contract:** phase-11a-ai-contract.md §7.1–§7.2. This document does not restate the
field shapes; it specifies what renders them, in what state, and what the screen must refuse to
show. Nothing here contradicts §7 — where it adds a prop not named in §7 (the two flags noted
in §7 below), that is called out explicitly as an addition, not a reinterpretation.

**Sibling to read before building:** `resources/js/Pages/Tprm/Settings/Programme.jsx`. Same
voice — lead with what is off and what that costs, before any field — and the same visual
grammar for state banners (`red-50`/`red-900` = blocked, `amber-50`/`amber-900` = attention
needed but not blocking, `emerald-50`/`emerald-900` = confirmed working). Do not invent a fourth
tone. Tile grid pattern borrows from `Documents/Index.jsx`'s `CapabilityBanner` and summary
tiles.

---

## 1. Purpose and the user

A `tprm.admin`-permissioned person at the bank — not a developer — opens this screen in one of
two circumstances: initial setup of AI-assisted document extraction, or troubleshooting after
someone reports "AI isn't reading my documents." In the first ten seconds they must be able to
tell, without opening a support ticket, **whether AI is on, and if it is off, whose decision that
was** — their own tenant's, or the deployment's. Those have different remedies: a tenant-level
"off" is fixed on this screen; a deployment-level "off" requires an operator. A screen that shows
only "AI: Off" answers neither question and sends every off-tenant to the same wrong queue.

This is not a screen a vendor or an examiner ever sees. It is internal configuration, gated
accordingly — plainest-language rules for the portal do not apply here, but the same honesty
about defaults does: nothing is pre-selected as a guess (mirroring Programme.jsx's rule that no
field is pre-filled with a guess).

## 2. Layout

Single-column `AppLayout`, `PageHeader` title "TPRM AI settings", subtitle: "Which AI services
this tenant may use, which endpoint answers them, and the monthly usage limit. AI is
never on by default — it stays off until a deployment operator and this tenant both say yes."
`PageHeader` action: a `btn-secondary` link to the usage report (`tprm.settings.ai.usage`),
labelled "View usage report".

Above the fold, in this order:

1. **Deployment-blocking banners** (`red-50`/`red-900`, only one shown — the first that
   applies, most-blocking first):
   - `!deployment.llm_enabled`: "AI is switched off for this entire deployment. Nothing below
     this line can turn it on — that requires an operator to enable
     `services.llm.enabled`."
   - else `!deployment.module_enabled`: "AI is switched off for TPRM at the deployment level.
     Your tenant setting below has no effect until an operator enables it for the module."
2. **Infrastructure banner** (`amber-50`/`amber-900`), independent of the above, shown whenever
   `!breaker.cache_store_is_shared`: "This deployment's circuit breaker cannot share state
   across workers (the cache store is not shared). A failing endpoint will not be protected the
   way this screen implies below — every worker will keep retrying it independently."
3. **Stale-profile banner** (`amber-50`/`amber-900`), shown when `stale_profile` is present: "The
   endpoint profile this tenant was set to, `{stale_profile}`, is no longer offered. This tenant
   is currently using the default profile, `{deployment.profiles[default].label}`, instead. Pick
   a current profile below and save to clear this."

**Three-layer status table** — the load-bearing element, always rendered, never collapsed or
behind a disclosure toggle. A real `<table>`, one row per switch that can say no:

| Layer | State |
|---|---|
| Deployment — model available | On / Off |
| Deployment — TPRM module | On / Off |
| Tenant — master switch | **On** / **Off** / **Not set — follows deployment** |
| *(effective master)* | **On** / **Off**, with the layer name that decided it |

The tenant row is the one place tri-state must visibly differ from boolean: `ai_enabled === null`
renders as "Not set — follows deployment (currently {On/Off})" in a neutral grey badge, never as
"Off" and never in the same red badge as a true `false`. `ai_enabled === false` renders as "Off"
in a plain grey/black badge — not red — because a tenant's own considered "no" is not a fault
condition, it is a decision; red is reserved for something blocking that the tenant did not
choose.

**Master switch** — a `<fieldset>` with three radio options, not a checkbox (a two-state control
cannot express "not set"):
- "Follow the deployment default (currently {On/Off})" — `ai_enabled = null`
- "On for this tenant" — `ai_enabled = true`
- "Off for this tenant" — `ai_enabled = false`

**Services** — a table, one row per entry in `deployment.services` (all seven configured keys,
always all seven, in the order `config('tprm.ai.services')` declares them):

| Service | Deployment | Tenant | Effective |
|---|---|---|---|
| Evidence extraction | On | (radio: Follow / On / Off) | On |
| Clause analysis | On | (radio) | Off — deployment allows it, tenant said no |
| Subprocessor discovery | — | *Not built in this release* | — |
| … | | | |

Per row:
- If `!implemented`: the Deployment and Tenant cells both render the single phrase *"Not built in
  this release"* in a neutral grey badge, spanning both columns. **No control renders at all** —
  not a disabled one. Effective reads "—". This is the one row shape where nothing is
  interactive, because there is nothing behind it to be interactive about.
- Else if `!deployment_enabled`: the Tenant cell's radio group is **disabled** (native
  `disabled` attribute — not merely styled), and immediately above the disabled control, in
  plain visible text (not only an `aria-describedby`), reads: "Off — this deployment has not
  enabled this service. An operator must turn it on before this tenant can." If a value is
  already stored for this tenant (e.g. `true`, set before an operator later disabled the
  service), that stored value is shown as read-only text next to the explanation — "This
  tenant's own setting is currently On, but it has no effect while the deployment has this
  service off" — rather than silently discarded. Effective reads "Off — blocked by deployment".
- Else (implemented and deployment-enabled): the radio group is interactive, same three options
  as the master switch, scoped to this service. Effective reads the resolved value, and if the
  tenant's own choice ("On") differs from the master switch being off, the Effective cell says
  "Off — the tenant master switch above is off" rather than repeating "Off" with no reason.

**Endpoint profile** — a labelled `<select>` listing `deployment.profiles`, each option showing
`label` and, for the default, "(deployment default)". No free-text field exists anywhere on this
screen for an endpoint — selection only, never entry, per ADR 0015 §3. Each option that is
`priced` may show "(metered)" after its label; every profile that is not priced shows nothing
extra — there being no cost is the unmarked case, not a state that needs a badge.

**Monthly usage limit** — two labelled number inputs, "Token limit" and "Call limit", both with
hint text "Leave blank for no limit" (never "unlimited" as a placeholder value, and never `0` as
a default — `0` would mean "block every call"). Beside them, read-only, the current month's
consumption for context: "{month.usage_month} so far: {call_count} calls" and, only when
`total_tokens` is not null, ", {total_tokens} tokens"; when it is null, ", tokens not reported by
this backend" — never `0`. A "View full usage report" link sits beside this block.

**Breaker health** — a small card, one per resolved profile actually in use: state
(`Closed`/`Open`/`Half-open`) as a plain-language badge (green/red/amber respectively, following
the same three tones as the top banners), `opens_at` formatted as a relative time when the state
is `Open` ("reopens for one probe at {time}"), and `consecutive_failures`. If
`!cache_store_is_shared`, this card additionally repeats, inline, "this number resets per worker
and cannot be trusted as a whole-deployment signal" rather than relying on the reader to recall
the banner above it.

**Footer** — `btn-primary` "Save settings", disabled while submitting (label changes to "Saving…"
to match `Programme.jsx`'s `processing` convention), a "Saved." confirmation
(`recentlySuccessful`), and "Last changed by {updated_by} on {updated_at}" sourced from
`TprmAuditable` once §7.2's trait addition lands (falls back to "Never changed" when no audit row
exists yet).

## 3. Every state

- **First visit, nothing ever set.** Every tenant field is at its null/default. The three-layer
  table shows "Not set — follows deployment" on the master row and on every service row that has
  no stored tenant value. This is the expected, common first state — not an error, and not
  rendered as if a decision had already been made.
- **Deployment fully off.** Banner 1 fires. Every service row's Tenant control is disabled
  regardless of `deployment_enabled` per service, because nothing downstream of "no model at
  all" can matter. The master switch remains interactive (a tenant may still set their own future
  preference) but the whole form is preceded by the banner explaining why none of it currently
  does anything.
- **Deployment on, tenant master off.** No red banner (this is the tenant's own choice, not a
  block). The layer table's Tenant row shows "Off" in a neutral badge. Effective is "Off — layer:
  tenant_master" on every service, plainly labelled so the admin does not have to guess which
  layer is responsible — it is their own screen's own setting.
  Programme.jsx.
- **Mixed services** (some on, some deployment-blocked, some unimplemented). This is the
  expected steady state for a partially-rolled-out deployment; the table renders all three row
  shapes side by side without needing a legend, because each row states its own condition in
  text.
- **Stale endpoint profile.** Banner 3 fires; the `<select>` falls back to showing the default
  profile selected, not the missing key, and not a blank/unselected control (a blank select would
  imply "nothing chosen," which is false — something is in effect, just not what was asked for).
- **Loading.** The page is server-rendered by Inertia; there is no client-side fetch on entry, so
  there is no loading skeleton to design. The only asynchronous action is Save.
- **Submitting.** Save button reads "Saving…" and is disabled; fields remain visible and
  editable-looking but changes are queued until the response returns (standard `useForm`
  behaviour) — no full-page spinner, so a slow link does not look like the browser hung.
- **Validation error.** `UpdateAiSettingsRequest` failures render per-field beneath the relevant
  control, in the same red-text-under-label pattern as `Programme.jsx`'s `Field` component. A
  rejected `ai_endpoint_profile` (someone attempted to bypass the select and POST a URL) shows:
  "This must be one of the configured profiles — a web address cannot be entered here." A
  rejected key in `ai_services` (attempting to set an unimplemented or unknown service) shows at
  the top of the Services section rather than against a row that does not exist in the rendered
  table.
- **Server/network error (500, or the request never completes).** Standard product error
  handling applies; the form's in-progress values are not discarded (Inertia preserves local
  state on a failed visit) so the admin does not have to re-enter six fields because one save
  timed out on a bad link.
- **Permission-denied.** A user without `tprm.admin` never sees "AI settings" in the TPRM nav
  section (the nav entry is gated the same way `Programme Settings` already is in
  `NavPresenter`). Direct navigation to the route returns the product's standard 403 page; this
  screen defines no bespoke denial UI beyond that.
- **Degraded network.** See §6.

## 4. Interactions

- **Change master switch / any service radio / endpoint select / cap inputs**, then **Save**.
  Standard `PUT`, `preserveScroll: true`. No confirmation dialog for any of these — every change
  here is reversible by changing it back, and none deletes data.
- **Attempt to widen a deployment-blocked service.** Not possible through the UI (the control is
  `disabled`); the resolver enforces this independently regardless (AC-16 item 3), so this is
  belt-and-braces, not the only guard.
- **No destructive action exists on this screen.** There is no delete, no reset-to-defaults
  button, and no "clear all" — worth stating because its absence is deliberate: a "reset AI
  settings" button would be exactly the kind of one-click irreversible action the module's
  standing rule (destructive actions get named, deliberate confirmation) would require designing
  a confirmation for, and 11a does not need one.
- **Follow "View usage report" / "View full usage report".** Plain navigation to
  `tprm.settings.ai.usage`, no confirmation, no unsaved-changes warning needed unless the form is
  dirty — if dirty, standard unsaved-changes browser warning applies (native `beforeunload`
  pattern already used elsewhere for long forms).

## 5. Accessibility (WCAG 2.1 AA)

- **Tri-state controls are native radio groups**, each inside a `<fieldset>` with a `<legend>`
  naming the service (or "Master switch"). A screen reader announces the group name, the number
  of options, and which is currently checked — a feature a custom dropdown or a styled checkbox
  would not give for free.
- **The three-layer table** is a real `<table>` with a `<caption>` ("How AI is switched on for
  this tenant, by layer") and `<th scope="col">`/`<th scope="row">` headers, so it is navigable
  by row and column with a screen reader's table commands, not just readable top to bottom.
- **Disabled controls carry their reason as visible preceding text, not only `aria-describedby`.**
  Several screen readers announce "dimmed" or skip disabled form controls in the tab order
  without reading an associated description; placing the explanation as ordinary text
  immediately before the control in DOM order means a reader tabbing past it, or reading
  linearly, still encounters the reason.
- **Colour is never the only signal.** Every badge (On/Off/Not set/Not built/Blocked) carries its
  state as text. The three tones (`red`, `amber`, `emerald`/neutral grey) are chosen to hold
  sufficient contrast against white per the existing `Programme.jsx` banners (`*-900` text on
  `*-50` background); no new shade is introduced, and Gold (`#D4AF37`) is not used as body text
  colour on this screen (insufficient contrast on white at small sizes) — reserved for accent use
  only, consistent with the theme.
- **Keyboard path**, top to bottom, all native and requiring no custom `tabindex`: banners (not
  focusable) → layer table (not focusable, informational) → master switch radios → per-service
  rows in table order (label text, then either the "Not built" badge, disabled radios, or
  interactive radios) → endpoint `<select>` → the two cap `<input type="number">` → Save button →
  "View usage report" link.
- **Save confirmation is announced, not only shown.** The "Saved." text renders inside a
  container with `aria-live="polite"` so a screen-reader user gets confirmation without needing
  to find and re-read the button area.
- **Field errors** use `role="alert"` and are associated to their control via
  `aria-describedby`, matching the existing `Field` pattern in `Programme.jsx`.

## 6. Low bandwidth (100 kbps)

- The entire screen is one Inertia page response containing every value it needs; there is no
  client-side polling and no background fetch of breaker or usage figures after load — they are
  a snapshot as of the request that rendered the page, and the screen says so is implicit (no
  claim of live-updating is made anywhere in the copy).
- No chart library, no icon webfont beyond what the shell already loads product-wide (the
  `material-symbols-outlined` glyphs already paid for by every other TPRM screen); nothing new is
  added to the page weight budget that the rest of the module does not already carry.
- Save is a single small `PUT` of at most a dozen scalar fields — no file upload, no large
  payload.
- On a stalled Save request, the button stays in its disabled "Saving…" state rather than
  reverting silently, so a person on a slow link is not tempted to click Save a second time and
  fire two writes.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| `deployment.llm_enabled` | `config('services.llm.enabled')` |
| `deployment.module_enabled` | `config('tprm.ai.enabled')` |
| Per-service `deployment_enabled` | `config('tprm.ai.services.<key>')` |
| Per-service `implemented` | `config('tprm.ai.services.<key>.implemented')` / the implemented-services list, §4.2 of the contract |
| Per-service `effective` and its `layer` | `LlmGateway::availability()` — computed server-side by the resolution order in contract §5; never re-derived in JavaScript |
| Master `effective` and its layer | `effective.enabled` and `effective.master_layer`, both server-side, same rule. **`master_layer` was missing from the response and the screen derived it in JS; ruled 2026-09-11 (frontend deviation 1) — the field is added and the derivation is deleted.** A JS fall-through cannot express `cap` or `breaker` and would name `tenant_master` for a reason that was neither, sending an administrator to change a switch that will not fix anything. |
| `tenant.*` (all `tp_settings.ai_*` columns) | this organization's `tp_settings` row |
| `stale_profile` | `EndpointResolver`'s fallback fact when the stored key is not in `config('llm.profiles')` |
| `breaker.state`, `.opens_at`, `.consecutive_failures` | `CircuitBreaker::state()`/`opensAt()` for the resolved profile, cache-backed |
| `cache_store_is_shared` | resolved cache store identity vs. `config('llm.cache_store')`/`config('cache.default')` — whether it is a process-local store such as `array` |
| `month.usage_month`, `.call_count`, `.total_tokens` | `llm_usage_events` aggregated for this `organization_id` + current `usage_month`; `total_tokens` is the **stored** column, summed, and is null (not zero) whenever the backend reported no counts for every call in the window |
| `month.token_cap` / `.call_cap` | `tp_settings.ai_monthly_token_cap` / `.ai_monthly_call_cap` |
| `updated_by` / `updated_at` | `TprmAuditable` trail on `TprmSetting` (added in 11a §7.2), most recent entry |

**No number on this screen is a cost or a currency.** The self-hosted profile in this deployment
declares no `unit_cost_per_1k_tokens_minor` and no `currency`; nothing computed from those fields
is rendered. If a future priced profile is added, this spec's cap and usage figures remain
token/call counts unchanged — a cost line is out of scope for 11a per ADR 0015 §4 and would be
specified separately if it is ever built.

## 8. What this screen must refuse to show

- No cost, spend or currency figure, anywhere, for any profile shipped in this deployment.
- No raw endpoint URL or hostname — only a profile's configured label and key.
- No interactive toggle for `subprocessor_discovery`, `response_quality`,
  `adverse_media_triage` or `scoping_assistant`. They render "Not built in this release" and
  nothing else.
- No collapsing of `ai_enabled === null` into "Off". The two render in visibly different badges
  with different text.
- No effective-value-only view. The three-layer table is always rendered, always expanded, never
  behind a "details" disclosure a busy admin could miss.
- No `0` standing in for "not reported" or "uncapped." A null cap reads "No limit"; a null token
  count reads "not reported by this backend."
- No apparent means, through this screen, for a tenant to enable a service the deployment has
  disabled — the control does not render as clickable-then-refused; it renders disabled with its
  reason stated beside it.
