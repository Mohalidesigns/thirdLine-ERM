<?php

namespace App\Http\Controllers\Risk;

use App\Contracts\DirectoryProfiles;
use App\Enums\KriContactRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kri\StoreKriDepartmentContactRequest;
use App\Models\BusinessUnit;
use App\Models\KriDepartmentContact;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Kri\DepartmentMismatchRule;
use App\Support\DirectoryProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Who is each department's risk champion, team lead, unit head and group head
 * (ADR 0026 section 2). Configuring a contact grants NOTHING: the screen flags a
 * champion who lacks `kri.submit_return`. The directory is a read-only hint,
 * read through App\Contracts\DirectoryProfiles when the screen is drawn.
 */
class KriDepartmentContactController extends Controller
{
    public function index(Request $request, DirectoryProfiles $directory)
    {
        $organizationId = TenantContext::organizationId();

        $units = BusinessUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'parent_id']);
        $contacts = KriDepartmentContact::query()->with('user')->orderBy('id')->get();
        $profiles = $directory->forUsers($organizationId, $contacts->pluck('user_id')->unique()->map(fn ($id) => (int) $id)->all());
        $permitted = [];

        return Inertia::render('Kri/Settings/Contacts', [
            'departments' => $units->map(function (BusinessUnit $unit) use ($contacts, $profiles, &$permitted) {
                $byRole = array_fill_keys(KriContactRole::values(), []);

                foreach ($contacts->where('business_unit_id', $unit->id) as $contact) {
                    if ($contact->user === null) {
                        continue;
                    }

                    $permitted[$contact->user_id] ??= $contact->user->can('kri.submit_return');
                    $byRole[$contact->role->value][] = $this->contactRow($contact, $permitted[$contact->user_id], $profiles[$contact->user_id] ?? null, $unit);
                }

                return [
                    'id' => (int) $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                    'parent_id' => $unit->parent_id === null ? null : (int) $unit->parent_id,
                    'contacts' => $byRole,
                    'has_champion' => $byRole[KriContactRole::RiskChampion->value] !== [],
                ];
            })->values()->all(),
            'roles' => array_map(fn (KriContactRole $r) => ['value' => $r->value, 'label' => $r->label()], KriContactRole::cases()),
        ]);
    }

    /** JSON user search: name or email, 2+ characters, at most 20. */
    public function users(Request $request, DirectoryProfiles $directory): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $like = '%'.addcslashes($q, '\\%_').'%';

        $users = User::query()
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->with('businessUnit:id,name')
            ->orderBy('name')
            ->limit(20)
            ->get();

        $profiles = $directory->forUsers(TenantContext::organizationId(), $users->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json($users->map(fn (User $user) => [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'home_unit' => $user->businessUnit === null ? null : ['id' => (int) $user->businessUnit->id, 'name' => $user->businessUnit->name],
            'has_submit_permission' => $user->can('kri.submit_return'),
            'directory' => $this->directoryRow($profiles[$user->id] ?? null),
        ])->values()->all());
    }

    public function store(StoreKriDepartmentContactRequest $request)
    {
        $validated = $request->validated();

        $contact = DB::transaction(function () use ($validated, $request) {
            $contact = KriDepartmentContact::query()->firstOrCreate(
                ['business_unit_id' => $validated['business_unit_id'], 'user_id' => $validated['user_id'], 'role' => $validated['role']],
                ['organization_id' => TenantContext::organizationId(), 'assigned_by' => $request->user()->id],
            );

            if ($contact->wasRecentlyCreated) {
                $this->audit($contact, 'kri_contact_added', null, $this->describe($contact));
            }

            return $contact;
        });

        return back()->with('success', $contact->wasRecentlyCreated ? 'Contact added.' : 'That person already holds this role.');
    }

    public function destroy(KriDepartmentContact $contact)
    {
        Gate::authorize('delete', $contact);

        DB::transaction(function () use ($contact) {
            $this->audit($contact, 'kri_contact_removed', $this->describe($contact), null);
            $contact->delete();
        });

        return back()->with('success', 'Contact removed.');
    }

    /** Audited on the BusinessUnit so one query answers a department's history. */
    private function audit(KriDepartmentContact $contact, string $action, ?string $old, ?string $new): void
    {
        $unit = BusinessUnit::query()->find($contact->business_unit_id);

        if ($unit !== null) {
            AuditTrailService::record($unit, $action, $contact->role->value, $old, $new);
        }
    }

    private function describe(KriDepartmentContact $contact): string
    {
        // The audit trail is append-only and cannot be erased, so it records the
        // user id only - no name or email (NDPA register, ADR 0026 section).
        return "user:{$contact->user_id}";
    }

    /** @return array<string, mixed> */
    private function contactRow(KriDepartmentContact $contact, bool $canSubmit, ?DirectoryProfile $profile, BusinessUnit $unit): array
    {
        return [
            'contact_id' => (int) $contact->id,
            'user_id' => (int) $contact->user_id,
            'name' => $contact->user->name,
            'email' => $contact->user->email,
            'is_active' => (bool) $contact->user->is_active,
            'has_submit_permission' => $canSubmit,
            'directory' => $this->directoryRow($profile),
            // A flag, never applied: business units are authoritative (ADR 0026 section 9).
            'department_mismatch' => DepartmentMismatchRule::mismatches($profile, $unit),
        ];
    }

    /** @return array<string, mixed>|null */
    private function directoryRow(?DirectoryProfile $profile): ?array
    {
        return $profile === null ? null : [
            'synced' => $profile->synced,
            'department' => $profile->department,
            'account_enabled' => $profile->accountEnabled,
            'last_synced_at' => $profile->lastSyncedAt?->toIso8601String(),
        ];
    }
}
