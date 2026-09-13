# Phase 6.2 — Organisation and single sign-on settings

`OrganizationSettingsController` (154 lines) and `SsoSettingsController` (212),
two Blade views totalling 739 lines. Three defects, and one of them is Phase
6.1's escalation reached through a different door.

## `admin.sso` was a path to super-admin

`role_map` and `default_roles` were filtered against `Role::all()`:

```php
$known = Role::query()->pluck('name')->all();
// …
if ($group !== '' && in_array($role, $known, true)) {
```

That is an allowlist against roles that do not exist, and no defence at all
against a real one. `super-admin` is a real one. Whoever held `admin.sso`
could map a directory group they belong to — or simply set
`default_roles: ["super-admin"]` — and `SsoProvisioningService::syncRoles()`
would hand the role out on their next sign-in:

```php
$user->syncRoles($roles);   // SsoProvisioningService, line 171
```

`Gate::before` answers every ability true for a super-admin. So this is the
whole platform, granted by an identity provider the platform does not control,
to anyone holding a permission whose name promises only that they may configure
sign-on.

It is closed the way 6.1 closed the user form, through the same decision:
`UserPolicy::grantSuperAdmin()`. Both screens now build their role list with
`App\Support\AssignableRoles`, which exists precisely so the four places that
need it — the user form's options and its validator, the SSO form's options and
its validator — cannot disagree. A form that offers a role its validator
rejects is the defect Phase 4.6 named; two validators that disagree about
`super-admin` is worse, because the laxer one is the one that matters.

**The login path is deliberately unchanged.** Once only a super-admin can store
the mapping, what `SsoProvisioningService` acts on is a super-admin's
deliberate choice, and second-guessing it there would silently break a
configuration somebody consented to.

## Two panels saved into a void

The settings screen had five panels. Two of them wrote ten keys that nothing
reads:

| Panel | Keys | Read by |
|---|---|---|
| Regulatory thresholds | `risk_thresholds.{critical,high,medium,low}_threshold`, `capital_requirement_percentage` | nothing |
| Notification preferences | `notification_prefs.{critical_risk,approval_required,deadline_approaching,report_ready}_notification`, `notification_email` | nothing |

Not a service, a job, a command, a Blade template or a React page. The greps
were run across `app/`, `config/`, `resources/` and `database/` for each key
name individually, not just the container. A user set a critical threshold of
80, saw "updated successfully", and nothing anywhere behaved differently.

Both had real homes elsewhere, which is why nobody noticed: rating boundaries
live on `ScoringProfile::$rating_bands` (edited by the scoring profile builder,
Phase 6.4) and the regulatory capital minimum on
`quantification_settings.cbn_minimum_car` (Phase 5.2's screen). The panels were
third copies with no readers.

Meanwhile five keys with real readers had no interface at all:

| Key | Read by |
|---|---|
| `risk.control_effectiveness` | `Control::…`, `RiskAssessmentControl` (×2), `ControlEffectivenessService` |
| `risk.regulatory_reportable_threshold_ngn` | `LossEventService` |
| `mfa_required_roles` | `EnsureMfaVerified`, `SsoController`, `AuthenticatedSessionController` |
| `reporting_currency` | `CurrencyService`, `ScoringProfileProvisioner`, `DynamicDetail` |
| `default_fx_rate_type` | `CurrencyService` |

So the panels are swapped. `admin.settings.thresholds` and
`admin.settings.notifications` are gone; `admin.settings.organization` writes
the keys above. **This is a deliberate departure from parity** — the only one
in Phase 6 so far — and `the_routes_that_saved_into_a_void_are_gone` fails if
either name comes back.

`OrganizationSettingsRequest::settings()` builds the blob, because the nesting
is the part that goes wrong: `risk.*` for the calculation settings, top level
for the rest. The test asserts through the consuming services rather than
against the database row, so a save nested one level wrong fails even though
the JSON looks plausible.

### What stayed out, and why

`risk.impact_aggregation` and `risk.impact_weights` are read only by
`ScoringProfileProvisioner::ensureFor()`, which runs once, when an organisation
has no profile yet. A form that changed them later would be the same lie in a
new place. The live control is `calculation_method` on the risk panel, which
writes to the profile — where `RiskScoringService` reads it.

## The settings cache was never flushed

`RiskCalculationSettings` memoises per organisation for the life of a request,
and its own docblock says `flush()` is "called from tests, and after an
organization's settings are edited". Nothing called it after an edit. The blast
radius was one request, so this was a latent bug rather than a live one — but
the screen now reads these values to render, so a save without a flush would
have quoted the old numbers straight back.

## Parity kept

Every SSO rule is the controller's, lifted verbatim into `SsoSettingsRequest`:
https-only endpoints, write-only secrets, a slug unique across the whole
installation (it is resolved before anybody is authenticated, so it cannot be
tenant-scoped), and the refusal to advertise a half-configured provider as
enabled.

Secrets are still never rendered. `the_settings_form_never_renders_a_stored_secret`
now guards an Inertia payload rather than HTML, which is a stricter place to
need the guarantee: the page receives `has_oidc_client_secret` as a boolean,
never the value.

`SsoSettingResolver` is new only because the Form Request and the controller
must see the same row — the request needs it to ignore its own slug in the
uniqueness rule — and a `firstOrCreate` written twice is one that will
eventually disagree with itself.

## Test changes

Two existing tests recorded the old behaviour and were updated, not deleted:

- `a_group_cannot_be_mapped_to_a_role_that_does_not_exist` — the invented role
  used to be dropped silently and the rest of the save went through. It is now
  a validation error. The guarantee asserted is unchanged: no such mapping is
  ever stored.
- `nested_admin_paths_highlight_exactly_one_entry` — asserts against the Blade
  sidebar, so it can only cover routes Blade still serves. The two settings
  routes left it here. The React sidebar cannot have that bug: `SectionItem`
  matches the exact path, not a prefix.
