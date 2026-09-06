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
        /*  12. TPRM reference libraries (idempotent).                         */
        /*                                                                     */
        /*      After RiskCategorySeeder, and the dependency is real rather    */
        /*      than incidental: the seeder hangs a "Third-Party and           */
        /*      Outsourcing Risk" area beneath the tenant's Operational Risk   */
        /*      node so third-party exposure rolls up into the ERM key risk    */
        /*      areas. Without a taxonomy it degrades — categories seed        */
        /*      unmapped rather than the run failing — but the mapping is the  */
        /*      point, so it runs where the taxonomy exists.                   */
        /* ------------------------------------------------------------------ */

        $this->call(\Database\Seeders\Tprm\TprmReferenceSeeder::class);
    }
}
