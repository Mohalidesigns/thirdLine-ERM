<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 1 — the aggregate root.
 *
 * FROZEN BY ADR 0027 AMENDMENT 1 §A1.2, plus one column from the addendum. A
 * column not named there does not exist in Phase 1; §A1.13 lists what was
 * deferred and to which phase, and BCMS standing rule 2 (adopted for `Sm` by
 * the amendment) says a phase that needs one raises a numbered ADR rather than
 * adding it here.
 *
 * THREE THINGS IN THIS FILE ARE DELIBERATE AND LOOK LIKE OVERSIGHTS:
 *
 *   1. `insurance_risk` and `financial_risk` are NULLABLE booleans while
 *      `eligible_to_transfer` is not. The workbook's first two dropdowns offer a
 *      blank ("unset = NULL", VOCABULARY-EXTRACTION.md); the third is `0`/`1`
 *      only. A `->default(false)` on the nullable pair would render "not yet
 *      assessed" as "No" on a governance screen — standard §5's absent-read-as-
 *      zero defect — and Phase 2's netting (`Σ type_total × eligible_flag`)
 *      needs a value, not a third case, in the two-case formula.
 *
 *   2. `weighting` is nullable with NO default. It is a judgement; a default of
 *      `medium_low` would be a fabricated figure on every scenario nobody has
 *      weighted. `nature` DOES default, to `local`, because it is a
 *      classification the designer holds at creation and `local` is the
 *      narrowest claim — the shape of `risks.status` defaulting `draft`.
 *
 *   3. The day columns are `_on`, not `_at`, and are `date`. ADR 0026 settled
 *      the suffix: `_on` is an Africa/Lagos calendar day, `_at` an instant.
 *      Standard §14's shipped defect was a `date` read with `->isPast()`, and a
 *      column named `_at` invites exactly that. Readers compare these with
 *      `whereDate($col, '<', now()->toDateString())`, never against a bare
 *      `now()` and never through `->isPast()`.
 *
 * `reference` is unique PER TENANT, not globally: `loss_events.event_reference`
 * is `->unique()` outright, which is a defect waiting for a second tenant. The
 * shape followed is `bcms_management_reviews` — `string(40)` plus
 * `unique(organization_id, reference)` — as `risks.risk_code` also does. The
 * width stays 40 rather than the 11 an `SM-YYYY-NNN` needs because Phase 5
 * imports filled LS workbooks whose references are the customer's own.
 *
 * There are NO `approved_by` / `approved_at` / `signed_off_at` columns: sign-off
 * is rows in `sm_scenario_signoffs` (§A1.6) — three sources, three sign-offs,
 * one status. A denormalised timestamp is a second source of truth that drifts
 * the first time a sign-off is returned and re-given.
 *
 * SQL stays inside the MariaDB 10.4 ∩ MySQL 8.0.46 intersection: no CHECK
 * constraint (none exists anywhere in this schema, and §A1.5 puts the
 * allocation invariant in the service so it answers 422 rather than
 * SQLSTATE[23000]), no generated column, no `timestampTz`, no
 * `useCurrentOnUpdate` — `NoSelfUpdatingTimestampColumnsTest` scans every column
 * of every type and its allowlist is empty (§13, ADR 0022).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_scenarios', function (Blueprint $table) {
            $table->id();
            // Aggregate root, so a uuid per ADR 0007 deviation 4. Child and
            // line tables in this module carry none (backend rule 6). Set in a
            // `creating` hook, as QuantificationScenario::boot() does.
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Service-generated `SM-{YYYY}-{NNN}`, Lagos year, max+1 per tenant
            // per year, collision retried (addendum-2). Never provisional, never
            // sent to a create form.
            $table->string('reference', 40);
            $table->string('name', 250);
            $table->string('nature', 20)->default('local');
            $table->string('status', 24)->default('draft');

            // Singular and nullable for Phase 1. An `sm_scenario_risks` pivot
            // was refused (§A1.2): one scenario is designed against one register
            // entry in the workbook, and a pivot with no screen is a table
            // nobody can test. Many-to-many, if Phase 4's risk-360 needs it, is
            // an amendment naming Phase 4.
            $table->foreignId('risk_id')->nullable()->constrained('risks')->nullOnDelete();
            // The Basel OP event type (OP1–OP17), seeded per tenant as
            // RiskTaxonomy nodes under framework 'Basel III' (§A1.9).
            $table->foreignId('risk_taxonomy_node_id')->nullable()->constrained('risk_taxonomies')->nullOnDelete();
            // `business_units`, NOT `entities` — §A1.3. ADR 0026 decision 0.5
            // settled that business_units are authoritative after KRIs'
            // `entity_id` turned out to be written by nothing.
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();

            $table->string('weighting', 20)->nullable();

            // char(3) to match fx_rates.from_currency / .to_currency; NOT NULL
            // because every figure here is money and a currency-less scenario
            // cannot be converted.
            $table->char('currency', 3)->default('NGN');

            // THREE FX columns, not one. `fx_rate_id` is provenance; the pair
            // below is the SNAPSHOT D1/§4.3 require, because `fx_rates` rows are
            // per-tenant and a platform rate can be superseded — a signed-off
            // figure must not move when the rate table does. Precision is
            // exactly `fx_rates.rate`'s decimal(18,8); narrower silently rounds
            // a figure an examiner recomputes. The column keeps its source's
            // name rather than taking the `_on` suffix so it reads against
            // `fx_rates.rate_date`.
            $table->foreignId('fx_rate_id')->nullable()->constrained('fx_rates')->nullOnDelete();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->date('fx_rate_date')->nullable();

            $table->date('designed_on')->nullable();
            $table->date('validated_on')->nullable();
            $table->date('review_due_on')->nullable();

            // The split `bcms_scenarios` uses for summary/narrative: the
            // workbook's background block is a page of prose.
            $table->longText('background')->nullable();
            $table->text('rationale')->nullable();

            // Two near-identical value sets is the price of an exact export.
            // The reputational dropdown's zero is `no_impact`, the legal one's
            // is `no` — they literally differ in the workbook, and D1 plus
            // Phase 5's round-trip require the stored value to map back to that
            // exact string.
            $table->string('reputational_exposure', 20)->nullable();
            $table->string('legal_compliance_exposure', 20)->nullable();

            $table->boolean('insurance_risk')->nullable();
            $table->boolean('financial_risk')->nullable();
            $table->boolean('eligible_to_transfer')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Index names frozen, abbreviated for MySQL's 64-character limit.
            $table->unique(['organization_id', 'reference'], 'sm_scenarios_org_reference_unique');
            // The grid and the pipeline tiles.
            $table->index(['organization_id', 'status'], 'sm_scenarios_org_status_idx');
            // The Phase 5 OP heatmap.
            $table->index(['organization_id', 'risk_taxonomy_node_id'], 'sm_scenarios_org_taxonomy_idx');
            $table->index(['organization_id', 'business_unit_id'], 'sm_scenarios_org_unit_idx');
            // The annual refresh prompt.
            $table->index(['organization_id', 'review_due_on'], 'sm_scenarios_org_review_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_scenarios');
    }
};
