# ADR 0009 — The Phase 2 BIA engine columns

**Status:** Accepted · **Date:** 2026-09-08 · **Phase:** BCMS Phase 2 · **Author:** architect
**Requested by:** backend-engineer (lead, Phase 2) · **Broadcast to:** Tracks B, C, D, E

## Context

The second ADR against the schema freeze, and a much smaller one than ADR 0008:
**eight columns, no new tables, nothing renamed.** Every table Phase 2 needs
exists — `bcms_bia_campaigns`, `bcms_bia_assessments`, `bcms_bia_impacts`,
`bcms_dependencies` all came out of Phase 0 complete.

What is missing is state the phase's acceptance criteria require to be
*provable* rather than merely *done*.

## Decision

### `bcms_bia_assessments`

| Column | Why |
|---|---|
| `derived_mtpd_hours` | The prompt is explicit that MTPD is "*derived and proposed* … then confirmed by the assessor — the system suggests, the human decides". One column cannot hold both. `mtpd_hours` is the assessor's answer and is what everything downstream measures against; this is what the impact grid proposed, kept beside it so a reviewer can see where they differ. Overwriting the proposal with the answer would erase the disagreement, which is the interesting part. |
| `ai_reasoning` (json) | Criterion 5 requires proposed RTO/RPO **with reasoning shown**. A number a model produced and cannot justify is worse than no number: standing rule 4 makes every AI output a draft, and a draft a human cannot interrogate is one they will either accept blindly or discard entirely. |
| `chased_at`, `chase_count` | Criterion 7 — a non-responding owner triggers escalation *on schedule*. Without a record of what was already sent, the nightly sweep either escalates every night or cannot tell whether it has escalated at all. |
| `escalated_at`, `escalated_to_user_id` | Same, for the escalation itself. `escalated_to_user_id` records **which** manager, because "we escalated" is not evidence and "we escalated to the Head of Operations on the 14th" is. |

### `bcms_settings`

| Column | Why |
|---|---|
| `impact_intolerable_score` | MTPD derivation needs a threshold: the severity at which impact stops being tolerable. It is a **tenant** judgement — one bank's 4-out-of-5 is another's 3 — and hard-coding it would make the derived MTPD our opinion rather than theirs. |
| `critical_service_rto_ceiling_hours` | Criterion 2: "a process flagged `is_critical_service` cannot have an RTO above the tenant's configured ceiling". Configured, so it is a column and not a constant. Nullable, because a tenant that has not set one gets no ceiling rather than a ceiling we invented. |

## What this ADR deliberately does not add

**A per-assessment deadline.** The campaign's `closes_at` is the deadline, and
adding a second one invites two answers to "when is this due". A genuine
per-assessment extension is a campaign-level decision recorded against the
campaign.

**A campaign→process targeting table.** Distribution *is* the creation of
`bcms_bia_assessments` rows, which already carry `campaign_id` + `process_id`
with a unique constraint on the pair. A separate target list would be a second
place to look for who is in scope, and the two would drift the first time
somebody was added mid-campaign.

**Anything for the dependency graph.** `bcms_dependencies` holds the edges and
`single_point_of_failure` already; the SPOF register, the reverse-impact view and
cycle detection are all queries over what exists. A materialised graph would be a
cache with no invalidation story, and the whole estate is thousands of edges, not
millions.

## Consequences

- The manifest is regenerated in the same commit, as ADR 0008 established.
- No column another track reads has changed. Nothing needs rebasing.
- Two ADRs in two phases is a rate worth watching. Both were foreseen —
  0008 by the Phase 0 handoff, 0009 by nothing, but it is eight columns of
  *evidence* rather than of structure. If Phase 3 needs a third, the architect
  should ask whether the freeze is holding or whether Phase 0 under-specified.
