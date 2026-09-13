<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-08 TASK 1 — the widget engine's three tables.
 *
 * The platform's dashboard was one 48.6 KB Blade view assembling eight
 * hardcoded sections in one controller method. Adding a chart meant a code
 * change; showing the same chart for a different business unit meant another
 * one. These tables replace that with Corporater's model: ONE widget
 * primitive, N contexts.
 *
 * The load-bearing column is widget_definitions.context_binding. A widget
 * definition does not know what data it shows — it knows how to FIND its data
 * from wherever it is placed. `inherit_subtree` on the Group HQ page means the
 * whole group; the SAME row rendered on a business-unit page means that unit's
 * subtree. One definition, N contexts, zero copies.
 *
 * organization_id is NULLABLE on widget_definitions and means "system widget,
 * available to every tenant" — the same convention object_types and
 * scoring_profiles use, so a new organization has a working widget library
 * before anyone has configured anything. Dashboards are always tenant-owned:
 * which widgets a bank puts in front of its board is that bank's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_definitions', function (Blueprint $table) {
            $table->id();
            // NULL = system widget. See the class docblock.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 200);
            $table->text('description')->nullable();

            // One of the ~21 renderer codes (kpi_tile, register, heatmap, …).
            // A string rather than an enum so a tenant-authored renderer added
            // in a later release is a row, not a migration.
            $table->string('widget_type', 40);

            // The object type this widget queries (Risk, Control, Issue, …).
            // NULL for widgets that aggregate across types (bar_by_type) or
            // read measures directly.
            $table->foreignId('object_type_id')->nullable()->constrained('object_types')->restrictOnDelete();

            // {source, filters[], relationship_joins[], group_by, aggregate,
            //  sort, limit, columns[]} — declarative, executed by
            //  WidgetQueryEngine against a WHITELIST of sources and columns.
            //  User-authored JSON on a multi-tenant platform never reaches the
            //  query builder raw.
            $table->json('query')->nullable();

            // For measure-driven widgets (trends, sparklines, gauges): which
            // measure to read from measure_values.
            $table->foreignId('measure_id')->nullable()->constrained('measures')->nullOnDelete();

            // How the widget resolves "when":
            //   selected — the page's global period selector (PeriodContext)
            //   relative — period_config {type, offset, count} against today
            //   fixed    — period_config {period_code}
            //   range    — period_config {type, count} trailing window ending
            //              at the selected period (trend widgets)
            $table->enum('period_binding', ['selected', 'relative', 'fixed', 'range'])->default('selected');
            $table->json('period_config')->nullable();

            // How the widget resolves "where" — THE POINT of the engine:
            //   inherit_node    — the page's node only
            //   inherit_subtree — the page's node and everything beneath it
            //   fixed_node      — query.node_id, wherever the widget is placed
            //   user_scope      — the viewer's scope_entity_id subtree
            //   global          — the whole organization
            $table->enum('context_binding', [
                'inherit_node', 'inherit_subtree', 'fixed_node', 'user_scope', 'global',
            ])->default('inherit_subtree');

            // Renderer options: palette role, axis labels, band overrides,
            // sparkline on/off, target lines. Never data — data comes from the
            // query at render time.
            $table->json('visualisation')->nullable();

            // {route, params, filter_map} — where a click lands. The heat map
            // cell drills to the register filtered to that cell.
            $table->json('drilldown')->nullable();

            // Grid constraints for the 12-column builder.
            $table->unsignedTinyInteger('min_w')->default(3);
            $table->unsignedTinyInteger('min_h')->default(2);

            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'widget_type']);
        });

        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 200);

            // The node type whose HQ page renders this dashboard. /hq/{object}
            // picks the published dashboard matching the object's type, falling
            // back to the type-agnostic default (object_type_id NULL).
            $table->foreignId('object_type_id')->nullable()->constrained('object_types')->restrictOnDelete();

            // Spatie role ids this dashboard is composed for. NULL = every
            // role. Kept as JSON, not a pivot: composition is read on every
            // page render and written only in the builder.
            $table->json('role_ids')->nullable();
            $table->boolean('is_default_for_role')->default(false);

            // [{code, label, layout: [{widget_id, x, y, w, h, overrides}]}]
            // x/y/w/h are 12-column grid units. `overrides` patches the widget
            // definition per placement (title, filters, visualisation) without
            // forking it.
            $table->json('tabs');

            // Drafts render only in the builder. Publishing bumps version so a
            // user's layout_override can detect it is stale.
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'object_type_id', 'is_published']);
        });

        Schema::create('dashboard_user_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained('dashboards')->cascadeOnDelete();

            // {version, tabs: {tab_code: [{widget_id,x,y,w,h}]}} — a personal
            // rearrangement of a published dashboard. `version` records which
            // dashboard version it was made against; the renderer discards it
            // when the dashboard has since been republished, because a stale
            // override can hide a widget the publisher deliberately added.
            $table->json('layout_override')->nullable();

            // {tab_code: {filter_code: value}} — saved filter state per tab.
            $table->json('saved_filters')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'dashboard_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_user_prefs');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('widget_definitions');
    }
};
