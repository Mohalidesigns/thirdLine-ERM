# ADR 0018 — BCMS Phase 2C is Entra ID only, and the sync stages a proposal rather than writing a roster

**Status:** Accepted · **Date:** 2026-09-13 · **Phase:** BCMS Phase 2C (reduced) · **Author:** architect
**Requested by:** product owner — "Entra only, as an integration part on the settings for the admin, so the module can be concluded"
**Consumers:** integrations-engineer **(lead)** · ui-designer · backend-engineer · reliability-engineer · frontend-engineer · compliance-analyst · qa-engineer · code-reviewer · Phases 6 and 7 (already shipped against the substrate) · Phase 11 (KRI) · Phase 12

## Context

Phase 2C was moved to Week 2 precisely because `bcms_contacts` is the substrate
the call tree and EMNS stand on (Orchestration §1, correction 1). It was then
never built, and Phases 6 and 7 both shipped anyway, each recording in its notes
why it did not wait: Phase 0 froze `bcms_contacts`, `ContactResolver` and the
`AudienceRule` grammar at G0, and `TreeProposalService` reads
`bcms_contacts.manager_user_id` and nothing else about any directory. That was
the right call and it holds. What 2C still owes is **the roster itself**.

Three facts from the code decide the shape of this phase, and two of them are
not in the prompt.

1. **Nothing in `app/` has ever created a contact.** `grep -rn "Contact::create\|new Contact" app` returns
   nothing; every `bcms_contacts` row in existence was written by a seeder. There
   is no contact directory screen and no contact CRUD route — the only write path
   is `bcms.call-tree-tests.nodes.fix-contact`, which repairs one number. The
   Entra sync is therefore the **first** bulk write path into the roster, and it
   arrives with no screen to inspect its output unless this phase builds one.
2. **SCIM already exists in the ERM core and does not feed BCMS.** `ScimToken`,
   `App\Http\Middleware\AuthenticateScim`, the `throttle:scim` + `scim.auth`
   group in `routes/api.php` and `Scim\{ScimUserController,ScimGroupController}`
   are live and complete — and they provision `App\Models\User` only. No line of
   `app/Http/Controllers/Scim` mentions `Contact`. So "SCIM inbound" for BCMS is
   not a connector to build; it is a **bridge from the existing endpoint into the
   staging table**, which is a smaller piece of work than the prompt implies and
   still not this phase's.
3. **The manager chain cannot be expressed for a directory-sourced roster
   today.** `manager_user_id` is a foreign key to `users`, and
   `TreeProposalService::arrange()` resolves it through contacts that carry a
   `user_id`. `bcms_contacts.user_id` is nullable *on purpose* — a guard, a
   cleaner and a contractor have no login — so a 200-person Entra sync into a
   tenant with twelve platform users produces a **flat tree**: every person a
   root, no tiers, criterion 3 unmeetable. `EscalationService::managerContactFor()`
   has the same hole from the EMNS side. This is the one structural gap in the
   G0 freeze and §2 below is the ADR that closes it.

The product owner's decision is to conclude the module: Entra ID only, delivered
as an integration section in the BCMS settings area. The rest of 2C is deferred
by name, not dropped.

## Decision

### 1. Scope, and the named phase the rest goes to

