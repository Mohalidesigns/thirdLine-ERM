<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-12 — let a dashboard belong to no tenant.
 *
 * `dashboards.organization_id` was NOT NULL, so the only dashboards that could
 * exist were ones somebody had seeded for a specific organization. In practice
 * that was one organization: WidgetDashboardSeeder looks up the demo bank by
 * cbn_institution_code and returns early if it is absent. Every other tenant —
 * including every real one — had zero rows in this table, DashboardResolver
 * returned null for every node, and Business HQ rendered "No dashboard
 * published for Enterprise" on the org tree. That is the single reason the
 * surface was retired.
 *
 * widget_definitions already allowed NULL here for exactly this purpose
 * (system widgets, WidgetDefinition::$tenantIncludesGlobal). Dashboards now
 * follow the same rule. A tenant-owned dashboard still wins over a system one
 * for the same object type — see DashboardResolver::pick().
 *
 * The unique key stays (organization_id, code). On both MySQL and SQLite a
 * NULL never equals a NULL in a unique index, so this does not stop two
 * different tenants sharing a code, and it does not stop a tenant overriding
 * a system code — which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot ALTER a column's nullability in place without the
        // doctrine/dbal path Laravel 11 dropped, so the change is expressed
        // through a table rebuild there and a plain modify elsewhere.
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable();

            return;
        }

        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed. Going back to NOT NULL means deciding
        // what to do with every system dashboard in the table, and the honest
        // answer — delete them and leave those tenants with a blank HQ — is
        // not something a migration should do silently.
    }

    private function rebuildSqliteTable(): void
    {
        Schema::create('dashboards_wp12_tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 200);
            $table->foreignId('object_type_id')->nullable()->constrained('object_types')->nullOnDelete();
            $table->json('role_ids')->nullable();
            $table->boolean('is_default_for_role')->default(false);
            $table->json('tabs');
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'object_type_id', 'is_published']);
        });

        \DB::statement(
            'INSERT INTO dashboards_wp12_tmp (id, organization_id, code, name, object_type_id, role_ids, is_default_for_role, tabs, is_published, version, created_at, updated_at)
             SELECT id, organization_id, code, name, object_type_id, role_ids, is_default_for_role, tabs, is_published, version, created_at, updated_at FROM dashboards'
        );

        Schema::drop('dashboards');
        Schema::rename('dashboards_wp12_tmp', 'dashboards');
    }
};
