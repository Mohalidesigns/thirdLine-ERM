<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /* ------------------------------------------------------------------ */
        /*  1. Roles & Permissions */
        /* ------------------------------------------------------------------ */

        $this->call(RolesAndPermissionsSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  2. Risk Categories (also creates the default Organization) */
        /* ------------------------------------------------------------------ */

        $this->call(RiskCategorySeeder::class);

        /* ------------------------------------------------------------------ */
        /*  3. Demo admin user */
        /* ------------------------------------------------------------------ */

        $org = Organization::where('cbn_institution_code', 'NGN/COM/0001')->first();

        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@risk.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'organization_id' => $org?->id,
            'staff_id' => 'ADM-001',
            'job_title' => 'System Administrator',
            'department' => 'Information Technology',
            'phone' => '+234-000-000-0000',
            'is_active' => true,
        ]);

        $admin->assignRole('super-admin');

        /* ------------------------------------------------------------------ */
        /*  4. Demo data (users, risks, controls, KRIs, etc.) */
        /* ------------------------------------------------------------------ */

        $this->call(DemoDataSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  5. Scoping (Entity Types + Entities + Risk Linkage) */
        /* ------------------------------------------------------------------ */

        $this->call(ScopingSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  6. Quantification (Scenarios, Simulations, ICAAP, Settings) */
        /* ------------------------------------------------------------------ */

        $this->call(QuantificationSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  7. Risk Analysis (Assessments, Loss Events, KRIs for trends) */
        /* ------------------------------------------------------------------ */

        $this->call(RiskAnalysisSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  8. Business process catalogue (idempotent) */
        /* ------------------------------------------------------------------ */

        $this->call(BusinessProcessSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  9. Workflow definitions (WP-06) — before the gap-fill seeder, which */
        /*     starts demo instances against them. */
        /* ------------------------------------------------------------------ */

        $this->call(WorkflowDefinitionSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  10. Enterprise gap-fill demo data (idempotent) */
        /* ------------------------------------------------------------------ */

        $this->call(EnterpriseGapSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  11. WP-08: system widget library + Corporater dashboards */
        /*      (idempotent). Last, because layouts reference the demo org's */
        /*      units, categories and measures. */
        /* ------------------------------------------------------------------ */

        $this->call(WidgetDashboardSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  12. TPRM reference libraries (idempotent). */
        /* */
        /*      After RiskCategorySeeder, and the dependency is real rather */
        /*      than incidental: the seeder hangs a "Third-Party and */
        /*      Outsourcing Risk" area beneath the tenant's Operational Risk */
        /*      node so third-party exposure rolls up into the ERM key risk */
        /*      areas. Without a taxonomy it degrades — categories seed */
        /*      unmapped rather than the run failing — but the mapping is the */
        /*      point, so it runs where the taxonomy exists. */
        /* ------------------------------------------------------------------ */

        $this->call(\Database\Seeders\Tprm\TprmReferenceSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  13. TPRM questionnaire packs (idempotent). */
        /* */
        /*      After the reference seeder, because the packs map their */
        /*      questions to framework controls it seeds. Publishing runs */
        /*      through the model, so FR-ASM-05's gate is exercised by the */
        /*      seeder before any author ever meets it. */
        /* ------------------------------------------------------------------ */

        $this->call(\Database\Seeders\Tprm\TprmQuestionnairePackSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  14. TPRM demonstration portfolio. */
        /* */
        /*      Invented vendors with invented spend, kept OUT of the */
        /*      reference seeder on purpose: a client who found Interswitch */
        /*      already in their register with a made-up contract value would */
        /*      be right to stop trusting everything else the seeder put */
        /*      there. It sits here beside DemoDataSeeder, which the same */
        /*      reasoning already applies to. */
        /* ------------------------------------------------------------------ */

        $this->call(\Database\Seeders\Tprm\TprmDemoSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  15. BCMS reference libraries (idempotent). */
        /* */
        /*      System-owned: the ISO 22301 / CBN clause reference table, the */
        /*      exercise-type catalogue, the readiness templates, the Nigerian */
        /*      blackout calendar, the alert templates, the scenario library */
        /*      and the training curricula. Plus one `bcms_settings` row per */
        /*      organisation. Independent of everything above it — it needs an */
        /*      organisation to exist and nothing else. */
        /* ------------------------------------------------------------------ */

        $this->call(\Database\Seeders\Bcms\BcmsReferenceSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  16. BCMS demonstration estate. */
        /* */
        /*      After DemoDataSeeder, and the dependency is real: the BCM */
        /*      process overlay links to `business_processes` rows that seeder */
        /*      creates, and the four divisions reparent its business units. */
        /*      Kept out of the reference seeder for the reason TprmDemoSeeder */
        /*      records — invented estate does not belong beside data a client */
        /*      is meant to trust. */
        /* ------------------------------------------------------------------ */

        $this->call(\Database\Seeders\Bcms\BcmsDemoSeeder::class);
    }
}
