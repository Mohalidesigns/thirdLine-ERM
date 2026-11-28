<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0028 Phase 1 — Link risk instances to master Risk Library definitions
 * and store Basel II/III L1/L2 event types and structured ISO 31000 consequences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('library_item_id')
                ->nullable()
                ->after('category_id')
                ->constrained('risk_library_items')
                ->restrictOnDelete();
            $table->unsignedInteger('library_version')
                ->nullable()
                ->after('library_item_id');
            $table->dateTime('library_synced_at')
                ->nullable()
                ->after('library_version');
            $table->unsignedInteger('library_update_dismissed_version')
                ->nullable()
                ->after('library_synced_at');
            $table->string('basel_l1_event_type', 40)
                ->nullable()
                ->after('risk_source');
            $table->string('basel_l2_event_type', 64)
                ->nullable()
                ->after('basel_l1_event_type');
            $table->json('consequences')
                ->nullable()
                ->after('metadata');

            $table->index(['organization_id', 'library_item_id'], 'risks_org_library_item_idx');
            $table->index(['organization_id', 'entity_id', 'process_id'], 'risks_org_entity_process_idx');
            $table->index(['organization_id', 'basel_l1_event_type'], 'risks_org_basel_l1_idx');
        });
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropIndex('risks_org_library_item_idx');
            $table->dropIndex('risks_org_entity_process_idx');
            $table->dropIndex('risks_org_basel_l1_idx');
            $table->dropForeign(['library_item_id']);
            $table->dropColumn([
                'library_item_id',
                'library_version',
                'library_synced_at',
                'library_update_dismissed_version',
                'basel_l1_event_type',
                'basel_l2_event_type',
                'consequences',
            ]);
        });
    }
};
