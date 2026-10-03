<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StoreBcmsVendorAttestationRequest;
use App\Models\Bcms\Contact;
use App\Models\Tprm\Engagement;
use App\Services\Bcms\ResilienceKriPublisher;
use App\Services\Bcms\Suppliers\SupplierResilienceService;
use App\Services\Tprm\Continuity\BcpTestRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier resilience — `docs/bcms/screens/supplier-resilience.md`,
 * phase-11-spec §4.
 *
 * WRITES NOTHING TO THE VENDOR REGISTER ITSELF. The one write action posts a
 * BCP test result into TPRM's own `tp_bcp_tests` through TPRM's own service
 * and authority check — a BCMS permission grant is not a licence to write the
 * vendor register.
 */
class SupplierResilienceController extends Controller
{
    public function __construct(
        private readonly SupplierResilienceService $suppliers,
        private readonly BcpTestRecorder $recorder,
        private readonly ResilienceKriPublisher $kris,
    ) {}

    public function index(): Response
    {
        Gate::authorize('bcms.report.view');

        // A6: computed once and reused — this used to be built up to three
        // times over on one page render (once here, once again inside
        // `chaseList()`).
        $continuity = $this->suppliers->continuityView();
        $chaseList = $this->suppliers->chaseList($continuity);
        $canRecordAttestation = request()->user()?->can('tprm.edit') ?? false;

        // ThirdParty carries HasTprmUuid and route-keys on `uuid` — the
        // continuity view itself only returns the numeric `vendor_id`
        // (ModuleActionUrlRouteKeyTest's own defect shape), so the URL is
        // built here, once, from the actual model, rather than asking the
        // screen to guess which key the route binds on. The action is
        // omitted entirely (not just disabled) for a viewer who lacks TPRM's
        // own `tprm.edit` authority, per the screen spec's "no apparent
        // means to do the thing you cannot do" rule.
        if ($canRecordAttestation && $continuity !== []) {
            $vendors = \App\Models\Tprm\ThirdParty::withTrashed()
                ->whereIn('id', array_column($continuity, 'vendor_id'))
                ->get()
                ->keyBy('id');

            $continuity = array_map(function (array $row) use ($vendors) {
                $vendor = $vendors->get($row['vendor_id']);
                $row['attestation_url'] = $vendor === null ? null : route('bcms.vendors.attestation.store', $vendor);

                return $row;
            }, $continuity);
        } else {
            $continuity = array_map(function (array $row) {
                $row['attestation_url'] = null;

                return $row;
            }, $continuity);
        }

        return Inertia::render('Bcms/Suppliers/Resilience', [
            'critical_vendor_count' => count($continuity),
            'continuity' => $continuity,
            'chase_list' => $chaseList,
            'participation' => $this->vendorParticipation(),
            'concentration' => $this->suppliers->concentration(),
            'can' => ['record_attestation' => $canRecordAttestation],
        ]);
    }

    public function concentration()
    {
        Gate::authorize('bcms.report.view');

        return response()->json($this->suppliers->concentration());
    }

    /**
     * Vendor exercise participants: a `bcms_contacts` row matched by email to
     * a TPRM `tp_contacts` row — the zero-migration join phase-11-spec §4(3)
     * and ADR 0021 §4 confirm (no `third_party_id` column on
     * `bcms_exercise_participants`, and none is proposed).
     *
     * A6: MATCHED CASE-INSENSITIVELY ON BOTH SIDES, CONSISTENTLY. MariaDB's
     * default `utf8mb4_unicode_ci` collation already matches `whereIn('email',
     * …)` case-insensitively at the database layer, so the SET of `Contact`
     * rows returned here was already right. The bug was purely in PHP:
     * `$vendorEmails->get($contact->email)` is a case-sensitive array-key
     * lookup, so a bank-side email that differs only in case from its TPRM
     * counterpart (`Musa@Vendor.com` vs `musa@vendor.com`) missed the lookup
     * and rendered `vendor_third_party_id = 0` — a named person attributed
     * to a vendor that does not exist. Normalising (`strtolower(trim(...))`)
     * on both the map's keys and the lookup key closes that gap.
     *
     * @return list<array<string, mixed>>
     */
    private function vendorParticipation(): array
    {
        $vendorEmails = \App\Models\Tprm\Contact::query()->whereNotNull('email')
            ->get(['email', 'third_party_id'])
            ->mapWithKeys(fn ($c) => [self::normaliseEmail($c->email) => $c->third_party_id]);

        if ($vendorEmails->isEmpty()) {
            return [];
        }

        return Contact::query()
            ->whereIn('email', $vendorEmails->keys())
            ->with(['user'])
            ->get()
            ->filter(fn (Contact $contact) => $vendorEmails->has(self::normaliseEmail($contact->email)))
            ->flatMap(function (Contact $contact) use ($vendorEmails) {
                $participants = \App\Models\Bcms\ExerciseParticipant::query()
                    ->where('contact_id', $contact->getKey())
                    ->with('occurrence:id,scheduled_date,definition_id')
                    ->get();

                return $participants->map(fn ($p) => [
                    'contact_name' => $contact->full_name,
                    'vendor_third_party_id' => (int) $vendorEmails->get(self::normaliseEmail($contact->email)),
                    'occurrence_id' => $p->occurrence_id,
                    'scheduled_date' => $p->occurrence?->scheduled_date?->toDateString(),
                    'role' => $p->role,
                    'invitation_status' => $p->invitation_status,
                    'attendance_status' => $p->attendance_status,
                ]);
            })
            ->values()
            ->all();
    }

    private static function normaliseEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public function storeAttestation(
        StoreBcmsVendorAttestationRequest $request,
        \App\Models\Tprm\ThirdParty $thirdParty
    ): RedirectResponse {
        // The route names the vendor; the write targets one of its
        // engagements, tenant-bound via the form request's own rule and
        // re-checked here against the route's third party so a mismatched
        // engagement_id 404s rather than silently writing another vendor's
        // engagement.
        $engagement = Engagement::query()
            ->where('third_party_id', $thirdParty->getKey())
            ->findOrFail($request->validated('engagement_id'));

        try {
            $this->recorder->record($engagement, $request->safe()->except('engagement_id'), $request->user());
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Recompute and file the one KRI measurement this phase writes
        // itself, so the chase-list change this attestation might resolve is
        // reflected in the register straight away rather than waiting for a
        // sweep.
        $this->kris->recordVendorAttestation(
            $this->suppliers->attestationRate(),
            'Recomputed after a vendor attestation was recorded.',
            $request->user()->getKey(),
        );

        return back()->with('success', 'Attestation recorded on the engagement in Third-Party Risk Management.');
    }
}
