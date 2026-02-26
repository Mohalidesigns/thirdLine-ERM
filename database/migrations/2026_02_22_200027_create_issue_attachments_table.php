<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('issue_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('file_name', 500);
            $table->integer('file_size_bytes')->nullable();
            $table->string('file_type', 50)->nullable();
            $table->text('storage_path');
            $table->string('document_type', 50)->nullable();
            $table->boolean('is_regulatory')->default(false);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_attachments');
    }
};
