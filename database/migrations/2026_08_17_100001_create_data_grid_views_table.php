<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 TASK 2: saved views for <x-data-grid>.
 *
 * A saved view is one user's named snapshot of a grid's state — search,
 * filters, sort, visible columns, page size. Personal, per grid, and never
 * shared: a view can leak nothing the user's own filters could not already
 * see, but sharing would still pin another user to a stranger's column set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_grid_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('grid', 64);
            $table->string('name', 60);
            $table->json('state');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'grid', 'name']);
            $table->index(['user_id', 'grid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_grid_views');
    }
};
