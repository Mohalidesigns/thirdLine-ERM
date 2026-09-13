# Deprecations

Everything here is still in the database and still readable. Nothing is
dropped in this release — per the additive-migrations rule, a column stops
being written in one release and is dropped in the next, so an instance
running the previous release can still read its own data while the new code
rolls out.

Each entry names the release that removes it.

---

## Removed in the next release (Migration B) — WP-01 TASK 1

The duplicate columns migration `2026_02_22_200038_align_schema_with_controllers`
created. They are no longer written; the model accessors named after them read
the canonical column instead. See `docs/schema/canonical-columns.md` for the
full pairing and the reasoning.

| table | columns |
| --- | --- |
| `treatment_plans` | `treatment_title`, `treatment_description`, `treatment_type`, `treatment_owner_id`, `target_completion_date`, `actual_completion_date`, `estimated_cost`, `actual_cost`, `progress_percentage`, `implementation_notes` |
| `loss_events` | `event_title`, `event_description`, `status`, `gross_loss_amount`, `recovery_amount`, `insurance_recovery`, `net_loss_amount`, `basel_event_type`, `cbn_loss_category`, `event_type`, `severity`, `root_cause_summary` |
| `issues` | `issue_title`, `issue_description`, `issue_owner_id`, `target_resolution_date`, `actual_resolution_date`, `source_reference`, `escalation_level` |
| `loss_event_rca` | `root_cause_description`, `contributing_factors_text`, `status`, `performed_by`, `analysis_date` |

Migration B must also delete the bridging accessors on `LossEvent`, `Issue`,
`TreatmentPlan` and `LossEventRca` — they are marked in the source with the
same "removed with the columns in Migration B" comment — and update the ~30
Blade views that still use the old names.

### Also due in Migration B

* `issues.current_escalation_level` is declared `string(50)` but has only ever
  held an integer level. The model casts it to `integer`; retype the column to
  `unsignedTinyInteger`.
* `control_tests.status` is now a plain string with `App\Enums\ControlTestStatus`
  as its authority, but the model does **not** cast the attribute to the enum.
  Casting it would make every existing `$test->status !== 'pending_review'`
  comparison compare an enum against a string — always true — and silently
  invert the approval and resubmit guards. Convert the ~21 read sites in
  `ControlTestController` and `resources/views/risk/controls/**`, then add
  `'status' => ControlTestStatus::class` to `$casts` and delete
  `ControlTest::statusEnum()`.

---

## Deprecated, no removal date set

### `risk_kri_mapping` (table)

KRIs are linked to risks through the direct foreign key
`key_risk_indicators.risk_id` — that is what `KriController::store` writes and
what `Risk::kris()` reads. The `risk_kri_mapping` pivot is a parallel
association that nothing writes and nothing reads.

Marked `@deprecated` on `App\Models\Risk`. Any use is logged.

**Before removing it**, check whether any row exists that the direct foreign key
does not also express — an installation that pre-dates `risk_id` may have
associations recorded only in the pivot:

```bash
php artisan schema:audit-deprecated
```

### `loss_events.is_near_miss` (column)

Duplicates the `near_misses` table, which is a first-class entity with its own
reference codes, its own controller, its own conversion workflow and its own
`converted_loss_event_id` link back to `loss_events`. A boolean on the loss
event is a second, weaker representation of the same fact, and the two can
disagree.

Marked `@deprecated` on `App\Models\LossEvent`. Reads of the flag are logged.

`LossEventController::store` still sets it when the "this is a near miss"
checkbox is ticked. Removing it means routing that checkbox to `NearMiss`
creation instead, which is WP-10 work.

---

## Explicitly NOT deprecated

### `risk_taxonomies`

It has no foreign key from any table, which makes it look orphaned. It is not.
It is a live feature with its own CRUD surface —
`RegulatoryComplianceController` lines 202, 220 and 224, and
`resources/views/risk/regulatory/taxonomy.blade.php` line 22 — and marking it
deprecated would fire warnings on functionality people use every day.

It is a disconnected parallel taxonomy tree, to be **merged** into the unified
taxonomy service in WP-10, not retired.
