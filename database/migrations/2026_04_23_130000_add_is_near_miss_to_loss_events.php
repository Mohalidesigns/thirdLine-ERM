<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loss_events', function (Blueprint $table) {
            if (! Schema::hasColumn('loss_events', 'is_near_miss')) {
                $table->boolean('is_near_miss')->default(false)->after('event_severity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loss_events', function (Blueprint $table) {
            if (Schema::hasColumn('loss_events', 'is_near_miss')) {
                $table->dropColumn('is_near_miss');
            }
        });
    }
};
