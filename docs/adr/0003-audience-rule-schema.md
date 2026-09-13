# ADR 0003 — The `AudienceRule` JSON schema

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** architect
**Consumers:** Track B (reminder audiences), Track C (EMNS targeting), Track D (incident alerts)

## Context

Three different features need to answer "who should this go to?": the T-10
reminder ladder (Phase 5), an EMNS dispatch (Phase 7) and an incident
notification (Phase 10). They are built by three tracks in three sessions over
ten weeks. Left to themselves they will produce three targeting languages, two
of which will not support the case the third invented.

## Decision

**One JSON grammar, one resolver, frozen at G0.** A rule is a JSON object with
a `type` and type-specific keys, or a boolean combination of them.

```jsonc
// Leaf forms
{"type": "org_node",              "id": 12, "include_descendants": true}
{"type": "site",                  "ids": [3, 7]}
{"type": "role",                  "names": ["branch-manager"], "org_node_id": 12}
{"type": "call_tree",             "id": 4, "tiers": [1, 2]}
{"type": "occurrence_participants","id": 88, "roles": ["participant", "facilitator"]}
{"type": "saved_group",           "id": 2}
{"type": "geo",                   "lat": 12.00, "lng": 8.59, "radius_km": 25}

// Combinators — arbitrarily nested
{"type": "all_of", "rules": [ … ]}   // intersection
{"type": "any_of", "rules": [ … ]}   // union
{"type": "none_of","rules": [ … ]}   // exclusion, applied last
```

Three rules that are not negotiable:

1. **A rule resolves to `bcms_contacts` rows, never to `users` rows.** Channel
   resolution is `ContactResolver`'s job (ADR 0004) and a user has no channel.
   Orchestration §5 states this as a contract; it is enforced by
   `AudienceResolver` returning a `Collection<Contact>` and by nothing else in
   BCMS querying `users` for a phone number.

2. **Resolution is snapshotted at dispatch, not stored as a rule reference.**
   `bcms_alert_recipients` and `bcms_reminder_schedules` record the contacts
   the rule resolved to *at that moment*, with the rule kept alongside for
   audit. An examiner asking "who was told" must get the list that was told,
   not the list the rule would produce today.

3. **`none_of` is applied after everything else** and can only remove. A rule
   that would otherwise resolve to nobody resolves to nobody; it does not fall
   back to everybody. Fail closed.

`App\Support\Bcms\AudienceRule` is the value object: it validates the grammar,
normalises it, and is the only thing that constructs one. A raw array reaching
`AudienceResolver` is a bug.

## Consequences

- Phase 5 and Phase 7 build against the same resolver from Week 2, against the
  Phase 0 mock channels, and neither waits on the other.
- Adding a leaf type is an enum case plus a resolver method plus a test — and
  an ADR, because the three consuming tracks must be told.
- Geo targeting needs a lat/lng on `bcms_sites` and on `bcms_contacts`. Both
  columns exist from Phase 0 even though nothing writes them until Phase 2C, so
  that adding geo later is not a structural migration (see standing rule 2).
