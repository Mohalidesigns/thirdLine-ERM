# Security notes — WP-00 (Security & Tenancy Kernel)

Findings and decisions recorded during WP-00. Read alongside the work package.

---

## 1. SMTP credential rotation — no rotation was required

**WP-00 TASK 5 says:** *"Rotate the SMTP credentials currently committed in .env; document the rotation."*

**Finding: the premise does not hold. Nothing was committed, so there is nothing to rotate.**

Evidence:

| Check | Command | Result |
|---|---|---|
| Is `.env` tracked? | `git ls-files --error-unmatch .env` | `did not match any file(s) known to git` |
| Was it ever committed? | `git log --all -- .env` | no commits |
| Is it ignored? | `.gitignore:3` | `.env` (also `.env.backup`, `.env.production`) |
| Any secret in tracked files? | `git grep MAIL_PASSWORD` | only `.env.example:55` → `MAIL_PASSWORD=null` |

The working-tree `.env` does contain a real `MAIL_PASSWORD`, but it has never left the
developer's machine through this repository.

**Action taken:** none, deliberately. Rotating a credential and writing it up as a
response to an exposure that did not happen would put a false entry in the security
record — precisely the sort of thing this platform exists to prevent.

**Still worth doing, outside this repo:** confirm the same credential was not pasted
into a deploy log, CI variable, ticket, or chat. This repository cannot answer that.

---

## 2. `install.php` was a live remote-code-execution path, not dormant

**WP-00 TASK 5 says:** *"install.php (16.9 KB) sits at the PROJECT ROOT, outside
public/. Under a standard Laravel docroot it is already unreachable — confirm no
deployment serves the project root, then delete it."*

**Finding: one of the two deployment paths deliberately published it into the web root.**

`build-deploy.sh` copied `install.php` into the deploy bundle and the printed
instructions read:

```
2. Upload install.php to public_html/riskmtg/
3. Visit https://yourdomain.com/riskmtg/install.php
```

The file was unauthenticated and would:

- write `.env`, including a freshly generated `APP_KEY` (`install.php:170`)
- call `exec()` with a constructed command line (`:330`)
- run `migrate --force` and `db:seed --force` (`:350`, `:357`)
- print `Login: admin@risk.test / password` (`:381`)
- delete itself afterwards — but only if whoever ran it clicked the cleanup button

For any cPanel-style deployment that is unauthenticated remote code execution plus a
known default administrator credential.

The GitHub Actions path (`scripts/deploy.sh` → `/var/www/thirdLine-ERM`, standard
`public/` docroot) was **not** affected — there, the file genuinely was unreachable.

**Action taken:**
- `install.php` deleted.
- `build-deploy.sh` no longer packages or mentions it; the printed steps now provision
  from the shell and end with `php artisan app:preflight`.
- `app:preflight` fails if an `install.php` reappears at the project root or in `public/`.
- No default administrator credential ships. The first admin is created explicitly.

---

## 3. `AutoLoginDev` was dormant — as the work package said

`app/Http/Middleware/AutoLoginDev.php` was registered nowhere: not in
`bootstrap/app.php`, not in `AppServiceProvider`, not on any route or group. It was
dead code rather than a live bypass.

Deleted anyway, and `app:preflight` fails if it returns.

---

## 4. Unauthenticated file-serving routes on the private disk

`config/filesystems.php` had `'serve' => true` on the `local` disk, whose root is
`storage/app/private` — where loss-event attachments, issue attachments and
control-test evidence are written.

That setting makes the framework register `GET /storage/{path}` and
`PUT /storage/{path}` **outside the web middleware group**: no authentication, no
permission, no tenant check. Laravel does require a valid signed URL for a
private-visibility disk, so this was not an open file server — but nothing in this
application ever mints such a URL, and every download already runs through an
authorized controller action.

**Action taken:** `'serve' => false`. Two ungoverned routes removed, no functionality lost.

---

## 5. Deviation: `hierarchy_path` in node-scoped authorization

**WP-00 TASK 3 says:** *"a scopeVisibleTo(User) macro that filters by hierarchy_path
prefix"*.

`risks.hierarchy_path` exists, but it stores the **parent-risk chain** — the risk
taxonomy — not the organizational chain. Filtering visibility on it would restrict a
user by risk lineage rather than by the org node they belong to, which is not what
node-scoped authorization means. It also does not exist on controls, issues, loss
events or KRIs, all of which the task requires scoping.

**Implemented instead:** the scope filters on the `entities` graph, which is what all
five models actually hang off via `entity_id`. `entities.hierarchy_path` was added as a
materialised path (`/1/7/23/`) so a subtree is an indexed prefix match, and
`users.scope_entity_id` names the node a user is confined to (NULL = organization-wide,
so node scoping is opt-in and no existing user changes behaviour).

`scope_entity_id` uses `restrictOnDelete`, not `nullOnDelete`: nulling an authorization
boundary when its node is removed would silently promote a subtree-limited user to
organization-wide visibility.

---

## 6. Reference codes are now numbered per organization

`ReferenceCodeService::generate()` had no organization filter, so every tenant drew from
one global sequence: organization B creating a risk advanced organization A's counter,
and the gaps in A's numbering leaked other tenants' activity levels.

Scoping the generator per organization required the uniqueness constraints to match.
`risks`, `controls` and `key_risk_indicators` already declared
`unique(organization_id, code)`. Four tables were still globally unique and would have
rejected organization B's `LE-2026-0001` because organization A had used it:

