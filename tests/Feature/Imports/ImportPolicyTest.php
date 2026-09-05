<?php

namespace Tests\Feature\Imports;

use App\Models\DataImport;
use App\Models\User;
use App\Policies\DataImportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * DataImportPolicy (migration Phase 5.5).
 *
 * UPLOADING IS NOT PROCESSING. `import.create` and `import.process` have always
 * been separate seeded permissions and the routes carry them separately; what
 * was missing is anything asking the question about a PARTICULAR import.
 * `processImport()` checked the tenant by hand and nothing checked the
 * permission beyond route middleware.
 *
 * The distinction earns its keep: uploading stages a file and reads its
 * headers, while processing writes every row into the register, irreversibly
 * and in bulk. A fifty-thousand-row risk import is the largest single write
 * anyone can make to this product.
 */
class ImportPolicyTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['import.view', 'import.create', 'import.process'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    #[Test]
    public function the_policy_is_discovered_for_its_model(): void
    {
        $this->assertInstanceOf(DataImportPolicy::class, Gate::getPolicyFor(DataImport::class));
    }

    #[Test]
    public function uploading_and_processing_are_separate_permissions(): void
    {
        $uploader = $this->userWith(['import.view', 'import.create'], 'uploader');
        $processor = $this->userWith(['import.view', 'import.process'], 'processor');

        $import = $this->import();

        $this->assertTrue($uploader->can('create', DataImport::class));
        $this->assertFalse($uploader->can('process', $import));

        $this->assertTrue($processor->can('process', $import));
        $this->assertFalse($processor->can('create', DataImport::class));
    }

    /** And the route enforces it, not just the policy object. */
    #[Test]
    public function an_uploader_cannot_start_an_import(): void
    {
        Queue::fake();

        $uploader = $this->userWith(['import.view', 'import.create'], 'uploader2');
        $import = $this->import();

        $this->actingAs($uploader)
            ->post(route('risk.imports.process', $import), ['column_mapping' => ['title' => 0]])
            ->assertForbidden();

        $this->assertSame('pending', $import->fresh()->status);
        Queue::assertNothingPushed();
    }

    /** A reader may see the history and nothing more. */
    #[Test]
    public function a_reader_may_see_the_history_and_nothing_more(): void
    {
        $reader = $this->userWith(['import.view'], 'reader');

        $this->assertTrue($reader->can('viewAny', DataImport::class));
        $this->assertFalse($reader->can('create', DataImport::class));
        $this->assertFalse($reader->can('process', $this->import()));
    }

    /* ------------------------------------------------------------------ */

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $handle): User
    {
        $user = User::create([
            'name' => $handle,
            'email' => $handle.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function import(): DataImport
    {
        return DataImport::create([
            'organization_id' => $this->organization->id,
            'import_type' => 'risks',
            'file_name' => 'register.csv',
            'file_path' => 'imports/register.csv',
            'status' => 'pending',
            'imported_by' => $this->actor->id,
        ]);
    }
}
