# Canonical columns

Migration `2026_02_22_200038_align_schema_with_controllers.php` added every
column the controllers referenced instead of renaming the columns that already
held the same data. The result was two sources of truth for a set of fields:
one written by the original domain migrations, one written by the controllers.

This document records which column won for each pair, why, and what still has
to happen.

## How to read this

* **Canonical** — the only column that may be read or written from this release
  on. Models, controllers, services, exports, reports and imports all use it.
* **Deprecated** — still present in the database, no longer written. A
  read-only accessor named after it survives on the model so the ~30 Blade
  views that use the old name keep rendering. Dropped in **Migration B**
  (WP-01 follow-up ticket), together with the accessors.
* **Retained** — added by 200038 but *not* a duplicate: no original column held
  the same fact. These stay exactly as they are.

The rule for choosing a canonical column, applied throughout: **prefer the
original 200010 / 200017 / 200020 / 200024 naming.** Those columns carry the
`NOT NULL` constraints, the indexes and the foreign keys, they are what the
domain services and the regulatory engine read, and they are the ones a Basel
or CBN ORMS extract maps onto. The 200038 columns were added later, are all
nullable, and are read almost exclusively by the CRUD screens.

---

## `treatment_plans`

| Deprecated (200038) | Canonical (200010) | Note |
| --- | --- | --- |
| `treatment_title` | `action_title` | |
| `treatment_description` | `action_description` | |
| `treatment_type` | `strategy` | Four-strategy response (accept / avoid / mitigate / transfer). `strategy` is the column WP-10 extends with SHARING. |
| `treatment_owner_id` | `owner_id` | `owner_id` carries the FK to `users`. |
| `target_completion_date` | `target_date` | |
| `actual_completion_date` | `completion_date` | |
| `estimated_cost` | `cost_estimate_ngn` | |
| `actual_cost` | `actual_cost_ngn` | |
| `progress_percentage` | `progress_pct` | |
| `implementation_notes` | `progress_notes` | |

**Retained:** `treatment_code`, `milestones`, `success_criteria`,
`expected_residual_likelihood`, `expected_residual_impact`, `approved_by`,
`approved_at`, `rejection_reason`, `updated_by`.

---

## `loss_events`

| Deprecated (200038) | Canonical (200017) | Note |
| --- | --- | --- |
| `event_title` | `title` | |
| `event_description` | `description` | |
| `status` | `current_status` | Canonical is **upper case** (`REPORTED`). The `status` accessor lower-cases it, preserving the contract the views and `destroy()` already rely on. |
| `gross_loss_amount` | `gross_loss_amount_kobo` | Unit change, not a rename. Money is stored in minor units. |
| `insurance_recovery` | `insurance_recovery_kobo` | Unit change. |
| `recovery_amount` | `other_recovery_kobo` | Unit change. "Recovery that is not insurance" is exactly what `other_recovery_kobo` means. |
| `net_loss_amount` | *(derived)* | No canonical column: `net_loss_amount_kobo` is an accessor over the recovery decomposition. See below. |
| `basel_event_type` | `basel_l1_category` | Canonical is **upper case**. See the bug note below. |
| `cbn_loss_category` | `cbn_risk_category` | |
| `event_type` | `loss_category` | |
| `severity` | `event_severity` | Canonical is **upper case**; the `severity` accessor lower-cases it. |
| `root_cause_summary` | `initial_root_cause` | |

**Retained:** `currency` (rule 5 requires a currency code alongside every
stored amount), `regulatory_body`, `reporting_deadline`,
`is_regulatory_reportable`, `corrective_action_summary`, `reported_by`,
`approved_by`, `approved_at`, `status_changed_at`, `status_changed_by`,
`updated_by`.

`is_regulatory_reportable` / `regulatory_body` / `reporting_deadline` are
deliberately **not** collapsed into `cbn_reportable` /
`cbn_reporting_deadline`. The CBN columns answer "is this reportable *to the
CBN*, and by when"; the generic trio answers "is this reportable to *any*
regulator" and names which one. NFIU, NDIC, BOFIA and EFCC each have their own
flag on the same table, so folding the generic roll-up into the CBN-specific
column would lose the distinction between "reportable somewhere" and
"reportable to the central bank".

### Case normalisation fixed a live regulatory bug