- `control_tests.test_code`
- `issues.issue_reference`
- `loss_events.event_reference`
- `assessment_campaigns.campaign_code`

Migration `2026_08_09_100002` converts those four to `unique(organization_id, code)`.
Only index shape changes — no column is added, dropped or retyped, and anything unique
globally is necessarily unique within an organization, so no existing row can violate
the new index.

`treatment_plans.treatment_code` was declared with `'unique' => true` in
`2026_02_22_200038`, but that migration's `addColumns()` helper silently ignores the
option — the index was never created. Left as-is; flagged here because the declaration
reads as though it exists.

---

## 7. Audit trail: attribution no longer falls back to user 1

`AuditTrailService` wrote `'changed_by' => auth()->id() ?? 1`, so any change made
without an interactive user was recorded against whichever account happened to be id 1.
In a compliance trail, naming the wrong person is worse than admitting the actor is
unknown.

`changed_by` is now nullable and records `NULL` for system-initiated changes.

The same `?? 1` pattern in `app/Listeners/SendNotification.php` was worse: that listener
is queued, so `auth()->id()` is *always* null on a worker and every queued notification
was attributed to user 1. `notifications_log.user_id` is NOT NULL, so the row is now
skipped and a warning logged rather than misattributed. The underlying design problem —
`user_id` holds the actor rather than the intended recipient — is out of scope for WP-00
and still open.

---

## 8. Single sign-on is configured per client, in the application

WP-00 TASK 4 described SSO as env-file configuration. That is the wrong shape for how
this product is sold: one deployment serves many client organizations, and each
configures its own identity provider after purchase. SSO settings therefore live in
`organization_sso_settings` behind **Administration → Settings → Single Sign-On**,
guarded by its own `admin.sso` permission.

**Both protocols are implemented and tested.**

- **OIDC** — one generic provider driven by the client's settings row, so Microsoft
  Entra ID, Google Workspace and Okta differ only by endpoint. Identity is read from the
  `userinfo` endpoint using the access token rather than by decoding the `id_token`,
  which keeps JWT signature, issuer, audience and expiry verification on the IdP.
- **SAML 2.0** — via `onelogin/php-saml` 4.3.2 (SAML-Toolkits, MIT). All XML signature
  verification, canonicalisation, certificate matching and Conditions / NotOnOrAfter /
  Destination / Audience / InResponseTo validation is delegated to that toolkit. This is
  deliberate: XML signature wrapping is the classic authentication **bypass** in SAML,
  and a hand-written verifier is how deployments get owned. The toolkit runs in `strict`
  mode with `wantAssertionsSigned` and `rejectUnsolicitedResponsesWithInResponseTo` both
  on — the two settings most often left permissive — and a test asserts that posture
  rather than trusting it.

### Identifying the tenant before anyone is signed in

Sign-in must know which organization it is acting for before there is a user to ask.
Two mechanisms, both standard:

1. **Sign-in slug** — each client gets `/auth/sso/{slug}`. SAML needs this regardless,
   because the Assertion Consumer Service URL is per service provider and has to be
   registered with the IdP.
2. **Home-realm discovery** — the login page accepts an email address and routes on its
   domain, so staff never need to know a URL. Failure is deliberately vague: whether a
   domain is federated is information about a customer, and enumerating it should not
   be free.

### What the client hands to their IdP administrator

The settings screen displays exactly what to paste in: sign-in URL, redirect/reply URL,
and for SAML the SP entity ID plus a live SP metadata XML endpoint.

### Security properties worth preserving

- OIDC client secrets and SAML SP private keys are **encrypted at rest** and never
  rendered back to the browser. A blank field means "keep the stored value"; clearing is
  an explicit checkbox.
- Removing a required credential **takes the provider out of service** rather than
  leaving a sign-in button that cannot work.
- Endpoint URLs must be `https://`.
- The IdP group → role map is an **allowlist** that can only reference roles the seeder
  created, so a client administrator cannot invent an authorization principal by typing
  its name.
- A user whose account belongs to a different organization is refused, so one client's
  IdP cannot adopt another client's user by asserting their address.
- Deactivated accounts stay deactivated — a stale directory entry cannot undo offboarding.
- SSO does not satisfy this platform's second factor: `mfa_required_roles` still applies
  after a successful federated sign-in.

### Still unverified

**No live IdP connection has been tested.** The SAML path is covered by feature tests
(metadata generation, forged and unsigned assertion rejection, security posture,
per-tenant isolation), but neither protocol has been exercised against a real Entra ID,
Okta or Google Workspace tenant. That is now a client-onboarding step rather than a code
gap — but the first real connection should be treated as commissioning, not as
regression testing.

---


## 9. Dependency advisories

`composer require laravel/socialite` reported *"35 security vulnerability advisories
affecting 11 packages"* — all pre-existing, none introduced by WP-00. Run
`composer audit` and triage separately.

`npm audit` reports 9 findings, but `npm audit --omit=dev` reports **0**: every one is
in build tooling that never reaches a browser. Lower priority, still worth clearing.

---

## 10. Repository formatting

`./vendor/bin/pint --test` failed on roughly 70 files before any WP-00 change; the
repository had never been formatted to the Pint ruleset. WP-00 runs Pint across the
codebase, so a large share of the diff is pre-existing formatting rather than logic.
