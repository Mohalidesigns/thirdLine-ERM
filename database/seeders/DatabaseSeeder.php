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
        /*  1. Roles & Permissions                                             */
        /* ------------------------------------------------------------------ */

        $this->call(RolesAndPermissionsSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  2. Risk Categories (also creates the default Organization)         */
        /* ------------------------------------------------------------------ */

        $this->call(RiskCategorySeeder::class);

        /* ------------------------------------------------------------------ */
        /*  3. Demo admin user                                                 */
        /* ------------------------------------------------------------------ */

        $org = Organization::where('cbn_institution_code', 'NGN/COM/0001')->first();

        $admin = User::create([
            'name'              => 'System Administrator',
            'email'             => 'admin@risk.test',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'organization_id'   => $org?->id,
            'staff_id'          => 'ADM-001',
            'job_title'         => 'System Administrator',
            'department'        => 'Information Technology',
            'phone'             => '+234-000-000-0000',
            'is_active'         => true,
        ]);

        $admin->assignRole('super-admin');

        /* ------------------------------------------------------------------ */
        /*  4. Demo data (users, risks, controls, KRIs, etc.)                  */
        /* ------------------------------------------------------------------ */

        $this->call(DemoDataSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  5. Scoping (Entity Types + Entities + Risk Linkage)               */
        /* ------------------------------------------------------------------ */

        $this->call(ScopingSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  6. Quantification (Scenarios, Simulations, ICAAP, Settings)       */
        /* ------------------------------------------------------------------ */

        $this->call(QuantificationSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  7. Risk Analysis (Assessments, Loss Events, KRIs for trends)      */
        /* ------------------------------------------------------------------ */

        $this->call(RiskAnalysisSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  8. Business process catalogue (idempotent)                        */
        /* ------------------------------------------------------------------ */

        $this->call(BusinessProcessSeeder::class);

        /* ------------------------------------------------------------------ */
        /*  9. Enterprise gap-fill demo data (idempotent)                     */
        /* ------------------------------------------------------------------ */

        $this->call(EnterpriseGapSeeder::class);
    }
}
