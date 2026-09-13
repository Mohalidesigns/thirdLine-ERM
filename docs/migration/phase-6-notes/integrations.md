# Phase 6.7 — Integrations

Four controllers (649 lines) and six Blade views: webhooks, API tokens,
connectors and background jobs. **These were the last Blade pages in the
product.**

Three of the four screens had the same defect wearing different clothes: a form
offering a fixed list, and a validator accepting anything.

## A webhook could subscribe to an event nobody publishes

```php
'events' => 'required|array|min:1',
'events.*' => 'string|max:100',
```

The form offered a vocabulary derived from the API resource registry —
`risk.created`, `control.updated`, `loss_event.state_changed` and so on. The
validator took any hundred characters. A subscription naming an event nobody
publishes never fires, and nothing anywhere says so: the integrator sees a
healthy subscription with an empty delivery log and no way to tell "not
configured" from "nothing has happened yet".

Not dangerous. Silent, which for an integration screen is worse — the whole
point of the delivery log is that "did you send it?" has an answer.

## A token could be issued with a scope that does not exist

Same shape. `scopes.*` was `string|max:100` while the form rendered every
seeded permission as a checkbox. A scope that does not exist grants nothing —
`ApiToken::permits()` intersects at request time — so this too was fail-closed
and silent: a typo produced a token that looked configured and refused the
request it was issued for.

`*` stays allowed, and only where it means something. A personal token cannot
grant what its owner does not have, so `*` on one means "whatever I can do" —
which is what the person could do by logging in. A machine token has no owner
to be narrowed by, and `ApiToken::assertMachineScopesAreExplicit()` refuses `*`
on one. That check stays on the model, where a console command and a future API
meet it too.

## A connector URL was checked only when it was fetched

`RestConnector` and `CsvConnector` both call `OutboundUrlGuard::assertSafe()`
before they fetch anything, so a connector pointed at `169.254.169.254` or an
internal host has never been able to reach it. **The hole was never open.**

What was missing is the courtesy the webhook screen has always had: the check at
save time, so the person who typed the URL finds out at the field rather than
from a failed run recorded against the connector as though the source were down.

The guard still runs at fetch time, and must — a URL that was safe when it was
typed can resolve somewhere else afterwards. Its docblock is honest that DNS
rebinding is not fully closed, and that remains true.

## Inline tenant checks became policies

Each controller asked `abort_unless($model->organization_id === TenantContext::organizationId(), 403)`
inline — four times in `WebhookController`, four in `ConnectorController`, and
in slightly different words in the other two. They are `WebhookSubscriptionPolicy`,
`ApiTokenPolicy`, `ConnectorPolicy` and `JobRunPolicy` now.

Two of them were wrong on the first draft, in a way worth recording: the
policies demanded MORE than their routes. `WebhookSubscriptionPolicy::viewAny`
asked for `webhook.manage` where the route asks for `webhook.view`, and
`ConnectorPolicy::run` folded `connector.run` into `connector.manage`. A policy
that quietly demands more than its route makes the route's middleware a lie, and
these three permissions are separately grantable precisely so that reading,
configuring and running a connector can be given to different people.

## Secrets

The signing secret and the token plaintext are still shown **once**. Both arrive
as props on their own page rather than through the shared flash — putting a
secret-shaped key in the props that every page receives would be a worse habit
than the line it saves. Two tests pin both halves: shown once on the request
that follows creating it, and never present in the listing.

Connector credentials are never sent to the browser at all; the form renders
them blank and the update only overwrites what was typed, so saving the page
cannot wipe what it was never shown. `saving_a_connector_does_not_wipe_credentials_it_was_never_shown`
pins that.

## Two tests reached the end of their subject

`AdminNavigationTest::nested_admin_paths_highlight_exactly_one_entry` asserted
that a prefix like `/admin/builder` did not light both a parent entry and its
child in the Blade sidebar. It could only cover routes Blade still served, and
the migration kept taking them away — settings in 6.2, the builder in 6.3,
scoring profiles in 6.4, the integrations group here. Its last subject is gone.

The bug it guarded is now impossible rather than merely absent: the React
sidebar's `SectionItem` matches on the exact path, so a parent and a child can
never both be active. So it is replaced by
`every_admin_surface_is_served_by_inertia`, which asserts the fact that made it
impossible.

`AuthPagesTest` carried a check against a still-Blade path so the `wire:navigate`
branch of `navigateAttribute()` was exercised rather than passing vacuously. Its
subject moved with every module of this phase, and after 6.7 there is no Blade
page left to point it at. It is now a filesystem assertion:
`bladePageViews()` returns every `.blade.php` that is not the app shell, a
layout, a shared component, a PDF template, a mailable or a vendor pagination
view — and it must be empty. Phase 6.8 narrows the allowed list further.

## A test-only note worth keeping

`OutboundUrlGuard` resolves DNS, deliberately — a hostname that resolves to
127.0.0.1 is the obvious way around a name-based blocklist. In a test that means
a `.test` hostname blocks until the resolver gives up, a minute per assertion.
`IntegrationScreenTest` allowlists its fixture hosts to skip the lookup, and the
link-local case still goes through the real check because `169.254.169.254` is
an address rather than a name.

## After this phase

No Blade page remains. What is left under `resources/views` is the app shell,
the Blade layout the shell no longer needs, the shared view components,
`livewire/dynamic-form.blade.php`, the PDF templates, one mailable and the
vendor pagination views — which is exactly 6.8's deletion list.
