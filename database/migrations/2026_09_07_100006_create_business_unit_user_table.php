<?php

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use ThirdLine\Platform\Authorization\SeedsPermissions;

/**
 * RCSA v2, P7 — who may see which business unit.
 *
 * §11: "every query is constrained by tenant and by the user's business-unit
 * ASSIGNMENTS. A risk champion in Retail Operations must not be able to read
 * Treasury's assessment, or export it."
 *
 * ASSIGNMENTS, PLURAL, WHICH `users.business_unit_id` CANNOT EXPRESS. That
 * column says where somebody works and it is the right shape for one fact about
 * a person; it is the wrong shape for authority. An ORM analyst reviews six
 * units, a regional head owns a subtree, and a risk champion covering two
 * branches over a holiday is an ordinary Tuesday. A pivot is the only honest
 * model of it, and `users.business_unit_id` stays exactly what it was — the
 * home unit, and the seed this table is backfilled from.
 *
 * A GENERAL TABLE, WIRED ONLY INTO RCSA. `business_unit_user` is deliberately
 * not prefixed `rcsa_`: nothing about "this user covers this unit" is specific
 * to self-assessment, and a second module needing it should not invent a second
 * table. What is RCSA-specific is the ENFORCEMENT, which lives in
 * App\Support\Rcsa\RcsaScope and nowhere else.
 *
 * THE ESCAPE HATCH IS A PERMISSION, NOT A NULL. `rcsa_scope.all_units` is what
 * the Head of ORM, the CRO and Internal Audit hold — §11's "read-only, full
 * estate". Treating "no assignments" as "sees everything" would be a scoping
 * system that does not scope, and it is the exact bug that makes RBAC
 * theatre: it fails open for precisely the accounts nobody has got round to
 * configuring.
 *
 * WHICH MEANS THIS MIGRATION MUST NOT BLIND A LIVE TENANT. Two things stop it:
 * every user's `business_unit_id` is copied in as their first assignment, and
 * the second-line and executive roles are granted `all_units` — the authority
 * they already had in practice, now written down. A deployment tightens from
 * there deliberately rather than discovering on Monday that the whole bank
 * sees nothing.
 */
return new class extends Migration
{
    use SeedsPermissions;

    public function up(): void
    {
        Schema::create('business_unit_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();

            // Whether the assignment reaches the unit's children. A regional
            // head assigned to "Retail" means Retail and everything under it;
            // a champion assigned to one branch means that branch. Storing the
            // intent beats storing the expansion, which would go stale the
            // moment somebody added a branch.
            $table->boolean('includes_descendants')->default(true);

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'business_unit_id']);
            $table->index(['organization_id', 'user_id']);
            $table->index(['organization_id', 'business_unit_id']);
        });

        $this->backfillFromHomeUnit();
        $this->grantFullEstateToSecondLine();
    }

    /**
     * Everybody's home unit becomes their first assignment.
     *
     * Chunked and inserted in bulk: a tenant with ten thousand users is a row
     * per user, and a per-user save would be ten thousand round trips inside a
     * migration.
     */
    private function backfillFromHomeUnit(): void
    {
        DB::table('users')
            ->whereNotNull('business_unit_id')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                $now = now();

                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'organization_id' => $user->organization_id,
                        'user_id' => $user->id,
                        'business_unit_id' => $user->business_unit_id,
                        'includes_descendants' => true,
                        'assigned_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('business_unit_user')->insertOrIgnore($rows);
                }
            });
    }

    private function grantFullEstateToSecondLine(): void
    {
        // The roles that already saw the whole estate before this migration
        // existed. Granting it explicitly is not a widening — it is writing
        // down the authority they were exercising, so that narrowing it later
        // is a decision somebody makes rather than an accident.
        $this->grantFromCatalog(new RiskPermissionCatalog, [
            'risk-manager' => ['rcsa_scope.all_units'],
            'chief-risk-officer' => ['rcsa_scope.all_units'],
            'risk-analyst' => ['rcsa_scope.all_units'],
            'compliance-officer' => ['rcsa_scope.all_units'],
        ]);

        // Keep super-admin level with the catalog — see 100007 and
        // docs/rcsa-v2/super-admin-403.md.
        $guard = config('auth.defaults.guard', 'web');
        $role = Role::query()->where('name', 'super-admin')->where('guard_name', $guard)->first();

        if ($role !== null) {
            $role->givePermissionTo(
                collect((new RiskPermissionCatalog)->names())
                    ->map(fn (string $name) => Permission::findOrCreate($name, $guard))
                    ->all()
            );

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_unit_user');
    }
};
