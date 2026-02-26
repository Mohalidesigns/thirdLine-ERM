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
        Schema::create('loss_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_reference', 20)->unique();

            // Event details
            $table->string('title', 500);
            $table->text('description');
            $table->text('initial_root_cause')->nullable();
            $table->date('date_of_loss');
            $table->date('date_discovered');
            $table->date('date_reported')->useCurrent();

            // Organizational context
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('department', 200)->nullable();
            $table->string('branch_name', 200)->nullable();
            $table->foreignId('responsible_officer_id')->nullable()->constrained('users')->nullOnDelete();

            // Basel III Classification
            $table->string('basel_l1_category', 50);
            $table->string('basel_l2_category', 100);
            $table->string('basel_l3_detail', 200)->nullable();

            // CBN ORMS Classification
            $table->string('cbn_risk_category', 100);
            $table->string('cbn_orms_event_type', 100)->nullable();
            $table->string('cbn_product_line', 100)->nullable();

            // Financial Impact (stored in kobo)
            $table->bigInteger('gross_loss_amount_kobo')->default(0);
            $table->bigInteger('insurance_recovery_kobo')->default(0);
            $table->bigInteger('other_recovery_kobo')->default(0);
            $table->bigInteger('pending_recovery_kobo')->default(0);
            $table->bigInteger('actual_recovery_kobo')->default(0);
            $table->bigInteger('provision_amount_kobo')->nullable();
            $table->string('cost_centre', 100)->nullable();
            $table->string('gl_account_code', 50)->nullable();
            $table->string('loss_category', 30);

            // Insurance
            $table->boolean('insurance_covered')->default(false);
            $table->string('insurance_policy_ref', 200)->nullable();
            $table->bigInteger('insured_amount_kobo')->nullable();
            $table->string('insurance_provider', 200)->nullable();
            $table->string('insurance_claim_status', 50)->nullable();

            // Severity & Status
            $table->string('event_severity', 20);
            $table->string('current_status', 30)->default('NEW')->index();

            // CBN Reporting
            $table->boolean('cbn_reportable')->default(false);
            $table->date('cbn_reporting_deadline')->nullable();
            $table->boolean('cbn_notification_sent')->default(false);
            $table->timestamp('cbn_notification_date')->nullable();
            $table->string('cbn_notification_ref', 200)->nullable();

            // NFIU/AML
            $table->boolean('nfiu_reportable')->default(false);
            $table->string('nfiu_report_type', 10)->nullable();
            $table->string('nfiu_str_reference', 100)->nullable();
            $table->boolean('nfiu_report_filed')->default(false);

            // BOFIA / NDIC
            $table->boolean('bofia_reportable')->default(false);
            $table->boolean('ndic_reportable')->default(false);

            // Law Enforcement
            $table->boolean('law_enforcement_notified')->default(false);
            $table->string('police_report_ref', 200)->nullable();
            $table->boolean('efcc_reported')->default(false);

            // Impact
            $table->string('customer_impact_rating', 20)->nullable();
            $table->integer('customers_affected_count')->nullable();
            $table->string('reputational_impact_rating', 20)->nullable();
            $table->decimal('operational_disruption_hrs', 10, 2)->nullable();
            $table->bigInteger('indirect_cost_kobo')->default(0);

            // Linkages
            $table->foreignId('risk_register_id')->nullable()->constrained('risks')->nullOnDelete();

            // Workflow
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_approval_stage', 50)->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loss_events');
    }
};