| In Phase 2C (reduced) | Out — **deferred to Phase 2D, "Identity breadth, self-service and verification"** |
|---|---|
| Microsoft Entra ID connector, one per tenant, configured in the BCMS settings area | On-prem AD over LDAPS (Blueprint §8.1's primary path for Nigerian banks) |
| App-only client-credentials read of `/users`, `/users/{id}/manager` | SCIM inbound → contacts (the ERM core endpoint stays as it is: users only) |
| Nightly full reconciliation + optional 15-minute delta | HRIS bridge (SAP / Oracle / Workday / local payroll) |
| Attribute mapping with Blueprint §8.2 defaults, editable | CSV/Excel import for the non-directory population |
| Staging table, change report, per-change and bulk approve/reject | My Emergency Profile (self-service channels, next-of-kin, language, consent capture) |
| Call-tree impact on every leaver and mover | Quarterly verification campaigns and manager escalation of non-responders |
| Leaver deactivation, never deletion | Break-glass offline roster: encrypted export + per-department printable PDF |
| Connector screen + sync change review screen | Contact directory screen and the data-confidence dashboard |
| `bcms.identity.manage` / `bcms.identity.review` | Group → team / warden / crisis-role mapping rules |

**Why Entra rather than LDAPS, given the blueprint calls LDAPS primary.** Entra
is the only one of the five paths that can be exercised end to end from this
repository: it is HTTP, so `Http::fake()` governs it and a
`FakeDirectoryClient` needs no network, no LDAP extension, no test directory
server and no VPN into a customer. LDAPS needs `ext-ldap`, a running directory
and a certificate chain to be a real test rather than a mocked one, and an
integration nobody can test is the thing this product has been paying for all
year. Entra also carries the delta-query and manager-relationship semantics the
staging design needs, so building it first means 2D's LDAPS path implements a
**contract that already has a working consumer** rather than inventing one.

**Phase 2D is unscheduled and that is a decision, not an oversight.** It is
sequenced no earlier than the W14 integration window and its first two items
(self-service consent capture and the verification campaign) are a **hard
prerequisite for any pilot that promises SMS drills** — see Consequences, which
states why in one paragraph an account manager can read.

### 2. Schema — three tables, one column, one unique index

This is a structural change after the freeze, so this ADR is the instrument
(Orchestration §8, rule 2). The count is the honest one: a phase whose entire
subject is a capability the schema has no home for brings its own tables; the
discipline the freeze enforces is that **no other phase's tables move**, and
none do.

#### 2.1 `bcms_contacts.manager_contact_id` — the one column

`unsignedBigInteger` nullable, FK → `bcms_contacts.id`, `nullOnDelete`, plus
`unique(['organization_id', 'ad_object_guid'])` as the sync's match key.

`manager_contact_id` becomes **the** reporting edge. `manager_user_id` stays and
is narrowed: it is the platform-user denormalisation that `BiaCampaignService`,
`EscalationService` and `ReminderAudienceResolver` already use to reach a manager
who has a login. For any row the sync touches, `manager_user_id` is **derived**
from `manager_contact_id` (the manager contact's `user_id`, or null when the
manager has no login), so the two cannot disagree for a synced row.
`TreeProposalService` and `EscalationService` read `manager_contact_id` first and
fall back to resolving `manager_user_id` → contact, which keeps every existing
seeder, fixture and hand-maintained roster working unchanged.

- **Rejected: require a `users` row for every directory object.** That is
  provisioning, which SSO and the existing SCIM endpoint own, and it would mint
  logins — and licence counts — for five thousand staff including the guards and
  cleaners the nullable `user_id` exists for.
- **Rejected: store the manager's directory GUID and resolve at query time.** It
  needs a column either way, and it gives a join on a string that means nothing
  once a contact is created by hand.
- **The new edge is self-referential, so it ships with a cycle guard.** A
  two-person management loop in a directory export is not hypothetical —
  `TreeProposalService::arrange()` already walks with a visited set because of it,
  and the product has already paid for one cyclic parent edge with a 512 MB OOM.
  Anything that walks `manager_contact_id` walks it with a visited set and a
  depth bound, and the loop is *reported* (an unplaced row with a reason), never
  silently flattened.

Nothing else on `bcms_contacts` changes. `source` already has
`ContactSource::Entra`, `ad_object_guid` (64 chars) holds a 36-character Entra
object id, `ad_synced_at` exists, and the consent, verification, language and
geo columns are all in the never-written list of §3.4.

#### 2.2 `bcms_identity_connectors`

`id`, `uuid` (aggregate root, ADR 0007 deviation 4), `organization_id`,
`provider` (20, `entra` only), `name` (120), `directory_tenant_id` (100),
`client_id` (100), `client_secret` (**TEXT**, nullable, `encrypted` cast),
`token_base_url` (190), `graph_base_url` (190), `directory_filter` (255, nullable),
`attribute_map` (**json**, nullable), `sync_schedule` (20: `nightly` |
`nightly_plus_delta` | `manual`), `auto_apply_policy` (20: `none` | `safe_only`),
`delta_link` (TEXT, nullable), `credential_expires_on` (date, nullable),
`is_active` (bool, default **false**), `created_by`, `updated_by`, timestamps.
`unique(['organization_id', 'provider'])`.

Six of those need their reason on the record:

1. **`client_secret` is TEXT and is never a json column.** Laravel's encrypter
   produces a base64 envelope, which is not valid JSON; MariaDB enforces that
   through the inline `json_valid()` CHECK it attaches to a json column. This is
   exactly the defect that meant `INSERT INTO connectors` had **never once
   succeeded on a real database** — `2026_09_07_130001_store_connector_config_as_text_not_json.php`
   is the post-mortem. `attribute_map` is json because it *is* json: it is not
   encrypted, and it holds a field map, not a credential.
2. **`attribute_map` is nullable and the defaults live in code.** A null map
   reads Blueprint §8.2's defaults from `App\Support\Bcms\DirectoryAttributeMap`,
   so improving a default does not need a data migration across every tenant that
   never touched the screen.
3. **No `status`, no `last_sync_at`, no counters on the connector.** Health and
   last-success are *derived* from `bcms_identity_sync_runs` — ADR 0013 decision
   2's argument, unchanged: a stored health column is only as true as its last
   write, and a dashboard reading one reports the health of the scheduler.
   `delta_link` is not a derived figure; it is the pointer the next delta run
   needs, which is state.
4. **`credential_expires_on` is input, not derivation.** Graph does not tell a
   client when its secret expires; the admin copies the date from the app
   registration. Without it a secret lapses and the roster silently freezes —
   which is the failure mode this module exists to prevent, applied to itself. The
   watchdog warns on it.
5. **`is_active` defaults to false.** A connector is created disabled and nothing
   syncs until a human has run Test connection. A connector that begins syncing
   the moment it is saved makes the first mistake a live one.
6. **No soft deletes and no delete route.** Runs and change rows are the audit
   trail of a directory read; a delete would either orphan them or erase them.
   Deactivation is the off switch, and `unique(organization_id, provider)` stays
   honest without a `deleted_at` arm. When a second provider arrives in 2D the
   unique index already has room for it — which is why `provider` is in the key
   despite having one case today.

**Rejected: hang this off `App\Models\OrganizationSsoSetting`.** It is tempting —
it already holds an Entra client id and an `encrypted` `oidc_client_secret` for
the same directory. It is wrong twice. Those credentials are a **delegated**
OIDC app used at sign-in, resolved *outside* the tenant scope by
`resolveBySlug()`/`resolveByEmailDomain()` before anybody is authenticated; Graph
app-only reads need a different grant, a different consent and an application
permission a login app must not hold. Putting a directory-read credential on the
row the login path reads unscoped is how a BCMS setting becomes an
authentication incident. They are also independently deletable facts: a bank may
federate without syncing, or sync without federating.

**Rejected: reuse `connectors` / `Connector`.** That table belongs to the measure
engine's scheduled pulls, is driven by `ConnectorRun` and `field_map`, and its
`type` vocabulary is the KRI module's. Borrowing it would put BCMS identity state
behind another module's screens and scheduler.

#### 2.3 `bcms_identity_sync_runs`

`id`, `uuid`, `organization_id`, `identity_connector_id` (FK), `trigger` (20:
`scheduled_full` | `scheduled_delta` | `manual`), `started_at`, `finished_at`
(nullable), `status` (20: `running` | `success` | `partial` | `failed`),
`directory_objects_read`, `pages_fetched`, `joiner_count`, `leaver_count`,
`mover_count`, `contact_change_count`, `auto_applied_count`, `pending_count`
(all unsigned ints, default 0), `error_class` (190, nullable), `error_code` (40,
nullable), `triggered_by` (FK users, nullable — null is the scheduler),
timestamps. Indexes `(organization_id, identity_connector_id, started_at)` and
`(organization_id, status)`.

- **`status` is stored here and computed on the connector, and the difference is
  the point.** A run's outcome is a fact about a past event. Call-tree staleness
  is a fact about the present, which is why ADR 0013 refused to store it.
- **`error_class` and `error_code`, never a provider message.** Phase 7's gate
  found provider free text carrying an MSISDN into a seven-year regulator-facing
  column three separate times. Graph's `error.code` is a bounded protocol field
  and is kept, exactly as Meta's numeric code and the SMTP reply code are;
  `error.message` is never stored, never logged, and never rendered. The rule
  extends to the `Log::` calls in the sync path: exception class plus HTTP
  status, never `getMessage()` — a Guzzle `ConnectException` message carries the
  full request URI, which for an aggregator is a credential.
- **Named integer counters, not a `counts` json blob.** The review queue filters
  and sums these, and MariaDB 10.4 does not have the JSON functions that would
  make a blob queryable. Portable SQL is a correctness requirement here, not a
  style preference.

#### 2.4 `bcms_identity_sync_changes` — the staging table

`id`, `organization_id`, `sync_run_id` (FK, cascadeOnDelete), `contact_id` (FK,
nullable — a joiner has no contact yet), `kind` (20: `joiner` | `leaver` |
`mover` | `contact_change`), `directory_object_id` (64), `subject_name` (200),
`before_json` (json, nullable), `after_json` (json, nullable), `impact_json`
(json, nullable), `requires_ack` (bool, default false), `decision` (20:
`pending` | `approved` | `rejected` | `auto_applied` | `superseded`, default
`pending`), `decided_by` (FK users, nullable), `decided_at`, `applied_at`,
`apply_error_class` (190, nullable), timestamps.
`unique(['sync_run_id', 'directory_object_id', 'kind'])`; indexes
`(organization_id, decision)`, `(organization_id, kind, decision)`,
`(contact_id)`.

- **No `uuid`, and it is routed anyway.** Change rows are a child table, so ADR
  0007 deviation 4 gives them no uuid; they are addressed **nested under their
  run** — `identity/runs/{run}/changes/{change}` with `->scopeBindings()`, which
  is also what ADR 0017 §5 requires. `BcmsRouteKeyTest` already permits a
  numeric nested child.
- **`requires_ack` is stored, not computed at read time.** The impact was
  evaluated against the estate as it stood when the sync ran. Recomputing it when
  somebody opens the queue would let an unrelated tree edit silently turn a
  change that needed a signature into one that did not.
- **`superseded` is a decision value, and it is what stops the queue growing
  every fifteen minutes.** A delta run that re-detects a still-pending change for
  the same object and kind supersedes the earlier row rather than adding a second.
  Without it, a leaver nobody has reviewed appears ninety-six times a day.
- **`after_json` is the field-level provenance store**, which is what makes
  §3.4's never-overwrite rule work without a per-field provenance column on
  `bcms_contacts`.
- **`before_json`/`after_json` hold personal data** (name, work mobile,
  department) and therefore need an NDPA register entry with a retention period.
  compliance-analyst owns the wording; the engineering commitment is that the
  columns hold only the mapped attributes, never the whole Graph object.

#### 2.5 Manifest and verification

`bcms:verify-schema --write` regenerates `database/schema/bcms-manifest.php` **in
the same commit as the migration**, so the diff is read at review. The freeze now
reads **15 (P1) → 8 (P2) → 5 (P3) → 1 (P4) → 0 (P5) → 1 (P6) → 0 (P7) → 0 (P7.5)
→ 2C: 3 tables, 1 column, 1 index.**

### 3. The sync

#### 3.1 Authentication and the read

App-only client credentials: `POST {token_base_url}/{directory_tenant_id}/oauth2/v2.0/token`
with `grant_type=client_credentials` and `scope={graph_base}/.default`. The access
token is cached (cache, not database) under a key that includes the connector id,
for `expires_in` minus 300 seconds, and is never logged, never rendered and never
persisted.

Users are read from `GET {graph_base}/users` with an explicit `$select` of the
mapped attributes only, `$top=999`, following `@odata.nextLink` to exhaustion.
The manager edge comes from `$expand=manager($select=id)` on the same request,
falling back to `GET /users/{id}/manager` for any row the expand omits — and
**a 404 from that endpoint means "no manager", not a failure**, which is the trap
that would otherwise mark every executive's run as partial. `sync_schedule =
nightly_plus_delta` adds `GET /users/delta`, persisting `@odata.deltaLink` to
`connectors.delta_link`. Delta carries attribute changes and `accountEnabled`
flips; it does **not** carry relationships, so a delta run never re-resolves a
manager edge — a delta that shows a department or manager-adjacent change marks
the contact for the next full run, and the nightly full reconciliation is what
keeps the hierarchy true. Saying this out loud is cheaper than discovering that
the 15-minute sync quietly stopped maintaining the tree.

#### 3.2 Read-only, enforced rather than asserted

Standing rule 3 is non-negotiable and "the reviewer confirms by inspection" is
not an enforcement mechanism. Three things make it one:

1. **`DirectoryClient` has no write method.** There is nothing to call.
2. **`EntraGraphClient` exposes only `get()`-shaped requests**, and a guard test
   asserts no `Http::post|put|patch|delete` anywhere under
   `app/Services/Bcms/Identity` targets a Graph host — the single POST is the
   token endpoint, allowlisted by URL in the test.
3. **The requested permission is `User.Read.All` and nothing else.** The
   connector screen states it; `Directory.Read.All` is accepted where a bank's IT
   will only grant the broader one. `.default` grants whatever the app
   registration holds, so the customer-side instruction is explicit that the
   registration must hold no `*.ReadWrite.*` permission, and the run records
   which scopes the token actually came back with so an over-privileged
   registration is visible on the screen rather than in a pen test.

**`directory_filter` is an OData `$filter` over user attributes, not a group id.**
The prompt calls it a group filter; scoping by group membership needs
`GroupMember.Read.All`, a second read scope this phase refuses. Group-based
scoping arrives with 2D's group → team rules, which need that scope for their own
reasons.

#### 3.3 Nothing is written live

Every run writes only to `bcms_identity_sync_changes`. Application is a separate,
audited step.

**Auto-apply has two settings and there is deliberately no third.**
`none` reviews everything; `safe_only` auto-applies changes whose `impact_json`
is empty. There is no `all`, because the rule is unconditional: **a change that
breaks a call tree or empties a saved audience requires BC-admin
acknowledgement.** A tenant switch that could turn that off would be the switch
somebody flips on a busy Friday.

`requires_ack` is set when any of these holds:

- the contact is a node on any `approved` call tree — with tree name, tier and
  `downstream_blocked_count` computed the way `BrokenBranchAnalyser` computes it
  (people actually left unreached, never the descendant count);
- the contact is the sole member of a must-reach node, or a named deputy;
- the contact is a static member of a `bcms_saved_group`, or a change to their
  `business_unit_id`/`site_id`/`is_active` takes a dynamic group's resolved count
  to zero — evaluated through `AudienceResolver`, which already fails closed on a
  cycle;
- the change moves a contact out of a business unit that a Phase 5 reminder
  ladder or a Phase 7 alert audience currently resolves through.

#### 3.4 What a sync may write, and what it may never touch

Applying a change writes **only** this allowlist, held as a constant on the
writer and asserted by a test: `full_name`, `employee_id`, `title`,
`business_unit_id`, `site_id`, `email`, `mobile_primary`, `manager_contact_id`,
`manager_user_id` (derived), `is_active` (deactivation only), `ad_object_guid`,
`ad_synced_at`, `source`.

**Never written, on any row, by any sync:** `whatsapp`, `mobile_secondary`,
`next_of_kin`, `channel_preferences`, `preferred_language`, `consent_status`,
`consent_captured_at`, `consent_withdrawn_at`, `verification_status`,
`last_verified_at`, `latitude`, `longitude`, `geo_last_known`, `user_id`.

Two of those need their reason stated:

- **`preferred_language` is not synced.** Entra's `preferredLanguage` is a UI
  locale. The language somebody wants an evacuation instruction in is a different
  fact, and getting it wrong is a safety incident rather than a formatting bug
  (Phase 7 §4). It stays `en` until a person says otherwise in 2D.
- **`consent_status` is not synced, and cannot be.** A mobile number in a
  directory is not the person agreeing to be texted on it —
  `ConsentStatus::permitsPersonalChannel()` is an allow-list and `not_requested`
  refuses exactly like `withdrawn`. See Consequences: this is the reduction's
  sharpest edge.

**Within the allowlist, a field is overwritten only if a human has not edited
it.** The comparison is against the last applied `after_json` for that contact:
live value equals last synced value, or is null → overwrite; otherwise raise a
`contact_change` for review and leave the value alone. That is why the staging
table is the provenance store, and it means a self-supplied value survives every
subsequent sync without a per-field provenance column.

**A leaver is deactivated, never deleted.** `accountEnabled = false`, or absence
from a full reconciliation, sets `is_active = false` and nothing else. The row is
referenced by call-tree nodes, cascade test nodes, alert recipients and delivery
evidence; deleting it would rewrite history a Nigerian examiner is entitled to
read. `ContactResolver::canReach()` already refuses an inactive contact, so
deactivation is complete as a safety measure on its own.

**Source.** Rows the sync creates, and existing rows it matches by
`ad_object_guid` (then `employee_id`, then `email`) become
`ContactSource::Entra`, so `isDirectorySourced()` stays truthful and the hygiene
report can say which directory a stale record came from. Self-supplied data is
protected by the never-write list, not by the `source` value — one enum on the
row could never have carried per-field provenance.

**Audit.** `BcmsAuditable` on all three models, plus explicit
`recordAudit()` events: `identity.connector.created`, `identity.connector.updated`,
`identity.connector.tested`, `identity.sync.started`, `identity.sync.finished`,
`identity.change.approved`, `identity.change.rejected`,
`identity.change.auto_applied`, `contact.synced`, `contact.deactivated`. The
longest is 38 characters, inside the 60 ADR 0014 bought. No morph-map entry is
needed: `bcms_audit_logs.auditable_type` stores the FQCN, and
`Relation::enforceMorphMap` governs the dependency morphs of ADR 0002, not this.

### 4. The boundary contract: `App\Services\Bcms\Identity\*`

```
App\Contracts\Bcms\DirectoryClient      interface — the only surface the sync knows
App\Services\Bcms\Identity\EntraGraphClient    implements it over the Http facade
App\Services\Bcms\Identity\FakeDirectoryClient implements it from a fixture array
```

`DirectoryClient` returns a paged iterable of `DirectoryUser` value objects plus
a delta cursor; it has no write method and no Graph vocabulary in its signature,
so 2D's LDAPS and HRIS paths implement the same interface. It is bound in
`AppServiceProvider` — **BCMS still has no service provider** (ADR 0007 deviation
2, and there is no new wiring here that would justify one: no observer, no
listener, no rate limiter, no asserted policy map).

**`EntraGraphClient` uses the `Http` facade, not a Graph SDK.** That is what puts
`Http::fake()` and `Http::preventStrayRequests()` in charge of every test —
`Phase2BiaEngineTest` already establishes the pattern — and it keeps the
credential handling, the timeouts and the error classification in the same shape
as Phase 7's `HttpChannel`, which has been through three rounds of a gate on
exactly this. **No test may reach the network.** The qa gate asserts
`preventStrayRequests()` is in force in every test that exercises the sync, and
`FakeDirectoryClient` is what the seeder and the acceptance tests run against.

**Queues and schedule (reliability-engineer).** Both jobs go on the existing
`bcms-sync` queue — `config('bcms.queues.sync')`, one Horizon supervisor,
`tries = 1`, 3600s timeout, `nice 10`, chosen in Phase 0 precisely because a
half-run sync retried from the top writes the same contacts twice. Nothing about
identity justifies a fifth queue. In `routes/console.php`, beside the eight BCMS
entries already there: the full reconciliation nightly at **02:30**, the delta
`everyFifteenMinutes()`, both `withoutOverlapping()`. Both commands are
per-tenant loops that skip a tenant with no active connector, and neither runs
when `features.bcms` is off. `BcmsWatchdog` gains two checks: a connector whose
last successful run is older than twice its schedule, and a
`credential_expires_on` inside thirty days.

### 5. Screens — two, and the ui-designer specs both before any React

1. **Identity connector** — a section in the BCMS settings area (`bcms.settings.*`
   is one page today; this is its second tab or an adjacent page, the designer's
   call). Configuration; **Test connection** (token + one page of one user, no
   write, no staging rows); **Sync now**; last-run health and history from the run
   table; the attribute map with §8.2 defaults, a per-field "default" marker and
   the never-synced list shown as read-only so nobody looks for the missing
   fields; the declared Graph scope; the credential-expiry warning; and a roster
   summary (contacts by source, unmatched departments, contacts with no manager
   edge, consent not requested) so an admin can see what the sync produced while
   the contact directory remains in 2D. Secrets are write-only: the field shows
   whether a secret is set and never its value.
2. **Sync change review** — joiners / leavers / movers / contact changes as tabs
   over one queue; per-row before → after; the call-tree impact panel naming the
   tree, the tier and the downstream-blocked count in the sentence Phase 6 proved
   is the demo ("Musa Bello left; he was Tier 2 in the Operations tree with 12
   downstream staff"); approve/reject per row and in bulk; `requires_ack` rows
   visually separated and **excluded from a bulk approve unless explicitly
   selected**; superseded rows hidden by default.

Both at 360px, WCAG 2.1 AA, and usable at 100 kbps (Definition of Done). Empty,
loading, error, no-connector and never-synced states specified, not improvised.

### 6. Acceptance criteria for the reduced phase

Derived from prompt criteria 1, 2, 3 and 8. Each is an automated test, not a
screenshot.

| # | Criterion |
|---|---|
| 1 | A fake directory of **~200 users** across the 12 seeded Kano Heritage departments, with a **5-level manager chain**, syncs in one full reconciliation and produces a change report with correct joiner/leaver/mover/contact-change counts. Paging is exercised (`$top` smaller than the directory) and the run row records `pages_fetched > 1`. |
| 2 | Of the **12 leavers**, one who is a Tier-2 call-tree node is flagged with `requires_ack`, naming tree, tier and downstream-blocked count; it does **not** apply until acknowledged; the other pending changes can be applied without it. |
| 3 | `TreeProposalService` returns a correct **5-level** proposal for a department from the synced `manager_contact_id` edges — including for contacts with no `user_id`, which is the case that is impossible today. The **8 movers** re-parent correctly on the next run. |
| 4 | **No write scope, no write call.** The guard test of §3.2 passes; the token request asks for `User.Read.All` only; a review-gate confirmation that no code path can write to the directory. |
| 5 | A directory value a human has edited is **not** overwritten: it raises a `contact_change` instead. A self-supplied `whatsapp`, `preferred_language` and `consent_status` are **unchanged** by a sync that would otherwise touch the row. |
| 6 | A leaver is **deactivated, never deleted**; their call-tree node, cascade history and alert-delivery rows still resolve. |
| 7 | A management **loop** in the fake directory is reported as unplaced with a reason and does not hang, recurse or OOM. |
| 8 | `Http::preventStrayRequests()` is in force across the identity tests; a Graph 401, a 429 with `Retry-After`, a 503 and a manager 404 each produce the right run status and an `error_class`/`error_code` with **no provider message text anywhere** in the run row, the change rows or the log. |
| 9 | Every route carries a permission; tenancy isolation holds; every state change writes an audit row (`bcms:verify-schema` clean, `RouteAuthorizationTest`, `TenancyIsolationTest`, `PermissionCatalogCoversRoutesTest` green). |

**Test data: ~200 users, not 5,000.** The prompt asks for 5,000 and 5,000 is the
right number for a *load* test, which belongs with Phase 12's G3 work alongside
the EMNS load test Phase 7 deferred there for the same reason. 200 across 12
departments with a 5-level chain, 12 leavers, 8 movers and 30% missing mobiles
exercises every branch of the change detector and runs inside a test suite.
Paging is proven by lowering `$top`, not by inflating the fixture.

### 7. Permissions

`bcms.identity.manage` **already exists** in
`App\Authorization\RiskPermissionCatalog` and is granted to
`chief-risk-officer`. One is added:

| Permission | Meaning | Granted to |
|---|---|---|
| `bcms.identity.manage` | Configure the connector, hold its credentials, run a sync. | `chief-risk-officer` (unchanged), super-admin |
| `bcms.identity.review` | Review the sync change queue and approve or reject changes. | `$riskManager` — the BC Coordinator of Blueprint §13 |

The split follows the catalogue's existing logic: holding a credential that reads
the bank's whole directory is an administrator's authority; deciding that Musa
Bello has left and his branch needs re-parenting is day-to-day continuity work.
Descriptions are mandatory and load-bearing (standard §2). The seeder needs no
edit — it reads the catalogue through `SeedsPermissions` — and
`PermissionCatalogCoversRoutesTest`, `RouteAuthorizationTest` and
`SuperAdminReachesEveryScreenTest` cover the rest. **The review queue is
deliberately not filtered per business unit:** a directory sync is an
organisation-wide fact, a leaver's downstream can sit in another unit, and a
change set half-approved per unit leaves the roster inconsistent with the
directory. That is why `bcms.identity.review` is a group-level grant and is not
given to the Department BC Champion role.

### 8. ADR 0017 applies to these routes from day one

All three new models are **organisation-level** under ADR 0017 §2, and each
earns its one-sentence reason in the pinned map of
`tests/Feature/Bcms/BcmsRecordVisibilityTest.php`:

- `IdentityConnector` — one per tenant, holds no business unit and should not;
- `IdentitySyncRun` — a record of an organisation-wide directory read;
- `IdentitySyncChange` — staged directory facts, reviewed at group level (§7).

ADR 0017's remediation **has not been implemented yet** (commit `750a2e0`, "it is
not built yet"), so whichever of Phase 7.5 and this phase lands second adds the
three entries. Until then the routes stand on their `permission:` middleware, the
nested change route carries `->scopeBindings()` from the first commit, and any id
arriving in a **body** — bulk approve takes a list — is validated with a
tenant-bound `Rule::exists(...)->where('organization_id', ...)` scoped to the run,
never a bare `exists:` (standard §4). This phase does not wait for 7.5 and does
not pre-empt it.

## What this ADR deliberately does not do

- **It does not create a `BcmsServiceProvider`.** One binding goes in
  `AppServiceProvider` like every other BCMS binding. ADR 0007 deviation 2 stands.
- **It does not touch `ContactResolver`, `AudienceResolver`, the `AudienceRule`
  grammar, `NotificationChannel` or the reminder ladder.** The four G0 contracts
  are consumed unchanged. What changes is that `bcms_contacts` finally has a
  supply.
- **It does not register a KRI.** Phase 6 already computes call-tree data
  confidence from `last_verified_at`, and Orchestration §5 says the phase that
  produces a metric registers it once. The connector screen *reads* that figure.
- **It does not add a contact directory, a data-confidence dashboard, or any
  screen beyond the two in §5.** Every additional screen is 2D's.
- **It does not change the SCIM endpoint.** `ScimUserController` keeps
  provisioning users only. When 2D bridges it into contacts, it goes through the
  staging table like every other source — a real-time create that wrote a live
  contact would be a second, unreviewed path into the roster.
- **It does not install anything.** No Graph SDK, no LDAP extension, no Prism
  (ADR 0010), no MeiliSearch. `Http` and `Cache` are what this needs.
- **It does not widen `ad_object_guid`, `ad_synced_at` or `source`.** They were
  specified in Phase 0 for exactly this and they fit.
- **It does not decide the NDPA wording.** Lawful basis, purpose limitation,
  retention for `before_json`/`after_json`, residency and the DSAR position are
  compliance-analyst's, in `docs/compliance/ndpa-register.md`, before the gate.

## Consequences

- **The sharpest edge of the reduction: a synced roster cannot be SMSed for
  routine traffic.** The sync never sets consent, `ConsentStatus` is an
  allow-list, and the mechanisms that collect consent — My Emergency Profile and
  the verification campaign — are in 2D. So after 2C a bank has an accurate
  roster reachable on corporate email and Teams for drills and reminders, and on
  SMS, voice and WhatsApp **only for life-safety traffic**, under the narrow
  vital-interests basis already recorded in the NDPA register. That is correct
  behaviour and it will read as a defect to anybody who was shown an SMS drill.
  **2D must be scheduled before any pilot that promises SMS for non-life-safety
  traffic**, and the connector screen states the consent figure so nobody
  discovers this during an exercise.
- **The call tree becomes generatable for real.** `manager_contact_id` closes the
  gap Phases 6 and 7 both worked around; `EscalationService` reaches managers who
  have no login, which it silently could not do before. Both read the new edge
  with the old one as a fallback, so no existing test or seed changes.
- **`bcms_contacts` is a G0 frozen contract and this extends it.** One nullable
  column and one unique index; no consumer needs a code change, and two
  consumers (`TreeProposalService`, `EscalationService`) get a better answer for
  the same call. Broadcast to Track B and Track D: read §2.1 before touching a
  manager edge.
- **The 15-minute delta does not maintain the hierarchy.** By design (§3.1). The
  nightly full run does. A tenant on `nightly_plus_delta` still gets tree
  corrections once a day, and the screen says so.
- **A tenant with no connector is unaffected in every respect.** No schedule
  work, no screen change beyond an empty state, no behaviour change to any
  hand-maintained roster. The module remains behind `features.bcms`.
- **2D's scope is now written down** in §1 and is the definition of record until
  a `plans/bcms/prompts/PHASE-02D-*.md` exists. Nothing in the deferred column is
  dropped, and the two items that are prerequisites for a pilot are named as such.
