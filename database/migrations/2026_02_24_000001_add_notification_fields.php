<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications_log')) {
            Schema::table('notifications_log', function (Blueprint $table) {
                if (! Schema::hasColumn('notifications_log', 'read_at')) {
                    $table->timestamp('read_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('notifications_log', 'notification_category')) {
                    $table->string('notification_category')->nullable()->after('read_at');
                }
                if (! Schema::hasColumn('notifications_log', 'action_url')) {
                    $table->string('action_url')->nullable()->after('notification_category');
                }
                if (! Schema::hasColumn('notifications_log', 'priority')) {
                    $table->string('priority')->default('medium')->after('action_url');
                }
            });
        } else {
            Schema::create('notifications_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('channel')->default('database');
                $table->string('type');
                $table->string('subject');
                $table->text('body');
                $table->string('status')->default('sent');
                $table->timestamp('read_at')->nullable();
                $table->string('notification_category')->nullable();
                $table->string('action_url')->nullable();
                $table->string('priority')->default('medium');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                $table->index(['organization_id', 'user_id']);
                $table->index(['user_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
    }
};
