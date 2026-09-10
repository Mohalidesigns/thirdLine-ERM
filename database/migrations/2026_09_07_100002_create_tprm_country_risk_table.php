<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 1 — the country risk table.
 *
 * A GAP IN THIS MODULE'S OWN PHASE 0. The phase prompt's reference-data list
 * asked for "country risk table with GEO scores and a supervisory_access_impeded
 * flag" and the Phase 0 delivery seeded every other reference library and
 * missed this one. `TieringService` needs it for the +0.2 GEO adjustment in
 * TRD §7.2, which is what surfaced the omission.
 *
 * ONE COLUMN HERE IS A POLICY JUDGEMENT, NOT A FACT, and it ships empty.
 *
 * `supervisory_access_impeded` says that a jurisdiction obstructs a Nigerian
 * supervisor's access to records held there. That is a determination with
 * diplomatic and commercial weight, it changes with the political weather, and
 * getting it wrong in a shipped default would put an assertion about a
 * sovereign state into a client's risk register under our name. So the column
 * exists, the scoring reads it, and NO COUNTRY SHIPS WITH IT SET — a tenant's
 * risk function sets it, with the same deliberateness as any other policy.
 *
 * `region` is different: ECOWAS membership is a matter of public record, so it
 * is seeded, and it is what lets the GEO factor tell a Ghanaian processor from
 * a Singaporean one without anyone configuring anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_country_risk', function (Blueprint $table) {
            $table->id();

            // Null for the shipped rows; a tenant may override any country by
            // inserting its own row, exactly as it may with document types.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('country_code', 2);
            $table->string('name', 120);

            // domestic | ecowas | africa_other | international
            $table->string('region', 30)->default('international');

            /*
             * The GEO factor score for a vendor processing here, before the
             * transfer-basis question is considered. Nullable, and null means
             * "not assessed" rather than "no risk": the calculator falls back
             * to the A3 transfer-basis answer, which is a real answer, instead
             * of inventing a score for a country nobody has looked at.
             */
            $table->decimal('geo_score', 3, 2)->nullable();

            // Ships false everywhere. See the header.
            $table->boolean('supervisory_access_impeded')->default(false);
            $table->text('impediment_note')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'country_code'], 'tp_country_risk_unique');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_country_risk');
    }
};
