<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\VerificationStatus;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Models\Organization;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\CallTreeDemoSeeder;
use Database\Seeders\Bcms\EmnsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 2 rejection, defect C2: `Contact::casts()` maps `verification_status`
 * onto `VerificationStatus`, which has never declared a `failed` case — the
 * migration's own contract comment says `unverified|verified|bounced|invalid`
 * — yet four seeder writes used `'failed'` anyway. Once the column was cast,
 * `HasAttributes::getEnumCaseFromValue()` calls the enum's `::from()` on every
 * read and write, and `db:seed` for the BCMS demo estate fatalled with a
 * `ValueError` the moment either seeder tried to write it.
 *
 * SCOPED TO WHAT THESE TWO SEEDERS NEED, NOT THE WHOLE DEMO ESTATE.
 * `BcmsDemoSeeder::run()` seeds fifty processes, a BIA campaign, a strategy
 * register and more before it ever reaches `CallTreeDemoSeeder` — none of
 * which this defect touches. One business unit coded `BU-OP` is enough for
 * `CallTreeDemoSeeder` to build its two-hundred-person Operations tree (the
 * one `breakTheOperationsTree()` and `seedDataFlaws()` both write
 * `verification_status` against), and that roster alone clears the
 * twenty-contact floor `EmnsDemoSeeder::spreadChannels()` requires before it
 * writes the same column.
 */
class DemoSeederCompletionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_call_tree_and_emns_demo_seeders_run_to_completion_on_a_fresh_tenant(): void
    {
        $organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($organization->id);

        BusinessUnit::create([
            'organization_id' => $organization->id, 'code' => 'BU-OP',
            'name' => 'Operations', 'is_active' => true,
        ]);

        // MUTATION: revert either of the two fixed writes in
        // CallTreeDemoSeeder (breakTheOperationsTree(), seedDataFlaws()) or
        // either in EmnsDemoSeeder (spreadChannels()) back to the literal
        // 'failed' and this test fatals with a ValueError instead of failing
        // an assertion — which is exactly the shape the real defect took.
        (new CallTreeDemoSeeder)->run($organization);
        (new EmnsDemoSeeder)->run($organization);

        $this->assertTrue(
            CallTree::query()->where('organization_id', $organization->id)->exists(),
            'The call-tree seeder produced no tree at all — the fixture set up for this test is not '
            .'exercising the seeder the way it runs in production.'
        );

        // Both fixed code paths must have actually run, not merely not have
        // crashed: a green test that skipped every branch touching
        // verification_status would prove nothing about the fix.
        $this->assertTrue(
            Contact::query()
                ->where('organization_id', $organization->id)
                ->where('verification_status', VerificationStatus::Bounced->value)
                ->exists(),
            'Neither seeder wrote a single Bounced verification_status row, so this test did not reach '
            .'the lines defect C2 fixed.'
        );
    }
}