`LossEventController` wrote `basel_l1_category` straight from the form enum, in
lower case (`internal_fraud`). `RegulatoryThresholdService` matches
`['INTERNAL_FRAUD', 'EXTERNAL_FRAUD']`. They never matched, so for every loss
event created through the UI:

* the **NFIU STR** alert (24 hours, AML/CFT Act 2022 §6(1)) never fired;
* the **EFCC** alert (EFCC Act 2004) never fired;
* the **cyber-fraud law-enforcement** alert (CBN Cyber Security Framework 2021)
  never fired.

The service was correct; the writer was wrong. Canonicalising
`basel_l1_category` to upper case on write fixes it. The service's behaviour is
pinned by `tests/Feature/Characterisation/RegulatoryThresholdServiceTest.php`
and the writer is covered by `tests/Feature/CanonicalColumnWriteTest.php`.

The backfill migration upper-cases existing `basel_l1_category`,
`basel_l2_category`, `cbn_risk_category`, `event_severity` and `current_status`
values so historic events start alerting correctly too.

### `net_loss_amount_kobo` and the five recovery columns

`loss_events` carries five recovery-shaped columns:
`insurance_recovery_kobo`, `other_recovery_kobo`, `pending_recovery_kobo`,
`actual_recovery_kobo`, `provision_amount_kobo`.

Net loss subtracts **only the first two**:

```
net = gross − insurance_recovery − other_recovery
```

* `pending_recovery_kobo` is money not yet received, so it does not reduce a
  realised loss.
* `actual_recovery_kobo` is the roll-up of insurance + other; subtracting it as
  well would double-count.
* `provision_amount_kobo` is an accounting entry, not a recovery.

This is a reading of Basel recovery treatment rather than a self-evident truth,
so it is pinned by `tests/Feature/Characterisation/LossEventNetLossTest.php`.
Changing it has to be a decision, not a refactor.

---

## `issues`

| Deprecated (200038) | Canonical (200024) | Note |
| --- | --- | --- |
| `issue_title` | `title` | |
| `issue_description` | `description` | |
| `issue_owner_id` | `responsible_owner_id` | `responsible_owner_id` carries the FK to `users`. |
| `target_resolution_date` | `remediation_due_date` | |
| `actual_resolution_date` | `actual_close_date` | |
| `escalation_level` | `current_escalation_level` | `IssueEscalationService` wrote both, always with the same integer. Canonical is the original column; the model casts it to `integer`. It is still declared `string(50)` — retyping it is Migration B work, listed in `docs/schema/deprecations.md`. |
| `source_reference` | `examination_ref` | |

**Retained:** `root_cause`, `impact_description`, `recommended_action`,
`progress_percentage`, `closure_justification`, `evidence_of_resolution`,
`closure_requested_at`, `closure_requested_by`, `closure_rejection_reason`,
`closure_rejected_at`, `closure_rejected_by`, `closed_at`, `closed_by`,
`status_changed_at`, `status_changed_by`, `updated_by`.

`issue_status` and `issue_reference` appear in the 200038 column list but were
never actually added: `addColumns()` skips any name that already exists, and
both were already on the table. They have only ever had one definition.

---

## `loss_event_rca`

| Deprecated (200038) | Canonical (200020) | Note |
| --- | --- | --- |
| `root_cause_description` | `root_cause_statement` | `root_cause_statement` is `NOT NULL`. |
| `contributing_factors_text` | `contributory_factors` | Type change: free text → JSON array. The backfill wraps any existing text in a single-element array. |
| `performed_by` | `completed_by` | `completed_by` carries the FK to `users`. |
| `analysis_date` | `completed_at` | |
| `status` | `rca_status` | |

**Retained:** `organization_id` (the table had none, and tenancy needs it),
`root_cause_category`, `analysis_details`, `recommendations`,
`lessons_learned`.

---

## What has not happened yet

**Migration B** — drop every column in the *Deprecated* columns above, and
delete the bridging accessors from `LossEvent`, `Issue`, `TreatmentPlan` and
`LossEventRca`. Tracked in `docs/schema/deprecations.md`.

Per the additive-migrations rule, the drop cannot ship in the same release as
the stop-writing change: a deployed instance running the previous release must
still be able to read its own columns while the new code rolls out.
