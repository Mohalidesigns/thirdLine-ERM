<?php

use App\Support\Graph\ObjectRegistryInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-03 TASK 2 — seed the type registry.
 *
 * This runs as a migration rather than a seeder because the migrations that
 * follow it depend on the types existing: the unification pass in 120007 has to
 * resolve 'BusinessUnit' to an id before it can write a single node. Seeders are
 * optional, environment-specific and frequently skipped; a backfill that
 * silently produces nothing because the seeder was not run is worse than one
 * that fails loudly.
 *
 * ObjectGraphSeeder calls the same installer, so re-running it after adding a
 * type to the registry updates an existing install without a new migration.
 *
 * object_attributes is intentionally left EMPTY. The seeded types are backed by
 * typed tables whose columns already carry their domain fields; attributes
 * exist so a tenant can extend those types without a migration, and inventing
 * system attributes here would put a second, competing definition of fields
 * that already have columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ObjectRegistryInstaller)->install();
    }

    public function down(): void
    {
        // Only the system rows. A tenant's own types, and any object that has
        // since been created against a system type, are not this migration's to
        // destroy — the FK from objects is restrictOnDelete, so an install with
        // live data refuses the rollback rather than cascading through it.
        DB::table('object_lifecycles')->whereNull('organization_id')->where('is_system', true)->delete();
        DB::table('object_relationship_types')->whereNull('organization_id')->where('is_system', true)->delete();
        DB::table('object_types')->whereNull('organization_id')->where('is_system', true)->update(['default_lifecycle_id' => null]);
        DB::table('object_types')->whereNull('organization_id')->where('is_system', true)->delete();
    }
};
