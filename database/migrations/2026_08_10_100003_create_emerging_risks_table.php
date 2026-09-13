<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The emerging risk register.
 *
 * The Risk Radar screen previously rendered a hardcoded array of eight
 * plausible-sounding emerging risks with invented confidence percentages and an
 * invented "sources scanned: 47". This table replaces that with a register real
 * people populate. WP-29 horizon scanning will later write into the same table;
 * `source` and `detected_at` exist so an automated feed and a manual entry stay
 * distinguishable.
 *
 * Velocity and proximity are the two axes the radar plots: how fast the risk is
 * developing, and how close it is to landing. Both are 1-5 ordinals entered by
 * the analyst — an opinion, but a recorded, attributable one, which is a
 * different thing from a number the software made up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emerging_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 50);
            $table->string('title');
            $table->text('description')->nullable();

            // Where this sits in the taxonomy. Nullable because an emerging
            // risk often predates the category that will eventually hold it.
            $table->foreignId('category_id')->nullable()->constrained('risk_categories')->nullOnDelete();

            $table->enum('horizon', ['0-3m', '3-6m', '6-12m', '12m+'])->default('6-12m');

            // 1 = slow / distant, 5 = fast / imminent.
            $table->unsignedTinyInteger('velocity_score')->default(3);
            $table->unsignedTinyInteger('proximity_score')->default(3);

            $table->enum('potential_impact', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->enum('status', ['monitoring', 'assessing', 'escalated', 'converted', 'closed'])->default('monitoring');

            // Provenance: who says so, and on what basis.
            $table->string('source', 160)->nullable();
            $table->text('source_reference')->nullable();
            $table->date('detected_at')->nullable();
            $table->date('last_reviewed_at')->nullable();

            $table->text('potential_response')->nullable();

            // Set when the emerging risk graduates into the register proper.
            $table->foreignId('converted_risk_id')->nullable()->constrained('risks')->nullOnDelete();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'horizon']);
            // Reference codes are unique per tenant, matching the convention
            // established by 2026_08_09_100002 for the other registers.
            $table->unique(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emerging_risks');
    }
};
