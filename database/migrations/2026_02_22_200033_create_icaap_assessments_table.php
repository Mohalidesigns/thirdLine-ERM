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
        Schema::create('icaap_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period', 20);
            $table->string('status', 20)->default('DRAFT');

            // Capital position
            $table->bigInteger('cet1_capital_kobo')->nullable();
            $table->bigInteger('tier1_capital_kobo')->nullable();
            $table->bigInteger('tier2_capital_kobo')->nullable();
            $table->bigInteger('total_qualifying_capital_kobo')->nullable();
            $table->bigInteger('total_rwa_kobo')->nullable();
            $table->decimal('car_actual', 8, 4)->nullable();

            // Pillar 1
            $table->decimal('cbn_minimum_car', 8, 4)->default(10.0);
            $table->decimal('conservation_buffer', 8, 4)->default(2.5);

            // Pillar 2
            $table->bigInteger('pillar2a_credit_kobo')->nullable();
            $table->bigInteger('pillar2a_market_kobo')->nullable();
            $table->bigInteger('pillar2a_operational_kobo')->nullable();
            $table->bigInteger('pillar2a_other_kobo')->nullable();
            $table->bigInteger('pillar2b_stress_buffer_kobo')->nullable();

            // Simulation reference
            $table->foreignId('base_simulation_id')->nullable()->constrained('simulation_runs')->nullOnDelete();
            $table->foreignId('stress_simulation_id')->nullable()->constrained('simulation_runs')->nullOnDelete();

            // Governance
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_board')->nullable()->constrained('users')->nullOnDelete();
            $table->date('board_approval_date')->nullable();
            $table->date('cbn_submission_date')->nullable();
            $table->string('cbn_submission_ref', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('icaap_assessments');
    }
};
