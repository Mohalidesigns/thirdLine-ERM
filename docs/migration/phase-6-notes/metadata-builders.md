# Phase 6.3 — Metadata builders

Four Livewire components (1,242 lines), a 58-line controller, ten Blade files.
The first Livewire in the product to go. Five defects, all of one family, plus
a small one found by the port itself.

## The seven bare `exists:` rules

Across the four builders:

| Field | Component |
|---|---|
| `parent_type_id` | ObjectTypeBuilder |
| `default_lifecycle_id` | ObjectTypeBuilder |
| `allowed_child_type_ids.*` | ObjectTypeBuilder |
| `ref_object_type_id` | AttributeBuilder |
| `from_type_ids.*` | RelationshipTypeBuilder |
| `to_type_ids.*` | RelationshipTypeBuilder |
| `objectTypeId` | LifecycleBuilder |

Every one was `exists:object_types,id` or `exists:object_lifecycles,id` — the
bare form this programme has been replacing since Phase 3 — and every one is on
a model that is **not** global. ObjectType, ObjectLifecycle and
ObjectRelationshipType use `BelongsToOrganization` with
`$tenantIncludesGlobal = true`, which means a tenant sees the seeded system rows
(`organization_id` NULL) and its own, and nobody else's. The validator saw the
whole table.

So a tenant could inherit from another institution's custom type, allow it as a
child, default to its lifecycle, point a link field at it, or constrain an edge
to it — putting another bank's names on their screens and its ids in their
graph.

`App\Support\Metadata\MetadataRules::visible()` writes the global scope's own
predicate once. It also excludes soft-deleted rows, which `exists:` never did:
ObjectType soft-deletes, so a type somebody had already removed stayed a valid
target.

## A lifecycle state could demand another institution's workflow

`LifecycleBuilder::save()` validated the lifecycle's five own fields and
**nothing inside a state** beyond its code and name. Four values went straight
into the JSON column from `$this->states`:

```php
'required_permission' => $state['required_permission'] ?: null,
'required_workflow_id' => $state['required_workflow_id'] ?: null,
```

`required_workflow_id` had no rule of any kind. WorkflowDefinition is strictly
tenant-scoped — no `$tenantIncludesGlobal`, so there is no such thing as a
shared definition — and a state pointing at another institution's workflow is a
transition **nobody in either organisation could ever complete**. The record
would simply refuse to move, and the reason would be a foreign id in a JSON
blob.

`required_permission` had no rule either. An invented permission fails closed,
which is the safe direction and a terrible experience: a transition that
silently refuses everybody and says nothing about why. The form offered
`Permission::all()`; the validator now accepts exactly those — 4.6's rule.

`color` had no rule and reaches a style attribute; it is a hex colour now.

## `visible_to_roles` was never validated

AttributeBuilder's form offered `Role::all()` and `save()` wrote
`array_filter($this->visible_to_roles)` without a rule. Same shape, same
fail-closed direction, same fix.

## What deliberately did not change

`validation.rules` — the comma-separated extra Laravel rules an SME may type —
is still free text. It is merged into the validator by
`ObjectAttribute::validationRules()`, so it is genuinely expressive, and
narrowing it to a whitelist would silently drop rules tenants have already
configured. Its blast radius is a form in the tenant's own organisation
refusing input. That is a hardening job with a data migration attached, not a
port.

`maps_to_column` was checked and is **not** a write vector:
`PersistsConfiguredAttributes` rejects mapped attributes from the writable set
before validating anything, so a mapped field is displayed from its column and
never written through the form.

## Found by porting: two invented migration strategies

The attribute editor offers a migration path when a data type change would lose
data. The first draft of this port offered `preserve_as_text`, `discard` and
`best_effort`. `MetadataGuard::migrateAttributeValues()` accepts
`preserve_as_text` and `clear`, and throws on anything else — so two of the
three choices would have been a validation error at the worst possible moment.
Caught by the existing acceptance test, which uses `clear`.

## Test changes

`ObjectTypeBuilderTest` is the WP-05 acceptance suite: a new object type with
five custom fields, a relationship and a lifecycle, created entirely through
the UI. Ten `Livewire::test()` calls became HTTP posts to the routes a browser
posts to. **Every assertion is the one it was.** What changed is that the Form
Requests and the policies are now in the path, and they were not before.

Every write in that file now asserts a redirect as well as an empty error bag.
On its own, `assertSessionHasNoErrors()` passes on a 500 — an exception puts
nothing in the error bag — and during this port it did exactly that, twice,
while the write silently never happened. The cause was `validated()` omitting
nullable keys the caller had not sent, and a payload built from bare
subscripts fatalling on the first partial form post. Both payload builders
default every read now.

`MetadataTenancyTest` is new: one test per foreign-id field, each asserting the
field is refused by name, so a later refactor that reaches for `exists:` again
fails here rather than in a customer's configuration. It also pins the two
things that must keep working — a seeded type is still a valid target, and an
attribute cannot be edited through another type's URL (ObjectAttribute has no
tenancy trait, so its route binding resolves unscoped).

## Still Livewire after this phase

`ScoringProfileBuilder` (6.4) and `WorkflowDesigner` (6.5).
`ConfigurationBuilderController::scoringProfiles()` stays as a Blade shell
until 6.4 flips it.
